<script setup lang="ts">
import type { NuxtError } from '#app'

const props = defineProps<{
  error: NuxtError
}>()

const message = computed(() => {
  if (props.error.statusCode === 404) {
    return '指定されたページは見つかりませんでした。'
  }

  return props.error.statusMessage || '画面を表示できませんでした。通信環境を確認してください。'
})

const retryRequired = computed(() => props.error.statusCode === 503)
const recover = () => {
  // 認証未確認のままエラーだけ解除せず、起動時の確認を最初からやり直す。
  if (retryRequired.value) {
    window.location.reload()
    return
  }
  return clearError({ redirect: '/' })
}
</script>

<template>
  <div class="min-h-screen bg-slate-100 flex items-center justify-center p-4">
    <div class="w-full max-w-sm bg-white rounded-2xl shadow-md p-6 text-center space-y-4">
      <h1 class="text-xl font-bold text-slate-800">Famie</h1>
      <p class="text-sm text-slate-600">{{ message }}</p>
      <button type="button" class="w-full py-2.5 bg-blue-600 text-white rounded-lg" @click="recover">
        {{ retryRequired ? '再試行' : 'ホームへ戻る' }}
      </button>
    </div>
  </div>
</template>
