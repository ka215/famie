$global:famiePublishTestState = @{}
$ErrorActionPreference = 'Stop'
$global:famiePublishTestState.commands = [System.Collections.Generic.List[string]]::new()
$global:famiePublishTestState.failAt = ''
$global:famiePublishTestState.existingPr = ''
$global:famiePublishTestState.hasChanges = $true
$global:famiePublishTestState.branch = 'feature/test-release'
$global:famiePublishTestState.fixture = Join-Path $PSScriptRoot "../../.temp/publish-test-$([guid]::NewGuid().ToString('N'))"
$fixture = $global:famiePublishTestState.fixture
New-Item -ItemType Directory -Force (Join-Path $fixture 'scripts/release'), (Join-Path $fixture 'backend'), (Join-Path $fixture 'frontend') | Out-Null
foreach ($file in @('prepare.ps1','publish.ps1','version.ps1','version-state.jq')) {
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot $file) -Destination (Join-Path $fixture "scripts/release/$file")
}
$json = & jq -n '{current:"0.2.0",next:"0.3.0"}'
if ($LASTEXITCODE -ne 0) { throw 'jq failed' }
[System.IO.File]::WriteAllText((Join-Path $fixture 'version.json'), ($json -join "`n"))
$json = & jq -n '{version:"0.3.0"}'
if ($LASTEXITCODE -ne 0) { throw 'jq failed' }
foreach ($area in @('frontend','backend')) {
    [System.IO.File]::WriteAllText((Join-Path $fixture "$area/package.json"), ($json -join "`n"))
}

# Stub both CLIs: no commits, pushes or GitHub writes occur in these tests.
function git {
    $command = $args -join ' '
    $global:famiePublishTestState.commands.Add("git $command")
    $global:LASTEXITCODE = 0
    if ($command -eq 'branch --show-current') { return $global:famiePublishTestState.branch }
    if ($command -eq 'remote get-url origin') { return 'git@github.com:example/famie.git' }
    if ($command -eq 'diff --cached --quiet') { $global:LASTEXITCODE = [int]$global:famiePublishTestState.hasChanges }
    if ($global:famiePublishTestState.failAt -and $command.StartsWith($global:famiePublishTestState.failAt)) { $global:LASTEXITCODE = 1 }
}
function gh {
    $command = $args -join ' '
    $global:famiePublishTestState.commands.Add("gh $command")
    $global:LASTEXITCODE = 0
    if ($command.StartsWith('repo view')) { return 'example/famie' }
    if ($command.StartsWith('pr list')) { return $global:famiePublishTestState.existingPr }
}
function pnpm {
    $command = $args -join ' '
    $global:famiePublishTestState.commands.Add("pnpm $command")
    $global:LASTEXITCODE = 0
    if ($command -eq 'build') {
        $public = Join-Path $global:famiePublishTestState.fixture 'frontend/.output/public'
        New-Item -ItemType Directory -Force $public | Out-Null
        foreach ($asset in @('index.html','.htaccess','sw.js','manifest.webmanifest')) {
            New-Item -ItemType File -Path (Join-Path $public $asset) | Out-Null
        }
    }
}
function Run-Publish {
    & (Join-Path $global:famiePublishTestState.fixture 'scripts/release/publish.ps1') -ExpectedBranch $global:famiePublishTestState.branch -ExpectedVersion '0.3.0' -CommitMessage 'test release' -PrTitle 'test PR'
}
function Assert-Command {
    param([string]$Pattern, [bool]$Expected)
    $found = @($global:famiePublishTestState.commands | Where-Object { $_ -like $Pattern }).Count -gt 0
    if ($found -ne $Expected) { throw "Unexpected commands for $Pattern : $global:famiePublishTestState.commands" }
}

Run-Publish
Assert-Command 'git commit *' $true
Assert-Command 'git push *' $true
Assert-Command 'gh pr create *--base dev*' $true

$global:famiePublishTestState.commands.Clear()
$global:famiePublishTestState.hasChanges = $false
$global:famiePublishTestState.existingPr = 'https://github.com/example/famie/pull/1'
Run-Publish
Assert-Command 'git commit *' $false
Assert-Command 'gh pr create *' $false

foreach ($failure in @('commit', 'push')) {
    $global:famiePublishTestState.commands.Clear()
    $global:famiePublishTestState.hasChanges = $true
    $global:famiePublishTestState.existingPr = ''
    $global:famiePublishTestState.failAt = $failure
    $caught = $false
    try { Run-Publish } catch { $caught = $true }
    if (-not $caught) { throw "Failure was ignored: $failure" }
    Assert-Command 'gh pr create *' $false
    if ($failure -eq 'commit') { Assert-Command 'git push *' $false }
}
$global:famiePublishTestState.commands.Clear()
$global:famiePublishTestState.failAt = ''
$global:famiePublishTestState.branch = 'main'
$caught = $false
try { Run-Publish } catch { $caught = $true }
if (-not $caught) { throw 'main was allowed to publish directly.' }
Assert-Command 'git add *' $false
$global:famiePublishTestState.commands.Clear()
$global:famiePublishTestState.branch = 'feature/test-release'
& (Join-Path $fixture 'scripts/release/prepare.ps1') -PublishMode Publish
Assert-Command 'git commit -m chore: prepare v0.3.0' $true
Assert-Command 'gh pr create *--title release: v0.3.0*' $true
Write-Host '6 publishing scenarios passed (stub CLIs only, including automatic version labels).'
