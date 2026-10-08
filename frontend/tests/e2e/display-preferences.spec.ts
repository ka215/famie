import { expect, test } from '@playwright/test'
import { timelineSample } from '../support/timeline-sample'

test('敬称は未保存の名前と独立して保存し、失敗時は保存値を維持する', async ({ page, context }) => {
  const sample = await timelineSample(page, context)
  sample.user.show_name_suffix = true
  await page.goto('/settings')
  const toggle = page.getByRole('switch', { name: 'ヘッダの名前に『さん』を表示する' })
  await expect(toggle).toHaveAttribute('aria-checked', 'true')
  await page.getByLabel('自分の表示名').fill('まだ保存していない名前')
  await toggle.click()
  await expect(toggle).toHaveAttribute('aria-checked', 'false')
  await expect(page.locator('header')).toContainText('おとうさん')
  await expect(page.locator('header')).not.toContainText('おとうさん さん')
  await expect(page.getByLabel('自分の表示名')).toHaveValue('まだ保存していない名前')
  expect(sample.user.display_name).toBe('おとうさん')
  await page.reload()
  await expect(toggle).toHaveAttribute('aria-checked', 'false')
  sample.onProfile = (route) => route.fulfill({ status: 500, json: {} })
  await toggle.click()
  await expect(
    page.getByText('敬称の表示設定を保存できませんでした。再試行してください。')
  ).toBeVisible()
  await expect(toggle).toHaveAttribute('aria-checked', 'false')
})

for (const role of ['admin', 'member']) {
  test(`${role}: カテゴリの編集案内は管理者だけに表示する`, async ({ page, context }) => {
    await timelineSample(page, context, role)
    await page.goto('/settings')
    await page.getByText('カテゴリ', { exact: true }).click()
    const help = page.getByText('カテゴリのバッジを押すと、名前や色を編集できます。')
    if (role === 'admin') await expect(help).toBeVisible()
    else await expect(help).toHaveCount(0)
  })
}
