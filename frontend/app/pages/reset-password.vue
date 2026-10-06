<script setup lang="ts">
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
const error = ref('')
async function submit() {
  if (busy.value || !valid.value || message.value) return
  error.value = ''
  if (password.value !== confirmation.value) {
    error.value = 'パスワードが一致しません。'
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
    error.value =
      (err as ApiRequestError).data?.message ??
      '再設定できませんでした。通信環境を確認してください。'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AccountRecoveryPanel title="新しいパスワードの設定">
    <p v-if="message" role="status" class="text-sm">{{ message }}</p>
    <template v-else>
      <p v-if="!valid" role="alert" class="text-sm text-red-600 dark:text-red-300">メール内のリンクから開き直してください。</p>
      <p v-if="error" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ error }}</p>
      <form class="space-y-4" @submit.prevent="submit">
        <div>
          <label for="reset-password" class="block text-sm">新しいパスワード</label>
          <PasswordInput id="reset-password" v-model="password" label="新しいパスワード" autocomplete="new-password" required minlength="6" :disabled="busy" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600" />
        </div>
        <div>
          <label for="reset-confirmation" class="block text-sm">新しいパスワード（確認）</label>
          <PasswordInput id="reset-confirmation" v-model="confirmation" label="新しいパスワード（確認）" autocomplete="new-password" required minlength="6" :disabled="busy" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600" />
        </div>
        <button type="submit" :disabled="busy || !valid" class="w-full rounded-lg bg-blue-600 py-3 text-white disabled:opacity-50">{{ busy ? '更新中...' : 'パスワードを再設定' }}</button>
      </form>
    </template>
    <NuxtLink to="/forgot-password" class="block text-sm text-blue-600 dark:text-blue-300">再設定メールをもう一度送る</NuxtLink>
  </AccountRecoveryPanel>
</template>
