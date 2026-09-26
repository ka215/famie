import { expect, test } from '@playwright/test'

for (const mode of ['create', 'edit'] as const) {
  test(`${mode}: 不正入力の未送信、正規化した保存、API422表示`, async ({ page, context }) => {
    const owner = { id: 1, username: 'owner', display_name: '自分', role: 'parent' }
    const category = { id: 1, name: '家事', color_code: '#10B981' }
    const log = {
      id: 1,
      user_id: 1,
      category_id: 1,
      activity_date: '2026-09-25',
      activity_time: '12:34',
      content: '元の記録',
      note: '',
      user: owner,
      category,
    }
    const requests: Record<string, unknown>[] = []
    let rejectSave = true
    await context.addCookies([
      { name: 'auth_token', value: 'validation-test', url: 'http://127.0.0.1' },
    ])
    await page.route('**/v1/**', async (route) => {
      const request = route.request()
      const path = new URL(request.url()).pathname
      if (['POST', 'PUT'].includes(request.method())) {
        requests.push(request.postDataJSON())
        if (rejectSave) {
          return route.fulfill({
            status: 422,
            json: {
              message: 'Validation failed',
              errors: { category_id: ['カテゴリが削除されています。'] },
            },
          })
        }
        return route.fulfill({ json: { data: log } })
      }
      if (path.endsWith('/auth/me')) return route.fulfill({ json: { user: owner } })
      if (path.endsWith('/categories')) return route.fulfill({ json: [category] })
      if (path.endsWith('/users')) return route.fulfill({ json: [owner] })
      if (path.endsWith('/logs/1')) return route.fulfill({ json: { data: log } })
      return route.fulfill({ json: { data: [], current_page: 1, last_page: 1, total: 0 } })
    })
    await page.goto(mode === 'create' ? '/' : '/logs/1')
    if (mode === 'create')
      await page.getByRole('button', { name: 'アクティビティを記録する' }).click()
    const form = page.locator('form')
    const content = form.locator('textarea').first()
    const note =
      mode === 'create' ? form.locator('input[type="text"]') : form.locator('textarea').nth(1)
    const date = form.locator('input[type="date"]')
    const time = form.locator('input[type="time"]')
    const save = page.getByRole('button', {
      name: mode === 'create' ? '記録を保存する' : '更新する',
      exact: true,
    })
    await date.fill('2026-09-25')
    for (const value of ['', '　\u200B　', '😀'.repeat(1001)]) {
      await content.fill(value)
      await save.click()
      await expect(page.getByRole('alert')).toHaveText(
        value.length > 1000
          ? '活動内容は1000文字以内で入力してください。'
          : '活動内容を入力してください。'
      )
    }
    await content.fill('有効な内容')
    await date.fill('')
    await save.click()
    await expect(page.getByRole('alert')).toHaveText('実施日を入力してください。')
    await date.fill('2026-09-25')
    await note.fill('あ'.repeat(1001))
    await save.click()
    await expect(page.getByRole('alert')).toHaveText('補足メモは1000文字以内で入力してください。')
    expect(requests).toHaveLength(0)
    await content.fill(`　${'😀'.repeat(1000)}　`)
    await note.fill('　\u200B')
    await time.fill('')
    await save.click()
    await expect(page.getByRole('alert')).toHaveText('カテゴリが削除されています。')
    expect(requests).toEqual([
      {
        category_id: 1,
        activity_date: '2026-09-25',
        activity_time: null,
        content: '😀'.repeat(1000),
        note: null,
      },
    ])
    await expect(content).toHaveValue(`　${'😀'.repeat(1000)}　`)
    rejectSave = false
    await save.click()
    await expect.poll(() => requests.length).toBe(2)
    if (mode === 'create') {
      await expect(
        page.getByRole('heading', { name: 'アクティビティを記録', exact: true })
      ).toHaveCount(0)
    } else {
      await expect(page).toHaveURL(/\/$/)
    }
  })
}
