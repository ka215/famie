import { expect, test } from '@playwright/test'

test('週の日付選択、月別件数、指定日表示、登録日の引き継ぎ', async ({ page, context }) => {
  const owner = { id: 1, username: 'owner', display_name: '自分', role: 'parent' }
  const category = { id: 1, name: '家事', color_code: '#10B981' }
  const now = new Date()
  const today = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Tokyo' }).format(now)
  const [year, monthNumber, day] = today.split('-').map(Number) as [number, number, number]
  const month = monthNumber - 1
  const selectedDate = `${year}-${String(monthNumber).padStart(2, '0')}-15`
  const selected = new Date(`${selectedDate}T12:00:00+09:00`)
  const monthFrom = `${year}-${String(monthNumber).padStart(2, '0')}-01`
  const monthTo = new Date(Date.UTC(year, monthNumber, 0)).toISOString().slice(0, 10)
  const nextWeekDate = new Date(Date.UTC(year, month, day + 7)).toISOString().slice(0, 10)
  const requestedCounts: string[] = []
  const requestedRanges: string[] = []

  const makeLog = (id: number) => ({
    id,
    user_id: owner.id,
    category_id: category.id,
    activity_date: selectedDate,
    activity_time: '12:34',
    content: `記録${id}`,
    note: null,
    user: owner,
    category,
  })

  await context.addCookies([
    { name: 'auth_token', value: 'calendar-test', url: 'http://127.0.0.1' },
  ])
  await page.route('**/v1/**', async (route) => {
    const url = new URL(route.request().url())
    if (url.pathname.endsWith('/auth/me')) return route.fulfill({ json: { user: owner } })
    if (url.pathname.endsWith('/categories')) return route.fulfill({ json: [category] })
    if (url.pathname.endsWith('/users')) return route.fulfill({ json: [owner] })
    if (url.pathname.endsWith('/logs/counts')) {
      const from = url.searchParams.get('from') ?? ''
      const to = url.searchParams.get('to') ?? ''
      requestedCounts.push(`${from}:${to}`)
      await new Promise((resolve) => setTimeout(resolve, 400))
      return route.fulfill({ json: { data: { [selectedDate]: 51 } } })
    }
    if (!url.pathname.endsWith('/logs')) return route.fulfill({ json: {} })

    const from = url.searchParams.get('from') ?? ''
    const to = url.searchParams.get('to') ?? ''
    requestedRanges.push(`${from}:${to}`)

    if (from === nextWeekDate && to === nextWeekDate) {
      await new Promise((resolve) => setTimeout(resolve, 400))
    }

    if (from === selectedDate && to === selectedDate) {
      return route.fulfill({
        json: { data: [makeLog(51)], current_page: 1, last_page: 1, total: 51 },
      })
    }
    return route.fulfill({ json: { data: [], current_page: 1, last_page: 1, total: 0 } })
  })

  await page.goto('/')
  await expect.poll(() => requestedRanges).toContain(`${today}:${today}`)
  const weekCalendar = page.getByRole('region', { name: '週カレンダー' })
  await expect(weekCalendar).toBeVisible()
  await expect(weekCalendar.locator('[aria-current="date"]')).toHaveAttribute(
    'aria-pressed',
    'true'
  )
  await weekCalendar.getByRole('button', { name: '次週' }).click()
  await expect(page.getByRole('status').filter({ hasText: '読み込み中...' })).toBeVisible()
  await expect.poll(() => requestedRanges).toContain(`${nextWeekDate}:${nextWeekDate}`)
  await page.getByRole('button', { name: 'アクティビティを記録する' }).click()
  await expect(page.getByLabel('実施日', { exact: true })).toHaveValue(nextWeekDate)
  await page.getByRole('button', { name: '閉じる' }).click()
  await page.getByRole('button', { name: '今週' }).click()
  await expect(weekCalendar.locator('[aria-current="date"]')).toHaveAttribute(
    'aria-pressed',
    'true'
  )

  await page.getByRole('button', { name: '月表示' }).click()
  const calendar = page.getByRole('region', { name: '月間カレンダー' })
  await expect(calendar).toBeVisible()
  await expect(
    page.getByRole('status').filter({ hasText: '月の記録を読み込み中...' })
  ).toBeVisible()
  await expect(calendar.getByRole('heading')).toHaveText(`${month + 1}月`)
  await expect(calendar.locator('.grid-cols-7').first().locator('span')).toHaveText([
    '日',
    '月',
    '火',
    '水',
    '木',
    '金',
    '土',
  ])
  await expect(
    calendar.getByRole('button', { name: '前月' }).locator('.calendar-nav-icon')
  ).toHaveCount(1)
  await expect(
    calendar.getByRole('button', { name: '次月' }).locator('.calendar-nav-icon')
  ).toHaveCount(1)
  await expect(calendar.locator('.grid-cols-7.gap-1 > button').first()).toHaveClass(/bg-red-50/)
  await expect(calendar.locator('.grid-cols-7.gap-1 > button').nth(6)).toHaveClass(/bg-blue-50/)
  const createBox = await page
    .getByRole('button', { name: 'アクティビティを記録する' })
    .boundingBox()
  const calendarBox = await calendar.boundingBox()
  expect(createBox).not.toBeNull()
  expect(calendarBox).not.toBeNull()
  if (!createBox || !calendarBox) throw new Error('登録ボタンまたはカレンダーを表示できません')
  expect(createBox.y + createBox.height).toBeLessThanOrEqual(calendarBox.y)
  await expect.poll(() => requestedCounts).toContain(`${monthFrom}:${monthTo}`)

  const selectedDay = page.getByRole('button', {
    name: /15日.*アクティビティ51件/,
  })
  await expect(selectedDay).toBeVisible()
  await expect(selectedDay).toHaveClass(/bg-amber-100/)
  await selectedDay.click()
  await expect(page.getByText('指定日のアクティビティ')).toBeVisible()
  await expect(page.getByRole('heading', { level: 2 })).toHaveText(
    new Intl.DateTimeFormat('ja-JP', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      weekday: 'short',
      timeZone: 'Asia/Tokyo',
    }).format(selected)
  )
  await expect(page.getByText('記録51', { exact: true })).toBeVisible()

  await page.getByRole('button', { name: 'アクティビティを記録する' }).click()
  await expect(page.getByLabel('実施日', { exact: true })).toHaveValue(selectedDate)
  await page.getByRole('button', { name: '閉じる' }).click()
  await page.getByRole('button', { name: 'カレンダーに戻る' }).click()
  await expect(page.getByRole('region', { name: '月間カレンダー' })).toBeVisible()
})
