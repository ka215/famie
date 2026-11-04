import { type BrowserContext, expect, type Page, test } from '@playwright/test'

const password = 'Famie-E2E-only-123!'

async function registerFamily(page: Page, context: BrowserContext) {
  const username = `life${Date.now()}${Math.floor(Math.random() * 10000)}`
  const response = await page.request.post('/api/v1/auth/register', {
    data: {
      username,
      display_name: '検証代表者',
      group_name: 'ライフサイクル検証家族',
      password,
      password_confirmation: password,
    },
  })
  expect(response.status()).toBe(201)
  const identity = await response.json()
  await context.addCookies([
    { name: 'auth_token', value: identity.access_token, url: 'https://127.0.0.1' },
  ])
  return {
    path: `/api/v1/groups/${identity.membership.group.id}`,
    headers: { Authorization: `Bearer ${identity.access_token}` },
  }
}

test('実APIで家族退会を確定し、トークン失効と公開状況確認を検証する', async ({ page, context }) => {
  const family = await registerFamily(page, context)
  await page.goto('/settings')
  await page.getByText('家族設定', { exact: true }).click()
  await page.getByRole('button', { name: '家族の利用を終了（退会）' }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog.locator('dd')).toHaveCount(2)
  await dialog.getByRole('checkbox').check()
  await dialog.getByRole('textbox', { name: '現在のパスワード' }).fill('wrong-password')
  await dialog.getByRole('button', { name: '家族全員の利用を終了' }).click()
  await expect(dialog.getByRole('alert')).toHaveText('現在のパスワードが正しくありません。')
  await dialog.getByRole('button', { name: '閉じる', exact: true }).click()
  for (let attempt = 0; attempt < 5; attempt++) {
    await page.getByRole('button', { name: '家族の利用を終了（退会）' }).click()
    await expect(dialog.locator('dd')).toHaveCount(2)
    await dialog.getByRole('button', { name: '閉じる', exact: true }).click()
  }
  await page.getByRole('button', { name: '家族の利用を終了（退会）' }).click()
  await expect(dialog.locator('dd')).toHaveCount(2)
  const receipt = (await dialog.locator('dd').nth(0).textContent())?.trim()
  const code = (await dialog.locator('dd').nth(1).textContent())?.trim()
  expect(receipt).toMatch(/^[a-f0-9-]{36}$/)
  expect(code).toMatch(/^[A-Za-z0-9_-]{43}$/)
  await dialog.getByRole('checkbox').check()
  await dialog.getByRole('textbox', { name: '現在のパスワード' }).fill(password)
  await dialog.getByRole('button', { name: '家族全員の利用を終了' }).click()
  await expect(page).toHaveURL(/\/lifecycle-status$/)
  await expect(page.getByText('確定済み', { exact: true })).toBeVisible()
  expect((await page.request.get('/api/v1/auth/me', { headers: family.headers })).status()).toBe(
    401
  )
  const status = await page.request.post('/api/v1/lifecycle-operation-status', {
    data: { receipt_id: receipt, confirmation_code: code },
  })
  expect(status.status()).toBe(200)
  expect((await status.json()).data.state).toBe('committed')
  expect(
    await page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }))
  ).not.toContain(code)
})

test('実APIで無効メンバーを削除し、代表者の継続利用とログインID再利用を検証する', async ({
  page,
  context,
}) => {
  const family = await registerFamily(page, context)
  const username = `child${Date.now()}${Math.floor(Math.random() * 10000)}`
  const memberBody = {
    username,
    display_name: '削除検証メンバー',
    role: 'member',
    password,
    password_confirmation: password,
  }
  const created = await page.request.post(`${family.path}/members`, {
    headers: family.headers,
    data: memberBody,
  })
  expect(created.status()).toBe(201)
  const member = (await created.json()).data
  expect(
    (
      await page.request.put(`${family.path}/members/${member.membership_id}/status`, {
        headers: family.headers,
        data: { status: 'inactive' },
      })
    ).ok()
  ).toBeTruthy()
  await page.goto('/settings#family-members')
  await page.getByRole('button', { name: '無効化済みメンバー', exact: true }).click()
  await page.getByRole('button', { name: '削除検証メンバーを復元', exact: true }).click()
  await expect(page.getByRole('dialog').getByRole('button', { name: /削除/ })).toHaveCount(0)
  await page.getByRole('dialog').getByRole('button', { name: '閉じる', exact: true }).click()
  await page.getByRole('button', { name: '削除検証メンバーを削除', exact: true }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog.getByRole('heading', { name: 'メンバーの削除' })).toBeVisible()
  const firstReceipt = (await dialog.locator('dd').nth(0).textContent())?.trim()
  const firstCode = (await dialog.locator('dd').nth(1).textContent())?.trim()
  await dialog.getByRole('button', { name: '閉じる', exact: true }).click()
  await expect(dialog).toHaveCount(0)
  const cancelled = await page.request.post('/api/v1/lifecycle-operation-status', {
    data: { receipt_id: firstReceipt, confirmation_code: firstCode },
  })
  expect(cancelled.status()).toBe(404)
  await page.getByRole('button', { name: '削除検証メンバーを削除', exact: true }).click()
  await expect(dialog.locator('dd').nth(0)).not.toHaveText(firstReceipt ?? '')
  await dialog.getByRole('checkbox').check()
  await dialog.getByRole('textbox', { name: '現在のパスワード' }).fill('wrong-password')
  await dialog.getByRole('button', { name: 'メンバーを削除', exact: true }).click()
  await expect(dialog.getByRole('alert')).not.toBeEmpty()
  await dialog.getByRole('button', { name: '閉じる', exact: true }).click()
  await expect(dialog).toHaveCount(0)
  await page.getByRole('button', { name: '削除検証メンバーを削除', exact: true }).click()
  await expect(dialog.getByRole('checkbox')).not.toBeChecked()
  const beforeReload = (await dialog.locator('dd').nth(0).textContent())?.trim()
  await page.reload()
  await page.getByRole('button', { name: '無効化済みメンバー', exact: true }).click()
  await page.getByRole('button', { name: '削除検証メンバーを削除', exact: true }).click()
  await expect(dialog.locator('dd').nth(0)).not.toHaveText(beforeReload ?? '')
  await dialog.getByRole('checkbox').check()
  await dialog.getByRole('textbox', { name: '現在のパスワード' }).fill(password)
  await dialog.getByRole('button', { name: 'メンバーを削除', exact: true }).click()
  await expect(dialog).toHaveCount(0)
  await expect(page.getByRole('status').filter({ hasText: 'メンバーを削除しました' })).toBeVisible()
  expect((await page.request.get('/api/v1/auth/me', { headers: family.headers })).status()).toBe(
    200
  )
  const recreated = await page.request.post(`${family.path}/members`, {
    headers: family.headers,
    data: memberBody,
  })
  expect(recreated.status()).toBe(201)
  const replacement = (await recreated.json()).data
  expect(replacement.id).not.toBe(member.id)

  // The general suite resets the array cache between requests; the dedicated CI step persists it.
  if (process.env.FAMIE_E2E_PERSIST_RATE_LIMITS !== '1') return

  // The earlier failed commit and successful deletion used two of the five attempts.
  const preparation = await page.request.post(`${family.path}/lifecycle-operations`, {
    headers: family.headers,
    data: { kind: 'group_withdraw' },
  })
  expect(preparation.status()).toBe(201)
  const pending = (await preparation.json()).data
  expect(pending.commit_retry_after).toBe(0)
  for (let attempt = 0; attempt < 3; attempt++) {
    const failed = await page.request.post(
      `${family.path}/lifecycle-operations/${pending.receipt_id}/commit`,
      {
        headers: family.headers,
        data: {
          current_password: 'wrong-password',
          confirmation_code: pending.confirmation_code,
          confirmed: true,
        },
      }
    )
    expect(failed.status()).toBe(422)
  }
  expect(
    (
      await page.request.post(`${family.path}/lifecycle-operations/${pending.receipt_id}/cancel`, {
        headers: family.headers,
      })
    ).status()
  ).toBe(200)
  await page.getByText('家族設定', { exact: true }).click()
  await page.getByRole('button', { name: '家族の利用を終了（退会）' }).click()
  await expect(dialog.getByRole('alert')).toContainText('秒後に再試行できます。')
  await expect(dialog.getByRole('alert')).toBeInViewport()
  await dialog.getByRole('checkbox').check()
  await dialog.getByRole('textbox', { name: '現在のパスワード' }).fill(password)
  await expect(dialog.getByRole('button', { name: '家族全員の利用を終了' })).toBeDisabled()
  await dialog.getByRole('button', { name: '閉じる', exact: true }).click()
  expect(
    (
      await page.request.put(`${family.path}/members/${replacement.id}/status`, {
        headers: family.headers,
        data: { status: 'inactive' },
      })
    ).status()
  ).toBe(200)
  await page.reload()
  await page.getByRole('button', { name: '無効化済みメンバー', exact: true }).click()
  await page.getByRole('button', { name: '削除検証メンバーを削除', exact: true }).click()
  await expect(dialog.getByRole('alert')).toContainText('秒後に再試行できます。')
  await expect(dialog.getByRole('alert')).toBeInViewport()
  await dialog.getByRole('checkbox').check()
  await dialog.getByRole('textbox', { name: '現在のパスワード' }).fill(password)
  await expect(dialog.getByRole('button', { name: 'メンバーを削除', exact: true })).toBeDisabled()
})
