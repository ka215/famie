<script setup lang="ts">
const { t, locale } = useI18n()

import type { ApiRequestError } from '#shared/types/api'

const { fetchApi } = useApi()
const isChecking = ref(false)
const message = ref('')

const retry = async () => {
  if (isChecking.value) return
  isChecking.value = true
  message.value = ''
  try {
    const result = await fetchApi<{ status: string }>('/status', { cache: 'no-store' })
    if (result.status !== 'ok') throw new Error('Unexpected status')
    window.location.reload()
  } catch (error: unknown) {
    const response = error as ApiRequestError
    message.value =
      response.statusCode === 503 && response.data?.code === 'maintenance'
        ? t('errors.maintenance.stillDown')
        : t('errors.maintenance.checkFailed')
  } finally {
    isChecking.value = false
  }
}
watch(locale, () => {
  message.value = ''
})
</script>

<template>
  <main class="min-h-screen bg-slate-50 dark:bg-slate-950 flex items-center justify-center px-6">
    <section class="max-w-md w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl p-8 shadow-sm text-center" aria-labelledby="maintenance-title">
      <p class="text-blue-600 dark:text-blue-300 font-bold mb-4">Famie</p>
      <h1 id="maintenance-title" class="text-xl font-bold text-slate-800 dark:text-slate-100">{{ t('errors.maintenance.title') }}</h1>
      <p class="mt-4 text-slate-600 dark:text-slate-300">{{ t('errors.maintenance.retryHelp') }}</p>
      <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ t('errors.maintenance.unsaved') }}</p>
      <button type="button" class="mt-6 rounded-lg bg-blue-600 text-white px-6 py-3 disabled:opacity-50" :disabled="isChecking" @click="retry">
        {{ isChecking ? t('common.checking') : t('common.retry') }}
      </button>
      <p role="status" class="mt-4 text-sm text-slate-600 dark:text-slate-300">{{ message }}</p>
    </section>
  </main>
</template>
