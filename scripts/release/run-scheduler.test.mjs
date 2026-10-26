import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { chmod, mkdtemp, mkdir, readFile, readdir, rm, utimes, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import test from 'node:test'

const bash = process.platform === 'win32' ? 'C:/Program Files/Git/bin/bash.exe' : 'bash'
const script = fileURLToPath(new URL('./server/run-scheduler.sh', import.meta.url))
const shellPath = (path) => resolve(path).replaceAll('\\', '/').replace(/^([A-Za-z]):/, (_, drive) => `/${drive.toLowerCase()}`)

async function fixture(t, body) {
  const root = await mkdtemp(join(tmpdir(), 'famie-scheduler-test-'))
  assert.equal(dirname(root), resolve(tmpdir()))
  t.after(() => rm(root, { recursive: true, force: true }))
  const backend = join(root, 'famie-stg/backend')
  await mkdir(join(backend, 'storage/app/operations'), { recursive: true })
  await writeFile(join(backend, 'artisan'), '')
  const php = join(root, 'php-fixture.sh')
  await writeFile(php, `#!/usr/bin/env bash\n[[ "$*" == 'artisan schedule:run --no-interaction' ]] || exit 99\n${body}\n`)
  await chmod(php, 0o700)
  return { root, backend, php }
}

async function run(fixture, environment = 'staging', extra = {}) {
  return await new Promise((resolve, reject) => {
    const child = spawn(bash, ['--noprofile', '--norc', shellPath(script), environment], {
      env: { ...process.env, HOME: shellPath(fixture.root), PHPCLI: shellPath(fixture.php), ...extra },
      windowsHide: true,
    })
    let output = ''
    child.stdout.on('data', (data) => { output += data })
    child.stderr.on('data', (data) => { output += data })
    child.on('error', reject)
    child.on('close', (code) => resolve({ code, output }))
  })
}

async function history(fixture) {
  const directory = join(fixture.backend, 'storage/app/operations')
  const files = (await readdir(directory)).filter((file) => /^scheduler-.*\.jsonl$/.test(file))
  const rows = []
  for (const file of files) {
    rows.push(...(await readFile(join(directory, file), 'utf8')).trim().split('\n').filter(Boolean).map(JSON.parse))
  }
  return rows
}

test('records successful invocation without command output or secrets', async (t) => {
  const f = await fixture(t, 'echo PRIVATE_OUTPUT; echo PRIVATE_ERROR >&2; exit 0')
  const result = await run(f)

  assert.equal(result.code, 0)
  const rows = await history(f)
  assert.deepEqual(rows.map(({ phase, exit_code }) => ({ phase, exit_code })), [
    { phase: 'started', exit_code: null }, { phase: 'finished', exit_code: 0 },
  ])
  assert.equal(rows[0].run_id, rows[1].run_id)
  assert.equal(rows[0].environment, 'staging')
  assert.equal(rows[0].maintenance, false)
  assert.doesNotMatch(JSON.stringify(rows) + result.output, /PRIVATE_OUTPUT|PRIVATE_ERROR|php-fixture/)
})

test('preserves nonzero scheduler exit status in history', async (t) => {
  const f = await fixture(t, 'exit 27')

  const result = await run(f)

  assert.equal(result.code, 27)
  assert.equal((await history(f))[1].exit_code, 27)
})

test('records timeout instead of claiming completion success', async (t) => {
  const f = await fixture(t, 'sleep 10')

  const result = await run(f, 'staging', { FAMIE_SCHEDULER_TIMEOUT_SECONDS: '1' })

  assert.equal(result.code, 124)
  assert.equal((await history(f))[1].exit_code, 124)
})

test('rejects unrecognized environment before invoking scheduler', async (t) => {
  const f = await fixture(t, 'exit 0')

  const result = await run(f, 'other')

  assert.equal(result.code, 1)
  assert.deepEqual(await history(f), [])
})

test('records wrapper failure when PHP cannot start', async (t) => {
  const f = await fixture(t, 'exit 0')

  const result = await run(f, 'staging', { PHPCLI: shellPath(join(f.root, 'missing-php')) })

  assert.equal(result.code, 1)
  assert.deepEqual((await history(f)).map(({ phase, exit_code }) => ({ phase, exit_code })), [
    { phase: 'started', exit_code: null }, { phase: 'finished', exit_code: 1 },
  ])
})

test('records maintenance without treating zero scheduler status as task success', async (t) => {
  const f = await fixture(t, 'exit 0')
  await mkdir(join(f.backend, 'storage/framework'), { recursive: true })
  await writeFile(join(f.backend, 'storage/framework/down'), '{}')

  const result = await run(f)

  assert.equal(result.code, 0)
  assert.equal((await history(f))[0].maintenance, true)
  assert.equal((await history(f))[1].maintenance, true)
  assert.equal('results' in (await history(f))[1], false)
})

test('prunes only expired scheduler history and preserves other operation files', async (t) => {
  const f = await fixture(t, 'exit 0')
  const directory = join(f.backend, 'storage/app/operations')
  const expired = join(directory, 'scheduler-2000-01-01.jsonl')
  const other = join(directory, 'monitor-2000-01-01.jsonl')
  await writeFile(expired, 'old scheduler record')
  await writeFile(other, 'other operation record')
  const old = new Date(Date.now() - 32 * 24 * 60 * 60 * 1000)
  await utimes(expired, old, old)
  await utimes(other, old, old)

  const result = await run(f)

  assert.equal(result.code, 0)
  assert.equal((await readdir(directory)).includes('scheduler-2000-01-01.jsonl'), false)
  assert.equal(await readFile(other, 'utf8'), 'other operation record')
  assert.equal((await history(f)).length, 2)
})

test('refuses an invalid diagnostic file instead of silently running without history', async (t) => {
  const f = await fixture(t, 'exit 0')
  const day = new Date().toISOString().slice(0, 10)
  await mkdir(join(f.backend, 'storage/app/operations', `scheduler-${day}.jsonl`))

  const result = await run(f)

  assert.equal(result.code, 1)
  assert.match(result.output, /Invalid diagnostic file/)
})
