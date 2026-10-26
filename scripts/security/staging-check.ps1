[CmdletBinding()]
param([Parameter(Mandatory)][string]$Candidate)

$ErrorActionPreference = 'Stop'
if ($Candidate -notmatch '\A[0-9a-f]{40}\z') { throw 'Candidate must be a full SHA.' }
if ($env:DEPLOY_HOST -ne 's140.coreserver.jp' -or $env:DEPLOY_USER -ne 'ka2') { throw 'Unexpected staging SSH target.' }
foreach ($name in @('DEPLOY_SSH_KEY', 'DEPLOY_KNOWN_HOSTS', 'FAMIE_SECURITY_FIXTURE')) {
    if (![Environment]::GetEnvironmentVariable($name)) { throw "Missing staging configuration: $name" }
}
$temporary = Join-Path ([IO.Path]::GetTempPath()) ('famie-staging-security-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temporary | Out-Null
$previousCandidate = $env:FAMIE_SECURITY_CANDIDATE
try {
    $key = Join-Path $temporary 'key'
    $knownHosts = Join-Path $temporary 'known_hosts'
    [IO.File]::WriteAllText($key, $env:DEPLOY_SSH_KEY + "`n")
    [IO.File]::WriteAllText($knownHosts, $env:DEPLOY_KNOWN_HOSTS + "`n")
    if ($IsWindows) {
        & icacls $key /inheritance:r /grant:r "${env:USERNAME}:(R)" > $null
        if ($LASTEXITCODE -ne 0) { throw 'Cannot protect temporary SSH key.' }
    } else { throw 'The staging runner must be Windows.' }
    $manifest = & ssh -i $key -o BatchMode=yes -o StrictHostKeyChecking=yes -o UpdateHostKeys=no -o ConnectTimeout=15 -o ServerAliveInterval=10 -o ServerAliveCountMax=2 -o "UserKnownHostsFile=$knownHosts" "$($env:DEPLOY_USER)@$($env:DEPLOY_HOST)" 'cat "$HOME/famie-stg-release-backups/current-manifest.json"' 2> (Join-Path $temporary 'ssh.log')
    if ($LASTEXITCODE -ne 0) { throw 'Cannot verify deployed staging manifest.' }
    $manifest | & jq -e --arg candidate $Candidate '.source_commit == $candidate and (.source_tree|test("^[0-9a-f]{40}$"))' > $null
    if ($LASTEXITCODE -ne 0) { throw 'Staging candidate does not match requested SHA.' }
    $env:FAMIE_SECURITY_CANDIDATE = $Candidate
    & node (Join-Path $PSScriptRoot 'staging-check.mjs')
    if ($LASTEXITCODE -ne 0) { throw 'Staging security checks did not pass.' }
    $after = & ssh -i $key -o BatchMode=yes -o StrictHostKeyChecking=yes -o UpdateHostKeys=no -o ConnectTimeout=15 -o ServerAliveInterval=10 -o ServerAliveCountMax=2 -o "UserKnownHostsFile=$knownHosts" "$($env:DEPLOY_USER)@$($env:DEPLOY_HOST)" 'cat "$HOME/famie-stg-release-backups/current-manifest.json"' 2> (Join-Path $temporary 'ssh-after.log')
    if ($LASTEXITCODE -ne 0) { throw 'Cannot verify staging manifest after scanning.' }
    $after | & jq -e --arg candidate $Candidate '.source_commit == $candidate' > $null
    if ($LASTEXITCODE -ne 0) { throw 'Staging candidate changed during scanning.' }
} finally {
    $env:FAMIE_SECURITY_CANDIDATE = $previousCandidate
    if (([IO.Path]::GetFullPath($temporary)).StartsWith([IO.Path]::GetFullPath([IO.Path]::GetTempPath()), [StringComparison]::OrdinalIgnoreCase)) {
        Remove-Item -LiteralPath $temporary -Recurse -Force
    }
}
