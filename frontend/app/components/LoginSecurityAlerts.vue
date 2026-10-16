<script setup lang="ts">
import type { ApiRequestError } from '#shared/types/api'

interface SecurityAlert {
  id: string
  display_name: string
  username: string
  occurred_at: string
}

function isSecurityAlert(value: unknown): value is SecurityAlert {
  if (!value || typeof value !== 'object') return false
  const alert = value as Record<string, unknown>
  return (
    typeof alert.id === 'string' &&
    typeof alert.display_name === 'string' &&
    typeof alert.username === 'string' &&
    typeof alert.occurred_at === 'string' &&
    !Number.isNaN(Date.parse(alert.occurred_at))
  )
}

const { user, token, groupId, isParent } = useAuth()
const { fetchApi } = useApi()
const { isMaintenance } = useMaintenance()
const route = useRoute()
const alerts = ref<SecurityAlert[]>([])
const error = ref('')
const busy = ref(false)
const dialog = ref<HTMLDialogElement | null>(null)
const dismissed = ref('')
const signature = computed(
  () => `${owner.value}:${alerts.value.map((alert) => alert.id).join(',')}`
)
const visible = computed(
  () => enabled.value && alerts.value.length > 0 && dismissed.value !== signature.value
)
const owner = computed(
  () => `${user.value?.id ?? ''}:${token.value ?? ''}:${groupId.value}:${isParent.value}`
)
const enabled = computed(
  () =>
    !!token.value &&
    !!user.value &&
    !!groupId.value &&
    isParent.value &&
    !isMaintenance.value &&
    !['/login', '/register', '/forgot-password', '/reset-password', '/verify-email'].includes(
      route.path
    )
)
let loading = false

function close() {
  if (!busy.value) dismissed.value = signature.value
}

watch(
  dialog,
  (element) => {
    if (element && !element.open) element.showModal()
  },
  { flush: 'post' }
)
onBeforeUnmount(() => dialog.value?.close())

async function refresh() {
  if (!enabled.value || loading || document.visibilityState === 'hidden') return
  const requestOwner = owner.value
  loading = true
  try {
    const result = await fetchApi<{ data: SecurityAlert[] }>(
      `/groups/${groupId.value}/security-alerts`
    )
    if (!Array.isArray(result?.data)) return
    if (requestOwner === owner.value && enabled.value) {
      alerts.value = result.data.filter(isSecurityAlert)
      error.value = ''
    }
  } catch {
    // 通知の取得失敗で通常の画面操作を妨げず、次回の確認時に再取得する。
  } finally {
    loading = false
  }
}

async function markRead(alert: SecurityAlert) {
  if (!enabled.value || busy.value) return
  const requestOwner = owner.value
  busy.value = true
  error.value = ''
  try {
    await fetchApi(`/groups/${groupId.value}/security-alerts/${alert.id}/read`, { method: 'PUT' })
    if (requestOwner === owner.value)
      alerts.value = alerts.value.filter((item) => item.id !== alert.id)
  } catch (cause) {
    if (requestOwner === owner.value)
      error.value = (cause as ApiRequestError).data?.message ?? '通知を確認済みにできませんでした。'
  } finally {
    busy.value = false
  }
}

watch([owner, enabled], () => {
  alerts.value = []
  error.value = ''
  if (import.meta.client) void refresh()
})
watch(
  () => route.fullPath,
  () => void refresh()
)
let interval: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  void refresh()
  interval = setInterval(() => void refresh(), 60_000)
  window.addEventListener('focus', refresh)
})
onBeforeUnmount(() => {
  clearInterval(interval)
  window.removeEventListener('focus', refresh)
})
</script>

<template>
  <dialog v-if="visible" ref="dialog" aria-label="ログインの安全性に関する通知" :aria-busy="busy" class="security-alert-dialog rounded-lg border border-amber-400 bg-amber-50 p-4 text-slate-900 dark:bg-amber-950 dark:text-slate-100" @cancel.prevent="close">
    <div class="flex items-center justify-between gap-4">
      <h2 class="font-semibold">ログインの安全性を確認してください</h2>
      <button type="button" :disabled="busy" class="min-h-11 shrink-0 rounded-lg border border-amber-600 px-3 text-sm disabled:opacity-50" @click="close">閉じる</button>
    </div>
    <p class="mt-2 text-sm">以下のメンバーで、24時間以内にログイン失敗による10分停止が2回以上発生しました。本人の入力間違いの可能性もあります。状況を確認し、必要に応じてメンバー管理からパスワード再発行・無効化を行ってください。</p>
    <ul class="mt-2 space-y-2">
      <li v-for="alert in alerts" :key="alert.id" class="flex flex-wrap items-center justify-between gap-2 border-t border-amber-300 pt-2">
        <span class="min-w-0 break-all text-sm">{{ alert.display_name }}（@{{ alert.username }}）<time class="ml-2" :datetime="alert.occurred_at">{{ new Date(alert.occurred_at).toLocaleString('ja-JP', { timeZone: 'Asia/Tokyo' }) }}（日本時間）</time></span>
        <button type="button" :disabled="busy" class="min-h-11 rounded-lg border border-amber-600 px-3 text-sm disabled:opacity-50" @click="markRead(alert)">確認済みにする</button>
      </li>
    </ul>
    <NuxtLink to="/settings#family-members" class="mt-2 inline-flex min-h-11 items-center text-blue-700 underline dark:text-blue-300" @click="close">メンバー管理を開く</NuxtLink>
    <p v-if="error" role="alert" class="text-sm text-red-700 dark:text-red-300">{{ error }}</p>
  </dialog>
</template>

<style scoped>
.security-alert-dialog {
  margin: auto;
  width: min(48rem, calc(100% - 2rem));
  max-height: 85dvh;
  overflow-y: auto;
}
.security-alert-dialog::backdrop { background: rgb(15 23 42 / 55%); }
</style>
