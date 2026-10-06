import { expect, test } from '@playwright/test'

const token = 'a'.repeat(64)

for (const account of ['parent1', 'child1']) {
  test(`${account}: メール申請・状態再取得・再送制限・取消`, async ({ page }) => {
    await page.goto('/login')
    await page.getByPlaceholder('例: parent1').fill(account)
    await page.getByLabel('パスワード', { exact: true }).fill('Famie-E2E-only-123!')
    await page.getByRole('button', { name: 'ログイン', exact: true }).click()
    await page.getByRole('link', { name: '設定', exact: true }).click()
    let pending: string | null = null
    await page.route('**/auth/me', async (route) => {
      const response = await route.fetch()
      const data = await response.json()
      data.user.pending_email = pending
      await route.fulfill({ response, json: data })
    })
    await page.route('**/auth/me/email', async (route) => {
      if (route.request().method() === 'DELETE') {
        pending = null
        await route.fulfill({ json: { message: '確認待ちの申請を取り消しました。' } })
      } else {
        expect(route.request().postDataJSON()).toEqual({
          email: 'new@example.test',
          current_password: 'password',
        })
        pending = 'new@example.test'
        await route.fulfill({ json: { message: '確認メールを送信しました。' } })
      }
    })
    await page.route('**/auth/me/email/resend', (route) =>
      route.fulfill({
        status: 429,
        json: { message: '送信間隔が短すぎます。60秒待ってから再試行してください。' },
      })
    )
    await page.locator('summary').filter({ hasText: 'メールアドレス' }).click()
    await page.getByLabel('追加・変更するメールアドレス').fill('new@example.test')
    await page.getByLabel('メール変更確認用パスワード', { exact: true }).fill('password')
    await page.getByRole('button', { name: '確認メールを送信', exact: true }).click()
    await expect(page.getByRole('status').filter({ hasText: /.+/ })).toContainText(
      '確認メールを送信しました。'
    )
    await expect(page.getByText('確認待ち：new@example.test')).toBeVisible()
    await expect(page.getByLabel('メール変更確認用パスワード', { exact: true })).toHaveValue('')
    await page.getByRole('button', { name: '確認メールを再送' }).click()
    await expect(page.getByRole('alert').filter({ hasText: '60秒待って' })).toBeVisible()
    await page.getByRole('button', { name: '申請を取り消す' }).click()
    await expect(page.getByRole('status').filter({ hasText: /.+/ })).toContainText('取り消しました')
    await expect(page.getByText('確認待ち：new@example.test')).toHaveCount(0)
  })
}

test('ログインから再設定申請へ移動し受付結果を表示する', async ({ page }) => {
  await page.goto('/login')
  await page.getByRole('link', { name: 'パスワードを忘れた方' }).click()
  await page.getByLabel('メールアドレス', { exact: true }).fill('absent@example.test')
  await page.getByRole('button', { name: '再設定メールを送信' }).click()
  await expect(page.getByRole('status').filter({ hasText: /.+/ })).toContainText(
    '再設定が可能な場合'
  )
})

test('メール確認はログイン中も表示し、ボタン操作のみで確定する', async ({ page }) => {
  await page.goto('/login')
  await page.getByPlaceholder('例: parent1').fill('parent1')
  await page.getByLabel('パスワード', { exact: true }).fill('Famie-E2E-only-123!')
  await page.getByRole('button', { name: 'ログイン', exact: true }).click()
  await expect(page).toHaveURL(/\/$/)
  let requests = 0
  await page.route('**/auth/email/verify', (route) => {
    requests++
    expect(route.request().postDataJSON()).toEqual({ id: '123', token })
    return route.fulfill({ json: { message: 'メールアドレスを確認しました。' } })
  })
  await page.goto(`/verify-email#id=123&token=${token}`)
  await expect(page.getByRole('button', { name: 'メールアドレスを確認する' })).toBeEnabled()
  await expect(page).toHaveURL(/\/verify-email$/)
  expect(requests).toBe(0)
  await page.getByRole('button', { name: 'メールアドレスを確認する' }).click()
  await expect(page.getByRole('status').filter({ hasText: /.+/ })).toHaveText(
    'メールアドレスを確認しました。'
  )
  expect(requests).toBe(1)
})

test('再設定の不一致・APIエラー・成功を表示する', async ({ page }) => {
  let requests = 0
  await page.route('**/auth/password/reset', (route) => {
    requests++
    return route.fulfill(
      requests === 1
        ? { status: 422, json: { message: 'リンクが無効です。もう一度申請してください。' } }
        : {
            json: {
              message: 'パスワードを再設定しました。新しいパスワードでログインしてください。',
            },
          }
    )
  })
  await page.goto(`/reset-password#id=123&token=${token}`)
  await page.getByLabel('新しいパスワード', { exact: true }).fill('new-password')
  await page.getByLabel('新しいパスワード（確認）', { exact: true }).fill('different-password')
  await page.getByRole('button', { name: 'パスワードを再設定', exact: true }).click()
  await expect(page.getByRole('alert')).toHaveText('パスワードが一致しません。')
  expect(requests).toBe(0)
  await page.getByLabel('新しいパスワード（確認）', { exact: true }).fill('new-password')
  await page.getByRole('button', { name: 'パスワードを再設定', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('リンクが無効')
  await page.getByRole('button', { name: 'パスワードを再設定', exact: true }).click()
  await expect(page.getByRole('status').filter({ hasText: /.+/ })).toContainText(
    '新しいパスワードでログイン'
  )
  await expect(page.getByLabel('新しいパスワード', { exact: true })).toHaveCount(0)
})

test('確認リンクの再読み込みでは秘密値を復元せず開き直しを案内する', async ({ page }) => {
  await page.goto(`/verify-email#id=123&token=${token}`)
  await expect(page.getByRole('button', { name: 'メールアドレスを確認する' })).toBeEnabled()
  await page.reload()
  await expect(page.getByRole('alert')).toContainText('メール内のリンクから開き直してください')
  await expect(page.getByRole('button', { name: 'メールアドレスを確認する' })).toBeDisabled()
})
