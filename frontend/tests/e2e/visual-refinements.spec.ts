import { expect, test } from '@playwright/test'
import { timelineSample } from '../support/timeline-sample'

for (const locale of ['ja', 'en'] as const) {
  for (const theme of ['light', 'dark'] as const) {
    test(`${locale}/${theme}: notes keep their meaning and strength keeps its values`, async ({
      page,
      context,
    }, testInfo) => {
      const sample = await timelineSample(page, context)
      sample.user.ui_locale = locale
      const original = `First line\nSecond line ${'LongNote'.repeat(30)}`
      const firstLog = sample.logs[0]
      if (!firstLog) throw new Error('Missing sample log')
      firstLog.note = original
      await page.setViewportSize({ width: 320, height: 850 })
      await page.goto('/')
      await page.evaluate(
        (theme) => document.documentElement.setAttribute('data-theme', theme),
        theme
      )
      const note = page.getByRole('article').first().locator('p').filter({ hasText: original })
      await expect(note).toBeVisible()
      await expect(note.locator('.sr-only')).toHaveText(locale === 'ja' ? 'メモ:' : 'Notes:')
      await expect(note.locator('[aria-hidden="true"]')).toHaveCount(1)
      await expect(note.locator('span').last()).toHaveText(original)
      expect(await note.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(
        true
      )
      await expect(page.getByRole('article').nth(1).locator('p .sr-only')).toHaveCount(0)
      if (firstLog.user) firstLog.user.display_name = 'LongName'.repeat(30)
      await page.goto('/logs/1')
      await page.evaluate(
        (theme) => document.documentElement.setAttribute('data-theme', theme),
        theme
      )
      await expect(page.getByRole('article')).toContainText(original)
      await expect(page.locator('form, input, textarea')).toHaveCount(0)
      expect(
        await page
          .locator('.app-scroll-area')
          .evaluate((element) => element.scrollWidth <= element.clientWidth)
      ).toBe(true)
      await page.screenshot({ path: testInfo.outputPath(`activity-detail-${locale}-${theme}.png`) })
      await context.clearCookies()
      await page.evaluate((locale) => localStorage.setItem('famie.uiLocale.guest', locale), locale)
      await page.goto('/register')
      await page.evaluate(
        (theme) => document.documentElement.setAttribute('data-theme', theme),
        theme
      )
      const meter = page.getByRole('meter')
      const password = page.locator('#register-password')
      await expect(meter).toHaveAttribute('min', '0')
      await expect(meter).toHaveAttribute('max', '3')
      await expect(meter).toHaveCSS('height', '8px')
      await expect(meter).toHaveCSS('border-top-width', '0px')
      for (const [value, score] of [
        ['', '0'],
        ['aaaaaaaaaaaaaaa', '1'],
        ['river otter sunset', '2'],
        ['river otter sunset garden path', '3'],
      ] as const) {
        await password.fill(value)
        await expect(meter).toHaveAttribute('value', score)
      }
      await page.screenshot({ path: testInfo.outputPath(`strength-${locale}-${theme}.png`) })
    })
  }
}
