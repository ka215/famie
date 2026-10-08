import { mkdir } from 'node:fs/promises'
import { resolve } from 'node:path'
import { expect, test } from '@playwright/test'
import { timelineSample } from '../support/timeline-sample'

test('楽観的更新は通信中に一度だけ送信し、正規化・解除・自己表示を維持する', async ({
  page,
  context,
}) => {
  const sample = await timelineSample(page, context)
  let release = () => {}
  const gate = new Promise<void>((resolve) => {
    release = resolve
  })
  sample.onLike = async (route) => {
    await gate
    await route.fulfill({
      json: { data: { id: 1, likes_count: 3, liked_by_me: true, can_like: true } },
    })
  }
  await page.goto('/')
  const card = page.getByRole('article').filter({ hasText: '夕ごはんの準備' })
  const button = card.getByRole('button')
  try {
    await button.click()
    await expect(button).toHaveAttribute('aria-pressed', 'true')
    await expect(button).toHaveAccessibleName('いいねを取り消す：2件')
    await expect(button).toBeDisabled()
    await button.dispatchEvent('click')
    await expect.poll(() => sample.methods.length).toBe(1)
  } finally {
    release()
  }
  await expect(button).toBeEnabled()
  await expect(button).toHaveAccessibleName('いいねを取り消す：3件')
  const firstLog = sample.logs[0]
  if (!firstLog) throw new Error('Missing sample log')
  firstLog.liked_by_me = true
  firstLog.likes_count = 3
  sample.onLike = null
  await button.click()
  await expect(button).toHaveAccessibleName('いいねする：2件')
  expect(sample.methods).toEqual(['PUT', 'DELETE'])
  firstLog.likes_count = 4
  await page.locator('select').first().selectOption('')
  await expect(button).toHaveAccessibleName('いいねする：4件')
  await expect(page).toHaveURL(/\/$/)
  const own = page.getByRole('article').filter({ hasText: '朝の公園' })
  await expect(own.getByRole('button')).toHaveCount(0)
  await own.getByLabel('受け取ったいいね：2件').click()
  await expect(page).toHaveURL(/\/$/)
  await expect(page.getByLabel('受け取ったいいね：0件')).toBeVisible()
  await own.getByRole('link').click()
  await expect(page).toHaveURL(/\/logs\/3$/)
})

test('確定エラーは表示を戻し、応答不明時は反転せず同じ希望状態を再送する', async ({
  page,
  context,
}) => {
  const sample = await timelineSample(page, context)
  sample.onLike = (route) => route.fulfill({ status: 422, json: { message: '検証エラー' } })
  await page.goto('/')
  const card = page.getByRole('article').filter({ hasText: '夕ごはんの準備' })
  const button = card.getByRole('button')
  await button.click()
  await expect(button).toHaveAccessibleName('いいねする：1件')
  await expect(card.getByRole('alert')).toHaveText('検証エラー')
  sample.onLike = async (route) => {
    const firstLog = sample.logs[0]
    if (!firstLog) throw new Error('Missing sample log')
    firstLog.liked_by_me = true
    firstLog.likes_count = 2
    await route.abort('failed')
  }
  await button.click()
  await expect(button).toHaveAccessibleName('いいねを再同期：2件')
  await expect(card.getByRole('alert')).toContainText('保存結果を確認できません')
  sample.onLike = null
  await button.click()
  await expect(button).toHaveAccessibleName('いいねを取り消す：2件')
  expect(sample.methods).toEqual(['PUT', 'PUT', 'PUT'])
})

test('429は他カードにも待機を共有し、再送せず待機終了後に復帰する', async ({ page, context }) => {
  const sample = await timelineSample(page, context)
  sample.onLike = (route) =>
    route.fulfill({
      status: 429,
      headers: { 'Retry-After': '1' },
      json: { message: '操作が続いています。' },
    })
  await page.goto('/')
  const first = page.getByRole('article').filter({ hasText: '夕ごはんの準備' }).getByRole('button')
  const second = page.getByRole('article').filter({ hasText: '家族で図書館' }).getByRole('button')
  await first.click()
  await expect(first).toHaveAccessibleName('いいねする：1件')
  await expect(second).toBeDisabled()
  // The sample clock fixes Date.now; advance it without delaying the test.
  await page.clock.setFixedTime(new Date('2026-10-08T10:00:02+09:00'))
  await expect(first).toBeEnabled()
  await expect(second).toBeEnabled()
  expect(sample.methods).toEqual(['PUT'])
})

test('フィルター再取得中の旧応答で別カードを変更しない', async ({ page, context }) => {
  const sample = await timelineSample(page, context)
  let release = () => {}
  const gate = new Promise<void>((resolve) => {
    release = resolve
  })
  sample.onLike = async (route) => {
    await gate
    await route.fulfill({
      json: { data: { id: 1, likes_count: 2, liked_by_me: true, can_like: true } },
    })
  }
  await page.goto('/')
  await page.getByRole('article').filter({ hasText: '夕ごはんの準備' }).getByRole('button').click()
  sample.logs.splice(0, 1)
  const response = page.waitForResponse((response) => response.url().endsWith('/logs/1/like'))
  try {
    await page.locator('select').first().selectOption('')
    await expect(page.getByRole('article').filter({ hasText: '夕ごはんの準備' })).toHaveCount(0)
  } finally {
    release()
  }
  await response
  const other = page.getByRole('article').filter({ hasText: '家族で図書館' }).getByRole('button')
  await expect(other).toHaveAccessibleName('いいねを取り消す：2件')
})

test('README用のサンプルタイムラインを撮影する', async ({ page, context }, testInfo) => {
  test.skip(
    process.env.FAMIE_CAPTURE_SAMPLE !== '1' || testInfo.project.name !== 'chromium',
    'Explicit sample capture only'
  )
  await timelineSample(page, context)
  await page.setViewportSize({ width: 430, height: 1060 })
  await page.goto('/')
  await page.locator('select').first().selectOption('')
  await expect(page.getByRole('article')).toHaveCount(4)
  await page.evaluate(() => document.fonts.ready)
  const directory = resolve('../docs/assets/screenshots')
  await mkdir(directory, { recursive: true })
  await page.screenshot({
    path: resolve(directory, 'timeline-v0.13.0.png'),
    animations: 'disabled',
  })
})
