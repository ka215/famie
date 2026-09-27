[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$Version,
    [switch]$Apply,
    [switch]$Resume,
    [switch]$NonInteractive
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')
. (Join-Path $PSScriptRoot 'version.ps1')
$root = Get-ReleaseRepoRoot
Push-Location $root
try {
    Assert-StableVersion -Version $Version
    Assert-RequiredTools -Tools @('git', 'gh', 'jq', 'pnpm')
    $branch = & git branch --show-current
    if ($LASTEXITCODE -ne 0 -or $branch -notmatch '^(feature/v|hotfix/).+') {
        throw 'Run release preparation on feature/vX.Y.Z* or hotfix/*.'
    }
    $releaseType = if ($branch -like 'hotfix/*') { 'hotfix' } else { 'normal' }
    $base = if ($releaseType -eq 'hotfix') { 'main' } else { 'dev' }
    if ($releaseType -eq 'normal' -and $branch -notlike "feature/v$Version*") {
        throw "Branch $branch does not match release v$Version."
    }
    $metadata = Get-ReleaseMetadata -Root $root
    if ($metadata.Next -ne $Version) { throw "version.json.next must equal $Version." }
    Invoke-ReleaseCommand git @('fetch', 'origin', $base)
    $ahead = & git rev-list --count "origin/$base..HEAD"
    if ($LASTEXITCODE -ne 0) { throw "Cannot compare $branch with origin/$base." }
    if ([int]$ahead -eq 0) { throw "$branch has no commits beyond origin/$base." }

    Write-Host "Release: v$Version"
    Write-Host "Branch:  $branch -> $base"
    Write-Host "Mode:    $(if ($Apply) { 'apply' } else { 'dry-run' })"
    if (-not $Apply) {
        Write-Host 'Plan: update package versions, run local checks, commit/push, create and merge PR, then build the CI artifact.'
        exit 0
    }

    Assert-CleanWorktree
    $session = Get-ReleaseSessionPath -Root $root -Version $Version
    if ($Resume) {
        if (-not (Test-Path -LiteralPath $session)) { throw "No release session exists for v$Version." }
    } else {
        $session = Initialize-ReleaseSession -Root $root -Version $Version -ReleaseType $releaseType -Branch $branch
    }
    Set-ReleasePackageVersions -Root $root -ExpectedNext $Version
    Push-Location (Join-Path $root 'frontend')
    try {
        Invoke-ReleaseCommand pnpm @('lint')
        Invoke-ReleaseCommand pnpm @('typecheck')
        Invoke-ReleaseCommand pnpm @('test:unit')
    } finally { Pop-Location }
    Invoke-ReleaseCommand git @('add', '--', 'frontend/package.json', 'backend/package.json')
    & git diff --cached --quiet
    if ($LASTEXITCODE -eq 1) { Invoke-ReleaseCommand git @('commit', '-m', "chore: prepare v$Version") }
    elseif ($LASTEXITCODE -gt 1) { throw 'Cannot inspect staged version changes.' }
    Invoke-ReleaseCommand git @('push', '--set-upstream', 'origin', $branch)

    $repo = & gh repo view --json nameWithOwner --jq '.nameWithOwner'
    if ($LASTEXITCODE -ne 0 -or -not $repo) { throw 'Cannot resolve GitHub repository.' }
    $pr = & gh pr list --repo $repo --base $base --head $branch --state open --json number --jq '.[0].number'
    if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect release PR.' }
    if (-not $pr) {
        $url = & gh pr create --repo $repo --base $base --head $branch --title "release: v$Version" --body "Prepare v$Version and generate its immutable release candidate artifact."
        if ($LASTEXITCODE -ne 0) { throw 'Cannot create release PR.' }
        $pr = & gh pr view $url --json number --jq '.number'
    }
    Update-ReleaseSession -Path $session -Filter '.state="candidate_pr_created" | .candidate_pr=($pr|tonumber)' -JqArguments @('--arg', 'pr', "$pr")
    Invoke-ReleaseCommand gh @('pr', 'checks', "$pr", '--watch', '--fail-fast')
    Invoke-ReleaseCommand gh @('pr', 'merge', "$pr", '--merge', '--delete-branch=false')
    Invoke-ReleaseCommand git @('fetch', 'origin', $base)
    $mergedCommit = & git rev-parse "origin/$base"
    $tree = & git rev-parse "origin/$base^{tree}"
    Update-ReleaseSession -Path $session -Filter '.state="candidate_merged" | .candidate_commit=$commit | .candidate_tree=$tree' -JqArguments @('--arg', 'commit', $mergedCommit, '--arg', 'tree', $tree)

    Invoke-ReleaseCommand gh @('workflow', 'run', 'release-ci.yml', '--ref', $base, '-f', "version=$Version", '-f', 'build_artifact=true')
    Start-Sleep -Seconds 3
    $runId = & gh run list --workflow release-ci.yml --branch $base --event workflow_dispatch --limit 1 --json databaseId --jq '.[0].databaseId'
    if ($LASTEXITCODE -ne 0 -or -not $runId) { throw 'Cannot locate release artifact workflow run.' }
    Invoke-ReleaseCommand gh @('run', 'watch', "$runId", '--exit-status')
    $artifactDirectory = Join-Path $root ".release/artifacts/v$Version"
    [System.IO.Directory]::CreateDirectory($artifactDirectory) | Out-Null
    Invoke-ReleaseCommand gh @('run', 'download', "$runId", '--dir', $artifactDirectory)
    $manifest = Get-ChildItem -LiteralPath $artifactDirectory -Filter '*.manifest.json' -File -Recurse | Select-Object -First 1
    if (-not $manifest) { throw 'Downloaded workflow has no release manifest.' }
    $manifestVersion = & jq -er '.release_version' $manifest.FullName
    $manifestTree = & jq -er '.source_tree' $manifest.FullName
    if ($manifestVersion -ne $Version -or $manifestTree -ne $tree) { throw 'Release artifact manifest does not match the merged candidate.' }
    $checksumFile = Get-ChildItem -LiteralPath $artifactDirectory -Filter '*.tar.gz.sha256' -File -Recurse | Select-Object -First 1
    if (-not $checksumFile) { throw 'Downloaded workflow has no bundle checksum.' }
    $artifactSha = ((Get-Content -LiteralPath $checksumFile.FullName -Raw) -split '\s+')[0]
    if ($artifactSha -notmatch '^[0-9a-f]{64}$') { throw 'Release bundle checksum is invalid.' }
    Update-ReleaseSession -Path $session -Filter '.state="artifact_ready" | .workflow_run_id=($run|tonumber) | .artifact_manifest=$manifest | .artifact_sha256=$sha' -JqArguments @('--arg', 'run', "$runId", '--arg', 'manifest', $manifest.FullName, '--arg', 'sha', $artifactSha)
    Write-Host "Release candidate artifact is ready. Session: $session"
} finally { Pop-Location }
