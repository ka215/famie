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

test('remote API responds through the public access path', async ({ request }) => {
  test.skip(!process.env.RELEASE_BASE_URL, 'Only applicable to a deployed release')

  const statusResponse = await request.get('/api/v1/status', {
    headers: { Accept: 'application/json' },
  })
  expect(statusResponse.status()).toBe(200)
  expect(await statusResponse.json()).toMatchObject({ status: 'ok' })

  const loginResponse = await request.post('/api/v1/auth/login', {
    data: {},
    headers: { Accept: 'application/json' },
  })
  expect(loginResponse.status()).toBe(422)
  expect(await loginResponse.json()).toMatchObject({
    message: expect.any(String),
    errors: expect.any(Object),
  })
})

test.describe('JavaScript読込前のテーマ', () => {
  test.use({ javaScriptEnabled: false, colorScheme: 'dark' })

  test('起動前から暗い背景と読込表示を配信する', async ({ page }) => {
    test.skip(!!process.env.RELEASE_BASE_URL, 'Local candidate verification before deployment')
    await page.goto('/login')
    await expect(page.getByText('Famie を読み込んでいます…')).toBeVisible()
    await expect(page.locator('html')).toHaveCSS('background-color', 'rgb(2, 6, 23)')
    await expect(page.locator('html')).toHaveCSS('color-scheme', 'dark')
  })
})

test('アプリ起動前に保存テーマとブラウザ表示色を適用する', async ({ page }) => {
  test.skip(!!process.env.RELEASE_BASE_URL, 'Local candidate verification before deployment')
  await page.emulateMedia({ colorScheme: 'light' })
  await page.addInitScript(() => localStorage.setItem('famie-theme', 'dark'))
  await page.route('**/_nuxt/*.js', (route) => route.abort())
  await page.goto('/login')
  await expect(page.getByText('Famie を読み込んでいます…')).toBeVisible()
  await expect(page.locator('html')).toHaveCSS('background-color', 'rgb(2, 6, 23)')
  await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#020617')
})
