import { expect, type Page, test } from '@playwright/test'

const owner = { id: 1, username: 'owner', display_name: '自分' }
const member = { id: 2, username: 'member', display_name: '家族' }
const group = { id: 1, name: '家族', images: { enabled: true } }
const category = { id: 1, name: '家事', color_code: '#10B981' }
const png = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lXcAAAAASUVORK5CYII=',
  'base64'
)

async function setup(page: Page) {
  let actor = owner
  await page
    .context()
    .addCookies([{ name: 'auth_token', value: 'return-test', url: 'http://127.0.0.1' }])
  const logs = Array.from({ length: 16 }, (_, i) => ({
    id: i + 1,
    user_id: i === 0 ? 2 : 1,
    category_id: 1,
    revision: 1,
    activity_date: '2026-10-15',
    activity_time: '12:00',
    content: `活動${i + 1}`,
    note: '補足メモ',
    user: i === 0 ? member : owner,
    category,
    image: { id: i + 1, width: 800, height: 800, bytes: 50, mime: 'image/png' },
    likes_count: 0,
    liked_by_me: false,
    can_like: i === 0,
  }))
  const queries: URL[] = []
  let fail = false
  let failMetadata = false
  await page.route('**/v1/**', async (route) => {
    const url = new URL(route.request().url())
    const path = url.pathname
    const method = route.request().method()
    if (path.endsWith('/status')) return route.fulfill({ json: { status: 'ok' } })
    if (path.endsWith('/auth/me') || path.endsWith('/auth/login'))
      return route.fulfill({
        json: {
          access_token: 'return-test',
          user: actor,
          membership: { id: actor.id, role: 'admin', status: 'active', group },
        },
      })
    if (path.endsWith('/groups/1')) return route.fulfill({ json: { data: group } })
    if (path.endsWith('/members')) return route.fulfill({ json: [owner, member] })
    if (path.endsWith('/categories')) {
      if (failMetadata) {
        failMetadata = false
        return route.fulfill({ status: 500, json: {} })
      }
      return route.fulfill({ json: [category] })
    }
    if (/\/images\/\d+$/.test(path)) return route.fulfill({ body: png, contentType: 'image/png' })
    if (path.endsWith('/logs/counts'))
      return route.fulfill({ json: { data: { '2026-10-15': 16 } } })
    if (/\/logs\/\d+$/.test(path)) {
      const id = Number(path.split('/').at(-1))
      const index = logs.findIndex((log) => log.id === id)
      if (method === 'DELETE') logs.splice(index, 1)
      else if (method === 'PUT') Object.assign(logs[index] ?? {}, route.request().postDataJSON())
      return route.fulfill({ json: { data: logs[index] } })
    }
    if (path.endsWith('/gallery') || path.endsWith('/logs')) {
      queries.push(url)
      if (fail) {
        fail = false
        return route.fulfill({ status: 500, json: { message: 'failed' } })
      }
      const current = Number(url.searchParams.get('page') ?? 1)
      return route.fulfill({
        json: {
          data: logs.slice((current - 1) * 4, current * 4),
          current_page: current,
          last_page: Math.max(1, Math.ceil(logs.length / 4)),
          total: logs.length,
        },
      })
    }
    return route.fulfill({ json: {} })
  })
  return {
    queries,
    setActor: (value: typeof owner) => {
      actor = value
    },
    failNext: () => {
      fail = true
    },
    failNextMetadata: () => {
      failMetadata = true
    },
  }
}

test('閲覧専用のダーク配色と、カード・画像・編集の独立した操作', async ({ page }) => {
  await setup(page)
  await page.addInitScript(() => localStorage.setItem('famie-theme', 'dark'))
  await page.goto('/')
  const card = page.locator('[data-log-id="2"]')
  await card.getByText('活動2', { exact: true }).click()
  await expect(page).toHaveURL(/\/$/)
  await card.getByRole('button', { name: '画像を拡大' }).click()
  await expect(page.getByRole('dialog', { name: '添付画像' })).toBeVisible()
  await page.getByRole('button', { name: '閉じる', exact: true }).click()
  await card.getByRole('link', { name: /記録を編集/ }).click()
  await expect(page.getByRole('link', { name: '← タイムラインへ' })).toBeVisible()
  await page.getByRole('navigation').getByRole('link', { name: 'ギャラリー' }).click()
  await page
    .locator('[data-log-id="1"]')
    .getByRole('link', { name: 'アクティビティを見る' })
    .click()
  await expect(page.getByRole('link', { name: '← ギャラリーへ' })).toBeVisible()
  for (const theme of ['light', 'dark']) {
    await page.evaluate(
      (value) => document.documentElement.setAttribute('data-theme', value),
      theme
    )
    for (const field of await page
      .locator('.activity-detail input, .activity-detail textarea, .activity-detail select')
      .all()) {
      await expect(field).toBeDisabled()
      await expect(field).toHaveCSS(
        'background-color',
        theme === 'dark' ? /^oklch\(0\.208 / : /^oklch\(0\.968 /
      )
      const colors = await field.evaluate((element) => {
        const css = getComputedStyle(element)
        return {
          background: css.backgroundColor,
          text: css.color,
          fill: css.webkitTextFillColor,
          opacity: css.opacity,
        }
      })
      expect(colors.text).not.toBe(colors.background)
      expect(colors.fill).not.toBe(colors.background)
      expect(colors.opacity).toBe('1')
    }
  }
  await expect(page.getByRole('button', { name: '更新する' })).toHaveCount(0)
})

for (const path of ['/', '/gallery']) {
  test(`${path}: randomUUIDが利用できない環境でも詳細から元の一覧へ戻れる`, async ({ page }) => {
    await page.addInitScript(() => {
      Object.defineProperty(crypto, 'randomUUID', { configurable: true, value: undefined })
    })
    await setup(page)
    const errors: string[] = []
    page.on('pageerror', (error) => errors.push(error.message))
    await page.goto(path)
    await page.getByRole('combobox').last().selectOption('1')
    const card = page.locator('[data-log-id="2"]')
    await card.getByRole('link').click()
    await expect(page).toHaveURL(/\/logs\/2\?back=[0-9a-f]{32}$/)
    await page
      .getByRole('link', { name: path === '/' ? '← タイムラインへ' : '← ギャラリーへ' })
      .click()
    await expect(card).toBeVisible()
    await expect(page.getByRole('combobox').last()).toHaveValue('1')
    expect(errors).toEqual([])
  })

  test(`${path}: 復帰時のカテゴリ取得失敗でも条件を残して再試行できる`, async ({ page }) => {
    const { failNextMetadata } = await setup(page)
    await page.goto(path)
    await page.getByRole('combobox').last().selectOption('1')
    const card = page.locator('[data-log-id="2"]')
    await card.getByRole('link').click()
    await expect(page.getByRole('button', { name: '更新する', exact: true })).toBeVisible()
    failNextMetadata()
    await page
      .getByRole('link', { name: path === '/' ? '← タイムラインへ' : '← ギャラリーへ' })
      .click()
    await expect(page.getByRole('alert')).toBeVisible()
    await page.getByRole('button', { name: '再試行' }).click()
    await expect(card).toBeVisible()
    await expect(page.getByRole('combobox').last()).toHaveValue('1')
  })
}

test('新規タブでは復帰情報を共有せず、元タブの情報も壊さない', async ({ page, context }) => {
  await setup(page)
  await page.goto('/gallery')
  await page.getByRole('combobox').last().selectOption('1')
  await page.locator('[data-log-id="2"]').getByRole('link').click()
  await expect(page.getByRole('link', { name: '← ギャラリーへ' })).toBeVisible()
  const other = await context.newPage()
  try {
    await setup(other)
    await other.goto(page.url())
    await expect(other.getByRole('link', { name: '← タイムラインへ' })).toHaveAttribute('href', '/')
  } finally {
    await other.close()
  }
  await page.getByRole('link', { name: '← ギャラリーへ' }).click()
  await expect(page.getByRole('combobox').last()).toHaveValue('1')
})

test('ギャラリーの追加読込・条件・カード位置を戻る／保存後に復元し、フッターで初期化する', async ({
  page,
}) => {
  const { queries } = await setup(page)
  await page.goto('/gallery')
  await page.getByRole('button', { name: '月間', exact: true }).click()
  await page.getByRole('combobox').first().selectOption('1')
  await page.getByRole('combobox').last().selectOption('1')
  const root = page.locator('.app-scroll-area')
  await root.evaluate((element) => element.scrollTo(0, element.scrollHeight))
  const card = page.locator('[data-log-id="6"]')
  await card.scrollIntoViewIfNeeded()
  const before = await card.evaluate((element) => element.getBoundingClientRect().top)
  await card.getByRole('link').click()
  await page.getByRole('link', { name: '← ギャラリーへ' }).click()
  await expect(card).toBeVisible()
  await expect(page.getByRole('combobox').first()).toHaveValue('1')
  await expect(page.getByRole('combobox').last()).toHaveValue('1')
  await expect
    .poll(async () =>
      Math.abs(((await card.boundingBox())?.y ?? Number.POSITIVE_INFINITY) - before)
    )
    .toBeLessThan(4)
  expect(
    queries.filter(
      (url) => url.pathname.endsWith('/gallery') && url.searchParams.get('page') === '2'
    ).length
  ).toBeGreaterThanOrEqual(2)
  await card.getByRole('link').click()
  await page.getByRole('button', { name: '更新する', exact: true }).click()
  await expect(page.getByRole('heading', { name: '画像ギャラリー' })).toBeVisible()
  await expect(page.getByRole('combobox').last()).toHaveValue('1')
  await card.getByRole('link').click()
  await page.getByRole('navigation').getByRole('link', { name: 'ギャラリー' }).click()
  await expect(page.getByRole('combobox').last()).toHaveValue('')
  await expect(page.getByRole('button', { name: '週間', exact: true })).toHaveAttribute(
    'aria-pressed',
    'true'
  )
})

test('タイムラインの指定期間・ページをブラウザ戻るで復元し、直リンクは既定の戻り先にする', async ({
  page,
}) => {
  const { queries } = await setup(page)
  await page.goto('/')
  await page.getByRole('button', { name: '指定', exact: true }).click()
  await page.getByLabel('開始日').fill('2026-10-01')
  await page.getByLabel('終了日').fill('2026-10-31')
  await page.getByRole('button', { name: '適用', exact: true }).click()
  await page.getByRole('button', { name: '次へ', exact: true }).click()
  await page
    .locator('[data-log-id="6"]')
    .getByRole('link', { name: /記録を編集/ })
    .click()
  await expect(page).toHaveURL(/\/logs\/6/)
  await page.goBack()
  await expect(page.getByLabel('開始日')).toHaveValue('2026-10-01')
  await expect(page.locator('[data-log-id="6"]')).toBeVisible()
  expect(queries.at(-1)?.searchParams.get('page')).toBe('2')
  await page.goto('/logs/6?back=https://example.com')
  await expect(page.getByRole('link', { name: '← タイムラインへ' })).toHaveAttribute('href', '/')
})

test('ギャラリー復帰の通信失敗を再試行し、削除後も条件を維持する', async ({ page }) => {
  const { failNext } = await setup(page)
  await page.goto('/gallery')
  await page.getByRole('button', { name: '月間', exact: true }).click()
  await page.getByRole('combobox').last().selectOption('1')
  const card = page.locator('[data-log-id="2"]')
  await card.getByRole('link').click()
  failNext()
  await page.getByRole('link', { name: '← ギャラリーへ' }).click()
  await expect(page.getByRole('alert')).toBeVisible()
  await page.getByRole('button', { name: '再試行' }).click()
  await expect(card).toBeVisible()
  await card.getByRole('link').click()
  page.once('dialog', (dialog) => dialog.accept())
  await page.getByRole('button', { name: '削除', exact: true }).click()
  await expect(page.getByRole('heading', { name: '画像ギャラリー' })).toBeVisible()
  await expect(card).toHaveCount(0)
  await expect(page.getByRole('combobox').last()).toHaveValue('1')
  await expect(page.getByRole('button', { name: '月間', exact: true })).toHaveAttribute(
    'aria-pressed',
    'true'
  )
})

test('月表示の日別選択を保持し、フッター経由では規定値へ戻す', async ({ page }) => {
  await setup(page)
  await page.clock.setFixedTime(new Date('2026-10-15T12:00:00+09:00'))
  await page.goto('/')
  await page.getByRole('button', { name: '月表示', exact: true }).click()
  await page.getByRole('button', { name: /2026年10月15日/ }).click()
  const card = page.locator('[data-log-id="2"]')
  await card.getByRole('link', { name: /記録を編集/ }).click()
  await page.getByRole('link', { name: '← タイムラインへ' }).click()
  await expect(page.getByRole('button', { name: 'カレンダーに戻る' })).toBeVisible()
  await expect(card).toBeVisible()
  await card.getByRole('link', { name: /記録を編集/ }).click()
  await page.getByRole('navigation').getByRole('link', { name: 'タイムライン' }).click()
  await expect(page.getByRole('region', { name: '週カレンダー' })).toBeVisible()
})

test('ログアウト後に別ユーザーで入ると、過去の詳細履歴に戻っても復帰情報を引き継がない', async ({
  page,
}) => {
  const { setActor } = await setup(page)
  await page.goto('/gallery')
  await page.getByRole('combobox').last().selectOption('1')
  await page.locator('[data-log-id="2"]').getByRole('link').click()
  await expect(page.getByRole('link', { name: '← ギャラリーへ' })).toBeVisible()
  const detailPosition = await page.evaluate(() => window.history.state.position as number)
  await page.getByRole('navigation').getByRole('link', { name: '設定', exact: true }).click()
  await page.getByRole('button', { name: 'ログアウト', exact: true }).click()
  await expect(page).toHaveURL(/\/login$/)
  setActor(member)
  await page.getByPlaceholder('例: parent1').fill('member')
  await page.getByLabel('パスワード', { exact: true }).fill('test-password')
  await page.getByRole('button', { name: 'ログイン', exact: true }).click()
  await expect(page).toHaveURL(/\/$/)
  await page.evaluate(
    (position) => window.history.go(position - window.history.state.position),
    detailPosition
  )
  await expect(page.getByRole('link', { name: '← タイムラインへ' })).toHaveAttribute('href', '/')
  await expect(page.getByText('この記録は閲覧のみ可能です。')).toBeVisible()
})

for (const path of ['/', '/gallery']) {
  test(`${path}: 一度復帰して条件を変更した後も、直前の条件でブラウザ戻るできる`, async ({
    page,
  }) => {
    await setup(page)
    await page.goto(path)
    await page.getByRole('combobox').last().selectOption('1')
    const card = page.locator('[data-log-id="2"]')
    const label = path === '/' ? '← タイムラインへ' : '← ギャラリーへ'
    await card.getByRole('link').click()
    await page.getByRole('link', { name: label }).click()
    await expect(page.getByRole('combobox').last()).toHaveValue('1')
    await page.getByRole('combobox').last().selectOption('')
    await card.getByRole('link').click()
    await expect(page.getByRole('link', { name: label })).toBeVisible()
    await page.goBack()
    await expect(page.getByRole('combobox').last()).toHaveValue('')
    await expect(card).toBeVisible()
  })
}
