<script setup lang="ts">
import type { ApiRequestError } from '#shared/types/api'

const { fetchApi } = useApi()
const isChecking = ref(false)
const message = ref('')

const retry = async () => {
  if (isChecking.value) return
  isChecking.value = true
  message.value = ''
  try {
    const result = await fetchApi<{ status: string }>('/status', { cache: 'no-store' })
    if (result.status !== 'ok') throw new Error('Unexpected status')
    window.location.reload()
  } catch (error: unknown) {
    const response = error as ApiRequestError
    message.value =
      response.statusCode === 503 && response.data?.code === 'maintenance'
        ? 'まだメンテナンス中です。しばらくしてから再試行してください。'
        : '復旧状況を確認できませんでした。通信環境を確認して再試行してください。'
  } finally {
    isChecking.value = false
  }
}
</script>

<template>
  <main class="min-h-screen bg-slate-50 flex items-center justify-center px-6">
    <section class="max-w-md w-full bg-white border border-slate-200 rounded-2xl p-8 shadow-sm text-center" aria-labelledby="maintenance-title">
      <p class="text-blue-600 font-bold mb-4">Famie</p>
      <h1 id="maintenance-title" class="text-xl font-bold text-slate-800">ただいまメンテナンス中です</h1>
      <p class="mt-4 text-slate-600">しばらくしてから再試行してください。</p>
      <p class="mt-2 text-sm text-slate-500">保存操作は自動で再送されません。再開後に記録を確認してください。</p>
      <button type="button" class="mt-6 rounded-lg bg-blue-600 text-white px-6 py-3 disabled:opacity-50" :disabled="isChecking" @click="retry">
        {{ isChecking ? '確認中…' : '再試行' }}
      </button>
      <p role="status" class="mt-4 text-sm text-slate-600">{{ message }}</p>
    </section>
  </main>
</template>
