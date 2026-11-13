import { expect, test } from '@playwright/test'
import { deferred, family, mockApi } from '../support/group-navigation'

test.beforeEach(async ({ context, baseURL }) => {
  test.skip(
    !baseURL || !['127.0.0.1', 'localhost'].includes(new URL(baseURL).hostname),
    'Synthetic accounts run locally only'
  )
  await context.addCookies([
    { name: 'auth_token', value: 'synthetic-initial-token', url: baseURL as string },
  ])
})

for (const enabled of [true, false]) {
  test(`家族設定の確定前は起動画面を維持し、最初から${enabled ? 4 : 3}列で表示する`, async ({
    page,
  }) => {
    const identity = family(901, enabled)
    let gate = deferred()
    let requests = 0
    await mockApi(page, identity, async (route) => {
      requests++
      await gate.wait
      await route.fulfill({ json: { data: identity.membership.group } })
    })
    await page.goto('/licenses/', { waitUntil: 'domcontentloaded' })
    await expect.poll(() => requests).toBe(1)
    await expect(page.getByRole('navigation')).toHaveCount(0)
    await expect(page.getByRole('status').filter({ hasText: 'Famie' })).toBeVisible()
    gate.release()
    await expect(page.locator('nav > div.grid')).toHaveClass(
      new RegExp(`grid-cols-${enabled ? 4 : 3}`)
    )
    await expect(page.locator('nav a[href="/gallery"]')).toHaveCount(enabled ? 1 : 0)
    expect(requests).toBe(1)
    if (enabled) {
      gate = deferred()
      await page.goto('/legal/privacy/?from=settings')
      await page.locator('#legal-back').click()
      await expect.poll(() => requests).toBe(2)
      await expect(page.getByRole('navigation')).toHaveCount(0)
      gate.release()
      await expect(page.locator('nav > div.grid')).toHaveClass(/grid-cols-4/)
      expect(requests).toBe(2)
    }
  })
}

test('文書モーダル・通常遷移は再取得せず、設定再訪時は最新の表示条件を反映する', async ({
  page,
}) => {
  const identity = family(901, true)
  let requests = 0
  await mockApi(page, identity, async (route) => {
    requests++
    await route.fulfill({ json: { data: identity.membership.group } })
  })
  await page.goto('/settings/')
  const about = page.locator('details').filter({ has: page.locator('a[href="/licenses"]') })
  await about.locator('summary').click()
  await about.locator('a[href^="/legal/privacy/"]').click()
  const document = page.frameLocator('dialog iframe')
  await document.locator('article a[href^="/legal/cookies/"]').click()
  await document.locator('#legal-back').click()
  await expect(page.locator('dialog')).toHaveCount(0)
  await expect(page.locator('nav > div.grid')).toHaveClass(/grid-cols-4/)
  expect(requests).toBe(1)
  await about.locator('a[href="/licenses"]').click()
  await expect(page.locator('pre')).toBeVisible()
  expect(requests).toBe(1)
  identity.membership.group.images.enabled = false
  await page.locator('nav a[href="/settings"]').click()
  await expect(page.locator('nav > div.grid')).toHaveClass(/grid-cols-3/)
  expect(requests).toBe(2)
})

test('設定保存の応答を共有し、フッターへ直ちに反映する', async ({ page }) => {
  const identity = family(901, true, true)
  await mockApi(page, identity, async (route) => {
    if (route.request().method() === 'PATCH') {
      identity.membership.group.images.enabled = false
      identity.membership.group.name = route.request().postDataJSON().name
    }
    await route.fulfill({ json: { data: identity.membership.group } })
  })
  await page.goto('/settings/')
  const card = page
    .locator('details')
    .filter({ has: page.getByRole('heading', { name: '家族設定', exact: true }) })
  await card.locator('summary').focus()
  await card.locator('summary').press('Enter')
  await page.locator('#group-name').fill('Updated family')
  await card.getByRole('button', { name: '変更', exact: true }).click()
  await expect(page.locator('nav > div.grid')).toHaveClass(/grid-cols-3/)
})

for (const language of ['ja', 'en']) {
  test(`家族設定の失敗をOFFとみなさず、再試行で復帰できる: ${language}`, async ({ page }) => {
    const identity = family(901, true)
    identity.user.ui_locale = language
    let requests = 0
    await mockApi(page, identity, async (route) => {
      requests++
      if (requests === 1)
        return route.fulfill({ status: 503, json: { message: 'Synthetic failure' } })
      await route.fulfill({ json: { data: identity.membership.group } })
    })
    await page.goto('/licenses/')
    await expect(page.getByRole('alert')).toContainText(
      language === 'ja' ? '家族の設定を読み込めませんでした' : 'Could not load family settings'
    )
    await expect(page.getByRole('navigation')).toHaveCount(0)
    await page.getByRole('alert').getByRole('button').click()
    await expect(page.locator('nav > div.grid')).toHaveClass(/grid-cols-4/)
    expect(requests).toBe(2)
  })
}

test('通信が完了しなくてもタイムアウトし、無限待機せず再試行できる', async ({ page }) => {
  const identity = family(901, false)
  const gate = deferred()
  let requests = 0
  await mockApi(page, identity, async (route) => {
    requests++
    if (requests === 1) await gate.wait
    await route.fulfill({ json: { data: identity.membership.group } }).catch(() => {})
  })
  await page.goto('/licenses/', { waitUntil: 'domcontentloaded' })
  await expect(page.getByRole('alert')).toContainText('家族の設定を読み込めませんでした', {
    timeout: 20000,
  })
  await page.getByRole('button', { name: '再試行', exact: true }).click()
  await expect(page.locator('nav > div.grid')).toHaveClass(/grid-cols-3/)
  gate.release()
  expect(requests).toBe(2)
})

test('法務確認が必要な利用者では家族設定APIを先行取得しない', async ({ page }) => {
  const identity = family(901, true)
  identity.legal.required = true
  let requests = 0
  await mockApi(page, identity, async (route) => {
    requests++
    await route.fulfill({ json: { data: identity.membership.group } })
  })
  await page.goto('/licenses/')
  await expect(page).toHaveURL(/\/legal-confirmation\/?$/)
  await expect(page.locator('fieldset')).toBeVisible()
  expect(requests).toBe(0)
})

for (const status of [401, 428]) {
  test(`家族設定取得中の${status}でも認証・同意画面へ移動できる`, async ({ page }) => {
    const identity = family(901, true)
    let requests = 0
    await mockApi(page, identity, async (route) => {
      requests++
      await route.fulfill({
        status,
        json:
          status === 428
            ? { code: 'legal_acceptance_required' }
            : { message: 'Synthetic session expired' },
      })
    })
    await page.goto('/licenses/')
    await expect(page).toHaveURL(status === 401 ? /\/login\/?$/ : /\/legal-confirmation\/?$/)
    await expect(page.locator('nav > div.grid')).toHaveCount(0)
    if (status === 428) await expect(page.locator('fieldset')).toBeVisible()
    expect(requests).toBe(1)
  })
}

test('不正な表示条件を成功値のOFFへ変換しない', async ({ page }) => {
  const identity = family(901, true)
  await mockApi(page, identity, async (route) => {
    await route.fulfill({
      json: { data: { ...identity.membership.group, images: { enabled: 'invalid' } } },
    })
  })
  await page.goto('/licenses/')
  await expect(page.getByRole('alert')).toContainText('家族の設定を読み込めませんでした')
  await expect(page.locator('nav > div.grid')).toHaveCount(0)
})
