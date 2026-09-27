$ErrorActionPreference = 'Stop'

function Get-ReleaseRepoRoot {
    return [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
}

function Assert-StableVersion {
    param([Parameter(Mandatory)][string]$Version)
    if ($Version -notmatch '^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$') {
        throw "Version must be stable X.Y.Z without leading zeroes: $Version"
    }
}

function Invoke-ReleaseCommand {
    param(
        [Parameter(Mandatory)][string]$Program,
        [Parameter()][string[]]$Arguments = @()
    )
    & $Program @Arguments
    if ($LASTEXITCODE -ne 0) { throw "$Program failed (exit $LASTEXITCODE)" }
}

function Get-ReleaseMetadata {
    param([Parameter(Mandatory)][string]$Root)
    $value = & jq -ser -f (Join-Path $Root 'scripts/release/version-state.jq') `
        (Join-Path $Root 'version.json') `
        (Join-Path $Root 'backend/package.json') `
        (Join-Path $Root 'frontend/package.json')
    if ($LASTEXITCODE -ne 0 -or -not $value) { throw 'Release version validation failed.' }
    $fields = $value -split "`t"
    if ($fields.Count -ne 3) { throw 'Unexpected version validation result.' }
    return @{ Current = $fields[0]; Next = $fields[1]; State = $fields[2] }
}

function Get-ReleaseSessionPath {
    param([Parameter(Mandatory)][string]$Root, [Parameter(Mandatory)][string]$Version)
    return Join-Path $Root ".release/sessions/v$Version.json"
}

function Initialize-ReleaseSession {
    param(
        [Parameter(Mandatory)][string]$Root,
        [Parameter(Mandatory)][string]$Version,
        [Parameter(Mandatory)][string]$ReleaseType,
        [Parameter(Mandatory)][string]$Branch
    )
    $path = Get-ReleaseSessionPath -Root $Root -Version $Version
    [System.IO.Directory]::CreateDirectory((Split-Path -Parent $path)) | Out-Null
    if (Test-Path -LiteralPath $path) { throw "Release session already exists; rerun with -Resume: $path" }
    $temporary = "$path.tmp"
    & jq -n --arg version $Version --arg releaseType $ReleaseType --arg branch $Branch `
        --arg createdAt ([DateTime]::UtcNow.ToString('o')) `
        '{schema_version:1,version:$version,release_type:$releaseType,state:"initialized",branch:$branch,created_at:$createdAt,updated_at:$createdAt}' `
        | Set-Content -LiteralPath $temporary -Encoding utf8
    if ($LASTEXITCODE -ne 0) { throw 'Cannot initialize release session.' }
    Move-Item -LiteralPath $temporary -Destination $path -Force
    return $path
}

function Update-ReleaseSession {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$Filter,
        [Parameter()][string[]]$JqArguments = @()
    )
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "Missing release session: $Path" }
    $temporary = "$Path.tmp"
    $updatedAt = [DateTime]::UtcNow.ToString('o')
    & jq @JqArguments --arg updatedAt $updatedAt "$Filter | .updated_at = `$updatedAt" $Path `
        | Set-Content -LiteralPath $temporary -Encoding utf8
    if ($LASTEXITCODE -ne 0) { throw 'Cannot update release session.' }
    Move-Item -LiteralPath $temporary -Destination $Path -Force
}

function Get-ReleaseSessionValue {
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][string]$Filter)
    $value = & jq -er $Filter $Path
    if ($LASTEXITCODE -ne 0) { throw "Cannot read $Filter from release session." }
    return $value
}

function Assert-RequiredTools {
    param([string[]]$Tools)
    foreach ($tool in $Tools) { Get-Command $tool -ErrorAction Stop | Out-Null }
}

function Assert-CleanWorktree {
    $changes = & git status --porcelain
    if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect Git worktree.' }
    if ($changes) { throw "Worktree must be clean before applying release operations.`n$changes" }
}
