<script setup lang="ts">
import type { ApiRequestError } from '#shared/types/api'

definePageMeta({
  layout: false,
})

const { login } = useAuth()
const loginId = ref('')
const password = ref('')
const errorMessage = ref('')
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
  <div class="min-h-screen bg-slate-100 flex flex-col items-center gap-4 px-4 py-8">
    <div class="w-full max-w-sm bg-white rounded-2xl shadow-md p-6 space-y-6">
      <div class="text-center space-y-1">
        <h1 class="text-2xl font-bold text-slate-800">Famie</h1>
        <p class="text-sm text-slate-500">家族のアクティビティ記録</p>
      </div>

      <form class="space-y-4" @submit.prevent="handleLogin">
        <div v-if="errorMessage" class="p-3 bg-red-50 text-red-600 text-sm rounded-lg border border-red-200">
          {{ errorMessage }}
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">ログインID / メールアドレス</label>
          <input
            v-model="loginId"
            type="text"
            required
            placeholder="例: parent1"
            class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-blue-500 focus:outline-none"
          >
        </div>

        <div>
          <label for="login-password" class="block text-sm font-medium text-slate-700 mb-1">パスワード</label>
          <PasswordInput
            id="login-password"
            label="パスワード"
            v-model="password"
            autocomplete="current-password"
            required
            class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-blue-500 focus:outline-none"
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
    </div>
    <HomeInstallGuide class="w-full max-w-sm" />
  </div>
</template>
