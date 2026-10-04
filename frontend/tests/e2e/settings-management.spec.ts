import { type BrowserContext, expect, type Page, test } from '@playwright/test'

const password = 'Famie-E2E-only-123!'
const setupFamily = async (page: Page, context: BrowserContext) => {
  const username = `manage${Date.now()}${Math.floor(Math.random() * 10000)}`
  const response = await page.request.post('/api/v1/auth/register', {
    data: {
      username,
      display_name: '管理する親',
      group_name: '管理テスト家族',
      password,
      password_confirmation: password,
    },
  })
  expect(response.ok()).toBeTruthy()
  const identity = await response.json()
  await context.addCookies([
    { name: 'auth_token', value: identity.access_token, url: 'http://127.0.0.1' },
  ])
  return {
    path: `/api/v1/groups/${identity.membership.group.id}`,
    headers: { Authorization: `Bearer ${identity.access_token}` },
    username,
  }
}

test('管理者がメンバーを編集・無効化・復元し、履歴が再表示される', async ({ page, context }) => {
  const family = await setupFamily(page, context)
  const created = await page.request.post(`${family.path}/members`, {
    headers: family.headers,
    data: {
      username: `${family.username}kid`,
      display_name: '管理対象',
      role: 'member',
      password,
      password_confirmation: password,
    },
  })
  expect(created.ok()).toBeTruthy()
  const target = (await created.json()).data
  const login = await page.request.post('/api/v1/auth/login', {
    data: { login: target.username, password },
  })
  const targetToken = (await login.json()).access_token
  const categories = await (
    await page.request.get(`${family.path}/categories`, { headers: family.headers })
  ).json()
  const recorded = await page.request.post(`${family.path}/logs`, {
    headers: { Authorization: `Bearer ${targetToken}` },
    data: { category_id: categories[0].id, activity_date: '2026-10-03', content: '復元される活動' },
  })
  expect(recorded.ok()).toBeTruthy()
  await page.goto('/settings')
  await page.getByText('家族メンバー', { exact: true }).click()
  await page.getByRole('button', { name: '管理対象を管理', exact: true }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('メンバーの表示名').fill('編集した名前')
  await dialog.getByLabel('メンバーの役割').selectOption('admin')
  await dialog.getByRole('button', { name: '変更を保存' }).click()
  await expect(dialog).toHaveCount(0)
  await page.getByRole('button', { name: '編集した名前を管理', exact: true }).click()
  await expect(dialog.getByLabel('メンバーの役割')).toHaveValue('admin')
  await dialog.getByRole('button', { name: 'このメンバーを無効化' }).click()
  await expect(dialog.getByText(/活動記録が全員の画面から非表示/)).toBeVisible()
  await dialog.getByRole('button', { name: '編集に戻る' }).click()
  await dialog.getByRole('button', { name: 'このメンバーを無効化' }).click()
  await dialog.getByRole('button', { name: '無効化する', exact: true }).click()
  await expect(page.getByRole('button', { name: '編集した名前を管理', exact: true })).toHaveCount(0)
  const hidden = await page.request.get(`${family.path}/logs`, { headers: family.headers })
  expect((await hidden.json()).total).toBe(0)
  const denied = await page.request.get(`${family.path}/logs`, {
    headers: { Authorization: `Bearer ${targetToken}` },
  })
  expect(denied.status()).toBe(401)
  await page.getByRole('button', { name: '無効化済みメンバーを復元', exact: true }).click()
  await page.getByRole('button', { name: '編集した名前を復元', exact: true }).click()
  await dialog.getByRole('button', { name: '復元する', exact: true }).click()
  await expect(page.getByRole('button', { name: '編集した名前を管理', exact: true })).toBeVisible()
  expect(
    (await (await page.request.get(`${family.path}/logs`, { headers: family.headers })).json())
      .total
  ).toBe(1)
  await page.getByRole('button', { name: '管理する親を管理', exact: true }).click()
  await expect(dialog.getByLabel('メンバーの役割')).toBeDisabled()
  await expect(dialog.getByRole('button', { name: 'このメンバーを無効化' })).toHaveCount(0)
})

test('カテゴリを編集し、使用中は削除拒否、未使用なら確認後に削除できる', async ({
  page,
  context,
}) => {
  const family = await setupFamily(page, context)
  const created = await page.request.post(`${family.path}/categories`, {
    headers: family.headers,
    data: { name: '編集するカテゴリ', color_code: '#123456' },
  })
  expect(created.ok()).toBeTruthy()
  const category = (await created.json()).data
  await page.goto('/settings')
  await page.getByRole('heading', { name: 'カテゴリ', exact: true }).click()
  await page.getByRole('button', { name: '編集するカテゴリを管理' }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('カテゴリ名', { exact: true }).fill('変更したカテゴリ')
  await dialog.getByLabel('カテゴリの色').fill('#ffffff')
  await dialog.getByRole('button', { name: '変更を保存' }).click()
  const badge = page.getByRole('button', { name: '変更したカテゴリを管理' })
  await expect(badge).toHaveCSS('color', 'rgb(0, 0, 0)')
  const recorded = await page.request.post(`${family.path}/logs`, {
    headers: family.headers,
    data: { category_id: category.id, activity_date: '2026-10-03', content: '削除を防ぐ記録' },
  })
  const log = (await recorded.json()).data
  await badge.click()
  await dialog.getByRole('button', { name: 'このカテゴリを削除' }).click()
  await dialog.getByRole('button', { name: '削除する', exact: true }).click()
  await expect(dialog.getByRole('alert')).toHaveText('ログが存在するカテゴリは削除できません。')
  await dialog.getByRole('button', { name: '閉じる', exact: true }).click()
  expect(
    (await page.request.delete(`${family.path}/logs/${log.id}`, { headers: family.headers })).ok()
  ).toBeTruthy()
  await badge.click()
  await dialog.getByRole('button', { name: 'このカテゴリを削除' }).click()
  await dialog.getByRole('button', { name: '削除する', exact: true }).click()
  await expect(dialog).toHaveCount(0)
  await expect(badge).toHaveCount(0)
})

test('一般メンバーには管理操作がなく、テーマは変更・保持できる', async ({ page, context }) => {
  await page.emulateMedia({ colorScheme: 'light' })
  const family = await setupFamily(page, context)
  const username = `${family.username}kid`
  await page.request.post(`${family.path}/members`, {
    headers: family.headers,
    data: {
      username,
      display_name: '一般メンバー',
      role: 'member',
      password,
      password_confirmation: password,
    },
  })
  const identity = await (
    await page.request.post('/api/v1/auth/login', { data: { login: username, password } })
  ).json()
  await context.addCookies([
    { name: 'auth_token', value: identity.access_token, url: 'http://127.0.0.1' },
  ])
  await page.goto('/settings')
  await page.getByRole('heading', { name: '家族メンバー', exact: true }).click()
  await page.getByRole('heading', { name: 'カテゴリ', exact: true }).click()
  await expect(page.getByRole('button', { name: /を管理$/ })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '無効化済みメンバーを復元' })).toHaveCount(0)
  await page.getByRole('heading', { name: '表示設定', exact: true }).click()
  await page.getByRole('radio', { name: 'ダーク', exact: true }).check()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
  await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#020617')
  await page.reload()
  await expect(page.locator('html')).toHaveCSS('color-scheme', 'dark')
  await page.getByRole('button', { name: 'ログアウト', exact: true }).click()
  await expect(page).toHaveURL(/\/login$/)
  await expect(page.locator('html')).toHaveCSS('color-scheme', 'dark')
  await page.goto('/maintenance.html')
  await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(2, 6, 23)')
})

test('OSがダークでもライトを選べ、保存できなくても現在の画面を切り替えられる', async ({
  page,
  context,
}) => {
  await page.emulateMedia({ colorScheme: 'dark' })
  await setupFamily(page, context)
  await page.goto('/settings')
  await page.getByRole('heading', { name: '表示設定', exact: true }).click()
  await page.getByRole('radio', { name: 'ライト', exact: true }).check()
  await expect(page.locator('html')).toHaveCSS('color-scheme', 'light')
  const otherTab = await context.newPage()
  await otherTab.goto('/settings')
  await expect(otherTab.locator('html')).toHaveCSS('color-scheme', 'light')
  await page.getByRole('radio', { name: 'ダーク', exact: true }).check()
  await expect(otherTab.locator('html')).toHaveCSS('color-scheme', 'dark')
  await page.getByRole('radio', { name: 'ライト', exact: true }).check()
  await otherTab.close()
  await page.reload()
  await expect(page.locator('html')).toHaveCSS('color-scheme', 'light')
  await page.getByRole('heading', { name: '表示設定', exact: true }).click()
  await page.evaluate(() => {
    Storage.prototype.setItem = () => {
      throw new Error('Storage unavailable')
    }
  })
  await page.getByRole('radio', { name: 'ダーク', exact: true }).check()
  await expect(page.locator('html')).toHaveCSS('color-scheme', 'dark')
  await expect(page.getByRole('group', { name: '表示モード' }).getByRole('alert')).toContainText(
    '表示設定を保存できませんでした'
  )
})
