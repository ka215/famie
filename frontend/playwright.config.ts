import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: 'https://127.0.0.1:3100',
    ignoreHTTPSErrors: true,
    locale: 'ja-JP',
    timezoneId: 'Asia/Tokyo',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    serviceWorkers: 'block',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'iphone-webkit', use: { ...devices['iPhone 13'] } },
  ],
  webServer: [
    {
      command: 'node tests/support/start-api.mjs',
      url: 'http://127.0.0.1:8100/up',
      reuseExistingServer: false,
      timeout: 120_000,
    },
    {
      command:
        'pnpm build --extends ./tests/support/e2e-layer && node tests/support/start-static.mjs .cache/e2e-output/public 3100 http://127.0.0.1:8100 tests/support/tls/key.pem tests/support/tls/cert.pem',
      url: 'https://127.0.0.1:3100/login',
      ignoreHTTPSErrors: true,
      env: { FAMIE_E2E: '1' },
      reuseExistingServer: false,
      timeout: 120_000,
    },
  ],
})
