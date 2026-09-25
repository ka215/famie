<script setup lang="ts">
import type { ActivityLog, ApiRequestError, Category, DataResponse } from '#shared/types/api'
import { type ActivityLogForm, activityLogSchema } from '#shared/utils/activityLogSchema'

const route = useRoute()
const { fetchApi } = useApi()
const { user } = useAuth()

const log = ref<ActivityLog | null>(null)
const categories = ref<Category[]>([])
const form = ref<ActivityLogForm>({
  category_id: null,
  activity_date: '',
  activity_time: '',
  content: '',
  note: '',
})
const isLoading = ref(true)
const isSaving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const canEdit = computed(() => log.value !== null && log.value.user_id === user.value?.id)

const loadLog = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const [logResponse, categoryResponse] = await Promise.all([
      fetchApi<DataResponse<ActivityLog>>(`/logs/${route.params.id}`),
      fetchApi<Category[]>('/categories'),
    ])
    log.value = logResponse.data
    categories.value = categoryResponse
    form.value = {
      category_id: logResponse.data.category_id,
      activity_date: logResponse.data.activity_date,
      activity_time: logResponse.data.activity_time?.slice(0, 5) ?? '',
      content: logResponse.data.content,
      note: logResponse.data.note ?? '',
    }
  } catch (error: unknown) {
    const status = (error as ApiRequestError).response?.status
    errorMessage.value =
      status === 404
        ? '指定された記録は見つかりませんでした。'
        : '記録の取得に失敗しました。通信環境を確認してください。'
  } finally {
    isLoading.value = false
  }
}

const handleUpdate = async () => {
  if (!canEdit.value || isSaving.value) return
  errorMessage.value = ''
  successMessage.value = ''
  const result = activityLogSchema.safeParse(form.value)
  if (!result.success) {
    errorMessage.value = result.error.issues[0]?.message ?? '入力内容を確認してください。'
    return
  }

  isSaving.value = true

  try {
    await fetchApi(`/logs/${route.params.id}`, {
      method: 'PUT',
      body: result.data,
    })
    await navigateTo('/')
  } catch (error: unknown) {
    const apiError = error as ApiRequestError
    errorMessage.value =
      Object.values(apiError.data?.errors ?? {}).flat()[0] ||
      apiError.data?.message ||
      '記録の更新に失敗しました。'
  } finally {
    isSaving.value = false
  }
}

const handleDelete = async () => {
  if (!canEdit.value || !window.confirm('この記録を削除しますか？')) {
    return
  }

  isSaving.value = true
  errorMessage.value = ''

  try {
    await fetchApi(`/logs/${route.params.id}`, { method: 'DELETE' })
    await navigateTo('/')
  } catch (error: unknown) {
    const apiError = error as ApiRequestError
    errorMessage.value = apiError.data?.message || '記録の削除に失敗しました。'
  } finally {
    isSaving.value = false
  }
}

onMounted(loadLog)
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <NuxtLink to="/" class="text-sm text-blue-600">← タイムラインへ</NuxtLink>
      <span v-if="log" class="text-xs text-slate-500">{{ log.user.display_name }}さんの記録</span>
    </div>

    <div v-if="errorMessage" role="alert" class="p-3 bg-red-50 text-red-700 text-sm rounded-lg border border-red-200">
      {{ errorMessage }}
    </div>
    <div v-if="successMessage" class="p-3 bg-emerald-50 text-emerald-700 text-sm rounded-lg">
      {{ successMessage }}
    </div>

    <div v-if="isLoading" class="text-center py-8 text-slate-400 text-sm">読み込み中...</div>

    <form v-else-if="log" class="bg-white p-4 rounded-xl border border-slate-200 space-y-4" novalidate @submit.prevent="handleUpdate">
      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">カテゴリ</label>
        <select
          v-model="form.category_id"
          required
          :disabled="!canEdit"
          class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white disabled:bg-slate-100"
        >
          <option v-for="category in categories" :key="category.id" :value="category.id">
            {{ category.name }}
          </option>
        </select>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div class="min-w-0">
          <label for="edit-activity-date" class="block text-xs font-semibold text-slate-600 mb-1">実施日</label>
          <input id="edit-activity-date" v-model="form.activity_date" type="date" required :disabled="!canEdit" class="block min-w-0 max-w-full w-full appearance-none px-3 py-2 rounded-lg border border-slate-300 bg-white disabled:bg-slate-100">
        </div>
        <div class="min-w-0">
          <label for="edit-activity-time" class="block text-xs font-semibold text-slate-600 mb-1">実施時刻</label>
          <input id="edit-activity-time" v-model="form.activity_time" type="time" :disabled="!canEdit" class="block min-w-0 max-w-full w-full appearance-none px-3 py-2 rounded-lg border border-slate-300 bg-white disabled:bg-slate-100">
        </div>
      </div>

      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">活動内容</label>
        <textarea v-model="form.content" required rows="4" :disabled="!canEdit" class="w-full px-3 py-2 rounded-lg border border-slate-300 disabled:bg-slate-100" />
      </div>

      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">補足メモ</label>
        <textarea v-model="form.note" rows="3" :disabled="!canEdit" class="w-full px-3 py-2 rounded-lg border border-slate-300 disabled:bg-slate-100" />
      </div>

      <div v-if="canEdit" class="flex gap-3 pt-2">
        <button type="submit" :disabled="isSaving" class="flex-1 py-2.5 bg-blue-600 text-white rounded-lg disabled:opacity-50">
          {{ isSaving ? '保存中...' : '更新する' }}
        </button>
        <button type="button" :disabled="isSaving" class="px-4 py-2.5 border border-red-300 text-red-600 rounded-lg disabled:opacity-50" @click="handleDelete">
          削除
        </button>
      </div>
      <p v-else class="text-xs text-slate-500">この記録は閲覧のみ可能です。</p>
    </form>
  </div>
</template>
