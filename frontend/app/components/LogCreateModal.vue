<script setup lang="ts">
import type { ApiRequestError, Category } from '#shared/types/api'
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

watch(
  () => props.isOpen,
  (isOpen) => {
    if (isOpen) {
      form.value.activity_date = props.initialDate || localToday()
      errorMessage.value = ''
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
  if (isLoading.value) return
  errorMessage.value = ''
  const result = activityLogSchema.safeParse(form.value)
  if (!result.success) {
    errorMessage.value = result.error.issues[0]?.message ?? '入力内容を確認してください。'
    return
  }

  isLoading.value = true

  try {
    await fetchApi(`/groups/${groupId.value}/logs`, {
      method: 'POST',
      body: result.data,
    })

    form.value.content = ''
    form.value.note = ''

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

        <button
          type="submit"
          :disabled="isLoading"
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
