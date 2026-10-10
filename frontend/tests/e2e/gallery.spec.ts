import { expect, test } from '@playwright/test'

test('画像ギャラリーを今週の2列で表示し、拡大と投稿者フィルターを使える', async ({
  page,
  context,
}) => {
  const group = {
    id: 1,
    name: '家族',
    type: 'family',
    images: { enabled: true, used_bytes: 100, quota_bytes: 1_000_000_000 },
  }
  const owner = { id: 1, username: 'owner', display_name: '自分' }
  const member = { id: 2, username: 'member', display_name: '家族' }
  const category = { id: 1, group_id: 1, name: '家事', color_code: '#10B981', sort_order: 1 }
  const today = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Tokyo' }).format(new Date())
  const logs = [owner, member].map((user, index) => ({
    id: index + 1,
    user_id: user.id,
    category_id: 1,
    activity_date: today,
    activity_time: '12:00',
    content: `写真${index + 1}`,
    note: null,
    user,
    category,
    image: {
      id: index + 1,
      bytes: 50,
      width: index === 0 ? 1600 : 800,
      height: index === 0 ? 800 : 1600,
      mime: 'image/webp',
    },
  }))
  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lXcAAAAASUVORK5CYII=',
    'base64'
  )
  const queries: string[] = []
  await context.addCookies([{ name: 'auth_token', value: 'gallery-test', url: 'http://127.0.0.1' }])
  await page.route('**/v1/**', async (route) => {
    const url = new URL(route.request().url())
    if (url.pathname.endsWith('/auth/me'))
      return route.fulfill({
        json: { user: owner, membership: { id: 1, role: 'admin', status: 'active', group } },
      })
    if (url.pathname.endsWith('/groups/1')) return route.fulfill({ json: { data: group } })
    if (url.pathname.endsWith('/members')) return route.fulfill({ json: [owner, member] })
    if (url.pathname.endsWith('/categories')) return route.fulfill({ json: [category] })
    if (/\/images\/\d+$/.test(url.pathname))
      return route.fulfill({ body: png, contentType: 'image/png' })
    if (url.pathname.endsWith('/gallery')) {
      queries.push(url.search)
      const selected = url.searchParams.get('user_id')
      const data = selected ? logs.filter((log) => String(log.user_id) === selected) : logs
      return route.fulfill({ json: { data, current_page: 1, last_page: 1, total: data.length } })
    }
    return route.fulfill({ json: {} })
  })

  await page.goto('/gallery')
  await expect(page.getByRole('heading', { name: '画像ギャラリー' })).toBeVisible()
  await expect(page.getByRole('button', { name: '週間' })).toHaveAttribute('aria-pressed', 'true')
  await expect(page.getByRole('article')).toHaveCount(2)
  await expect(page.locator('.grid.grid-cols-2').last()).toBeVisible()
  for (const thumbnail of await page.getByRole('article').locator('img').all()) {
    await expect(thumbnail).toHaveClass(/object-cover/)
    await expect(thumbnail.locator('..').locator('..')).toHaveCSS('aspect-ratio', '1 / 1')
  }
  await page.getByRole('article').first().getByRole('button', { name: '画像を拡大' }).click()
  await expect(page.getByRole('dialog', { name: '添付画像' })).toBeVisible()
  await expect(
    page
      .getByRole('dialog', { name: '添付画像' })
      .getByRole('button', { name: '閉じる' })
      .locator('span')
  ).toBeVisible()
  await page
    .getByRole('dialog', { name: '添付画像' })
    .getByRole('button', { name: '閉じる' })
    .click()
  await page.getByRole('combobox').first().selectOption('2')
  await expect(page.getByRole('article')).toHaveCount(1)
  expect(queries.some((query) => query.includes('user_id=2'))).toBe(true)
  group.images.enabled = false
  await page.reload()
  await expect(
    page
      .getByRole('navigation', { name: 'メインメニュー' })
      .getByRole('link', { name: 'ギャラリー' })
  ).toHaveCount(0)
})

test('4件ずつ取得するギャラリーで5枚目を追加表示する', async ({ page, context }) => {
  const owner = { id: 1, username: 'owner', display_name: '自分' }
  const group = {
    id: 1,
    name: '家族',
    images: { enabled: true, used_bytes: 500, quota_bytes: 1_000_000_000 },
  }
  const category = { id: 1, group_id: 1, name: '家事', color_code: '#10B981' }
  const today = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Tokyo' }).format(new Date())
  const logs = Array.from({ length: 5 }, (_, index) => ({
    id: index + 1,
    user_id: 1,
    category_id: 1,
    activity_date: today,
    activity_time: '12:00',
    content: `写真${index + 1}`,
    user: owner,
    category,
    image: { id: index + 1, bytes: 100, width: 100, height: 100, mime: 'image/webp' },
  }))
  const requestedPages: string[] = []
  await context.addCookies([
    { name: 'auth_token', value: 'gallery-paging-test', url: 'http://127.0.0.1' },
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
    if (/\/images\/\d+$/.test(url.pathname))
      return route.fulfill({
        body: Buffer.from(
          'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lXcAAAAASUVORK5CYII=',
          'base64'
        ),
        contentType: 'image/png',
      })
    if (url.pathname.endsWith('/gallery')) {
      const pageNumber = Number(url.searchParams.get('page'))
      requestedPages.push(String(pageNumber))
      return route.fulfill({
        json: {
          data: logs.slice((pageNumber - 1) * 4, pageNumber * 4),
          current_page: pageNumber,
          last_page: 2,
          per_page: 4,
          total: 5,
        },
      })
    }
    return route.fulfill({ json: {} })
  })

  await page.goto('/gallery')
  await expect.poll(() => requestedPages.includes('1')).toBe(true)
  await page.locator('.app-scroll-area').evaluate((element) => {
    element.scrollTop = element.scrollHeight
  })
  await expect(page.getByRole('article')).toHaveCount(5)
  expect(requestedPages).toEqual(['1', '2'])
})
