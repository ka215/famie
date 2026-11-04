<script setup lang="ts">
import type { ApiRequestError, DataResponse, LifecycleStatus } from '#shared/types/api'

definePageMeta({ layout: false })
const { t } = useI18n()
const { fetchApi } = useApi()
const remembered = useState<{ receipt_id: string; confirmation_code: string } | null>(
  'lifecycle_status_receipt',
  () => null
)
const receiptId = ref(remembered.value?.receipt_id ?? '')
const confirmationCode = ref(remembered.value?.confirmation_code ?? '')
const status = ref<LifecycleStatus | null>(null)
const error = ref('')
const busy = ref(false)

const check = async () => {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    const response = await fetchApi<DataResponse<LifecycleStatus>>('/lifecycle-operation-status', {
      method: 'POST',
      body: { receipt_id: receiptId.value, confirmation_code: confirmationCode.value },
    })
    status.value = response.data
  } catch (cause: unknown) {
    status.value = null
    error.value = (cause as ApiRequestError).data?.message ?? t('settings.lifecycle.statusNotFound')
  } finally {
    busy.value = false
  }
}

onMounted(() => {
  if (receiptId.value && confirmationCode.value) check()
})
useHead(() => ({
  title: `${t('settings.lifecycle.statusTitle')} | Famie`,
  meta: [{ name: 'referrer', content: 'no-referrer' }],
}))
</script>

<template>
  <main class="min-h-screen bg-slate-50 px-4 py-10 text-slate-800 dark:bg-slate-950 dark:text-slate-100">
    <section class="relative mx-auto max-w-lg space-y-5 rounded-2xl bg-white p-6 shadow dark:bg-slate-800">
      <LanguageSwitcher />
      <h1 class="pr-12 text-xl font-bold">{{ t('settings.lifecycle.statusTitle') }}</h1>
      <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('settings.lifecycle.statusHelp') }}</p>
      <form class="space-y-4" @submit.prevent="check">
        <div><label for="lifecycle-receipt" class="block text-sm">{{ t('settings.lifecycle.receipt') }}</label><input id="lifecycle-receipt" v-model="receiptId" autocomplete="off" required class="mt-1 w-full rounded-lg border border-slate-400 px-3 py-2"></div>
        <div><label for="lifecycle-code" class="block text-sm">{{ t('settings.lifecycle.code') }}</label><input id="lifecycle-code" v-model="confirmationCode" autocomplete="off" required class="mt-1 w-full rounded-lg border border-slate-400 px-3 py-2"></div>
        <button type="submit" :disabled="busy" class="min-h-11 w-full rounded-lg bg-blue-600 text-white disabled:opacity-50">{{ busy ? t('common.processing') : t('settings.lifecycle.checkStatus') }}</button>
      </form>
      <p v-if="error" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ error }}</p>
      <dl v-if="status" class="space-y-2 rounded-lg bg-slate-100 p-4 text-sm dark:bg-slate-900">
        <div><dt class="font-semibold">{{ t('settings.lifecycle.state') }}</dt><dd>{{ t(`settings.lifecycle.states.${status.state}`) }}</dd></div>
        <div><dt class="font-semibold">{{ t('settings.lifecycle.cleanup') }}</dt><dd>{{ t(`settings.lifecycle.cleanupStates.${status.cleanup_state}`) }}</dd></div>
        <div v-if="status.committed_at"><dt class="font-semibold">{{ t('settings.lifecycle.committedAt') }}</dt><dd>{{ new Date(status.committed_at).toLocaleString() }}</dd></div>
      </dl>
      <div class="text-center">
        <NuxtLink to="/login" class="text-sm text-blue-600 dark:text-blue-300">{{ t('auth.backToLogin') }}</NuxtLink>
      </div>
    </section>
  </main>
</template>
