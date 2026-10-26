$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$temporary = Join-Path ([IO.Path]::GetTempPath()) ('famie-audit-policy-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temporary | Out-Null
try {
    $fixture = Join-Path $temporary 'fixture.json'
    & jq -n '{advisories:{fixture:{module_name:"node-forge",severity:"high",url:"https://github.com/advisories/GHSA-86w9-cpqp-85rv",findings:[{version:"1.4.0",paths:[".>nuxt>@nuxt/nitro-server>nitropack>listhen>node-forge"]}]}}}' | Set-Content $fixture
    if ($LASTEXITCODE -ne 0) { throw 'Cannot create policy fixture.' }
    $cases = @(
        @{ Change='.'; Now=1793000000; Accepted=1; Blocking=0 },
        @{ Change='.'; Now=([DateTimeOffset]::Parse('2026-11-10T00:00:00+09:00').ToUnixTimeSeconds()); Accepted=0; Blocking=1 },
        @{ Change='.advisories.fixture.severity="critical"'; Now=1793000000; Accepted=0; Blocking=1 },
        @{ Change='.advisories.fixture.url="https://github.com/advisories/GHSA-other"'; Now=1793000000; Accepted=0; Blocking=1 },
        @{ Change='.advisories.fixture.findings[0].version="1.4.1"'; Now=1793000000; Accepted=0; Blocking=1 },
        @{ Change='.advisories.fixture.findings[0].paths += [".>node-forge"]'; Now=1793000000; Accepted=0; Blocking=1 },
        @{ Change='.advisories.fixture.findings=[]'; Now=1793000000; Accepted=0; Blocking=1 },
        @{ Change='.advisories.other = (.advisories.fixture | .module_name="other")'; Now=1793000000; Accepted=1; Blocking=1 },
        @{ Change='.advisories={}'; Now=1793000000; Accepted=0; Blocking=0 }
    )
    foreach ($case in $cases) {
        $report = Join-Path $temporary 'report.json'
        & jq $case.Change $fixture | Set-Content $report
        if ($LASTEXITCODE -ne 0) { throw 'Fixture mutation failed.' }
        $verdict = & jq --argjson now $case.Now -f (Join-Path $PSScriptRoot 'audit-policy.jq') $report
        if ($LASTEXITCODE -ne 0) { throw 'Policy evaluation failed.' }
        $verdict | & jq -e --argjson accepted $case.Accepted --argjson blocking $case.Blocking '(.accepted_exceptions|length)==$accepted and (.blocking|length)==$blocking' > $null
        if ($LASTEXITCODE -ne 0) { throw 'Exception policy did not enforce expected boundary.' }
    }
    Write-Host "Exception policy: $($cases.Count) boundary cases passed."
} finally {
    if (([IO.Path]::GetFullPath($temporary)).StartsWith([IO.Path]::GetFullPath([IO.Path]::GetTempPath()), [StringComparison]::OrdinalIgnoreCase)) {
        Remove-Item -LiteralPath $temporary -Recurse -Force
    }
}
