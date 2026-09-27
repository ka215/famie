[CmdletBinding()]
param(
    [Parameter(Mandatory)][ValidateSet('staging', 'production')][string]$Environment,
    [Parameter(Mandatory)][string]$ToVersion,
    [string]$RestoreDatabase,
    [switch]$Apply,
    [switch]$NonInteractive
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')
$root = Get-ReleaseRepoRoot
Push-Location $root
try {
    Assert-StableVersion -Version $ToVersion
    Assert-RequiredTools -Tools @('gh', 'jq')
    $tag = "v$ToVersion"
    $release = & gh release view $tag --json tagName --jq '.tagName'
    if ($LASTEXITCODE -ne 0 -or $release -ne $tag) { throw "GitHub Release $tag is unavailable." }
    Write-Host "Environment: $Environment"
    Write-Host "Rollback to: $tag"
    Write-Host "Database:   $(if ($RestoreDatabase) { $RestoreDatabase } else { 'unchanged' })"
    Write-Host "Mode:       $(if ($Apply) { 'apply' } else { 'dry-run' })"
    Invoke-ReleaseCommand gh @('workflow', 'run', 'rollback-release.yml', '--ref', 'main', '-f', "environment=$Environment", '-f', "to_version=$ToVersion", '-f', "restore_database=$RestoreDatabase", '-f', "apply=$($Apply.ToString().ToLowerInvariant())")
    Start-Sleep -Seconds 3
    $run = & gh run list --workflow rollback-release.yml --branch main --event workflow_dispatch --limit 1 --json databaseId --jq '.[0].databaseId'
    if ($LASTEXITCODE -ne 0 -or -not $run) { throw 'Cannot locate rollback workflow run.' }
    Invoke-ReleaseCommand gh @('run', 'watch', "$run", '--exit-status')
    Write-Host "Rollback workflow completed: $run"
} finally { Pop-Location }
