import { expect, type Page, test } from '@playwright/test'
import { timelineSample } from '../support/timeline-sample'

async function browserLanguage(page: Page, language: string) {
  await page.evaluate((value) => {
    Object.defineProperty(navigator, 'languages', { configurable: true, get: () => [value] })
    Object.defineProperty(navigator, 'language', { configurable: true, get: () => value })
    window.dispatchEvent(new Event('languagechange'))
  }, language)
}

async function preferBritishEnglish(page: Page) {
  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'languages', { configurable: true, get: () => ['en-GB'] })
    Object.defineProperty(navigator, 'language', { configurable: true, get: () => 'en-GB' })
  })
}

test('activity language changes preserve Monday-first selection, draft and validation', async ({
  page,
  context,
}) => {
  await timelineSample(page, context)
  await preferBritishEnglish(page)
  const ranges: string[] = []
  page.on('request', (request) => {
    if (/\/logs\?/.test(request.url())) ranges.push(new URL(request.url()).search)
  })
  await page.goto('/')
  const week = page.getByRole('region', { name: 'Weekly calendar' })
  await expect(week.locator('.grid-cols-7 > button').first()).toContainText('Mon')
  await expect(page).toHaveTitle('Timeline | Famie')
  await page.getByRole('button', { name: 'Record an activity', exact: true }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Notes (optional)', { exact: true }).fill('未保存のメモ')
  await dialog.getByLabel('Activity description', { exact: true }).fill(' ')
  await dialog.getByRole('button', { name: 'Save activity' }).click()
  await expect(dialog.getByRole('alert')).toHaveText('Enter an activity description.')
  const previousRanges = [...ranges]
  await browserLanguage(page, 'ja-JP')
  await expect(dialog.getByRole('alert')).toHaveText('活動内容を入力してください。')
  await expect(dialog.getByLabel('補足メモ（任意）', { exact: true })).toHaveValue('未保存のメモ')
  await expect(dialog.getByLabel('実施日', { exact: true })).toHaveValue('2026-10-08')
  await dialog.getByRole('button', { name: '閉じる' }).click()
  await expect(
    page.getByRole('region', { name: '週カレンダー' }).locator('.grid-cols-7 > button').first()
  ).toContainText('月')
  expect(ranges).toEqual(previousRanges)
  await expect(page.getByRole('article').first()).toContainText('夕ごはんの準備をお手伝い')
})

test('like uncertainty and rate-limit state survive language changes without resending', async ({
  page,
  context,
}) => {
  const sample = await timelineSample(page, context)
  await preferBritishEnglish(page)
  sample.onLike = (route) => route.abort('failed')
  await page.goto('/')
  const card = page.getByRole('article').filter({ hasText: '夕ごはんの準備' })
  await card.getByRole('button').click()
  await expect(card.getByRole('button')).toHaveAccessibleName('Resync like: 2 likes')
  await browserLanguage(page, 'ja-JP')
  await expect(card.getByRole('button')).toHaveAccessibleName('いいねを再同期：2件')
  await expect(card.getByRole('alert')).toContainText('保存結果を確認できません')
  expect(sample.methods).toEqual(['PUT'])
  sample.onLike = (route) =>
    route.fulfill({ status: 429, headers: { 'Retry-After': '30' }, json: {} })
  await card.getByRole('button').click()
  await expect(card.getByRole('button')).toBeDisabled()
  await browserLanguage(page, 'en-US')
  await expect(card.getByRole('button')).toBeDisabled()
  await expect(card.getByRole('status')).toContainText('Please wait')
  await expect(card.getByRole('alert')).toContainText('Too many requests')
  expect(sample.methods).toEqual(['PUT', 'PUT'])
})

test('image conversion errors translate in place and narrow English UI fits', async ({
  page,
  context,
}, testInfo) => {
  await timelineSample(page, context)
  await preferBritishEnglish(page)
  await page.route('**/v1/groups/1', (route) =>
    route.fulfill({ json: { data: { id: 1, images: { enabled: true } } } })
  )
  await page.setViewportSize({ width: 320, height: 700 })
  await page.goto('/')
  await page.getByRole('button', { name: 'Record an activity', exact: true }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Activity description', { exact: true }).fill('Keep this input')
  await dialog
    .getByLabel('Image (optional, one image)')
    .setInputFiles({ name: 'unsupported.txt', mimeType: 'text/plain', buffer: Buffer.from('test') })
  await expect(dialog.getByRole('alert')).toHaveText('This image format is not supported.')
  expect(await dialog.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true)
  await page.screenshot({
    path: testInfo.outputPath('activity-image-english-320.png'),
    fullPage: true,
  })
  await browserLanguage(page, 'ja-JP')
  await expect(dialog.getByRole('alert')).toHaveText('対応していない画像形式です。')
  await expect(dialog.getByLabel('活動内容', { exact: true })).toHaveValue('Keep this input')
})

test('monthly count and activity details use account language over browser language', async ({
  page,
  context,
}) => {
  const sample = await timelineSample(page, context)
  sample.user.ui_locale = 'en'
  await page.route('**/v1/groups/1/logs/counts?*', (route) =>
    route.fulfill({ json: { data: { '2026-10-08': 1, '2026-10-09': 2 } } })
  )
  await page.goto('/')
  await page.getByRole('button', { name: 'Month', exact: true }).click()
  const month = page.getByRole('region', { name: 'Monthly calendar' })
  await expect(month.getByRole('heading')).toHaveText('October')
  await expect(month.getByRole('button', { name: /October 8.*1 activity$/ })).toBeVisible()
  await expect(month.getByRole('button', { name: /October 9.*2 activities$/ })).toBeVisible()
  await page.goto('/logs/1')
  await expect(page.getByText('Activity by はる', { exact: true })).toBeVisible()
  await expect(page.locator('form, input, textarea')).toHaveCount(0)
  await expect(page.getByRole('article')).toContainText('夕ごはんの準備をお手伝い')
  await expect(page).toHaveTitle('Activity details | Famie')
})

test('malformed HEIC worker failures are translated and do not expose decoder errors', async ({
  page,
  context,
}) => {
  await timelineSample(page, context)
  await preferBritishEnglish(page)
  await page.route('**/v1/groups/1', (route) =>
    route.fulfill({ json: { data: { id: 1, images: { enabled: true } } } })
  )
  await page.goto('/')
  await page.getByRole('button', { name: 'Record an activity', exact: true }).click()
  const dialog = page.getByRole('dialog')
  const malformed = Buffer.concat([
    Buffer.from([0, 0, 0, 24]),
    Buffer.from('ftypheic'),
    Buffer.alloc(12),
  ])
  await dialog
    .getByLabel('Image (optional, one image)')
    .setInputFiles({ name: 'broken.heic', mimeType: 'image/heic', buffer: malformed })
  await expect(dialog.getByRole('alert')).toHaveText(
    /Could not (load the image|convert the HEIC image)\./
  )
  await browserLanguage(page, 'ja-JP')
  await expect(dialog.getByRole('alert')).toHaveText(
    /画像を読み込めませんでした。|HEIC画像を変換できません。/
  )
  await expect(dialog.getByRole('button', { name: '記録を保存する' })).toBeEnabled()
})

test.describe('activity date timezone boundaries', () => {
  test.use({ timezoneId: 'America/Los_Angeles' })
  test('stored dates remain intact while today follows the device timezone', async ({
    page,
    context,
  }) => {
    await timelineSample(page, context)
    await preferBritishEnglish(page)
    await page.goto('/')
    await expect(page.getByRole('article').first()).toContainText('10/08/2026')
    await page.getByRole('button', { name: 'Record an activity', exact: true }).click()
    await expect(page.getByLabel('Activity date', { exact: true })).toHaveValue('2026-10-07')
    await browserLanguage(page, 'ja-JP')
    await expect(page.getByLabel('実施日', { exact: true })).toHaveValue('2026-10-07')
    await page.getByRole('dialog').getByRole('button', { name: '閉じる' }).click()
    await expect(page.getByRole('article').first()).toContainText('2026/10/08')
  })
})
