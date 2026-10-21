<script setup lang="ts">
const { t, locale } = useI18n()

import type { ApiRequestError, CurrentUserResponse } from '#shared/types/api'

const { user, token } = useAuth()
const { fetchApi } = useApi()
const { isMaintenance } = useMaintenance()
const route = useRoute()
const dismissedFor = ref('')
const snooze = ref(false)
const editing = ref(false)
const busy = ref(false)
const error = useUiMessage()
const now = ref(Date.now())
const form = ref({ current_password: '', new_password: '', new_password_confirmation: '' })
const owner = computed(() => `${user.value?.id ?? ''}:${token.value ?? ''}`)
const visit = computed(() => `${owner.value}:${route.fullPath}`)
const visible = computed(() => {
  const security = user.value?.password_security
  return (
    !!token.value &&
    !!security &&
    security.status !== 'ok' &&
    !isMaintenance.value &&
    !['/login', '/register', '/reset-password', '/verify-email', '/forgot-password'].includes(
      route.path.replace(/\/+$/, '')
    ) &&
    dismissedFor.value !== visit.value &&
    (!security.snoozed_until || Date.parse(security.snoozed_until) <= now.value)
  )
})

const reset = () => {
  snooze.value = false
  editing.value = false
  error.value = ''
  form.value = { current_password: '', new_password: '', new_password_confirmation: '' }
}
watch([() => route.fullPath, owner], () => {
  now.value = Date.now()
  dismissedFor.value = ''
  reset()
})
watch(isMaintenance, (active) => {
  if (active) reset()
})

async function close() {
  if (busy.value) return
  if (isMaintenance.value) {
    reset()
    return
  }
  if (!snooze.value) {
    dismissedFor.value = visit.value
    reset()
    return
  }
  busy.value = true
  error.value = ''
  const requestOwner = owner.value
  try {
    const response = await fetchApi<CurrentUserResponse>('/auth/me/password-warning/snooze', {
      method: 'POST',
    })
    if (requestOwner === owner.value) {
      user.value = response.user
      reset()
    }
  } catch (cause) {
    if (requestOwner === owner.value) {
      error.value = (cause as ApiRequestError).data?.message ?? t('auth.security.snoozeFailed')
    }
  } finally {
    busy.value = false
  }
}

async function change() {
  if (busy.value) return
  error.value = ''
  if (form.value.new_password !== form.value.new_password_confirmation) {
    error.setKey('validation.newPasswordMismatch')
    return
  }
  busy.value = true
  const requestOwner = owner.value
  try {
    await fetchApi('/auth/me/password', { method: 'PUT', body: { ...form.value } })
    if (requestOwner === owner.value) {
      if (user.value) {
        user.value.password_security = { status: 'ok', snoozed_until: null }
        user.value.pending_email = null
        user.value.email_verification_expires_at = null
      }
      reset()
    }
  } catch (cause) {
    if (requestOwner === owner.value) {
      const apiError = cause as ApiRequestError
      error.value =
        Object.values(apiError.data?.errors ?? {}).flat()[0] ??
        apiError.data?.message ??
        t('auth.security.changeFailed')
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <SettingsDialog v-if="visible" :title="t('auth.security.title')" :busy="busy" @close="close">
    <div class="space-y-4">
      <p v-if="user?.password_security?.status === 'weak'" class="text-sm">{{ t('auth.security.weak') }}</p>
      <p v-else class="text-sm">{{ t('auth.security.unknown') }}</p>
      <p v-if="error" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ error }}</p>
      <form v-if="editing" class="space-y-3" @submit.prevent="change">
        <div><label for="security-current-password" class="block text-sm">{{ t('auth.currentPassword') }}</label><PasswordInput id="security-current-password" v-model="form.current_password" :label="t('auth.currentPassword')" autocomplete="current-password" required :disabled="busy" class="mt-1 w-full rounded-lg border border-slate-400 px-3 py-2" /></div>
        <div><label for="security-new-password" class="block text-sm">{{ t('auth.newPassword') }}</label><PasswordInput id="security-new-password" v-model="form.new_password" :label="t('auth.newPassword')" autocomplete="new-password" required minlength="15" strength :disabled="busy" class="mt-1 w-full rounded-lg border border-slate-400 px-3 py-2" /></div>
        <div><label for="security-confirmation" class="block text-sm">{{ t('auth.confirmNewPassword') }}</label><PasswordInput id="security-confirmation" v-model="form.new_password_confirmation" :label="t('auth.confirmNewPassword')" autocomplete="new-password" required minlength="15" :disabled="busy" class="mt-1 w-full rounded-lg border border-slate-400 px-3 py-2" /></div>
        <button type="submit" :disabled="busy" class="min-h-11 rounded-lg bg-blue-600 px-4 py-2 text-white disabled:opacity-50">{{ busy ? t('common.changing') : t('settings.password.submit') }}</button>
      </form>
      <button v-else type="button" class="min-h-11 rounded-lg bg-blue-600 px-4 py-2 text-white" @click="editing = true">{{ t('auth.security.change') }}</button>
      <label class="flex min-h-11 items-center gap-2 text-sm"><input v-model="snooze" type="checkbox" :disabled="busy">{{ t('auth.security.snooze') }}</label>
      <button type="button" :disabled="busy" class="min-h-11 rounded-lg border border-slate-400 px-4 py-2 disabled:opacity-50" @click="close">{{ t('common.later') }}</button>
    </div>
  </SettingsDialog>
</template>
