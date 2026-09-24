import { expect, test } from '@playwright/test'

for (const account of ['parent1', 'child1']) {
  test(`${account}: 表示名の検証・保存・再読み込み・APIエラー表示`, async ({ page }) => {
    await page.goto('/login')
    await page.getByPlaceholder('例: parent1').fill(account)
    await page.getByLabel('パスワード', { exact: true }).fill('Famie-E2E-only-123!')
    await page.getByRole('button', { name: 'ログイン', exact: true }).click()
    await page.getByRole('link', { name: '設定', exact: true }).click()
    const input = page.getByLabel('自分の表示名', { exact: true })
    const save = page.getByRole('button', { name: '表示名を保存する' })
    let sent = 0
    page.on('request', (request) => {
      if (request.url().endsWith('/auth/me') && request.method() === 'PATCH') sent++
    })
    for (const invalid of ['', ' \u3000 ', 'あ'.repeat(51), '😀'.repeat(51)]) {
      await input.fill(invalid)
      await save.click()
      await expect(page.locator('#display-name-error')).toHaveText(
        invalid.trim() ? '表示名は50文字以内で入力してください。' : '表示名を入力してください。'
      )
    }
    expect(sent).toBe(0)
    await input.fill('😀'.repeat(50))
    await save.click()
    await expect(page.getByText('表示名を変更しました。', { exact: true })).toBeVisible()
    await expect(page.locator('header')).toContainText('😀'.repeat(50))
    const name = `${account}の新しい名前`
    await input.fill(`　${name}  `)
    await save.click()
    await expect(page.locator('header')).toContainText(name)
    await expect(input).toHaveValue(name)
    await expect(page.getByText(name, { exact: true })).toHaveCount(2)
    await page.reload()
    await expect(input).toHaveValue(name)
    await expect(page.locator('header')).toContainText(name)
    await page.route('**/api/v1/auth/me', (route) => {
      if (route.request().method() !== 'PATCH') return route.continue()
      return route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'Validation failed',
          errors: { display_name: ['サーバー側の検証エラー'] },
        }),
      })
    })
    await input.fill('正常な入力')
    await save.click()
    await expect(page.locator('#display-name-error')).toHaveText('サーバー側の検証エラー')
    await expect(page.locator('header')).toContainText(name)
  })
}
