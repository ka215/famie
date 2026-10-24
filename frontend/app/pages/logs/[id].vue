<script setup lang="ts">
const { t } = useI18n()

import type { ActivityLog, ApiRequestError, Category, DataResponse, Group } from '#shared/types/api'
import { type ActivityLogForm, createActivityLogSchema } from '#shared/utils/activityLogSchema'
import chevronDoubleLeft from '~/assets/icons/chevron-double-left.svg'
import chevronLeft from '~/assets/icons/chevron-left.svg'

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
const { user, groupId, token } = useAuth()
const identity = computed(
  () => `${user.value?.id}:${groupId.value}:${token.value}:${route.params.id}`
)
const groupPath = (path: string) => `/groups/${groupId.value}${path}`
const likes = useActivityLikes()
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
const metadataLoading = ref(false)
const metadataReady = ref(false)
const savedAwaitingRefresh = ref(false)
const errorMessage = useUiMessage()
const successMessage = computed(() => (savedAwaitingRefresh.value ? t('activity.saved') : ''))
useHead(() => ({ title: `${t('activity.detailTitle')} | Famie` }))
const imagesEnabled = ref(false)
const selectedImage = ref<File | null>(null)
const removeImage = ref(false)
const previewUrl = ref('')
const isConverting = ref(false)
const imageInput = ref<HTMLInputElement | null>(null)
let selectionId = 0
let disposed = false
let loadId = 0
let metadataId = 0
const canEdit = computed(() => log.value !== null && log.value.user_id === user.value?.id)
const isEditing = computed(() => canEdit.value && route.query.mode === 'edit')
const detailBackIconStyle = { maskImage: `url("${chevronLeft}")` }
const listBackIconStyle = computed(() => ({
  maskImage: `url("${isEditing.value ? chevronDoubleLeft : chevronLeft}")`,
}))
const editTarget = computed(() => ({ path: route.path, query: { ...route.query, mode: 'edit' } }))
const setMode = (edit: boolean) => {
  const query = { ...route.query }
  if (edit) query.mode = 'edit'
  else delete query.mode
  return navigateTo({ path: route.path, query }, { replace: true })
}
const beginEdit = (event: MouseEvent) => {
  if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return
  event.preventDefault()
  if (canEdit.value) void setMode(true)
}
const clearSelectedImage = () => {
  selectionId++
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = ''
  selectedImage.value = null
  isConverting.value = false
}
const resetForm = () => {
  clearSelectedImage()
  removeImage.value = false
  if (log.value)
    form.value = {
      category_id: log.value.category_id,
      activity_date: log.value.activity_date,
      activity_time: log.value.activity_time?.slice(0, 5) ?? '',
      content: log.value.content,
      note: log.value.note ?? '',
    }
}
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
const loadEditMetadata = async () => {
  const current = ++metadataId
  const owner = identity.value
  metadataLoading.value = true
  metadataReady.value = false
  try {
    const [categoryResponse, groupResponse] = await Promise.all([
      fetchApi<Category[]>(groupPath('/categories')),
      fetchApi<DataResponse<Group>>(groupPath('')),
    ])
    if (current !== metadataId || disposed || owner !== identity.value || !isEditing.value) return
    categories.value = categoryResponse
    imagesEnabled.value = groupResponse.data.images?.enabled ?? false
    metadataReady.value = true
  } catch {
    if (current === metadataId && !disposed && owner === identity.value && isEditing.value)
      errorMessage.setKey('activity.fetchFailed')
  } finally {
    if (current === metadataId) metadataLoading.value = false
  }
}
const loadLog = async () => {
  const current = ++loadId
  const owner = identity.value
  const readRevision = likes.beginRead()
  isLoading.value = true
  errorMessage.value = ''
  log.value = null
  try {
    const response = await fetchApi<DataResponse<ActivityLog>>(
      groupPath(`/logs/${route.params.id}`)
    )
    if (current !== loadId || disposed || owner !== identity.value) return
    log.value = response.data
    likes.acceptLogs([response.data], readRevision)
    savedAwaitingRefresh.value = false
    resetForm()
    if (route.query.mode && (!canEdit.value || route.query.mode !== 'edit')) await setMode(false)
    if (isEditing.value) await loadEditMetadata()
  } catch (error: unknown) {
    if (current !== loadId || disposed || owner !== identity.value) return
    log.value = null
    const apiError = error as ApiRequestError
    const status = apiError.response?.status ?? apiError.statusCode
    errorMessage.setKey(
      savedAwaitingRefresh.value
        ? 'activity.savedRefreshFailed'
        : status === 404
          ? 'activity.notFound'
          : 'activity.fetchFailed'
    )
  } finally {
    if (current === loadId) isLoading.value = false
  }
}
const cancelEdit = async () => {
  if (isSaving.value) return
  resetForm()
  errorMessage.value = ''
  await setMode(false)
}
const handleUpdate = async () => {
  if (!isEditing.value || !metadataReady.value || isSaving.value || isConverting.value) return
  errorMessage.value = ''
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
  const owner = identity.value
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
    if (disposed || owner !== identity.value) return
    savedAwaitingRefresh.value = true
    clearSelectedImage()
    await setMode(false)
    await loadLog()
  } catch (error: unknown) {
    if (disposed || owner !== identity.value) return
    const apiError = error as ApiRequestError
    const message = Object.values(apiError.data?.errors ?? {}).flat()[0] || apiError.data?.message
    const status = apiError.response?.status ?? apiError.statusCode
    if (status === 403 || status === 404) {
      log.value = null
      metadataReady.value = false
      clearSelectedImage()
      await setMode(false)
    }
    if (message) errorMessage.value = message
    else errorMessage.setKey('activity.updateFailed')
  } finally {
    if (!disposed && owner === identity.value) isSaving.value = false
  }
}
const handleDelete = async () => {
  if (!isEditing.value || isSaving.value || !window.confirm(t('activity.deleteConfirm'))) return
  const owner = identity.value
  isSaving.value = true
  errorMessage.value = ''
  try {
    await fetchApi(groupPath(`/logs/${route.params.id}`), { method: 'DELETE' })
    if (!disposed && owner === identity.value) await navigateTo(returnTarget.value)
  } catch (error: unknown) {
    if (disposed || owner !== identity.value) return
    const apiError = error as ApiRequestError
    if (apiError.data?.message) errorMessage.value = apiError.data.message
    else errorMessage.setKey('activity.deleteFailed')
  } finally {
    if (!disposed && owner === identity.value) isSaving.value = false
  }
}
watch(
  () => route.query.mode,
  () => {
    errorMessage.value = ''
    metadataId++
    metadataLoading.value = false
    resetForm()
    if (isEditing.value) void loadEditMetadata()
    else if (route.query.mode && log.value) void setMode(false)
  }
)
watch(identity, () => {
  loadId++
  metadataId++
  log.value = null
  clearSelectedImage()
  metadataReady.value = false
  metadataLoading.value = false
  isSaving.value = false
  savedAwaitingRefresh.value = false
  if (!disposed && token.value && user.value && groupId.value) void loadLog()
})
onMounted(loadLog)
onBeforeUnmount(() => {
  disposed = true
  loadId++
  metadataId++
  clearSelectedImage()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div class="flex flex-wrap gap-3">
        <button v-if="isEditing" type="button" :disabled="isSaving" class="inline-flex min-h-11 items-center gap-1 text-sm text-blue-600 dark:text-blue-300 disabled:opacity-50" @click="cancelEdit">
          <span aria-hidden="true" class="h-5 w-5 shrink-0 bg-current [mask-size:contain] [mask-repeat:no-repeat] [mask-position:center]" :style="detailBackIconStyle" />
          {{ t('activity.backDetail') }}
        </button>
        <NuxtLink :to="returnTarget" class="inline-flex min-h-11 items-center gap-1 text-sm text-blue-600 dark:text-blue-300">
          <span aria-hidden="true" class="h-5 w-5 shrink-0 bg-current [mask-size:contain] [mask-repeat:no-repeat] [mask-position:center]" :style="listBackIconStyle" />
          {{ returnLabel }}
        </NuxtLink>
      </div>
      <span v-if="log" class="min-w-0 break-all text-xs text-slate-500 dark:text-slate-400">{{ t('activity.by', { name: log.user.display_name }) }}</span>
    </div>

    <div v-if="errorMessage" role="alert" class="p-3 bg-red-50 dark:bg-red-950 text-red-700 dark:text-red-300 text-sm rounded-lg border border-red-200 dark:border-red-800">
      {{ errorMessage }}
      <button v-if="!isEditing || !metadataReady" type="button" :disabled="isLoading || metadataLoading" class="min-h-11 px-3 underline" @click="loadLog">{{ t('activity.retry') }}</button>
    </div>
    <div v-if="successMessage" class="p-3 bg-emerald-50 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 text-sm rounded-lg">
      {{ successMessage }}
    </div>

    <div v-if="isLoading || metadataLoading" class="text-center py-8 text-slate-400 dark:text-slate-400 text-sm">{{ t('activity.loading') }}</div>

    <ActivityCard v-else-if="log && !isEditing" :log="log" :state="likes.stateFor(log)" :pending="likes.operations.value[log.id]?.pending" :uncertain="likes.operations.value[log.id]?.uncertain" :waiting="likes.waiting.value" :wait-seconds="likes.waitSeconds.value" :error="likes.errorFor(log.id)" :edit-to="editTarget" @toggle="likes.toggle(log)" @edit="beginEdit" />

    <form v-else-if="log && isEditing && metadataReady" class="activity-detail bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-slate-700 space-y-4" novalidate @submit.prevent="handleUpdate">
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
        <button type="button" :disabled="isSaving" class="min-h-11 rounded-lg border border-slate-300 px-3 text-sm dark:border-slate-600 disabled:opacity-50" @click="cancelEdit">{{ t('activity.cancelEdit') }}</button>
      </div>
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
