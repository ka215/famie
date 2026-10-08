import type {
  ActivityLikeState,
  ActivityLog,
  ApiRequestError,
  DataResponse,
} from '#shared/types/api'

interface LikeOperation {
  state: ActivityLikeState
  before: ActivityLikeState
  desired: boolean
  pending: boolean
  uncertain: boolean
  error: string
  settledRevision: number
}

export const useActivityLikes = () => {
  const { user, groupId, token } = useAuth()
  const { fetchApi } = useApi()
  const operations = useState<Record<number, LikeOperation>>('activity_like_operations', () => ({}))
  const retryUntil = useState<number>('activity_like_retry_until', () => 0)
  const owner = useState<string>('activity_like_owner', () => '')
  const revision = useState<number>('activity_like_revision', () => 0)
  const now = ref(Date.now())
  const identity = () => `${user.value?.id ?? ''}:${groupId.value ?? ''}`
  const reset = () => {
    operations.value = {}
    retryUntil.value = 0
    owner.value = identity()
  }
  if (owner.value !== identity()) reset()
  watch([token, groupId], reset, { flush: 'sync' })
  let timer: ReturnType<typeof setInterval> | undefined
  onMounted(() => {
    timer = setInterval(() => {
      now.value = Date.now()
    }, 200)
  })
  onUnmounted(() => clearInterval(timer))
  const waiting = computed(() => retryUntil.value > now.value)
  const waitSeconds = computed(() => Math.max(0, Math.ceil((retryUntil.value - now.value) / 1000)))

  const stateFor = (log: ActivityLog): ActivityLikeState => operations.value[log.id]?.state ?? log
  const beginRead = () => ++revision.value
  const acceptLogs = (logs: ActivityLog[], readRevision: number) => {
    for (const log of logs) {
      const operation = operations.value[log.id]
      if (
        operation &&
        !operation.pending &&
        !operation.uncertain &&
        operation.settledRevision < readRevision
      ) {
        delete operations.value[log.id]
      }
    }
  }

  const toggle = async (log: ActivityLog) => {
    const previous = operations.value[log.id]
    const before = stateFor(log)
    if (!before.can_like || previous?.pending || Date.now() < retryUntil.value || !groupId.value)
      return
    const desired = previous?.uncertain ? previous.desired : !before.liked_by_me
    const operation: LikeOperation = previous?.uncertain
      ? previous
      : {
          before: { ...before },
          state: {
            ...before,
            liked_by_me: desired,
            likes_count: Math.max(0, before.likes_count + (desired ? 1 : -1)),
          },
          desired,
          pending: false,
          uncertain: false,
          error: '',
          settledRevision: 0,
        }
    operations.value[log.id] = operation
    const active = operations.value[log.id]
    if (!active) return
    active.pending = true
    active.error = ''
    const requestIdentity = identity()
    const requestToken = token.value
    const path = `/groups/${groupId.value}/logs/${log.id}/like`
    const isCurrent = () =>
      operations.value[log.id] === active &&
      identity() === requestIdentity &&
      token.value === requestToken
    try {
      const response = await fetchApi<DataResponse<ActivityLikeState>>(path, {
        method: desired ? 'PUT' : 'DELETE',
        timeout: 15000,
      })
      if (!isCurrent()) return
      active.state = response.data
      active.uncertain = false
    } catch (error: unknown) {
      if (!isCurrent()) return
      const failure = error as ApiRequestError
      const status = failure.response?.status ?? failure.statusCode
      if (status === 429) {
        const header = failure.response?.headers?.get('Retry-After') ?? '1'
        const seconds = Number(header)
        const delay = Number.isFinite(seconds) ? seconds * 1000 : Date.parse(header) - Date.now()
        retryUntil.value = Date.now() + Math.max(1000, Number.isFinite(delay) ? delay : 1000)
        now.value = Date.now()
        active.state = active.uncertain ? active.state : active.before
        active.error = '操作が続いています。少し待ってから再試行してください。'
      } else if (status && status >= 400 && status < 500 && status !== 408) {
        active.state = { ...active.before, can_like: ![401, 403, 404].includes(status) }
        active.uncertain = false
        active.error =
          failure.data?.message ?? 'いいねを変更できませんでした。再読み込みしてください。'
      } else {
        active.uncertain = true
        active.error = '保存結果を確認できませんでした。「再同期」で同じ操作を再送してください。'
      }
    } finally {
      if (isCurrent()) {
        active.pending = false
        active.settledRevision = ++revision.value
      }
    }
  }

  return { operations, stateFor, toggle, beginRead, acceptLogs, waiting, waitSeconds }
}
