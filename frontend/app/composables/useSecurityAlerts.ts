import type { ApiRequestError, SecurityAlert, SecurityAlertsResponse } from '#shared/types/api'

function isSecurityAlertsResponse(value: unknown): value is SecurityAlertsResponse {
  if (!value || typeof value !== 'object') return false
  const response = value as Partial<SecurityAlertsResponse>
  return (
    Array.isArray(response.data) &&
    response.data.every(
      (alert) =>
        alert &&
        typeof alert.id === 'string' &&
        Number.isInteger(alert.group_id) &&
        alert.group_id > 0 &&
        Number.isInteger(alert.membership_id) &&
        alert.membership_id > 0 &&
        typeof alert.display_name === 'string' &&
        typeof alert.username === 'string' &&
        typeof alert.occurred_at === 'string' &&
        !Number.isNaN(Date.parse(alert.occurred_at))
    ) &&
    !!response.meta &&
    Number.isInteger(response.meta.unread_total) &&
    response.meta.unread_total >= 0 &&
    response.meta.limit === 20 &&
    typeof response.meta.has_more === 'boolean' &&
    Array.isArray(response.meta.unread_by_member) &&
    response.meta.unread_by_member.every(
      (item) =>
        item &&
        Number.isInteger(item.membership_id) &&
        item.membership_id > 0 &&
        Number.isInteger(item.count) &&
        item.count > 0
    )
  )
}

export const useSecurityAlerts = () => {
  const { user, token, groupId, isParent } = useAuth()
  const { isMaintenance } = useMaintenance()
  const { fetchApi } = useApi()
  const { t } = useI18n()
  const route = useRoute()
  const state = useState('security-alert-state', () => ({
    owner: '',
    revision: 0,
    latest: [] as SecurityAlert[],
    selected: [] as SecurityAlert[],
    counts: [] as { membership_id: number; count: number }[],
    dismissed: [] as string[],
    memberId: null as number | null,
    total: 0,
    loading: false,
    busy: false,
    error: '',
  }))
  const owner = computed(
    () => `${user.value?.id}:${token.value}:${groupId.value}:${isParent.value}`
  )
  const enabled = computed(
    () =>
      !!user.value &&
      !!token.value &&
      !!groupId.value &&
      isParent.value &&
      !isMaintenance.value &&
      !['/login', '/register', '/forgot-password', '/reset-password', '/verify-email'].includes(
        route.path
      )
  )
  const reset = () => {
    Object.assign(state.value, {
      owner: owner.value,
      revision: state.value.revision + 1,
      latest: [],
      selected: [],
      counts: [],
      dismissed: [],
      memberId: null,
      total: 0,
      loading: false,
      busy: false,
      error: '',
    })
  }
  if (state.value.owner !== owner.value) reset()
  watch([owner, enabled], reset, { flush: 'sync' })
  const alerts = computed(() =>
    state.value.memberId === null ? state.value.latest : state.value.selected
  )
  const visible = computed(
    () =>
      enabled.value &&
      (state.value.memberId !== null ||
        state.value.latest.some((alert) => !state.value.dismissed.includes(alert.id)))
  )
  const hasUnread = (id: number) =>
    enabled.value && state.value.counts.some((item) => item.membership_id === id && item.count > 0)
  const close = () => {
    if (state.value.busy) return
    state.value.dismissed = [
      ...new Set([
        ...state.value.dismissed,
        ...state.value.latest.map((alert) => alert.id),
        ...state.value.selected.map((alert) => alert.id),
      ]),
    ]
    state.value.memberId = null
    state.value.error = ''
    state.value.revision++
    state.value.loading = false
  }
  async function refresh(force = false) {
    if (
      !enabled.value ||
      state.value.busy ||
      (!force && (state.value.loading || document.visibilityState === 'hidden'))
    )
      return
    const revision = ++state.value.revision
    const requestOwner = owner.value
    const memberId = state.value.memberId
    const current = () =>
      revision === state.value.revision && requestOwner === owner.value && enabled.value
    state.value.loading = true
    try {
      const base = `/groups/${groupId.value}/security-alerts`
      const all = await fetchApi<SecurityAlertsResponse>(base)
      if (!current()) return
      const selected =
        memberId === null
          ? all
          : await fetchApi<SecurityAlertsResponse>(`${base}?membership_id=${memberId}`)
      if (!current()) return
      if (!isSecurityAlertsResponse(all) || !isSecurityAlertsResponse(selected)) {
        throw new Error('Invalid login security alert response')
      }
      state.value.latest = all.data
      state.value.counts = all.meta.unread_by_member
      state.value.selected = selected.data
      state.value.total = selected.meta.unread_total
      state.value.error = ''
      if (memberId !== null && selected.meta.unread_total === 0) close()
    } catch (cause) {
      if (!current()) return
      const failure = cause as ApiRequestError
      const status = failure.statusCode ?? failure.response?.status
      if (status === 401 || status === 403 || status === 503) reset()
      else state.value.error = 'auth.alerts.loadFailed'
    } finally {
      if (current()) state.value.loading = false
    }
  }
  const open = (id: number) => {
    if (!enabled.value || state.value.loading || state.value.busy) return
    state.value.memberId = id
    state.value.selected = state.value.latest.filter((alert) => alert.membership_id === id)
    state.value.total = state.value.counts.find((item) => item.membership_id === id)?.count ?? 0
    void refresh(true)
  }
  async function markRead(alert: SecurityAlert) {
    if (!enabled.value || state.value.busy || state.value.loading) return
    const requestOwner = owner.value
    const revision = ++state.value.revision
    state.value.busy = true
    state.value.error = ''
    try {
      await fetchApi(`/groups/${groupId.value}/security-alerts/${alert.id}/read`, { method: 'PUT' })
      if (requestOwner !== owner.value || revision !== state.value.revision) return
      state.value.latest = state.value.latest.filter((item) => item.id !== alert.id)
      state.value.selected = state.value.selected.filter((item) => item.id !== alert.id)
      const count = state.value.counts.find((item) => item.membership_id === alert.membership_id)
      if (count) count.count = Math.max(0, count.count - 1)
      state.value.total = Math.max(0, state.value.total - 1)
    } catch (cause) {
      if (requestOwner === owner.value && revision === state.value.revision) {
        const failure = cause as ApiRequestError
        const status = failure.statusCode ?? failure.response?.status
        if (status === 401 || status === 403 || status === 503) reset()
        else state.value.error = 'auth.alerts.readFailed'
      }
      return
    } finally {
      if (requestOwner === owner.value && revision === state.value.revision)
        state.value.busy = false
    }
    if (requestOwner === owner.value) await refresh(true)
  }
  return {
    state,
    alerts,
    enabled,
    visible,
    hasUnread,
    close,
    refresh,
    open,
    markRead,
    error: computed(() => (state.value.error ? t(state.value.error) : '')),
  }
}
