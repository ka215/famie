import { expect, type Page, test } from '@playwright/test'
import { timelineSample } from '../support/timeline-sample'

async function openLanguage(page: Page) {
  if (!(await page.getByRole('dialog').isVisible()))
    await page.getByRole('button', { name: /^(Language|言語)$/ }).click()
}
async function closeLanguage(page: Page) {
  await page
    .getByRole('dialog')
    .getByRole('button', { name: /^(Close|閉じる)$/ })
    .click()
}

test('guest language is persisted, automatic follows only the preferred browser language', async ({
  page,
}) => {
  await page.route('**/v1/status', (route) => route.fulfill({ json: { status: 'ok' } }))
  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'languages', { get: () => ['fr-FR', 'ja-JP'] })
    Object.defineProperty(navigator, 'language', { get: () => 'fr-FR' })
  })
  await page.goto('/login')
  await expect(page.locator('html')).toHaveAttribute('lang', 'en')
  await expect(page.getByRole('button', { name: 'Log in', exact: true })).toBeVisible()
  await openLanguage(page)
  await page.getByRole('combobox', { name: 'Language', exact: true }).selectOption('ja')
  await expect(page.locator('html')).toHaveAttribute('lang', 'ja')
  await page.reload()
  await expect(page.getByRole('button', { name: 'ログイン', exact: true })).toBeVisible()
  await openLanguage(page)
  await page.getByRole('combobox', { name: '言語', exact: true }).selectOption('auto')
  await expect(page.locator('html')).toHaveAttribute('lang', 'en')
  await expect(page).toHaveURL(/\/login\/?$/)
  await closeLanguage(page)
  await page.getByRole('link', { name: 'Forgot your password?' }).click()
  await expect(page.getByText('Emails are currently sent in Japanese.')).toHaveCount(0)
  await openLanguage(page)
  await expect(page.getByRole('combobox', { name: 'Language', exact: true })).toHaveValue('auto')
  await expect(page).toHaveTitle('Reset your password | Famie')
  await page.goto(`/reset-password#id=1&token=${'a'.repeat(64)}`)
  await page.getByLabel('New password', { exact: true }).fill('a sufficiently long phrase')
  await page.getByLabel('Confirm new password', { exact: true }).fill('a different lengthy phrase')
  await page.getByRole('button', { name: 'Reset password', exact: true }).click()
  await expect(page.getByRole('alert')).toHaveText('Passwords do not match.')
  await openLanguage(page)
  await page.getByRole('combobox', { name: 'Language', exact: true }).selectOption('ja')
  await closeLanguage(page)
  await expect(page.getByRole('alert')).toHaveText('パスワードが一致しません。')
  await expect(page.getByLabel('新しいパスワード', { exact: true })).toHaveValue(
    'a sufficiently long phrase'
  )
})

test('account preference wins over guest selection and saves without losing profile input', async ({
  page,
  context,
}) => {
  const sample = await timelineSample(page, context)
  sample.user.ui_locale = 'en'
  sample.user.show_name_suffix = true
  await page.addInitScript(() => localStorage.setItem('famie.uiLocale.guest', 'ja'))
  const languages: string[] = []
  page.on('request', (request) => {
    if (request.url().includes('/v1/')) languages.push(request.headers()['accept-language'] ?? '')
  })
  await page.goto('/settings')
  await expect(page.locator('html')).toHaveAttribute('lang', 'en')
  await expect(page.locator('header')).toContainText('おとうさん')
  await expect(page.locator('header')).not.toContainText('おとうさん さん')
  await expect(page.getByRole('switch', { name: /san/ })).toHaveCount(0)
  expect(sample.user.show_name_suffix).toBe(true)
  await page.getByLabel('My display name', { exact: true }).fill('Unsaved name')
  await page.getByText('Display settings', { exact: true }).click()
  const languageForm = page
    .locator('form')
    .filter({ has: page.getByRole('radio', { name: 'English', exact: true }) })
  await languageForm.getByRole('radio', { name: '日本語', exact: true }).check()
  await expect(page.locator('html')).toHaveAttribute('lang', 'en')
  await languageForm.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(page.locator('html')).toHaveAttribute('lang', 'ja')
  await expect(page.getByText('言語設定を変更しました。')).toBeVisible()
  await expect(page.locator('header')).toContainText('おとうさん さん')
  await expect(page.getByRole('switch', { name: 'ヘッダの名前に『さん』を表示する' })).toBeChecked()
  expect(sample.user.show_name_suffix).toBe(true)
  await expect(page.getByLabel('自分の表示名', { exact: true })).toHaveValue('Unsaved name')
  expect(sample.user.display_name).toBe('おとうさん')
  expect(sample.user.ui_locale).toBe('ja')
  expect(languages).toContain('en')
  await page.reload()
  await page.getByText('表示設定', { exact: true }).click()
  sample.onProfile = (route) => route.fulfill({ status: 500, json: {} })
  await languageForm.getByRole('radio', { name: 'English', exact: true }).check()
  await languageForm.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByText('言語設定を保存できませんでした。再試行してください。')).toBeVisible()
  await expect(page.locator('html')).toHaveAttribute('lang', 'ja')
  await expect(languageForm.getByRole('radio', { name: '日本語', exact: true })).toBeChecked()
  await page.getByRole('button', { name: 'ログアウト', exact: true }).click()
  await expect(page).toHaveURL(/\/login\/?$/)
  await expect(page.locator('html')).toHaveAttribute('lang', 'ja')
  await openLanguage(page)
  await expect(page.getByRole('combobox', { name: '言語', exact: true })).toHaveValue('ja')
})

test('automatic account follows language changes while fixed preference stays fixed', async ({
  page,
  context,
}) => {
  const sample = await timelineSample(page, context, 'member')
  await page.goto('/settings')
  await expect(page.locator('html')).toHaveAttribute('lang', 'ja')
  await page.evaluate(() => {
    Object.defineProperty(navigator, 'languages', { configurable: true, get: () => ['en-US'] })
    window.dispatchEvent(new Event('languagechange'))
  })
  await expect(page.locator('html')).toHaveAttribute('lang', 'en')
  sample.user.ui_locale = 'en'
  await page.reload()
  await page.evaluate(() => {
    Object.defineProperty(navigator, 'languages', { configurable: true, get: () => ['ja-JP'] })
    window.dispatchEvent(new Event('languagechange'))
  })
  await expect(page.locator('html')).toHaveAttribute('lang', 'en')
})

test('database preference is shared across browser contexts and English fits a narrow screen', async ({
  page,
  context,
  browser,
  baseURL,
}, testInfo) => {
  if (!baseURL) throw new Error('Test base URL is required')
  const password = 'a sufficiently long phrase'
  const created = await context.request.post('/api/v1/auth/register', {
    data: {
      group_name: 'Language test family',
      username: `locale-${testInfo.project.name}-${Date.now()}`,
      display_name: 'Parent',
      password,
      password_confirmation: password,
    },
  })
  expect(created.status()).toBe(201)
  const identity = await created.json()
  expect(identity.user.ui_locale).toBeNull()
  await context.addCookies([{ name: 'auth_token', value: identity.access_token, url: baseURL }])
  await page.goto('/settings')
  await page.getByText('表示設定', { exact: true }).click()
  const form = page
    .locator('form')
    .filter({ has: page.getByRole('radio', { name: 'English', exact: true }) })
  await form.getByRole('radio', { name: 'English', exact: true }).check()
  await form.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.locator('html')).toHaveAttribute('lang', 'en')
  const second = await browser.newContext({ baseURL, locale: 'fr-FR', ignoreHTTPSErrors: true })
  try {
    await second.addCookies([{ name: 'auth_token', value: identity.access_token, url: baseURL }])
    const otherPage = await second.newPage()
    await otherPage.goto('/settings')
    await expect(otherPage.getByLabel('My display name', { exact: true })).toBeVisible()
    await page.setViewportSize({ width: 320, height: 700 })
    await page.locator('details').evaluateAll((elements) => {
      for (const element of elements) (element as HTMLDetailsElement).open = true
    })
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= document.documentElement.clientWidth
      )
    ).toBe(true)
    await page.screenshot({ path: testInfo.outputPath('english-settings-320.png'), fullPage: true })
    await form.getByRole('radio', { name: '日本語', exact: true }).check()
    await form.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(page.locator('html')).toHaveAttribute('lang', 'ja')
    await otherPage.reload()
    await expect(otherPage.getByLabel('自分の表示名', { exact: true })).toBeVisible()
  } finally {
    await second.close()
  }
})

test('guest switching works offline and corrupt storage falls back to English', async ({
  page,
  context,
}) => {
  await page.route('**/v1/status', (route) => route.fulfill({ json: { status: 'ok' } }))
  await page.addInitScript(() => localStorage.setItem('famie.uiLocale.guest', 'invalid-locale'))
  await page.goto('/login')
  await expect(page.locator('html')).toHaveAttribute('lang', 'en')
  await context.setOffline(true)
  await openLanguage(page)
  await page.getByRole('combobox', { name: 'Language', exact: true }).selectOption('ja')
  await expect(page.getByRole('button', { name: 'ログイン', exact: true })).toBeVisible()
  await openLanguage(page)
  await page.getByRole('combobox', { name: '言語', exact: true }).selectOption('en')
  await expect(page.getByRole('button', { name: 'Log in', exact: true })).toBeVisible()
  await context.setOffline(false)
})

test('an expired login session does not discard a public email confirmation link', async ({
  page,
  context,
  baseURL,
}) => {
  if (!baseURL) throw new Error('Test base URL is required')
  await context.addCookies([{ name: 'auth_token', value: 'expired-test-session', url: baseURL }])
  await page.route('**/v1/auth/me', (route) =>
    route.fulfill({ status: 401, json: { message: 'Unauthenticated' } })
  )
  await page.route('**/v1/auth/email/verify', (route) =>
    route.fulfill({ json: { message: 'メールアドレスを確認しました。' } })
  )
  await page.goto(`/verify-email#id=1&token=${'a'.repeat(64)}`)
  await expect(
    page.getByRole('button', { name: 'メールアドレスを確認する', exact: true })
  ).toBeEnabled()
  await openLanguage(page)
  await expect(page.getByRole('combobox', { name: '言語', exact: true })).toBeVisible()
  await closeLanguage(page)
  await page.getByRole('button', { name: 'メールアドレスを確認する', exact: true }).click()
  await expect(page.getByRole('main').getByRole('status')).toHaveText(
    'メールアドレスを確認しました。'
  )
})

test('language dialog returns focus on Escape and fits narrow cards', async ({
  page,
}, testInfo) => {
  await page.route('**/v1/status', (route) => route.fulfill({ json: { status: 'ok' } }))
  await page.setViewportSize({ width: 320, height: 700 })
  for (const path of [
    '/login',
    '/register',
    '/forgot-password',
    '/reset-password',
    '/verify-email',
  ]) {
    await page.goto(path)
    await expect(page.getByRole('combobox')).toHaveCount(0)
    const trigger = page.getByRole('button', { name: /^(Language|言語)$/ })
    await trigger.click()
    await expect(page.getByRole('dialog')).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog')).toHaveCount(0)
    await expect(trigger).toBeFocused()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  }
  await page.goto('/register')
  await page.screenshot({
    path: testInfo.outputPath('register-language-icon-320.png'),
    fullPage: true,
  })
  await openLanguage(page)
  await page.screenshot({ path: testInfo.outputPath('language-dialog-320.png'), fullPage: true })
})

test('family members keep independent account languages across logout and user switching', async ({
  browser,
  context,
  page,
  baseURL,
}, testInfo) => {
  if (!baseURL) throw new Error('Test base URL is required')
  const password = 'family language isolation phrase'
  const username = `family-locale-${testInfo.project.name}-${Date.now()}`
  const registration = await context.request.post('/api/v1/auth/register', {
    data: {
      group_name: '原文の家族 Family',
      username,
      display_name: '親の原文 Parent',
      password,
      password_confirmation: password,
    },
  })
  expect(registration.status()).toBe(201)
  const parent = await registration.json()
  const parentHeaders = { Authorization: `Bearer ${parent.access_token}` }
  const created = await context.request.post(
    `/api/v1/groups/${parent.membership.group.id}/members`,
    {
      headers: parentHeaders,
      data: {
        username: `${username}-child`,
        display_name: '子の原文 Child',
        role: 'member',
        password,
        password_confirmation: password,
      },
    }
  )
  expect(created.status()).toBe(201)
  const login = await context.request.post('/api/v1/auth/login', {
    data: { login: `${username}-child`, password },
  })
  expect(login.status()).toBe(200)
  const child = await login.json()
  const childHeaders = { Authorization: `Bearer ${child.access_token}` }
  expect(
    (
      await context.request.patch('/api/v1/auth/me', {
        headers: parentHeaders,
        data: { ui_locale: 'ja' },
      })
    ).ok()
  ).toBe(true)
  expect(
    (
      await context.request.patch('/api/v1/auth/me', {
        headers: childHeaders,
        data: { ui_locale: 'en' },
      })
    ).ok()
  ).toBe(true)
  await context.addCookies([{ name: 'auth_token', value: parent.access_token, url: baseURL }])
  const childContext = await browser.newContext({
    baseURL,
    locale: 'ja-JP',
    ignoreHTTPSErrors: true,
  })
  try {
    await childContext.addCookies([{ name: 'auth_token', value: child.access_token, url: baseURL }])
    const childPage = await childContext.newPage()
    await page.goto('/settings')
    await childPage.goto('/settings')
    await expect(page.locator('html')).toHaveAttribute('lang', 'ja')
    await expect(childPage.locator('html')).toHaveAttribute('lang', 'en')
    await expect(page.getByLabel('自分の表示名', { exact: true })).toHaveValue('親の原文 Parent')
    await expect(childPage.getByLabel('My display name', { exact: true })).toHaveValue(
      '子の原文 Child'
    )
    const members = await childContext.request.get(
      `/api/v1/groups/${parent.membership.group.id}/members`,
      { headers: childHeaders }
    )
    expect(
      (await members.json()).map((member: { display_name: string }) => member.display_name)
    ).toEqual(['親の原文 Parent', '子の原文 Child'])
    const parentMe = await context.request.get('/api/v1/auth/me', { headers: parentHeaders })
    expect((await parentMe.json()).user.ui_locale).toBe('ja')
    await childPage.getByRole('button', { name: 'Log out', exact: true }).click()
    await expect(childPage).toHaveURL(/\/login\/?$/)
    await expect(childPage.locator('html')).toHaveAttribute('lang', 'ja')
    await childPage.getByLabel('ログインID / メールアドレス', { exact: true }).fill(username)
    await childPage.getByLabel('パスワード', { exact: true }).fill(password)
    await childPage.getByRole('button', { name: 'ログイン', exact: true }).click()
    await expect(childPage.getByRole('link', { name: '設定', exact: true })).toBeVisible()
    await expect(childPage.locator('html')).toHaveAttribute('lang', 'ja')
    await page.reload()
    await expect(page.locator('html')).toHaveAttribute('lang', 'ja')
    const childMe = await context.request.get('/api/v1/auth/me', { headers: childHeaders })
    expect(childMe.status()).toBe(401)
  } finally {
    await childContext.close()
  }
})
