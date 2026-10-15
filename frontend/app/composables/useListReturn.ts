import type { FilterPeriod } from '#shared/types/forms'

export interface TimelineReturnFilters {
  period: FilterPeriod
  from: string
  to: string
  userId: string
  categoryId: string
  year: number
  month: number
  selectedDate: string | null
  dayView: boolean
}
export interface GalleryReturnFilters {
  period: 'week' | 'month' | 'custom'
  anchor: string
  from: string
  to: string
  userId: string
  categoryId: string
}
interface ReturnEntry {
  path: '/' | '/gallery'
  filters: TimelineReturnFilters | GalleryReturnFilters
  page: number
  cardId: number
  offset: number
  scrollTop: number
}

// Nuxtのアプリ内だけに保持する。履歴には秘密情報を含まない参照キーだけを置く。
export const useListReturn = () => {
  let disposed = false
  onScopeDispose(() => {
    disposed = true
  })
  const entries = useState<Record<string, ReturnEntry>>('list_return_entries', () => ({}))
  const owner = useState('list_return_owner', () => '')
  const { user, groupId } = useAuth()
  const identity = computed(() => `${user.value?.id ?? ''}:${groupId.value ?? ''}`)
  watch(
    identity,
    (value) => {
      if (owner.value !== value) {
        entries.value = {}
        owner.value = value
      }
    },
    { immediate: true, flush: 'sync' }
  )

  const clear = () => {
    entries.value = {}
  }
  const read = (key: unknown) =>
    typeof key === 'string' && Object.hasOwn(entries.value, key) ? entries.value[key] : undefined
  const current = (path: ReturnEntry['path']) => {
    const route = useRoute()
    const entry = read(route.query.resume) ?? read(window.history.state?.famieList)
    return entry?.path === path ? entry : undefined
  }
  const open = (
    event: MouseEvent,
    id: number,
    state: Omit<ReturnEntry, 'cardId' | 'offset' | 'scrollTop'>
  ) => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)
      return
    event.preventDefault()
    const root = document.querySelector<HTMLElement>('.app-scroll-area')
    const card = document.querySelector<HTMLElement>(`[data-log-id="${id}"]`)
    const key = crypto.randomUUID()
    entries.value[key] = {
      ...state,
      cardId: id,
      offset:
        root && card ? card.getBoundingClientRect().top - root.getBoundingClientRect().top : 0,
      scrollTop: root?.scrollTop ?? 0,
    }
    for (const old of Object.keys(entries.value).slice(0, -32)) delete entries.value[old]
    window.history.replaceState({ ...window.history.state, famieList: key }, '')
    return navigateTo({ path: `/logs/${id}`, query: { back: key } })
  }
  const restoreScroll = async (entry: ReturnEntry) => {
    await nextTick()
    await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()))
    if (disposed) return
    const root = document.querySelector<HTMLElement>('.app-scroll-area')
    const card = document.querySelector<HTMLElement>(`[data-log-id="${entry.cardId}"]`)
    if (!root) return
    root.scrollTop = card
      ? root.scrollTop +
        card.getBoundingClientRect().top -
        root.getBoundingClientRect().top -
        entry.offset
      : entry.scrollTop
  }
  return { clear, read, current, open, restoreScroll, isActive: () => !disposed }
}
