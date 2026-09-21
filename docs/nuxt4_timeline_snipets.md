メインとなるタイムライン画面（ログ一覧表示・期間フィルタ・ログ投稿）のコンポーネントコードを作成しました。

スマホからの操作を意識し、ボトムシート風の投稿モーダルや、期間（今週・今月・指定期間）切り替えフィルタ、家族ごとの投稿カード表示を実装しています。

---

## 1. メインレイアウト (`app/layouts/default.vue`)

アプリ全体で共通利用するヘッダーおよびボトムナビゲーションです。スマホでアプリのように操作できるよう画面下部にナビゲーションを固定しています。

```vue
<!-- app/layouts/default.vue -->
<script setup lang="ts">
const { user, logout } = useAuth()
</script>

<template>
  <div class="min-h-screen bg-slate-50 pb-20">
    <!-- ヘッダー -->
    <header class="sticky top-0 z-10 bg-white border-b border-slate-200 px-4 py-3 flex items-center justify-between shadow-xs">
      <div class="flex items-center space-x-2">
        <h1 class="text-lg font-bold text-slate-800">ファミログ</h1>
      </div>
      <div class="flex items-center space-x-3 text-sm">
        <span class="text-slate-600 font-medium">{{ user?.display_name }} さん</span>
        <button @click="logout" class="text-slate-400 hover:text-red-500 text-xs">
          ログアウト
        </button>
      </div>
    </header>

    <!-- メインコンテンツ -->
    <main class="max-w-md mx-auto p-4">
      <slot />
    </main>

    <!-- ボトムナビゲーション (スマホ向け) -->
    <nav class="fixed bottom-0 left-0 right-0 bg-white border-t border-slate-200 py-2 px-6 flex justify-around items-center z-10">
      <NuxtLink to="/" class="flex flex-col items-center text-blue-600">
        <span class="text-xs font-medium">タイムライン</span>
      </NuxtLink>
      <NuxtLink to="/settings" class="flex flex-col items-center text-slate-400 hover:text-slate-600">
        <span class="text-xs font-medium">設定</span>
      </NuxtLink>
    </nav>
  </div>
</template>

```

---

## 2. ログ投稿モーダルコンポーネント (`app/components/LogCreateModal.vue`)

アクティビティを新規登録するための入力フォームです。カテゴリ選択や日付・時刻指定に対応しています。

```vue
<!-- app/components/LogCreateModal.vue -->
<script setup lang="ts">
const props = defineProps<{
  isOpen: boolean
  categories: Array<{ id: number; name: string; color_code: string }>
}>()

const emit = defineEmits(['close', 'created'])
const { fetchApi } = useApi()

// 本日の日付（YYYY-MM-DD）を初期値に設定
const today = new Date().toISOString().split('T')[0]
const currentTime = new Date().toTimeString().slice(0, 5)

const form = ref({
  category_id: props.categories[0]?.id || 1,
  activity_date: today,
  activity_time: currentTime,
  content: '',
  note: '',
})

const isLoading = ref(false)
const errorMessage = ref('')

const handleSubmit = async () => {
  if (!form.value.content) {
    errorMessage.value = '活動内容を入力してください。'
    return
  }

  isLoading.value = true
  errorMessage.value = ''

  try {
    await fetchApi('/logs', {
      method: 'POST',
      body: form.value,
    })
    
    // フォームリセット
    form.value.content = ''
    form.value.note = ''
    
    emit('created')
    emit('close')
  } catch (err: any) {
    errorMessage.value = err.data?.message || '登録に失敗しました。'
  } finally {
    isLoading.value = false
  }
}
</script>

<template>
  <div v-if="isOpen" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-0 sm:p-4">
    <div class="w-full max-w-md bg-white rounded-t-2xl sm:rounded-2xl p-6 space-y-4 max-h-[90vh] overflow-y-auto">
      <div class="flex justify-between items-center border-b pb-3">
        <h2 class="text-lg font-bold text-slate-800">アクティビティを記録</h2>
        <button @click="emit('close')" class="text-slate-400 hover:text-slate-600 text-xl font-bold">&times;</button>
      </div>

      <div v-if="errorMessage" class="p-3 bg-red-50 text-red-600 text-xs rounded-lg">
        {{ errorMessage }}
      </div>

      <form @submit.prevent="handleSubmit" class="space-y-4">
        <!-- カテゴリ選択 -->
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">カテゴリ</label>
          <select
            v-model="form.category_id"
            class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            <option v-for="cat in categories" :key="cat.id" :value="cat.id">
              {{ cat.name }}
            </option>
          </select>
        </div>

        <!-- 日付・時刻 -->
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">実施日</label>
            <input
              v-model="form.activity_date"
              type="date"
              required
              class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">時刻（任意）</label>
            <input
              v-model="form.activity_time"
              type="time"
              class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
        </div>

        <!-- 活動内容 -->
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">活動内容</label>
          <textarea
            v-model="form.content"
            required
            rows="3"
            placeholder="例: 算数のドリルを2ページ進めた"
            class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          ></textarea>
        </div>

        <!-- 補足メモ -->
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">補足メモ（任意）</label>
          <input
            v-model="form.note"
            type="text"
            placeholder="例: つまずいた箇所あり"
            class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
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
  </div>
</template>

```

---

## 3. タイムライン画面 (`app/pages/index.vue`)

ログの一覧表示、期間フィルタリング（今週・今月・カスタム範囲）、自分以外の家族ログの確認、権限に基づく編集/削除ボタン制御を行います。

```vue
<!-- app/pages/index.vue -->
<script setup lang="ts">
interface ActivityLog {
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
const { user, isParent } = useAuth()

const logs = ref<ActivityLog[]>([])
const categories = ref<Array<{ id: number; name: string; color_code: string }>>([])
const isLoading = ref(false)
const isModalOpen = ref(false)

// フィルタ状態
const filterPeriod = ref<'this_week' | 'this_month' | 'custom'>('this_week')
const filterFrom = ref('')
const filterTo = ref('')

// 日付範囲の設定関数
const setPeriodRange = () => {
  const now = new Date()
  if (filterPeriod.value === 'this_week') {
    const first = now.getDate() - now.getDay() + 1 // 月曜日
    const last = first + 6
    filterFrom.value = new Date(now.setDate(first)).toISOString().split('T')[0]
    filterTo.value = new Date(now.setDate(last)).toISOString().split('T')[0]
  } else if (filterPeriod.value === 'this_month') {
    const firstDay = new Date(now.getFullYear(), now.getMonth(), 1)
    const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0)
    filterFrom.value = firstDay.toISOString().split('T')[0]
    filterTo.value = lastDay.toISOString().split('T')[0]
  }
}

// ログ取得処理
const fetchLogs = async () => {
  isLoading.value = true
  try {
    const params = new URLSearchParams()
    if (filterFrom.value) params.append('from', filterFrom.value)
    if (filterTo.value) params.append('to', filterTo.value)

    const res = await fetchApi<{ data: ActivityLog[] }>(`/logs?${params.toString()}`)
    logs.value = res.data
  } catch (err) {
    console.error('ログの取得に失敗しました', err)
  } finally {
    isLoading.value = false
  }
}

// 初期カテゴリ取得
const fetchCategories = async () => {
  try {
    const res = await fetchApi<Array<{ id: number; name: string; color_code: string }>>('/categories')
    categories.value = res
  } catch (err) {
    console.error('カテゴリの取得に失敗しました', err)
  }
}

// 削除処理
const handleDelete = async (logId: number) => {
  if (!confirm('このログを削除しますか？')) return
  try {
    await fetchApi(`/logs/${logId}`, { method: 'DELETE' })
    await fetchLogs()
  } catch (err) {
    alert('削除に失敗しました。')
  }
}

// 編集/削除ボタンの権限判定 (親または投稿者本人のみ表示)
const canEditOrDelete = (log: ActivityLog) => {
  if (isParent.value) return true
  return log.user_id === user.value?.id
}

watch(filterPeriod, () => {
  if (filterPeriod.value !== 'custom') {
    setPeriodRange()
    fetchLogs()
  }
})

onMounted(async () => {
  setPeriodRange()
  await Promise.all([fetchCategories(), fetchLogs()])
})
</script>

<template>
  <div class="space-y-4">
    <!-- 期間フィルタ設定 -->
    <div class="bg-white p-3 rounded-xl border border-slate-200 space-y-3">
      <div class="flex items-center justify-between">
        <span class="text-xs font-semibold text-slate-500">表示期間</span>
        <div class="flex space-x-1 bg-slate-100 p-1 rounded-lg">
          <button
            @click="filterPeriod = 'this_week'"
            :class="['px-2.5 py-1 text-xs rounded-md font-medium transition', filterPeriod === 'this_week' ? 'bg-white text-blue-600 shadow-xs' : 'text-slate-600']"
          >
            今週
          </button>
          <button
            @click="filterPeriod = 'this_month'"
            :class="['px-2.5 py-1 text-xs rounded-md font-medium transition', filterPeriod === 'this_month' ? 'bg-white text-blue-600 shadow-xs' : 'text-slate-600']"
          >
            今月
          </button>
          <button
            @click="filterPeriod = 'custom'"
            :class="['px-2.5 py-1 text-xs rounded-md font-medium transition', filterPeriod === 'custom' ? 'bg-white text-blue-600 shadow-xs' : 'text-slate-600']"
          >
            指定
          </button>
        </div>
      </div>

      <!-- カスタム範囲入力 -->
      <div v-if="filterPeriod === 'custom'" class="flex items-center space-x-2 pt-2 border-t">
        <input v-model="filterFrom" type="date" class="px-2 py-1 border text-xs rounded-lg w-full" />
        <span class="text-slate-400 text-xs">〜</span>
        <input v-model="filterTo" type="date" class="px-2 py-1 border text-xs rounded-lg w-full" />
        <button @click="fetchLogs" class="px-3 py-1 bg-slate-800 text-white text-xs rounded-lg shrink-0">
          適用
        </button>
      </div>
    </div>

    <!-- 新規記録ボタン (フローティング風) -->
    <button
      @click="isModalOpen = true"
      class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-xl shadow-md flex items-center justify-center space-x-2 transition"
    >
      <span class="text-lg font-bold">+</span>
      <span>アクティビティを記録する</span>
    </button>

    <!-- ログ一覧タイムライン -->
    <div v-if="isLoading" class="text-center py-8 text-slate-400 text-sm">
      読み込み中...
    </div>

    <div v-else-if="logs.length === 0" class="text-center py-8 bg-white rounded-xl border border-dashed border-slate-300 p-6">
      <p class="text-slate-500 text-sm">該当する記録がありません。</p>
    </div>

    <div v-else class="space-y-3">
      <div
        v-for="log in logs"
        :key="log.id"
        class="bg-white p-4 rounded-xl border border-slate-200 shadow-2xs space-y-2 relative"
      >
        <div class="flex items-center justify-between">
          <div class="flex items-center space-x-2">
            <!-- カテゴリバッジ -->
            <span
              class="px-2 py-0.5 text-xs font-semibold text-white rounded-full"
              :style="{ backgroundColor: log.category?.color_code || '#3B82F6' }"
            >
              {{ log.category?.name }}
            </span>
            <span class="text-xs font-bold text-slate-700">{{ log.user?.display_name }}</span>
          </div>

          <!-- 日時表示 -->
          <span class="text-xs text-slate-400">
            {{ log.activity_date }} {{ log.activity_time ? log.activity_time.slice(0, 5) : '' }}
          </span>
        </div>

        <!-- 活動内容 -->
        <p class="text-sm text-slate-800 whitespace-pre-wrap font-normal">
          {{ log.content }}
        </p>

        <!-- 補足メモ -->
        <p v-if="log.note" class="text-xs text-slate-500 bg-slate-50 p-2 rounded-lg">
          メモ: {{ log.note }}
        </p>

        <!-- 操作用ボタン (権限のあるユーザーのみ表示) -->
        <div v-if="canEditOrDelete(log)" class="flex justify-end space-x-2 pt-1 border-t border-slate-100">
          <button @click="handleDelete(log.id)" class="text-xs text-red-500 hover:text-red-700">
            削除
          </button>
        </div>
      </div>
    </div>

    <!-- 投稿用モーダル -->
    <LogCreateModal
      :is-open="isModalOpen"
      :categories="categories"
      @close="isModalOpen = false"
      @created="fetchLogs"
    />
  </div>
</template>

```

---