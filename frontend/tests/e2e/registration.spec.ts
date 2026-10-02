import { expect, test } from '@playwright/test'

test('新しい親が家族を登録し、家族名を変更できる', async ({ page }) => {
  const suffix = Date.now().toString()
  await page.goto('/login')
  await page.getByRole('link', { name: '新しい家族を登録' }).click()
  await page.getByLabel('家族名').fill('新規家族')
  await page.getByLabel('ログインID').fill('parent1')
  await expect(
    page.getByText('このログインIDは既に使用されています。', { exact: true })
  ).toBeVisible()
  await page.getByLabel('ログインID').fill(`new-parent-${suffix}`)
  await expect(page.getByText('このログインIDは利用できます。', { exact: true })).toBeVisible()
  await page.getByLabel('親アカウントの表示名').fill('新しい親')
  await page.getByLabel('パスワード', { exact: true }).fill('registration-password')
  await page.getByLabel('パスワード（確認）', { exact: true }).fill('registration-password')
  await page.getByRole('button', { name: '家族を登録する' }).click()

  await expect(page.getByRole('button', { name: 'アクティビティを記録する' })).toBeVisible()
  await page.getByRole('link', { name: '設定', exact: true }).click()
  await page.locator('summary').filter({ hasText: '家族設定' }).click()
  await expect(page.getByLabel('家族名')).toHaveValue('新規家族')
  await page.getByLabel('家族名').fill('変更後の家族名')
  await page
    .getByLabel('家族名')
    .locator('..')
    .getByRole('button', { name: '変更', exact: true })
    .click()
  await expect(page.getByText('家族名を変更しました。', { exact: true })).toBeVisible()
  await expect(page.getByLabel('家族名')).toHaveValue('変更後の家族名')
})
