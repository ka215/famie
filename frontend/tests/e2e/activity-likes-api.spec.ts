import { expect, test } from '@playwright/test'

test('実APIで他者へのいいねを保存・再読込・解除し、敬称設定も再ログイン後に維持する', async ({
  page,
  context,
}) => {
  const username = `likes${Date.now()}`
  const password = 'Famie-E2E-only-123!'
  const registered = await page.request.post('/api/v1/auth/register', {
    data: {
      username,
      display_name: 'いいねする親',
      group_name: 'いいね家族',
      password,
      password_confirmation: password,
    },
  })
  expect(registered.ok()).toBeTruthy()
  const identity = await registered.json()
  const headers = { Authorization: `Bearer ${identity.access_token}` }
  const path = `/api/v1/groups/${identity.membership.group.id}`
  const member = await page.request.post(`${path}/members`, {
    headers,
    data: {
      username: `${username}kid`,
      display_name: '記録する子',
      role: 'member',
      password,
      password_confirmation: password,
    },
  })
  expect(member.ok()).toBeTruthy()
  const login = await page.request.post('/api/v1/auth/login', {
    data: { login: `${username}kid`, password },
  })
  const child = await login.json()
  const categories = await (await page.request.get(`${path}/categories`, { headers })).json()
  const created = await page.request.post(`${path}/logs`, {
    headers: { Authorization: `Bearer ${child.access_token}` },
    data: {
      category_id: categories[0].id,
      activity_date: '2026-10-08',
      content: '実APIの家族の活動',
    },
  })
  expect(created.ok()).toBeTruthy()
  const log = (await created.json()).data
  await context.addCookies([
    { name: 'auth_token', value: identity.access_token, url: 'http://127.0.0.1' },
  ])
  await page.clock.setFixedTime(new Date('2026-10-08T10:00:00+09:00'))
  await page.goto('/')
  await page.locator('select').first().selectOption('')
  const card = page.getByRole('article').filter({ hasText: '実APIの家族の活動' })
  await card.getByRole('button', { name: 'いいねする：0件', exact: true }).click()
  await expect(card.getByRole('button')).toBeEnabled()
  await expect(card.getByRole('button')).toHaveAccessibleName('いいねを取り消す：1件')
  expect(
    (await (await page.request.get(`${path}/logs/${log.id}`, { headers })).json()).data.liked_by_me
  ).toBe(true)
  await page.reload()
  await page.locator('select').first().selectOption('')
  await expect(card.getByRole('button')).toHaveAccessibleName('いいねを取り消す：1件')
  await card.getByRole('button').click()
  await expect(card.getByRole('button')).toBeEnabled()
  expect(
    (await (await page.request.get(`${path}/logs/${log.id}`, { headers })).json()).data.likes_count
  ).toBe(0)
  await page.goto('/settings')
  await page.getByRole('switch').click()
  await expect(page.getByRole('switch')).toHaveAttribute('aria-checked', 'false')
  const relogin = await page.request.post('/api/v1/auth/login', {
    data: { login: username, password },
  })
  expect((await relogin.json()).user.show_name_suffix).toBe(false)
})
