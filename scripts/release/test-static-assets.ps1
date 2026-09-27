$ErrorActionPreference = 'Stop'
$fixture = Join-Path $PSScriptRoot "../../.temp/static-assets-test-$([guid]::NewGuid().ToString('N'))"
$nuxt = Join-Path $fixture '_nuxt'
New-Item -ItemType Directory -Force $nuxt | Out-Null
try {
    Set-Content -LiteralPath (Join-Path $fixture 'index.html') -Value '<script type="module" src="/_nuxt/app.js"></script><link rel="stylesheet" href="/_nuxt/app.css">'
    Set-Content -LiteralPath (Join-Path $nuxt 'app.js') -Value 'console.log("ok")'
    Set-Content -LiteralPath (Join-Path $nuxt 'app.css') -Value 'body{}'
    foreach ($file in @('sw.js','manifest.webmanifest','maintenance.html','maintenance.json')) {
        Set-Content -LiteralPath (Join-Path $fixture $file) -Value '{}'
    }
    & node (Join-Path $PSScriptRoot 'verify-static-assets.mjs') $fixture
    if ($LASTEXITCODE -ne 0) { throw 'Valid fixture was rejected.' }

    Set-Content -LiteralPath (Join-Path $fixture 'index.html') -Value '<script type="module" src="/_nuxt/missing.js"></script>'
    & node (Join-Path $PSScriptRoot 'verify-static-assets.mjs') $fixture 2>$null
    if ($LASTEXITCODE -eq 0) { throw 'Missing asset was accepted.' }

    Set-Content -LiteralPath (Join-Path $fixture 'index.html') -Value '<script type="module" src="/@vite/client"></script>'
    & node (Join-Path $PSScriptRoot 'verify-static-assets.mjs') $fixture 2>$null
    if ($LASTEXITCODE -eq 0) { throw 'Development client reference was accepted.' }
    Write-Host 'Static asset verifier tests passed.'
} finally {
    Remove-Item -LiteralPath $fixture -Recurse -Force -ErrorAction SilentlyContinue
}
