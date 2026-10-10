import { expect, test } from '@playwright/test'

test('画像を選んでプレビューし、アクティビティと一緒に送信する', async ({ page, context }) => {
  const group = {
    id: 1,
    name: '家族',
    type: 'family',
    images: { enabled: true, used_bytes: 0, quota_bytes: 1_000_000_000 },
  }
  const owner = { id: 1, username: 'owner', display_name: '自分' }
  const category = { id: 1, name: '家事', color_code: '#10B981', sort_order: 1 }
  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lXcAAAAASUVORK5CYII=',
    'base64'
  )
  let posted = ''
  await context.addCookies([
    { name: 'auth_token', value: 'image-create-test', url: 'http://127.0.0.1' },
  ])
  await page.route('**/v1/**', async (route) => {
    const url = new URL(route.request().url())
    if (url.pathname.endsWith('/auth/me'))
      return route.fulfill({
        json: { user: owner, membership: { id: 1, role: 'admin', status: 'active', group } },
      })
    if (url.pathname.endsWith('/groups/1')) return route.fulfill({ json: { data: group } })
    if (url.pathname.endsWith('/members')) return route.fulfill({ json: [owner] })
    if (url.pathname.endsWith('/categories')) return route.fulfill({ json: [category] })
    if (url.pathname.endsWith('/logs') && route.request().method() === 'POST') {
      posted = route.request().postDataBuffer()?.toString('utf8') ?? ''
      return route.fulfill({ status: 201, json: { data: { id: 1 } } })
    }
    if (url.pathname.endsWith('/logs/counts')) return route.fulfill({ json: { data: {} } })
    if (url.pathname.endsWith('/logs'))
      return route.fulfill({ json: { data: [], current_page: 1, last_page: 1, total: 0 } })
    return route.fulfill({ json: {} })
  })

  await page.goto('/')
  await page.getByRole('button', { name: 'アクティビティを記録する' }).click()
  await expect(page.getByRole('button', { name: '画像を選択' })).toBeVisible()
  await page
    .getByLabel('画像（任意・1枚）')
    .setInputFiles({ name: 'photo.png', mimeType: 'image/png', buffer: png })
  await expect(page.getByAltText('添付予定の画像')).toBeVisible()
  await page.getByPlaceholder('例: 算数のドリルを2ページ進めた').fill('画像付きの記録')
  await page.getByRole('button', { name: '記録を保存する' }).click()
  await expect.poll(() => posted).toContain('photo.png')
  expect(posted).toContain('client_request_id')
  expect(posted).toContain('画像付きの記録')
})

test('編集画面で画像の差し替えと削除をボタンで操作できる', async ({ page, context }) => {
  const owner = { id: 1, username: 'owner', display_name: '自分' }
  const category = { id: 1, name: '家事', color_code: '#10B981', sort_order: 1 }
  const group = {
    id: 1,
    name: '家族',
    images: { enabled: true, used_bytes: 100, quota_bytes: 1_000_000_000 },
  }
  const log = {
    id: 1,
    user_id: 1,
    category_id: 1,
    activity_date: '2026-10-10',
    activity_time: '12:00',
    content: '写真',
    note: null,
    revision: 1,
    user: owner,
    category,
    image: { id: 1, bytes: 100, width: 100, height: 100, mime: 'image/webp' },
  }
  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lXcAAAAASUVORK5CYII=',
    'base64'
  )
  await context.addCookies([
    { name: 'auth_token', value: 'image-edit-test', url: 'http://127.0.0.1' },
  ])
  await page.route('**/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/auth/me'))
      return route.fulfill({
        json: { user: owner, membership: { id: 1, role: 'admin', status: 'active', group } },
      })
    if (path.endsWith('/groups/1')) return route.fulfill({ json: { data: group } })
    if (path.endsWith('/categories')) return route.fulfill({ json: [category] })
    if (path.endsWith('/logs/1')) return route.fulfill({ json: { data: log } })
    if (path.endsWith('/images/1')) return route.fulfill({ body: png, contentType: 'image/png' })
    return route.fulfill({ json: {} })
  })

  await page.goto('/logs/1')
  await expect(page.getByRole('button', { name: '画像を差し替える' })).toBeVisible()
  await page.getByRole('button', { name: '保存時に画像を削除' }).click()
  await expect(page.getByRole('button', { name: '削除を取り消す' })).toBeVisible()
  await page.getByRole('button', { name: '削除を取り消す' }).click()
  await page
    .getByLabel('画像を選択')
    .setInputFiles({ name: 'replacement.png', mimeType: 'image/png', buffer: png })
  await expect(page.getByRole('button', { name: '差し替えを取り消す' })).toBeVisible()
  await page.getByRole('button', { name: '差し替えを取り消す' }).click()
  await expect(page.getByRole('button', { name: '保存時に画像を削除' })).toBeVisible()
})
