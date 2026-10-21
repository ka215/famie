<script setup lang="ts">
const { t, locale } = useI18n()

import type { ApiRequestError } from '#shared/types/api'

definePageMeta({
  layout: false,
})

const { login, accessError } = useAuth()
const loginId = ref('')
const password = ref('')
const errorMessage = useUiMessage(accessError.value)
accessError.value = ''
const isLoading = ref(false)

const handleLogin = async () => {
  if (!loginId.value || !password.value) {
    errorMessage.setKey('auth.login.required')
    return
  }

  isLoading.value = true
  errorMessage.value = ''

  try {
    await login(loginId.value, password.value)
    await navigateTo('/')
  } catch (err: unknown) {
    const e = err as ApiRequestError
    const retryAfter = Number(e.response?.headers?.get('Retry-After'))
    errorMessage.value =
      e.response?.status === 429 && Number.isFinite(retryAfter) && retryAfter > 0
        ? t(
            'auth.login.limited',
            { minutes: Math.ceil(retryAfter / 60) },
            Math.ceil(retryAfter / 60)
          )
        : e.data?.message || t('auth.login.failed')
  } finally {
    isLoading.value = false
  }
}
useHead(() => ({ title: `${t('auth.login.title')} | Famie` }))
</script>

<template>
  <div class="min-h-screen bg-slate-100 dark:bg-slate-900 flex flex-col items-center gap-4 px-4 py-8">
    <div class="relative w-full max-w-sm bg-white dark:bg-slate-800 rounded-2xl shadow-md p-6 space-y-6">
      <LanguageSwitcher />
      <div class="px-9 text-center space-y-1">
        <h1 class="text-2xl font-bold text-slate-800 dark:text-slate-100">Famie</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('auth.tagline') }}</p>
      </div>

      <form class="space-y-4" @submit.prevent="handleLogin">
        <div v-if="errorMessage" role="alert" class="p-3 bg-red-50 dark:bg-red-950 text-red-600 dark:text-red-300 text-sm rounded-lg border border-red-200 dark:border-red-800">
          {{ errorMessage }}
        </div>

        <div>
          <label for="login-identity" class="block text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">{{ t('auth.login.identity') }}</label>
          <input
            id="login-identity"
            v-model="loginId"
            type="text"
            required
            :placeholder="t('auth.login.example')"
            class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 focus:ring-2 focus:ring-blue-500 focus:outline-none"
          >
        </div>

        <div>
          <label for="login-password" class="block text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">{{ t('auth.password') }}</label>
          <PasswordInput
            id="login-password"
            :label="t('auth.password')"
            v-model="password"
            autocomplete="current-password"
            required
            class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 focus:ring-2 focus:ring-blue-500 focus:outline-none"
          />
        </div>

        <button
          type="submit"
          :disabled="isLoading"
          class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition duration-200 disabled:opacity-50"
        >
          <span v-if="isLoading">{{ t('auth.login.busy') }}</span>
          <span v-else>{{ t('auth.login.title') }}</span>
        </button>
      </form>
      <div class="px-9 text-center">
        <NuxtLink to="/forgot-password" class="text-sm text-blue-600 dark:text-blue-300">{{ t('auth.forgot.link') }}</NuxtLink>
      </div>
      <div class="border-t border-slate-200 pt-4 text-center dark:border-slate-700">
        <NuxtLink to="/register" class="text-sm font-medium text-blue-600 dark:text-blue-300">{{ t('auth.register.title') }}</NuxtLink>
      </div>
    </div>
    <HomeInstallGuide class="w-full max-w-sm" />
  </div>
</template>
