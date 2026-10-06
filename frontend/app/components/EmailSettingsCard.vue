<script setup lang="ts">
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
    error.value =
      (err as ApiRequestError).data?.message ?? '処理に失敗しました。通信環境を確認してください。'
  } finally {
    try {
      await fetchUser()
    } catch {
      refreshError.value = '最新の状態を取得できませんでした。画面を再読み込みしてください。'
    }
    busy.value = false
  }
}
</script>

<template>
  <SettingsCard title="メールアドレス">
    <p class="text-sm break-all">{{ user?.email || '未登録' }} <span v-if="user?.email">（{{ user.email_verified_at ? '確認済み' : '未認証' }}）</span></p>
    <p v-if="!user?.email_verified_at" class="text-sm text-slate-600 dark:text-slate-300">メールを確認すると、パスワードを忘れた場合に再設定できます。未登録・未認証のままではメールによる復旧は利用できません。</p>
    <p v-if="message" role="status" class="text-sm text-green-700 dark:text-green-300">{{ message }}</p>
    <p v-if="error" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ error }}</p>
    <p v-if="refreshError" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ refreshError }}</p>
    <div v-if="user?.pending_email" class="space-y-2 rounded-lg bg-slate-100 p-3 text-sm dark:bg-slate-900">
      <p class="break-all">確認待ち：{{ user.pending_email }}</p>
      <p>リンクの有効期限は送信から60分です。再送後は古いリンクを使用できません。</p>
      <div class="flex flex-wrap gap-4">
        <button type="button" :disabled="busy" class="text-blue-600 disabled:opacity-50 dark:text-blue-300" @click="submit('resend')">確認メールを再送</button>
        <button type="button" :disabled="busy" class="text-red-600 disabled:opacity-50 dark:text-red-300" @click="submit('cancel')">申請を取り消す</button>
      </div>
    </div>
    <form class="space-y-3" @submit.prevent="submit('request')">
      <div>
        <label for="account-email" class="block text-sm">追加・変更するメールアドレス</label>
        <input id="account-email" v-model="email" type="email" autocomplete="email" required maxlength="255" :disabled="busy" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600">
      </div>
      <p v-if="user?.email && !user.email_verified_at" class="text-xs text-slate-600 dark:text-slate-300">現在と同じメールアドレスを入力して確認することもできます。</p>
      <div>
        <label for="email-current-password" class="block text-sm">メール変更確認用パスワード</label>
        <PasswordInput id="email-current-password" v-model="password" label="メール変更確認用パスワード" autocomplete="current-password" required :disabled="busy" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600" />
      </div>
      <p class="text-xs text-slate-600 dark:text-slate-300">現在のパスワードを入力してください。新しいメールの確認が終わるまで、現在のメールを維持します。</p>
      <button type="submit" :disabled="busy" class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white disabled:opacity-50">{{ busy ? '処理中...' : '確認メールを送信' }}</button>
    </form>
  </SettingsCard>
</template>
