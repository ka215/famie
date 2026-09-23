function Get-ReleaseVersionState {
    param([string]$Root)
    $state = & jq -ser -f (Join-Path $PSScriptRoot 'version-state.jq') (Join-Path $Root 'version.json') (Join-Path $Root 'backend/package.json') (Join-Path $Root 'frontend/package.json')
    if ($LASTEXITCODE -ne 0 -or -not $state) { throw 'Release version validation failed.' }
    $fields = $state -split "`t"
    if ($fields.Count -ne 3) { throw 'Unexpected version validation result.' }
    return @{ Current = $fields[0]; Next = $fields[1]; State = $fields[2] }
}

function Set-ReleasePackageVersions {
    param([string]$Root, [string]$ExpectedNext)
    $state = Get-ReleaseVersionState -Root $Root
    if ($state.Next -ne $ExpectedNext) { throw 'Release target changed during preparation.' }
    if ($state.State -eq 'next') { return }
    # Validate both files before changing either; retries accept both already at next.
    $files = @((Join-Path $Root 'backend/package.json'), (Join-Path $Root 'frontend/package.json'))
    $originals = @{}
    foreach ($file in $files) { $originals[$file] = [System.IO.File]::ReadAllBytes($file) }
    $updated = @()
    foreach ($file in $files) {
        $indentArgs = @(if ($file -eq $files[0]) { '--indent'; '4' } else { '--tab' })
        $content = & jq @indentArgs --arg version $ExpectedNext '.version = $version' $file
        if ($LASTEXITCODE -ne 0) { throw "Cannot update version: $file" }
        $updated += ($content -join "`n") + "`n"
    }
    try {
        for ($i = 0; $i -lt $files.Count; $i++) { [System.IO.File]::WriteAllText($files[$i], $updated[$i]) }
    } catch {
        foreach ($file in $files) { [System.IO.File]::WriteAllBytes($file, $originals[$file]) }
        throw
    }
}
