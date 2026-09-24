import { expect, test } from '@playwright/test'

for (const account of ['parent1', 'child1']) {
  test(`${account}: 設定カードの初期状態・独立開閉・入力保持・キーボード操作`, async ({ page }) => {
    await page.goto('/login')
    await page.getByPlaceholder('例: parent1').fill(account)
    await page.getByLabel('パスワード', { exact: true }).fill('Famie-E2E-only-123!')
    await page.getByRole('button', { name: 'ログイン', exact: true }).click()
    await page.getByRole('link', { name: '設定', exact: true }).click()
    const card = (title: string) =>
      page
        .locator('details')
        .filter({ has: page.getByRole('heading', { name: title, exact: true }) })
    const loginCard = card('ログイン情報')
    const passwordCard = card('パスワードの変更')
    await expect(loginCard).toHaveJSProperty('open', true)
    for (const title of ['家族メンバー', 'カテゴリ', 'パスワードの変更']) {
      await expect(card(title)).toHaveJSProperty('open', false)
    }
    if (account === 'parent1') {
      await expect(card('新しい家族を追加')).toHaveJSProperty('open', false)
    } else {
      await expect(page.getByText('新しい家族を追加', { exact: true })).toHaveCount(0)
    }
    await page.getByLabel('自分の表示名').fill('入力中の名前')
    await passwordCard.locator('summary').focus()
    await page.keyboard.press('Enter')
    await expect(passwordCard).toHaveJSProperty('open', true)
    await expect(loginCard).toHaveJSProperty('open', true)
    await page.getByLabel('現在のパスワード', { exact: true }).fill('まだ保存しない値')
    await passwordCard.locator('summary').focus()
    await page.keyboard.press('Space')
    await expect(passwordCard).toHaveJSProperty('open', false)
    await expect(page.getByLabel('現在のパスワード', { exact: true })).toBeHidden()
    await passwordCard.locator('summary').press('Enter')
    await expect(page.getByLabel('現在のパスワード', { exact: true })).toHaveValue(
      'まだ保存しない値'
    )
    await loginCard.locator('summary').click()
    await loginCard.locator('summary').click()
    await expect(page.getByLabel('自分の表示名')).toHaveValue('入力中の名前')
    await page.getByRole('link', { name: 'タイムライン', exact: true }).click()
    await page.getByRole('link', { name: '設定', exact: true }).click()
    await expect(loginCard).toHaveJSProperty('open', true)
    await expect(passwordCard).toHaveJSProperty('open', false)
  })
}
