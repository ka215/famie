$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

# Mock only the external CLI and delay; no GitHub mutation or real sleeping.
function Start-Sleep { param([int]$Seconds) }
function gh {
    $global:LASTEXITCODE = 0
    if ($args -contains '--watch') { return 'optional workflow skipped' }
    $script:reads++
    if ($script:states.Count -gt 1) {
        $value = $script:states[0]
        $script:states = $script:states[1..($script:states.Count - 1)]
        return $value
    }
    return $script:states[0]
}
foreach ($case in @(
    @{ States = @('MISSING', 'IN_PROGRESS', 'SUCCESS'); Pass = $true; Reads = 3 },
    @{ States = @('SUCCESS'); Pass = $true; Reads = 1 },
    @{ States = @('SKIPPED'); Pass = $false; Reads = 1 },
    @{ States = @('FAILURE'); Pass = $false; Reads = 1 },
    @{ States = @('MISSING'); Pass = $false; Reads = 24 }
)) {
    $script:states = $case.States
    $script:reads = 0
    $passed = $true
    try { Wait-PullRequestChecks -PullRequest '123' } catch { $passed = $false }
    if ($passed -ne $case.Pass -or $script:reads -ne $case.Reads) { throw 'Mandatory PR check regression.' }
}
Write-Host 'PR mandatory-check boundary tests: 5 passed.'
