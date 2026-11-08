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
        const otherLanguage = page.locator('nav a')
        await otherLanguage.click()
        await expect(page.locator('html')).toHaveAttribute('lang', language === 'ja' ? 'en' : 'ja')
      }
    }
    expect(apiRequests).toBe(0)
  } finally {
    await context.close()
  }
})

test('ログイン・登録から別タブで文書を開き、登録入力を維持する', async ({ page, baseURL }) => {
  test.skip(
    !baseURL || !['127.0.0.1', 'localhost'].includes(new URL(baseURL).hostname),
    '合成応答の確認はローカルのみ'
  )
  const unexpected: string[] = []
  await page.context().route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname
    if (path === '/api/v1/status') return route.fulfill({ json: { status: 'ok' } })
    if (path === '/api/v1/auth/register/username-availability')
      return route.fulfill({ json: { available: true } })
    unexpected.push(path)
    return route.abort()
  })
  await page.goto('/login/')
  const navigation = page.locator('nav').filter({ has: page.locator('a[href^="/legal/"]') })
  await expect(navigation.locator('a')).toHaveCount(3)
  const popupPromise = page.waitForEvent('popup')
  await navigation.locator('a').first().click()
  const popup = await popupPromise
  await expect(popup.locator('h1')).toContainText(/利用規約|Terms of Service/)
  await popup.close()
  await page.goto('/register/')
  await page.locator('#register-group-name').fill('Synthetic legal review')
  await page.locator('#register-password').fill('Synthetic review password 123!')
  const registerPopupPromise = page.waitForEvent('popup')
  await page.locator('a[href^="/legal/privacy/"]').click()
  const registerPopup = await registerPopupPromise
  await expect(registerPopup.locator('h1')).toContainText(/プライバシー|Privacy/)
  await registerPopup.close()
  await expect(page.locator('#register-group-name')).toHaveValue('Synthetic legal review')
  await expect(page.locator('#register-password')).toHaveValue('Synthetic review password 123!')
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
  const link = about.locator('a[href="/legal/terms/en/"]')
  await expect(link).toHaveText(/Terms of Service/)
  const popupPromise = page.waitForEvent('popup')
  await link.click()
  const popup = await popupPromise
  await expect(popup.locator('html')).toHaveAttribute('lang', 'en')
  await popup.close()
  expect(unexpected).toEqual([])
})

test('PWA制御下でも法務文書を認証APIなしで開ける', async ({ browser, baseURL }) => {
  test.skip(
    !baseURL || !['127.0.0.1', 'localhost'].includes(new URL(baseURL).hostname),
    'PWAの合成応答確認はローカルのみ'
  )
  const context = await browser.newContext({ serviceWorkers: 'allow' })
  let blockApi = false
  let blockedRequests = 0
  await context.route('**/api/**', async (route) => {
    if (!blockApi && new URL(route.request().url()).pathname === '/api/v1/status')
      return route.fulfill({ json: { status: 'ok' } })
    blockedRequests++
    return route.abort()
  })
  try {
    const page = await context.newPage()
    await page.goto(`${baseURL}/login/`)
    await page.evaluate(() => navigator.serviceWorker.ready)
    await page.reload()
    await expect
      .poll(() => page.evaluate(() => Boolean(navigator.serviceWorker.controller)))
      .toBe(true)
    blockApi = true
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
