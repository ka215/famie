<script setup lang="ts">
const { t } = useI18n()

import type { ActivityLog, ApiRequestError, Category, DataResponse, Group } from '#shared/types/api'
import { type ActivityLogForm, createActivityLogSchema } from '#shared/utils/activityLogSchema'

const route = useRoute()
const listReturn = useListReturn()
const returnEntry = computed(() => listReturn.read(route.query.back))
const returnTarget = computed(() =>
  returnEntry.value
    ? { path: returnEntry.value.path, query: { resume: String(route.query.back) } }
    : { path: '/' }
)
const returnLabel = computed(() =>
  returnEntry.value?.path === '/gallery' ? t('activity.backGallery') : t('activity.backTimeline')
)
const { fetchApi } = useApi()
const { prepareFile, formatError: imageError } = useActivityImage()
const { user, groupId } = useAuth()
const groupPath = (path: string) => `/groups/${groupId.value}${path}`

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
const errorMessage = useUiMessage()
const successMessage = ref('')
useHead(() => ({ title: `${t('activity.detailTitle')} | Famie` }))
const imagesEnabled = ref(false)
const selectedImage = ref<File | null>(null)
const removeImage = ref(false)
const previewUrl = ref('')
const isConverting = ref(false)
const imageInput = ref<HTMLInputElement | null>(null)
let selectionId = 0

const selectImage = async (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  input.value = ''
  const current = ++selectionId
  isConverting.value = true
  errorMessage.value = ''
  try {
    const prepared = await prepareFile(file)
    if (current !== selectionId) return
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
    selectedImage.value = prepared
    previewUrl.value = URL.createObjectURL(prepared)
    removeImage.value = false
  } catch (error) {
    if (current === selectionId) errorMessage.setLocal(() => imageError(error))
  } finally {
    if (current === selectionId) isConverting.value = false
  }
}
const clearSelectedImage = () => {
  selectionId++
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = ''
  selectedImage.value = null
  isConverting.value = false
}
onBeforeUnmount(clearSelectedImage)

const canEdit = computed(() => log.value !== null && log.value.user_id === user.value?.id)

const loadLog = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const [logResponse, categoryResponse, groupResponse] = await Promise.all([
      fetchApi<DataResponse<ActivityLog>>(groupPath(`/logs/${route.params.id}`)),
      fetchApi<Category[]>(groupPath('/categories')),
      fetchApi<DataResponse<Group>>(groupPath('')),
    ])
    log.value = logResponse.data
    categories.value = categoryResponse
    imagesEnabled.value = groupResponse.data.images?.enabled ?? false
    form.value = {
      category_id: logResponse.data.category_id,
      activity_date: logResponse.data.activity_date,
      activity_time: logResponse.data.activity_time?.slice(0, 5) ?? '',
      content: logResponse.data.content,
      note: logResponse.data.note ?? '',
    }
  } catch (error: unknown) {
    const status = (error as ApiRequestError).response?.status
    errorMessage.setKey(status === 404 ? 'activity.notFound' : 'activity.fetchFailed')
  } finally {
    isLoading.value = false
  }
}

const handleUpdate = async () => {
  if (!canEdit.value || isSaving.value || isConverting.value) return
  errorMessage.value = ''
  successMessage.value = ''
  const result = createActivityLogSchema(t).safeParse(form.value)
  if (!result.success) {
    const input = { ...form.value }
    errorMessage.setLocal(() => {
      const validation = createActivityLogSchema(t).safeParse(input)
      return validation.success
        ? ''
        : (validation.error.issues[0]?.message ?? t('activity.checkInput'))
    })
    return
  }

  isSaving.value = true

  try {
    if (selectedImage.value) {
      const body = new FormData()
      body.append('_method', 'PUT')
      for (const [key, value] of Object.entries(result.data))
        body.append(key, value == null ? '' : String(value))
      body.append('image', selectedImage.value)
      body.append('revision', String(log.value?.revision ?? 1))
      await fetchApi(groupPath(`/logs/${route.params.id}`), { method: 'POST', body })
    } else {
      await fetchApi(groupPath(`/logs/${route.params.id}`), {
        method: 'PUT',
        body: {
          ...result.data,
          revision: log.value?.revision,
          ...(removeImage.value ? { remove_image: true } : {}),
        },
      })
    }
    await navigateTo(returnTarget.value)
  } catch (error: unknown) {
    const apiError = error as ApiRequestError
    const message = Object.values(apiError.data?.errors ?? {}).flat()[0] || apiError.data?.message
    if (message) errorMessage.value = message
    else errorMessage.setKey('activity.updateFailed')
  } finally {
    isSaving.value = false
  }
}

const handleDelete = async () => {
  if (!canEdit.value || !window.confirm(t('activity.deleteConfirm'))) {
    return
  }

  isSaving.value = true
  errorMessage.value = ''

  try {
    await fetchApi(groupPath(`/logs/${route.params.id}`), { method: 'DELETE' })
    await navigateTo(returnTarget.value)
  } catch (error: unknown) {
    const apiError = error as ApiRequestError
    if (apiError.data?.message) errorMessage.value = apiError.data.message
    else errorMessage.setKey('activity.deleteFailed')
  } finally {
    isSaving.value = false
  }
}

onMounted(loadLog)
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <NuxtLink :to="returnTarget" class="text-sm text-blue-600 dark:text-blue-300">{{ returnLabel }}</NuxtLink>
      <span v-if="log" class="text-xs text-slate-500 dark:text-slate-400">{{ t('activity.by', { name: log.user.display_name }) }}</span>
    </div>

    <div v-if="errorMessage" role="alert" class="p-3 bg-red-50 dark:bg-red-950 text-red-700 dark:text-red-300 text-sm rounded-lg border border-red-200 dark:border-red-800">
      {{ errorMessage }}
    </div>
    <div v-if="successMessage" class="p-3 bg-emerald-50 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 text-sm rounded-lg">
      {{ successMessage }}
    </div>

    <div v-if="isLoading" class="text-center py-8 text-slate-400 dark:text-slate-400 text-sm">{{ t('activity.loading') }}</div>

    <form v-else-if="log" class="activity-detail bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-slate-700 space-y-4" novalidate @submit.prevent="handleUpdate">
      <div>
        <label for="edit-category" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('activity.category') }}</label>
        <select id="edit-category"
          v-model="form.category_id"
          required
          :disabled="!canEdit"
          class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 disabled:bg-slate-100"
        >
          <option v-for="category in categories" :key="category.id" :value="category.id">
            {{ category.name }}
          </option>
        </select>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div class="min-w-0">
          <label for="edit-activity-date" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('activity.date') }}</label>
          <input id="edit-activity-date" v-model="form.activity_date" type="date" required :disabled="!canEdit" class="block min-w-0 max-w-full w-full appearance-none px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 disabled:bg-slate-100">
        </div>
        <div class="min-w-0">
          <label for="edit-activity-time" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('activity.time') }}</label>
          <input id="edit-activity-time" v-model="form.activity_time" type="time" :disabled="!canEdit" class="block min-w-0 max-w-full w-full appearance-none px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 disabled:bg-slate-100">
        </div>
      </div>

      <div>
        <label for="edit-content" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('activity.content') }}</label>
        <textarea id="edit-content" v-model="form.content" required rows="4" :disabled="!canEdit" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 disabled:bg-slate-100" />
      </div>

      <div>
        <label for="edit-note" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('activity.note') }}</label>
        <textarea id="edit-note" v-model="form.note" rows="3" :disabled="!canEdit" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 disabled:bg-slate-100" />
      </div>

      <div v-if="log.image || imagesEnabled" class="space-y-2">
        <p class="text-xs font-semibold text-slate-600 dark:text-slate-300">{{ t('activity.imageAttached') }}</p>
        <ActivityImageView v-if="log.image && !removeImage && !selectedImage" :image-id="log.image.id" :width="log.image.width" :height="log.image.height" />
        <img v-if="previewUrl" :src="previewUrl" :alt="t('activity.imageReplacePreview')" class="max-h-48 rounded-lg object-contain">
        <p v-if="isConverting" role="status" class="text-xs text-slate-500">{{ t('activity.imageConverting') }}</p>
        <div v-if="canEdit" class="flex flex-wrap gap-2">
          <template v-if="imagesEnabled">
            <input id="edit-image" ref="imageInput" type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" :aria-label="t('activity.imageSelect')" class="hidden" @change="selectImage">
            <button type="button" :disabled="isConverting" class="min-h-11 rounded-lg border border-blue-600 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 disabled:opacity-50 dark:bg-blue-950 dark:text-blue-200" @click="imageInput?.click()">{{ log.image ? t('activity.imageReplace') : t('activity.imageSelect') }}</button>
          </template>
          <button v-if="selectedImage" type="button" class="min-h-11 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 dark:border-slate-600 dark:text-slate-200" @click="clearSelectedImage">{{ t('activity.imageCancelReplace') }}</button>
          <button v-if="log.image && !selectedImage" type="button" class="min-h-11 rounded-lg border px-3 py-2 text-sm font-medium" :class="removeImage ? 'border-slate-300 text-slate-700 dark:border-slate-600 dark:text-slate-200' : 'border-red-300 text-red-700 dark:border-red-700 dark:text-red-300'" @click="removeImage = !removeImage">{{ removeImage ? t('activity.imageCancelRemove') : t('activity.imageRemove') }}</button>
        </div>
      </div>

      <div v-if="canEdit" class="flex gap-3 pt-2">
        <button type="submit" :disabled="isSaving || isConverting" class="flex-1 py-2.5 bg-blue-600 text-white rounded-lg disabled:opacity-50">
          {{ isSaving ? t('activity.saving') : t('activity.update') }}
        </button>
        <button type="button" :disabled="isSaving" class="px-4 py-2.5 border border-red-300 dark:border-red-600 text-red-600 dark:text-red-300 rounded-lg disabled:opacity-50" @click="handleDelete">
          {{ t('activity.delete') }}
        </button>
      </div>
      <p v-else class="text-xs text-slate-500 dark:text-slate-400">{{ t('activity.readOnly') }}</p>
    </form>
  </div>
</template>

<style scoped>
.activity-detail :is(input, select, textarea) {
  background-color: var(--color-white);
  color: var(--color-slate-800);
}
.activity-detail :is(input, select, textarea):disabled {
  background-color: var(--color-slate-100);
  color: var(--color-slate-700);
  -webkit-text-fill-color: var(--color-slate-700);
  opacity: 1;
}
[data-theme='dark'] .activity-detail :is(input, select, textarea) {
  background-color: var(--color-slate-800);
  color: var(--color-slate-100);
}
[data-theme='dark'] .activity-detail :is(input, select, textarea):disabled {
  background-color: var(--color-slate-900);
  color: var(--color-slate-200);
  -webkit-text-fill-color: var(--color-slate-200);
}
</style>
