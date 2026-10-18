<script setup lang="ts">
import type { NuxtError } from '#app'

const props = defineProps<{ error: NuxtError }>()
const { t } = useI18n()
const { effective } = useUiLocale()
const errorKey = computed(() => {
  const reason = (props.error.data as { reason?: string } | undefined)?.reason
  if (reason === 'auth-check') return 'errors.authCheckFailed'
  if (reason === 'status-check') return 'errors.statusCheckFailed'
  return props.error.statusCode === 404 ? 'errors.notFound' : 'errors.general'
})
const retryRequired = computed(() => props.error.statusCode === 503)
const recover = () => {
  if (retryRequired.value) {
    window.location.reload()
    return
  }
  return clearError({ redirect: '/' })
}
useHead(() => ({ htmlAttrs: { lang: effective.value }, title: `${t(errorKey.value)} | Famie` }))
</script>

<template>
  <main class="min-h-screen bg-slate-100 dark:bg-slate-900 flex items-center justify-center p-4">
    <div class="relative w-full max-w-sm bg-white dark:bg-slate-800 rounded-2xl shadow-md p-6 text-center space-y-4">
      <LanguageSwitcher />
      <h1 class="px-9 text-xl font-bold text-slate-800 dark:text-slate-100">Famie</h1>
      <p class="text-sm text-slate-600 dark:text-slate-300">{{ t(errorKey) }}</p>
      <button type="button" class="w-full min-h-11 py-2.5 bg-blue-600 text-white rounded-lg" @click="recover">{{ t(retryRequired ? 'common.retry' : 'common.home') }}</button>
    </div>
  </main>
</template>
