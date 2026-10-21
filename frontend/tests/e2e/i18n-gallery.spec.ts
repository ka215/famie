import { expect, type Page, test } from '@playwright/test'

async function language(page: Page, value: string) {
  await page.evaluate((value) => {
    Object.defineProperty(navigator, 'languages', { configurable: true, get: () => [value] })
    Object.defineProperty(navigator, 'language', { configurable: true, get: () => value })
    window.dispatchEvent(new Event('languagechange'))
  }, value)
}

async function setup(page: Page) {
  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'languages', { configurable: true, get: () => ['en-GB'] })
    Object.defineProperty(navigator, 'language', { configurable: true, get: () => 'en-GB' })
  })
  await page
    .context()
    .addCookies([{ name: 'auth_token', value: 'gallery-i18n', url: 'http://127.0.0.1' }])
  const user = { id: 1, username: 'owner', display_name: '自分', ui_locale: null }
  const group = { id: 1, name: '家族', images: { enabled: true } }
  const category = { id: 1, name: '家事', color_code: '#10B981' }
  const logs = Array.from({ length: 12 }, (_, i) => ({
    id: i + 1,
    user_id: 1,
    category_id: 1,
    activity_date: '2026-10-18',
    activity_time: '12:00',
    content: `原文${i + 1}`,
    note: null,
    revision: 1,
    user,
    category,
    image: { id: i + 1, bytes: 50, width: 800, height: 800, mime: 'image/webp' },
  }))
  const state = { queries: [] as string[], images: 0, fail: false, metadataFail: false }
  await page.route('**/v1/**', async (route) => {
    const url = new URL(route.request().url())
    const path = url.pathname
    if (path.endsWith('/auth/me'))
      return route.fulfill({
        json: { user, membership: { id: 1, role: 'admin', status: 'active', group } },
      })
    if (path.endsWith('/groups/1')) return route.fulfill({ json: { data: group } })
    if (path.endsWith('/members')) return route.fulfill({ json: [user] })
    if (path.endsWith('/categories'))
      return route.fulfill({
        status: state.metadataFail ? 500 : 200,
        json: state.metadataFail ? {} : [category],
      })
    if (/\/images\/\d+$/.test(path)) {
      state.images++
      return route.fulfill({
        body: Buffer.from(
          'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lXcAAAAASUVORK5CYII=',
          'base64'
        ),
        contentType: 'image/png',
      })
    }
    if (/\/logs\/\d+$/.test(path))
      return route.fulfill({ json: { data: logs[Number(path.split('/').at(-1)) - 1] } })
    if (path.endsWith('/gallery')) {
      state.queries.push(url.search)
      if (state.fail) return route.fulfill({ status: 500, json: {} })
      const number = Number(url.searchParams.get('page'))
      return route.fulfill({
        json: {
          data: logs.slice((number - 1) * 4, number * 4),
          current_page: number,
          last_page: 3,
          total: 12,
        },
      })
    }
    return route.fulfill({ json: {} })
  })
  return state
}

test('gallery language preserves drafts, validation and expanded image without fetching', async ({
  page,
}, testInfo) => {
  const state = await setup(page)
  await page.setViewportSize({ width: 320, height: 700 })
  await page.goto('/gallery')
  await expect(page).toHaveTitle('Image gallery | Famie')
  await expect(page.getByRole('article').first()).toContainText('自分 · 10/18/2026')
  expect(await page.locator('main').evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(true)
  await page.screenshot({ path: testInfo.outputPath('gallery-english-320-light.png') })
  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'))
  await page.screenshot({ path: testInfo.outputPath('gallery-english-320-dark.png') })
  const initial = new URLSearchParams(state.queries[0])
  expect(new Date(`${initial.get('from')}T12:00:00`).getDay()).toBe(1)
  await page.getByRole('button', { name: 'Custom range', exact: true }).click()
  await page.getByLabel('Start date', { exact: true }).fill('2026-10-19')
  await page.getByLabel('End date', { exact: true }).fill('2026-10-18')
  await page.getByRole('button', { name: 'Apply', exact: true }).click()
  await expect(page.getByRole('alert')).toHaveText(
    'The start date must be on or before the end date.'
  )
  const requests = [...state.queries]
  await language(page, 'ja-JP')
  await expect(page.getByRole('alert')).toHaveText('開始日は終了日以前にしてください。')
  await expect(page.getByLabel('開始日', { exact: true })).toHaveValue('2026-10-19')
  expect(state.queries).toEqual(requests)
  await page.getByLabel('開始日', { exact: true }).fill('2026-10-17')
  await page.getByRole('button', { name: '適用', exact: true }).click()
  await page.getByRole('article').first().getByRole('button', { name: '画像を拡大' }).click()
  const images = state.images
  await language(page, 'en-US')
  await expect(page.getByRole('dialog', { name: 'Attached image' })).toBeVisible()
  expect(state.images).toBe(images)
  await page.getByRole('dialog').getByRole('button', { name: 'Close', exact: true }).click()
  expect(await page.locator('main').evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(true)
})

test('gallery metadata and list errors translate without retrying until requested', async ({
  page,
}) => {
  const state = await setup(page)
  state.metadataFail = true
  await page.goto('/gallery')
  await expect(page.getByRole('alert')).toHaveText('Could not load filters. Please try again.')
  await language(page, 'ja-JP')
  await expect(page.getByRole('alert')).toHaveText(
    '表示条件を取得できませんでした。再試行してください。'
  )
  state.metadataFail = false
  state.fail = true
  await page.getByRole('button', { name: '再試行', exact: true }).click()
  await expect(page.getByRole('alert')).toHaveText(
    '画像を取得できませんでした。再試行してください。'
  )
  const requests = [...state.queries]
  await language(page, 'en-US')
  await expect(page.getByRole('alert')).toHaveText('Could not load images. Please try again.')
  expect(state.queries).toEqual(requests)
  state.fail = false
  await page.getByRole('button', { name: 'Retry', exact: true }).click()
  await expect(page.getByRole('article').first()).toBeVisible()
})

test('gallery restores pages and filters after changing language in activity details', async ({
  page,
}) => {
  const state = await setup(page)
  await page.goto('/gallery')
  await page.getByRole('combobox', { name: 'Posted by', exact: true }).selectOption('1')
  await page.getByRole('combobox', { name: 'Category', exact: true }).selectOption('1')
  await page.locator('.app-scroll-area').evaluate((el) => {
    el.scrollTop = el.scrollHeight
  })
  await expect
    .poll(() => state.queries.some((query) => new URLSearchParams(query).get('page') === '2'))
    .toBe(true)
  const card = page.getByRole('article').nth(4)
  await card.getByRole('link', { name: 'View activity' }).click()
  await language(page, 'ja-JP')
  await page.getByRole('link', { name: '← ギャラリーへ' }).click()
  await expect(page).toHaveTitle('画像ギャラリー | Famie')
  await expect(page.getByRole('combobox', { name: '投稿者', exact: true })).toHaveValue('1')
  await expect(page.getByRole('combobox', { name: 'カテゴリー', exact: true })).toHaveValue('1')
  await expect(page.locator('[data-log-id="5"]')).toBeVisible()
  await expect(page.getByRole('article').first()).toContainText('2026/10/18')
})
