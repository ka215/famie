[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$Version,
    [switch]$Apply,
    [switch]$ConfirmProduction,
    [string]$NextVersion,
    [switch]$Resume,
    [switch]$NonInteractive
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')
$root = Get-ReleaseRepoRoot
Push-Location $root
try {
    Assert-StableVersion -Version $Version
    if ($NextVersion) { Assert-StableVersion -Version $NextVersion }
    Assert-RequiredTools -Tools @('git', 'gh', 'jq')
    $session = Get-ReleaseSessionPath -Root $root -Version $Version
    if (-not (Test-Path -LiteralPath $session)) { throw "Missing release session for v$Version." }
    $state = Get-ReleaseSessionValue -Path $session -Filter '.state'

    if ($ConfirmProduction) {
        if (-not $Apply) { throw '-ConfirmProduction requires -Apply.' }
        if ($state -ne 'production_verified') { throw "Cannot confirm production from state: $state" }
        Update-ReleaseSession -Path $session -Filter '.production.user_verified=true'
        Write-Host "Production user verification recorded for v$Version."
        exit 0
    }

    $confirmed = & jq -er '.production.automated_verified == true and .production.user_verified == true' $session
    if ($LASTEXITCODE -ne 0 -or $confirmed -ne 'true') { throw 'Production automated and real-device verification must both be complete.' }
    Write-Host "Release: v$Version"
    Write-Host "Mode:    $(if ($Apply) { 'apply' } else { 'dry-run' })"
    if (-not $Apply) {
        Write-Host 'Plan: publish GitHub Release assets, sync main to dev, update version state and release record, then clean merged branches.'
        exit 0
    }
    Assert-CleanWorktree
    $tag = "v$Version"
    $releaseExists = & gh release view $tag --json tagName --jq '.tagName' 2>$null
    if (-not $releaseExists) {
        $artifactManifest = Get-ReleaseSessionValue -Path $session -Filter '.artifact_manifest'
        if (-not $artifactManifest -or -not (Test-Path -LiteralPath $artifactManifest -PathType Leaf)) {
            throw 'The release session artifact manifest is not available locally.'
        }
        $artifactDirectory = Split-Path -Parent $artifactManifest
        $assets = Get-ChildItem -LiteralPath $artifactDirectory -File -Recurse | Where-Object { $_.Name -match '\.(tar\.gz|sha256|manifest\.json)$' }
        if (-not $assets) { throw 'Release assets are not available locally.' }
        $checksumFile = $assets | Where-Object { $_.Name -like '*.tar.gz.sha256' } | Select-Object -First 1
        $expectedArtifactSha = Get-ReleaseSessionValue -Path $session -Filter '.artifact_sha256'
        $actualArtifactSha = if ($checksumFile) { ((Get-Content -LiteralPath $checksumFile.FullName -Raw) -split '\s+')[0] } else { $null }
        if (-not $actualArtifactSha -or $actualArtifactSha -ne $expectedArtifactSha) {
            throw 'Release asset checksum does not match the release session.'
        }
        $arguments = @('release', 'create', $tag, '--verify-tag', '--title', $tag, '--notes', "Immutable release artifact for $tag.")
        $arguments += $assets.FullName
        Invoke-ReleaseCommand gh $arguments
    }

    $syncPr = & gh pr list --base dev --head main --state open --json number --jq '.[0].number'
    if (-not $syncPr) {
        $syncUrl = & gh pr create --base dev --head main --title "chore: sync main after $tag" --body "Synchronize the published $tag release into dev."
        if ($LASTEXITCODE -ne 0) { throw 'Cannot create main-to-dev sync PR.' }
        $syncPr = & gh pr view $syncUrl --json number --jq '.number'
    }
    Wait-PullRequestChecks -PullRequest "$syncPr"
    Invoke-ReleaseCommand gh @('pr', 'merge', "$syncPr", '--merge', '--delete-branch=false')
    Invoke-ReleaseCommand git @('fetch', 'origin', 'dev')

    $recordBranch = "docs/v$Version-release-complete"
    Invoke-ReleaseCommand git @('switch', '-C', $recordBranch, 'origin/dev')
    $nextJson = if ($NextVersion) { "`"$NextVersion`"" } else { 'null' }
    $versionTemporary = Join-Path $root 'version.json.tmp'
    & jq --arg current $Version ".current=`$current | .next=$nextJson" version.json | Set-Content -LiteralPath $versionTemporary -Encoding utf8
    if ($LASTEXITCODE -ne 0) { throw 'Cannot update version.json.' }
    Move-Item -LiteralPath $versionTemporary -Destination (Join-Path $root 'version.json') -Force
    $record = Join-Path $root "docs/operations/change-log/$([DateTime]::Now.ToString('yyyy-MM-dd'))-v$($Version.Replace('.',''))-release-notes.md"
    $mainCommit = Get-ReleaseSessionValue -Path $session -Filter '.main_commit'
    $artifactSha = Get-ReleaseSessionValue -Path $session -Filter '.artifact_sha256'
    $stagingRun = Get-ReleaseSessionValue -Path $session -Filter '.staging.workflow_run_id|tostring'
    $productionRun = Get-ReleaseSessionValue -Path $session -Filter '.production.workflow_run_id|tostring'
    @"
# $tag リリース記録

$([DateTime]::Now.ToString('yyyy-MM-dd')) JST、$tagを商用環境へ公開した。

- main SHA: ``$mainCommit``
- 成果物SHA256: ``$artifactSha``
- ステージングworkflow run: ``$stagingRun``
- 商用workflow run: ``$productionRun``
- ステージング・商用の自動検証および実機確認: 合格

詳細なバックアップ、配置ログ、検証結果は各workflow runとリリースセッションを参照する。
"@ | Set-Content -LiteralPath $record -Encoding utf8
    Invoke-ReleaseCommand git @('add', '--', 'version.json', $record)
    Invoke-ReleaseCommand git @('commit', '-m', "docs: record $tag release completion")
    Invoke-ReleaseCommand git @('push', '--set-upstream', 'origin', $recordBranch)
    $recordUrl = & gh pr create --base dev --head $recordBranch --title "docs: record $tag release completion" --body "Record the completed release and advance version state."
    if ($LASTEXITCODE -ne 0) { throw 'Cannot create release record PR.' }
    $recordPr = & gh pr view $recordUrl --json number --jq '.number'
    Wait-PullRequestChecks -PullRequest "$recordPr"
    Invoke-ReleaseCommand gh @('pr', 'merge', "$recordPr", '--merge', '--delete-branch')

    $sourceBranch = Get-ReleaseSessionValue -Path $session -Filter '.branch'
    if ($sourceBranch -and $sourceBranch -ne 'dev' -and $sourceBranch -ne 'main') {
        & git push origin --delete $sourceBranch 2>$null
    }
    Update-ReleaseSession -Path $session -Filter '.state="completed" | .github_release=$release | .sync_pr=($sync|tonumber) | .record_pr=($record|tonumber)' -JqArguments @('--arg', 'release', "https://github.com/$(& gh repo view --json nameWithOwner --jq '.nameWithOwner')/releases/tag/$tag", '--arg', 'sync', "$syncPr", '--arg', 'record', "$recordPr")
    Write-Host "$tag release processing completed."
} finally { Pop-Location }
