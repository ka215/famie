import { readFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'

test.use({ ignoreHTTPSErrors: true })

const documents = JSON.parse(
  readFileSync(new URL('../../../backend/resources/legal-documents.json', import.meta.url), 'utf8')
).documents

test.beforeEach(async ({ baseURL }) => {
  test.skip(
    !baseURL || !['127.0.0.1', 'localhost'].includes(new URL(baseURL).hostname),
    'Device-storage checks use local synthetic accounts only'
  )
})

test('認証CookieはHTTPS・30日・Laxで、ログアウト時は削除し表示設定だけを残す', async ({
  page,
  context,
  baseURL,
  browserName,
}) => {
  test.skip(new URL(baseURL ?? '').protocol !== 'https:', 'Secure cookie attributes require HTTPS')
  const unexpected: string[] = []
  await page.addInitScript(() => {
    const descriptor = Object.getOwnPropertyDescriptor(Document.prototype, 'cookie')
    if (!descriptor?.get || !descriptor.set) throw new Error('Cookie instrumentation unavailable')
    const attributes: string[][] = []
    ;(window as unknown as { r04CookieAttributes: string[][] }).r04CookieAttributes = attributes
    Object.defineProperty(Document.prototype, 'cookie', {
      ...descriptor,
      set(value: string) {
        const pair = value.split(';')[0] ?? ''
        if (pair.startsWith('auth_token=') && pair !== 'auth_token=') {
          attributes.push(
            value
              .split(';')
              .slice(1)
              .map((attribute) => attribute.trim().toLowerCase())
          )
        }
        descriptor.set?.call(this, value)
      },
    })
  })
  await context.route('**/api/**', (route) => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/status')) return route.fulfill({ json: { status: 'ok' } })
    if (path.endsWith('/legal/documents'))
      return route.fulfill({ json: { data: documents, meta: { acceptance_enabled: true } } })
    if (path.endsWith('/auth/login'))
      return route.fulfill({
        json: {
          access_token: 'synthetic-storage-token',
          token_type: 'Bearer',
          user: {
            id: 900001,
            username: 'synthetic_storage',
            display_name: 'Synthetic',
            ui_locale: 'en',
          },
          membership: {
            id: 900001,
            role: 'admin',
            status: 'active',
            is_representative: true,
            group: { id: 900001, name: 'Synthetic family', type: 'family' },
          },
          legal: { enabled: true, required: true },
        },
      })
    if (path.endsWith('/auth/logout')) return route.fulfill({ json: { message: 'Signed out' } })
    if (path.endsWith('/security-alerts'))
      return route.fulfill({
        json: {
          data: [],
          meta: { unread_total: 0, unread_by_member: [], limit: 20, has_more: false },
        },
      })
    unexpected.push(path)
    return route.abort()
  })
  await page.goto('/login/')
  expect((await context.cookies()).map((cookie) => cookie.name)).toEqual([])
  expect(await page.evaluate(() => Object.keys(localStorage))).toEqual(['famie-theme'])
  await page.locator('button[aria-haspopup="dialog"]').click()
  await page.getByRole('dialog').locator('select').selectOption('en')
  await page
    .getByRole('dialog')
    .getByRole('button', { name: /Close|閉じる/ })
    .click()
  expect(await page.evaluate(() => localStorage.getItem('famie.uiLocale.guest'))).toBe('en')
  const started = Date.now() / 1000
  await page.locator('#login-identity').fill('synthetic_storage')
  await page.locator('#login-password').fill('Synthetic password 123!')
  await page.locator('button[type=submit]').click()
  await expect(page).toHaveURL(/\/legal-confirmation\/?$/)
  const cookie = (await context.cookies()).find((entry) => entry.name === 'auth_token')
  expect(cookie?.secure).toBe(true)
  expect(cookie?.httpOnly).toBe(false)
  const serializedAttributes = await page.evaluate(
    () => (window as unknown as { r04CookieAttributes: string[][] }).r04CookieAttributes
  )
  expect(serializedAttributes.length).toBeGreaterThan(0)
  for (const attributes of serializedAttributes) {
    expect(attributes).toEqual(
      expect.arrayContaining(['samesite=lax', 'secure', 'path=/', 'max-age=2592000'])
    )
  }
  if (browserName !== 'webkit' || process.platform !== 'win32') {
    expect(cookie?.sameSite).toBe('Lax')
  } else {
    const limitation = {
      type: 'measurement-limit',
      description:
        'Windows WebKit reports None for direct Lax/Strict control cookies too; application serialization is asserted, not native Safari enforcement.',
    }
    test.info().annotations.push(limitation)
  }
  expect(cookie?.path).toBe('/')
  expect(cookie?.domain).toBe(new URL(baseURL ?? '').hostname)
  expect(cookie?.expires ?? 0).toBeGreaterThan(started + 30 * 86400 - 60)
  expect(cookie?.expires ?? 0).toBeLessThan(started + 30 * 86400 + 60)
  await page.getByRole('button', { name: 'Sign out', exact: true }).click()
  await expect(page).toHaveURL(/\/login\/?$/)
  expect((await context.cookies()).map((entry) => entry.name)).not.toContain('auth_token')
  expect(await page.evaluate(() => Object.keys(localStorage).sort())).toEqual([
    'famie-theme',
    'famie.uiLocale.guest',
  ])
  expect(await page.evaluate(() => Object.keys(sessionStorage))).toEqual([])
  expect(unexpected).toEqual([])
})

test('PWAは静的資産を保存しAPIへのGET応答をCache Storageへ残さない', async ({
  browser,
  baseURL,
}) => {
  test.skip(
    new URL(baseURL ?? '').protocol !== 'http:',
    'Use the trusted local HTTP origin for deterministic worker inspection'
  )
  const context = await browser.newContext({ serviceWorkers: 'allow' })
  try {
    const page = await context.newPage()
    await page.goto(`${baseURL}/legal/cookies/`)
    await page.evaluate(async () => {
      await navigator.serviceWorker.register('/sw.js')
      await navigator.serviceWorker.ready
    })
    await page.reload()
    await expect
      .poll(() => page.evaluate(() => Boolean(navigator.serviceWorker.controller)))
      .toBe(true)
    const observed = await page.evaluate(async () => {
      // The local server is static-only: this GET cannot reach an application database.
      await fetch('/api/v1/status?r04-storage-audit=1')
      const stored: string[] = []
      for (const name of await caches.keys()) {
        const cache = await caches.open(name)
        stored.push(...(await cache.keys()).map((request) => request.url))
      }
      return {
        staticCount: stored.filter((url) => new URL(url).pathname.startsWith('/_nuxt/')).length,
        apiCount: stored.filter((url) => /^\/api(?:\/|$)/.test(new URL(url).pathname)).length,
        externalCount: stored.filter((url) => new URL(url).origin !== location.origin).length,
      }
    })
    expect(observed.staticCount).toBeGreaterThan(0)
    expect(observed.apiCount).toBe(0)
    expect(observed.externalCount).toBe(0)
  } finally {
    await context.close()
  }
})
