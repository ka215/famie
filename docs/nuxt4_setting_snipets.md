設定・ユーザー管理画面（`/settings`）の Nuxt 4 コンポーネントコードを作成しました。

この画面では、以下の機能をサポートしています：

* **共通機能**: 自身の表示名確認・パスワード変更
* **親（管理者）限定機能**: 新規家族アカウントの作成（メアドなしの子供アカウント対応）

---

## `app/pages/settings.vue`

```vue
<!-- app/pages/settings.vue -->
<script setup lang="ts">
const { user, isParent } = useAuth()
const { fetchApi } = useApi()

// パスワード変更フォームの状態
const pwdForm = ref({
  current_password: '',
  new_password: '',
  new_password_confirmation: '',
})
const pwdIsLoading = ref(false)
const pwdSuccessMessage = ref('')
const pwdErrorMessage = ref('')

// 新規ユーザー追加フォームの状態（親のみ）
const userForm = ref({
  username: '',
  display_name: '',
  email: '',
  password: '',
  role: 'child' as 'parent' | 'child',
})
const userIsLoading = ref(false)
const userSuccessMessage = ref('')
const userErrorMessage = ref('')

// 家族メンバー一覧
const familyMembers = ref<Array<{ id: number; username: string; display_name: string; role: string }>>([])

// 家族メンバー一覧の取得
const fetchMembers = async () => {
  try {
    const res = await fetchApi<Array<{ id: number; username: string; display_name: string; role: string }>>('/users')
    familyMembers.value = res
  } catch (err) {
    console.error('メンバー一覧の取得に失敗しました', err)
  }
}

// パスワード変更の送信
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

// ユーザー新規追加の送信（親のみ）
const handleAddUser = async () => {
  if (!userForm.value.username || !userForm.value.display_name || !userForm.value.password) {
    userErrorMessage.value = '必須項目を入力してください。'
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
    userForm.value = { username: '', display_name: '', email: '', password: '', role: 'child' }
    await fetchMembers()
  } catch (err: any) {
    userErrorMessage.value = err.data?.message || 'アカウントの作成に失敗しました。'
  } finally {
    userIsLoading.value = false
  }
}

onMounted(() => {
  fetchMembers()
})
</script>

<template>
  <div class="space-y-6">
    <!-- ユーザープロフィール情報 -->
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
            isParent ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-600'
          ]"
        >
          {{ isParent ? '親（管理者）' : '子供（一般）' }}
        </span>
      </div>
    </div>

    <!-- 家族メンバー一覧 -->
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

    <!-- 家族アカウント作成フォーム (親アカウントのみ表示) -->
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

      <form @submit.prevent="handleAddUser" class="space-y-3">
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">表示名（名前）<span class="text-red-500">*</span></label>
          <input
            v-model="userForm.display_name"
            type="text"
            required
            placeholder="例: たろう"
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">ログインID（ユーザー名）<span class="text-red-500">*</span></label>
          <input
            v-model="userForm.username"
            type="text"
            required
            placeholder="例: taro"
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">メールアドレス（任意）</label>
          <input
            v-model="userForm.email"
            type="email"
            placeholder="持っていない場合は空欄でOK"
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">初期パスワード<span class="text-red-500">*</span></label>
          <input
            v-model="userForm.password"
            type="password"
            required
            placeholder="6文字以上"
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">役割（ロール）</label>
          <div class="flex space-x-4 pt-1">
            <label class="flex items-center space-x-2 text-sm text-slate-700 cursor-pointer">
              <input type="radio" v-model="userForm.role" value="child" class="text-blue-600" />
              <span>子供（一般）</span>
            </label>
            <label class="flex items-center space-x-2 text-sm text-slate-700 cursor-pointer">
              <input type="radio" v-model="userForm.role" value="parent" class="text-blue-600" />
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

    <!-- パスワード変更フォーム -->
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-2xs space-y-4">
      <h2 class="text-sm font-bold text-slate-800 border-b border-slate-100 pb-2">パスワードの変更</h2>

      <div v-if="pwdSuccessMessage" class="p-3 bg-emerald-50 text-emerald-600 text-xs rounded-lg">
        {{ pwdSuccessMessage }}
      </div>
      <div v-if="pwdErrorMessage" class="p-3 bg-red-50 text-red-600 text-xs rounded-lg">
        {{ pwdErrorMessage }}
      </div>

      <form @submit.prevent="handlePasswordChange" class="space-y-3">
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">現在のパスワード</label>
          <input
            v-model="pwdForm.current_password"
            type="password"
            required
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">新しいパスワード</label>
          <input
            v-model="pwdForm.new_password"
            type="password"
            required
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">新しいパスワード（確認）</label>
          <input
            v-model="pwdForm.new_password_confirmation"
            type="password"
            required
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
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

```

---