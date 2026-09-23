[CmdletBinding()]
param([string]$Bash = 'bash')

$ErrorActionPreference = 'Stop'
$scriptPath = Join-Path $PSScriptRoot 'deploy.sh'
$tokens = $null
$parseErrors = $null
[System.Management.Automation.Language.Parser]::ParseFile(
    (Join-Path $PSScriptRoot 'prepare.ps1'), [ref]$tokens, [ref]$parseErrors
) | Out-Null
if ($parseErrors.Count) { throw ($parseErrors | Out-String) }

& $Bash -n $scriptPath
if ($LASTEXITCODE -ne 0) { throw 'Invalid deployment script syntax.' }

# These cases must stop before inspecting or changing the deployment environment.
$cases = @(
    @{ Arguments = @('main'); Expected = 'Usage:' },
    @{ Arguments = @('v0.3.0', '--apply'); Expected = 'Create a DB backup first' },
    @{ Arguments = @('v0.3.0', '--db-backup'); Expected = 'Missing backup reference' },
    @{ Arguments = @('v0.3.0', '--force'); Expected = 'Unknown argument' }
)
foreach ($case in $cases) {
    $arguments = $case.Arguments
    $result = & $Bash $scriptPath @arguments 2>&1
    if ($LASTEXITCODE -eq 0 -or ($result | Out-String) -notmatch [regex]::Escape($case.Expected)) {
        throw "Unexpected result for: $arguments`n$result"
    }
}
Write-Host 'Release script syntax and 4 rejection cases passed.'
