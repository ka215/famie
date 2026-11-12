import { readFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'

const manifest = JSON.parse(
  readFileSync(new URL('../../../backend/resources/legal-documents.json', import.meta.url), 'utf8')
)
const identity = {
  user: {
    id: 101,
    username: 'synthetic_owner',
    display_name: 'Synthetic owner',
    show_name_suffix: false,
    ui_locale: 'ja',
  },
  membership: {
    id: 101,
    role: 'admin',
    status: 'active',
    is_representative: true,
    group: { id: 101, name: 'Synthetic family', type: 'family' },
  },
  legal: { enabled: true, required: true },
}

test.beforeEach(async ({ baseURL }) => {
  if (!baseURL) throw new Error('Local base URL required')
  test.skip(
    !['127.0.0.1', 'localhost'].includes(new URL(baseURL).hostname),
    'Synthetic API tests run only on local hosts'
  )
})

test('登録では別々の未選択チェックが必要で、版変更時には選択を解除する', async ({
  page,
}, testInfo) => {
  let submissions = 0
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/status')) return route.fulfill({ json: { status: 'ok' } })
    if (path.endsWith('/legal/documents'))
      return route.fulfill({
        json: { data: manifest.documents, meta: { acceptance_enabled: true } },
      })
    if (path.endsWith('/username-availability')) return route.fulfill({ json: { available: true } })
    if (path.endsWith('/auth/register')) {
      submissions++
      const body = route.request().postDataJSON()
      expect(body.legal.terms.accepted).toBe(true)
      expect(body.legal.privacy.acknowledged).toBe(true)
      expect(body.legal.terms.locale).toBe('ja')
      expect(body.legal.terms).not.toHaveProperty('user_id')
      return route.fulfill({ status: 409, json: { code: 'legal_version_changed' } })
    }
    return route.abort()
  })
  await page.goto('/register/')
  await page.locator('#register-group-name').fill('Synthetic family')
  await page.locator('#register-username').fill('synthetic_owner')
  await page.locator('#register-display-name').fill('Synthetic owner')
  await page.locator('#register-password').fill('Synthetic password 123!')
  await page.locator('#register-password-confirmation').fill('Synthetic password 123!')
  const checks = page.locator('fieldset input[type=checkbox]')
  const submit = page.locator('button[type=submit]')
  await expect(checks).toHaveCount(2)
  await page.screenshot({
    path: `../.temp/v0.20.0/legal-registration-${testInfo.project.name}.png`,
    fullPage: true,
  })
  await expect(checks.first()).not.toBeChecked()
  await expect(submit).toBeDisabled()
  await checks.first().check()
  await expect(submit).toBeDisabled()
  await checks.nth(1).check()
  await expect(submit).toBeEnabled()
  await submit.click()
  await expect(page.getByRole('alert').first()).toContainText('文書が更新されました')
  await expect(checks.first()).not.toBeChecked()
  await expect(checks.nth(1)).not.toBeChecked()
  await expect(submit).toBeDisabled()
  expect(submissions).toBe(1)
})

test('未確認の代表管理者は確認画面へ移動し文書・問い合わせ・退会にアクセスできる', async ({
  page,
  context,
  baseURL,
}, testInfo) => {
  let submissions = 0
  if (!baseURL) throw new Error('Local base URL required')
  await context.addCookies([{ name: 'auth_token', value: 'synthetic-token', url: baseURL }])
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/auth/me'))
      return route.fulfill({
        json: { ...identity, legal: { enabled: true, required: submissions < 2 } },
      })
    if (path.endsWith('/legal/documents'))
      return route.fulfill({
        json: { data: manifest.documents, meta: { acceptance_enabled: true } },
      })
    if (path.endsWith('/auth/legal-acceptances')) {
      submissions++
      expect(route.request().postDataJSON().legal.privacy.acknowledged).toBe(true)
      if (submissions === 2) return route.fulfill({ json: { status: 'current' } })
      return route.fulfill({ status: 409, json: { code: 'legal_version_changed' } })
    }
    if (path.endsWith('/lifecycle-operations'))
      return route.fulfill({
        status: 201,
        json: {
          data: {
            receipt_id: '00000000-0000-4000-8000-000000000001',
            confirmation_code: 'synthetic-code',
            kind: 'group_withdraw',
            prepared_expires_at: '2099-01-01T00:00:00Z',
            commit_retry_after: 0,
            impact: { members: 1, family: true, posts: 'delete', images: 'delete' },
          },
        },
      })
    if (path.endsWith('/cancel')) return route.fulfill({ json: { data: { state: 'aborted' } } })
    return route.abort()
  })
  await page.goto('/settings/')
  await expect(page).toHaveURL(/\/legal-confirmation\/?$/)
  await expect(page.locator('a[href="mailto:support@famie.co"]')).toBeVisible()
  await expect(page.locator('a[href^="/legal/"]')).toHaveCount(5)
  const checks = page.locator('fieldset input[type=checkbox]')
  const submit = page.locator('button[type=submit]')
  await expect(submit).toBeDisabled()
  await page.screenshot({
    path: `../.temp/v0.20.0/legal-reconfirmation-${testInfo.project.name}.png`,
    fullPage: true,
  })
  await checks.first().check()
  await checks.nth(1).check()
  await submit.click()
  await expect(page.getByRole('alert').first()).toContainText('文書が更新されました')
  await expect(checks.first()).not.toBeChecked()
  expect(submissions).toBe(1)
  await page.getByRole('button', { name: '家族の利用を終了（退会）', exact: true }).click()
  await expect(page.getByRole('dialog')).toBeVisible()
  await page.screenshot({
    path: '../.temp/v0.20.0/legal-reconfirmation-withdrawal.png',
    fullPage: true,
  })
  await page.getByRole('dialog').getByRole('button', { name: '閉じる', exact: true }).click()
  await expect(page.getByRole('dialog')).toHaveCount(0)
  await checks.first().check()
  await checks.nth(1).check()
  await submit.click()
  await expect(page).toHaveURL(/:\d+\/$/)
  expect(submissions).toBe(2)
})
