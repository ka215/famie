import { expect, type Page, test } from '@playwright/test'

const initialPassword = 'Famie-recovery-only-2026!'

async function createFamily(page: Page) {
  const username = `recovery${Date.now()}${Math.floor(Math.random() * 10000)}`
  const response = await page.request.post('/api/v1/auth/register', {
    data: {
      username,
      password: initialPassword,
      password_confirmation: initialPassword,
      display_name: '復旧確認の親',
      group_name: '復旧確認',
    },
  })
  expect(response.status()).toBe(201)
  return { username, ...(await response.json()) }
}

async function login(page: Page, username: string) {
  await page.goto('/login')
  await page.getByPlaceholder('例: parent1').fill(username)
  await page.getByLabel('パスワード', { exact: true }).fill(initialPassword)
  await page.getByRole('button', { name: 'ログイン', exact: true }).click()
  await expect(page.getByRole('link', { name: '設定', exact: true })).toBeVisible()
}

test('親がメール未登録メンバーを再発行でき、既存ログインは失効する', async ({ page }) => {
  const family = await createFamily(page)
  const childUsername = `${family.username}child`
  const created = await page.request.post(`/api/v1/groups/${family.membership.group.id}/members`, {
    headers: { Authorization: `Bearer ${family.access_token}` },
    data: {
      username: childUsername,
      display_name: '再発行対象',
      role: 'member',
      password: initialPassword,
      password_confirmation: initialPassword,
    },
  })
  expect(created.status()).toBe(201)
  const oldLogin = await page.request.post('/api/v1/auth/login', {
    data: { login: childUsername, password: initialPassword },
  })
  expect(oldLogin.status()).toBe(200)
  const oldToken = (await oldLogin.json()).access_token
  await login(page, family.username)
  await page.getByRole('link', { name: '設定', exact: true }).click()
  await page.getByRole('heading', { name: '家族メンバー', exact: true }).click()
  await page.getByRole('button', { name: '再発行対象を管理', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'メンバーを管理', exact: true })
  await dialog.getByRole('button', { name: 'パスワードを再発行', exact: true }).click()
  await dialog.getByLabel('親自身の現在のパスワード', { exact: true }).fill('wrong-parent-password')
  await dialog
    .getByLabel('メンバーの新しいパスワード', { exact: true })
    .fill('river otter garden sunset')
  await dialog
    .getByLabel('メンバーの新しいパスワード（確認）', { exact: true })
    .fill('river otter garden sunset')
  await expect(dialog.getByRole('meter')).toHaveAttribute('value', '3')
  await dialog.getByRole('button', { name: 'パスワードを再発行する', exact: true }).click()
  await expect(dialog.getByRole('alert')).toContainText(
    '親自身の現在のパスワードが正しくありません'
  )
  await dialog.getByLabel('親自身の現在のパスワード', { exact: true }).fill(initialPassword)
  await dialog.getByRole('button', { name: 'パスワードを再発行する', exact: true }).click()
  await expect(dialog.getByRole('status')).toContainText('本人へ伝えてください')
  await expect(dialog.getByLabel('メンバーの新しいパスワード', { exact: true })).toHaveCount(0)
  const expired = await page.request.get('/api/v1/auth/me', {
    headers: { Authorization: `Bearer ${oldToken}` },
  })
  expect(expired.status()).toBe(401)
  const replacement = await page.request.post('/api/v1/auth/login', {
    data: { login: childUsername, password: 'river otter garden sunset' },
  })
  expect(replacement.status()).toBe(200)
  await page.reload()
  await page.getByRole('heading', { name: '家族メンバー', exact: true }).click()
  await expect(page.getByRole('button', { name: '再発行対象を管理', exact: true })).toBeVisible()
})

test('親への安全性通知からメンバー管理へ移動し、確認済みを保存できる', async ({ page }) => {
  const family = await createFamily(page)
  let unread = true
  await page.route('**/groups/*/security-alerts', (route) =>
    route.fulfill({
      json: {
        data: unread
          ? [
              {
                id: '12345678-1234-4234-8234-123456789abc',
                display_name: '通知対象<script>',
                username: 'notice-member',
                occurred_at: '2026-10-16T06:00:00+00:00',
              },
            ]
          : [],
      },
    })
  )
  await page.route('**/security-alerts/*/read', (route) => {
    expect(route.request().method()).toBe('PUT')
    unread = false
    return route.fulfill({ json: { message: '通知を確認済みにしました。' } })
  })
  await login(page, family.username)
  const notice = page.getByRole('dialog', { name: 'ログインの安全性に関する通知' })
  await expect(notice).toContainText('本人の入力間違いの可能性もあります')
  await expect(notice).toContainText('通知対象<script>')
  await expect(notice.locator('script')).toHaveCount(0)
  const position = await notice.evaluate((element) => {
    const bounds = element.getBoundingClientRect()
    return {
      x: bounds.x + bounds.width / 2 - window.innerWidth / 2,
      y: bounds.y + bounds.height / 2 - window.innerHeight / 2,
      modal: element.matches(':modal'),
    }
  })
  expect(position.modal).toBe(true)
  expect(Math.abs(position.x)).toBeLessThanOrEqual(1)
  expect(Math.abs(position.y)).toBeLessThanOrEqual(1)
  await notice.getByRole('link', { name: 'メンバー管理を開く' }).click()
  await expect(page).toHaveURL(/\/settings#family-members$/)
  await expect(page.getByRole('button', { name: '復旧確認の親を管理', exact: true })).toBeVisible()
  await expect(notice).toHaveCount(0)
  expect(unread).toBe(true)
  await page.reload()
  await expect(notice).toBeVisible()
  await notice.getByRole('button', { name: '確認済みにする' }).click()
  await expect(notice).toHaveCount(0)
  await page.reload()
  await expect(page.getByRole('button', { name: '復旧確認の親を管理', exact: true })).toBeVisible()
  await expect(notice).toHaveCount(0)
})
