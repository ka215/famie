import assert from 'node:assert/strict'
import { readdir, readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { join } from 'node:path'

const root = fileURLToPath(new URL('../../', import.meta.url))
async function walk(directory, inspect) {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    assert(!entry.isSymbolicLink(), 'Unexpected symlink in static runtime scope')
    const path = join(directory, entry.name)
    if (entry.isDirectory()) await walk(path, inspect)
    else await inspect(path)
  }
}
const config = await readFile(join(root, 'frontend/nuxt.config.ts'), 'utf8')
assert.match(config, /\bssr:\s*false\s*,/, 'Static SPA configuration changed')
for (const directory of ['frontend/app', 'frontend/shared', 'backend/app']) {
  await walk(join(root, directory), async (path) => {
    const source = await readFile(path, 'utf8')
    assert(!/node-forge|\blisthen\b|\bforge\s*\./i.test(source), 'Application now references development HTTPS tooling')
  })
}
const packaging = await readFile(join(root, 'scripts/release/create-artifact.sh'), 'utf8')
assert(packaging.includes('cp -a frontend/.output/public "$work/payload/frontend/public"'), 'Review changed frontend packaging')
assert(!/cp[^\n]*frontend[^\n]*(node_modules|\.output\/server)/.test(packaging), 'Node runtime added to release payload')
if (process.argv.includes('--built')) {
  await walk(join(root, 'frontend/.output/public'), async (path) => {
    assert(!/[/\\](node_modules|server)[/\\]/.test(path), 'Node runtime directory in static output')
    if (/\.(js|mjs|html)$/.test(path)) {
      const source = await readFile(path, 'utf8')
      assert(!/node-forge|DigestAlgorithm|RSASSA-PKCS1-V1_5/.test(source), 'Review unexpected forge marker in public assets')
    }
  })
}
console.log('Static runtime boundary checks passed (static SPA / PHP API; no development HTTPS tooling).')
