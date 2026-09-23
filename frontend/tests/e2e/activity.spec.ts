import { expect, test } from '@playwright/test'

test('ログインして記録を登録し、再読み込み後も一覧で確認できる', async ({ page }) => {
  await page.goto('/login')
  await page.getByPlaceholder('例: parent1').fill('parent1')
  await page.locator('input[type="password"]').fill('Famie-E2E-only-123!')
  await page.getByRole('button', { name: 'ログイン', exact: true }).click()
  await expect(page.getByRole('button', { name: 'ログアウト' })).toBeVisible()
  await page.getByRole('button', { name: 'アクティビティを記録する' }).click()
  await page.locator('form select').selectOption({ label: '勉強・宿題' })

  const date = page.getByLabel('実施日', { exact: true })
  const time = page.getByLabel('時刻（任意）', { exact: true })
  await expect(date).toBeVisible()
  const dateBox = await date.boundingBox()
  const timeBox = await time.boundingBox()
  expect(dateBox).not.toBeNull()
  expect(timeBox).not.toBeNull()
  if (!dateBox || !timeBox) throw new Error('日時欄を表示できません')
  expect(dateBox.x + dateBox.width).toBeLessThanOrEqual(timeBox.x)
  expect(timeBox.x + timeBox.width).toBeLessThanOrEqual(page.viewportSize()?.width ?? 0)

  const today = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Tokyo' }).format(new Date())
  await date.fill(today)
  await time.fill('12:34')
  const content = `E2E-${Date.now()}`
  await page.getByPlaceholder('例: 算数のドリルを2ページ進めた').fill(content)
  await page.getByRole('button', { name: '記録を保存する' }).click()
  await expect(page.getByText(content, { exact: true })).toBeVisible()
  await page.reload()
  await expect(page.getByText(content, { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'ログアウト' }).click()
  await expect(page.getByRole('button', { name: 'ログイン', exact: true })).toBeVisible()
})
