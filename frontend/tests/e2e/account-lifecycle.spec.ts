import { type BrowserContext, expect, type Page, test } from '@playwright/test'

test('代表管理者が受付情報を保存して家族退会を確定し、公開画面で状況を確認できる', async ({
  page,
  context,
}) => {
  const receipt = '018f2c3d-4567-7890-abcd-ef0123456789'
  const code = 'A'.repeat(43)
  const group = { id: 1, name: '家族', type: 'family', images: { enabled: true } }
  const user = { id: 1, username: 'owner', display_name: '代表者' }
  const membership = {
    id: 1,
    role: 'admin',
    status: 'active',
    is_representative: true,
    group,
  }
  let commitBody: unknown
  let statusBody: unknown
  await context.addCookies([
    { name: 'auth_token', value: 'lifecycle-test', url: 'http://127.0.0.1' },
  ])
  await page.route('**/v1/**', (route) => {
    const request = route.request()
    const path = new URL(request.url()).pathname
    if (path.endsWith('/status')) return route.fulfill({ json: { status: 'ok' } })
    if (path.endsWith('/auth/me')) return route.fulfill({ json: { user, membership } })
    if (path.endsWith('/groups/1/lifecycle-operations') && request.method() === 'POST') {
      return route.fulfill({
        status: 201,
        json: {
          data: {
            receipt_id: receipt,
            confirmation_code: code,
            kind: 'group_withdraw',
            prepared_expires_at: '2030-11-03T13:00:00Z',
            impact: { family: true, members: 3, posts: 'delete', images: 'delete' },
          },
        },
      })
    }
    if (path.endsWith(`/groups/1/lifecycle-operations/${receipt}/commit`)) {
      commitBody = request.postDataJSON()
      return route.fulfill({
        status: 202,
        json: {
          data: {
            receipt_id: receipt,
            kind: 'group_withdraw',
            state: 'committed',
            cleanup_state: 'processing',
            committed_at: '2026-11-03T12:50:00Z',
            retention_until: '2026-12-03T12:50:00Z',
          },
        },
      })
    }
    if (path.endsWith('/lifecycle-operation-status')) {
      statusBody = request.postDataJSON()
      return route.fulfill({
        json: {
          data: {
            receipt_id: receipt,
            kind: 'group_withdraw',
            state: 'committed',
            cleanup_state: 'processing',
            committed_at: '2026-11-03T12:50:00Z',
            retention_until: '2026-12-03T12:50:00Z',
          },
        },
      })
    }
    if (path.endsWith('/groups/1')) return route.fulfill({ json: { data: group } })
    return route.fulfill({ json: [] })
  })

  await page.goto('/settings')
  await page.getByText('家族設定', { exact: true }).click()
  await page.getByRole('button', { name: '家族の利用を終了（退会）' }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog.getByText(receipt)).toBeVisible()
  await expect(dialog.getByText(code)).toBeVisible()
  const submit = dialog.getByRole('button', { name: '家族全員の利用を終了' })
  await expect(submit).toBeDisabled()
  const downloaded = page.waitForEvent('download')
  await dialog.getByRole('button', { name: '受付情報をテキストで保存' }).click()
  expect((await downloaded).suggestedFilename()).toBe(`famie-${receipt}.txt`)
  await dialog.getByRole('checkbox').check()
  await expect(submit).toBeDisabled()
  await dialog.getByRole('textbox', { name: '現在のパスワード' }).fill('password')
  await dialog.getByRole('button', { name: '家族全員の利用を終了' }).click()

  await expect(page).toHaveURL(/\/lifecycle-status$/)
  await expect(page.getByText('確定済み')).toBeVisible()
  expect(commitBody).toEqual({
    current_password: 'password',
    confirmation_code: code,
    confirmed: true,
  })
  expect(statusBody).toEqual({ receipt_id: receipt, confirmation_code: code })
  const browserStorage = await page.evaluate(() =>
    JSON.stringify({ ...localStorage, ...sessionStorage })
  )
  expect(browserStorage).not.toContain(receipt)
  expect(browserStorage).not.toContain(code)
  await expect(page.locator('meta[name="referrer"]')).toHaveAttribute('content', 'no-referrer')
  await page.getByRole('link', { name: 'ログインへ戻る', exact: true }).click()
  await expect(page).toHaveURL(/\/login$/)
  await page.getByRole('link', { name: '退会・削除状況の確認', exact: true }).click()
  await expect(page).toHaveURL(/\/lifecycle-status$/)
  await expect(page.getByText('確定済み', { exact: true })).toBeVisible()
  await page.reload()
  await page.getByRole('textbox', { name: '受付番号', exact: true }).fill(receipt)
  await page.getByRole('textbox', { name: '問い合わせ用確認コード', exact: true }).fill(code)
  await page.getByRole('button', { name: '状況を確認', exact: true }).click()
  await expect(page.getByText('確定済み', { exact: true })).toBeVisible()
})

const fixtureReceipt = '018f2c3d-4567-7890-abcd-ef0123456789'
const fixtureCode = 'A'.repeat(43)

async function mockSettings(page: Page, context: BrowserContext, representative = true) {
  await context.addCookies([
    { name: 'auth_token', value: 'lifecycle-fixture', url: 'https://127.0.0.1' },
  ])
  const group = {
    id: 1,
    name: '検証家族',
    type: 'family',
    images: {
      enabled: true,
      used_bytes: 0,
      quota_bytes: 10485760,
      max_files_per_activity: 1,
      max_input_bytes: 31457280,
    },
  }
  await page.route('**/v1/**', (route) => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/status')) return route.fulfill({ json: { status: 'ok' } })
    if (path.endsWith('/auth/me'))
      return route.fulfill({
        json: {
          user: { id: 1, username: 'owner', display_name: '検証者', ui_locale: 'ja' },
          membership: {
            id: 1,
            role: 'admin',
            status: 'active',
            is_representative: representative,
            group,
          },
        },
      })
    if (path.endsWith('/groups/1')) return route.fulfill({ json: { data: group } })
    if (path.endsWith('/groups/1/lifecycle-operations'))
      return route.fulfill({
        status: 201,
        json: {
          data: {
            receipt_id: fixtureReceipt,
            confirmation_code: fixtureCode,
            kind: 'group_withdraw',
            prepared_expires_at: '2030-11-03T13:00:00Z',
            impact: { family: true, members: 3, posts: 'delete', images: 'delete' },
          },
        },
      })
    return route.fulfill({ json: [] })
  })
}

test('代表者以外の親には家族退会ボタンを表示しない', async ({ page, context }) => {
  await mockSettings(page, context, false)
  await page.goto('/settings')
  await page.getByText('家族設定', { exact: true }).click()
  await expect(page.getByRole('button', { name: '家族の利用を終了（退会）' })).toHaveCount(0)
})

test('パスワード不一致では確認画面を保持し、確定情報を保存しない', async ({ page, context }) => {
  await mockSettings(page, context)
  await page.route('**/lifecycle-operations/*/commit', (route) =>
    route.fulfill({
      status: 422,
      json: { errors: { current_password: ['検証用パスワード不一致'] } },
    })
  )
  await page.goto('/settings')
  await page.getByText('家族設定', { exact: true }).click()
  await page.getByRole('button', { name: '家族の利用を終了（退会）' }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByRole('checkbox').check()
  await dialog.getByRole('textbox', { name: '現在のパスワード' }).fill('wrong-password')
  await dialog.getByRole('button', { name: '家族全員の利用を終了' }).click()
  await expect(dialog.getByRole('alert')).toHaveText('検証用パスワード不一致')
  await expect(page).toHaveURL(/\/settings$/)
  expect(
    await page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }))
  ).not.toContain(fixtureCode)
})

test('未ログインで英語の状況確認を開き、期限切れを確認できる', async ({ page, context }) => {
  await context.addInitScript(() => localStorage.setItem('famie.uiLocale.guest', 'en'))
  await page.route('**/v1/**', (route) => {
    if (new URL(route.request().url()).pathname.endsWith('/status'))
      return route.fulfill({ json: { status: 'ok' } })
    return route.fulfill({ status: 404, json: { message: 'Receipt expired for test' } })
  })
  await page.goto('/lifecycle-status')
  await expect(
    page.getByRole('heading', { name: 'Check withdrawal or deletion status' })
  ).toBeVisible()
  await page.getByRole('textbox', { name: 'Receipt number' }).fill(fixtureReceipt)
  await page.getByRole('textbox', { name: 'Inquiry confirmation code' }).fill(fixtureCode)
  await page.getByRole('button', { name: 'Check status' }).click()
  await expect(page.getByRole('alert')).toHaveText('Receipt expired for test')
  await expect(page).toHaveURL(/\/lifecycle-status$/)
})

test('無効化済みメンバーを削除して一覧を更新し、代表者のログインを維持する', async ({
  page,
  context,
}) => {
  await mockSettings(page, context)
  let deleted = false
  let commitAttempts = 0
  let preparedBody: unknown
  const member = {
    id: 2,
    membership_id: 2,
    username: 'inactive',
    display_name: '削除対象',
    role: 'member',
    is_representative: false,
  }
  await page.route('**/groups/1/security-alerts*', (route) =>
    route.fulfill({
      json: {
        data: [],
        meta: { unread_total: 0, unread_by_member: [], limit: 20, has_more: false },
      },
    })
  )
  await page.route('**/members/inactive', (route) =>
    route.fulfill({ json: deleted ? [] : [member] })
  )
  await page.route('**/groups/1/lifecycle-operations', (route) => {
    preparedBody = route.request().postDataJSON()
    return route.fulfill({
      status: 201,
      json: {
        data: {
          receipt_id: fixtureReceipt,
          confirmation_code: fixtureCode,
          kind: 'member_delete',
          prepared_expires_at: '2030-11-03T13:00:00Z',
          impact: { family: false, members: 1, posts: 'delete', images: 'delete' },
        },
      },
    })
  })
  await page.route('**/lifecycle-operations/*/commit', (route) => {
    commitAttempts++
    if (commitAttempts === 1)
      return route.fulfill({
        status: 422,
        json: { errors: { current_password: ['現在のパスワードが正しくありません。'] } },
      })
    deleted = true
    return route.fulfill({
      status: 202,
      json: {
        data: {
          receipt_id: fixtureReceipt,
          kind: 'member_delete',
          state: 'committed',
          cleanup_state: 'processing',
          committed_at: '2030-11-03T12:50:00Z',
          retention_until: '2030-12-03T12:50:00Z',
        },
      },
    })
  })
  await page.goto('/settings#family-members')
  const inactiveToggle = page.getByRole('button', { name: '無効化済みメンバー', exact: true })
  await inactiveToggle.click()
  await expect(inactiveToggle).toHaveAttribute('aria-expanded', 'true')
  await inactiveToggle.click()
  await expect(page.getByRole('button', { name: '削除対象を復元', exact: true })).toBeHidden()
  await inactiveToggle.click()
  await page.getByRole('button', { name: '削除対象を復元', exact: true }).click()
  await expect(page.getByRole('dialog').getByRole('button', { name: /削除/ })).toHaveCount(0)
  await page.getByRole('dialog').getByRole('button', { name: '閉じる', exact: true }).click()
  await page.getByRole('button', { name: '削除対象を削除', exact: true }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog.getByRole('heading', { name: 'メンバーの削除' })).toBeVisible()
  await dialog.getByRole('checkbox').check()
  await dialog.getByRole('textbox', { name: '現在のパスワード' }).fill('wrong-password')
  const submit = dialog.getByRole('button', { name: 'メンバーを削除', exact: true })
  await submit.click()
  const commitAlert = dialog.getByRole('alert')
  await expect(commitAlert).toHaveText('現在のパスワードが正しくありません。')
  await expect(commitAlert).toBeInViewport()
  const errorBox = await commitAlert.boundingBox()
  const passwordBox = await dialog.getByRole('textbox', { name: '現在のパスワード' }).boundingBox()
  const buttonBox = await submit.boundingBox()
  if (!errorBox || !passwordBox || !buttonBox)
    throw new Error('Confirmation controls are not rendered')
  expect(errorBox.y).toBeGreaterThan(passwordBox.y + passwordBox.height)
  expect(errorBox.y + errorBox.height).toBeLessThan(buttonBox.y)
  await dialog.getByRole('textbox', { name: '現在のパスワード' }).fill('password')
  await dialog.getByRole('button', { name: 'メンバーを削除', exact: true }).click()
  await expect(dialog).toHaveCount(0)
  await expect(page.getByRole('status').filter({ hasText: 'メンバーを削除しました' })).toBeVisible()
  await expect(page.getByRole('button', { name: '削除対象を復元', exact: true })).toHaveCount(0)
  await expect(page).toHaveURL(/\/settings#family-members$/)
  expect(preparedBody).toEqual({ kind: 'member_delete', membership_id: 2 })
  expect((await context.cookies()).find((cookie) => cookie.name === 'auth_token')?.value).toBe(
    'lifecycle-fixture'
  )
})

test('無効メンバーがいなければ空の案内だけを表示する', async ({ page, context }) => {
  await mockSettings(page, context)
  await page.goto('/settings#family-members')
  await expect(page.getByText('無効化済みメンバーはいません。', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: '無効化済みメンバー', exact: true })).toHaveCount(0)
})

test('代表者以外の親には無効メンバーの復元だけを表示する', async ({ page, context }) => {
  await mockSettings(page, context, false)
  await page.route('**/members/inactive', (route) =>
    route.fulfill({
      json: [
        {
          id: 2,
          membership_id: 2,
          username: 'inactive',
          display_name: '無効メンバー',
          role: 'member',
          is_representative: false,
        },
      ],
    })
  )
  await page.goto('/settings#family-members')
  await page.getByRole('button', { name: '無効化済みメンバー', exact: true }).click()
  await expect(page.getByRole('button', { name: '無効メンバーを復元', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: '無効メンバーを削除', exact: true })).toHaveCount(0)
})

test('パスワード誤入力後に閉じると確認を取り消し、すぐに開き直せる', async ({ page, context }) => {
  await mockSettings(page, context)
  let cancelled = false
  let preparedCount = 0
  await page.route('**/groups/1/lifecycle-operations', (route) => {
    preparedCount++
    return route.fulfill({
      status: 201,
      json: {
        data: {
          receipt_id: fixtureReceipt,
          confirmation_code: fixtureCode,
          kind: 'group_withdraw',
          prepared_expires_at: '2030-11-03T13:00:00Z',
          impact: { family: true, members: 3, posts: 'delete', images: 'delete' },
        },
      },
    })
  })
  await page.route('**/lifecycle-operations/*/commit', (route) =>
    route.fulfill({ status: 422, json: { message: '検証用パスワード不一致' } })
  )
  await page.route('**/lifecycle-operations/*/cancel', (route) => {
    cancelled = true
    return route.fulfill({ json: { data: { state: 'aborted' } } })
  })
  await page.goto('/settings')
  await page.getByText('家族設定', { exact: true }).click()
  await page.getByRole('button', { name: '家族の利用を終了（退会）' }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog.getByText(/確認期限：/)).toBeVisible()
  await dialog.getByRole('checkbox').check()
  await dialog.getByRole('textbox', { name: '現在のパスワード' }).fill('wrong')
  await dialog.getByRole('button', { name: '家族全員の利用を終了' }).click()
  await expect(dialog.getByRole('alert')).toHaveText('検証用パスワード不一致')
  await dialog.getByRole('button', { name: '閉じる', exact: true }).click()
  await expect(dialog).toHaveCount(0)
  expect(cancelled).toBe(true)
  await page.getByRole('button', { name: '家族の利用を終了（退会）' }).click()
  await expect(dialog.getByText(fixtureCode)).toBeVisible()
  await expect(dialog.getByRole('textbox', { name: '現在のパスワード' })).toHaveValue('')
  await expect(dialog.getByRole('checkbox')).not.toBeChecked()
  expect(preparedCount).toBe(2)
})
