import { expect, test } from '@playwright/test'
import en from '../../i18n/locales/en.json' with { type: 'json' }
import ja from '../../i18n/locales/ja.json' with { type: 'json' }

for (const locale of ['ja', 'en'] as const) {
  test(`${locale}: 編集の即時反映と予約条件の保持、獲得通知を確認する`, async ({
    page,
    context,
  }) => {
    const labels = (locale === 'ja' ? ja : en).reward
    await page.addInitScript((locale) => {
      Object.defineProperty(navigator, 'languages', { configurable: true, get: () => [locale] })
      Object.defineProperty(navigator, 'language', { configurable: true, get: () => locale })
    }, locale)
    const username = `immediate${Date.now()}${Math.floor(Math.random() * 10000)}`
    const password = 'Famie-E2E-only-123!'
    const registered = await page.request.post('/api/v1/auth/register', {
      data: {
        username,
        display_name: 'Immediate parent',
        group_name: 'Immediate family',
        password,
        password_confirmation: password,
      },
    })
    expect(registered.ok()).toBeTruthy()
    const identity = await registered.json()
    const path = `/api/v1/groups/${identity.membership.group.id}`
    const headers = { Authorization: `Bearer ${identity.access_token}` }
    const child = await page.request.post(`${path}/members`, {
      headers,
      data: {
        username: `${username}kid`,
        display_name: 'Immediate child',
        role: 'member',
        password,
        password_confirmation: password,
      },
    })
    expect(child.ok()).toBeTruthy()
    const member = (await child.json()).data
    const categories = await (await page.request.get(`${path}/categories`, { headers })).json()
    const today = new Date().toISOString().slice(0, 10)
    expect(
      (
        await page.request.post(`${path}/logs`, {
          headers,
          data: {
            category_id: categories[0].id,
            activity_date: today,
            content: 'Before joining the reward',
          },
        })
      ).ok()
    ).toBeTruthy()
    const created = await page.request.post(`${path}/rewards`, {
      headers,
      data: {
        name: 'Original reward',
        description: 'Original description',
        metric: 'activities',
        color: 'bronze',
        required_count: 1,
        target_ids: [member.membership_id],
        start_month: today.slice(0, 7),
        cycle_months: 1,
        timezone: 'UTC',
      },
    })
    expect(created.ok()).toBeTruthy()
    const reward = (await created.json()).data
    expect(
      (
        await page.request.put(`${path}/rewards/${reward.id}/status`, {
          headers,
          data: { revision: 1, status: 'active' },
        })
      ).ok()
    ).toBeTruthy()
    await context.addCookies([
      { name: 'auth_token', value: identity.access_token, url: 'https://127.0.0.1' },
    ])
    await page.goto('/rewards')
    await page.getByRole('button', { name: labels.manage, exact: true }).click()
    await page.getByRole('button', { name: labels.edit, exact: true }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(labels.immediate, { exact: true })).toHaveCount(3)
    await expect(dialog.getByText(labels.nextCycleHelp, { exact: true })).toBeVisible()
    await dialog.getByLabel(labels.name, { exact: true }).fill('Immediate reward')
    await dialog.getByLabel(labels.description, { exact: true }).fill('Immediate description')
    await dialog.getByRole('checkbox', { name: /Immediate parent/ }).check()
    await dialog.getByLabel(labels.required, { exact: true }).fill('5')
    const firstSave = page.waitForResponse(
      (response) =>
        response.request().method() === 'PUT' && response.url().endsWith(`/rewards/${reward.id}`)
    )
    await dialog.getByRole('button', { name: labels.saveChanges, exact: true }).click()
    const saved = (await (await firstSave).json()).data
    expect(saved.version.name).toBe('Immediate reward')
    expect(saved.version.required_count).toBe(1)
    expect(saved.pending_version.required_count).toBe(5)
    expect(saved.version.target_ids).toContain(identity.membership.id)
    const notification = page.getByRole('dialog', { name: labels.earnedTitle, exact: true })
    await expect(notification).toBeVisible()
    await expect(notification.getByText('Immediate reward', { exact: true })).toBeVisible()
    await notification.getByRole('button', { name: /^(閉じる|Close)$/ }).click()
    await page.getByRole('button', { name: labels.edit, exact: true }).click()
    await expect(dialog.getByLabel(labels.required, { exact: true })).toHaveValue('5')
    await dialog.getByLabel(labels.name, { exact: true }).fill('Renamed again')
    const secondSave = page.waitForResponse(
      (response) =>
        response.request().method() === 'PUT' && response.url().endsWith(`/rewards/${reward.id}`)
    )
    await dialog.getByRole('button', { name: labels.saveChanges, exact: true }).click()
    const edited = (await (await secondSave).json()).data
    expect(edited.version.name).toBe('Renamed again')
    expect(edited.pending_version.name).toBe('Renamed again')
    expect(edited.version.required_count).toBe(1)
    expect(edited.pending_version.required_count).toBe(5)
    await expect(page.getByRole('heading', { name: 'Renamed again', exact: true })).toBeVisible()
    const inventory = await (await page.request.get(`${path}/rewards/awards`, { headers })).json()
    expect(inventory.total).toBe(1)
    expect(inventory.data[0].snapshot.name).toBe('Immediate reward')
    expect(inventory.data[0].snapshot.description).toBe('Immediate description')
  })
}
