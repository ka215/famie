<script setup lang="ts">
interface ActivityLogItem {
  id: number
  user_id: number
  activity_date: string
  activity_time: string | null
  content: string
  note: string | null
  user: { id: number; display_name: string; role: 'parent' | 'child' }
  category: { id: number; name: string; color_code: string }
}

const { fetchApi } = useApi()
const { user } = useAuth()

const logs = ref<ActivityLogItem[]>([])
const categories = ref<Array<{ id: number; name: string; color_code: string }>>([])
const familyMembers = ref<
  Array<{ id: number; username: string; display_name: string; role: string }>
>([])
const isLoading = ref(false)
const isModalOpen = ref(false)

const filterPeriod = ref<'this_week' | 'this_month' | 'custom'>('this_week')
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

const fetchLogs = async () => {
  isLoading.value = true
  try {
    const params = new URLSearchParams()
    if (filterFrom.value) params.append('from', filterFrom.value)
    if (filterTo.value) params.append('to', filterTo.value)
    if (filterUserId.value) params.append('user_id', filterUserId.value)
    if (filterCategoryId.value) params.append('category_id', filterCategoryId.value)

    const res = await fetchApi<{ data: ActivityLogItem[] }>(`/logs?${params.toString()}`)
    logs.value = res.data
  } catch (err) {
    console.error('ログの取得に失敗しました', err)
  } finally {
    isLoading.value = false
  }
}

const fetchCategories = async () => {
  try {
    categories.value =
      await fetchApi<Array<{ id: number; name: string; color_code: string }>>('/categories')
  } catch (err) {
    console.error('カテゴリの取得に失敗しました', err)
  }
}

const fetchMembers = async () => {
  try {
    familyMembers.value =
      await fetchApi<Array<{ id: number; username: string; display_name: string; role: string }>>(
        '/users'
      )
  } catch (err) {
    console.error('メンバー一覧の取得に失敗しました', err)
  }
}

watch(filterPeriod, () => {
  if (filterPeriod.value !== 'custom') {
    setPeriodRange()
    fetchLogs()
  }
})

watch([filterUserId, filterCategoryId], () => {
  fetchLogs()
})

onMounted(async () => {
  setPeriodRange()
  await Promise.all([fetchCategories(), fetchMembers(), fetchLogs()])
})
</script>

<template>
  <div class="space-y-4">
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
        <button class="px-3 py-1 bg-slate-800 text-white text-xs rounded-lg shrink-0" @click="fetchLogs">
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
      <span class="text-lg font-bold">+</span>
      <span>アクティビティを記録する</span>
    </button>

    <div v-if="isLoading" class="text-center py-8 text-slate-400 text-sm">
      読み込み中...
    </div>

    <div v-else-if="logs.length === 0" class="text-center py-8 bg-white rounded-xl border border-dashed border-slate-300 p-6">
      <p class="text-slate-500 text-sm">該当する記録がありません。</p>
    </div>

    <div v-else class="space-y-3">
      <NuxtLink
        v-for="log in logs"
        :key="log.id"
        :to="`/logs/${log.id}`"
        class="block bg-white p-4 rounded-xl border border-slate-200 shadow-2xs space-y-2 relative hover:border-blue-300 transition"
      >
        <div class="flex items-center justify-between">
          <div class="flex items-center space-x-2">
            <span
              class="px-2 py-0.5 text-xs font-semibold text-white rounded-full"
              :style="{ backgroundColor: log.category?.color_code || '#3B82F6' }"
            >
              {{ log.category?.name }}
            </span>
            <span class="text-xs font-bold text-slate-700">{{ log.user?.display_name }}</span>
          </div>

          <span class="text-xs text-slate-400">
            {{ log.activity_date }} {{ log.activity_time ? log.activity_time.slice(0, 5) : '' }}
          </span>
        </div>

        <p class="text-sm text-slate-800 whitespace-pre-wrap font-normal">
          {{ log.content }}
        </p>

        <p v-if="log.note" class="text-xs text-slate-500 bg-slate-50 p-2 rounded-lg">
          メモ: {{ log.note }}
        </p>
      </NuxtLink>
    </div>

    <LogCreateModal
      :is-open="isModalOpen"
      :categories="categories"
      @close="isModalOpen = false"
      @created="fetchLogs"
    />
  </div>
</template>
