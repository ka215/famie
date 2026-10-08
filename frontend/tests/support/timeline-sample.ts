import type { BrowserContext, Page, Route } from '@playwright/test'

export async function timelineSample(page: Page, context: BrowserContext, role = 'admin') {
  const user = {
    id: 1,
    username: 'sample-parent',
    display_name: 'おとうさん',
    show_name_suffix: false,
  }
  const membership = {
    id: 1,
    role,
    status: 'active',
    group: { id: 1, name: 'サンプル家族', type: 'family' },
  }
  const categories = [
    { id: 1, group_id: 1, name: 'お手伝い', color_code: '#059669', sort_order: 1 },
    { id: 2, group_id: 1, name: '勉強', color_code: '#2563eb', sort_order: 2 },
    { id: 3, group_id: 1, name: '運動', color_code: '#d97706', sort_order: 3 },
  ]
  const members = [
    user,
    { id: 2, username: 'sample-child', display_name: 'はる' },
    { id: 3, username: 'sample-mom', display_name: 'おかあさん' },
  ]
  const logs = [
    {
      id: 1,
      user_id: 2,
      category_id: 1,
      content: '夕ごはんの準備をお手伝い',
      note: 'サラダの盛りつけ、上手にできた！',
      activity_time: '18:00',
      likes_count: 1,
      liked_by_me: false,
      can_like: true,
    },
    {
      id: 2,
      user_id: 3,
      category_id: 2,
      content: '家族で図書館へ。お気に入りの一冊を発見',
      note: null,
      activity_time: '16:00',
      likes_count: 2,
      liked_by_me: true,
      can_like: true,
    },
    {
      id: 3,
      user_id: 1,
      category_id: 3,
      content: '朝の公園をみんなでお散歩',
      note: null,
      activity_time: '07:30',
      likes_count: 2,
      liked_by_me: false,
      can_like: false,
    },
    {
      id: 4,
      user_id: 1,
      category_id: 1,
      content: '花壇に水やり',
      note: null,
      activity_time: '07:00',
      likes_count: 0,
      liked_by_me: false,
      can_like: false,
    },
  ].map((log) => ({
    ...log,
    activity_date: '2026-10-08',
    user: members.find((member) => member.id === log.user_id),
    category: categories.find((category) => category.id === log.category_id),
  }))
  const state = {
    user,
    membership,
    categories,
    logs,
    onLike: null as ((route: Route) => Promise<void>) | null,
    onProfile: null as ((route: Route) => Promise<void>) | null,
    methods: [] as string[],
  }
  await page.clock.setFixedTime(new Date('2026-10-08T10:00:00+09:00'))
  await context.addCookies([{ name: 'auth_token', value: 'sample-only', url: 'http://127.0.0.1' }])
  await page.addInitScript(() => localStorage.setItem('famie-theme', 'light'))
  await page.route('**/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/status')) return route.fulfill({ json: { status: 'ok' } })
    if (path.endsWith('/auth/me')) {
      if (route.request().method() === 'PATCH') {
        if (state.onProfile) return state.onProfile(route)
        Object.assign(user, route.request().postDataJSON())
      }
      return route.fulfill({ json: { user, membership } })
    }
    if (path.endsWith('/auth/logout'))
      return route.fulfill({ json: { message: 'ログアウトしました。' } })
    if (path.endsWith('/categories')) return route.fulfill({ json: categories })
    if (path.endsWith('/members'))
      return route.fulfill({
        json: members.map((member) => ({
          ...member,
          membership_id: member.id,
          role: member.id === 1 ? role : 'member',
        })),
      })
    if (path.endsWith('/members/inactive')) return route.fulfill({ json: [] })
    if (path.endsWith('/groups/1')) return route.fulfill({ json: { data: membership.group } })
    if (path.endsWith('/like')) {
      state.methods.push(route.request().method())
      if (state.onLike) return state.onLike(route)
      const id = Number(path.split('/').at(-2))
      const log = logs.find((entry) => entry.id === id)
      if (!log) return route.fulfill({ status: 404, json: {} })
      const desired = route.request().method() === 'PUT'
      if (log.liked_by_me !== desired) log.likes_count += desired ? 1 : -1
      log.liked_by_me = desired
      return route.fulfill({
        json: {
          data: { id, likes_count: log.likes_count, liked_by_me: desired, can_like: log.can_like },
        },
      })
    }
    if (/\/logs\/\d+$/.test(path))
      return route.fulfill({
        json: { data: logs.find((log) => log.id === Number(path.split('/').at(-1))) },
      })
    if (path.endsWith('/logs'))
      return route.fulfill({
        json: { data: logs, current_page: 1, last_page: 1, total: logs.length },
      })
    return route.fulfill({ status: 404, json: {} })
  })
  return state
}
