<script setup lang="ts">
const { t, locale } = useI18n()

import type { ApiRequestError, MessageResponse } from '#shared/types/api'

definePageMeta({ layout: false, scrollToTop: false })
useHead({ meta: [{ name: 'referrer', content: 'no-referrer' }] })
const { id, token, valid } = useAccountLink()
const { fetchApi } = useApi()
const busy = ref(false)
const message = ref('')
const error = ref('')
async function confirm() {
  if (busy.value || !valid.value || message.value) return
  busy.value = true
  error.value = ''
  try {
    const result = await fetchApi<MessageResponse>('/auth/email/verify', {
      method: 'POST',
      body: { id: id.value, token: token.value },
    })
    message.value = result.message
    token.value = ''
  } catch (err) {
    error.value = (err as ApiRequestError).data?.message ?? t('auth.verify.failed')
  } finally {
    busy.value = false
  }
}
useHead(() => ({ title: `${t('auth.verify.title')} | Famie` }))
watch(locale, () => {
  message.value = ''
  error.value = ''
})
</script>

<template>
  <AccountRecoveryPanel :title="t('auth.verify.title')">
    <p v-if="locale !== 'ja'" class="text-xs text-slate-600 dark:text-slate-300">{{ t('auth.mail.languageNote') }}</p>
    <p v-if="message" role="status">{{ message }}</p>
    <template v-else>
      <p class="text-sm">{{ t('auth.verify.intro') }}</p>
      <p v-if="!valid" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ t('auth.link.missing') }}</p>
      <p v-if="error" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ error }}</p>
      <button type="button" :disabled="busy || !valid" class="w-full rounded-lg bg-blue-600 py-3 text-white disabled:opacity-50" @click="confirm">{{ busy ? t('auth.verify.busy') : t('auth.verify.submit') }}</button>
    </template>
  </AccountRecoveryPanel>
</template>
