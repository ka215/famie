param(
    [string]$Httpd = 'C:/xampp/apache/bin/httpd.exe',
    [int]$Port = 18916
)
$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath $Httpd)) { throw 'Apache httpd is required.' }
$fixture = Join-Path ([IO.Path]::GetTempPath()) ('famie-domain-' + [guid]::NewGuid().ToString('N'))
$public = Join-Path $fixture 'public'
New-Item -ItemType Directory -Path $public | Out-Null
Copy-Item -LiteralPath "$PSScriptRoot/server/legacy-domain.htaccess" -Destination "$public/.htaccess"
Copy-Item -LiteralPath "$PSScriptRoot/server/domain-moved.json" -Destination $public
Set-Content -LiteralPath "$public/index.html" -Value 'new-host-placeholder' -Encoding utf8
# An existing API directory must still be blocked by the parent rules.
New-Item -ItemType Directory -Path "$public/api" | Out-Null
Set-Content -LiteralPath "$public/api/index.html" -Value 'API MUST NOT BE REACHED' -Encoding utf8
$serverRoot = (Split-Path (Split-Path $Httpd)).Replace('\', '/')
$publicPath = $public.Replace('\', '/')
$fixturePath = $fixture.Replace('\', '/')
$config = @"
ServerRoot "$serverRoot"
Listen 127.0.0.1:$Port
ServerName localhost
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule alias_module modules/mod_alias.so
LoadModule headers_module modules/mod_headers.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule mime_module modules/mod_mime.so
LoadModule dir_module modules/mod_dir.so
TypesConfig conf/mime.types
PidFile "$fixturePath/httpd.pid"
ErrorLog "$fixturePath/error.log"
DocumentRoot "$publicPath"
DirectoryIndex index.html
<Directory "$publicPath">
    AllowOverride All
    Require all granted
</Directory>
"@
$configPath = Join-Path $fixture 'httpd.conf'
[IO.File]::WriteAllText($configPath, $config, [Text.UTF8Encoding]::new($false))
& $Httpd -t -f $configPath
if ($LASTEXITCODE -ne 0) { throw "Apache config failed: $configPath" }
$process = Start-Process -FilePath $Httpd -ArgumentList @('-X', '-f', ('"' + $configPath + '"')) -WindowStyle Hidden -PassThru
$client = [Net.Http.HttpClient]::new([Net.Http.HttpClientHandler]@{AllowAutoRedirect = $false})
try {
    $ready = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        try {
            $probe = $client.GetAsync("http://127.0.0.1:$Port/").GetAwaiter().GetResult()
            $probe.Dispose()
            $ready = $true
            break
        } catch { Start-Sleep -Milliseconds 100 }
    }
    if (-not $ready) { throw "Apache did not start. Inspect $fixture" }
    $cases = @(
        @('GET', '/gallery/?month=2026-10', 302),
        @('HEAD', '/login/', 302),
        @('GET', '/api', 410),
        @('GET', '/api/v1/status', 410),
        @('HEAD', '/api/v1/status', 410),
        @('POST', '/api/v1/auth/login', 410),
        @('PUT', '/api/v1/logs/1', 410),
        @('PATCH', '/api/v1/logs/1', 410),
        @('DELETE', '/api/v1/logs/1', 410),
        @('OPTIONS', '/api/v1/logs', 410),
        @('POST', '/login/', 410)
    )
    foreach ($case in $cases) {
        $request = [Net.Http.HttpRequestMessage]::new([Net.Http.HttpMethod]::new($case[0]), "http://127.0.0.1:$Port$($case[1])")
        $request.Headers.Host = 'famie.ka2.org'
        $response = $client.SendAsync($request).GetAwaiter().GetResult()
        try {
            if ([int]$response.StatusCode -ne $case[2]) { throw "Unexpected status for $($case[0]) $($case[1]): $($response.StatusCode). Inspect $fixture" }
            if (-not $response.Headers.CacheControl.NoStore) { throw 'Missing no-store.' }
            if ($case[2] -eq 302) {
                if ($response.Headers.Location.AbsoluteUri -ne "https://famie.co$($case[1])") { throw 'Incorrect redirect destination or query.' }
            } elseif ($case[0] -ne 'HEAD') {
                if ($response.Content.Headers.ContentType.MediaType -ne 'application/json') { throw 'Expected JSON error document.' }
                $body = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
                $url = $body | jq -er '.url'
                if ($LASTEXITCODE -ne 0 -or $url -ne 'https://famie.co/') { throw 'Invalid migration JSON.' }
                $message = $body | jq -er '.message'
                if ($LASTEXITCODE -ne 0 -or $message -notmatch '再ログイン' -or $message -notmatch 'Please sign in again') { throw 'Missing bilingual migration message.' }
            }
        } finally { $response.Dispose(); $request.Dispose() }
    }
    $request = [Net.Http.HttpRequestMessage]::new([Net.Http.HttpMethod]::Get, "http://127.0.0.1:$Port/")
    $request.Headers.Host = 'famie.co'
    $response = $client.SendAsync($request).GetAwaiter().GetResult()
    try {
        if ([int]$response.StatusCode -ne 200) { throw 'New host was redirected or blocked.' }
    } finally { $response.Dispose(); $request.Dispose() }
    Copy-Item -LiteralPath "$PSScriptRoot/server/production-https.htaccess" -Destination "$public/.htaccess" -Force
    foreach ($path in @('/login/?migration_probe=1', '/api/v1/status')) {
        $request = [Net.Http.HttpRequestMessage]::new([Net.Http.HttpMethod]::Get, "http://127.0.0.1:$Port$path")
        $request.Headers.Host = 'famie.co'
        $response = $client.SendAsync($request).GetAwaiter().GetResult()
        try {
            if ([int]$response.StatusCode -ne 307 -or $response.Headers.Location.AbsoluteUri -ne "https://famie.co$path") {
                throw 'Production HTTPS redirect lost the request path or query.'
            }
        } finally { $response.Dispose(); $request.Dispose() }
    }
    Write-Output "PASS: $($cases.Count) legacy HTTP cases, new-host isolation and 2 HTTPS redirects. Fixture: $fixture"
} finally {
    $client.Dispose()
    if (-not $process.HasExited) { Stop-Process -Id $process.Id }
}
