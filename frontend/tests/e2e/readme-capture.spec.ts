import { mkdir, readFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { expect, test } from '@playwright/test'
import { timelineSample } from '../support/timeline-sample'

test('capture the three English README screens', async ({ page, context }, testInfo) => {
  test.skip(
    process.env.FAMIE_CAPTURE_SAMPLE !== '1' || testInfo.project.name !== 'chromium',
    'Explicit README capture only'
  )
  const sample = await timelineSample(page, context)
  sample.user.ui_locale = 'en'
  sample.user.display_name = 'Alex'
  sample.membership.group.name = 'The Parker Family'
  const names = ['Chores & helping out', 'Study & homework', 'Exercise & sports']
  sample.categories.forEach((category, index) => {
    category.name = names[index] ?? category.name
  })
  const contents = [
    'Helped prepare dinner',
    'Read for 20 minutes',
    'Went for a walk in the park',
    'Watered the garden',
  ]
  const notes = [
    'Made a colorful salad together.',
    'Finished another chapter today.',
    'Enjoyed the sunshine and fresh air.',
    null,
  ]
  sample.logs.forEach((log, index) => {
    log.content = contents[index] ?? log.content
    log.note = notes[index] ?? null
    if (log.user)
      log.user.display_name = log.user_id === 1 ? 'Alex' : log.user_id === 2 ? 'Jamie' : 'Morgan'
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
      name: 'Everyday helper',
      description: 'Choose a family movie together.',
      color: 'gold',
      category: sample.categories[0],
      metric: 'activities',
      required: 5,
      progress: 1,
    },
    {
      name: 'Keep on reading',
      description: 'Pick a new book for our shelf.',
      color: 'silver',
      category: sample.categories[1],
      metric: 'activities',
      required: 5,
      progress: 0,
    },
    {
      name: 'Spread some kindness',
      description: 'Celebrate each other with a heart.',
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
          { id: 2, membership_id: 2, display_name: 'Jamie', role: 'member' },
          { id: 3, membership_id: 3, display_name: 'Morgan', role: 'admin' },
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
    await expect(page.locator('html')).toHaveAttribute('lang', 'en')
    if (screen === 'timeline') {
      await page.locator('select').first().selectOption('')
      await expect(page.getByRole('article')).toHaveCount(4)
    } else {
      await expect(page.getByRole('article')).toHaveCount(3)
    }
    if (screen !== 'rewards') {
      const images = page.getByAltText('Image attached to the activity')
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
    await page.screenshot({ path: resolve(directory, `${screen}-en.png`), animations: 'disabled' })
  }
})
