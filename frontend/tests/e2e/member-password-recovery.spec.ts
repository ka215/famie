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
                group_id: family.membership.group.id,
                membership_id: family.membership.id,
                display_name: '通知対象<script>',
                username: 'notice-member',
                occurred_at: '2026-10-16T06:00:00+00:00',
              },
            ]
          : [],
        meta: {
          unread_total: unread ? 1 : 0,
          unread_by_member: unread ? [{ membership_id: family.membership.id, count: 1 }] : [],
          limit: 20,
          has_more: false,
        },
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

test('20件外の警告もメンバー別に再表示でき、失敗では消さず確認後に操作位置へ戻る', async ({
  page,
}) => {
  const family = await createFamily(page)
  const parentId = family.membership.id
  const created = await page.request.post(`/api/v1/groups/${family.membership.group.id}/members`, {
    headers: { Authorization: `Bearer ${family.access_token}` },
    data: {
      username: `${family.username}child`,
      display_name: '別の対象',
      role: 'member',
      password: initialPassword,
      password_confirmation: initialPassword,
    },
  })
  expect(created.status()).toBe(201)
  const childId = (await created.json()).data.membership_id
  const childAlerts = Array.from({ length: 21 }, (_, index) => ({
    id: `child-alert-${index}`,
    group_id: family.membership.group.id,
    membership_id: childId,
    display_name: '別の対象',
    username: 'child-notice',
    occurred_at: '2026-10-24T06:00:00+00:00',
  }))
  const parentAlert = {
    ...childAlerts[0],
    id: 'parent-alert',
    membership_id: parentId,
    display_name: '通知時点の親',
    username: family.username,
  }
  let parentUnread = true
  let failLoad = false
  let failRead = true
  let holdFilter = false
  let releaseFilter: (() => void) | undefined
  await page.route('**/groups/*/security-alerts*', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback()
    const memberId = new URL(route.request().url()).searchParams.get('membership_id')
    if (memberId && holdFilter) {
      await new Promise<void>((resolve) => {
        releaseFilter = resolve
      })
    }
    if (memberId && failLoad)
      return route.fulfill({ status: 500, json: { message: 'temporary failure' } })
    const all = [...childAlerts, ...(parentUnread ? [parentAlert] : [])]
    const filtered = memberId
      ? all.filter((alert) => alert.membership_id === Number(memberId))
      : all
    return route.fulfill({
      json: {
        data: filtered.slice(0, 20),
        meta: {
          unread_total: filtered.length,
          limit: 20,
          has_more: filtered.length > 20,
          unread_by_member: [
            { membership_id: childId, count: childAlerts.length },
            ...(parentUnread ? [{ membership_id: parentId, count: 1 }] : []),
          ],
        },
      },
    })
  })
  await page.route('**/security-alerts/*/read', (route) => {
    if (failRead) return route.fulfill({ status: 500, json: { message: 'temporary failure' } })
    parentUnread = false
    return route.fulfill({ json: { message: 'OK' } })
  })
  await login(page, family.username)
  const dialog = page.getByRole('dialog', { name: 'ログインの安全性に関する通知' })
  await expect(dialog).toContainText('未確認22件のうち最新20件')
  await dialog.getByRole('link', { name: 'メンバー管理を開く' }).click()
  const parentButton = page.getByRole('button', {
    name: '復旧確認の親の未確認ログイン警告を表示',
    exact: true,
  })
  await expect(parentButton).toBeVisible()
  await expect(
    page.getByRole('button', { name: '別の対象の未確認ログイン警告を表示', exact: true })
  ).toBeVisible()
  failLoad = true
  await parentButton.click()
  await expect(dialog.getByRole('alert')).toContainText('取得できませんでした')
  failLoad = false
  await dialog.getByRole('button', { name: '再試行', exact: true }).click()
  await expect(dialog).toContainText('通知時点の親')
  await expect(dialog).not.toContainText('別の対象')
  await dialog.getByRole('button', { name: '確認済みにする' }).click()
  await expect(dialog.getByRole('alert')).toContainText('確認済みにできませんでした')
  failRead = false
  await dialog.getByRole('button', { name: '確認済みにする' }).click()
  await expect(dialog).toHaveCount(0)
  await expect(parentButton).toHaveCount(0)
  await expect(page.locator('[data-alert-focus]').first()).toBeFocused()
  await page.evaluate(() => window.dispatchEvent(new Event('focus')))
  await expect(dialog).toHaveCount(0)
  await page
    .getByRole('button', { name: '別の対象の未確認ログイン警告を表示', exact: true })
    .click()
  await expect(dialog).toContainText('未確認21件のうち最新20件')
  await dialog.getByRole('button', { name: '閉じる', exact: true }).click()
  await expect(dialog).toHaveCount(0)
  holdFilter = true
  await page
    .getByRole('button', { name: '別の対象の未確認ログイン警告を表示', exact: true })
    .click()
  await expect.poll(() => !!releaseFilter).toBe(true)
  await page.keyboard.press('Escape')
  await expect(dialog).toHaveCount(0)
  holdFilter = false
  const lateResponse = page.waitForResponse((response) =>
    response.url().includes('security-alerts?membership_id=')
  )
  releaseFilter?.()
  await lateResponse
  await expect(dialog).toHaveCount(0)
  childAlerts.unshift({ ...childAlerts[0], id: 'new-alert' })
  await page.evaluate(() => window.dispatchEvent(new Event('focus')))
  await expect(dialog).toBeVisible()
  await expect(dialog).toContainText('未確認22件のうち最新20件')
})
