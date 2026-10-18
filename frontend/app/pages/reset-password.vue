<script setup lang="ts">
const { t, locale } = useI18n()

import type { ApiRequestError, MessageResponse } from '#shared/types/api'

definePageMeta({ layout: false, scrollToTop: false })
useHead({ meta: [{ name: 'referrer', content: 'no-referrer' }] })
const { id, token, valid } = useAccountLink()
const { fetchApi } = useApi()
const auth = useAuth()
const password = ref('')
const confirmation = ref('')
const busy = ref(false)
const message = ref('')
const error = useUiMessage()
async function submit() {
  if (busy.value || !valid.value || message.value) return
  error.value = ''
  if (password.value !== confirmation.value) {
    error.setKey('validation.passwordMismatch')
    return
  }
  busy.value = true
  try {
    const result = await fetchApi<MessageResponse>('/auth/password/reset', {
      method: 'POST',
      body: {
        id: id.value,
        token: token.value,
        password: password.value,
        password_confirmation: confirmation.value,
      },
    })
    message.value = result.message
    if (auth.user.value && String(auth.user.value.id) === id.value) {
      auth.token.value = null
      auth.user.value = null
      auth.membership.value = null
    }
    token.value = ''
    password.value = ''
    confirmation.value = ''
  } catch (err) {
    error.value = (err as ApiRequestError).data?.message ?? t('auth.reset.failed')
  } finally {
    busy.value = false
  }
}
useHead(() => ({ title: `${t('auth.reset.title')} | Famie` }))
watch(locale, () => {
  message.value = ''
})
</script>

<template>
  <AccountRecoveryPanel :title="t('auth.reset.title')">
    <p v-if="locale !== 'ja'" class="text-xs text-slate-600 dark:text-slate-300">{{ t('auth.mail.languageNote') }}</p>
    <p v-if="message" role="status" class="text-sm">{{ message }}</p>
    <template v-else>
      <p v-if="!valid" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ t('auth.link.missing') }}</p>
      <p v-if="error" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ error }}</p>
      <form class="space-y-4" @submit.prevent="submit">
        <div>
          <label for="reset-password" class="block text-sm">{{ t('auth.newPassword') }}</label>
          <PasswordInput id="reset-password" v-model="password" strength :label="t('auth.newPassword')" autocomplete="new-password" required minlength="15" :disabled="busy" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600" />
        </div>
        <div>
          <label for="reset-confirmation" class="block text-sm">{{ t('auth.confirmNewPassword') }}</label>
          <PasswordInput id="reset-confirmation" v-model="confirmation" :label="t('auth.confirmNewPassword')" autocomplete="new-password" required minlength="15" :disabled="busy" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600" />
        </div>
        <button type="submit" :disabled="busy || !valid" class="w-full rounded-lg bg-blue-600 py-3 text-white disabled:opacity-50">{{ busy ? t('common.updating') : t('auth.reset.submit') }}</button>
      </form>
    </template>
    <NuxtLink to="/forgot-password" class="block text-sm text-blue-600 dark:text-blue-300">{{ t('auth.reset.resend') }}</NuxtLink>
  </AccountRecoveryPanel>
</template>
