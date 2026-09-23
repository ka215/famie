import { expect, type Page, test } from '@playwright/test'

const maintenance = {
  status: 503,
  contentType: 'application/json',
  headers: { 'Cache-Control': 'no-store', 'Retry-After': '60' },
  body: JSON.stringify({ code: 'maintenance', message: 'メンテナンス中です' }),
}

async function login(page: Page) {
  await page.goto('/login')
  await page.getByPlaceholder('例: parent1').fill('parent1')
  await page.getByLabel('パスワード', { exact: true }).fill('Famie-E2E-only-123!')
  await page.getByRole('button', { name: 'ログイン', exact: true }).click()
  await expect(page.getByRole('button', { name: 'ログアウト' })).toBeVisible()
}

test('起動時の503で認証を維持し、再試行で復旧する', async ({ page, context }) => {
  await login(page)
  const token = (await context.cookies()).find((cookie) => cookie.name === 'auth_token')?.value
  await page.route('**/api/v1/**', (route) => route.fulfill(maintenance))
  await page.reload()
  await expect(page.getByRole('heading', { name: 'ただいまメンテナンス中です' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'ログイン', exact: true })).toHaveCount(0)
  expect((await context.cookies()).find((cookie) => cookie.name === 'auth_token')?.value).toBe(
    token
  )
  await page.getByRole('button', { name: '再試行', exact: true }).click()
  await expect(page.getByRole('main').getByRole('status')).toContainText('まだメンテナンス中です')
  await page.unroute('**/api/v1/**')
  await page.getByRole('button', { name: '再試行', exact: true }).click()
  await expect(page.getByRole('button', { name: 'ログアウト' })).toBeVisible()
  expect((await context.cookies()).find((cookie) => cookie.name === 'auth_token')?.value).toBe(
    token
  )
})

test('保存時の503を案内し、復旧しても書き込みを自動再送しない', async ({ page }) => {
  await login(page)
  await page.getByRole('button', { name: 'アクティビティを記録する' }).click()
  await page.locator('form select').selectOption({ label: '勉強・宿題' })
  await page.getByPlaceholder('例: 算数のドリルを2ページ進めた').fill('メンテナンス中の未保存記録')
  let writes = 0
  await page.route('**/api/v1/logs', async (route) => {
    if (route.request().method() === 'POST') {
      writes++
      await route.fulfill(maintenance)
    } else {
      await route.continue()
    }
  })
  await page.getByRole('button', { name: '記録を保存する' }).click()
  await expect(page.getByRole('heading', { name: 'ただいまメンテナンス中です' })).toBeVisible()
  await expect(
    page.getByText('保存操作は自動で再送されません。再開後に記録を確認してください。')
  ).toBeVisible()
  await page.getByRole('button', { name: '再試行', exact: true }).click()
  await expect(page.getByRole('button', { name: 'ログアウト' })).toBeVisible()
  expect(writes).toBe(1)
  await expect(page.getByText('メンテナンス中の未保存記録', { exact: true })).toHaveCount(0)
})

test('ログアウト時の503でも認証情報を消さない', async ({ page, context }) => {
  await login(page)
  const token = (await context.cookies()).find((cookie) => cookie.name === 'auth_token')?.value
  await page.route('**/api/v1/auth/logout', (route) => route.fulfill(maintenance))
  await page.getByRole('button', { name: 'ログアウト' }).click()
  await expect(page.getByRole('heading', { name: 'ただいまメンテナンス中です' })).toBeVisible()
  expect((await context.cookies()).find((cookie) => cookie.name === 'auth_token')?.value).toBe(
    token
  )
})

test('メンテナンス案内中の通信断を区別し、案内を維持する', async ({ page }) => {
  await login(page)
  await page.route('**/api/v1/**', (route) => route.fulfill(maintenance))
  await page.reload()
  await expect(page.getByRole('heading', { name: 'ただいまメンテナンス中です' })).toBeVisible()
  await page.unroute('**/api/v1/**')
  await page.route('**/api/v1/status', (route) => route.abort('failed'))
  await page.getByRole('button', { name: '再試行', exact: true }).click()
  await expect(page.getByRole('main').getByRole('status')).toContainText(
    '通信環境を確認して再試行してください'
  )
  await expect(page.getByRole('heading', { name: 'ただいまメンテナンス中です' })).toBeVisible()
})

test('通常の503や通信断をメンテナンスと誤認しない', async ({ page }) => {
  await page.route('**/api/v1/auth/login', (route) =>
    route.fulfill({
      status: 503,
      contentType: 'application/json',
      body: '{"message":"Unavailable"}',
    })
  )
  await page.goto('/login')
  await page.getByPlaceholder('例: parent1').fill('parent1')
  await page.getByLabel('パスワード', { exact: true }).fill('Famie-E2E-only-123!')
  await page.getByRole('button', { name: 'ログイン', exact: true }).click()
  await expect(page.getByText('Unavailable', { exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'ただいまメンテナンス中です' })).toHaveCount(0)
  await page.unroute('**/api/v1/auth/login')
  await page.route('**/api/v1/auth/login', (route) => route.abort('failed'))
  await page.getByRole('button', { name: 'ログイン', exact: true }).click()
  await expect(page.getByRole('button', { name: 'ログイン', exact: true })).toBeEnabled()
  await expect(page.getByRole('heading', { name: 'ただいまメンテナンス中です' })).toHaveCount(0)
})
