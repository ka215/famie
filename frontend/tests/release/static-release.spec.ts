import { expect, test } from '@playwright/test'

// Playwright emits this warning itself when service workers are intentionally
// disabled to keep release smoke tests independent from an existing PWA cache.
const allowedWarnings = [/^Service Worker registration blocked by Playwright$/]

test.beforeEach(async ({ page }) => {
  if (!process.env.RELEASE_BASE_URL) {
    await page.route('**/api/v1/status', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: '{"status":"ok"}' })
    })
  }
})

for (const path of ['/login', '/']) {
  test(`${path} mounts without console errors or unknown warnings`, async ({ page }) => {
    const consoleFailures: string[] = []
    const pageFailures: string[] = []
    page.on('console', (message) => {
      if (message.type() === 'error') consoleFailures.push(message.text())
      if (
        message.type() === 'warning' &&
        !allowedWarnings.some((rule) => rule.test(message.text()))
      ) {
        consoleFailures.push(`warning: ${message.text()}`)
      }
    })
    page.on('pageerror', (error) => pageFailures.push(error.message))

    await page.goto(path)
    await expect(page.locator('#__nuxt')).not.toBeEmpty()
    await expect(page.locator('body')).toBeVisible()
    expect(pageFailures).toEqual([])
    expect(consoleFailures).toEqual([])
  })
}
