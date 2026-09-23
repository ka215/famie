[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$ExpectedBranch,
    [Parameter(Mandatory)][string]$ExpectedVersion,
    [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string]$CommitMessage,
    [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string]$PrTitle
)

# Called after prepare.ps1 has obtained consent to publish every displayed change.
$ErrorActionPreference = 'Stop'
$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
. (Join-Path $PSScriptRoot 'version.ps1')
function Invoke-Checked {
    param([string]$Program, [string[]]$Arguments)
    & $Program @Arguments
    if ($LASTEXITCODE -ne 0) { throw "$Program failed (exit $LASTEXITCODE)" }
}
Push-Location $repoRoot
try {
    $branch = & git branch --show-current
    if ($LASTEXITCODE -ne 0 -or $branch -ne $ExpectedBranch -or $branch -notmatch '^(feature|hotfix)/') {
        throw 'Branch changed or is not a feature/hotfix branch. Publishing stopped.'
    }
    $base = if ($branch -like 'hotfix/*') { 'main' } else { 'dev' }
    $release = Get-ReleaseVersionState -Root $repoRoot
    if ($release.Next -ne $ExpectedVersion -or $release.State -ne 'next') { throw 'Prepared package versions do not match the release target.' }
    Get-Command gh -ErrorAction Stop | Out-Null
    Invoke-Checked gh @('auth', 'status')
    $remote = & git remote get-url origin
    if ($LASTEXITCODE -ne 0) { throw 'origin is not configured.' }
    $repo = & gh repo view $remote --json nameWithOwner --jq '.nameWithOwner'
    if ($LASTEXITCODE -ne 0 -or -not $repo) { throw 'Cannot resolve origin on GitHub.' }

    Invoke-Checked git @('add', '--all', '--', '.')
    & git diff --cached --quiet
    $diffExit = $LASTEXITCODE
    if ($diffExit -gt 1) { throw 'Cannot inspect staged changes.' }
    if ($diffExit -eq 1) { Invoke-Checked git @('commit', '-m', $CommitMessage) }
    Invoke-Checked git @('push', '--set-upstream', 'origin', $branch)

    $existing = & gh pr list --repo $repo --base $base --head $branch --state open --json url --jq '.[].url'
    if ($LASTEXITCODE -ne 0) { throw 'Cannot check existing PRs. Branch has been pushed; retry publishing later.' }
    if ($existing) {
        Write-Host "Updated existing PR: $existing"
    } else {
        $bodyFile = [System.IO.Path]::GetTempFileName()
        try {
            [System.IO.File]::WriteAllText($bodyFile, "Release preparation for $branch.`n`nReview the source changes and generated assets before merging.`n")
            Invoke-Checked gh @('pr', 'create', '--repo', $repo, '--base', $base, '--head', $branch, '--title', $PrTitle, '--body-file', $bodyFile)
        } finally { Remove-Item -LiteralPath $bodyFile }
    }
} finally { Pop-Location }
