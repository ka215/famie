<script setup lang="ts">
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
const errorMessage = ref('')
const isLoading = ref(false)
const usernameStatus = ref<'idle' | 'checking' | 'available' | 'unavailable' | 'error'>('idle')
const usernamePattern = /^[a-z0-9](?:[a-z0-9._-]{1,48}[a-z0-9])$/
let usernameTimer: ReturnType<typeof setTimeout> | undefined
let usernameRequestId = 0

const checkUsername = async (immediate = false) => {
  if (usernameTimer) clearTimeout(usernameTimer)
  const username = form.value.username
  if (!usernamePattern.test(username)) {
    usernameStatus.value = 'idle'
    return
  }
  const requestId = ++usernameRequestId
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
      if (requestId === usernameRequestId) usernameStatus.value = 'error'
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
  if (usernameTimer) clearTimeout(usernameTimer)
})

const handleRegister = async () => {
  errorMessage.value = ''
  if (form.value.password !== form.value.password_confirmation) {
    errorMessage.value = 'パスワードが一致しません。'
    return
  }
  if (usernameStatus.value === 'unavailable') {
    errorMessage.value = 'このログインIDは既に使用されています。'
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
        ? `登録回数が上限に達しました。約${Math.max(1, Math.ceil(retryAfter / 60))}分後に再試行してください。`
        : Object.values(apiError.data?.errors ?? {}).flat()[0] ||
          apiError.data?.message ||
          '家族を登録できませんでした。時間を置いて再試行してください。'
  } finally {
    isLoading.value = false
  }
}
</script>

<template>
  <main class="min-h-screen bg-slate-100 px-4 py-8 dark:bg-slate-900">
    <div class="mx-auto w-full max-w-sm space-y-5 rounded-2xl bg-white p-6 shadow-md dark:bg-slate-800">
      <div class="text-center">
        <h1 class="text-2xl font-bold text-slate-800 dark:text-slate-100">新しい家族を登録</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">最初の親アカウントも同時に作成します</p>
      </div>
      <p v-if="errorMessage" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-600 dark:bg-red-950 dark:text-red-300">{{ errorMessage }}</p>
      <form class="space-y-3" @submit.prevent="handleRegister">
        <div>
          <label for="register-group-name" class="block text-sm text-slate-700 dark:text-slate-200">家族名</label>
          <input id="register-group-name" v-model="form.group_name" required maxlength="50" placeholder="例: 山田家" aria-describedby="group-name-help" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600">
          <p id="group-name-help" class="mt-1 text-xs text-slate-500 dark:text-slate-400">1〜50文字で入力してください。</p>
        </div>
        <div>
          <label for="register-username" class="block text-sm text-slate-700 dark:text-slate-200">ログインID</label>
          <input id="register-username" v-model="form.username" required minlength="3" maxlength="50" autocomplete="username" placeholder="例: yamada-parent" aria-describedby="username-help username-status" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600" @blur="checkUsername(true)">
          <p id="username-help" class="mt-1 text-xs text-slate-500 dark:text-slate-400">3〜50文字。半角小文字英字・数字・「.」「-」「_」が使えます。先頭と末尾は英数字にしてください。Famie全体で一意である必要があります。</p>
          <p v-if="usernameStatus === 'checking'" id="username-status" role="status" class="mt-1 text-xs text-slate-500 dark:text-slate-400">利用可能か確認中です…</p>
          <p v-else-if="usernameStatus === 'available'" id="username-status" role="status" class="mt-1 text-xs text-emerald-600 dark:text-emerald-300">このログインIDは利用できます。</p>
          <p v-else-if="usernameStatus === 'unavailable'" id="username-status" role="alert" class="mt-1 text-xs text-red-600 dark:text-red-300">このログインIDは既に使用されています。</p>
          <p v-else-if="usernameStatus === 'error'" id="username-status" role="status" class="mt-1 text-xs text-amber-700 dark:text-amber-300">利用状況を確認できませんでした。登録時に再確認します。</p>
        </div>
        <div>
          <label for="register-display-name" class="block text-sm text-slate-700 dark:text-slate-200">親アカウントの表示名</label>
          <input id="register-display-name" v-model="form.display_name" required maxlength="50" autocomplete="name" placeholder="例: おとうさん" aria-describedby="display-name-help" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600">
          <p id="display-name-help" class="mt-1 text-xs text-slate-500 dark:text-slate-400">作成する親アカウントに表示する名前を1〜50文字で入力してください。</p>
        </div>
        <div>
          <label for="register-password" class="block text-sm text-slate-700 dark:text-slate-200">パスワード</label>
          <PasswordInput id="register-password" v-model="form.password" label="パスワード" autocomplete="new-password" required minlength="6" placeholder="6文字以上" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600" />
          <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">6文字以上で入力してください。</p>
        </div>
        <div>
          <label for="register-password-confirmation" class="block text-sm text-slate-700 dark:text-slate-200">パスワード（確認）</label>
          <PasswordInput id="register-password-confirmation" v-model="form.password_confirmation" label="パスワード（確認）" autocomplete="new-password" required minlength="6" placeholder="同じパスワードを再入力" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600" />
        </div>
        <button type="submit" :disabled="isLoading" class="w-full rounded-lg bg-blue-600 py-3 font-medium text-white disabled:opacity-50">
          {{ isLoading ? '登録中...' : '家族を登録する' }}
        </button>
      </form>
      <div class="text-center"><NuxtLink to="/login" class="text-sm text-blue-600 dark:text-blue-300">ログインへ戻る</NuxtLink></div>
    </div>
  </main>
</template>
