import { expect, test } from '@playwright/test'
import { deferred, family, mockApi } from '../support/group-navigation'

test.beforeEach(async ({ context, baseURL }) => {
  if (!baseURL || new URL(baseURL).protocol !== 'https:')
    throw new Error('Login ownership tests require the HTTPS application fixture')
  await context.addCookies([{ name: 'auth_token', value: 'synthetic-initial-token', url: baseURL }])
})

test('ログアウト後に届く前家族の応答で、新しい家族の表示を上書きしない', async ({ page }) => {
  const first = family(901, true)
  const second = family(902, false)
  const gate = deferred()
  let oldRequests = 0
  await mockApi(
    page,
    first,
    async (route, current) => {
      if (current === first) {
        oldRequests++
        if (oldRequests === 1) return route.fulfill({ status: 503, json: {} })
        await gate.wait
      }
      await route.fulfill({ json: { data: current.membership.group } })
    },
    second
  )
  await page.goto('/licenses/')
  await page.getByRole('button', { name: '再試行', exact: true }).click()
  await expect.poll(() => oldRequests).toBe(2)
  await page.getByRole('button', { name: 'ログアウト', exact: true }).click()
  await page.locator('#login-identity').fill('synthetic_other')
  await page.locator('#login-password').fill('Synthetic password 123!')
  await page.getByRole('button', { name: 'ログイン', exact: true }).click()
  await expect(page.locator('header')).toContainText('Synthetic member 902')
  await expect(page.locator('nav > div.grid')).toHaveClass(/grid-cols-3/)
  const late = page.waitForResponse(
    (response) =>
      new URL(response.url()).pathname.endsWith('/groups/901') && response.status() === 200
  )
  gate.release()
  await late
  await page.evaluate(
    () =>
      new Promise<void>((resolve) =>
        requestAnimationFrame(() => requestAnimationFrame(() => resolve()))
      )
  )
  await expect(page.locator('nav > div.grid')).toHaveClass(/grid-cols-3/)
  await expect(page.locator('nav a[href="/gallery"]')).toHaveCount(0)
})
