import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('../../', import.meta.url))
const backend = resolve(root, '../backend')
const scratch = resolve(root, '.cache/e2e')
mkdirSync(scratch, { recursive: true })
const runDir = mkdtempSync(resolve(scratch, 'run-'))
const database = resolve(runDir, 'database.sqlite')
writeFileSync(database, '')

// Every run gets a new database and cache paths, independent of backend/.env.
const env = {
  ...process.env,
  APP_ENV: 'testing',
  APP_DEBUG: 'false',
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  APP_CONFIG_CACHE: resolve(runDir, 'config.php'),
  APP_ROUTES_CACHE: resolve(runDir, 'routes.php'),
  APP_URL: 'http://127.0.0.1:8100',
  DB_CONNECTION: 'sqlite',
  DB_URL: '',
  DB_DATABASE: database,
  CACHE_STORE: 'array',
  SESSION_DRIVER: 'array',
  QUEUE_CONNECTION: 'sync',
  MAIL_MAILER: 'log',
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
    '127.0.0.1:8100',
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
