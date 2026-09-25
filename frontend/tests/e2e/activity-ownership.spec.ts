import { expect, test } from '@playwright/test'

for (const role of ['parent', 'child']) {
  test(`${role}: 自分のカードだけ編集へ遷移し、他人の記録は閲覧のみ`, async ({ page, context }) => {
    const owner = { id: 1, username: 'owner', display_name: '自分', role }
    const category = { id: 1, name: '家事', color_code: '#10B981' }
    const logs = [1, 2].map((id) => ({
      id,
      user_id: id,
      category_id: 1,
      activity_date: '2026-09-25',
      activity_time: '12:00',
      content: id === 1 ? '自分の記録' : '他人の記録',
      note: null,
      category,
      user: id === 1 ? owner : { ...owner, id: 2, display_name: '家族' },
    }))
    await context.addCookies([
      { name: 'auth_token', value: 'ownership-test', url: 'http://127.0.0.1' },
    ])
    await page.route('**/v1/**', async (route) => {
      const path = new URL(route.request().url()).pathname
      if (path.endsWith('/auth/me')) return route.fulfill({ json: { user: owner } })
      if (path.endsWith('/categories')) return route.fulfill({ json: [category] })
      if (path.endsWith('/users')) return route.fulfill({ json: [owner] })
      if (/\/logs\/\d+$/.test(path)) {
        return route.fulfill({ json: { data: logs[Number(path.split('/').pop()) - 1] } })
      }
      return route.fulfill({ json: { data: logs, current_page: 1, last_page: 1, total: 2 } })
    })
    await page.goto('/')
    const ownCard = page.getByRole('article').filter({ hasText: '自分の記録' })
    const otherCard = page.getByRole('article').filter({ hasText: '他人の記録' })
    await expect(ownCard.getByRole('link', { name: '2026-09-25の記録を編集' })).toBeVisible()
    await expect(otherCard.getByRole('link')).toHaveCount(0)
    await otherCard.getByText('他人の記録').click()
    await expect(page).toHaveURL(/\/$/)
    await ownCard.click({ position: { x: 20, y: 65 } })
    await expect(page).toHaveURL(/\/logs\/1$/)
    await expect(page.getByRole('button', { name: '更新する' })).toBeVisible()
    await page.goto('/logs/2')
    await expect(page.getByText('この記録は閲覧のみ可能です。')).toBeVisible()
    await expect(page.getByRole('button', { name: '更新する' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: '削除', exact: true })).toHaveCount(0)
  })
}

test('ホーム追加ガイドはQRと端末別手順を表示し、イベントがなければ追加ボタンを出さない', async ({
  page,
}) => {
  await page.route('**/v1/status', (route) => route.fulfill({ json: { status: 'ok' } }))
  await page.goto('/login')
  const guide = page.getByRole('region', { name: 'ホーム画面への追加ガイド' })
  await expect(
    guide.getByRole('heading', { name: 'ホーム画面に追加してご利用ください' })
  ).toBeVisible()
  await expect(guide.getByRole('link')).toHaveAttribute('href', 'https://famie.ka2.org/')
  const qr = guide.getByRole('img', { name: 'Famieのトップページを開くQRコード' })
  await expect(qr).toBeVisible()
  await expect
    .poll(() => qr.evaluate((image) => (image as HTMLImageElement).naturalWidth))
    .toBeGreaterThan(0)
  await expect(
    guide.getByRole('button', { name: 'ホーム画面に追加する', exact: true })
  ).toHaveCount(0)
  await guide.getByRole('button', { name: 'Android', exact: true }).click()
  await expect(guide.getByText('Chromeなどの対応ブラウザでFamieを開きます。')).toBeVisible()
  await guide.getByRole('button', { name: 'iPhone', exact: true }).click()
  await expect(guide.getByText('共有ボタン', { exact: true })).toBeVisible()
})

for (const outcome of ['accepted', 'dismissed', 'error'] as const) {
  test(`追加イベントから標準ダイアログを一度呼び出す: ${outcome}`, async ({ page }) => {
    await page.route('**/v1/status', (route) => route.fulfill({ json: { status: 'ok' } }))
    await page.goto('/login')
    await expect(
      page.getByRole('heading', { name: 'ホーム画面に追加してご利用ください' })
    ).toBeVisible()
    await page.evaluate((result) => {
      const event = Object.assign(new Event('beforeinstallprompt', { cancelable: true }), {
        prompt: () => {
          document.documentElement.dataset.installCalls = String(
            Number(document.documentElement.dataset.installCalls ?? '0') + 1
          )
          if (result === 'error') throw new Error('Unavailable')
        },
        userChoice: Promise.resolve({ outcome: result, platform: 'web' }),
      })
      window.dispatchEvent(event)
    }, outcome)
    await page.getByRole('button', { name: 'ホーム画面に追加する', exact: true }).click()
    await expect(page.locator('html')).toHaveAttribute('data-install-calls', '1')
    const message =
      outcome === 'accepted'
        ? '追加を受け付けました。ホーム画面をご確認ください。'
        : outcome === 'dismissed'
          ? '追加は行われませんでした。ブラウザのメニューからも追加できます。'
          : '追加ダイアログを開けませんでした。下の手順で追加してください。'
    await expect(page.getByText(message)).toBeVisible()
    await expect(
      page.getByRole('button', { name: 'ホーム画面に追加する', exact: true })
    ).toHaveCount(0)
    if (outcome === 'accepted') {
      await page.evaluate(() => window.dispatchEvent(new Event('appinstalled')))
      await expect(page.getByRole('region', { name: 'ホーム画面への追加ガイド' })).toHaveCount(0)
    }
  })
}
