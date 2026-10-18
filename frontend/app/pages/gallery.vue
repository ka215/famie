<script setup lang="ts">
import type { ActivityLog, Category, FamilyMember, PaginatedResponse } from '#shared/types/api'
import {
  getFirstDayOfWeek,
  getMonthRange,
  getPreferredLocale,
  getWeekRange,
  parseLocalDate,
} from '#shared/utils/calendar'

const { t } = useI18n()
const { intlLocale } = useUiLocale()
const dateLabel = (value: string) =>
  new Intl.DateTimeFormat(intlLocale.value, {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(parseLocalDate(value))
useHead(() => ({ title: `${t('gallery.title')} | Famie` }))
const { fetchApi } = useApi()
const { groupId } = useAuth()
const listReturn = useListReturn()
let savedReturn = listReturn.current('/gallery')
const restoring = ref(true)
const metadataReady = ref(false)
const openDetail = (event: MouseEvent, id: number) =>
  listReturn.open(event, id, {
    path: '/gallery',
    page: currentPage.value,
    filters: {
      period: period.value,
      anchor: anchor.value.toISOString(),
      from: from.value,
      to: to.value,
      userId: userId.value,
      categoryId: categoryId.value,
    },
  })
const restoreList = async () => {
  restoring.value = true
  const target = savedReturn?.page ?? 1
  if (!currentPage.value) await fetchPage(1)
  while (
    listReturn.isActive() &&
    !error.value &&
    currentPage.value < target &&
    currentPage.value < lastPage.value
  )
    await fetchPage(currentPage.value + 1)
  if (savedReturn && !error.value) await listReturn.restoreScroll(savedReturn)
  restoring.value = false
}
const retryList = () =>
  !metadataReady.value
    ? initialize()
    : savedReturn
      ? restoreList()
      : fetchPage(currentPage.value + 1)
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
const error = useUiMessage()
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
  if (!listReturn.isActive()) return
  if (isLoading.value || (page > 1 && page > lastPage.value)) return
  if (range.value.from && range.value.to && range.value.from > range.value.to) {
    error.setKey('gallery.invalidRange')
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
    if (current === requestId) error.setKey('gallery.loadFailed')
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
    restoring.value ||
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
  savedReturn = undefined
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

watch([period, anchor, userId, categoryId], () => {
  if (!restoring.value) reset()
})
const initialize = async () => {
  restoring.value = true
  error.value = ''
  try {
    await Promise.all([
      fetchApi<FamilyMember[]>(`/groups/${groupId.value}/members`).then((result) => {
        members.value = result
      }),
      fetchApi<Category[]>(`/groups/${groupId.value}/categories`).then((result) => {
        categories.value = result
      }),
    ])
  } catch {
    error.setKey('gallery.filtersFailed')
    restoring.value = false
    return
  }
  if (!listReturn.isActive()) return
  metadataReady.value = true
  if (savedReturn) {
    const filters =
      savedReturn.filters as import('~/composables/useListReturn').GalleryReturnFilters
    period.value = filters.period
    anchor.value = new Date(filters.anchor)
    from.value = filters.from
    to.value = filters.to
    userId.value = members.value.some((member) => String(member.id) === filters.userId)
      ? filters.userId
      : ''
    categoryId.value = categories.value.some(
      (category) => String(category.id) === filters.categoryId
    )
      ? filters.categoryId
      : ''
  }
  await nextTick()
  await restoreList()
  if (!listReturn.isActive()) return
  observer = new IntersectionObserver(
    (entries) => {
      if (entries[0]?.isIntersecting) loadMoreIfVisible()
    },
    { root: document.querySelector('.app-scroll-area'), rootMargin: '200px' }
  )
  if (sentinel.value) observer.observe(sentinel.value)
}
onMounted(initialize)
onBeforeUnmount(() => {
  requestId++
  observer?.disconnect()
})
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-lg font-bold">{{ t('gallery.title') }}</h1>
    <fieldset :disabled="restoring" :aria-busy="restoring" class="min-w-0 space-y-3 rounded-xl bg-white p-3 dark:bg-slate-800">
      <div class="flex gap-2">
        <button v-for="choice in (['week', 'month', 'custom'] as const)" :key="choice" type="button" :aria-pressed="period === choice" class="rounded-lg px-3 py-2 text-xs" :class="period === choice ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-700'" @click="period = choice">{{ t(`gallery.${choice}`) }}</button>
      </div>
      <div v-if="period !== 'custom'" class="flex flex-wrap items-center justify-between gap-2 text-sm">
        <button type="button" class="px-3 py-2" @click="movePeriod(-1)">{{ t('gallery.previous') }}</button>
        <span class="min-w-0 text-center">{{ t('gallery.range', { from: dateLabel(range.from), to: dateLabel(range.to) }) }}</span>
        <button type="button" class="px-3 py-2" @click="movePeriod(1)">{{ t('gallery.next') }}</button>
      </div>
      <div v-else class="flex flex-wrap items-center gap-2 text-xs">
        <input v-model="from" type="date" :aria-label="t('gallery.startDate')" class="min-w-0 flex-1 border rounded-lg p-2">
        <span aria-hidden="true">{{ t('gallery.rangeSeparator') }}</span>
        <input v-model="to" type="date" :aria-label="t('gallery.endDate')" class="min-w-0 flex-1 border rounded-lg p-2">
        <button type="button" class="rounded-lg bg-blue-600 px-3 py-2 text-white" @click="reset">{{ t('gallery.apply') }}</button>
      </div>
      <div class="grid grid-cols-2 gap-2">
        <label class="text-xs">{{ t('gallery.author') }}<select v-model="userId" class="mt-1 w-full rounded-lg border p-2 dark:bg-slate-800"><option value="">{{ t('gallery.everyone') }}</option><option v-for="member in members" :key="member.id" :value="String(member.id)">{{ member.display_name }}</option></select></label>
        <label class="text-xs">{{ t('gallery.category') }}<select v-model="categoryId" class="mt-1 w-full rounded-lg border p-2 dark:bg-slate-800"><option value="">{{ t('gallery.allCategories') }}</option><option v-for="category in categories" :key="category.id" :value="String(category.id)">{{ category.name }}</option></select></label>
      </div>
    </fieldset>
    <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
    <div class="grid grid-cols-2 gap-2">
      <article v-for="log in logs" :key="log.id" :data-log-id="log.id" class="min-w-0 rounded-xl bg-white p-2 dark:bg-slate-800">
        <ActivityImageView v-if="log.image" :image-id="log.image.id" :width="log.image.width" :height="log.image.height" square />
        <p class="mt-2 truncate text-xs">{{ log.user.display_name }} · {{ dateLabel(log.activity_date) }}</p>
        <NuxtLink :to="`/logs/${log.id}`" @click="openDetail($event, log.id)" class="text-xs text-blue-600 dark:text-blue-300">{{ t('gallery.viewActivity') }}</NuxtLink>
      </article>
    </div>
    <p v-if="!logs.length && !isLoading && !error" class="py-8 text-center text-sm text-slate-500">{{ t('gallery.empty') }}</p>
    <div ref="sentinel" class="h-4" />
    <p v-if="isLoading" role="status" class="text-center text-xs">{{ t('gallery.loading') }}</p>
    <button v-if="error" type="button" class="rounded-lg bg-blue-600 px-4 py-2 text-white" @click="retryList">{{ t('gallery.retry') }}</button>
  </div>
</template>
