$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$temporary = Join-Path ([IO.Path]::GetTempPath()) ('famie-audit-policy-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temporary | Out-Null
try {
    $fixture = Join-Path $temporary 'fixture.json'
    & jq -n '{advisories:{fixture:{module_name:"node-forge",severity:"high",url:"https://github.com/advisories/GHSA-86w9-cpqp-85rv",findings:[{version:"1.4.0",paths:[".>nuxt>@nuxt/nitro-server>nitropack>listhen>node-forge"]}]}}}' | Set-Content $fixture
    if ($LASTEXITCODE -ne 0) { throw 'Cannot create policy fixture.' }
    $cases = @(
        @{ Change='.'; Blocking=1 },
        @{ Change='.advisories.fixture.severity="critical"'; Blocking=1 },
        @{ Change='.advisories.fixture.severity=null'; Blocking=1 },
        @{ Change='.advisories.fixture.severity="unknown"'; Blocking=1 },
        @{ Change='.advisories.fixture.severity="moderate"'; Blocking=0 },
        @{ Change='.advisories.fixture.severity="low"'; Blocking=0 },
        @{ Change='.advisories.fixture.severity="info"'; Blocking=0 },
        @{ Change='.advisories.other = (.advisories.fixture | .module_name="other")'; Blocking=2 },
        @{ Change='.advisories={}'; Blocking=0 }
    )
    foreach ($case in $cases) {
        $report = Join-Path $temporary 'report.json'
        & jq $case.Change $fixture | Set-Content $report
        if ($LASTEXITCODE -ne 0) { throw 'Fixture mutation failed.' }
        $verdict = & jq -f (Join-Path $PSScriptRoot 'audit-policy.jq') $report
        if ($LASTEXITCODE -ne 0) { throw 'Policy evaluation failed.' }
        $verdict | & jq -e --argjson blocking $case.Blocking '(.accepted_exceptions|length)==0 and (.blocking|length)==$blocking' > $null
        if ($LASTEXITCODE -ne 0) { throw 'Audit policy did not enforce expected boundary.' }
    }
    Write-Host "Audit policy without exceptions: $($cases.Count) boundary cases passed."
} finally {
    if (([IO.Path]::GetFullPath($temporary)).StartsWith([IO.Path]::GetFullPath([IO.Path]::GetTempPath()), [StringComparison]::OrdinalIgnoreCase)) {
        Remove-Item -LiteralPath $temporary -Recurse -Force
    }
}
