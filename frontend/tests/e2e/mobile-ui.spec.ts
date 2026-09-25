import { expect, test } from '@playwright/test'

test('スマホの入力欄は16px以上で、日時フィルターと登録フォームが横にはみ出さない', async ({
  page,
  context,
}) => {
  await page.setViewportSize({ width: 320, height: 844 })
  const user = { id: 1, username: 'owner', display_name: '自分', role: 'parent' }
  const category = { id: 1, name: '家事', color_code: '#10B981' }
  await page.route('**/v1/**', (route) => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/status')) return route.fulfill({ json: { status: 'ok' } })
    if (path.endsWith('/auth/me')) return route.fulfill({ json: { user } })
    if (path.endsWith('/categories')) return route.fulfill({ json: [category] })
    if (path.endsWith('/users')) return route.fulfill({ json: [user] })
    if (path.endsWith('/logs/1'))
      return route.fulfill({
        json: {
          data: {
            id: 1,
            user_id: 1,
            category_id: 1,
            activity_date: '2026-09-25',
            activity_time: '12:34',
            content: '確認',
            note: '',
            user,
            category,
          },
        },
      })
    return route.fulfill({ json: { data: [], current_page: 1, last_page: 1, total: 0 } })
  })
  const checkFields = async () => {
    const fields = page.locator(
      'input:not([type="radio"], [type="checkbox"], [type="color"]):visible, select:visible, textarea:visible'
    )
    expect(await fields.count()).toBeGreaterThan(0)
    for (const field of await fields.all()) {
      expect(
        await field.evaluate((element) => Number.parseFloat(getComputedStyle(element).fontSize))
      ).toBeGreaterThanOrEqual(16)
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320)
  }
  await page.goto('/login')
  await expect(page.getByPlaceholder('例: parent1')).toBeVisible()
  await checkFields()
  await context.addCookies([{ name: 'auth_token', value: 'mobile-test', url: 'http://127.0.0.1' }])
  await page.goto('/settings')
  await expect(page.getByLabel('自分の表示名')).toBeVisible()
  await checkFields()
  await page.goto('/logs/1')
  await expect(page.getByLabel('実施日', { exact: true })).toBeVisible()
  await checkFields()
  await page.goto('/')
  await page.getByRole('button', { name: '指定', exact: true }).click()
  await checkFields()
  await page.getByRole('button', { name: 'アクティビティを記録する' }).click()
  await checkFields()
  await page.getByPlaceholder('例: 算数のドリルを2ページ進めた').focus()
  await checkFields()
  await page.getByRole('button', { name: '閉じる', exact: true }).click()
  await checkFields()
})

test('編集・閲覧専用の日時欄は狭い画面でも重ならず、編集値を保存できる', async ({
  page,
  context,
}) => {
  const user = { id: 1, username: 'owner', display_name: '自分', role: 'parent' }
  const category = { id: 1, name: '家事', color_code: '#10B981' }
  let readonly = false
  let saved: Record<string, unknown> | undefined
  await context.addCookies([{ name: 'auth_token', value: 'mobile-test', url: 'http://127.0.0.1' }])
  await page.route('**/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname
    if (route.request().method() === 'PUT') {
      saved = route.request().postDataJSON()
      return route.fulfill({ json: {} })
    }
    if (path.endsWith('/auth/me')) return route.fulfill({ json: { user } })
    if (path.endsWith('/categories')) return route.fulfill({ json: [category] })
    if (path.endsWith('/users')) return route.fulfill({ json: [user] })
    if (path.endsWith('/logs/1')) {
      return route.fulfill({
        json: {
          data: {
            id: 1,
            user_id: readonly ? 2 : 1,
            category_id: 1,
            activity_date: '2026-09-25',
            activity_time: '12:34',
            content: '確認用記録',
            note: '',
            user,
            category,
          },
        },
      })
    }
    return route.fulfill({ json: { data: [], current_page: 1, last_page: 1, total: 0 } })
  })
  for (const width of [320, 390]) {
    await page.setViewportSize({ width, height: 844 })
    await page.goto('/logs/1')
    const date = page.getByLabel('実施日', { exact: true })
    const time = page.getByLabel('実施時刻', { exact: true })
    await expect(date).toBeVisible()
    const dateBox = await date.boundingBox()
    const timeBox = await time.boundingBox()
    if (!dateBox || !timeBox) throw new Error('日時欄がありません')
    expect(dateBox.x + dateBox.width).toBeLessThanOrEqual(timeBox.x)
    expect(timeBox.x + timeBox.width).toBeLessThanOrEqual(width)
    await expect(date).toBeEnabled({ enabled: !readonly })
    if (!readonly) {
      await date.fill('2026-09-26')
      await time.fill('')
      await page.getByRole('button', { name: '更新する', exact: true }).click()
      await expect(page).toHaveURL(/\/$/)
      expect(saved).toMatchObject({ activity_date: '2026-09-26', activity_time: null })
    }
    readonly = true
  }
})
