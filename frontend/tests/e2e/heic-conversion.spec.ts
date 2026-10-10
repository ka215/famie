import { expect, test } from '@playwright/test'

// Source: https://github.com/strukturag/libheif/blob/master/fuzzing/data/corpus/colors-no-alpha.heic
const heicSample = Buffer.from(
  'AAAAGGZ0eXBoZWljAAAAAG1pZjFoZWljAAABLm1ldGEAAAAAAAAAIWhkbHIAAAAAAAAAAHBpY3QAAAAAAAAAAAAAAAAAAAAADnBpdG0AAAAAAAEAAAAiaWxvYwAAAABEQAABAAEAAAAAAU4AAQAAAAAAAAClAAAAI2lpbmYAAAAAAAEAAAAVaW5mZQIAAAAAAQAAaHZjMQAAAACuaXBycAAAAJFpcGNvAAAAdWh2Y0MBA3AAAAAAAAAAAAAe8AD8/fj4AAAPAyAAAQAYQAEMAf//A3AAAAMAkAAAAwAAAwAeugJAIQABAChCAQEDcAAAAwCQAAADAAADAB6gIIEFlupJKa5sCAAAAwAIAAADAAhAIgABAAdEAcFysCJAAAAAFGlzcGUAAAAAAAAAQAAAAEAAAAAVaXBtYQAAAAAAAAABAAECgQIAAACtbWRhdAAAAKEmAa8TgIGSEXXAGM2sfMMD8HKXsBNBYjkEW6//QKl1HfLCc/SN/bWOG2ARaa8rk4JsxRuKJFz/vIlnrSBv0Pk7pYMv503LniUfVt0RGOMyTBZVcbnDhlXs0nsTVObq7679Fh7MfXPARYndCrwpKWSNTQcCjNVYWPVOenDxU81lLBnE070xnN107IoLiTNywdiNWzedf/q6zzV3iwZflrO94A==',
  'base64'
)

test('HEICをブラウザーでPNGへ変換しプレビューできる', async ({ page, context }) => {
  const owner = { id: 1, username: 'owner', display_name: '自分' }
  const group = {
    id: 1,
    name: '家族',
    type: 'family',
    images: { enabled: true, used_bytes: 0, quota_bytes: 1000000000 },
  }
  await context.addCookies([{ name: 'auth_token', value: 'smoke', url: 'http://127.0.0.1' }])
  await page.route('**/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/auth/me'))
      return route.fulfill({
        json: { user: owner, membership: { id: 1, role: 'admin', status: 'active', group } },
      })
    if (path.endsWith('/groups/1')) return route.fulfill({ json: { data: group } })
    if (path.endsWith('/categories'))
      return route.fulfill({ json: [{ id: 1, name: '家事', color_code: '#10B981' }] })
    if (path.endsWith('/members')) return route.fulfill({ json: [owner] })
    return route.fulfill({ json: { data: [], current_page: 1, last_page: 1, total: 0 } })
  })
  await page.goto('/')
  await page.getByRole('button', { name: 'アクティビティを記録する' }).click()
  await page
    .getByLabel('画像（任意・1枚）')
    .setInputFiles({ name: 'sample.heic', mimeType: 'image/heic', buffer: heicSample })
  await expect(page.getByAltText('添付予定の画像')).toBeVisible({ timeout: 15000 })
})
