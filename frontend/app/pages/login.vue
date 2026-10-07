<script setup lang="ts">
import type { ApiRequestError } from '#shared/types/api'

definePageMeta({
  layout: false,
})

const { login, accessError } = useAuth()
const loginId = ref('')
const password = ref('')
const errorMessage = ref(accessError.value)
accessError.value = ''
const isLoading = ref(false)

const handleLogin = async () => {
  if (!loginId.value || !password.value) {
    errorMessage.value = 'ログインIDとパスワードを入力してください。'
    return
  }

  isLoading.value = true
  errorMessage.value = ''

  try {
    await login(loginId.value, password.value)
    await navigateTo('/')
  } catch (err: unknown) {
    const e = err as ApiRequestError
    errorMessage.value = e.data?.message || 'ログインに失敗しました。'
  } finally {
    isLoading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-slate-100 dark:bg-slate-900 flex flex-col items-center gap-4 px-4 py-8">
    <div class="w-full max-w-sm bg-white dark:bg-slate-800 rounded-2xl shadow-md p-6 space-y-6">
      <div class="text-center space-y-1">
        <h1 class="text-2xl font-bold text-slate-800 dark:text-slate-100">Famie</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">家族のアクティビティ記録</p>
      </div>

      <form class="space-y-4" @submit.prevent="handleLogin">
        <div v-if="errorMessage" class="p-3 bg-red-50 dark:bg-red-950 text-red-600 dark:text-red-300 text-sm rounded-lg border border-red-200 dark:border-red-800">
          {{ errorMessage }}
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">ログインID / メールアドレス</label>
          <input
            v-model="loginId"
            type="text"
            required
            placeholder="例: parent1"
            class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 focus:ring-2 focus:ring-blue-500 focus:outline-none"
          >
        </div>

        <div>
          <label for="login-password" class="block text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">パスワード</label>
          <PasswordInput
            id="login-password"
            label="パスワード"
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
          <span v-if="isLoading">ログイン中...</span>
          <span v-else>ログイン</span>
        </button>
      </form>
      <div class="text-center">
        <NuxtLink to="/forgot-password" class="text-sm text-blue-600 dark:text-blue-300">パスワードを忘れた方</NuxtLink>
      </div>
      <div class="border-t border-slate-200 pt-4 text-center dark:border-slate-700">
        <NuxtLink to="/register" class="text-sm font-medium text-blue-600 dark:text-blue-300">新しい家族を登録</NuxtLink>
      </div>
    </div>
    <HomeInstallGuide class="w-full max-w-sm" />
  </div>
</template>
