import { expect, type Locator, test } from '@playwright/test'

const expectReadable = async (locator: Locator) => {
  const contrast = await locator.evaluate((element) => {
    const canvas = document.createElement('canvas')
    canvas.width = canvas.height = 1
    const context = canvas.getContext('2d')
    if (!context) throw new Error('Color sampling unavailable')
    const luminance = (color: string) => {
      context.clearRect(0, 0, 1, 1)
      context.fillStyle = color
      context.fillRect(0, 0, 1, 1)
      const pixel = context.getImageData(0, 0, 1, 1).data
      return [0.2126, 0.7152, 0.0722].reduce((sum, weight, index) => {
        const value = (pixel[index] ?? 0) / 255
        return sum + weight * (value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4)
      }, 0)
    }
    let parent: Element | null = element
    let background = 'rgba(0, 0, 0, 0)'
    while (parent && background === 'rgba(0, 0, 0, 0)') {
      background = getComputedStyle(parent).backgroundColor
      parent = parent.parentElement
    }
    const text = luminance(getComputedStyle(element).color)
    const surface = luminance(background)
    return (Math.max(text, surface) + 0.05) / (Math.min(text, surface) + 0.05)
  })
  expect(contrast).toBeGreaterThanOrEqual(4.5)
}

test('ログイン画面は初期ダーク表示とシステム設定の変更に追従し、QRは白背景を維持する', async ({
  page,
}) => {
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.route('**/v1/status', (route) => route.fulfill({ json: { status: 'ok' } }))
  await page.goto('/login')
  await expect(page.locator('html')).toHaveCSS('color-scheme', 'dark')
  const input = page.getByPlaceholder('例: parent1')
  const darkBackground = await input.evaluate((el) => getComputedStyle(el).backgroundColor)
  await expect(page.getByAltText('Famieのトップページを開くQRコード')).toHaveCSS(
    'background-color',
    'rgb(255, 255, 255)'
  )
  await page.emulateMedia({ colorScheme: 'light' })
  await expect(page.locator('html')).toHaveCSS('color-scheme', 'light')
  await expect(input).not.toHaveCSS('background-color', darkBackground)
  await page.emulateMedia({ colorScheme: 'dark' })
  await expect(input).toHaveCSS('background-color', darkBackground)
  await expectReadable(page.getByRole('heading', { name: 'Famie', exact: true }))
  await expectReadable(page.getByText('家族のアクティビティ記録', { exact: true }))
  await expectReadable(page.getByRole('button', { name: 'ログイン', exact: true }))
})

test('ダーク表示でタイムライン・登録・編集・設定の文字と背景を確認できる', async ({
  page,
  context,
}) => {
  await page.emulateMedia({ colorScheme: 'dark' })
  await context.addCookies([{ name: 'auth_token', value: 'theme-test', url: 'http://127.0.0.1' }])
  const user = { id: 1, username: 'parent', display_name: '自分', role: 'parent' }
  const category = { id: 1, name: '明るいカテゴリ', color_code: '#ffffff' }
  const log = {
    id: 1,
    user_id: 1,
    category_id: 1,
    activity_date: '2026-09-28',
    activity_time: null,
    content: '表示確認',
    note: '補足',
    user,
    category,
  }
  await page.route('**/v1/**', (route) => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/status')) return route.fulfill({ json: { status: 'ok' } })
    if (path.endsWith('/auth/me')) return route.fulfill({ json: { user } })
    if (path.endsWith('/categories')) return route.fulfill({ json: [category] })
    if (path.endsWith('/users')) return route.fulfill({ json: [user] })
    if (path.endsWith('/logs/1')) return route.fulfill({ json: { data: log } })
    return route.fulfill({ json: { data: [log], current_page: 1, last_page: 1, total: 1 } })
  })
  await page.goto('/')
  const article = page.locator('article').first()
  await expect(article).toBeVisible()
  const darkCard = await article.evaluate((el) => getComputedStyle(el).backgroundColor)
  await expect(article.getByText('明るいカテゴリ')).toHaveCSS('color', 'rgb(0, 0, 0)')
  await expectReadable(article.getByText('表示確認', { exact: true }))
  await expectReadable(article.getByText('明るいカテゴリ', { exact: true }))
  await page.getByRole('button', { name: 'アクティビティを記録する' }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog).toHaveCSS('background-color', darkCard)
  await dialog.getByRole('button', { name: '記録を保存する' }).click()
  await expect(dialog.getByRole('alert')).toBeVisible()
  await expectReadable(dialog.getByRole('alert'))
  await dialog.getByRole('button', { name: '閉じる', exact: true }).click()
  await page.goto('/logs/1')
  await expect(page.getByLabel('実施日', { exact: true })).toHaveCSS('background-color', darkCard)
  await page.goto('/settings')
  await expect(page.getByLabel('自分の表示名')).toHaveCSS('background-color', darkCard)
  await expect(page.getByRole('button', { name: '変更', exact: true })).toBeDisabled()
  await page.screenshot({ path: 'test-results/settings-dark.png', fullPage: true })
})

test('静的メンテナンス画面もシステム設定へ追従する', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.goto('/maintenance.html')
  await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(2, 6, 23)')
  await expect(page.getByRole('button', { name: '再試行' })).toBeVisible()
  await page.emulateMedia({ colorScheme: 'light' })
  await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(248, 250, 252)')
})
