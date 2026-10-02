<script setup lang="ts">
import type { ActivityLog, Category, FamilyMember, PaginatedResponse } from '#shared/types/api'
import type { FilterPeriod } from '#shared/types/forms'
import {
  countActivitiesByDate,
  formatLocalDate,
  getCalendarDisplayLocale,
  getFirstDayOfWeek,
  getMonthRange,
  getPreferredLocale,
  getWeekRange,
  parseLocalDate,
} from '#shared/utils/calendar'
import { categoryStyle } from '#shared/utils/categoryColor'
import plusIcon from '~/assets/icons/plus.svg'
import editIcon from '~/assets/icons/square-edit-outline.svg'

const { fetchApi } = useApi()
const { user, groupId } = useAuth()
const groupPath = (path: string) => `/groups/${groupId.value}${path}`
const editIconStyle = { maskImage: `url("${editIcon}")` }
const plusIconStyle = { maskImage: `url("${plusIcon}")` }

const logs = ref<ActivityLog[]>([])
const categories = ref<Category[]>([])
const familyMembers = ref<FamilyMember[]>([])
const isLoading = ref(false)
const isModalOpen = ref(false)
const badgeStyle = categoryStyle
const openCreateModal = (event: MouseEvent) => {
  // Safariはタップしたボタンへ自動でフォーカスしないため、復帰先を明示する。
  const trigger = event.currentTarget as HTMLButtonElement
  trigger.focus({ preventScroll: true })
  isModalOpen.value = true
}
const errorMessage = ref('')
const currentPage = ref(1)
const lastPage = ref(1)
const total = ref(0)
const locale = ref('ja-JP')
const firstDayOfWeek = ref(0)
const calendarYear = ref(new Date().getFullYear())
const calendarMonth = ref(new Date().getMonth())
const activityCounts = ref<Record<string, number>>({})
const isCalendarLoading = ref(false)
const selectedDate = ref<string | null>(null)
const isDayView = ref(false)
let logRequestId = 0
let calendarRequestId = 0

const filterPeriod = ref<FilterPeriod>('this_week')
const filterFrom = ref('')
const filterTo = ref('')
// 初期表示は自分の投稿のみ。空文字列は全員表示を表す。
const filterUserId = ref<string>(user.value ? String(user.value.id) : '')
const filterCategoryId = ref<string>('')

const setPeriodRange = () => {
  if (filterPeriod.value === 'this_week') {
    const target = selectedDate.value ? parseLocalDate(selectedDate.value) : new Date()
    const range = getWeekRange(target, firstDayOfWeek.value)
    filterFrom.value = range.from
    filterTo.value = range.to
  }
}

const appendFilters = (params: URLSearchParams) => {
  if (filterUserId.value) params.set('user_id', filterUserId.value)
  if (filterCategoryId.value) params.set('category_id', filterCategoryId.value)
}

const fetchLogs = async (page = 1, day?: string | null) => {
  const activeDay =
    day === undefined
      ? filterPeriod.value === 'this_week' || isDayView.value
        ? selectedDate.value
        : null
      : day
  const from = activeDay || filterFrom.value
  const to = activeDay || filterTo.value
  if (from && to && from > to) {
    errorMessage.value = '開始日は終了日以前の日付を指定してください。'
    return
  }

  const requestId = ++logRequestId
  isLoading.value = true
  errorMessage.value = ''
  try {
    const params = new URLSearchParams()
    if (from) params.set('from', from)
    if (to) params.set('to', to)
    appendFilters(params)
    params.set('page', String(page))

    const res = await fetchApi<PaginatedResponse<ActivityLog>>(
      groupPath(`/logs?${params.toString()}`)
    )
    if (requestId !== logRequestId) return
    logs.value = res.data
    currentPage.value = res.current_page
    lastPage.value = res.last_page
    total.value = res.total
  } catch {
    if (requestId !== logRequestId) return
    errorMessage.value = '記録の取得に失敗しました。通信環境を確認してください。'
  } finally {
    if (requestId === logRequestId) isLoading.value = false
  }
}

const fetchMonthCounts = async () => {
  const requestId = ++calendarRequestId
  isCalendarLoading.value = true
  activityCounts.value = {}
  errorMessage.value = ''
  const range = getMonthRange(calendarYear.value, calendarMonth.value)

  try {
    const fetchPage = async (page: number) => {
      const params = new URLSearchParams({ from: range.from, to: range.to, page: String(page) })
      appendFilters(params)
      return await fetchApi<PaginatedResponse<ActivityLog>>(groupPath(`/logs?${params.toString()}`))
    }

    const firstPage = await fetchPage(1)
    const remainingPages = Array.from(
      { length: Math.max(0, firstPage.last_page - 1) },
      (_, index) => index + 2
    )
    const remaining = await Promise.all(remainingPages.map(fetchPage))
    if (requestId !== calendarRequestId) return
    const monthLogs = [firstPage, ...remaining].flatMap((response) => response.data)
    activityCounts.value = countActivitiesByDate(monthLogs.map((log) => log.activity_date))
  } catch {
    if (requestId !== calendarRequestId) return
    errorMessage.value = '月の記録件数の取得に失敗しました。もう一度操作してください。'
  } finally {
    if (requestId === calendarRequestId) isCalendarLoading.value = false
  }
}

const fetchCategories = async () => {
  try {
    categories.value = await fetchApi<Category[]>(groupPath('/categories'))
  } catch {
    errorMessage.value = 'カテゴリの取得に失敗しました。'
  }
}

const fetchMembers = async () => {
  try {
    familyMembers.value = await fetchApi<FamilyMember[]>(groupPath('/members'))
  } catch {
    errorMessage.value = '家族メンバーの取得に失敗しました。'
  }
}

const selectPeriod = (period: FilterPeriod) => {
  filterPeriod.value = period
  isDayView.value = false
  if (period === 'month') {
    selectedDate.value = null
    logRequestId++
    isLoading.value = false
    fetchMonthCounts()
  } else {
    calendarRequestId++
    isCalendarLoading.value = false
  }
  if (period === 'this_week') {
    selectedDate.value = formatLocalDate(new Date())
    setPeriodRange()
    fetchLogs(1)
  } else if (period === 'custom') {
    selectedDate.value = null
  }
}

const selectWeekDate = (date: string) => {
  selectedDate.value = date
  setPeriodRange()
  fetchLogs(1)
}

const moveWeek = (offset: number) => {
  const target = selectedDate.value ? parseLocalDate(selectedDate.value) : new Date()
  target.setDate(target.getDate() + offset * 7)
  selectWeekDate(formatLocalDate(target))
}

const moveCalendarMonth = (offset: number) => {
  const target = new Date(calendarYear.value, calendarMonth.value + offset, 1, 12)
  calendarYear.value = target.getFullYear()
  calendarMonth.value = target.getMonth()
  selectedDate.value = null
  isDayView.value = false
  fetchMonthCounts()
}

const showCurrentMonth = () => {
  const now = new Date()
  calendarYear.value = now.getFullYear()
  calendarMonth.value = now.getMonth()
  selectedDate.value = null
  isDayView.value = false
  fetchMonthCounts()
}

const selectCalendarDate = (date: string, inCurrentMonth: boolean) => {
  if (!inCurrentMonth) {
    const target = parseLocalDate(date)
    calendarYear.value = target.getFullYear()
    calendarMonth.value = target.getMonth()
    selectedDate.value = null
    isDayView.value = false
    fetchMonthCounts()
    return
  }
  selectedDate.value = date
  isDayView.value = true
  fetchLogs(1, date)
}

const returnToCalendar = () => {
  isDayView.value = false
}

const selectedDateLabel = computed(() =>
  selectedDate.value
    ? new Intl.DateTimeFormat(getCalendarDisplayLocale(locale.value), {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        weekday: 'short',
      }).format(parseLocalDate(selectedDate.value))
    : ''
)

const weekDateLabel = computed(() => {
  if (!selectedDate.value) return { date: '', weekday: '' }
  const date = parseLocalDate(selectedDate.value)
  const displayLocale = getCalendarDisplayLocale(locale.value)
  return {
    date: new Intl.DateTimeFormat(displayLocale, {
      month: 'numeric',
      day: 'numeric',
    }).format(date),
    weekday: new Intl.DateTimeFormat(displayLocale, { weekday: 'long' }).format(date),
  }
})

const modalInitialDate = computed(() =>
  (filterPeriod.value === 'this_week' || isDayView.value) && selectedDate.value
    ? selectedDate.value
    : formatLocalDate(new Date())
)

const refreshCurrentView = async () => {
  if (filterPeriod.value === 'month') {
    await fetchMonthCounts()
    if (isDayView.value && selectedDate.value) await fetchLogs(1, selectedDate.value)
  } else {
    await fetchLogs(1)
  }
}

watch([filterUserId, filterCategoryId], () => {
  if (filterPeriod.value === 'month') {
    fetchMonthCounts()
    if (isDayView.value && selectedDate.value) fetchLogs(1, selectedDate.value)
  } else {
    fetchLogs(1)
  }
})

onMounted(async () => {
  locale.value = getPreferredLocale(navigator.languages, navigator.language)
  firstDayOfWeek.value = getFirstDayOfWeek(locale.value)
  selectedDate.value = formatLocalDate(new Date())
  setPeriodRange()
  await Promise.all([fetchCategories(), fetchMembers(), fetchLogs()])
})
</script>

<template>
  <div class="space-y-4">
    <div v-if="errorMessage" class="p-3 bg-red-50 dark:bg-red-950 text-red-700 dark:text-red-300 text-sm rounded-lg border border-red-200 dark:border-red-800">
      {{ errorMessage }}
    </div>
    <div class="bg-white dark:bg-slate-800 p-3 rounded-xl border border-slate-200 dark:border-slate-700 space-y-3">
      <div class="flex items-center justify-between">
        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">表示期間</span>
        <div class="flex space-x-1 bg-slate-100 dark:bg-slate-900 p-1 rounded-lg">
          <button
            :class="['px-2.5 py-1 text-xs rounded-md font-medium transition', filterPeriod === 'this_week' ? 'bg-white dark:bg-slate-800 text-blue-600 dark:text-blue-300 shadow-xs' : 'text-slate-600 dark:text-slate-300']"
            @click="selectPeriod('this_week')"
          >
            今週
          </button>
          <button
            :class="['px-2.5 py-1 text-xs rounded-md font-medium transition', filterPeriod === 'month' ? 'bg-white dark:bg-slate-800 text-blue-600 dark:text-blue-300 shadow-xs' : 'text-slate-600 dark:text-slate-300']"
            @click="selectPeriod('month')"
          >
            月表示
          </button>
          <button
            :class="['px-2.5 py-1 text-xs rounded-md font-medium transition', filterPeriod === 'custom' ? 'bg-white dark:bg-slate-800 text-blue-600 dark:text-blue-300 shadow-xs' : 'text-slate-600 dark:text-slate-300']"
            @click="selectPeriod('custom')"
          >
            指定
          </button>
        </div>
      </div>

      <div v-if="filterPeriod === 'custom'" class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2 pt-2 border-t border-slate-100 dark:border-slate-700">
        <input v-model="filterFrom" aria-label="開始日" type="date" class="block min-w-0 max-w-full appearance-none px-2 py-1 border border-slate-200 dark:border-slate-700 text-xs rounded-lg w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
        <span class="text-slate-400 dark:text-slate-400 text-xs">〜</span>
        <input v-model="filterTo" aria-label="終了日" type="date" class="block min-w-0 max-w-full appearance-none px-2 py-1 border border-slate-200 dark:border-slate-700 text-xs rounded-lg w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
        <button class="col-span-3 justify-self-end px-3 py-1 bg-slate-800 dark:bg-slate-700 text-white text-xs rounded-lg" @click="fetchLogs(1)">
          適用
        </button>
      </div>

      <div class="grid grid-cols-2 gap-2 pt-2 border-t border-slate-100 dark:border-slate-700">
        <div>
          <label class="block text-2xs font-semibold text-slate-500 dark:text-slate-400 mb-1">投稿者</label>
          <select v-model="filterUserId" class="w-full px-2 py-1.5 text-xs border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
            <option value="">全員</option>
            <option v-for="member in familyMembers" :key="member.id" :value="String(member.id)">
              {{ member.id === user?.id ? '自分' : member.display_name }}
            </option>
          </select>
        </div>
        <div>
          <label class="block text-2xs font-semibold text-slate-500 dark:text-slate-400 mb-1">カテゴリ</label>
          <select v-model="filterCategoryId" class="w-full px-2 py-1.5 text-xs border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
            <option value="">全カテゴリ</option>
            <option v-for="cat in categories" :key="cat.id" :value="String(cat.id)">
              {{ cat.name }}
            </option>
          </select>
        </div>
      </div>
    </div>

    <button
      class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-xl shadow-md flex items-center justify-center space-x-2 transition"
      @click="openCreateModal"
    >
      <span aria-hidden="true" class="h-5 w-5 shrink-0 bg-current [mask-size:contain] [mask-repeat:no-repeat] [mask-position:center]" :style="plusIconStyle" />
      <span>アクティビティを記録する</span>
    </button>

    <WeekCalendar
      v-if="filterPeriod === 'this_week' && selectedDate"
      :selected-date="selectedDate"
      :locale="locale"
      :first-day="firstDayOfWeek"
      @previous="moveWeek(-1)"
      @next="moveWeek(1)"
      @select="selectWeekDate"
    />

    <div v-if="filterPeriod === 'month' && !isDayView" class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-3">
      <MonthCalendar
        :year="calendarYear"
        :month="calendarMonth"
        :locale="locale"
        :first-day="firstDayOfWeek"
        :counts="activityCounts"
        :is-loading="isCalendarLoading"
        :selected-date="selectedDate"
        @previous="moveCalendarMonth(-1)"
        @next="moveCalendarMonth(1)"
        @current="showCurrentMonth"
        @select="selectCalendarDate"
      />
    </div>

    <div v-if="filterPeriod === 'month' && isDayView" class="flex items-center justify-between gap-3 rounded-xl border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950 p-3">
      <div>
        <p class="text-2xs font-semibold text-blue-600 dark:text-blue-300">指定日のアクティビティ</p>
        <h2 class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ selectedDateLabel }}</h2>
      </div>
      <button type="button" class="shrink-0 rounded-lg border border-blue-300 dark:border-blue-500 bg-white dark:bg-slate-800 px-3 py-2 text-xs font-medium text-blue-700 dark:text-blue-300" @click="returnToCalendar">
        カレンダーに戻る
      </button>
    </div>

    <div
      v-if="filterPeriod !== 'month' || isDayView"
      :class="filterPeriod === 'this_week' ? 'grid grid-cols-[3.25rem_minmax(0,1fr)] items-start gap-3' : ''"
    >
      <div v-if="filterPeriod === 'this_week'" class="pt-2 text-center" aria-live="polite">
        <p class="text-sm font-bold leading-tight text-slate-700 dark:text-slate-200">{{ weekDateLabel.date }}</p>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ weekDateLabel.weekday }}</p>
      </div>

      <div class="min-w-0">
        <LoadingIndicator v-if="isLoading" class="py-4" />

        <div v-else-if="logs.length === 0" class="text-center py-8 bg-white dark:bg-slate-800 rounded-xl border border-dashed border-slate-300 dark:border-slate-600 p-6">
          <p class="text-slate-500 dark:text-slate-400 text-sm">該当する記録がありません。</p>
        </div>

        <div v-else class="space-y-3">
          <article
            v-for="log in logs"
            :key="log.id"
            class="block bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-slate-700 shadow-2xs space-y-2 relative transition"
            :class="log.user_id === user?.id ? 'hover:border-blue-300 dark:hover:border-blue-500 focus-within:ring-2 focus-within:ring-blue-500' : ''"
          >
            <div class="flex flex-wrap gap-2 items-center justify-between">
              <div class="flex flex-wrap gap-2 items-center">
                <span
                  class="px-2 py-0.5 text-xs font-semibold text-white rounded-full"
                  :style="badgeStyle(log.category?.color_code)"
                >
                  {{ log.category?.name }}
                </span>
                <span class="text-xs font-bold text-slate-700 dark:text-slate-200">{{ log.user?.display_name }}</span>
              </div>

              <span class="ml-auto text-xs text-slate-400 dark:text-slate-400 whitespace-nowrap">
                {{ log.activity_date }} {{ log.activity_time ? log.activity_time.slice(0, 5) : '' }}
              </span>
            </div>

            <p class="text-sm text-slate-800 dark:text-slate-100 whitespace-pre-wrap font-normal">
              {{ log.content }}
            </p>

            <p v-if="log.note" class="text-xs text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-950 p-2 rounded-lg">
              メモ: {{ log.note }}
            </p>
            <div v-if="log.user_id === user?.id" class="flex justify-end">
              <NuxtLink :to="`/logs/${log.id}`" :aria-label="`${log.activity_date}の記録を編集`" class="edit-link flex h-9 w-9 items-center justify-center rounded-lg text-blue-600 dark:text-blue-300 bg-blue-50 dark:bg-blue-950">
                <span aria-hidden="true" class="h-5 w-5 bg-current [mask-size:contain] [mask-repeat:no-repeat] [mask-position:center]" :style="editIconStyle" />
              </NuxtLink>
            </div>
          </article>

          <div v-if="lastPage > 1" class="flex items-center justify-between pt-2">
            <button
              type="button"
              :disabled="currentPage <= 1 || isLoading"
              class="px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 disabled:opacity-40"
              @click="fetchLogs(currentPage - 1)"
            >
              前へ
            </button>
            <span class="text-xs text-slate-500 dark:text-slate-400">
              {{ currentPage }} / {{ lastPage }} ページ（全 {{ total }} 件）
            </span>
            <button
              type="button"
              :disabled="currentPage >= lastPage || isLoading"
              class="px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 disabled:opacity-40"
              @click="fetchLogs(currentPage + 1)"
            >
              次へ
            </button>
          </div>
        </div>
      </div>
    </div>

    <LogCreateModal
      :is-open="isModalOpen"
      :categories="categories"
      :initial-date="modalInitialDate"
      @close="isModalOpen = false"
      @created="refreshCurrentView"
    />
  </div>
</template>

<style scoped>
.edit-link::after {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: 12px;
}
</style>
