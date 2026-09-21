<script setup lang="ts">
const { user, isParent } = useAuth()
const { fetchApi } = useApi()

const pwdForm = ref({
  current_password: '',
  new_password: '',
  new_password_confirmation: '',
})
const pwdIsLoading = ref(false)
const pwdSuccessMessage = ref('')
const pwdErrorMessage = ref('')

const userForm = ref({
  username: '',
  display_name: '',
  email: '',
  password: '',
  password_confirmation: '',
  role: 'child' as 'parent' | 'child',
})
const userIsLoading = ref(false)
const userSuccessMessage = ref('')
const userErrorMessage = ref('')

const familyMembers = ref<Array<{ id: number, username: string, display_name: string, role: string }>>([])

// カテゴリ管理（親のみ）
const categories = ref<Array<{ id: number, name: string, color_code: string }>>([])
const categoryForm = ref({ name: '', color_code: '#3B82F6' })
const categoryIsLoading = ref(false)
const categorySuccessMessage = ref('')
const categoryErrorMessage = ref('')

const fetchCategories = async () => {
  try {
    categories.value = await fetchApi<Array<{ id: number, name: string, color_code: string }>>('/categories')
  } catch (err) {
    console.error('カテゴリ一覧の取得に失敗しました', err)
  }
}

const handleAddCategory = async () => {
  if (!categoryForm.value.name) {
    categoryErrorMessage.value = 'カテゴリ名を入力してください。'
    return
  }

  categoryIsLoading.value = true
  categoryErrorMessage.value = ''
  categorySuccessMessage.value = ''

  try {
    await fetchApi('/categories', {
      method: 'POST',
      body: categoryForm.value,
    })
    categorySuccessMessage.value = `「${categoryForm.value.name}」を追加しました。`
    categoryForm.value = { name: '', color_code: '#3B82F6' }
    await fetchCategories()
  } catch (err: any) {
    categoryErrorMessage.value = err.data?.message || 'カテゴリの追加に失敗しました。'
  } finally {
    categoryIsLoading.value = false
  }
}

const fetchMembers = async () => {
  try {
    familyMembers.value = await fetchApi<Array<{ id: number, username: string, display_name: string, role: string }>>('/users')
  } catch (err) {
    console.error('メンバー一覧の取得に失敗しました', err)
  }
}

const handlePasswordChange = async () => {
  if (pwdForm.value.new_password !== pwdForm.value.new_password_confirmation) {
    pwdErrorMessage.value = '新しいパスワードが一致しません。'
    return
  }

  pwdIsLoading.value = true
  pwdErrorMessage.value = ''
  pwdSuccessMessage.value = ''

  try {
    await fetchApi(`/users/${user.value?.id}/password`, {
      method: 'PUT',
      body: pwdForm.value,
    })
    pwdSuccessMessage.value = 'パスワードを変更しました。'
    pwdForm.value = { current_password: '', new_password: '', new_password_confirmation: '' }
  } catch (err: any) {
    pwdErrorMessage.value = err.data?.message || 'パスワードの変更に失敗しました。'
  } finally {
    pwdIsLoading.value = false
  }
}

const handleAddUser = async () => {
  if (!userForm.value.username || !userForm.value.display_name || !userForm.value.password) {
    userErrorMessage.value = '必須項目を入力してください。'
    return
  }

  if (userForm.value.password !== userForm.value.password_confirmation) {
    userErrorMessage.value = 'パスワードが一致しません。'
    return
  }

  userIsLoading.value = true
  userErrorMessage.value = ''
  userSuccessMessage.value = ''

  try {
    await fetchApi('/users', {
      method: 'POST',
      body: userForm.value,
    })
    userSuccessMessage.value = `${userForm.value.display_name} さんのアカウントを作成しました。`
    userForm.value = { username: '', display_name: '', email: '', password: '', password_confirmation: '', role: 'child' }
    await fetchMembers()
  } catch (err: any) {
    userErrorMessage.value = err.data?.message || 'アカウントの作成に失敗しました。'
  } finally {
    userIsLoading.value = false
  }
}

onMounted(() => {
  fetchMembers()
  fetchCategories()
})
</script>

<template>
  <div class="space-y-6">
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-2xs space-y-2">
      <h2 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">ログイン情報</h2>
      <div class="flex justify-between items-center pt-1">
        <div>
          <p class="text-base font-bold text-slate-800">{{ user?.display_name }}</p>
          <p class="text-xs text-slate-500">ユーザー名: {{ user?.username }}</p>
        </div>
        <span
          :class="[
            'px-2.5 py-1 text-xs font-semibold rounded-full',
            isParent ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-600',
          ]"
        >
          {{ isParent ? '親（管理者）' : '子供（一般）' }}
        </span>
      </div>
    </div>

    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-2xs space-y-3">
      <h2 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">家族メンバー</h2>
      <div class="divide-y divide-slate-100">
        <div v-for="member in familyMembers" :key="member.id" class="py-2.5 flex justify-between items-center first:pt-0 last:pb-0">
          <div>
            <p class="text-sm font-medium text-slate-700">{{ member.display_name }}</p>
            <p class="text-xs text-slate-400">@{{ member.username }}</p>
          </div>
          <span class="text-xs text-slate-500 bg-slate-100 px-2 py-0.5 rounded-md">
            {{ member.role === 'parent' ? '親' : '子' }}
          </span>
        </div>
      </div>
    </div>

    <!-- カテゴリ管理 -->
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-2xs space-y-3">
      <h2 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">カテゴリ</h2>
      <div class="flex flex-wrap gap-2">
        <span
          v-for="category in categories"
          :key="category.id"
          class="px-2 py-0.5 text-xs font-semibold text-white rounded-full"
          :style="{ backgroundColor: category.color_code }"
        >
          {{ category.name }}
        </span>
      </div>

      <form v-if="isParent" class="flex items-end space-x-2 pt-2 border-t border-slate-100" @submit.prevent="handleAddCategory">
        <div class="flex-1">
          <label class="block text-xs font-semibold text-slate-600 mb-1">新しいカテゴリ名</label>
          <input
            v-model="categoryForm.name"
            type="text"
            required
            placeholder="例: 読書"
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">色</label>
          <input v-model="categoryForm.color_code" type="color" class="h-9 w-12 rounded-lg border border-slate-300">
        </div>
        <button
          type="submit"
          :disabled="categoryIsLoading"
          class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition disabled:opacity-50"
        >
          追加
        </button>
      </form>

      <div v-if="categorySuccessMessage" class="p-3 bg-emerald-50 text-emerald-600 text-xs rounded-lg">
        {{ categorySuccessMessage }}
      </div>
      <div v-if="categoryErrorMessage" class="p-3 bg-red-50 text-red-600 text-xs rounded-lg">
        {{ categoryErrorMessage }}
      </div>
    </div>

    <div v-if="isParent" class="bg-white p-4 rounded-xl border border-blue-200 shadow-2xs space-y-4">
      <div class="flex items-center justify-between border-b border-slate-100 pb-2">
        <h2 class="text-sm font-bold text-slate-800">新しい家族を追加</h2>
        <span class="text-2xs bg-blue-50 text-blue-600 px-2 py-0.5 rounded font-medium">管理者機能</span>
      </div>

      <div v-if="userSuccessMessage" class="p-3 bg-emerald-50 text-emerald-600 text-xs rounded-lg">
        {{ userSuccessMessage }}
      </div>
      <div v-if="userErrorMessage" class="p-3 bg-red-50 text-red-600 text-xs rounded-lg">
        {{ userErrorMessage }}
      </div>

      <form class="space-y-3" @submit.prevent="handleAddUser">
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">表示名（名前）<span class="text-red-500">*</span></label>
          <input
            v-model="userForm.display_name"
            type="text"
            required
            placeholder="例: たろう"
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">ログインID（ユーザー名）<span class="text-red-500">*</span></label>
          <input
            v-model="userForm.username"
            type="text"
            required
            placeholder="例: taro"
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">メールアドレス（任意）</label>
          <input
            v-model="userForm.email"
            type="email"
            placeholder="持っていない場合は空欄でOK"
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">初期パスワード<span class="text-red-500">*</span></label>
          <input
            v-model="userForm.password"
            type="password"
            required
            placeholder="6文字以上"
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">初期パスワード（確認）<span class="text-red-500">*</span></label>
          <input
            v-model="userForm.password_confirmation"
            type="password"
            required
            placeholder="もう一度入力してください"
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">役割（ロール）</label>
          <div class="flex space-x-4 pt-1">
            <label class="flex items-center space-x-2 text-sm text-slate-700 cursor-pointer">
              <input v-model="userForm.role" type="radio" value="child" class="text-blue-600">
              <span>子供（一般）</span>
            </label>
            <label class="flex items-center space-x-2 text-sm text-slate-700 cursor-pointer">
              <input v-model="userForm.role" type="radio" value="parent" class="text-blue-600">
              <span>親（保護者）</span>
            </label>
          </div>
        </div>

        <button
          type="submit"
          :disabled="userIsLoading"
          class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium text-sm rounded-lg transition disabled:opacity-50"
        >
          {{ userIsLoading ? '作成中...' : 'アカウントを作成する' }}
        </button>
      </form>
    </div>

    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-2xs space-y-4">
      <h2 class="text-sm font-bold text-slate-800 border-b border-slate-100 pb-2">パスワードの変更</h2>

      <div v-if="pwdSuccessMessage" class="p-3 bg-emerald-50 text-emerald-600 text-xs rounded-lg">
        {{ pwdSuccessMessage }}
      </div>
      <div v-if="pwdErrorMessage" class="p-3 bg-red-50 text-red-600 text-xs rounded-lg">
        {{ pwdErrorMessage }}
      </div>

      <form class="space-y-3" @submit.prevent="handlePasswordChange">
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">現在のパスワード</label>
          <input
            v-model="pwdForm.current_password"
            type="password"
            required
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">新しいパスワード</label>
          <input
            v-model="pwdForm.new_password"
            type="password"
            required
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">新しいパスワード（確認）</label>
          <input
            v-model="pwdForm.new_password_confirmation"
            type="password"
            required
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
        </div>

        <button
          type="submit"
          :disabled="pwdIsLoading"
          class="w-full py-2.5 bg-slate-800 hover:bg-slate-900 text-white font-medium text-sm rounded-lg transition disabled:opacity-50"
        >
          {{ pwdIsLoading ? '更新中...' : 'パスワードを変更する' }}
        </button>
      </form>
    </div>
  </div>
</template>
