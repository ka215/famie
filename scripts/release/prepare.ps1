[CmdletBinding()]
param(
    [ValidateSet('Prompt', 'Skip', 'Publish')]
    [string]$PublishMode = 'Prompt',
    [string]$CommitMessage = 'chore: prepare release',
    [string]$PrTitle = 'chore: prepare next release'
)

$ErrorActionPreference = 'Stop'
$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))

function Invoke-Checked {
    param([string]$Program, [string[]]$Arguments)
    & $Program @Arguments
    if ($LASTEXITCODE -ne 0) { throw "$Program failed (exit $LASTEXITCODE)" }
}

Push-Location $repoRoot
try {
    $branch = & git branch --show-current
    if ($LASTEXITCODE -ne 0 -or $branch -notmatch '^(feature|hotfix)/') {
        throw 'Run release preparation on a feature/* or hotfix/* branch.'
    }
    foreach ($tool in @('git', 'pnpm', 'php', 'composer')) {
        Get-Command $tool -ErrorAction Stop | Out-Null
    }

    Push-Location (Join-Path $repoRoot 'frontend')
    try {
        Invoke-Checked pnpm @('install', '--frozen-lockfile')
        Invoke-Checked pnpm @('lint')
        Invoke-Checked pnpm @('typecheck')
        Invoke-Checked pnpm @('test:e2e')

        $outputPath = [System.IO.Path]::GetFullPath((Join-Path $repoRoot 'frontend/.output'))
        $expectedPath = Join-Path $repoRoot 'frontend\.output'
        if ($outputPath -ne $expectedPath) { throw 'Unexpected build output path.' }
        if (Test-Path -LiteralPath $outputPath) {
            if ((Get-Item -LiteralPath $outputPath -Force).Attributes -band [System.IO.FileAttributes]::ReparsePoint) {
                throw 'Build output must not be a symlink or junction.'
            }
            Remove-Item -LiteralPath $outputPath -Recurse -Force
        }
        Invoke-Checked pnpm @('build')
        foreach ($asset in @('index.html', '.htaccess', 'sw.js', 'manifest.webmanifest')) {
            if (-not (Test-Path -LiteralPath (Join-Path $outputPath "public/$asset") -PathType Leaf)) {
                throw "Missing release asset: $asset"
            }
        }
    } finally { Pop-Location }

    Write-Host 'Preparation complete. Review ALL changes below (including untracked files).'
    Invoke-Checked git @('--no-pager', 'diff', 'HEAD', '--stat')
    Invoke-Checked git @('--no-pager', 'status', '--short')

    $publish = $PublishMode -eq 'Publish'
    if ($PublishMode -eq 'Prompt') {
        $answer = Read-Host 'Commit ALL non-ignored changes shown above, push this branch, and create a PR? [y/N]'
        $publish = $answer -match '^(y|yes)$'
    }
    if ($publish) {
        & (Join-Path $PSScriptRoot 'publish.ps1') -ExpectedBranch $branch -CommitMessage $CommitMessage -PrTitle $PrTitle
    } else {
        Write-Host 'Publishing skipped. Changes remain in the working tree.'
    }
} finally { Pop-Location }
