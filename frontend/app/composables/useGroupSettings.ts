import type { DataResponse, Group } from '#shared/types/api'

interface GroupSettingsState {
  owner: string
  revision: number
  status: 'idle' | 'loading' | 'ready' | 'error'
  group: Group | null
}

const pending = new WeakMap<
  object,
  { owner: string; revision: number; request: Promise<Group | null> }
>()

export const useGroupSettings = () => {
  const app = useNuxtApp()
  const { token, membership, groupId } = useAuth()
  const { fetchApi } = useApi()
  const owner = computed(() =>
    token.value && groupId.value ? `${token.value}:${membership.value?.id}:${groupId.value}` : ''
  )
  const state = useState<GroupSettingsState>('group_settings', () => ({
    owner: '',
    revision: 0,
    status: 'idle',
    group: null,
  }))
  const synchronize = () => {
    if (state.value.owner === owner.value) return
    state.value = {
      owner: owner.value,
      revision: state.value.revision + 1,
      status: 'idle',
      group: null,
    }
    pending.delete(app)
  }
  const ready = computed(() => state.value.owner === owner.value && state.value.status === 'ready')
  const group = computed(() => (state.value.owner === owner.value ? state.value.group : null))
  const failed = computed(() => state.value.owner === owner.value && state.value.status === 'error')

  const load = async (refresh = false): Promise<Group | null> => {
    synchronize()
    if (!owner.value) return null
    const active = pending.get(app)
    if (active?.owner === owner.value) return active.request
    if (ready.value && !refresh) return state.value.group
    const requestOwner = owner.value
    const revision = ++state.value.revision
    if (state.value.status !== 'ready') state.value.status = 'loading'
    const current = () => owner.value === requestOwner && state.value.revision === revision
    const request = fetchApi<DataResponse<Group>>(`/groups/${groupId.value}`, {
      cache: 'no-store',
      timeout: 15000,
    })
      .then((response) => {
        if (!current()) return null
        if (
          !response.data ||
          (response.data.images && typeof response.data.images.enabled !== 'boolean')
        )
          throw new Error('Invalid image settings')
        state.value.group = response.data
        state.value.status = 'ready'
        return response.data
      })
      .catch(() => {
        if (current()) state.value.status = 'error'
        return null
      })
      .finally(() => {
        if (pending.get(app)?.revision === revision) pending.delete(app)
      })
    pending.set(app, { owner: requestOwner, revision, request })
    return request
  }

  const updateGroup = (value: Group, requestOwner: string) => {
    synchronize()
    if (!owner.value || requestOwner !== owner.value || value.id !== groupId.value) return false
    state.value.revision++
    state.value.group = value
    state.value.status = 'ready'
    pending.delete(app)
    return true
  }

  return { owner, group, ready, failed, load, updateGroup }
}
