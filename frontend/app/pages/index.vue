<script setup lang="ts">
import type { ActivityLog, Category, PaginatedResponse, User } from '#shared/types/api'
import type { FilterPeriod } from '#shared/types/forms'
import plusIcon from '~/assets/icons/plus.svg'
import editIcon from '~/assets/icons/square-edit-outline.svg'

const { fetchApi } = useApi()
const { user } = useAuth()
const editIconStyle = { maskImage: `url("${editIcon}")` }
const plusIconStyle = { maskImage: `url("${plusIcon}")` }

const logs = ref<ActivityLog[]>([])
const categories = ref<Category[]>([])
const familyMembers = ref<User[]>([])
const isLoading = ref(false)
const isModalOpen = ref(false)
const errorMessage = ref('')
const currentPage = ref(1)
const lastPage = ref(1)
const total = ref(0)

const filterPeriod = ref<FilterPeriod>('this_week')
const filterFrom = ref('')
const filterTo = ref('')
// 初期表示は自分の投稿のみ。空文字列は全員表示を表す。
const filterUserId = ref<string>(user.value ? String(user.value.id) : '')
const filterCategoryId = ref<string>('')

const setPeriodRange = () => {
  const now = new Date()
  if (filterPeriod.value === 'this_week') {
    const day = now.getDay() || 7
    const monday = new Date(now)
    monday.setDate(now.getDate() - day + 1)
    const sunday = new Date(monday)
    sunday.setDate(monday.getDate() + 6)
    filterFrom.value = monday.toISOString().slice(0, 10)
    filterTo.value = sunday.toISOString().slice(0, 10)
  } else if (filterPeriod.value === 'this_month') {
    const firstDay = new Date(now.getFullYear(), now.getMonth(), 1)
    const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0)
    filterFrom.value = firstDay.toISOString().slice(0, 10)
    filterTo.value = lastDay.toISOString().slice(0, 10)
  }
}

const fetchLogs = async (page = 1) => {
  if (filterFrom.value && filterTo.value && filterFrom.value > filterTo.value) {
    errorMessage.value = '開始日は終了日以前の日付を指定してください。'
    return
  }

  isLoading.value = true
  errorMessage.value = ''
  try {
    const params = new URLSearchParams()
    if (filterFrom.value) params.append('from', filterFrom.value)
    if (filterTo.value) params.append('to', filterTo.value)
    if (filterUserId.value) params.append('user_id', filterUserId.value)
    if (filterCategoryId.value) params.append('category_id', filterCategoryId.value)
    params.append('page', String(page))

    const res = await fetchApi<PaginatedResponse<ActivityLog>>(`/logs?${params.toString()}`)
    logs.value = res.data
    currentPage.value = res.current_page
    lastPage.value = res.last_page
    total.value = res.total
  } catch {
    errorMessage.value = '記録の取得に失敗しました。通信環境を確認してください。'
  } finally {
    isLoading.value = false
  }
}

const fetchCategories = async () => {
  try {
    categories.value = await fetchApi<Category[]>('/categories')
  } catch {
    errorMessage.value = 'カテゴリの取得に失敗しました。'
  }
}

const fetchMembers = async () => {
  try {
    familyMembers.value = await fetchApi<User[]>('/users')
  } catch {
    errorMessage.value = '家族メンバーの取得に失敗しました。'
  }
}

watch(filterPeriod, () => {
  if (filterPeriod.value !== 'custom') {
    setPeriodRange()
    fetchLogs(1)
  }
})

watch([filterUserId, filterCategoryId], () => {
  fetchLogs(1)
})

onMounted(async () => {
  setPeriodRange()
  await Promise.all([fetchCategories(), fetchMembers(), fetchLogs()])
})
</script>

<template>
  <div class="space-y-4">
    <div v-if="errorMessage" class="p-3 bg-red-50 text-red-700 text-sm rounded-lg border border-red-200">
      {{ errorMessage }}
    </div>
    <div class="bg-white p-3 rounded-xl border border-slate-200 space-y-3">
      <div class="flex items-center justify-between">
        <span class="text-xs font-semibold text-slate-500">表示期間</span>
        <div class="flex space-x-1 bg-slate-100 p-1 rounded-lg">
          <button
            :class="['px-2.5 py-1 text-xs rounded-md font-medium transition', filterPeriod === 'this_week' ? 'bg-white text-blue-600 shadow-xs' : 'text-slate-600']"
            @click="filterPeriod = 'this_week'"
          >
            今週
          </button>
          <button
            :class="['px-2.5 py-1 text-xs rounded-md font-medium transition', filterPeriod === 'this_month' ? 'bg-white text-blue-600 shadow-xs' : 'text-slate-600']"
            @click="filterPeriod = 'this_month'"
          >
            今月
          </button>
          <button
            :class="['px-2.5 py-1 text-xs rounded-md font-medium transition', filterPeriod === 'custom' ? 'bg-white text-blue-600 shadow-xs' : 'text-slate-600']"
            @click="filterPeriod = 'custom'"
          >
            指定
          </button>
        </div>
      </div>

      <div v-if="filterPeriod === 'custom'" class="flex items-center space-x-2 pt-2 border-t">
        <input v-model="filterFrom" type="date" class="px-2 py-1 border text-xs rounded-lg w-full">
        <span class="text-slate-400 text-xs">〜</span>
        <input v-model="filterTo" type="date" class="px-2 py-1 border text-xs rounded-lg w-full">
        <button class="px-3 py-1 bg-slate-800 text-white text-xs rounded-lg shrink-0" @click="fetchLogs(1)">
          適用
        </button>
      </div>

      <div class="grid grid-cols-2 gap-2 pt-2 border-t border-slate-100">
        <div>
          <label class="block text-2xs font-semibold text-slate-500 mb-1">投稿者</label>
          <select v-model="filterUserId" class="w-full px-2 py-1.5 text-xs border border-slate-300 rounded-lg bg-white">
            <option value="">全員</option>
            <option v-for="member in familyMembers" :key="member.id" :value="String(member.id)">
              {{ member.id === user?.id ? '自分' : member.display_name }}
            </option>
          </select>
        </div>
        <div>
          <label class="block text-2xs font-semibold text-slate-500 mb-1">カテゴリ</label>
          <select v-model="filterCategoryId" class="w-full px-2 py-1.5 text-xs border border-slate-300 rounded-lg bg-white">
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
      @click="isModalOpen = true"
    >
      <span aria-hidden="true" class="h-5 w-5 shrink-0 bg-current [mask-size:contain] [mask-repeat:no-repeat] [mask-position:center]" :style="plusIconStyle" />
      <span>アクティビティを記録する</span>
    </button>

    <div v-if="isLoading" class="text-center py-8 text-slate-400 text-sm">
      読み込み中...
    </div>

    <div v-else-if="logs.length === 0" class="text-center py-8 bg-white rounded-xl border border-dashed border-slate-300 p-6">
      <p class="text-slate-500 text-sm">該当する記録がありません。</p>
    </div>

    <div v-else class="space-y-3">
      <article
        v-for="log in logs"
        :key="log.id"
        class="block bg-white p-4 rounded-xl border border-slate-200 shadow-2xs space-y-2 relative transition"
        :class="log.user_id === user?.id ? 'hover:border-blue-300 focus-within:ring-2 focus-within:ring-blue-500' : ''"
      >
        <div class="flex flex-wrap gap-2 items-center justify-between">
          <div class="flex flex-wrap gap-2 items-center">
            <span
              class="px-2 py-0.5 text-xs font-semibold text-white rounded-full"
              :style="{ backgroundColor: log.category?.color_code || '#3B82F6' }"
            >
              {{ log.category?.name }}
            </span>
            <span class="text-xs font-bold text-slate-700">{{ log.user?.display_name }}</span>
          </div>

          <span class="ml-auto text-xs text-slate-400 whitespace-nowrap">
            {{ log.activity_date }} {{ log.activity_time ? log.activity_time.slice(0, 5) : '' }}
          </span>
        </div>

        <p class="text-sm text-slate-800 whitespace-pre-wrap font-normal">
          {{ log.content }}
        </p>

        <p v-if="log.note" class="text-xs text-slate-500 bg-slate-50 p-2 rounded-lg">
          メモ: {{ log.note }}
        </p>
        <div v-if="log.user_id === user?.id" class="flex justify-end">
          <NuxtLink :to="`/logs/${log.id}`" :aria-label="`${log.activity_date}の記録を編集`" class="edit-link flex h-9 w-9 items-center justify-center rounded-lg text-blue-600 bg-blue-50">
            <span aria-hidden="true" class="h-5 w-5 bg-current [mask-size:contain] [mask-repeat:no-repeat] [mask-position:center]" :style="editIconStyle" />
          </NuxtLink>
        </div>
      </article>

      <div v-if="lastPage > 1" class="flex items-center justify-between pt-2">
        <button
          type="button"
          :disabled="currentPage <= 1 || isLoading"
          class="px-3 py-2 text-sm rounded-lg border border-slate-300 bg-white disabled:opacity-40"
          @click="fetchLogs(currentPage - 1)"
        >
          前へ
        </button>
        <span class="text-xs text-slate-500">
          {{ currentPage }} / {{ lastPage }} ページ（全 {{ total }} 件）
        </span>
        <button
          type="button"
          :disabled="currentPage >= lastPage || isLoading"
          class="px-3 py-2 text-sm rounded-lg border border-slate-300 bg-white disabled:opacity-40"
          @click="fetchLogs(currentPage + 1)"
        >
          次へ
        </button>
      </div>
    </div>

    <LogCreateModal
      :is-open="isModalOpen"
      :categories="categories"
      @close="isModalOpen = false"
      @created="fetchLogs(1)"
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
