<script setup lang="ts">
import type { ApiRequestError, MessageResponse } from '#shared/types/api'

definePageMeta({ layout: false })
const { fetchApi } = useApi()
const email = ref('')
const busy = ref(false)
const message = ref('')
const error = ref('')
async function submit() {
  if (busy.value) return
  busy.value = true
  message.value = ''
  error.value = ''
  try {
    const result = await fetchApi<MessageResponse>('/auth/password/forgot', {
      method: 'POST',
      body: { email: email.value.trim() },
    })
    message.value = result.message
  } catch (err) {
    const failure = err as ApiRequestError
    error.value =
      failure.response?.status === 429
        ? '送信回数が多すぎます。60秒待って再試行してください。'
        : (failure.data?.message ?? '送信できませんでした。通信環境を確認してください。')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AccountRecoveryPanel title="パスワードの再設定">
    <p class="text-sm">確認済みのメールアドレスに再設定リンクを送信します。</p>
    <p v-if="message" role="status" class="text-sm">{{ message }}</p>
    <p v-if="error" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ error }}</p>
    <form class="space-y-4" @submit.prevent="submit">
      <div>
        <label for="recovery-email" class="block text-sm">メールアドレス</label>
        <input id="recovery-email" v-model="email" type="email" autocomplete="email" required maxlength="255" :disabled="busy" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600">
      </div>
      <button type="submit" :disabled="busy" class="w-full rounded-lg bg-blue-600 py-3 text-white disabled:opacity-50">{{ busy ? '送信中...' : '再設定メールを送信' }}</button>
    </form>
    <p class="text-xs text-slate-600 dark:text-slate-300">メール未登録・未認証のアカウントでは利用できません。ログインできる場合は設定画面からメールを確認してください。</p>
  </AccountRecoveryPanel>
</template>
