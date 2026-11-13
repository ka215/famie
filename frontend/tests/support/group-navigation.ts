import { readFileSync } from 'node:fs'
import type { Page, Route } from '@playwright/test'

const catalog = JSON.parse(
  readFileSync(new URL('../../../backend/resources/legal-documents.json', import.meta.url), 'utf8')
)
export const family = (id: number, enabled: boolean, admin = false) => {
  const group = {
    id,
    name: `Synthetic family ${id}`,
    type: 'family',
    images: {
      enabled,
      used_bytes: 0,
      quota_bytes: 1000000,
      max_files_per_activity: 1,
      max_input_bytes: 1000000,
    },
  }
  return {
    access_token: `synthetic-group-token-${id}`,
    user: {
      id,
      username: `synthetic_${id}`,
      display_name: `Synthetic member ${id}`,
      show_name_suffix: false,
      ui_locale: 'ja',
      password_security: { status: 'ok', snoozed_until: null },
    },
    membership: {
      id,
      role: admin ? 'admin' : 'member',
      status: 'active',
      is_representative: admin,
      group,
    },
    legal: { enabled: true, required: false },
  }
}
type Identity = ReturnType<typeof family>
export const deferred = () => {
  let release!: () => void
  const wait = new Promise<void>((resolve) => {
    release = resolve
  })
  return { wait, release }
}

export async function mockApi(
  page: Page,
  identity: Identity,
  groupRequest: (route: Route, current: Identity) => Promise<void>,
  next = identity
) {
  let current = identity
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/status')) return route.fulfill({ json: { status: 'ok' } })
    if (path.endsWith('/auth/me')) return route.fulfill({ json: current })
    if (path.endsWith('/auth/logout')) return route.fulfill({ json: { message: 'Logged out' } })
    if (path.endsWith('/auth/login')) {
      current = next
      return route.fulfill({ json: current })
    }
    if (path.endsWith('/legal/documents'))
      return route.fulfill({
        json: { data: catalog.documents, meta: { acceptance_enabled: true } },
      })
    if (/\/groups\/\d+$/.test(path)) return groupRequest(route, current)
    if (path.endsWith('/security-alerts'))
      return route.fulfill({
        json: {
          data: [],
          meta: { unread_total: 0, unread_by_member: [], limit: 10, has_more: false },
        },
      })
    if (path.endsWith('/logs/counts')) return route.fulfill({ json: { data: {} } })
    if (path.endsWith('/logs'))
      return route.fulfill({ json: { data: [], current_page: 1, last_page: 1, total: 0 } })
    if (/\/(members|members\/inactive|categories)$/.test(path)) return route.fulfill({ json: [] })
    throw new Error(`Unexpected synthetic API route: ${path}`)
  })
}
