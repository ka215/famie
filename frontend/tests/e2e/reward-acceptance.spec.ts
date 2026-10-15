import { type APIRequestContext, type BrowserContext, expect, test } from '@playwright/test'

test.setTimeout(60_000)

async function family(request: APIRequestContext, context: BrowserContext) {
  const username = `accept${Date.now()}${Math.floor(Math.random() * 10000)}`
  const password = 'Famie-E2E-only-123!'
  const response = await request.post('/api/v1/auth/register', {
    data: {
      username,
      password,
      password_confirmation: password,
      display_name: '受入確認の親',
      group_name: '受入確認',
    },
  })
  expect(response.ok()).toBeTruthy()
  const identity = await response.json()
  const headers = { Authorization: `Bearer ${identity.access_token}` }
  await context.addCookies([
    { name: 'auth_token', value: identity.access_token, url: 'http://127.0.0.1' },
  ])
  const path = `/api/v1/groups/${identity.membership.group.id}`
  const categories = await (await request.get(`${path}/categories`, { headers })).json()
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'UTC',
    year: 'numeric',
    month: '2-digit',
  }).formatToParts(new Date())
  const month = `${parts.find((p) => p.type === 'year')?.value}-${parts.find((p) => p.type === 'month')?.value}`
  const definition = async (name: string, overrides = {}) => {
    const payload = {
      name,
      description: '受入報酬',
      metric: 'activities',
      color: 'gold',
      required_count: 3,
      target_ids: [identity.membership.id],
      category_id: null,
      start_month: month,
      cycle_months: 1,
      timezone: 'UTC',
      ...overrides,
    }
    const created = await request.post(`${path}/rewards`, { headers, data: payload })
    expect(created.status(), await created.text()).toBe(201)
    return { ...(await created.json()).data, payload }
  }
  const status = async (reward: { id: number }, state: 'active' | 'stopped', revision: number) => {
    const result = await request.put(`${path}/rewards/${reward.id}/status`, {
      headers,
      data: { status: state, revision },
    })
    expect(result.ok()).toBeTruthy()
    return (await result.json()).data
  }
  const log = async (day: number, categoryId = categories[0].id) => {
    const result = await request.post(`${path}/logs`, {
      headers,
      data: {
        category_id: categoryId,
        activity_date: `${month}-${String(day).padStart(2, '0')}`,
        content: '受入活動',
      },
    })
    expect(result.status()).toBe(201)
    return (await result.json()).data
  }
  return { identity, headers, path, categories, month, username, password, definition, status, log }
}

test('A1・A2：実APIの定義前活動とカテゴリ別の日数を画面で確認する', async ({ page, context }) => {
  const f = await family(page.request, context)
  const reward = await f.definition('カテゴリ3日', { category_id: f.categories[0].id })
  await f.status(reward, 'active', 1)
  await f.log(1)
  await f.log(1)
  await f.log(2, f.categories[1].id)
  await f.log(3)
  const initialPreparation = page.waitForResponse((r) => r.url().endsWith('/rewards/notifications'))
  await page.goto('/rewards')
  await initialPreparation
  await expect(page.getByRole('article').getByText('現在 2/3', { exact: true })).toBeVisible()
  await expect(page.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '66')
  const progressRow = page.getByRole('progressbar').locator('..')
  await expect(progressRow).toContainText('66%')
  const aligned = await progressRow.evaluate((element) => {
    const parts = [...element.children].map((child) => child.getBoundingClientRect())
    return (
      parts.every(
        (rect) =>
          Math.abs(
            (rect.top + rect.bottom) / 2 - ((parts[0]?.top ?? 0) + (parts[0]?.bottom ?? 0)) / 2
          ) < 2
      ) && element.scrollWidth <= element.clientWidth
    )
  })
  expect(aligned).toBe(true)
  await page
    .getByRole('article')
    .screenshot({ path: test.info().outputPath('reward-progress.png') })
  await expect(page.getByText(/集計中です/)).toHaveCount(0)
  await expect(page.getByRole('dialog')).toHaveCount(0)
  await page.goto('/settings')
  await f.log(2)
  for (let day = 4; day <= 10; day++) await f.log(day)
  const existing = await f.definition('定義前10日', { required_count: 10 })
  await f.status(existing, 'active', 1)
  await page.goto('/rewards')
  const modal = page.getByRole('dialog', { name: 'リワードを獲得しました！' })
  await expect(modal.getByRole('heading', { name: '定義前10日' })).toBeVisible()
  await expect(modal.getByRole('heading', { name: 'カテゴリ3日' })).toBeVisible()
  await modal.getByRole('button', { name: '閉じる', exact: true }).click()
  await expect(page.getByRole('article').filter({ hasText: '定義前10日' })).toContainText(
    '獲得済み'
  )
})

test('A5・A10：いいね解除後の獲得維持と同名再作成を実APIで確認する', async ({ page, context }) => {
  const f = await family(page.request, context)
  const member = await page.request.post(`${f.path}/members`, {
    headers: f.headers,
    data: {
      username: `${f.username}kid`,
      display_name: 'いいねする子',
      role: 'member',
      password: f.password,
      password_confirmation: f.password,
    },
  })
  expect(member.ok()).toBeTruthy()
  const login = await (
    await page.request.post('/api/v1/auth/login', {
      data: { login: `${f.username}kid`, password: f.password },
    })
  ).json()
  const liker = { Authorization: `Bearer ${login.access_token}` }
  const reward = await f.definition('同名いいね賞', { metric: 'likes', required_count: 10 })
  await f.status(reward, 'active', 1)
  let lastLog = 0
  for (let i = 0; i < 10; i++) {
    lastLog = (await f.log(1)).id
    expect(
      (await page.request.put(`${f.path}/logs/${lastLog}/like`, { headers: liker })).ok()
    ).toBeTruthy()
  }
  await page.goto('/rewards')
  await expect(page.getByRole('dialog', { name: 'リワードを獲得しました！' })).toBeVisible()
  await page.getByRole('dialog').getByRole('button', { name: '閉じる', exact: true }).click()
  expect(
    (await page.request.delete(`${f.path}/logs/${lastLog}/like`, { headers: liker })).ok()
  ).toBeTruthy()
  await page.reload()
  await expect(page.getByRole('article')).toContainText('現在 9/10')
  await expect(page.getByRole('article')).toContainText('獲得済み')
  await expect(page.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '90')
  await f.status(reward, 'stopped', 2)
  expect(
    (
      await page.request.delete(`${f.path}/rewards/${reward.id}`, {
        headers: f.headers,
        data: { revision: 3 },
      })
    ).ok()
  ).toBeTruthy()
  const recreated = await f.definition('同名いいね賞', { metric: 'likes', required_count: 9 })
  expect(recreated.id).not.toBe(reward.id)
  await f.status(recreated, 'active', 1)
  await page.reload()
  await expect(page.getByRole('dialog', { name: 'リワードを獲得しました！' })).toBeVisible()
  await page.getByRole('dialog').getByRole('button', { name: '閉じる', exact: true }).click()
  await page.getByRole('button', { name: 'インベントリ', exact: true }).click()
  await expect(page.getByRole('article')).toHaveCount(2)
  await expect(page.getByText('削除済みリワード', { exact: true })).toHaveCount(1)
})

test('A6：停止中の編集を予約し、再開時に旧条件で達成する', async ({ page, context }) => {
  const f = await family(page.request, context)
  const reward = await f.definition('再開確認', { required_count: 2 })
  await f.status(reward, 'active', 1)
  await f.status(reward, 'stopped', 2)
  await page.goto('/rewards')
  await page.getByRole('button', { name: '管理', exact: true }).click()
  await page.getByRole('button', { name: '編集', exact: true }).click()
  await page.getByRole('dialog').getByLabel('必要数', { exact: true }).fill('3')
  await page.getByRole('dialog').getByRole('button', { name: '変更を保存', exact: true }).click()
  await expect(page.getByText(/次期変更あり/)).toContainText('必要数 3')
  await page.goto('/settings')
  await f.log(1)
  await f.log(2)
  await page.goto('/rewards')
  await page.getByRole('button', { name: '管理', exact: true }).click()
  await page.getByRole('button', { name: '再開', exact: true }).click()
  await expect(page.getByRole('dialog', { name: 'リワードを獲得しました！' })).toBeVisible()
  await page.getByRole('dialog').getByRole('button', { name: '閉じる', exact: true }).click()
  await page.getByRole('button', { name: '条件・進捗', exact: true }).click()
  await expect(page.getByRole('article')).toContainText('現在 2/2')
  await expect(page.getByRole('article')).toContainText('獲得済み')
  await expect(page.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '100')
})

test('A7・A14：別タイムゾーンの端末でも開始前編集と保存済み期間を維持する', async ({
  page,
  context,
  browser,
}) => {
  const f = await family(page.request, context)
  const next = new Date(`${f.month}-01T00:00:00Z`)
  next.setUTCMonth(next.getUTCMonth() + 2)
  const startMonth = next.toISOString().slice(0, 7)
  const reward = await f.definition('未来開始', { start_month: startMonth, timezone: 'Asia/Tokyo' })
  await f.status(reward, 'active', 1)
  const otherContext = await browser.newContext({
    baseURL: 'http://127.0.0.1:3100',
    timezoneId: 'America/Los_Angeles',
  })
  try {
    await otherContext.addCookies([
      { name: 'auth_token', value: f.identity.access_token, url: 'http://127.0.0.1' },
    ])
    const other = await otherContext.newPage()
    await other.goto('/rewards')
    await expect(other.getByRole('article')).not.toContainText('Asia/Tokyo')
    await expect(other.getByRole('article')).toContainText('開始前')
    await other.getByRole('button', { name: '管理', exact: true }).click()
    await other.getByRole('button', { name: '編集', exact: true }).click()
    await expect(other.getByRole('dialog')).toContainText('タイムゾーン：Asia/Tokyo')
    await other.getByRole('dialog').getByLabel('必要数', { exact: true }).fill('4')
    await other.getByRole('dialog').getByRole('button', { name: '変更を保存', exact: true }).click()
    await expect(other.getByRole('dialog')).toHaveCount(0)
    await expect(other.getByRole('article')).toContainText('4日')
    await expect(other.getByText(/次期変更あり/)).toHaveCount(0)
    const data = await (
      await page.request.get(`${f.path}/rewards/manage`, { headers: f.headers })
    ).json()
    expect(data.data[0].timezone).toBe('Asia/Tokyo')
    expect(data.data[0].starts_on).toBe(`${startMonth}-01`)
    expect(data.data[0].version.required_count).toBe(4)
    expect(data.data[0].pending_version).toBeNull()
  } finally {
    await otherContext.close()
  }
})

test('A9：有効3件・管理6件の画面制限と停止後の枠解放', async ({ page, context }) => {
  const f = await family(page.request, context)
  for (let i = 0; i < 6; i++) {
    const reward = await f.definition(`枠${i + 1}`)
    if (i < 3) await f.status(reward, 'active', 1)
  }
  await page.goto('/rewards')
  await page.getByRole('button', { name: '管理', exact: true }).click()
  await expect(page.getByText('有効 3/3件・管理 6/6件')).toBeVisible()
  await expect(page.getByRole('button', { name: 'リワードを作成', exact: true })).toBeDisabled()
  for (const button of await page.getByRole('button', { name: '有効化', exact: true }).all())
    await expect(button).toBeDisabled()
  const first = page
    .getByRole('article')
    .filter({ has: page.getByRole('heading', { name: '枠1', exact: true }) })
  await first.getByRole('button', { name: '停止', exact: true }).click()
  await expect(page.getByText('有効 2/3件・管理 6/6件')).toBeVisible()
  await expect(page.getByRole('button', { name: '有効化', exact: true }).first()).toBeEnabled()
  const draft = page
    .getByRole('article')
    .filter({ has: page.getByRole('heading', { name: '枠6', exact: true }) })
  await draft.getByRole('button', { name: '削除', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: '削除する', exact: true }).click()
  await expect(page.getByText('有効 2/3件・管理 5/6件')).toBeVisible()
  await expect(page.getByRole('button', { name: 'リワードを作成', exact: true })).toBeEnabled()
})

test('A12：二つのブラウザで通知は一方だけ、応答喪失後も再通知しない', async ({
  page,
  context,
  browser,
}) => {
  const f = await family(page.request, context)
  const reward = await f.definition('先着通知', { required_count: 1 })
  await f.status(reward, 'active', 1)
  await f.log(1)
  const second = await browser.newContext({
    baseURL: 'http://127.0.0.1:3100',
    timezoneId: 'America/Los_Angeles',
  })
  try {
    await second.addCookies([
      { name: 'auth_token', value: f.identity.access_token, url: 'http://127.0.0.1' },
    ])
    const other = await second.newPage()
    const claims = [page, other].map((p) =>
      p.waitForResponse(
        (r) => r.url().endsWith('/rewards/notifications') && r.request().method() === 'POST'
      )
    )
    await Promise.all([page.goto('/rewards'), other.goto('/rewards')])
    const responses = await Promise.all(claims)
    const sizes = await Promise.all(responses.map(async (r) => (await r.json()).data.length))
    expect(sizes.sort()).toEqual([0, 1])
    await expect
      .poll(
        async () =>
          (await page.getByRole('dialog').count()) + (await other.getByRole('dialog').count())
      )
      .toBe(1)
  } finally {
    await second.close()
  }
  await page.goto('/settings')
  const lost = await f.definition('応答喪失', { required_count: 1 })
  await f.status(lost, 'active', 1)
  let consumed = false
  await page.route('**/rewards/notifications', async (route) => {
    const response = await route.fetch()
    expect((await response.json()).data).toHaveLength(1)
    consumed = true
    await route.abort('failed')
  })
  await page.goto('/rewards')
  await expect.poll(() => consumed).toBeTruthy()
  await page.unroute('**/rewards/notifications')
  const claim = page.waitForResponse((r) => r.url().endsWith('/rewards/notifications'))
  await page.reload()
  expect((await (await claim).json()).data).toHaveLength(0)
  await expect(page.getByRole('dialog')).toHaveCount(0)
  await page.getByRole('button', { name: 'インベントリ', exact: true }).click()
  await expect(page.getByRole('article').getByRole('heading', { name: '応答喪失' })).toBeVisible()
})

test('A13・A15：集計中の閲覧・編集禁止と失敗後の再試行表示', async ({ page, context }) => {
  const f = await family(page.request, context)
  const reward = await f.definition('処理状態表示')
  await f.status(reward, 'active', 1)
  // Response overrides exercise UI states; rollback and concurrency use real database tests.
  let state: 'processing' | 'failed' | 'complete' = 'processing'
  let recoverOnRetry = false
  await page.route('**/rewards/prepare', (route) => {
    if (recoverOnRetry) state = 'complete'
    return route.fulfill({ json: { status: state } })
  })
  await page.route(/\/rewards(?:\/manage)?\?/, async (route) => {
    const response = await route.fetch()
    await route.fulfill({ response, json: { ...(await response.json()), sync_status: state } })
  })
  await page.goto('/rewards')
  await expect(page.getByText(/集計中です/)).toBeVisible()
  await expect(page.getByRole('heading', { name: '処理状態表示' })).toBeVisible()
  await page.getByRole('button', { name: '管理', exact: true }).click()
  for (const name of ['編集', '停止', 'リワードを作成'])
    await expect(page.getByRole('button', { name, exact: true })).toBeDisabled()
  state = 'failed'
  await page.reload()
  await expect(page.getByRole('alert')).toContainText('集計に失敗しました')
  recoverOnRetry = true
  await page.getByRole('button', { name: '再試行', exact: true }).click()
  await expect(page.getByText(/集計に失敗しました/)).toHaveCount(0)
  await page.getByRole('button', { name: '管理', exact: true }).click()
  await expect(page.getByRole('button', { name: '編集', exact: true })).toBeEnabled()
})
