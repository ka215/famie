import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { chmod, copyFile, mkdir, mkdtemp, readFile, readdir, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import test from 'node:test'

// Isolated processes and fixtures only: no SSH connection, PHP application or DB.
const bash = process.platform === 'win32' ? 'C:/Program Files/Git/bin/bash.exe' : 'bash'
const source = dirname(fileURLToPath(import.meta.url))
const shellPath = (path) => resolve(path).replaceAll('\\', '/').replace(/^([A-Za-z]):/, (_, drive) => `/${drive.toLowerCase()}`)

for (const scenario of ['unchanged', 'earlier migration', 'modified migration', 'deleted migration', 'missing tag']) {
  test(`rollback compatibility against published tag: ${scenario}`, async (t) => {
    const root = await mkdtemp(join(tmpdir(), 'famie-rollback-test-'))
    assert.equal(dirname(root), resolve(tmpdir()))
    t.after(() => rm(root, { recursive: true, force: true }))
    const git = (...args) => {
      const result = spawnSync('git', args, { cwd: root, encoding: 'utf8', windowsHide: true })
      assert.equal(result.status, 0, result.stderr)
    }
    git('init')
    git('config', 'user.name', 'Fixture')
    git('config', 'user.email', 'fixture@example.invalid')
    git('config', 'commit.gpgsign', 'false')
    const migrations = join(root, 'backend/database/migrations')
    await mkdir(migrations, { recursive: true })
    const migration = join(migrations, 'baseline.php')
    await writeFile(migration, '<?php // baseline\n')
    git('add', '.')
    git('commit', '-m', 'published')
    if (scenario !== 'missing tag') git('tag', 'v0.19.2')
    if (scenario === 'earlier migration') await writeFile(join(migrations, 'new.php'), '<?php // new\n')
    if (scenario === 'modified migration') await writeFile(migration, '<?php // changed\n')
    if (scenario === 'deleted migration') await rm(migration)
    git('add', '-A')
    git('commit', '--allow-empty', '-m', 'implementation')
    await writeFile(join(root, 'notes.md'), 'Documentation-only final commit\n')
    git('add', '.')
    git('commit', '-m', 'documentation')
    const result = spawnSync(bash, ['--noprofile', '--norc', '-c', 'source "$1"; database_rollback_compatibility 0.19.2', 'fixture', shellPath(join(source, 'database-compatibility.sh'))], {
      cwd: root, env: { ...process.env, BASH_ENV: '' }, encoding: 'utf8', windowsHide: true,
    })
    assert.equal(result.status, 0, result.stderr)
    assert.equal(result.stdout.trim(), scenario === 'unchanged' ? 'true' : 'false')
  })
}

async function fixture(t) {
  const root = await mkdtemp(join(tmpdir(), 'famie-deployment-diagnostic-test-'))
  assert.equal(dirname(root), resolve(tmpdir()))
  t.after(() => rm(root, { recursive: true, force: true }))
  for (const name of ['deployment-diagnostics.sh', 'supervise-deployment.sh', 'backup-database.sh']) {
    await copyFile(join(source, 'server', name), join(root, name))
    await chmod(join(root, name), 0o700)
  }
  return root
}

async function executable(path, body) {
  await writeFile(path, `#!/usr/bin/env bash\nset -Eeuo pipefail\n${body}\n`)
  await chmod(path, 0o700)
}

async function run(root, entry, args = [], extra = {}) {
  return await new Promise((resolve, reject) => {
    const child = spawn(bash, ['--noprofile', '--norc', '-c', 'export PATH="$FAMIE_TEST_BIN:$PATH"; exec /usr/bin/bash "$@"', 'fixture-run', shellPath(entry), ...args], {
      env: { ...process.env, HOME: shellPath(root), BASH_ENV: '', ...extra, FAMIE_TEST_BIN: shellPath(join(root, 'bin')) }, windowsHide: true,
    })
    let output = ''
    child.stdout.on('data', (data) => { output += data })
    child.stderr.on('data', (data) => { output += data })
    child.on('error', reject)
    child.on('close', (code) => resolve({ code, output }))
  })
}

async function logs(root) {
  const directory = join(root, 'diagnostics')
  return (await Promise.all((await readdir(directory)).map((name) => readFile(join(directory, name), 'utf8')))).join('\n')
}

for (const [primary, fallback, expected] of [[0, 0, 'archive.ubuntu.com'], [1, 0, 'azure.archive.ubuntu.com'], [1, 1, null]]) {
  test(`CI dependency installation checks DNS before APT (${primary}/${fallback})`, async (t) => {
    const root = await fixture(t)
    await mkdir(join(root, 'bin'))
    await executable(join(root, 'bin', 'timeout'), 'shift; exec "$@"')
    await executable(join(root, 'bin', 'getent'), `if [[ $2 == archive.ubuntu.com ]]; then exit ${primary}; fi
if [[ $2 == azure.archive.ubuntu.com ]]; then exit ${fallback}; fi
exit 99`)
    await executable(join(root, 'bin', 'sudo'), 'if [[ $1 == tee ]]; then cat >/dev/null; fi')
    await executable(join(root, 'bin', 'pnpm'), 'echo ISOLATED_APT_INSTALL_REACHED')
    await executable(join(root, 'bin', 'node'), 'echo ISOLATED_DNS_DIAG_REACHED')

    const result = await run(root, join(source, 'install-playwright-deps.sh'), ['chromium'], { GITHUB_ACTIONS: 'true', RUNNER_OS: 'Linux' })

    assert.equal(result.code, expected ? 0 : 1, result.output)
    if (expected) {
      assert.ok(result.output.includes(`Playwright dependency mirror: ${expected}`), result.output)
      assert.match(result.output, /ISOLATED_APT_INSTALL_REACHED/)
      assert.doesNotMatch(result.output, /ISOLATED_DNS_DIAG_REACHED/)
    } else {
      assert.match(result.output, /Neither Ubuntu mirror could be resolved/)
      assert.doesNotMatch(result.output, /ISOLATED_APT_INSTALL_REACHED/)
      assert.match(result.output, /ISOLATED_DNS_DIAG_REACHED/)
    }
  })
}

for (const code of [0, 27, 255]) {
  test(`supervisor preserves child exit ${code} and structured checkpoints`, async (t) => {
    const root = await fixture(t)
    await executable(join(root, 'deploy-artifact.sh'), `source "$(dirname "$0")/deployment-diagnostics.sh"
diagnostic_init deploy
trap 'diagnostic_record finished "$?" "$LINENO"' EXIT
diagnostic_checkpoint database_backup_returned
echo PRIVATE_COMMAND_OUTPUT
exit ${code}`)
    const result = await run(root, join(root, 'supervise-deployment.sh'))
    assert.equal(result.code, code)
    const content = await logs(root)
    assert.match(content, new RegExp(`component=supervisor event=checkpoint stage=child_finished .* exit=${code} `))
    assert.match(content, /component=deploy event=checkpoint stage=database_backup_returned/)
    assert.doesNotMatch(content, /PRIVATE_COMMAND_OUTPUT/)
  })
}

test('supervisor detects a killed child even without its EXIT record', async (t) => {
  const root = await fixture(t)
  await executable(join(root, 'deploy-artifact.sh'), `source "$(dirname "$0")/deployment-diagnostics.sh"
diagnostic_init deploy
trap 'diagnostic_record finished "$?" "$LINENO"' EXIT
diagnostic_checkpoint database_backup_starting
kill -KILL "$BASHPID"`)
  const result = await run(root, join(root, 'supervise-deployment.sh'))
  assert.equal(result.code, 137)
  const content = await logs(root)
  assert.match(content, /component=supervisor event=checkpoint stage=child_finished .* exit=137 /)
  assert.doesNotMatch(content, /component=deploy event=finished/)
})

test('child records TERM separately from an ordinary nonzero exit', async (t) => {
  const root = await fixture(t)
  await executable(join(root, 'deploy-artifact.sh'), `source "$(dirname "$0")/deployment-diagnostics.sh"
diagnostic_init deploy
trap 'diagnostic_record finished "$?" "$LINENO"' EXIT
trap 'diagnostic_interrupted TERM 143 "$LINENO"' TERM
kill -TERM "$BASHPID"`)
  const result = await run(root, join(root, 'supervise-deployment.sh'))
  assert.equal(result.code, 143)
  assert.match(await logs(root), /event=interrupted .* exit=143 .* signal=TERM/)
})

test('supervisor refuses to overwrite previous diagnostic evidence', async (t) => {
  const root = await fixture(t)
  await mkdir(join(root, 'diagnostics'))
  await writeFile(join(root, 'diagnostics', 'evidence'), 'retain')
  await executable(join(root, 'deploy-artifact.sh'), 'exit 99')
  assert.equal((await run(root, join(root, 'supervise-deployment.sh'))).code, 1)
  assert.equal(await readFile(join(root, 'diagnostics', 'evidence'), 'utf8'), 'retain')
})

async function backupFixture(t) {
  const root = await fixture(t)
  const backend = join(root, 'backend')
  const backup = join(root, 'backup')
  const bin = join(root, 'bin')
  await mkdir(backend)
  await mkdir(bin)
  await writeFile(join(backend, 'artisan'), '')
  await writeFile(join(backend, '.env'), '')
  const php = join(bin, 'php-fixture')
  await executable(php, `printf '%s' '{"host":"PRIVATE_HOST","port":5432,"database":"fixture","username":"PRIVATE_USER","password":"PRIVATE_PASSWORD"}'`)
  await executable(join(bin, 'pg_dump'), `echo PRIVATE_ERROR >&2
if [[ \${FIXTURE_DUMP_EXIT:-0} != 0 ]]; then exit "$FIXTURE_DUMP_EXIT"; fi
while (($#)); do if [[ $1 == --file ]]; then shift; printf 'fixture archive' > "$1"; fi; shift; done`)
  await executable(join(bin, 'pg_restore'), "printf 'TABLE DATA x users x\\nTABLE DATA x activity_logs x\\n'")
  return { root, backend, backup, env: { PATH: `${shellPath(bin)}:${process.env.PATH}`, PHPCLI: shellPath(php) } }
}

for (const code of [0, 42]) {
  test(`actual backup script records stage and exit ${code} without credentials`, async (t) => {
    const f = await backupFixture(t)
    const result = await run(f.root, join(f.root, 'backup-database.sh'), [shellPath(f.backend), shellPath(f.backup)], {
      ...f.env, FIXTURE_DUMP_EXIT: String(code),
    })
    assert.equal(result.code, code, result.output)
    const directory = (await readdir(f.backup)).find((name) => name.startsWith('diagnostics.'))
    const content = await readFile(join(f.backup, directory, 'backup.log'), 'utf8')
    assert.match(content, new RegExp(`event=finished .* exit=${code} `))
    assert.match(content, code === 0 ? /stage=backup_complete/ : /event=error stage=dump_starting/)
    assert.doesNotMatch(content + result.output, /PRIVATE_HOST|PRIVATE_USER|PRIVATE_PASSWORD|PRIVATE_ERROR/)
  })
}

test('configuration extraction failure255 is attributed before any dump command', async (t) => {
  const f = await backupFixture(t)
  await executable(join(f.root, 'bin/php-fixture'), 'echo PRIVATE_CONFIGURATION_ERROR >&2; exit 255')
  const result = await run(f.root, join(f.root, 'backup-database.sh'), [shellPath(f.backend), shellPath(f.backup)], f.env)
  assert.equal(result.code, 255, result.output)
  const directory = (await readdir(f.backup)).find((name) => name.startsWith('diagnostics.'))
  const content = await readFile(join(f.backup, directory, 'backup.log'), 'utf8')
  assert.match(content, /event=finished stage=connection_configuration .* exit=255 /)
  assert.doesNotMatch(content + result.output, /PRIVATE_CONFIGURATION_ERROR|stage=dump_starting/)
})

test('archive inspection failure retains its actual exit and stage', async (t) => {
  const f = await backupFixture(t)
  await executable(join(f.root, 'bin/pg_restore'), 'echo PRIVATE_ARCHIVE_ERROR >&2; exit 43')
  const result = await run(f.root, join(f.root, 'backup-database.sh'), [shellPath(f.backend), shellPath(f.backup)], f.env)
  assert.equal(result.code, 43, result.output)
  const directory = (await readdir(f.backup)).find((name) => name.startsWith('diagnostics.'))
  const content = await readFile(join(f.backup, directory, 'backup.log'), 'utf8')
  assert.match(content, /event=finished stage=archive_list_starting .* exit=43 /)
  assert.doesNotMatch(content + result.output, /PRIVATE_ARCHIVE_ERROR|stage=backup_complete/)
})

test('actual deployment guard failure records exit and preserves a lock owned by another run', async (t) => {
  const root = await fixture(t)
  await copyFile(join(source, 'server/deploy-artifact.sh'), join(root, 'deploy-artifact.sh'))
  const bin = join(root, 'bin')
  await mkdir(bin)
  await executable(join(bin, 'rsync'), 'exit 99')
  await executable(join(root, 'php-fixture'), 'exit 99')
  for (const name of ['bundle', 'checksum', 'composer.phar']) await writeFile(join(root, name), '')
  const lock = join(root, 'famie-stg-release-backups/releases/.deploy-lock')
  await mkdir(lock, { recursive: true })
  await writeFile(join(lock, 'existing'), 'retain')
  const result = await run(root, join(root, 'supervise-deployment.sh'), [
    '--env', 'staging', '--bundle', shellPath(join(root, 'bundle')), '--checksum', shellPath(join(root, 'checksum')), '--apply',
  ], { PATH: `${shellPath(bin)}:${process.env.PATH}`, PHPCLI: shellPath(join(root, 'php-fixture')), COMPOSER_FILE: shellPath(join(root, 'composer.phar')) })
  assert.equal(result.code, 1, result.output)
  assert.match(await logs(root), /component=deploy event=finished stage=environment_guard .* exit=1 /)
  assert.equal(await readFile(join(lock, 'existing'), 'utf8'), 'retain')
})

for (const remoteStatus of [true, false]) {
  test(`client distinguishes remote exit255 from missing exit-status (${remoteStatus}) without SSH`, async (t) => {
    const root = await fixture(t)
    const bin = join(root, 'bin')
    await mkdir(bin)
    await mkdir(join(root, 'famie-ssh'))
    await executable(join(bin, 'ssh'), `debug=''
while (($#)); do if [[ $1 == -E ]]; then shift; debug=$1; fi; shift; done
if [[ -n $debug ]]; then
  printf 'PRIVATE_SSH_DETAILS\\n' > "$debug"
  ${remoteStatus ? `printf 'debug1: client_input_channel_req: channel 0 rtype exit-status reply 0\\ndebug1: Exit status 255\\n' >> "$debug"` : ':'}
  exit 255
fi
printf 'at=2026-11-07T01:00:00+0000 component=deploy event=checkpoint stage=database_backup_returned pid=1 ppid=2 exit=0 line=3 signal=none\\nPRIVATE_SERVER_DATA\\n'`)
    const env = {
      PATH: `${shellPath(bin)}:${process.env.PATH}`, RUNNER_TEMP: shellPath(root), GITHUB_RUN_ID: '123', GITHUB_RUN_ATTEMPT: '1',
      DEPLOY_ENVIRONMENT: 'staging', DEPLOY_APPLY: 'true', DEPLOY_USER: 'fixture', DEPLOY_HOST: 'unused.invalid',
      ssh_key: 'unused-key', known_hosts: 'unused-hosts', remote: 'release-fixture', archive: 'fixture.tar.gz', checksum: 'fixture.sha256',
    }
    const entry = join(source, 'deploy-with-diagnostics.sh')
    assert.equal((await run(root, entry, ['run'], env)).code, 255)
    assert.equal((await run(root, entry, ['collect'], env)).code, 0)
    const directory = join(root, 'famie-deployment-diagnostics-123-1')
    const content = (await Promise.all((await readdir(directory)).map((name) => readFile(join(directory, name), 'utf8')))).join('\n')
    assert.equal(content.includes('event=no_remote_exit_status'), !remoteStatus)
    assert.match(content, /stage=database_backup_returned/)
    assert.match(content, /event=ssh_finished exit=255/)
    assert.doesNotMatch(content, /PRIVATE_SSH_DETAILS|PRIVATE_SERVER_DATA/)
  })
}
