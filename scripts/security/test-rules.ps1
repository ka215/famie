[CmdletBinding()]
param([string]$ToolsDirectory = (Join-Path $PSScriptRoot '../../.tools/security'))

$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$temporary = Join-Path ([IO.Path]::GetTempPath()) ('famie-rule-test-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temporary | Out-Null
$php = @'
<?php
function bad($request) {
    $command = $request->input('command');
    shell_exec($command);
    $sql = $request->query('sql');
    DB::select($sql);
    unserialize($request->input('object'));
    eval($request->input('code'));
}
function good($request) {
    DB::select('select * from entries where id = ?', [$request->input('id')]);
}
'@
$js = @'
eval(location.hash);
document.body.innerHTML = location.hash;
'@
try {
    Set-Content (Join-Path $temporary 'unsafe.php') $php
    Set-Content (Join-Path $temporary 'unsafe.ts') $js
    Set-Content (Join-Path $temporary 'unsafe.vue') '<template><div v-html="message" /></template>'
    New-Item -ItemType Directory -Force -Path (Join-Path $temporary '.github/workflows') | Out-Null
    Set-Content (Join-Path $temporary '.github/workflows/unsafe.yml') 'run: echo "${{ github.event.pull_request.title }}"'
    $opengrep = Join-Path ([IO.Path]::GetFullPath($ToolsDirectory)) ('opengrep/opengrep' + $(if ($IsWindows) { '.exe' } else { '' }))
    Push-Location $temporary
    try {
        & $opengrep scan --config (Join-Path $root 'scripts/security/rules.yml') --disable-version-check --strict --disable-nosem --no-git-ignore --json --output result.json . *> scan.log
        if ($LASTEXITCODE -ne 0) { throw 'Rule fixture scan failed.' }
        & jq -e '(.errors|length) == 0 and ([.results[].check_id | split(".") | last] | unique | length) == 8 and ([.results[] | select(.path == "unsafe.php" and .start.line >= 10)] | length) == 0' result.json > $null
        if ($LASTEXITCODE -ne 0) { throw 'Expected eight rule detections and no bound-SQL finding.' }
        Write-Host 'All eight security rules detect unsafe fixtures; bound SQL is accepted.'
    } finally { Pop-Location }
} finally {
    if (([IO.Path]::GetFullPath($temporary)).StartsWith([IO.Path]::GetFullPath([IO.Path]::GetTempPath()), [StringComparison]::OrdinalIgnoreCase)) {
        Remove-Item -LiteralPath $temporary -Recurse -Force
    }
}
