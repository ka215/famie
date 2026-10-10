<script setup lang="ts">
import type { ApiRequestError, Category, DataResponse, Group } from '#shared/types/api'
import { type ActivityLogForm, activityLogSchema } from '#shared/utils/activityLogSchema'
import closeIcon from '~/assets/icons/close.svg'

const closeIconStyle = { maskImage: `url("${closeIcon}")` }

const props = defineProps<{
  isOpen: boolean
  categories: Category[]
  initialDate?: string
}>()

const emit = defineEmits(['close', 'created'])
const { fetchApi } = useApi()
const { prepareFile } = useActivityImage()
const { groupId } = useAuth()
const { isMaintenance } = useMaintenance()
const dialog = ref<HTMLDialogElement | null>(null)
const viewportStyle = ref<Record<string, string>>({})
const updateViewport = () => {
  const viewport = window.visualViewport
  if (!viewport) return
  viewportStyle.value = {
    '--dialog-viewport-height': `${viewport.height}px`,
    '--dialog-viewport-top': `${viewport.offsetTop}px`,
  }
}

onMounted(() => {
  updateViewport()
  window.visualViewport?.addEventListener('resize', updateViewport)
  window.visualViewport?.addEventListener('scroll', updateViewport)
})

watch(
  [() => props.isOpen, isMaintenance, dialog],
  ([isOpen, maintenance]) => {
    if (isOpen && !maintenance) {
      if (!dialog.value?.open) {
        updateViewport()
        dialog.value?.showModal()
      }
    } else {
      dialog.value?.close()
      if (isOpen && maintenance) emit('close')
    }
  },
  { flush: 'post' }
)

onBeforeUnmount(() => {
  dialog.value?.close()
  window.visualViewport?.removeEventListener('resize', updateViewport)
  window.visualViewport?.removeEventListener('scroll', updateViewport)
})

const localToday = () => {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
}
const today = localToday()
const currentTime = new Date().toTimeString().slice(0, 5)

const form = ref<ActivityLogForm>({
  category_id: props.categories[0]?.id || null,
  activity_date: today,
  activity_time: currentTime,
  content: '',
  note: '',
})

const isLoading = ref(false)
const errorMessage = ref('')
const imagesEnabled = ref(false)
const selectedImage = ref<File | null>(null)
const previewUrl = ref('')
const isConverting = ref(false)
const imageInput = ref<HTMLInputElement | null>(null)
const newRequestId = () => {
  const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16))
  bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x40
  bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}
const clientRequestId = ref('')
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
  } catch (error) {
    if (current === selectionId)
      errorMessage.value = error instanceof Error ? error.message : '画像を変換できませんでした。'
  } finally {
    if (current === selectionId) isConverting.value = false
  }
}

const clearImage = () => {
  selectionId++
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = ''
  selectedImage.value = null
  isConverting.value = false
}

onBeforeUnmount(clearImage)

watch(
  () => props.isOpen,
  (isOpen) => {
    if (isOpen) {
      form.value.activity_date = props.initialDate || localToday()
      errorMessage.value = ''
      fetchApi<DataResponse<Group>>(`/groups/${groupId.value}`)
        .then((response) => {
          imagesEnabled.value = response.data.images?.enabled ?? false
        })
        .catch(() => {
          imagesEnabled.value = false
        })
    } else {
      clearImage()
    }
  }
)

watch(
  () => props.categories,
  (categories) => {
    if (form.value.category_id === null && categories.length > 0) {
      form.value.category_id = categories[0]?.id ?? null
    }
  },
  { immediate: true }
)

const handleSubmit = async () => {
  if (isLoading.value || isConverting.value) return
  errorMessage.value = ''
  const result = activityLogSchema.safeParse(form.value)
  if (!result.success) {
    errorMessage.value = result.error.issues[0]?.message ?? '入力内容を確認してください。'
    return
  }

  isLoading.value = true
  if (!clientRequestId.value) clientRequestId.value = newRequestId()

  try {
    const body = selectedImage.value
      ? new FormData()
      : { ...result.data, client_request_id: clientRequestId.value }
    if (body instanceof FormData) {
      for (const [key, value] of Object.entries(result.data)) {
        body.append(key, value == null ? '' : String(value))
      }
      body.append('image', selectedImage.value as File)
      body.append('client_request_id', clientRequestId.value)
    }
    await fetchApi(`/groups/${groupId.value}/logs`, { method: 'POST', body })

    form.value.content = ''
    form.value.note = ''
    clearImage()
    clientRequestId.value = ''

    emit('created')
    emit('close')
  } catch (err: unknown) {
    const e = err as ApiRequestError
    errorMessage.value =
      Object.values(e.data?.errors ?? {}).flat()[0] || e.data?.message || '登録に失敗しました。'
  } finally {
    isLoading.value = false
  }
}
</script>

<template>
  <Teleport to="body">
  <dialog ref="dialog" aria-labelledby="log-create-title" class="log-create-dialog bg-white dark:bg-slate-800 rounded-t-2xl sm:rounded-2xl" :style="viewportStyle" @cancel.prevent="emit('close')">
    <div class="p-6 space-y-4 log-create-content">
      <div class="flex justify-between items-center border-b border-slate-200 dark:border-slate-700 pb-3">
        <h2 id="log-create-title" class="text-lg font-bold text-slate-800 dark:text-slate-100">アクティビティを記録</h2>
        <button type="button" aria-label="閉じる" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 dark:text-slate-400 hover:text-slate-600 dark:hover:text-slate-300" @click="emit('close')">
          <span aria-hidden="true" class="h-5 w-5 bg-current [mask-size:contain] [mask-repeat:no-repeat] [mask-position:center]" :style="closeIconStyle" />
        </button>
      </div>

      <div v-if="errorMessage" role="alert" class="p-3 bg-red-50 dark:bg-red-950 text-red-600 dark:text-red-300 text-xs rounded-lg">
        {{ errorMessage }}
      </div>

      <form class="space-y-4" novalidate @submit.prevent="handleSubmit">
        <div>
          <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">カテゴリ</label>
          <select
            v-model="form.category_id"
            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            <option v-for="cat in categories" :key="cat.id" :value="cat.id">
              {{ cat.name }}
            </option>
          </select>
        </div>

        <div class="grid grid-cols-2 gap-2">
          <div class="min-w-0">
            <label for="activity-date" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">実施日</label>
            <input
              id="activity-date"
              v-model="form.activity_date"
              type="date"
              required
              class="block min-w-0 max-w-full w-full appearance-none px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
          </div>
          <div class="min-w-0">
            <label for="activity-time" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">時刻（任意）</label>
            <input
              id="activity-time"
              v-model="form.activity_time"
              type="time"
              class="block min-w-0 max-w-full w-full appearance-none px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
          </div>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">活動内容</label>
          <textarea
            v-model="form.content"
            required
            rows="3"
            placeholder="例: 算数のドリルを2ページ進めた"
            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">補足メモ（任意）</label>
          <input
            v-model="form.note"
            type="text"
            placeholder="例: つまずいた箇所あり"
            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
        </div>

        <div v-if="imagesEnabled" class="space-y-2">
          <p class="text-xs font-semibold text-slate-600 dark:text-slate-300">画像（任意・1枚）</p>
          <input id="create-image" ref="imageInput" type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" aria-label="画像（任意・1枚）" class="hidden" @change="selectImage">
          <button type="button" :disabled="isConverting" class="min-h-11 rounded-lg border border-blue-600 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 disabled:opacity-50 dark:bg-blue-950 dark:text-blue-200" @click="imageInput?.click()">画像を選択</button>
          <p v-if="isConverting" role="status" class="text-xs text-slate-500">画像を変換中...</p>
          <img v-if="previewUrl" :src="previewUrl" alt="添付予定の画像" class="max-h-48 rounded-lg object-contain">
          <button v-if="selectedImage" type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 dark:border-slate-600 dark:text-slate-200" @click="clearImage">選択を取り消す</button>
        </div>

        <button
          type="submit"
          :disabled="isLoading || isConverting"
          class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-xl shadow-xs transition disabled:opacity-50"
        >
          {{ isLoading ? '保存中...' : '記録を保存する' }}
        </button>
      </form>
    </div>
  </dialog>
  </Teleport>
</template>

<style scoped>
.log-create-dialog {
  position: fixed;
  inset: auto 0 0;
  bottom: max(0px, calc(100dvh - var(--dialog-viewport-height, 100dvh) - var(--dialog-viewport-top, 0px)));
  margin: 0 auto;
  width: 100%;
  max-width: 28rem;
  max-height: 90vh;
  max-height: min(90dvh, calc(var(--dialog-viewport-height, 100dvh) - 1rem));
  padding: 0;
  border: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
}

.log-create-dialog::backdrop {
  background: rgb(0 0 0 / 40%);
}

.log-create-content {
  padding-bottom: calc(1.5rem + env(safe-area-inset-bottom));
}

@media (min-width: 640px) {
  .log-create-dialog {
    top: calc(var(--dialog-viewport-top, 0px) + var(--dialog-viewport-height, 100dvh) / 2);
    bottom: auto;
    transform: translateY(-50%);
  }
}
</style>
