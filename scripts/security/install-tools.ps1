[CmdletBinding()]
param([string]$Destination = (Join-Path $PSScriptRoot '../../.tools/security'))

$ErrorActionPreference = 'Stop'
$Destination = [IO.Path]::GetFullPath($Destination)
$platform = if ($IsWindows) { 'windows' } elseif ($IsLinux) { 'linux' } else { throw 'Supported platforms: Windows/Linux x64.' }
if ([Runtime.InteropServices.RuntimeInformation]::OSArchitecture -ne 'X64') { throw 'x64 is required.' }
New-Item -ItemType Directory -Force -Path $Destination | Out-Null
# Digests are pinned from upstream release assets, not fetched at installation time.
$tools = @(
    @{ Name='gitleaks'; Repo='gitleaks/gitleaks'; Tag='v8.30.1'; Windows='gitleaks_8.30.1_windows_x64.zip'; Linux='gitleaks_8.30.1_linux_x64.tar.gz'; WindowsHash='d29144deff3a68aa93ced33dddf84b7fdc26070add4aa0f4513094c8332afc4e'; LinuxHash='551f6fc83ea457d62a0d98237cbad105af8d557003051f41f3e7ca7b3f2470eb' },
    @{ Name='actionlint'; Repo='rhysd/actionlint'; Tag='v1.7.12'; Windows='actionlint_1.7.12_windows_amd64.zip'; Linux='actionlint_1.7.12_linux_amd64.tar.gz'; WindowsHash='6e7241b51e6817ea6a047693d8e6fed13b31819c9a0dd6c5a726e1592d22f6e9'; LinuxHash='8aca8db96f1b94770f1b0d72b6dddcb1ebb8123cb3712530b08cc387b349a3d8' },
    @{ Name='opengrep'; Repo='opengrep/opengrep'; Tag='v1.32.0'; Windows='opengrep_windows_x86.exe'; Linux='opengrep_manylinux_x86'; WindowsHash='ae7152809ec71340213e8f4de3701c83edbdb33f4ac05eca2987b96ec6826a5e'; LinuxHash='7360d60174880bd9214f2dfbe439b7bce82b7a3d44cfe945ba833c85cc8bda75' }
)
foreach ($tool in $tools) {
    $asset = if ($platform -eq 'windows') { $tool.Windows } else { $tool.Linux }
    $expected = if ($platform -eq 'windows') { $tool.WindowsHash } else { $tool.LinuxHash }
    $archive = Join-Path $Destination $asset
    if (!(Test-Path -LiteralPath $archive)) {
        Invoke-WebRequest -Uri "https://github.com/$($tool.Repo)/releases/download/$($tool.Tag)/$asset" -OutFile $archive
    }
    if ((Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expected) { throw "Checksum mismatch: $($tool.Name)" }
    $directory = Join-Path $Destination $tool.Name
    New-Item -ItemType Directory -Force -Path $directory | Out-Null
    $binary = Join-Path $directory ($tool.Name + $(if ($IsWindows) { '.exe' } else { '' }))
    if ($tool.Name -eq 'opengrep') { Copy-Item -LiteralPath $archive -Destination $binary -Force }
    elseif ($IsWindows) { Expand-Archive -LiteralPath $archive -DestinationPath $directory -Force }
    else {
        & tar -xzf $archive -C $directory
        if ($LASTEXITCODE -ne 0) { throw "Extraction failed: $($tool.Name)" }
    }
    if (!$IsWindows) {
        & chmod +x $binary
        if ($LASTEXITCODE -ne 0) { throw 'Cannot set executable permission.' }
    }
    Write-Host "$($tool.Name) $($tool.Tag): checksum verified"
}
