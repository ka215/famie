<script setup lang="ts">
import type { ApiRequestError, MessageResponse } from '#shared/types/api'

definePageMeta({ layout: false, scrollToTop: false })
useHead({ meta: [{ name: 'referrer', content: 'no-referrer' }] })
const { id, token, valid } = useAccountLink()
const { fetchApi } = useApi()
const busy = ref(false)
const message = ref('')
const error = ref('')
async function confirm() {
  if (busy.value || !valid.value || message.value) return
  busy.value = true
  error.value = ''
  try {
    const result = await fetchApi<MessageResponse>('/auth/email/verify', {
      method: 'POST',
      body: { id: id.value, token: token.value },
    })
    message.value = result.message
    token.value = ''
  } catch (err) {
    error.value =
      (err as ApiRequestError).data?.message ?? '確認できませんでした。通信環境を確認してください。'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AccountRecoveryPanel title="メールアドレスの確認">
    <p v-if="message" role="status">{{ message }}</p>
    <template v-else>
      <p class="text-sm">ご自身が申請したメールアドレスの登録・変更を完了します。</p>
      <p v-if="!valid" role="alert" class="text-sm text-red-600 dark:text-red-300">メール内のリンクから開き直してください。</p>
      <p v-if="error" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ error }}</p>
      <button type="button" :disabled="busy || !valid" class="w-full rounded-lg bg-blue-600 py-3 text-white disabled:opacity-50" @click="confirm">{{ busy ? '確認中...' : 'メールアドレスを確認する' }}</button>
    </template>
  </AccountRecoveryPanel>
</template>
