#requires -Version 7.2
[CmdletBinding()]
param(
    [ValidateSet('Plan', 'Create', 'Verify')][string]$Mode = 'Plan',
    [Parameter(Mandatory)][string]$KeyFile,
    [Parameter(Mandatory)][string]$PublicFile,
    [Parameter(Mandatory)][string]$KeyId,
    [string]$ExpectedSourceSha256,
    [string]$DestinationDirectory,
    [string]$DpapiPassphraseFile,
    [string]$AuditDirectory,
    [string]$Operator,
    [string]$PhpPath = 'php',
    [switch]$Apply
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if (-not $IsWindows) { throw 'This operator wrapper requires Windows PowerShell 7.2 or newer.' }
$executable = (Get-Command -Name $PhpPath -CommandType Application).Source
$native = Join-Path $PSScriptRoot 'lifecycle-key-recovery.php'
$effectiveMode = if ($Mode -eq 'Create' -and -not $Apply) { 'plan' } else { $Mode.ToLowerInvariant() }
if ($effectiveMode -ne 'plan' -and $ExpectedSourceSha256 -notmatch '\A[a-f0-9]{64}\z') {
    throw 'Run Plan first and supply the separately retained checksum of the selected input key.'
}
if ($effectiveMode -eq 'create' -and -not $DestinationDirectory) { throw 'A private destination directory is required.' }
if ($effectiveMode -ne 'plan' -and (-not $AuditDirectory -or -not $Operator)) { throw 'A private audit directory and opaque operator ID are required.' }
if ($Apply -and $Mode -ne 'Create') { throw 'Apply is only valid for Create.' }
$arguments = @(('--key-file=' + (Resolve-Path -LiteralPath $KeyFile).ProviderPath),
    ('--public-file=' + (Resolve-Path -LiteralPath $PublicFile).ProviderPath), ('--key-id=' + $KeyId))
if ($ExpectedSourceSha256) { $arguments += '--expected-source-sha256=' + $ExpectedSourceSha256 }
if ($DestinationDirectory) { $arguments += '--destination=' + (Resolve-Path -LiteralPath $DestinationDirectory).ProviderPath }
if ($AuditDirectory) { $arguments += '--audit-directory=' + (Resolve-Path -LiteralPath $AuditDirectory).ProviderPath }
if ($Operator) { $arguments += '--operator=' + $Operator }

function Invoke-KeyRecovery {
    param([string]$Action, [Security.SecureString[]]$Secrets = @())
    $start = [Diagnostics.ProcessStartInfo]::new()
    $start.FileName = $executable
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.RedirectStandardInput = $true
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    foreach ($name in @($start.Environment.Keys)) {
        if ($name -like 'DB_*' -or $name -like 'PG*') { [void]$start.Environment.Remove($name) }
    }
    $start.Environment['DB_CONNECTION'] = 'disabled-key-recovery'
    $start.Environment['DB_URL'] = ''
    $start.Environment['APP_ENV'] = 'key-recovery'
    $start.ArgumentList.Add($native)
    $start.ArgumentList.Add('--mode=' + $Action)
    foreach ($argument in $arguments) { $start.ArgumentList.Add($argument) }
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $start
    try {
        [void]$process.Start()
        $stdoutTask = $process.StandardOutput.ReadToEndAsync()
        $stderrTask = $process.StandardError.ReadToEndAsync()
        foreach ($protectedValue in $Secrets) {
            $plain = [Net.NetworkCredential]::new('', $protectedValue).Password
            $bytes = [Text.Encoding]::UTF8.GetBytes($plain)
            $encoded = [Convert]::ToBase64String($bytes)
            try { $process.StandardInput.WriteLine($encoded) }
            finally {
                [Array]::Clear($bytes, 0, $bytes.Length)
                $plain = $null
                $encoded = $null
            }
        }
        $process.StandardInput.Close()
        if (-not $process.WaitForExit(30000)) {
            $process.Kill($true)
            [void]$process.WaitForExit(5000)
            throw 'Key recovery helper timed out. Preserve originals and treat destination files as unverified.'
        }
        $stdout = $stdoutTask.GetAwaiter().GetResult()
        [void]$stderrTask.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0) { throw 'Key recovery failed. Preserve originals; destination files are unverified.' }
        return $stdout.Trim()
    } finally {
        $process.Dispose()
    }
}

if ($effectiveMode -eq 'plan') {
    Invoke-KeyRecovery 'plan'
    exit
}
[void](Invoke-KeyRecovery 'plan')
$old = $null
$new = $null
$confirm = $null
try {
    if ($effectiveMode -eq 'verify') {
        $new = Read-Host 'Portable key passphrase' -AsSecureString
        Invoke-KeyRecovery 'verify' @($new)
    } else {
        if ($DpapiPassphraseFile) {
            $old = Import-Clixml -LiteralPath $DpapiPassphraseFile
            if ($old -isnot [Security.SecureString]) { throw 'The selected DPAPI file is not a protected passphrase.' }
        } else {
            $old = Read-Host 'Current key passphrase' -AsSecureString
        }
        $new = Read-Host 'New portable key passphrase (at least 16 characters)' -AsSecureString
        $confirm = Read-Host 'Confirm new portable key passphrase' -AsSecureString
        if ([Net.NetworkCredential]::new('', $new).Password -cne [Net.NetworkCredential]::new('', $confirm).Password) {
            throw 'Passphrase confirmation differs. No portable key was created.'
        }
        Invoke-KeyRecovery 'create' @($old, $new)
    }
} finally {
    foreach ($protectedValue in @($old, $new, $confirm)) {
        if ($protectedValue -is [Security.SecureString]) { $protectedValue.Dispose() }
    }
}
