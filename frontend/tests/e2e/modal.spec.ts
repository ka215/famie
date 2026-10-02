import { expect, test } from '@playwright/test'

test.beforeEach(async ({ page, context }) => {
  await context.addCookies([{ name: 'auth_token', value: 'modal-test', url: 'http://127.0.0.1' }])
  await page.route('**/v1/**', (route) => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/status')) return route.fulfill({ json: { status: 'ok' } })
    if (path.endsWith('/auth/me'))
      return route.fulfill({ json: { user: { id: 1, display_name: '自分', role: 'parent' } } })
    if (path.endsWith('/categories'))
      return route.fulfill({ json: [{ id: 1, name: '家事', color_code: '#10B981' }] })
    if (path.endsWith('/users')) return route.fulfill({ json: [] })
    return route.fulfill({ json: { data: [], current_page: 1, last_page: 1, total: 0 } })
  })
})

test('低い画面でも保存ボタンが最前面になり、背景操作を遮断して閉じると復旧する', async ({
  page,
}) => {
  await page.setViewportSize({ width: 320, height: 480 })
  await page.goto('/')
  const trigger = page.getByRole('button', { name: 'アクティビティを記録する' })
  await trigger.click()
  const dialog = page.getByRole('dialog', { name: 'アクティビティを記録' })
  await expect(dialog).toBeVisible()
  await expect(page.locator('.app-scroll-area')).toHaveCSS('overflow-y', 'hidden')
  await page
    .getByRole('link', { name: '設定', exact: true })
    .evaluate((element) => (element as HTMLElement).focus())
  expect(await dialog.evaluate((element) => element.contains(document.activeElement))).toBe(true)
  await page.getByPlaceholder('例: 算数のドリルを2ページ進めた').fill('前面の保存ボタン確認')
  const save = dialog.getByRole('button', { name: '記録を保存する' })
  await save.scrollIntoViewIfNeeded()
  expect(
    await save.evaluate((element) => {
      const box = element.getBoundingClientRect()
      return element.contains(
        document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2)
      )
    })
  ).toBe(true)
  const request = page.waitForRequest(
    (req) => req.url().endsWith('/logs') && req.method() === 'POST'
  )
  await save.click()
  expect((await request).postDataJSON().content).toBe('前面の保存ボタン確認')
  await expect(dialog).not.toBeVisible()
  await expect(trigger).toBeFocused()
  await expect(page.locator('.app-scroll-area')).toHaveCSS('overflow-y', 'auto')
  await trigger.click()
  await dialog.getByRole('button', { name: '閉じる', exact: true }).click()
  await expect(dialog).not.toBeVisible()
  await page.getByRole('link', { name: '設定', exact: true }).click()
  await expect(page).toHaveURL(/\/settings$/)
})

test('保存中にメンテナンスへ移行するとモーダルを閉じ、再試行を操作できる', async ({ page }) => {
  await page.route('**/v1/groups/*/logs', (route) =>
    route.request().method() === 'POST'
      ? route.fulfill({ status: 503, json: { code: 'maintenance', message: 'メンテナンス中' } })
      : route.fallback()
  )
  await page.goto('/')
  await page.getByRole('button', { name: 'アクティビティを記録する' }).click()
  await page.getByPlaceholder('例: 算数のドリルを2ページ進めた').fill('停止確認')
  await page.getByRole('button', { name: '記録を保存する' }).click()
  await expect(page.getByRole('dialog')).not.toBeVisible()
  await expect(page.getByRole('heading', { name: 'ただいまメンテナンス中です' })).toBeVisible()
  await expect(page.getByRole('button', { name: '再試行', exact: true })).toBeEnabled()
})
