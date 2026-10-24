import { expect, test } from '@playwright/test'
import { timelineSample } from '../support/timeline-sample'

test('本人の詳細はカードが既定で、取消と保存は履歴を増やさず詳細へ戻る', async ({
  page,
  context,
}) => {
  const sample = await timelineSample(page, context)
  const own = sample.logs[2]
  if (!own) throw new Error('Missing activity')
  let updates = 0
  await page.route('**/v1/groups/1/logs/3', (route) => {
    if (route.request().method() === 'PUT') {
      updates++
      Object.assign(own, route.request().postDataJSON())
    }
    return route.fulfill({ json: { data: own } })
  })
  await page.goto('/')
  await page.locator('[data-log-id="3"]').getByRole('link').click()
  await expect(page).toHaveURL(/mode=edit/)
  await expect(page.getByLabel('活動内容', { exact: true })).toHaveValue(own.content)
  const historyLength = await page.evaluate(() => history.length)
  await page.getByLabel('活動内容', { exact: true }).fill('破棄する内容')
  await page.getByRole('button', { name: '編集を取り消す', exact: true }).click()
  await expect(page.getByRole('article')).toContainText(own.content)
  await expect(page.locator('form')).toHaveCount(0)
  expect(updates).toBe(0)
  await page.getByRole('link', { name: /記録を編集/ }).click()
  await page.getByLabel('活動内容', { exact: true }).fill('保存した内容\n次の行')
  await page.getByRole('button', { name: '更新する', exact: true }).click()
  await expect(page.getByRole('article')).toContainText('保存した内容\n次の行')
  await expect(page).not.toHaveURL(/mode=edit/)
  expect(updates).toBe(1)
  expect(await page.evaluate(() => history.length)).toBe(historyLength)
  await page.goBack()
  await expect(page).toHaveURL(/\/$/)
  await expect(page.locator('[data-log-id="3"]')).toContainText('保存した内容')
  await page.goto('/logs/3')
  await expect(page.getByRole('article')).toContainText('保存した内容')
  await expect(page.locator('form')).toHaveCount(0)
})

test('保存409は入力を保持し、保存後の取得失敗は保存失敗と区別して再取得できる', async ({
  page,
  context,
}) => {
  const sample = await timelineSample(page, context)
  const own = sample.logs[2]
  if (!own) throw new Error('Missing activity')
  let updates = 0
  let conflict = true
  let failRead = false
  let denyWrite = false
  await page.route('**/v1/groups/1/logs/3', (route) => {
    if (route.request().method() === 'PUT') {
      updates++
      if (denyWrite)
        return route.fulfill({
          status: 404,
          json: { message: '指定された記録は見つかりませんでした。' },
        })
      if (conflict)
        return route.fulfill({ status: 409, json: { message: '別の端末で更新されました。' } })
      Object.assign(own, route.request().postDataJSON())
      failRead = true
      return route.fulfill({ json: { data: own } })
    }
    if (failRead) return route.fulfill({ status: 500, json: {} })
    return route.fulfill({ json: { data: own } })
  })
  await page.goto('/logs/3?mode=edit')
  await page.getByLabel('活動内容', { exact: true }).fill('未保存の入力')
  await page.getByRole('button', { name: '更新する', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('別の端末で更新されました')
  await expect(page.getByLabel('活動内容', { exact: true })).toHaveValue('未保存の入力')
  conflict = false
  await page.getByRole('button', { name: '更新する', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('記録は保存済みですが')
  await expect(page.getByRole('button', { name: '更新する', exact: true })).toHaveCount(0)
  await expect(page.getByRole('article')).toHaveCount(0)
  failRead = false
  await page.getByRole('button', { name: '再試行', exact: true }).click()
  await expect(page.getByRole('article')).toContainText('未保存の入力')
  expect(updates).toBe(2)
  await page.getByRole('link', { name: /記録を編集/ }).click()
  denyWrite = true
  await page.getByRole('button', { name: '更新する', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('見つかりませんでした')
  await expect(page.locator('form')).toHaveCount(0)
  await expect(page.getByRole('article')).toHaveCount(0)
})

test('保存中に一覧へ移動した後の応答は、画面を詳細へ戻さない', async ({ page, context }) => {
  const sample = await timelineSample(page, context)
  const own = sample.logs[2]
  let release: (() => void) | undefined
  await page.route('**/v1/groups/1/logs/3', async (route) => {
    if (route.request().method() === 'PUT')
      await new Promise<void>((resolve) => {
        release = resolve
      })
    return route.fulfill({ json: { data: own } })
  })
  await page.goto('/logs/3?mode=edit')
  await page.getByLabel('活動内容', { exact: true }).fill('保存中に画面を移動する')
  await page.getByRole('button', { name: '更新する', exact: true }).click()
  await expect.poll(() => !!release).toBe(true)
  await page
    .getByRole('navigation')
    .getByRole('link', { name: 'タイムライン', exact: true })
    .click()
  await expect(page.locator('[data-log-id="3"]')).toBeVisible()
  const response = page.waitForResponse(
    (result) => result.url().endsWith('/logs/3') && result.request().method() === 'PUT'
  )
  release?.()
  await response
  await expect(page).toHaveURL(/\/$/)
  await expect(page.getByRole('article')).toHaveCount(sample.logs.length)
})

test('ギャラリー経由の他者カードでいいねを共有し、未確定の再送と429待機を維持する', async ({
  page,
  context,
}) => {
  const sample = await timelineSample(page, context)
  const other = sample.logs[0]
  if (!other) throw new Error('Missing activity')
  await page.route('**/v1/groups/1/gallery?*', (route) =>
    route.fulfill({
      json: {
        data: [other],
        current_page: 1,
        last_page: 1,
        total: 1,
      },
    })
  )
  await page.goto('/gallery')
  await page.getByRole('link', { name: 'アクティビティを見る' }).click()
  await expect(page.getByRole('article')).toContainText(other.content)
  await expect(page.locator('form')).toHaveCount(0)
  const button = page.getByRole('article').getByRole('button', { name: /いいね/ })
  await button.click()
  await expect(button).toHaveAccessibleName('いいねを取り消す：2件')
  await page.getByRole('link', { name: 'ギャラリーへ' }).click()
  await page.getByRole('navigation').getByRole('link', { name: 'タイムライン' }).click()
  await expect(page.locator('[data-log-id="1"]').getByRole('button')).toHaveAccessibleName(
    'いいねを取り消す：2件'
  )
  await page.goto('/logs/1?mode=edit')
  await expect(page).toHaveURL(/\/logs\/1$/)
  sample.onLike = async (route) => {
    await route.abort('failed')
  }
  await button.click()
  await expect(button).toHaveAccessibleName('いいねを再同期：1件')
  sample.onLike = null
  await button.click()
  await expect(button).toHaveAccessibleName('いいねする：1件')
  sample.onLike = (route) =>
    route.fulfill({ status: 429, headers: { 'Retry-After': '2' }, json: {} })
  await button.click()
  await expect(button).toBeDisabled()
  await expect(page.getByRole('article').getByRole('status')).toContainText('秒お待ちください')
  expect(sample.methods).toEqual(['PUT', 'DELETE', 'DELETE', 'PUT'])
})
