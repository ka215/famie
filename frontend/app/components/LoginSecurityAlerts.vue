<script setup lang="ts">
const { t } = useI18n()
const { intlLocale } = useUiLocale()
const { state, alerts, visible, close: closeAlerts, refresh, markRead, error } = useSecurityAlerts()
const route = useRoute()
const dialog = ref<HTMLDialogElement | null>(null)
const busy = computed(() => state.value.busy || state.value.loading)
let returnFocus: HTMLElement | null = null
let returnRow: HTMLElement | null = null
function close() {
  if (!state.value.busy) closeAlerts()
}
watch(
  visible,
  (value, previous) => {
    if (value && !previous) {
      returnFocus = document.activeElement as HTMLElement | null
      returnRow = returnFocus?.closest<HTMLElement>('[data-alert-focus]') ?? null
    }
    if (!value && previous) {
      const target = returnFocus?.isConnected ? returnFocus : returnRow
      nextTick(() => target?.focus())
    }
  },
  { flush: 'sync' }
)
watch(
  dialog,
  (element) => {
    if (element && !element.open) element.showModal()
  },
  { flush: 'post' }
)
watch(
  () => route.fullPath,
  () => void refresh()
)
watch(
  () => state.value.owner,
  () => {
    if (import.meta.client) void refresh()
  }
)
let interval: ReturnType<typeof setInterval> | undefined
const onFocus = () => void refresh()
onMounted(() => {
  void refresh()
  interval = setInterval(() => void refresh(), 60_000)
  window.addEventListener('focus', onFocus)
})
onBeforeUnmount(() => {
  dialog.value?.close()
  clearInterval(interval)
  window.removeEventListener('focus', onFocus)
})
</script>

<template>
  <dialog v-if="visible" ref="dialog" :aria-label="t('auth.alerts.label')" :aria-busy="busy" class="security-alert-dialog rounded-lg border border-amber-400 bg-amber-50 p-4 text-slate-900 dark:bg-amber-950 dark:text-slate-100" @cancel.prevent="close">
    <div class="flex items-center justify-between gap-4">
      <h2 class="font-semibold">{{ t('auth.alerts.title') }}</h2>
      <button type="button" :disabled="busy" class="min-h-11 shrink-0 rounded-lg border border-amber-600 px-3 text-sm disabled:opacity-50" @click="close">{{ t('common.close') }}</button>
    </div>
    <p class="mt-2 text-sm">{{ t('auth.alerts.intro') }}</p>
    <p v-if="state.total > 20" class="mt-2 text-sm">{{ t('auth.alerts.latest', { count: state.total }) }}</p>
    <ul class="mt-2 space-y-2">
      <li v-for="alert in alerts" :key="alert.id" class="flex flex-wrap items-center justify-between gap-2 border-t border-amber-300 pt-2">
        <span class="min-w-0 break-all text-sm">{{ alert.display_name }}（@{{ alert.username }}）<time class="ml-2" :datetime="alert.occurred_at">{{ new Date(alert.occurred_at).toLocaleString(intlLocale, { timeZone: 'Asia/Tokyo' }) }}{{ t('common.japanTime') }}</time></span>
        <button type="button" :disabled="busy" class="min-h-11 rounded-lg border border-amber-600 px-3 text-sm disabled:opacity-50" @click="markRead(alert)">{{ t('auth.alerts.markRead') }}</button>
      </li>
    </ul>
    <NuxtLink to="/settings#family-members" class="mt-2 inline-flex min-h-11 items-center text-blue-700 underline dark:text-blue-300" @click="close">{{ t('auth.alerts.openMembers') }}</NuxtLink>
    <p v-if="error" role="alert" class="text-sm text-red-700 dark:text-red-300">{{ error }}</p>
    <button v-if="error" type="button" :disabled="busy" class="min-h-11 underline" @click="refresh(true)">{{ t('common.retry') }}</button>
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
