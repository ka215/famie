$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'version.ps1')
$fixture = Join-Path $PSScriptRoot "../../.temp/version-test-$([guid]::NewGuid().ToString('N'))"
New-Item -ItemType Directory -Force (Join-Path $fixture 'backend'), (Join-Path $fixture 'frontend') | Out-Null
function Write-Fixture {
    param([string]$Current, [string]$Next, [string]$Backend, [string]$Frontend)
    $json = & jq -n --arg current $Current --arg next $Next '{current:$current,next:$next}'
    if ($LASTEXITCODE -ne 0) { throw 'jq failed' }
    [System.IO.File]::WriteAllText((Join-Path $fixture 'version.json'), ($json -join "`n"))
    foreach ($entry in @(@('backend', $Backend), @('frontend', $Frontend))) {
        $json = & jq -n --arg version $entry[1] '{version:$version,private:true}'
        if ($LASTEXITCODE -ne 0) { throw 'jq failed' }
        [System.IO.File]::WriteAllText((Join-Path $fixture "$($entry[0])/package.json"), ($json -join "`n"))
    }
}
Write-Fixture '0.2.0' '0.3.0' '0.2.0' '0.2.0'
if ((Get-ReleaseVersionState $fixture).State -ne 'current') { throw 'Initial state failed' }
Set-ReleasePackageVersions $fixture '0.3.0'
if ((Get-ReleaseVersionState $fixture).State -ne 'next') { throw 'Update failed' }
$hash = (Get-FileHash (Join-Path $fixture 'frontend/package.json')).Hash
Set-ReleasePackageVersions $fixture '0.3.0'
if ((Get-FileHash (Join-Path $fixture 'frontend/package.json')).Hash -ne $hash) { throw 'Retry changed file' }
Write-Fixture '0.9.0' '0.10.0' '0.9.0' '0.9.0'
Get-ReleaseVersionState $fixture | Out-Null
foreach ($case in @(
    @('0.2.0','0.3.0','0.2.0','0.3.0'),
    @('0.2.0','0.2.0','0.2.0','0.2.0'),
    @('0.3.0','0.2.0','0.3.0','0.3.0'),
    @('0.2.0','v0.3.0','0.2.0','0.2.0'),
    @('0.2.0','0.3.0','0.1.0','0.1.0'),
    @('0.2.0','0.03.0','0.2.0','0.2.0')
)) {
    Write-Fixture @case
    $caught = $false
    try { Get-ReleaseVersionState $fixture 2>$null | Out-Null } catch { $caught = $true }
    if (-not $caught) { throw "Invalid versions accepted: $case" }
}
Write-Host 'Version tests passed: initial state, update, retry, numeric ordering, 6 invalid cases.'
