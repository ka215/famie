[CmdletBinding()]
param(
    [string]$ApacheRoot = 'C:/xampp/apache',
    [int]$Port = 8111
)

$ErrorActionPreference = 'Stop'
$workspace = (Resolve-Path "$PSScriptRoot/../..").Path
$fixture = Join-Path $workspace ".temp/apache-maintenance-$([guid]::NewGuid().ToString('N'))"
$public = Join-Path $fixture 'public'
New-Item -ItemType Directory -Path $public | Out-Null
$backend = Join-Path $fixture 'backend-public'
New-Item -ItemType Directory -Path $backend | Out-Null
New-Item -ItemType Junction -Path (Join-Path $public 'api') -Target $backend | Out-Null
Copy-Item -LiteralPath (Join-Path $workspace 'backend/public/.htaccess') -Destination $backend
# Windows junctions need an explicit URL base for the standalone test vhost.
Add-Content -LiteralPath (Join-Path $backend '.htaccess') -Value 'RewriteBase /api/'
# A static front controller proves that maintenance never depends on PHP/Laravel.
Set-Content -LiteralPath (Join-Path $backend 'index.php') -Value 'Backend reached' -Encoding utf8NoBOM
foreach ($asset in @('.htaccess', 'maintenance.html', 'maintenance.json')) {
    Copy-Item -LiteralPath (Join-Path $workspace "frontend/public/$asset") -Destination $public
}
Set-Content -LiteralPath (Join-Path $public 'index.html') -Value 'Normal frontend' -Encoding utf8NoBOM
$rules = [System.IO.File]::ReadAllText((Join-Path $public '.htaccess'))
$rules = "Require ip 127.0.0.1`n" + $rules
[System.IO.File]::WriteAllText((Join-Path $public '.htaccess'), $rules)
$apache = $ApacheRoot.Replace('\', '/')
$root = $fixture.Replace('\', '/')
$configPath = Join-Path $fixture 'httpd.conf'
$config = @"
ServerRoot "$apache"
Listen 127.0.0.1:$Port
ServerName 127.0.0.1
PidFile "$root/httpd.pid"
ErrorLog "$root/error.log"
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
LoadModule alias_module modules/mod_alias.so
TypesConfig "$apache/conf/mime.types"
AddType text/plain .php
DocumentRoot "$root/public"
DirectoryIndex index.html
<Directory "$root">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
"@
[System.IO.File]::WriteAllText($configPath, $config)
$httpd = Join-Path $ApacheRoot 'bin/httpd.exe'
& $httpd -t -f $configPath
if ($LASTEXITCODE -ne 0) { throw 'Apache test configuration failed.' }
$process = Start-Process -FilePath $httpd -ArgumentList @('-X', '-f', ('"' + $configPath + '"')) -PassThru -WindowStyle Hidden
$base = "http://127.0.0.1:$Port"
function Request([string]$Path, [string]$Method = 'GET') {
    Invoke-WebRequest -Uri "$base$Path" -Method $Method -SkipHttpErrorCheck -TimeoutSec 5
}
try {
    $ready = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        try { $response = Request '/'; $ready = $true; break } catch { Start-Sleep -Milliseconds 200 }
    }
    if (-not $ready) { throw "Test Apache did not start: $fixture/error.log" }
    if ($response.StatusCode -ne 200 -or $response.Content -notmatch 'Normal frontend') { throw 'Normal page failed.' }
    $response = Request '/api/v1/status'
    if ($response.StatusCode -ne 200 -or $response.Content -notmatch 'Backend reached') { throw "Normal API routing failed: $($response.StatusCode) $($response.Content)" }
    New-Item -ItemType File -Path (Join-Path $public '.maintenance') | Out-Null
    foreach ($path in @('/', '/login/', '/settings')) {
        $response = Request $path
        if ($response.StatusCode -ne 503 -or $response.Content -notmatch 'ただいまメンテナンス中です') { throw "Expected maintenance HTML: $path HTTP $($response.StatusCode)" }
        if ($response.Headers['Cache-Control'] -notmatch 'no-store') { throw 'Maintenance HTML is cacheable.' }
        if ($response.Headers['Retry-After'] -ne '60') { throw 'Retry-After missing.' }
    }
    foreach ($method in @('GET', 'POST')) {
      foreach ($path in @('/api/v1/status', '/api/v1/auth/login', '/api/index.php')) {
        $response = Request $path $method
        if ($response.StatusCode -ne 503 -or $response.Headers['Content-Type'] -notmatch 'application/json') { throw "Expected maintenance API: $method HTTP $($response.StatusCode)" }
        $response.Content | & jq -e '.code == "maintenance"' | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Maintenance code missing.' }
        if ($response.Headers['Cache-Control'] -notmatch 'no-store') { throw 'Maintenance JSON is cacheable.' }
        if ($response.Headers['Retry-After'] -ne '60') { throw 'API Retry-After missing.' }
        if ($response.Content -cne [IO.File]::ReadAllText((Join-Path $public 'maintenance.json'))) { throw 'API did not serve the exact static JSON.' }
      }
    }
    # Even with the front controller unavailable (e.g. Composer update), serve JSON.
    Move-Item -LiteralPath (Join-Path $backend 'index.php') -Destination (Join-Path $backend 'index.php.disabled')
    $response = Request '/api/v1/status' 'POST'
    if ($response.StatusCode -ne 503 -or $response.Content -cne [IO.File]::ReadAllText((Join-Path $public 'maintenance.json'))) { throw 'Maintenance depends on backend availability.' }
    Move-Item -LiteralPath (Join-Path $backend 'index.php.disabled') -Destination (Join-Path $backend 'index.php')
    foreach ($asset in @('/maintenance.html', '/maintenance.json')) {
        $response = Request $asset
        if ($response.StatusCode -ne 200 -or $response.Headers['Cache-Control'] -notmatch 'no-store') { throw "Maintenance asset failed: $asset" }
    }
    [System.IO.File]::WriteAllText((Join-Path $public '.htaccess'), $rules.Replace('Require ip 127.0.0.1', 'Require ip 192.0.2.1'))
    foreach ($path in @('/', '/api/v1/status', '/maintenance.html', '/maintenance.json')) {
        $response = Request $path
        if ($response.StatusCode -ne 403) { throw "IP restriction bypassed: $path HTTP $($response.StatusCode)" }
    }
    [System.IO.File]::WriteAllText((Join-Path $public '.htaccess'), $rules)
    Remove-Item -LiteralPath (Join-Path $public '.maintenance')
    $response = Request '/'
    if ($response.StatusCode -ne 200 -or $response.Content -notmatch 'Normal frontend') { throw 'Recovery failed.' }
    $response = Request '/api/v1/status'
    if ($response.StatusCode -ne 200 -or $response.Content -notmatch 'Backend reached') { throw 'API recovery failed.' }
    Write-Host 'PASS: Apache HTML/API 503, GET/POST, no-store, retry header, IP restrictions and recovery.'
    Write-Host "Fixture retained: $fixture"
} finally {
    if (-not $process.HasExited) { Stop-Process -Id $process.Id -Force }
}
