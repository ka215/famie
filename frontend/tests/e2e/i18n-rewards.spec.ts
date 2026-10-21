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
    Object.defineProperty(navigator, 'languages', { configurable: true, get: () => ['en-US'] })
    Object.defineProperty(navigator, 'language', { configurable: true, get: () => 'en-US' })
  })
  await page
    .context()
    .addCookies([{ name: 'auth_token', value: 'reward-language', url: 'http://127.0.0.1' }])
  const user = {
    id: 1,
    username: 'parent',
    display_name: '親の原文',
    ui_locale: null as string | null,
  }
  const member = { ...user, membership_id: 1, role: 'admin', status: 'active' }
  const group = { id: 1, name: '家族' }
  const version = {
    id: 1,
    name: '原文リワード',
    description: '原文の報酬',
    metric: 'activities',
    color: 'gold',
    count_mode: 'fixed',
    required_count: 1,
    category_id: 1,
    category_name: '家事',
    target_ids: [1],
    effective_from: '2026-10-01',
  }
  const rewards = [1, 2, 3].map((count) => ({
    id: count + 1,
    status: 'active',
    revision: 1,
    start_month: '2026-10-01',
    cycle_months: count,
    timezone: 'Asia/Tokyo',
    version: { ...version, required_count: count, metric: count === 3 ? 'likes' : 'activities' },
    pending_version: null,
    starts_on: '2026-10-01',
    ends_on: '2026-10-31',
    started: true,
    progress: count === 1 ? null : count - 2,
    awarded: false,
  }))
  const award = {
    id: 1,
    reward_id: 1,
    awarded_at: '2026-10-18T00:30:00Z',
    achieved_count: 1,
    snapshot: {
      ...version,
      starts_on: '2026-10-01',
      ends_on: '2026-10-31',
      timezone: 'Asia/Tokyo',
    },
  }
  const state = {
    calls: [] as string[],
    notification: false,
    processing: false,
    user,
  }
  await page.route('**/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname
    const method = route.request().method()
    state.calls.push(`${method} ${path}`)
    if (path.endsWith('/auth/me'))
      return route.fulfill({
        json: { user, membership: { id: 1, role: 'admin', status: 'active', group } },
      })
    if (path.endsWith('/members')) return route.fulfill({ json: [member] })
    if (path.endsWith('/categories'))
      return route.fulfill({ json: [{ id: 1, name: '家事', color_code: '#10B981' }] })
    if (path.endsWith('/groups/1')) return route.fulfill({ json: { data: group } })
    if (path.endsWith('/prepare'))
      return route.fulfill({ json: { status: state.processing ? 'processing' : 'complete' } })
    if (path.endsWith('/notifications'))
      return route.fulfill({ json: { data: state.notification ? [award] : [] } })
    if (method === 'POST' && path.endsWith('/rewards'))
      return route.fulfill({
        status: 422,
        json: { errors: { name: ['Server validation message'] } },
      })
    return route.fulfill({
      json: {
        data: path.endsWith('/awards') ? [award] : rewards,
        current_page: 1,
        last_page: 1,
        sync_status: state.processing ? 'processing' : 'complete',
        counts: { active: 3, management: 3 },
        limits: { active: 3, management: 6 },
      },
    })
  })
  return state
}

test('reward counts, date labels and narrow tabs translate without reloading', async ({
  page,
}, testInfo) => {
  const state = await setup(page)
  await page.setViewportSize({ width: 320, height: 740 })
  await page.goto('/rewards')
  await expect(page).toHaveTitle('Rewards | Famie')
  await expect(page.getByRole('article').nth(0)).toContainText('1 active day')
  await expect(page.getByRole('article').nth(1)).toContainText('2 active days')
  await expect(page.getByRole('article').nth(2)).toContainText('3 likes received')
  await expect(page.getByRole('article').nth(0).getByRole('progressbar')).toHaveAttribute(
    'aria-valuetext',
    'Not calculated'
  )
  expect(await page.locator('main').evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(true)
  await page.screenshot({ path: testInfo.outputPath('rewards-english-320-light.png') })
  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'))
  for (const select of await page.locator('.reward-filters select').all()) {
    await expect(select).toHaveCSS('appearance', 'none')
    await expect(select).toHaveCSS('background-color', /^oklch\(0\.279 0\.041 260\.03\d+\)$/)
    await expect(select).toHaveCSS('color', /^oklch\(0\.968 0\.007 247\.89\d+\)$/)
  }
  for (const option of await page.locator('.reward-filters option').all()) {
    await expect(option).toHaveCSS('background-color', 'rgb(30, 41, 59)')
    await expect(option).toHaveCSS('color', 'rgb(241, 245, 249)')
  }
  await page.screenshot({ path: testInfo.outputPath('rewards-english-320-dark.png') })
  const calls = [...state.calls]
  await language(page, 'ja-JP')
  await expect(page.getByRole('button', { name: '条件・進捗', exact: true })).toHaveAttribute(
    'aria-pressed',
    'true'
  )
  await expect(page.getByRole('article').first()).toContainText('2026/10/01〜2026/10/31')
  await expect(page.getByRole('article').first()).toContainText('原文リワード')
  expect(state.calls).toEqual(calls)
  await page.getByRole('button', { name: 'インベントリ', exact: true }).click()
  await expect(page.locator('.reward-filters select')).toHaveCSS(
    'background-color',
    /^oklch\(0\.279 0\.041 260\.03\d+\)$/
  )
  const memberOption = page.locator('.reward-filters option').first()
  await expect(memberOption).toHaveCSS('background-color', 'rgb(30, 41, 59)')
  await expect(memberOption).toHaveCSS('color', 'rgb(241, 245, 249)')
  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'))
  await expect(page.locator('.reward-filters select')).toHaveCSS(
    'background-color',
    'rgb(255, 255, 255)'
  )
  await expect(page.locator('.reward-filters select')).toHaveCSS(
    'color',
    /^oklch\(0\.208 0\.042 265\.75\d+\)$/
  )
  await expect(memberOption).toHaveCSS('background-color', 'rgb(255, 255, 255)')
  await expect(memberOption).toHaveCSS('color', 'rgb(15, 23, 42)')
})

test('reward draft, validation, trophy selection and API error survive locale switching', async ({
  page,
}, testInfo) => {
  const state = await setup(page)
  await page.goto('/rewards')
  await page.getByRole('button', { name: 'Manage', exact: true }).click()
  await page.getByRole('button', { name: 'Create reward', exact: true }).click()
  const dialog = page.getByRole('dialog')
  await page.setViewportSize({ width: 320, height: 740 })
  expect(await dialog.evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(true)
  await page.screenshot({ path: testInfo.outputPath('reward-form-english-320.png') })
  await dialog.getByRole('textbox', { name: 'Name', exact: true }).fill('原文の入力')
  await dialog.getByRole('textbox', { name: 'Reward description', exact: true }).fill('説明の入力')
  await dialog.getByRole('button', { name: 'Clear all', exact: true }).click()
  await dialog.getByRole('button', { name: 'Save draft', exact: true }).click()
  await expect(dialog.getByRole('alert')).toContainText('at least one member')
  await dialog.getByRole('combobox', { name: 'Trophy', exact: true }).click()
  const calls = [...state.calls]
  await language(page, 'ja-JP')
  await expect(dialog.getByRole('alert')).toContainText('対象者を入力')
  await expect(dialog.getByRole('listbox', { name: 'トロフィー', exact: true })).toBeVisible()
  await expect(dialog.getByRole('textbox', { name: '名称', exact: true })).toHaveValue('原文の入力')
  expect(state.calls).toEqual(calls)
  await dialog.getByRole('option', { name: '銀トロフィー' }).click()
  await dialog.getByRole('button', { name: '全選択', exact: true }).click()
  await dialog.getByRole('button', { name: '下書きとして保存', exact: true }).click()
  await expect(dialog.getByRole('alert')).toHaveText('Server validation message')
  const afterSave = [...state.calls]
  await language(page, 'en-US')
  await expect(dialog.getByRole('alert')).toHaveCount(0)
  await expect(dialog.getByRole('combobox', { name: 'Trophy', exact: true })).toContainText(
    'Silver trophy'
  )
  expect(state.calls).toEqual(afterSave)
})

test('earned notification translates in place without claiming again and history uses device time', async ({
  page,
}) => {
  const state = await setup(page)
  state.notification = true
  await page.goto('/rewards')
  await expect(page.getByRole('dialog', { name: 'You earned a reward!' })).toBeVisible()
  const calls = [...state.calls]
  await language(page, 'ja-JP')
  await expect(page.getByRole('dialog', { name: 'リワードを獲得しました！' })).toBeVisible()
  expect(state.calls).toEqual(calls)
  await page.getByRole('dialog').getByRole('button', { name: '閉じる', exact: true }).click()
  await page.getByRole('button', { name: 'インベントリ', exact: true }).click()
  await expect(page.getByRole('article')).toContainText('2026/10/18 09:30:00')
  expect(state.calls.filter((call) => call.endsWith('/notifications'))).toHaveLength(1)
})

test.describe('reward timestamp in a different device time zone', () => {
  test.use({ timezoneId: 'America/Los_Angeles' })
  test('fixed account language formats dates without changing saved period boundaries', async ({
    page,
  }) => {
    const state = await setup(page)
    state.user.ui_locale = 'en'
    await page.goto('/rewards')
    await language(page, 'ja-JP')
    await expect(page).toHaveTitle('Rewards | Famie')
    await expect(page.getByRole('article').first()).toContainText('10/01/2026 – 10/31/2026')
    await page.getByRole('button', { name: 'Inventory', exact: true }).click()
    await expect(page.getByRole('article')).toContainText('10/17/2026, 17:30:00')
  })
})
