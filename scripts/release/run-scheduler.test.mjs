import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { chmod, mkdtemp, mkdir, readFile, readdir, rm, utimes, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import test from 'node:test'

const bash = process.platform === 'win32' ? 'C:/Program Files/Git/bin/bash.exe' : 'bash'
const script = fileURLToPath(new URL('./server/run-scheduler.sh', import.meta.url))
const shellPath = (path) => resolve(path).replaceAll('\\', '/').replace(/^([A-Za-z]):/, (_, drive) => `/${drive.toLowerCase()}`)

async function fixture(t, body, environment = 'staging') {
  const root = await mkdtemp(join(tmpdir(), 'famie-scheduler-test-'))
  assert.equal(dirname(root), resolve(tmpdir()))
  t.after(() => rm(root, { recursive: true, force: true }))
  const backend = join(root, environment === 'production' ? 'famie/backend' : 'famie-stg/backend')
  await mkdir(join(backend, 'storage/app/operations'), { recursive: true })
  await writeFile(join(backend, 'artisan'), '')
  const php = join(root, 'php-fixture.sh')
  await writeFile(php, `#!/usr/bin/env bash\n[[ "$*" == 'artisan schedule:run --no-interaction' ]] || exit 99\n${body}\n`)
  await chmod(php, 0o700)
  return { root, backend, php }
}

async function run(fixture, environment = 'staging', extra = {}, entry = script) {
  return await new Promise((resolve, reject) => {
    const args = entry === script
      ? ['--noprofile', '--norc', shellPath(entry), environment]
      : ['--noprofile', '--norc', '-c', 'exec /usr/bin/sh "$1" "$2"', 'panel-entry', shellPath(entry), environment]
    const child = spawn(bash, args, {
      env: {
        ...process.env, HOME: shellPath(fixture.root), PHPCLI: shellPath(fixture.php),
        FAMIE_SCHEDULER_DELAY_SECONDS: '0', FAMIE_SCHEDULER_TIMEOUT_SECONDS: '150', BASH_ENV: '', ...extra,
      },
      windowsHide: true,
    })
    let output = ''
    child.stdout.on('data', (data) => { output += data })
    child.stderr.on('data', (data) => { output += data })
    child.on('error', reject)
    child.on('close', (code) => resolve({ code, output }))
  })
}

async function history(fixture, checkpoints = false) {
  const directory = join(fixture.backend, 'storage/app/operations')
  const files = (await readdir(directory)).filter((file) => /^scheduler-.*\.jsonl$/.test(file))
  const rows = []
  for (const file of files) {
    rows.push(...(await readFile(join(directory, file), 'utf8')).trim().split('\n').filter(Boolean).map(JSON.parse))
  }
  return checkpoints ? rows : rows.filter(({ phase }) => phase !== 'checkpoint')
}

async function clockEnvironment(fixture, first, later) {
  const clock = join(fixture.root, 'clock-env.sh')
  await writeFile(clock, `date() {\n  if [[ "$*" == '+%s' ]]; then\n    if [[ -f "$HOME/clock-read" ]]; then echo ${later}; else touch "$HOME/clock-read"; echo ${first}; fi\n  else /usr/bin/date "$@"; fi\n}\n`)
  return shellPath(clock)
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
  assert.equal(rows[0].delay_seconds, 0)
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

  assert.ok([124, 143].includes(result.code))
  assert.equal((await history(f)).at(-1).exit_code, 143)
  assert.equal((await history(f)).at(-1).signal, 'TERM')
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

test('defaults to zero delay for production', async (t) => {
  const f = await fixture(t, 'exit 0', 'production')

  const result = await run(f, 'production', { FAMIE_SCHEDULER_DELAY_SECONDS: undefined })

  assert.equal(result.code, 0)
  assert.equal((await history(f))[0].delay_seconds, 0)
})

test('rejects a production delay before invoking PHP', async (t) => {
  const f = await fixture(t, 'echo invoked > invoked', 'production')

  const result = await run(f, 'production', { FAMIE_SCHEDULER_DELAY_SECONDS: '25' })

  assert.equal(result.code, 1)
  assert.deepEqual(await history(f), [])
  assert.equal((await readdir(f.backend)).includes('invoked'), false)
})

for (const delay of ['-1', '60', '01', '1.5', 'invalid-delay']) {
  test(`rejects invalid delay ${delay} before invoking PHP`, async (t) => {
    const f = await fixture(t, 'echo invoked > invoked')

    const result = await run(f, 'staging', { FAMIE_SCHEDULER_DELAY_SECONDS: delay })

    assert.equal(result.code, 1)
    assert.deepEqual(await history(f), [])
    assert.equal((await readdir(f.backend)).includes('invoked'), false)
    assert.doesNotMatch(result.output, new RegExp(delay.replace('.', '\\.')))
  })
}

test('waits before PHP and includes the wait in elapsed time', async (t) => {
  const f = await fixture(t, 'date +%s%3N > invoked-at; exit 0')
  const clock = await clockEnvironment(f, 1793061000, 1793061001)
  const before = performance.now()

  const result = await run(f, 'staging', { FAMIE_SCHEDULER_DELAY_SECONDS: '1', BASH_ENV: clock })

  assert.equal(result.code, 0)
  assert.ok(performance.now() - before >= 1000)
  const rows = await history(f)
  assert.equal(rows[0].delay_seconds, 1)
  assert.equal(rows[1].delay_seconds, 1)
  assert.ok(rows[1].elapsed_seconds >= 1)
  const invoked = Number(await readFile(join(f.backend, 'invoked-at'), 'utf8'))
  assert.ok(invoked - Date.parse(rows[0].at) >= 1000)
})

test('rejects a delay crossing into another schedule minute', async (t) => {
  const f = await fixture(t, 'echo invoked > invoked')
  const clock = await clockEnvironment(f, 1793060999, 1793061000)

  const result = await run(f, 'staging', {
    FAMIE_SCHEDULER_DELAY_SECONDS: '1',
    BASH_ENV: clock,
  })

  assert.equal(result.code, 1)
  assert.equal((await readdir(f.backend)).includes('invoked'), false)
  assert.equal((await history(f))[1].exit_code, 1)
  assert.match(result.output, /crossed the scheduled minute/)
})

test('records PHP boundaries and its status without exposing command output', async (t) => {
  const f = await fixture(t, 'echo PRIVATE_OUTPUT; exit 27')
  const result = await run(f)
  const rows = await history(f, true)

  assert.equal(result.code, 27)
  assert.deepEqual(rows.map(({ phase, stage, exit_code, signal }) => ({ phase, stage, exit_code, signal })), [
    { phase: 'started', stage: 'preflight', exit_code: null, signal: null },
    { phase: 'checkpoint', stage: 'php_running', exit_code: null, signal: null },
    { phase: 'checkpoint', stage: 'php_finished', exit_code: 27, signal: null },
    { phase: 'finished', stage: 'housekeeping', exit_code: 27, signal: null },
  ])
  assert.equal(new Set(rows.map(({ run_id }) => run_id)).size, 1)
  assert.doesNotMatch(JSON.stringify(rows) + result.output, /PRIVATE_OUTPUT|php-fixture/)
})

test('records delay boundaries before PHP starts', async (t) => {
  const f = await fixture(t, 'exit 0')
  const clock = await clockEnvironment(f, 1793061000, 1793061001)
  const result = await run(f, 'staging', { FAMIE_SCHEDULER_DELAY_SECONDS: '1', BASH_ENV: clock })

  assert.equal(result.code, 0)
  assert.deepEqual((await history(f, true)).map(({ stage }) => stage), [
    'preflight', 'waiting', 'delay_finished', 'php_running', 'php_finished', 'housekeeping',
  ])
})

test('does not infer a wrapper signal from scheduler exit 143', async (t) => {
  const f = await fixture(t, 'exit 143')
  const result = await run(f)

  assert.equal(result.code, 143)
  const rows = await history(f, true)
  assert.equal(rows.find(({ stage }) => stage === 'php_finished').exit_code, 143)
  assert.ok(rows.every(({ signal }) => signal === null))
})

test('records TERM during the delay without invoking PHP', async (t) => {
  const f = await fixture(t, 'echo invoked > invoked')
  const environment = join(f.root, 'signal-env.sh')
  await writeFile(environment, 'sleep() { kill -TERM "$$"; }\n')

  const result = await run(f, 'staging', { FAMIE_SCHEDULER_DELAY_SECONDS: '25', BASH_ENV: shellPath(environment) })
  const rows = await history(f, true)

  assert.equal(result.code, 143)
  assert.equal((await readdir(f.backend)).includes('invoked'), false)
  assert.deepEqual(rows.map(({ stage }) => stage), ['preflight', 'waiting', 'waiting'])
  assert.equal(rows.at(-1).phase, 'finished')
  assert.equal(rows.at(-1).signal, 'TERM')
  assert.equal(rows.at(-1).exit_code, 143)
})

for (const [signal, code] of [['TERM', 143], ['INT', 130], ['HUP', 129]]) {
  test(`records ${signal} received by wrapper during PHP execution`, async (t) => {
    const f = await fixture(t, `kill -${signal} "$(cat "$HOME/wrapper-pid")"; exit 0`)
    const environment = join(f.root, 'signal-env.sh')
    await writeFile(environment, '[[ "$0" != */run-scheduler.sh ]] || printf "%s" "$$" > "$HOME/wrapper-pid"\n')

    const result = await run(f, 'staging', { BASH_ENV: shellPath(environment) })
    const rows = await history(f, true)

    assert.equal(result.code, code)
    assert.equal(rows.at(-1).phase, 'finished')
    assert.equal(rows.at(-1).stage, 'php_running')
    assert.equal(rows.at(-1).signal, signal)
    assert.equal(rows.at(-1).exit_code, code)
    assert.equal(rows.filter(({ phase }) => phase === 'finished').length, 1)
  })
}

test('bounds the delay as part of the whole invocation', async (t) => {
  const f = await fixture(t, 'echo invoked > invoked')
  const before = performance.now()
  const result = await run(f, 'staging', {
    FAMIE_SCHEDULER_TIMEOUT_SECONDS: '2', FAMIE_SCHEDULER_DELAY_SECONDS: '25',
  })

  assert.equal(result.code, 124)
  assert.ok(performance.now() - before < 8000)
  assert.equal((await readdir(f.backend)).includes('invoked'), false)
  const last = (await history(f)).at(-1)
  assert.equal(last.stage, 'waiting')
  assert.equal(last.signal, 'TERM')
})

for (const limit of ['0', '151', '900', 'invalid']) {
  test(`rejects a whole-job timeout of ${limit}`, async (t) => {
    const f = await fixture(t, 'echo invoked > invoked')
    const result = await run(f, 'staging', { FAMIE_SCHEDULER_TIMEOUT_SECONDS: limit })

    assert.equal(result.code, 1)
    assert.deepEqual(await history(f), [])
    assert.equal((await readdir(f.backend)).includes('invoked'), false)
  })
}

test('kills a TERM-resistant child and descendant at the hard deadline', async (t) => {
  const f = await fixture(t, `trap '' TERM
(trap '' TERM; while :; do echo tick >> "$HOME/ticks"; sleep 0.1; done) &
wait`)
  const before = performance.now()
  const result = await run(f, 'staging', { FAMIE_SCHEDULER_TIMEOUT_SECONDS: '2' })

  assert.notEqual(result.code, 0)
  assert.ok(performance.now() - before < 12000)
  const ticks = await readFile(join(f.root, 'ticks'), 'utf8')
  await new Promise((resolve) => setTimeout(resolve, 300))
  assert.equal(await readFile(join(f.root, 'ticks'), 'utf8'), ticks)
})

test('panel entry selects the environment and clears the old staging delay', async (t) => {
  const f = await fixture(t, 'exit 0')
  const jq = spawnSync(bash, ['-c', 'command -v jq'], { encoding: 'utf8', windowsHide: true })
  assert.equal(jq.status, 0)
  await mkdir(join(f.root, 'bin'))
  await writeFile(join(f.root, 'bin/jq'), `#!/bin/sh\nexec '${jq.stdout.trim().replaceAll("'", "'\\''")}' "$@"\n`)
  await chmod(join(f.root, 'bin/jq'), 0o700)
  const worker = join(f.backend, 'storage/app/operations/run-scheduler.sh')
  await writeFile(worker, await readFile(script))
  const panel = fileURLToPath(new URL('./server/run-scheduler-panel.sh', import.meta.url))

  const result = await run(f, 'staging', { FAMIE_SCHEDULER_DELAY_SECONDS: '25' }, panel)

  assert.equal(result.code, 0)
  assert.equal((await history(f))[0].environment, 'staging')
  assert.equal((await history(f))[0].delay_seconds, 0)
})

test('panel entry rejects an unknown environment before PHP', async (t) => {
  const f = await fixture(t, 'echo invoked > invoked')
  const panel = fileURLToPath(new URL('./server/run-scheduler-panel.sh', import.meta.url))
  const result = await run(f, 'other', {}, panel)

  assert.equal(result.code, 1)
  assert.equal((await readdir(f.backend)).includes('invoked'), false)
})
