import { expect, test } from '@playwright/test'

test('有効な家族では家族名の下に使用容量を表示し、無効化すると表示しない', async ({
  page,
  context,
}) => {
  const owner = { id: 1, username: 'owner', display_name: '自分' }
  const group = {
    id: 1,
    name: '家族',
    type: 'family',
    images: { enabled: true, used_bytes: 3_200_000_000, quota_bytes: 5_000_000_000 },
  }
  await context.addCookies([{ name: 'auth_token', value: 'storage-test', url: 'http://127.0.0.1' }])
  await page.route('**/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/auth/me'))
      return route.fulfill({
        json: { user: owner, membership: { id: 1, role: 'admin', status: 'active', group } },
      })
    if (path.endsWith('/groups/1')) return route.fulfill({ json: { data: group } })
    if (path.endsWith('/members')) return route.fulfill({ json: [owner] })
    if (path.endsWith('/categories')) return route.fulfill({ json: [] })
    return route.fulfill({ json: {} })
  })

  await page.goto('/settings')
  const family = page.locator('details').filter({ hasText: '家族設定' })
  await family.locator('summary').click()
  await expect(family.getByLabel('家族名')).toHaveValue('家族')
  const progress = family.getByRole('progressbar', { name: '画像の保存容量' })
  await expect(progress).toHaveAttribute('aria-valuenow', '64')
  await expect(progress).toHaveAttribute('aria-valuetext', '3.2 GB 使用中 / 5 GB')
  await expect(progress.locator('div')).toHaveAttribute('style', /width: 64%/)
  await expect(family.getByText('3.2 GB 使用中')).toBeVisible()
  await expect(family.getByText('残り 1.8 GB / 5 GB')).toBeVisible()
  const familyNameBox = await family.getByLabel('家族名').boundingBox()
  const progressBox = await progress.boundingBox()
  expect(familyNameBox && progressBox && familyNameBox.y < progressBox.y).toBeTruthy()

  group.images.enabled = false
  await page.reload()
  await family.locator('summary').click()
  await expect(family.getByRole('progressbar', { name: '画像の保存容量' })).toHaveCount(0)
  await expect(family.getByText('画像添付: 有効')).toHaveCount(0)
})
