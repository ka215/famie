<script setup lang="ts">
const { t, locale } = useI18n()

import type { ApiRequestError, MessageResponse } from '#shared/types/api'

const { user, fetchUser } = useAuth()
const { fetchApi } = useApi()
const email = ref('')
const password = ref('')
const busy = ref(false)
const message = ref('')
const error = ref('')
const refreshError = ref('')

async function submit(action: 'request' | 'resend' | 'cancel') {
  if (busy.value) return
  busy.value = true
  message.value = ''
  error.value = ''
  refreshError.value = ''
  try {
    const result = await fetchApi<MessageResponse>(
      action === 'resend' ? '/auth/me/email/resend' : '/auth/me/email',
      {
        method: action === 'cancel' ? 'DELETE' : 'POST',
        ...(action === 'request'
          ? { body: { email: email.value.trim(), current_password: password.value } }
          : {}),
      }
    )
    message.value = result.message
    password.value = ''
  } catch (err) {
    error.value = (err as ApiRequestError).data?.message ?? t('errors.operationFailed')
  } finally {
    try {
      await fetchUser()
    } catch {
      refreshError.value = t('errors.refreshFailed')
    }
    busy.value = false
  }
}
watch(locale, () => {
  message.value = ''
  error.value = ''
  refreshError.value = ''
})
</script>

<template>
  <SettingsCard :title="t('auth.email')">
    <p v-if="locale !== 'ja'" class="text-xs text-slate-600 dark:text-slate-300">{{ t('auth.mail.languageNote') }}</p>
    <p class="text-sm break-all">{{ user?.email || t('settings.email.notRegistered') }} <span v-if="user?.email">（{{ user.email_verified_at ? t('settings.email.verified') : t('settings.email.unverified') }}）</span></p>
    <p v-if="!user?.email_verified_at" class="text-sm text-slate-600 dark:text-slate-300">{{ t('settings.email.intro') }}</p>
    <p v-if="message" role="status" class="text-sm text-green-700 dark:text-green-300">{{ message }}</p>
    <p v-if="error" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ error }}</p>
    <p v-if="refreshError" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ refreshError }}</p>
    <div v-if="user?.pending_email" class="space-y-2 rounded-lg bg-slate-100 p-3 text-sm dark:bg-slate-900">
      <p class="break-all">{{ t('settings.email.pending') }}{{ user.pending_email }}</p>
      <p>{{ t('settings.email.expiryHelp') }}</p>
      <p>{{ t('settings.email.deliveryHelp') }}</p>
      <div class="flex flex-wrap gap-4">
        <button type="button" :disabled="busy" class="text-blue-600 disabled:opacity-50 dark:text-blue-300" @click="submit('resend')">{{ t('settings.email.resend') }}</button>
        <button type="button" :disabled="busy" class="text-red-600 disabled:opacity-50 dark:text-red-300" @click="submit('cancel')">{{ t('settings.email.cancel') }}</button>
      </div>
    </div>
    <form class="space-y-3" @submit.prevent="submit('request')">
      <div>
        <label for="account-email" class="block text-sm">{{ t('settings.email.newAddress') }}</label>
        <input id="account-email" v-model="email" type="email" autocomplete="email" required maxlength="255" :disabled="busy" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600">
      </div>
      <p v-if="user?.email && !user.email_verified_at" class="text-xs text-slate-600 dark:text-slate-300">{{ t('settings.email.sameAddressHelp') }}</p>
      <div>
        <label for="email-current-password" class="block text-sm">{{ t('settings.email.passwordLabel') }}</label>
        <PasswordInput id="email-current-password" v-model="password" :label="t('settings.email.passwordLabel')" autocomplete="current-password" required :disabled="busy" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600" />
      </div>
      <p class="text-xs text-slate-600 dark:text-slate-300">{{ t('settings.email.passwordHelp') }}</p>
      <button type="submit" :disabled="busy" class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white disabled:opacity-50">{{ busy ? t('common.processing') : t('settings.email.send') }}</button>
    </form>
  </SettingsCard>
</template>
