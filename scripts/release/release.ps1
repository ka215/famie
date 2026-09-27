[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$Version,
    [Parameter(Mandatory)][ValidateSet('staging', 'production')][string]$Environment,
    [switch]$Apply,
    [switch]$Resume,
    [switch]$ConfirmStaging,
    [switch]$NonInteractive
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')
$root = Get-ReleaseRepoRoot
Push-Location $root
try {
    Assert-StableVersion -Version $Version
    Assert-RequiredTools -Tools @('git', 'gh', 'jq')
    $session = Get-ReleaseSessionPath -Root $root -Version $Version
    if (-not (Test-Path -LiteralPath $session)) { throw "Missing release session for v$Version. Run prepare-release.ps1 first." }
    $state = Get-ReleaseSessionValue -Path $session -Filter '.state'

    if ($ConfirmStaging) {
        if ($Environment -ne 'staging' -or -not $Apply) { throw '-ConfirmStaging requires -Environment staging -Apply.' }
        if ($state -notin @('staging_deployed', 'staging_verified')) { throw "Cannot confirm staging from state: $state" }
        Update-ReleaseSession -Path $session -Filter '.state="staging_verified" | .staging.user_verified=true'
        Write-Host "Staging user verification recorded for v$Version."
        exit 0
    }

    $runId = Get-ReleaseSessionValue -Path $session -Filter '.workflow_run_id|tostring'
    Write-Host "Release:     v$Version"
    Write-Host "Environment: $Environment"
    Write-Host "Artifact run: $runId"
    Write-Host "Mode:        $(if ($Apply) { 'apply' } else { 'dry-run' })"

    $workflowRef = 'dev'
    if ($Environment -eq 'staging') {
        if ($state -notin @('artifact_ready', 'staging_deployed', 'staging_verified')) { throw "Staging cannot run from state: $state" }
    } else {
        $verified = & jq -er '.staging.user_verified == true' $session
        if ($LASTEXITCODE -ne 0 -or $verified -ne 'true') { throw 'Record staging real-device verification before production.' }
        Invoke-ReleaseCommand git @('fetch', 'origin', 'dev', 'main')
        $difference = & git rev-list --count 'origin/main..origin/dev'
        if ($LASTEXITCODE -ne 0 -or [int]$difference -eq 0) { throw 'dev has no release commits beyond main.' }
        if ($Apply) {
            $pr = & gh pr list --base main --head dev --state open --json number --jq '.[0].number'
            if (-not $pr) {
                $url = & gh pr create --base main --head dev --title "release: v$Version" --body "Promote the staging-verified immutable artifact for v$Version."
                if ($LASTEXITCODE -ne 0) { throw 'Cannot create production PR.' }
                $pr = & gh pr view $url --json number --jq '.number'
            }
            Update-ReleaseSession -Path $session -Filter '.state="production_pr_created" | .production_pr=($pr|tonumber)' -JqArguments @('--arg', 'pr', "$pr")
            Wait-PullRequestChecks -PullRequest "$pr"
            Invoke-ReleaseCommand gh @('pr', 'merge', "$pr", '--merge', '--delete-branch=false')
            Invoke-ReleaseCommand git @('fetch', 'origin', 'main')
            $mainCommit = & git rev-parse origin/main
            $mainTree = & git rev-parse 'origin/main^{tree}'
            $candidateTree = Get-ReleaseSessionValue -Path $session -Filter '.candidate_tree'
            if ($mainTree -ne $candidateTree) { throw 'main tree does not match the staging artifact tree.' }
            $tag = "v$Version"
            $existingTag = & git ls-remote --tags origin "refs/tags/$tag^{}"
            if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect remote release tag.' }
            if ($existingTag) {
                $tagCommit = ($existingTag -split "`t")[0]
                if ($tagCommit -ne $mainCommit) { throw "$tag already points to another commit." }
            } else {
                Invoke-ReleaseCommand git @('tag', '-a', $tag, $mainCommit, '-m', "Release $tag")
                Invoke-ReleaseCommand git @('push', 'origin', $tag)
            }
            Update-ReleaseSession -Path $session -Filter '.state="tagged" | .main_commit=$commit | .main_tree=$tree | .tag=$tag' -JqArguments @('--arg', 'commit', $mainCommit, '--arg', 'tree', $mainTree, '--arg', 'tag', $tag)
        }
        $workflowRef = 'main'
    }

    Invoke-ReleaseCommand gh @('workflow', 'run', 'deploy-release.yml', '--ref', $workflowRef, '-f', "version=$Version", '-f', "environment=$Environment", '-f', "artifact_run_id=$runId", '-f', "apply=$($Apply.ToString().ToLowerInvariant())")
    Start-Sleep -Seconds 3
    $deployRun = & gh run list --workflow deploy-release.yml --branch $workflowRef --event workflow_dispatch --limit 1 --json databaseId --jq '.[0].databaseId'
    if ($LASTEXITCODE -ne 0 -or -not $deployRun) { throw 'Cannot locate deployment workflow run.' }
    Invoke-ReleaseCommand gh @('run', 'watch', "$deployRun", '--exit-status')
    if ($Apply) {
        if ($Environment -eq 'staging') {
            Update-ReleaseSession -Path $session -Filter '.state="staging_deployed" | .staging.workflow_run_id=($run|tonumber) | .staging.automated_verified=true' -JqArguments @('--arg', 'run', "$deployRun")
            Write-Host "Staging deployed. After real-device verification run: ./scripts/release/release.ps1 -Version $Version -Environment staging -ConfirmStaging -Apply"
        } else {
            Update-ReleaseSession -Path $session -Filter '.state="production_verified" | .production.workflow_run_id=($run|tonumber) | .production.automated_verified=true' -JqArguments @('--arg', 'run', "$deployRun")
            Write-Host "Production automated verification passed. Complete real-device verification before complete-release.ps1."
        }
    }
} finally { Pop-Location }
