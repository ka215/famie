[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$temporary = Join-Path ([IO.Path]::GetTempPath()) ('famie-audit-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temporary | Out-Null
$failed = $false
try {
    Push-Location (Join-Path $root 'frontend')
    try {
        $report = Join-Path $temporary 'pnpm.json'
        & pnpm audit --json 2> (Join-Path $temporary 'pnpm.stderr') | Set-Content $report
        $status = $LASTEXITCODE
        & jq -e 'type == "object" and (.error == null) and (.advisories | type == "object") and (.metadata.vulnerabilities | type == "object") and ([.metadata.vulnerabilities | .info,.low,.moderate,.high,.critical] | all(type == "number" and . >= 0))' $report > $null
        if ($LASTEXITCODE -ne 0 -or $status -notin @(0, 1)) { throw 'pnpm audit could not complete; no successful verdict.' }
        & jq '{tool:"pnpm", vulnerabilities:.metadata.vulnerabilities, findings:[.advisories[] | {module_name,severity,url}]}' $report
        $verdict = Join-Path $temporary 'verdict.json'
        & jq -f (Join-Path $PSScriptRoot 'audit-policy.jq') $report | Set-Content $verdict
        if ($LASTEXITCODE -ne 0) { throw 'Dependency audit policy could not be evaluated.' }
        Get-Content -LiteralPath $verdict
        & jq -e '.blocking | length == 0' $verdict > $null
        if ($LASTEXITCODE -ne 0) { $failed = $true }
        if ($status -eq 1) {
            & jq -e '.advisories | length > 0' $report > $null
            if ($LASTEXITCODE -ne 0) { throw 'pnpm returned an unexplained audit failure.' }
        }
    } finally { Pop-Location }
    Push-Location (Join-Path $root 'backend')
    try {
        $report = Join-Path $temporary 'composer.json'
        & composer audit --locked --format=json 2> (Join-Path $temporary 'composer.stderr') | Set-Content $report
        $status = $LASTEXITCODE
        & jq -e 'type == "object" and (.advisories | type == "object" or type == "array") and (.abandoned | type == "object" or type == "array")' $report > $null
        if ($LASTEXITCODE -ne 0 -or $status -notin @(0, 1, 2, 3)) { throw 'Composer audit could not complete; no successful verdict.' }
        if ($status -ne 0) {
            & jq -e '(.advisories|length) + (.abandoned|length) > 0' $report > $null
            if ($LASTEXITCODE -ne 0) { throw 'Composer returned an unexplained audit failure.' }
        }
        & jq '{tool:"composer", findings:[.advisories[][] | {packageName,severity,advisoryId,link}],abandoned_count:(.abandoned|length)}' $report
        # An advisory without a severity is not silently treated as low risk.
        & jq -e '[.advisories[][] | select(.severity == null or .severity == "high" or .severity == "critical")] | length == 0' $report > $null
        if ($LASTEXITCODE -ne 0) { $failed = $true }
    } finally { Pop-Location }
    if ($failed) { throw 'High/critical or unclassified dependency advisories require resolution.' }
} finally {
    if (([IO.Path]::GetFullPath($temporary)).StartsWith([IO.Path]::GetFullPath([IO.Path]::GetTempPath()), [StringComparison]::OrdinalIgnoreCase)) {
        Remove-Item -LiteralPath $temporary -Recurse -Force
    }
}
