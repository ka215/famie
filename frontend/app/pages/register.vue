<script setup lang="ts">
const { t, locale } = useI18n()

import type { ApiRequestError } from '#shared/types/api'
import type { RegisterForm } from '#shared/types/forms'

definePageMeta({ layout: false })

const { register } = useAuth()
const { fetchApi } = useApi()
const form = ref<RegisterForm>({
  group_name: '',
  username: '',
  display_name: '',
  password: '',
  password_confirmation: '',
})
const errorMessage = useUiMessage()
const isLoading = ref(false)
const usernameStatus = ref<'idle' | 'checking' | 'available' | 'unavailable' | 'error'>('idle')
const usernamePattern = /^[a-z0-9](?:[a-z0-9._-]{1,48}[a-z0-9])$/
let usernameTimer: ReturnType<typeof setTimeout> | undefined
let usernameRequestId = 0

const checkUsername = async (immediate = false) => {
  if (usernameTimer) clearTimeout(usernameTimer)
  const requestId = ++usernameRequestId
  usernameStatus.value = 'idle'
  const username = form.value.username
  if (!usernamePattern.test(username)) {
    usernameStatus.value = 'idle'
    return
  }
  const run = async () => {
    usernameStatus.value = 'checking'
    try {
      const response = await fetchApi<{ available: boolean }>(
        '/auth/register/username-availability',
        {
          method: 'POST',
          body: { username },
        }
      )
      if (requestId === usernameRequestId && username === form.value.username) {
        usernameStatus.value = response.available ? 'available' : 'unavailable'
      }
    } catch {
      if (requestId === usernameRequestId && username === form.value.username)
        usernameStatus.value = 'error'
    }
  }
  if (immediate) await run()
  else usernameTimer = setTimeout(run, 500)
}

watch(
  () => form.value.username,
  () => checkUsername()
)
onBeforeUnmount(() => {
  ++usernameRequestId
  if (usernameTimer) clearTimeout(usernameTimer)
})

const handleRegister = async () => {
  errorMessage.value = ''
  if (form.value.password !== form.value.password_confirmation) {
    errorMessage.setKey('validation.passwordMismatch')
    return
  }
  if (usernameStatus.value === 'unavailable') {
    errorMessage.setKey('auth.register.usernameUnavailable')
    return
  }
  isLoading.value = true
  try {
    await register(form.value)
    await navigateTo('/')
  } catch (error: unknown) {
    const apiError = error as ApiRequestError
    const retryAfter = Number(apiError.response?.headers?.get('Retry-After'))
    errorMessage.value =
      apiError.response?.status === 429 && Number.isFinite(retryAfter)
        ? t(
            'auth.register.limited',
            { minutes: Math.max(1, Math.ceil(retryAfter / 60)) },
            Math.max(1, Math.ceil(retryAfter / 60))
          )
        : Object.values(apiError.data?.errors ?? {}).flat()[0] ||
          apiError.data?.message ||
          t('auth.register.failed')
  } finally {
    isLoading.value = false
  }
}
useHead(() => ({ title: `${t('auth.register.title')} | Famie` }))
</script>

<template>
  <main class="min-h-screen bg-slate-100 px-4 py-8 dark:bg-slate-900">
    <div class="relative mx-auto w-full max-w-sm space-y-5 rounded-2xl bg-white p-6 shadow-md dark:bg-slate-800">
      <LanguageSwitcher />
      <div class="pr-8 text-center">
        <h1 class="text-xl sm:text-2xl font-bold text-slate-800 dark:text-slate-100">{{ t('auth.register.title') }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('auth.register.intro') }}</p>
      </div>
      <p v-if="errorMessage" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-600 dark:bg-red-950 dark:text-red-300">{{ errorMessage }}</p>
      <form class="space-y-3" @submit.prevent="handleRegister">
        <div>
          <label for="register-group-name" class="block text-sm text-slate-700 dark:text-slate-200">{{ t('settings.family.name') }}</label>
          <input id="register-group-name" v-model="form.group_name" required maxlength="50" :placeholder="t('auth.register.familyExample')" aria-describedby="group-name-help" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600">
          <p id="group-name-help" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('validation.nameLength') }}</p>
        </div>
        <div>
          <label for="register-username" class="block text-sm text-slate-700 dark:text-slate-200">{{ t('auth.loginId') }}</label>
          <input id="register-username" v-model="form.username" required minlength="3" maxlength="50" autocomplete="username" :placeholder="t('auth.register.usernameExample')" aria-describedby="username-help username-status" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600" @blur="checkUsername(true)">
          <p id="username-help" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('auth.register.usernameHelp') }}</p>
          <p v-if="usernameStatus === 'checking'" id="username-status" role="status" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('auth.register.checking') }}</p>
          <p v-else-if="usernameStatus === 'available'" id="username-status" role="status" class="mt-1 text-xs text-emerald-600 dark:text-emerald-300">{{ t('auth.register.available') }}</p>
          <p v-else-if="usernameStatus === 'unavailable'" id="username-status" role="alert" class="mt-1 text-xs text-red-600 dark:text-red-300">{{ t('auth.register.usernameUnavailable') }}</p>
          <p v-else-if="usernameStatus === 'error'" id="username-status" role="status" class="mt-1 text-xs text-amber-700 dark:text-amber-300">{{ t('auth.register.checkFailed') }}</p>
        </div>
        <div>
          <label for="register-display-name" class="block text-sm text-slate-700 dark:text-slate-200">{{ t('auth.register.parentName') }}</label>
          <input id="register-display-name" v-model="form.display_name" required maxlength="50" autocomplete="name" :placeholder="t('auth.register.parentExample')" aria-describedby="display-name-help" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600">
          <p id="display-name-help" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('auth.register.displayNameHelp') }}</p>
        </div>
        <div>
          <label for="register-password" class="block text-sm text-slate-700 dark:text-slate-200">{{ t('auth.password') }}</label>
          <PasswordInput id="register-password" v-model="form.password" strength :label="t('auth.password')" autocomplete="new-password" required minlength="15" :placeholder="t('validation.passwordMinimum')" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600" />
          <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('auth.register.passwordHelp') }}</p>
        </div>
        <div>
          <label for="register-password-confirmation" class="block text-sm text-slate-700 dark:text-slate-200">{{ t('auth.confirmPassword') }}</label>
          <PasswordInput id="register-password-confirmation" v-model="form.password_confirmation" :label="t('auth.confirmPassword')" autocomplete="new-password" required minlength="15" :placeholder="t('auth.repeatPassword')" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600" />
        </div>
        <button type="submit" :disabled="isLoading" class="w-full rounded-lg bg-blue-600 py-3 font-medium text-white disabled:opacity-50">
          {{ isLoading ? t('auth.register.busy') : t('auth.register.submit') }}
        </button>
      </form>
      <div class="text-center"><NuxtLink to="/login" class="text-sm text-blue-600 dark:text-blue-300">{{ t('auth.backToLogin') }}</NuxtLink></div>
    </div>
  </main>
</template>
