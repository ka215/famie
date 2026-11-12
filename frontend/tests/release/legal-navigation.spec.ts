import { readFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'

test('法務文書の日英6ページはJavaScript・APIなしで閲覧できる', async ({ browser, baseURL }) => {
  const context = await browser.newContext({ javaScriptEnabled: false, serviceWorkers: 'block' })
  let apiRequests = 0
  await context.route('**/api/**', (route) => {
    apiRequests++
    return route.abort()
  })
  try {
    const page = await context.newPage()
    for (const document of ['terms', 'privacy', 'cookies']) {
      for (const language of ['ja', 'en']) {
        await page.goto(`${baseURL}/legal/${document}/${language === 'ja' ? '' : 'en/'}`)
        await expect(page.locator('html')).toHaveAttribute('lang', language)
        await expect(page.locator('h1')).toHaveCount(1)
        await expect(page.locator('aside')).toContainText(
          language === 'ja' ? '先行確認版・未施行' : 'Preview — not yet effective'
        )
        await expect(page.locator('main')).not.toContainText('REVIEW')
        await expect(page.locator('main.legal-card')).toBeVisible()
        await page.locator('.language-picker summary').click()
        const otherLanguage = page.locator(`nav a[lang="${language === 'ja' ? 'en' : 'ja'}"]`)
        await otherLanguage.click()
        await expect(page.locator('html')).toHaveAttribute('lang', language === 'ja' ? 'en' : 'ja')
      }
    }
    expect(apiRequests).toBe(0)
  } finally {
    await context.close()
  }
})

test('ログイン・登録のガイダンス下から同じタブで文書を開く', async ({ page, baseURL }) => {
  test.skip(
    !baseURL || !['127.0.0.1', 'localhost'].includes(new URL(baseURL).hostname),
    '合成応答の確認はローカルのみ'
  )
  const unexpected: string[] = []
  await page.context().route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname
    if (path === '/api/v1/status') return route.fulfill({ json: { status: 'ok' } })
    if (path === '/api/v1/legal/documents')
      return route.fulfill({
        json: {
          data: JSON.parse(
            readFileSync(
              new URL('../../../backend/resources/legal-documents.json', import.meta.url),
              'utf8'
            )
          ).documents,
          meta: { acceptance_enabled: false },
        },
      })
    if (path === '/api/v1/auth/register/username-availability')
      return route.fulfill({ json: { available: true } })
    unexpected.push(path)
    return route.abort()
  })
  await page.goto('/login/')
  const navigation = page.locator('nav').filter({ has: page.locator('a[href^="/legal/"]') })
  await expect(navigation.locator('a')).toHaveCount(3)
  expect(await navigation.evaluate((element) => element.previousElementSibling?.tagName)).toBe(
    'SECTION'
  )
  await expect(navigation.locator('a').first()).not.toHaveAttribute('target')
  await navigation.locator('a').first().click()
  await expect(page.locator('h1')).toContainText(/利用規約|Terms of Service/)
  await expect(page.locator('footer a')).toHaveCount(1)
  await expect(page.locator('#legal-back')).toHaveAttribute('href', '/login/')
  expect(page.context().pages()).toHaveLength(1)
  await page.goto('/register/')
  await page.locator('#register-group-name').fill('Synthetic legal review')
  await page.locator('#register-password').fill('Synthetic review password 123!')
  expect(await navigation.evaluate((element) => element.previousElementSibling?.tagName)).toBe(
    'SECTION'
  )
  await page.locator('a[href^="/legal/privacy/"]').click()
  await expect(page.locator('h1')).toContainText(/プライバシー|Privacy/)
  expect(page.context().pages()).toHaveLength(1)
  const storedValues = await page.evaluate(() =>
    Object.values(localStorage).concat(Object.values(sessionStorage)).join(' ')
  )
  expect(storedValues).not.toContain('Synthetic review password 123!')
  expect(unexpected).toEqual([])
})

test('設定画面の文書リンクは選択言語に対応する', async ({ page, baseURL }) => {
  test.skip(
    !baseURL || !['127.0.0.1', 'localhost'].includes(new URL(baseURL).hostname),
    '合成アカウントの確認はローカルのみ'
  )
  const group = {
    id: 999,
    name: 'Synthetic group',
    type: 'family',
    images: {
      enabled: false,
      used_bytes: 0,
      quota_bytes: 1000000,
      max_files_per_activity: 1,
      max_input_bytes: 1000000,
    },
  }
  const user = {
    id: 999,
    username: 'synthetic',
    display_name: 'Synthetic member',
    show_name_suffix: false,
    ui_locale: 'en',
    email: null,
    password_security: { status: 'ok', snoozed_until: null },
  }
  const membership = { id: 999, role: 'member', status: 'active', is_representative: false, group }
  const unexpected: string[] = []
  await page
    .context()
    .addCookies([
      { name: 'auth_token', value: 'synthetic-only-not-a-real-token', url: baseURL as string },
    ])
  await page.context().route('**/api/**', async (route) => {
    const request = route.request()
    const path = new URL(request.url()).pathname
    if (request.method() !== 'GET') {
      unexpected.push(`${request.method()} ${path}`)
      return route.abort()
    }
    if (path === '/api/v1/auth/me') return route.fulfill({ json: { user, membership } })
    if (path === '/api/v1/status') return route.fulfill({ json: { status: 'ok' } })
    if (path === '/api/v1/groups/999') return route.fulfill({ json: { data: group } })
    if (/^\/api\/v1\/groups\/999\/(members|categories)$/.test(path))
      return route.fulfill({ json: [] })
    unexpected.push(path)
    return route.abort()
  })
  await page.goto('/settings/')
  const about = page
    .locator('details')
    .filter({ has: page.locator('a[href="/THIRD_PARTY_NOTICES.txt"]') })
  await about.locator('summary').click()
  await expect(about.locator('a[href^="/legal/"]')).toHaveCount(3)
  const link = about.locator('a[href="/legal/terms/en/?from=settings"]')
  await expect(link).toHaveText(/Terms of Service/)
  await expect(link).not.toHaveAttribute('target')
  await link.click()
  await expect(page.locator('html')).toHaveAttribute('lang', 'en')
  await expect(page.locator('h1')).toContainText('Terms of Service')
  await expect(page.locator('footer a')).toHaveCount(1)
  await expect(page.locator('#legal-back')).toHaveAttribute('href', '/settings/')
  await expect(page.locator('#legal-back')).toHaveText('Back to settings')
  await page.locator('.language-picker summary').click()
  await page.locator('nav a[lang="ja"]').click()
  await expect(page.locator('#legal-back')).toHaveText('設定へ戻る')
  await expect(page.locator('#legal-back')).toHaveAttribute('href', '/settings/')
  expect(page.context().pages()).toHaveLength(1)
  expect(unexpected).toEqual([])
})

for (const savedTheme of ['light', 'dark'] as const) {
  test(`法務文書は端末設定より保存済みテーマを優先する: ${savedTheme}`, async ({
    browser,
    baseURL,
  }) => {
    const context = await browser.newContext({
      colorScheme: savedTheme === 'dark' ? 'light' : 'dark',
    })
    await context.addInitScript((theme) => localStorage.setItem('famie-theme', theme), savedTheme)
    try {
      const page = await context.newPage()
      await page.goto(`${baseURL}/legal/privacy/?from=settings`)
      await expect(page.locator('html')).toHaveAttribute('data-theme', savedTheme)
      await expect(page.locator('body')).toHaveCSS(
        'background-color',
        savedTheme === 'dark' ? 'rgb(15, 23, 42)' : 'rgb(241, 245, 249)'
      )
      await page.locator('.language-picker summary').click()
      await page.locator('nav a[lang="en"]').click()
      await expect(page.locator('html')).toHaveAttribute('data-theme', savedTheme)
      await expect(page.locator('#legal-back')).toHaveAttribute('href', '/settings/')
      await page.locator('article a[href^="/legal/cookies/"]').click()
      await expect(page.locator('#legal-back')).toHaveAttribute('href', '/settings/')
      await page.goto(`${baseURL}/legal/terms/?from=https://example.com`)
      await expect(page.locator('#legal-back')).toHaveAttribute('href', '/login/')
      await expect(page.locator('footer a')).toHaveCount(1)
    } finally {
      await context.close()
    }
  })
}

test('PWA制御下でも法務文書を認証APIなしで開ける', async ({ browser, baseURL }) => {
  test.skip(
    !baseURL || !['127.0.0.1', 'localhost'].includes(new URL(baseURL).hostname),
    'PWAの合成応答確認はローカルのみ'
  )
  const context = await browser.newContext({ serviceWorkers: 'allow' })
  let blockedRequests = 0
  await context.route('**/api/**', async (route) => {
    blockedRequests++
    return route.abort()
  })
  try {
    const page = await context.newPage()
    // Activate the shipped worker without booting the login page or its status API.
    await page.goto(`${baseURL}/legal/terms/`)
    await page.evaluate(async () => {
      await navigator.serviceWorker.register('/sw.js')
      await navigator.serviceWorker.ready
    })
    await page.reload()
    await expect
      .poll(() => page.evaluate(() => Boolean(navigator.serviceWorker.controller)))
      .toBe(true)
    for (const path of ['/legal/terms/', '/legal/privacy/en/', '/legal/cookies/0.1.0/ja/']) {
      await page.goto(`${baseURL}${path}`)
      await expect(page.locator('aside')).toContainText(/未施行|not yet effective/)
      await expect(page.locator('h1')).toHaveCount(1)
    }
    expect(blockedRequests).toBe(0)
  } finally {
    await context.close()
  }
})
