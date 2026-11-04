import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdirSync, mkdtempSync, realpathSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('../../', import.meta.url))
const backend = resolve(root, '../backend')
const scratch = resolve(root, '.cache/e2e')
mkdirSync(scratch, { recursive: true })
const runDir = mkdtempSync(resolve(scratch, 'run-'))
const database = resolve(runDir, 'database.sqlite')
writeFileSync(database, '')
const port = Number(process.env.FAMIE_E2E_API_PORT ?? 8100)
if (!Number.isInteger(port) || port < 1024 || port > 65535) throw new Error('Invalid E2E API port')
const lifecycleRoot = realpathSync(mkdtempSync(resolve(tmpdir(), 'famie-e2e-lifecycle-')))
mkdirSync(resolve(lifecycleRoot, 'vault'), { mode: 0o700 })
mkdirSync(resolve(lifecycleRoot, 'ledger'), { mode: 0o700 })
const publicKey = spawnSync(
  'php',
  [
    '-r',
    '$pair = sodium_crypto_box_keypair(); echo base64_encode(sodium_crypto_box_publickey($pair)); sodium_memzero($pair);',
  ],
  { encoding: 'utf8' }
)
if (publicKey.error) throw publicKey.error
if (publicKey.status !== 0) throw new Error('E2E requires Sodium for isolated lifecycle encryption')

// Every run gets a new database and cache paths, independent of backend/.env.
const env = {
  ...process.env,
  APP_ENV: 'testing',
  FAMIE_DB_TEST_RUN: '1',
  FAMIE_TEST_PROFILE: 'e2e',
  APP_DEBUG: 'false',
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  APP_CONFIG_CACHE: resolve(runDir, 'config.php'),
  APP_ROUTES_CACHE: resolve(runDir, 'routes.php'),
  APP_URL: `http://127.0.0.1:${port}`,
  DB_CONNECTION: 'sqlite',
  DB_URL: '',
  DB_DATABASE: database,
  CACHE_STORE: 'array',
  SESSION_DRIVER: 'array',
  QUEUE_CONNECTION: 'sync',
  MAIL_MAILER: 'array',
  FAMIE_OPERATIONS_ENABLED: 'false',
  FAMIE_LIFECYCLE_VAULT_ROOT: resolve(lifecycleRoot, 'vault'),
  FAMIE_LIFECYCLE_LEDGER_ROOT: resolve(lifecycleRoot, 'ledger'),
  FAMIE_LIFECYCLE_PUBLIC_KEY: publicKey.stdout.trim(),
  FAMIE_LIFECYCLE_KEY_ID: 'e2e-ephemeral',
  LOG_CHANNEL: 'stderr',
  ALLOWED_IPS: '127.0.0.1,::1',
  DEV_SEED_PASSWORD: 'Famie-E2E-only-123!',
}
const prepare = spawnSync('php', ['artisan', 'migrate', '--seed', '--force', '--no-interaction'], {
  cwd: backend,
  env,
  stdio: 'inherit',
})
if (prepare.error) throw prepare.error
if (prepare.status !== 0) process.exit(prepare.status ?? 1)

const server = spawn(
  'php',
  [
    '-S',
    `127.0.0.1:${port}`,
    '-t',
    '.',
    resolve(backend, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'),
  ],
  { cwd: resolve(backend, 'public'), env, stdio: 'inherit' }
)
server.on('error', (error) => {
  throw error
})
server.on('exit', (code) => process.exit(code ?? 1))
for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => {
    server.kill()
    process.exit(0)
  })
}
