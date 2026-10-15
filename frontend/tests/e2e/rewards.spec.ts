import { expect, test } from '@playwright/test'

test('親が定義を作成し、獲得通知・履歴保持と子の権限を確認できる', async ({ page, context }) => {
  test.setTimeout(60_000)
  const username = `reward${Date.now()}${Math.floor(Math.random() * 10000)}`
  const password = 'Famie-E2E-only-123!'
  const registered = await page.request.post('/api/v1/auth/register', {
    data: {
      username,
      display_name: 'リワードの親',
      group_name: 'リワードテスト',
      password,
      password_confirmation: password,
    },
  })
  expect(registered.ok()).toBeTruthy()
  const identity = await registered.json()
  const path = `/api/v1/groups/${identity.membership.group.id}`
  const headers = { Authorization: `Bearer ${identity.access_token}` }
  for (const [suffix, displayName, inactive] of [
    ['target', '選択対象の子', false],
    ['off', '無効な子', true],
  ] as const) {
    const created = await page.request.post(`${path}/members`, {
      headers,
      data: {
        username: `${username}${suffix}`,
        display_name: displayName,
        role: 'member',
        password,
        password_confirmation: password,
      },
    })
    expect(created.ok()).toBeTruthy()
    if (inactive) {
      const member = (await created.json()).data
      expect(
        (
          await page.request.put(`${path}/members/${member.membership_id}/status`, {
            headers,
            data: { status: 'inactive' },
          })
        ).ok()
      ).toBeTruthy()
    }
  }
  await context.addCookies([
    { name: 'auth_token', value: identity.access_token, url: 'http://127.0.0.1' },
  ])
  await page.goto('/rewards')
  for (const label of ['条件・進捗', 'インベントリ', '管理']) {
    const icon = page
      .getByRole('button', { name: label, exact: true })
      .locator('[aria-hidden="true"]')
    await expect(icon).toBeVisible()
    await expect
      .poll(() => icon.evaluate((element) => getComputedStyle(element).maskImage))
      .not.toBe('none')
  }
  await page.getByRole('button', { name: '管理', exact: true }).click()
  const originalViewport = page.viewportSize()
  await page.setViewportSize({ width: 320, height: 740 })
  await page.getByRole('button', { name: 'リワードを作成', exact: true }).click()
  const dialog = page.getByRole('dialog')
  const expectInputsToFit = async () => {
    const bounds = await dialog.evaluate((element) => {
      const dialogRect = element.getBoundingClientRect()
      return {
        scroll: element.scrollWidth - element.clientWidth,
        outside: [...element.querySelectorAll('input,select,textarea')].some((input) => {
          const rect = input.getBoundingClientRect()
          return rect.left < dialogRect.left || rect.right > dialogRect.right
        }),
      }
    })
    expect(bounds.scroll).toBeLessThanOrEqual(1)
    expect(bounds.outside).toBe(false)
  }
  await expectInputsToFit()
  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'))
  const option = dialog.getByLabel('条件指標').locator('option').first()
  await expect(option).toHaveCSS('background-color', 'rgb(30, 41, 59)')
  await expect(option).toHaveCSS('color', 'rgb(241, 245, 249)')
  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'))
  await expect(option).toHaveCSS('background-color', 'rgb(255, 255, 255)')
  await expect(option).toHaveCSS('color', 'rgb(15, 23, 42)')
  await dialog.getByLabel('名称', { exact: true }).fill('初めての活動')
  await dialog.getByLabel('報酬の説明').fill('好きなおやつ')
  await dialog.getByLabel('必要数', { exact: true }).fill('1')
  const trophySelect = dialog.getByRole('combobox', { name: 'トロフィー', exact: true })
  await trophySelect.click()
  for (const name of ['金トロフィー', '銀トロフィー', '銅トロフィー']) {
    await expect(dialog.getByRole('option', { name, exact: true })).toBeVisible()
  }
  await trophySelect.press('Escape')
  await expect(dialog).toBeVisible()
  await expect(dialog.getByRole('listbox')).toHaveCount(0)
  await trophySelect.press('ArrowDown')
  await page.keyboard.press('Home')
  await page.keyboard.press('Enter')
  await expect(trophySelect).toContainText('金トロフィー')
  await expect(dialog.getByRole('checkbox')).toHaveCount(2)
  await expect(dialog.getByText('無効な子', { exact: false })).toHaveCount(0)
  for (const checkbox of await dialog.getByRole('checkbox').all())
    await expect(checkbox).toBeChecked()
  await dialog.getByRole('button', { name: '全チェック解除', exact: true }).click()
  for (const checkbox of await dialog.getByRole('checkbox').all())
    await expect(checkbox).not.toBeChecked()
  await dialog.getByRole('button', { name: '全選択', exact: true }).click()
  for (const checkbox of await dialog.getByRole('checkbox').all())
    await expect(checkbox).toBeChecked()
  await dialog.getByRole('checkbox', { name: '選択対象の子（子）' }).uncheck()
  const draftReady = page
    .waitForResponse(
      (response) =>
        response.url().endsWith('/rewards/prepare') && response.request().method() === 'POST'
    )
    .then(() =>
      page.waitForResponse(
        (response) =>
          response.url().includes('/rewards/manage?') && response.request().method() === 'GET'
      )
    )
  await dialog.getByRole('button', { name: '下書きとして保存', exact: true }).click()
  await draftReady
  await expect(dialog).toHaveCount(0)
  await expect(page.getByText('下書き', { exact: true })).toBeVisible()
  const badges = page.getByRole('article').locator(':scope > div').first()
  await expect(badges).toContainText('金トロフィー')
  await expect(badges).toContainText('下書き')
  await expect(page.getByRole('article')).not.toContainText('Asia/Tokyo')
  await page.getByRole('button', { name: '編集', exact: true }).click()
  await expect(dialog.getByRole('button', { name: '変更を保存', exact: true })).toBeVisible()
  await expect(dialog.getByText(/タイムゾーン：/)).toBeVisible()
  await expectInputsToFit()
  await expect(dialog.getByRole('checkbox', { name: '選択対象の子（子）' })).not.toBeChecked()
  await expect(dialog.getByRole('checkbox', { name: 'リワードの親（親）' })).toBeChecked()
  await trophySelect.click()
  await dialog.getByRole('option', { name: '銀トロフィー', exact: true }).click()
  await expect(trophySelect).toContainText('銀トロフィー')
  await dialog.getByRole('button', { name: '閉じる', exact: true }).click()
  if (originalViewport) await page.setViewportSize(originalViewport)
  const trophy = page.getByRole('article').getByRole('heading').locator('[style*="mask"]')
  await expect(trophy).toBeVisible()
  await expect
    .poll(() => trophy.evaluate((element) => getComputedStyle(element).maskImage))
    .not.toBe('none')
  await page.getByRole('button', { name: '有効化', exact: true }).click()
  await expect(page.getByText('有効', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: '編集', exact: true }).click()
  await expect(dialog.getByLabel('開始月')).toBeDisabled()
  await expect(dialog.getByLabel('繰り返し周期（月）')).toBeDisabled()
  await expect(
    dialog.getByText('開始月・繰り返し周期は、期間開始後に一度でも有効化すると変更できません。')
  ).toBeVisible()
  await dialog.getByRole('button', { name: '閉じる', exact: true }).click()
  const categories = await (await page.request.get(`${path}/categories`, { headers })).json()
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Tokyo',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date())
  const date = ['year', 'month', 'day']
    .map((type) => parts.find((p) => p.type === type)?.value)
    .join('-')
  await page.goto('/settings')
  const log = await page.request.post(`${path}/logs`, {
    headers,
    data: { category_id: categories[0].id, activity_date: date, content: '達成する活動' },
  })
  expect(log.ok()).toBeTruthy()
  await page.goto('/rewards')
  await expect(page.getByRole('dialog', { name: 'リワードを獲得しました！' })).toBeVisible()
  await expect(page.getByRole('dialog').getByText('金トロフィー')).toBeVisible()
  await page.getByRole('dialog').getByRole('button', { name: '閉じる', exact: true }).click()
  await page.reload()
  await expect(page.getByRole('dialog')).toHaveCount(0)
  await expect(page.getByText(/獲得済み/)).toBeVisible()
  await expect(
    page.getByRole('article').locator(':scope > div').first().getByText('獲得済み', { exact: true })
  ).toHaveClass(/bg-red-600.*text-white/)
  await expect(page.getByRole('article')).not.toContainText('獲得時')
  await page.getByRole('button', { name: '管理', exact: true }).click()
  await page.getByRole('button', { name: '停止', exact: true }).click()
  await page.getByRole('button', { name: '削除', exact: true }).click()
  await expect(page.getByRole('dialog').getByText(/獲得履歴は残ります/)).toBeVisible()
  await page.getByRole('dialog').getByRole('button', { name: '削除する', exact: true }).click()
  await page.getByRole('button', { name: 'インベントリ', exact: true }).click()
  await expect(page.getByText('削除済みリワード', { exact: true })).toBeVisible()
  await expect(page.getByText('好きなおやつ', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: '管理', exact: true }).click()
  await page.getByRole('button', { name: 'リワードを作成', exact: true }).click()
  await page.getByRole('dialog').getByLabel('名称', { exact: true }).fill('皆勤賞')
  await page.getByRole('dialog').getByLabel('報酬の説明').fill('毎日の活動')
  await page
    .getByRole('dialog')
    .getByLabel('必要数の指定', { exact: true })
    .selectOption('period_days')
  await expect(page.getByRole('dialog').getByLabel('必要数', { exact: true })).toHaveCount(0)
  for (const checkbox of await page.getByRole('dialog').getByRole('checkbox').all())
    await expect(checkbox).toBeChecked()
  const dynamicSaved = page.waitForResponse(
    (response) => response.url().endsWith('/rewards') && response.request().method() === 'POST'
  )
  await page
    .getByRole('dialog')
    .getByRole('button', { name: '下書きとして保存', exact: true })
    .click()
  const dynamic = await (await dynamicSaved).json()
  expect(dynamic.data.version.count_mode).toBe('period_days')
  expect(dynamic.data.version.required_count).toBe(
    new Date(Number(date.slice(0, 4)), Number(date.slice(5, 7)), 0).getDate()
  )
  await expect(page.getByRole('article').getByRole('heading', { name: '皆勤賞' })).toBeVisible()
  await expect(page.getByRole('button', { name: '停止', exact: true })).toHaveCount(0)
  await page.getByRole('button', { name: '削除', exact: true }).click()
  await expect(page.getByRole('dialog').getByText(/完全に削除/)).toBeVisible()
  await page.getByRole('dialog').getByRole('button', { name: '削除する', exact: true }).click()
  await expect(page.getByRole('heading', { name: '皆勤賞' })).toHaveCount(0)
  const childUsername = `${username}kid`
  expect(
    (
      await page.request.post(`${path}/members`, {
        headers,
        data: {
          username: childUsername,
          display_name: 'リワードの子',
          role: 'member',
          password,
          password_confirmation: password,
        },
      })
    ).ok()
  ).toBeTruthy()
  const child = await (
    await page.request.post('/api/v1/auth/login', { data: { login: childUsername, password } })
  ).json()
  await context.addCookies([
    { name: 'auth_token', value: child.access_token, url: 'http://127.0.0.1' },
  ])
  await page.reload()
  await expect(page.getByRole('button', { name: '管理', exact: true })).toHaveCount(0)
  await expect(page.getByText('対象のリワードはまだありません。')).toBeVisible()
})
