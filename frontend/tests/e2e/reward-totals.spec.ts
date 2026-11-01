import { expect, type Page, test } from '@playwright/test'

async function setup(page: Page, locale = 'ja', role = 'admin') {
  await page
    .context()
    .addCookies([{ name: 'auth_token', value: 'totals-test', url: 'http://127.0.0.1' }])
  const state = {
    totals: { gold: 2, silver: 1, bronze: 10 } as unknown,
    activities: { posts: 12, likes_received: 7 } as unknown,
    status: 200,
    holds: new Map<string, Promise<void>>(),
  }
  const user = { id: 1, username: 'parent', display_name: '本人', ui_locale: locale }
  const group = { id: 1, name: '累計家族' }
  await page.route('**/v1/**', async (route) => {
    const url = new URL(route.request().url())
    const path = url.pathname
    if (path.endsWith('/auth/me'))
      return route.fulfill({ json: { user, membership: { id: 1, role, status: 'active', group } } })
    if (path.endsWith('/members'))
      return route.fulfill({
        json: [
          { ...user, membership_id: 1, role, status: 'active' },
          {
            id: 2,
            username: 'child',
            display_name: '子ども',
            membership_id: 2,
            role: 'member',
            status: 'active',
          },
        ],
      })
    if (path.endsWith('/prepare')) return route.fulfill({ json: { status: 'complete' } })
    if (path.endsWith('/notifications')) return route.fulfill({ json: { data: [] } })
    if (path.endsWith('/groups/1')) return route.fulfill({ json: { data: group } })
    if (path.endsWith('/awards')) {
      const member = url.searchParams.get('member_id') ?? '1'
      const currentPage = Number(url.searchParams.get('page') ?? 1)
      const totals = member === '2' ? { gold: 0, silver: 3, bronze: 0 } : state.totals
      const count =
        totals && typeof totals === 'object'
          ? Object.values(totals).reduce(
              (sum: number, value) => sum + (typeof value === 'number' ? value : 0),
              0
            )
          : 0
      const data = Array.from(
        { length: Math.max(0, Math.min(10, count - (currentPage - 1) * 10)) },
        (_, index) => ({
          id: currentPage * 10 + index,
          reward_id: 1,
          awarded_at: '2026-01-31T12:00:00Z',
          achieved_count: 500,
          snapshot: {
            name: `獲得履歴 ${currentPage}-${index}`,
            description: '履歴',
            color: 'gold',
            metric: 'activities',
            required_count: 1,
            starts_on: '2026-01-01',
            ends_on: '2026-01-31',
            timezone: 'UTC',
          },
        })
      )
      const body = {
        data,
        current_page: currentPage,
        last_page: Math.max(1, Math.ceil(count / 10)),
        total: count,
        meta: {
          trophy_totals: totals,
          activity_totals: member === '2' ? { posts: 3, likes_received: 2 } : state.activities,
        },
      }
      const status = state.status
      await state.holds.get(member)
      return route.fulfill({
        status,
        json: status === 200 ? body : { message: 'Inventory unavailable' },
      })
    }
    if (path.endsWith('/rewards'))
      return route.fulfill({ json: { data: [], current_page: 1, last_page: 1, total: 0 } })
    return route.fulfill({ json: [] })
  })
  await page.goto('/rewards')
  await expect(
    page.getByText(
      locale === 'ja' ? '対象のリワードはまだありません。' : 'No rewards for this member yet.',
      { exact: true }
    )
  ).toBeVisible()
  return state
}

for (const locale of ['ja', 'en']) {
  test(`累計はページを跨いで同じ数、日英と320pxのテーマ表示（${locale}）`, async ({
    page,
  }, testInfo) => {
    await setup(page, locale)
    await page.setViewportSize({ width: 320, height: 740 })
    await page
      .getByRole('button', { name: locale === 'ja' ? 'インベントリ' : 'Inventory', exact: true })
      .click()
    const totals = page.getByTestId('trophy-totals')
    await expect(page.getByTestId('total-posts')).toContainText(
      locale === 'ja' ? 'アクティビティ投稿総数 12件' : 'Total posts: 12 posts'
    )
    await expect(page.getByTestId('total-likes_received')).toContainText(
      locale === 'ja' ? 'いいね獲得総数 7件' : 'Total likes received: 7 likes'
    )
    await expect(totals).toContainText(
      locale === 'ja' ? '金トロフィー 2個' : 'Gold trophy: 2 trophies'
    )
    await expect(totals).toContainText(
      locale === 'ja' ? '銅トロフィー 10個' : 'Bronze trophy: 10 trophies'
    )
    await expect(page.getByRole('article')).toHaveCount(10)
    await page.getByRole('button', { name: locale === 'ja' ? '次へ' : 'Next', exact: true }).click()
    await expect(page.getByRole('article')).toHaveCount(3)
    await expect(totals).toContainText(
      locale === 'ja' ? '金トロフィー 2個' : 'Gold trophy: 2 trophies'
    )
    for (const theme of ['light', 'dark']) {
      await page.evaluate(
        (value) => document.documentElement.setAttribute('data-theme', value),
        theme
      )
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)
      ).toBe(true)
      await page.screenshot({ path: testInfo.outputPath(`totals-${locale}-${theme}-320.png`) })
    }
  })
}

test('メンバー切替は未確定表示にし、遅い前メンバー応答を反映しない', async ({ page }) => {
  const state = await setup(page)
  let releaseFirst = () => {}
  let releaseSecond = () => {}
  state.holds.set(
    '1',
    new Promise<void>((resolve) => {
      releaseFirst = resolve
    })
  )
  state.holds.set(
    '2',
    new Promise<void>((resolve) => {
      releaseSecond = resolve
    })
  )
  const firstRequest = page.waitForRequest('**/rewards/awards?**')
  await page.getByRole('button', { name: 'インベントリ', exact: true }).click()
  await firstRequest
  await page.getByRole('combobox', { name: '対象者', exact: true }).selectOption('2')
  const totals = page.getByTestId('trophy-totals')
  await expect(totals).toContainText('—')
  releaseSecond()
  await expect(totals).toContainText('銀トロフィー 3個')
  await expect(page.getByTestId('total-posts')).toContainText('アクティビティ投稿総数 3件')
  const oldResponse = page.waitForResponse(
    (response) =>
      response.url().includes('/awards?') &&
      new URL(response.url()).searchParams.get('member_id') === '1'
  )
  releaseFirst()
  await oldResponse
  await expect(totals).toContainText('銀トロフィー 3個')
  await expect(page.getByTestId('total-likes_received')).toContainText('いいね獲得総数 2件')
  await expect(page.getByRole('article')).toHaveCount(3)
})

test('失敗・不正メタデータはゼロや部分履歴を表示せず、再試行でゼロを確定する', async ({ page }) => {
  const state = await setup(page)
  state.status = 500
  await page.getByRole('button', { name: 'インベントリ', exact: true }).click()
  const totals = page.getByTestId('trophy-totals')
  await expect(page.getByRole('alert')).toContainText('Inventory unavailable')
  await expect(totals).toContainText('—')
  state.status = 200
  state.totals = { gold: 2, silver: -1, bronze: 0 }
  await page.getByRole('button', { name: '再読み込み', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('取得できませんでした。')
  await expect(page.getByRole('article')).toHaveCount(0)
  await expect(totals).toContainText('—')
  state.totals = { gold: 0, silver: 0, bronze: 0 }
  for (const invalid of [
    undefined,
    { posts: -1, likes_received: 7 },
    { posts: 12 },
    { posts: 12, likes_received: Number.MAX_SAFE_INTEGER + 1 },
  ]) {
    state.activities = invalid
    await page.getByRole('button', { name: '再読み込み', exact: true }).click()
    await expect(page.getByRole('alert')).toContainText('取得できませんでした。')
    await expect(page.getByTestId('total-posts')).toContainText('—')
    await expect(page.getByRole('article')).toHaveCount(0)
  }
  state.activities = { posts: 0, likes_received: 0 }
  await page.getByRole('button', { name: '再読み込み', exact: true }).click()
  await expect(totals).toContainText('金トロフィー 0個')
  await expect(page.getByTestId('total-posts')).toContainText('アクティビティ投稿総数 0件')
  await expect(page.getByTestId('total-likes_received')).toContainText('いいね獲得総数 0件')
  await expect(page.getByText('獲得履歴はまだありません。', { exact: true })).toBeVisible()
})

test('一般メンバーには選択欄を追加せず、大きな累計は行内でスクロールする', async ({ page }) => {
  const state = await setup(page, 'ja', 'member')
  state.totals = { gold: 1_234_567_890_123, silver: 1_234_567_890_123, bronze: 1_234_567_890_123 }
  state.activities = { posts: 1_234_567_890_123, likes_received: 1_234_567_890_123 }
  // Keep the response bounded while testing counts above the visible page size.
  await page.setViewportSize({ width: 320, height: 740 })
  await page.getByRole('button', { name: 'インベントリ', exact: true }).click()
  await expect(page.getByRole('combobox')).toHaveCount(0)
  const totals = page.getByTestId('trophy-totals')
  await expect(totals).toContainText('1,234,567,890,123')
  expect(await totals.evaluate((el) => el.scrollWidth > el.clientWidth)).toBe(true)
  await totals.focus()
  await expect(totals).toBeFocused()
  await totals.press('ArrowRight')
  await expect.poll(() => totals.evaluate((el) => el.scrollLeft)).toBeGreaterThan(0)
  expect(
    await page.evaluate(() => ({
      fits: document.documentElement.scrollWidth <= window.innerWidth,
      viewport: window.innerWidth,
      document: document.documentElement.scrollWidth,
      outside: [...document.querySelectorAll('body *')]
        .filter((el) => {
          const rect = el.getBoundingClientRect()
          return rect.right > window.innerWidth && rect.width > 1
        })
        .slice(0, 6)
        .map((el) => ({
          tag: el.tagName,
          class: el.className,
          right: el.getBoundingClientRect().right,
        })),
    }))
  ).toMatchObject({ fits: true })
})
