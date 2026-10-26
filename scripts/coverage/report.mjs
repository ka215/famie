import assert from 'node:assert/strict'
import { appendFile, readFile } from 'node:fs/promises'

const [path] = process.argv.slice(2)
assert(path && process.env.GITHUB_STEP_SUMMARY, 'Report and GitHub summary destination required')
const summary = JSON.parse(await readFile(path, 'utf8'))
assert(['frontend-unit', 'backend-sqlite', 'backend-postgres'].includes(summary.target))
assert(/^[a-f0-9]{40}$/.test(summary.commit))
const lines = summary.totals.lines
assert(Number.isSafeInteger(lines.covered) && Number.isSafeInteger(lines.total) && lines.total > 0 && lines.covered <= lines.total)
await appendFile(process.env.GITHUB_STEP_SUMMARY,
  `### Coverage: ${summary.target}\n\nCommit: \`${summary.commit}\`\n\n` +
  `Lines: **${lines.covered}/${lines.total} (${lines.percent}%)**\n\n` +
  'Scopes are independent; do not average SQLite, PostgreSQL and frontend reports. ' +
  'No coverage threshold is applied until the initial baseline is reviewed.\n\n')
