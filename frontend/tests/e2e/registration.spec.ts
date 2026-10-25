import { expect, test } from '@playwright/test'

for (const locale of ['ja', 'en'] as const) {
  test(`禁止名は入力を保持して利用不可と表示し、言語切替でも維持する（${locale}）`, async ({
    page,
  }) => {
    await page.addInitScript(
      (language) => localStorage.setItem('famie.uiLocale.guest', language),
      locale
    )
    await page.goto('/register')
    const username = page.locator('input[autocomplete="username"]')
    const family = page.getByLabel(locale === 'ja' ? '家族名' : 'Family name', { exact: true })
    await family.fill('Retained family')
    for (const name of ['admin', 'famie_support']) {
      const checked = page.waitForResponse(
        (response) =>
          response.url().endsWith('/username-availability') &&
          response.request().postDataJSON().username === name
      )
      await username.fill(name)
      expect(await (await checked).json()).toEqual({ available: false })
      await expect(page.locator('#username-status')).toHaveText(
        locale === 'ja'
          ? 'このアカウント名は使用できません。別の名前を入力してください。'
          : 'This username is unavailable. Choose another name.'
      )
      await expect(family).toHaveValue('Retained family')
    }
    await page
      .getByRole('button', { name: locale === 'ja' ? '言語' : 'Language', exact: true })
      .click()
    await page.getByRole('combobox').selectOption(locale === 'ja' ? 'en' : 'ja')
    await expect(page.locator('#username-status')).toHaveText(
      locale === 'ja'
        ? 'This username is unavailable. Choose another name.'
        : 'このアカウント名は使用できません。別の名前を入力してください。'
    )
    await expect(username).toHaveValue('famie_support')
  })
}

test('利用可否の遅い失敗応答で新しい不正形式の入力状態を上書きしない', async ({ page }) => {
  let releaseResponse: (() => void) | undefined
  const pending = new Promise<void>((resolve) => {
    releaseResponse = resolve
  })
  await page.route('**/auth/register/username-availability', async (route) => {
    await pending
    await route.fulfill({ status: 503, json: { message: 'Unavailable' } })
  })
  await page.goto('/register')
  const username = page.getByLabel('ログインID', { exact: true })
  const request = page.waitForRequest('**/auth/register/username-availability')
  await username.fill('old-family')
  await request
  await username.fill('A')
  const response = page.waitForResponse('**/auth/register/username-availability')
  releaseResponse?.()
  await response
  await expect(page.locator('#username-status')).toHaveCount(0)
  await expect(username).toHaveValue('A')
})

test('新しい親が家族を登録し、家族名を変更できる', async ({ page }) => {
  const suffix = Date.now().toString()
  await page.goto('/login')
  await page.getByRole('link', { name: '新しい家族を登録' }).click()
  await page.getByLabel('家族名').fill('新規家族')
  await page.getByLabel('ログインID').fill('parent1')
  await expect(
    page.getByText('このアカウント名は使用できません。別の名前を入力してください。', {
      exact: true,
    })
  ).toBeVisible()
  await page.getByLabel('ログインID').fill(`new-parent-${suffix}`)
  await expect(page.getByText('このログインIDは利用できます。', { exact: true })).toBeVisible()
  await page.getByLabel('親アカウントの表示名').fill('新しい親')
  await page.getByLabel('パスワード', { exact: true }).fill('registration-password')
  await page.getByLabel('パスワード（確認）', { exact: true }).fill('registration-password')
  await page.getByRole('button', { name: '家族を登録する' }).click()

  await expect(page.getByRole('button', { name: 'アクティビティを記録する' })).toBeVisible()
  await page.getByRole('link', { name: '設定', exact: true }).click()
  await page.locator('summary').filter({ hasText: '家族設定' }).click()
  await expect(page.getByLabel('家族名')).toHaveValue('新規家族')
  await page.getByLabel('家族名').fill('変更後の家族名')
  await page
    .getByLabel('家族名')
    .locator('..')
    .getByRole('button', { name: '変更', exact: true })
    .click()
  await expect(page.getByText('家族名を変更しました。', { exact: true })).toBeVisible()
  await expect(page.getByLabel('家族名')).toHaveValue('変更後の家族名')
})

test('英語ゲストで登録した初期カテゴリは本人の自動言語へ戻っても原文を保持する', async ({
  page,
}) => {
  await page.addInitScript(() => localStorage.setItem('famie.uiLocale.guest', 'en'))
  await page.goto('/register')
  await page.getByLabel('Family name', { exact: true }).fill('English preset family')
  await page.getByLabel('Login ID', { exact: true }).fill(`english-family-${Date.now()}`)
  await page.getByLabel("Parent's display name", { exact: true }).fill('Original parent')
  await page.getByLabel('Password', { exact: true }).fill('registration-password')
  await page.getByLabel('Confirm password', { exact: true }).fill('registration-password')
  const registered = page.waitForResponse(
    (response) =>
      response.url().endsWith('/auth/register') && response.request().method() === 'POST'
  )
  await page.getByRole('button', { name: 'Register family', exact: true }).click()
  const response = await registered
  expect(response.status()).toBe(201)
  expect(response.request().headers()['accept-language']).toBe('en')
  const account = await response.json()
  expect(account.user.ui_locale).toBeNull()
  await expect(
    page.getByRole('button', { name: 'アクティビティを記録する', exact: true })
  ).toBeVisible()
  const categories = await page.request.get(
    `/api/v1/groups/${account.membership.group.id}/categories`,
    { headers: { Authorization: `Bearer ${account.access_token}`, 'Accept-Language': 'ja' } }
  )
  expect(categories.ok()).toBeTruthy()
  expect((await categories.json()).map((category: { name: string }) => category.name)).toEqual([
    'Study & homework',
    'Chores & helping out',
    'Exercise & sports',
    'Lessons & activities',
    'Health & daily life',
    'Outings',
    'Other',
  ])
})
