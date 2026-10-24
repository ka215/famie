import { mkdir, readFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { expect, test } from '@playwright/test'
import { timelineSample } from '../support/timeline-sample'

for (const locale of ['en', 'ja'] as const) {
  test(`capture the three ${locale} README screens`, async ({ page, context }, testInfo) => {
    test.skip(
      process.env.FAMIE_CAPTURE_SAMPLE !== '1' || testInfo.project.name !== 'chromium',
      'Explicit README capture only'
    )
    const sample = await timelineSample(page, context)
    const english = locale === 'en'
    sample.user.ui_locale = locale
    sample.user.display_name = english ? 'Alex' : 'おとうさん'
    sample.membership.group.name = english ? 'The Parker Family' : 'サンプル家族'
    const names = english
      ? ['Chores & helping out', 'Study & homework', 'Exercise & sports']
      : ['お手伝い', '勉強', '運動']
    const memberNames = english ? ['Alex', 'Jamie', 'Morgan'] : ['おとうさん', 'はる', 'おかあさん']
    sample.categories.forEach((category, index) => {
      category.name = names[index] ?? category.name
    })
    const contents = english
      ? [
          'Helped prepare dinner',
          'Read for 20 minutes',
          'Went for a walk in the park',
          'Watered the garden',
        ]
      : ['夕ごはんの準備をお手伝い', '20分間の読書', '朝の公園をみんなでお散歩', '花壇に水やり']
    const notes = english
      ? [
          'Made a colorful salad together.',
          'Finished another chapter today.',
          'Enjoyed the sunshine and fresh air.',
          null,
        ]
      : [
          '彩り豊かなサラダを一緒に作った。',
          '今日はもう一章読み終えた。',
          '日差しと新鮮な空気を楽しんだ。',
          null,
        ]
    sample.logs.forEach((log, index) => {
      log.content = contents[index] ?? log.content
      log.note = notes[index] ?? null
      if (log.user) log.user.display_name = memberNames[log.user_id - 1] ?? ''
      if (index < 3)
        Object.assign(log, {
          image: { id: index + 1, width: 3, height: 2, mime: 'image/jpeg', bytes: 100_000 },
        })
    })
    const photos = await Promise.all(
      ['salad', 'books', 'park'].map((theme) =>
        readFile(resolve(`../.tools/assets/pexels-${theme}.jpg`))
      )
    )
    const member = { ...sample.user, membership_id: 1, role: 'admin', status: 'active' }
    const rewards = [
      {
        name: english ? 'Everyday helper' : '毎日のお手伝い',
        description: english ? 'Choose a family movie together.' : '家族で見る映画を一緒に選ぼう。',
        color: 'gold',
        category: sample.categories[0],
        metric: 'activities',
        required: 5,
        progress: 1,
      },
      {
        name: english ? 'Keep on reading' : '読書を続けよう',
        description: english
          ? 'Pick a new book for our shelf.'
          : '本棚に並べる新しい一冊を選ぼう。',
        color: 'silver',
        category: sample.categories[1],
        metric: 'activities',
        required: 5,
        progress: 0,
      },
      {
        name: english ? 'Spread some kindness' : 'ありがとうを届けよう',
        description: english
          ? 'Celebrate each other with a heart.'
          : 'ハートで家族の頑張りを応援しよう。',
        color: 'bronze',
        category: null,
        metric: 'likes',
        required: 5,
        progress: 2,
      },
    ].map((item, index) => ({
      id: index + 1,
      status: 'active',
      revision: 1,
      start_month: '2026-10-01',
      cycle_months: 1,
      timezone: 'Asia/Tokyo',
      version: {
        id: index + 1,
        name: item.name,
        description: item.description,
        color: item.color,
        metric: item.metric,
        count_mode: 'fixed',
        required_count: item.required,
        category_id: item.category?.id ?? null,
        category_name: item.category?.name ?? null,
        target_ids: [1],
        effective_from: '2026-10-01',
      },
      pending_version: null,
      starts_on: '2026-10-01',
      ends_on: '2026-10-31',
      started: true,
      progress: item.progress,
      awarded: false,
    }))
    await page.route('**/v1/**', async (route) => {
      const path = new URL(route.request().url()).pathname
      if (path.endsWith('/groups/1'))
        return route.fulfill({
          json: { data: { ...sample.membership.group, images: { enabled: true } } },
        })
      const image = path.match(/\/images\/(\d+)$/)
      if (image)
        return route.fulfill({ body: photos[Number(image[1]) - 1], contentType: 'image/jpeg' })
      if (path.endsWith('/gallery'))
        return route.fulfill({
          json: { data: sample.logs.slice(0, 3), current_page: 1, last_page: 1, total: 3 },
        })
      if (path.endsWith('/prepare')) return route.fulfill({ json: { status: 'complete' } })
      if (path.endsWith('/notifications')) return route.fulfill({ json: { data: [] } })
      if (path.endsWith('/members'))
        return route.fulfill({
          json: [
            member,
            { id: 2, membership_id: 2, display_name: memberNames[1], role: 'member' },
            { id: 3, membership_id: 3, display_name: memberNames[2], role: 'admin' },
          ],
        })
      if (path.endsWith('/rewards'))
        return route.fulfill({
          json: {
            data: rewards,
            current_page: 1,
            last_page: 1,
            total: 3,
            sync_status: 'complete',
            counts: { active: 3, management: 3 },
            limits: { active: 3, management: 6 },
          },
        })
      return route.fallback()
    })
    await page.setViewportSize({ width: 430, height: 932 })
    const directory = resolve('../docs/assets/screenshots')
    await mkdir(directory, { recursive: true })
    for (const [screen, path] of [
      ['timeline', '/'],
      ['rewards', '/rewards'],
      ['gallery', '/gallery'],
    ]) {
      await page.goto(path ?? '/')
      await expect(page.locator('html')).toHaveAttribute('lang', locale)
      // The footer depends on the asynchronous group image settings, independently of page data.
      const navigation = page.getByRole('navigation')
      await expect(
        navigation.getByRole('link', { name: english ? 'Gallery' : 'ギャラリー', exact: true })
      ).toBeVisible()
      await expect(navigation.getByRole('link')).toHaveCount(4)
      if (screen === 'timeline') {
        await page.locator('select').first().selectOption('')
        await expect(page.getByRole('article')).toHaveCount(4)
      } else {
        await expect(page.getByRole('article')).toHaveCount(3)
      }
      if (screen !== 'rewards') {
        const images = page.getByAltText(
          english ? 'Image attached to the activity' : 'アクティビティの添付画像'
        )
        if (screen === 'gallery') await expect(images).toHaveCount(3)
        await expect(images.first()).toBeVisible()
        for (const image of await images.all()) {
          await expect
            .poll(() =>
              image.evaluate(
                (element: HTMLImageElement) => element.complete && element.naturalWidth > 0
              )
            )
            .toBe(true)
        }
      }
      await page.evaluate(async () => {
        await document.fonts.ready
        await Promise.all([...document.images].map((image) => image.decode().catch(() => {})))
      })
      await page.screenshot({
        path: resolve(directory, `${screen}-${locale}.png`),
        animations: 'disabled',
      })
    }
  })
}
