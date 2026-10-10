<script setup lang="ts">
import type { ActivityLog, Category, FamilyMember, PaginatedResponse } from '#shared/types/api'
import {
  getFirstDayOfWeek,
  getMonthRange,
  getPreferredLocale,
  getWeekRange,
} from '#shared/utils/calendar'

const { fetchApi } = useApi()
const { groupId } = useAuth()
const logs = ref<ActivityLog[]>([])
const members = ref<FamilyMember[]>([])
const categories = ref<Category[]>([])
const period = ref<'week' | 'month' | 'custom'>('week')
const anchor = ref(new Date())
const from = ref('')
const to = ref('')
const userId = ref('')
const categoryId = ref('')
const currentPage = ref(0)
const lastPage = ref(1)
const isLoading = ref(false)
const error = ref('')
const sentinel = ref<HTMLElement | null>(null)
let requestId = 0
let observer: IntersectionObserver | null = null

const range = computed(() => {
  if (period.value === 'custom') return { from: from.value, to: to.value }
  if (period.value === 'month')
    return getMonthRange(anchor.value.getFullYear(), anchor.value.getMonth())
  return getWeekRange(
    anchor.value,
    getFirstDayOfWeek(getPreferredLocale(navigator.languages, navigator.language))
  )
})

const fetchPage = async (page = 1) => {
  if (isLoading.value || (page > 1 && page > lastPage.value)) return
  if (range.value.from && range.value.to && range.value.from > range.value.to) {
    error.value = '開始日は終了日以前にしてください。'
    return
  }
  const current = ++requestId
  isLoading.value = true
  error.value = ''
  try {
    const params = new URLSearchParams({ page: String(page) })
    if (range.value.from) params.set('from', range.value.from)
    if (range.value.to) params.set('to', range.value.to)
    if (userId.value) params.set('user_id', userId.value)
    if (categoryId.value) params.set('category_id', categoryId.value)
    const response = await fetchApi<PaginatedResponse<ActivityLog>>(
      `/groups/${groupId.value}/gallery?${params}`
    )
    if (current !== requestId) return
    logs.value =
      page === 1
        ? response.data
        : [
            ...logs.value,
            ...response.data.filter((item) => !logs.value.some((old) => old.id === item.id)),
          ]
    currentPage.value = response.current_page
    lastPage.value = response.last_page
  } catch {
    if (current === requestId) error.value = '画像を取得できませんでした。再試行してください。'
  } finally {
    if (current === requestId) {
      isLoading.value = false
      await nextTick()
      loadMoreIfVisible()
    }
  }
}

const loadMoreIfVisible = () => {
  if (
    isLoading.value ||
    error.value ||
    currentPage.value < 1 ||
    currentPage.value >= lastPage.value
  )
    return
  const root = document.querySelector<HTMLElement>('.app-scroll-area')
  if (!root || !sentinel.value) return
  const rootBounds = root.getBoundingClientRect()
  const sentinelBounds = sentinel.value.getBoundingClientRect()
  if (
    sentinelBounds.top <= rootBounds.bottom + 200 &&
    sentinelBounds.bottom >= rootBounds.top - 200
  )
    fetchPage(currentPage.value + 1)
}

const reset = () => {
  requestId++
  isLoading.value = false
  logs.value = []
  currentPage.value = 0
  lastPage.value = 1
  fetchPage()
}

const movePeriod = (offset: number) => {
  const next = new Date(anchor.value)
  if (period.value === 'month') next.setMonth(next.getMonth() + offset)
  else next.setDate(next.getDate() + offset * 7)
  anchor.value = next
}

watch([period, anchor, userId, categoryId], reset)
onMounted(async () => {
  await Promise.all([
    fetchApi<FamilyMember[]>(`/groups/${groupId.value}/members`).then((result) => {
      members.value = result
    }),
    fetchApi<Category[]>(`/groups/${groupId.value}/categories`).then((result) => {
      categories.value = result
    }),
  ])
  reset()
  observer = new IntersectionObserver(
    (entries) => {
      if (entries[0]?.isIntersecting) loadMoreIfVisible()
    },
    { root: document.querySelector('.app-scroll-area'), rootMargin: '200px' }
  )
  if (sentinel.value) observer.observe(sentinel.value)
})
onBeforeUnmount(() => {
  requestId++
  observer?.disconnect()
})
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-lg font-bold">画像ギャラリー</h1>
    <div class="space-y-3 rounded-xl bg-white p-3 dark:bg-slate-800">
      <div class="flex gap-2">
        <button v-for="choice in (['week', 'month', 'custom'] as const)" :key="choice" type="button" :aria-pressed="period === choice" class="rounded-lg px-3 py-2 text-xs" :class="period === choice ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-700'" @click="period = choice">{{ choice === 'week' ? '週間' : choice === 'month' ? '月間' : '指定期間' }}</button>
      </div>
      <div v-if="period !== 'custom'" class="flex items-center justify-between text-sm">
        <button type="button" class="px-3 py-2" @click="movePeriod(-1)">前へ</button>
        <span>{{ range.from }} 〜 {{ range.to }}</span>
        <button type="button" class="px-3 py-2" @click="movePeriod(1)">次へ</button>
      </div>
      <div v-else class="flex flex-wrap items-center gap-2 text-xs">
        <input v-model="from" type="date" aria-label="開始日" class="min-w-0 flex-1 border rounded-lg p-2">
        <span>〜</span>
        <input v-model="to" type="date" aria-label="終了日" class="min-w-0 flex-1 border rounded-lg p-2">
        <button type="button" class="rounded-lg bg-blue-600 px-3 py-2 text-white" @click="reset">適用</button>
      </div>
      <div class="grid grid-cols-2 gap-2">
        <label class="text-xs">投稿者<select v-model="userId" class="mt-1 w-full rounded-lg border p-2 dark:bg-slate-800"><option value="">全員</option><option v-for="member in members" :key="member.id" :value="String(member.id)">{{ member.display_name }}</option></select></label>
        <label class="text-xs">カテゴリー<select v-model="categoryId" class="mt-1 w-full rounded-lg border p-2 dark:bg-slate-800"><option value="">全カテゴリー</option><option v-for="category in categories" :key="category.id" :value="String(category.id)">{{ category.name }}</option></select></label>
      </div>
    </div>
    <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
    <div class="grid grid-cols-2 gap-2">
      <article v-for="log in logs" :key="log.id" class="min-w-0 rounded-xl bg-white p-2 dark:bg-slate-800">
        <ActivityImageView v-if="log.image" :image-id="log.image.id" :width="log.image.width" :height="log.image.height" square />
        <p class="mt-2 truncate text-xs">{{ log.user.display_name }} · {{ log.activity_date }}</p>
        <NuxtLink :to="`/logs/${log.id}`" class="text-xs text-blue-600 dark:text-blue-300">アクティビティを見る</NuxtLink>
      </article>
    </div>
    <p v-if="!logs.length && !isLoading && !error" class="py-8 text-center text-sm text-slate-500">画像はありません。</p>
    <div ref="sentinel" class="h-4" />
    <p v-if="isLoading" role="status" class="text-center text-xs">読み込み中...</p>
    <button v-if="error" type="button" class="rounded-lg bg-blue-600 px-4 py-2 text-white" @click="fetchPage(currentPage + 1)">再試行</button>
  </div>
</template>
