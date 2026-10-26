import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdir, readFile, readdir, writeFile, rm } from 'node:fs/promises'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { frontendRoot, utilityFiles } from './utility-files.mjs'

const root = fileURLToPath(new URL('../../', import.meta.url))
const output = join(root, 'coverage-results/frontend-unit')
await mkdir(output, { recursive: true })
const summaryPath = join(output, 'summary.json')
await rm(summaryPath, { force: true })
const lcovPath = join(output, 'lcov.info')
await rm(lcovPath, { force: true })
const tests = (await readdir(join(frontendRoot, 'tests/unit')))
  .filter((name) => name.endsWith('.test.ts')).sort().map((name) => `tests/unit/${name}`)
const run = spawnSync(process.execPath, [
  '--experimental-transform-types', '--enable-source-maps', '--experimental-test-coverage',
  '--import', new URL('./preload-utilities.mjs', import.meta.url).href,
  '--test-coverage-include=shared/utils/**', '--test',
  '--test-reporter=spec', '--test-reporter-destination=stdout',
  '--test-reporter=lcov', `--test-reporter-destination=${lcovPath}`, ...tests,
], { cwd: frontendRoot, stdio: 'inherit', timeout: 120_000 })
assert(!run.error && run.status === 0, 'Frontend coverage test execution failed')
const expected = (await utilityFiles()).map((file) => relative(frontendRoot, file).replaceAll('\\', '/'))
const files = (await readFile(lcovPath, 'utf8')).split('end_of_record').filter((record) => record.includes('SF:')).map((record) => {
  const lines = record.trim().split(/\r?\n/)
  const metric = (prefix) => {
    const value = Number(lines.find((line) => line.startsWith(`${prefix}:`))?.slice(prefix.length + 1))
    assert(Number.isSafeInteger(value) && value >= 0, `Missing/invalid coverage metric: ${prefix}`)
    return value
  }
  const file = lines.find((line) => line.startsWith('SF:'))?.slice(3).replaceAll('\\', '/')
  assert(expected.includes(file), 'Unexpected source in frontend coverage')
  return {
    file: `frontend/${file}`,
    lines: { covered: metric('LH'), total: metric('LF') },
    branches: { covered: metric('BRH'), total: metric('BRF') },
    functions: { covered: metric('FNH'), total: metric('FNF') },
    uncovered_lines: lines.filter((line) => /^DA:\d+,0$/.test(line)).map((line) => Number(line.slice(3).split(',')[0])),
  }
})
assert.deepEqual(files.map(({ file }) => file.slice('frontend/'.length)).sort(), expected.sort(), 'Unloaded utility omitted from coverage')
const totals = {}
for (const metric of ['lines', 'branches', 'functions']) {
  const covered = files.reduce((sum, file) => sum + file[metric].covered, 0)
  const total = files.reduce((sum, file) => sum + file[metric].total, 0)
  assert(total > 0 && covered <= total, 'Invalid frontend coverage denominator')
  totals[metric] = { covered, total, percent: Number((100 * covered / total).toFixed(2)) }
}
const git = (...args) => {
  const result = spawnSync('git', args, { cwd: root, encoding: 'utf8' })
  assert.equal(result.status, 0, 'Cannot identify measured source')
  return result.stdout.trim()
}
const summary = {
  schema_version: 1, target: 'frontend-unit', scope: 'frontend/shared/utils/*.ts; excludes Vue/composables/E2E/types',
  commit: git('rev-parse', 'HEAD'), tree: git('rev-parse', 'HEAD^{tree}'),
  working_tree_dirty: git('status', '--porcelain') !== '', measured_at: new Date().toISOString(),
  tool: `Node ${process.version} native test coverage / TypeScript transform / source maps`,
  totals, files,
}
await writeFile(summaryPath, `${JSON.stringify(summary, null, 2)}\n`)
console.log(`Frontend unit coverage: ${totals.lines.covered}/${totals.lines.total} lines (${totals.lines.percent}%), ${files.length} utility files`)
