import { expect, test } from '@playwright/test'

const initialPassword = 'Famie-security-only-2026!'
const dialogTitle = 'パスワードの安全性'

test('弱いパスワードの警告を画面遷移で再表示し、モーダルから変更できる', async ({ page }) => {
  const username = `security${Date.now()}${Math.floor(Math.random() * 10000)}`
  const created = await page.request.post('/api/v1/auth/register', {
    data: {
      username,
      password: initialPassword,
      password_confirmation: initialPassword,
      display_name: '安全性確認',
      group_name: '安全性確認',
    },
  })
  expect(created.status()).toBe(201)
  // The API tests exercise legacy hashes; this response override selects the warning UI.
  await page.route('**/auth/login', async (route) => {
    const response = await route.fetch()
    const data = await response.json()
    data.user.password_security = { status: 'weak', snoozed_until: null }
    await route.fulfill({ response, json: data })
  })
  await page.goto('/login')
  await page.getByPlaceholder('例: parent1').fill(username)
  await page.getByLabel('パスワード', { exact: true }).fill(initialPassword)
  await page.getByRole('button', { name: 'ログイン', exact: true }).click()
  const notice = page.getByRole('dialog', { name: dialogTitle })
  await expect(notice).toContainText('新しい設定基準を満たしていません')
  await notice.getByRole('button', { name: 'あとで確認する' }).click()
  await expect(notice).toHaveCount(0)
  await page.getByRole('link', { name: '設定', exact: true }).click()
  await expect(notice).toBeVisible()
  await notice.getByRole('button', { name: 'パスワードを変更', exact: true }).click()
  await notice.getByLabel('現在のパスワード', { exact: true }).fill(initialPassword)
  await notice.getByLabel('新しいパスワード', { exact: true }).fill('aaaaaaaaaaaaaaa')
  await notice.getByLabel('新しいパスワード（確認）', { exact: true }).fill('aaaaaaaaaaaaaaa')
  await expect(notice.getByRole('meter')).toHaveAttribute('value', '1')
  await notice.screenshot({ path: test.info().outputPath('password-security.png') })
  await notice.getByRole('button', { name: 'パスワードを変更する', exact: true }).click()
  await expect(notice.getByRole('alert')).toContainText('15文字以上')
  const replacement = 'river otter garden sunset'
  await notice.getByLabel('新しいパスワード', { exact: true }).fill(replacement)
  await notice.getByLabel('新しいパスワード（確認）', { exact: true }).fill(replacement)
  await notice.getByRole('button', { name: 'パスワードを変更する', exact: true }).click()
  await expect(notice).toHaveCount(0)
  const login = await page.request.post('/api/v1/auth/login', {
    data: { login: username, password: replacement },
  })
  expect(login.status()).toBe(200)
  expect((await login.json()).user.password_security.status).toBe('ok')
})

test('翌日までの警告抑制は再読込後も維持され、期限後の画面遷移で再表示する', async ({ page }) => {
  const username = `snooze${Date.now()}${Math.floor(Math.random() * 10000)}`
  const created = await page.request.post('/api/v1/auth/register', {
    data: {
      username,
      password: initialPassword,
      password_confirmation: initialPassword,
      display_name: '通知確認',
      group_name: '通知確認',
    },
  })
  expect(created.status()).toBe(201)
  let snoozedUntil: string | null = null
  for (const endpoint of ['**/auth/login', '**/auth/me', '**/auth/me/password-warning/snooze']) {
    await page.route(endpoint, async (route) => {
      const response = await route.fetch()
      const data = await response.json()
      if (route.request().url().endsWith('/snooze')) {
        expect(route.request().postData()).toBeNull()
        snoozedUntil = data.user.password_security.snoozed_until
      }
      data.user.password_security = { status: 'weak', snoozed_until: snoozedUntil }
      await route.fulfill({ response, json: data })
    })
  }
  await page.clock.install({ time: new Date() })
  await page.goto('/login')
  await page.getByPlaceholder('例: parent1').fill(username)
  await page.getByLabel('パスワード', { exact: true }).fill(initialPassword)
  await page.getByRole('button', { name: 'ログイン', exact: true }).click()
  const notice = page.getByRole('dialog', { name: dialogTitle })
  await notice.getByLabel('翌日までこの通知を表示しない（日本時間）').check()
  await notice.getByRole('button', { name: 'あとで確認する' }).click()
  await expect(notice).toHaveCount(0)
  expect(snoozedUntil).not.toBeNull()
  await page.getByRole('link', { name: '設定', exact: true }).click()
  await expect(page).toHaveURL(/\/settings\/?$/)
  await expect(notice).toHaveCount(0)
  await page.reload()
  await expect(page.getByRole('heading', { name: 'ログイン情報', exact: true })).toBeVisible()
  await expect(notice).toHaveCount(0)
  await page.clock.setSystemTime(new Date(Date.parse(snoozedUntil ?? '') + 1000))
  await page.getByRole('link', { name: 'タイムライン', exact: true }).click()
  await expect(notice).toBeVisible()
})

test('ログイン制限時は再試行までの時間を表示し、自動再送しない', async ({ page }) => {
  let attempts = 0
  await page.route('**/auth/login', (route) => {
    attempts++
    return route.fulfill({
      status: 429,
      headers: { 'Retry-After': '600' },
      json: { message: 'ログイン試行回数が上限に達しました。' },
    })
  })
  await page.goto('/login')
  await page.getByPlaceholder('例: parent1').fill('limited-user')
  await page.getByLabel('パスワード', { exact: true }).fill(initialPassword)
  await page.getByRole('button', { name: 'ログイン', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('約10分後')
  expect(attempts).toBe(1)
})
