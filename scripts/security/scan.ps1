[CmdletBinding()]
param(
    [string]$ToolsDirectory = (Join-Path $PSScriptRoot '../../.tools/security'),
    [switch]$History,
    [string]$BaseCommit
)

$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$ToolsDirectory = [IO.Path]::GetFullPath($ToolsDirectory)
$temporary = Join-Path ([IO.Path]::GetTempPath()) ('famie-security-' + [Guid]::NewGuid().ToString('N'))
$suffix = if ($IsWindows) { '.exe' } else { '' }
New-Item -ItemType Directory -Path $temporary | Out-Null
$failed = $false
function Invoke-PrivateCheck([string]$Name, [string]$Executable, [string[]]$Arguments) {
    $log = Join-Path $temporary "$Name.log"
    & $Executable @Arguments *> $log
    $status = $LASTEXITCODE
    # Raw scanner output can contain source snippets/secrets. Never echo or upload it.
    Write-Host "$Name exit=$status"
    if ($status -ne 0) { $script:failed = $true }
}
Push-Location $root
try {
    foreach ($name in @('gitleaks', 'actionlint', 'opengrep')) {
        if (!(Test-Path -LiteralPath (Join-Path $ToolsDirectory "$name/$name$suffix"))) { throw 'Run install-tools.ps1 first.' }
    }
    if ($BaseCommit -and $BaseCommit -notmatch '\A[0-9a-f]{40}\z') { throw 'BaseCommit must be a full SHA.' }
    $tree = Join-Path $temporary 'tree'
    New-Item -ItemType Directory -Path $tree | Out-Null
    # Scan a clean source snapshot, including current edits, without local .env/vendor/cache.
    $files = & git -c core.quotepath=false ls-files --cached --others --exclude-standard
    if ($LASTEXITCODE -ne 0) { throw 'Cannot enumerate source files.' }
    foreach ($file in $files) {
        $source = Join-Path $root $file
        if (!(Test-Path -LiteralPath $source -PathType Leaf)) { continue }
        if ((Get-Item -LiteralPath $source).LinkType) { throw 'Source snapshot must not follow symlinks.' }
        $target = [IO.Path]::GetFullPath((Join-Path $tree $file))
        if (!$target.StartsWith($tree + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Invalid source path.' }
        New-Item -ItemType Directory -Force -Path ([IO.Path]::GetDirectoryName($target)) | Out-Null
        Copy-Item -LiteralPath $source -Destination $target
    }
    $gitleaks = Join-Path $ToolsDirectory "gitleaks/gitleaks$suffix"
    $config = Join-Path $root '.gitleaks.toml'
    $common = @('--config', $config, '--redact=100', '--no-banner', '--log-level', 'error', '--ignore-gitleaks-allow', '--timeout', '180')
    Push-Location $tree
    try { Invoke-PrivateCheck 'secrets-tree' $gitleaks (@('dir', '.') + $common) }
    finally { Pop-Location }
    if ($History) { Invoke-PrivateCheck 'secrets-history' $gitleaks (@('git', '.', '--log-opts=--all') + $common) }
    if ($BaseCommit) { Invoke-PrivateCheck 'secrets-diff' $gitleaks (@('git', '.', "--log-opts=$BaseCommit..HEAD") + $common) }
    Invoke-PrivateCheck 'workflows' (Join-Path $ToolsDirectory "actionlint/actionlint$suffix") @('-shellcheck=', '-pyflakes=')
    $vueScripts = Join-Path $temporary 'vue-scripts'
    & node scripts/security/extract-vue-scripts.mjs frontend/app $vueScripts
    if ($LASTEXITCODE -ne 0) { throw 'Vue script extraction failed.' }
    Invoke-PrivateCheck 'static' (Join-Path $ToolsDirectory "opengrep/opengrep$suffix") @(
        'scan', '--config', 'scripts/security/rules.yml', '--disable-version-check', '--strict', '--error',
        '--disable-nosem', '--jobs', '2', '--timeout', '10', '--json', '--output', (Join-Path $temporary 'static.json'),
        'backend/app', 'frontend/app', 'scripts', '.github/workflows', $vueScripts
    )
    $staticReport = Join-Path $temporary 'static.json'
    if (!(Test-Path -LiteralPath $staticReport)) { throw 'Static scanner produced no report.' }
    & jq -e '(.errors|length) == 0 and (.paths.scanned|length) > 0' $staticReport > $null
    if ($LASTEXITCODE -ne 0) { $failed = $true }
    & jq --arg vue $vueScripts '{findings:[.results[] | {rule:.check_id,file:(.path | gsub("\\\\";"/") | if startswith(($vue|gsub("\\\\";"/"))+"/") then "frontend/app/" + (ltrimstr(($vue|gsub("\\\\";"/"))+"/") | sub("\\.ts$";"")) else . end),line:.start.line}],errors:(.errors|length),scanned:(.paths.scanned|length)}' $staticReport
    if ($failed) { throw 'Security checks failed. Re-run the failing tool privately; do not publish raw reports.' }
} finally {
    Pop-Location
    # The path is a newly created, uniquely named temporary directory.
    if (([IO.Path]::GetFullPath($temporary)).StartsWith([IO.Path]::GetFullPath([IO.Path]::GetTempPath()), [StringComparison]::OrdinalIgnoreCase)) {
        Remove-Item -LiteralPath $temporary -Recurse -Force
    }
}
