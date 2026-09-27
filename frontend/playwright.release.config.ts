import { defineConfig, devices } from '@playwright/test'

const remoteBaseUrl = process.env.RELEASE_BASE_URL

export default defineConfig({
  testDir: './tests/release',
  fullyParallel: false,
  workers: 1,
  forbidOnly: true,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-release-report' }]],
  use: {
    baseURL: remoteBaseUrl || 'http://127.0.0.1:3200',
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
  webServer: remoteBaseUrl
    ? undefined
    : {
        command: 'node tests/support/start-static.mjs .output/public 3200',
        url: 'http://127.0.0.1:3200/login',
        reuseExistingServer: false,
        timeout: 120_000,
      },
})
