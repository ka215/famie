import { expect, test } from '@playwright/test'

for (const account of ['parent1', 'child1']) {
  test(`${account}: パスワードの切り替えは値を保持し、各欄で独立する`, async ({ page }) => {
    await page.goto('/login')
    const password = page.getByLabel('パスワード', { exact: true })
    await expect(password).toHaveAttribute('type', 'password')
    await page.getByPlaceholder('例: parent1').fill(account)
    await password.fill('Famie-E2E-only-123!')
    await page.getByRole('button', { name: 'パスワードを表示する', exact: true }).click()
    await expect(password).toHaveAttribute('type', 'text')
    await expect(password).toHaveValue('Famie-E2E-only-123!')
    await expect(page).toHaveURL(/\/login\/?$/)
    await page.getByRole('button', { name: 'パスワードを隠す', exact: true }).press('Enter')
    await expect(password).toHaveAttribute('type', 'password')
    await page.getByRole('button', { name: 'ログイン', exact: true }).click()
    await page.getByRole('link', { name: '設定', exact: true }).click()

    const labels = ['現在のパスワード', '新しいパスワード', '新しいパスワード（確認）']
    if (account === 'parent1') labels.push('初期パスワード', '初期パスワード（確認）')
    for (const label of labels) {
      const input = page.getByLabel(new RegExp(`^${label}\\*?$`))
      await expect(input).toHaveAttribute('type', 'password')
      await input.fill('temporary-test-value')
      await page.getByRole('button', { name: `${label}を表示する`, exact: true }).click()
      await expect(input).toHaveAttribute('type', 'text')
      await expect(input).toHaveValue('temporary-test-value')
      for (const other of labels.filter((item) => item !== label)) {
        await expect(page.getByLabel(new RegExp(`^${other}\\*?$`))).toHaveAttribute(
          'type',
          'password'
        )
      }
      await page.getByRole('button', { name: `${label}を隠す`, exact: true }).click()
      await expect(input).toHaveAttribute('type', 'password')
    }
    await expect(page.getByText('パスワードを変更しました。', { exact: true })).toHaveCount(0)
  })
}
