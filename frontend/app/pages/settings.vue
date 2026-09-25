<script setup lang="ts">
import type { ApiRequestError, Category, CurrentUserResponse, User } from '#shared/types/api'
import type { CreateUserForm, PasswordForm } from '#shared/types/forms'
import { type ProfileForm, profileSchema } from '#shared/utils/profileSchema'

const { user, isParent } = useAuth()
const { public: publicConfig } = useRuntimeConfig()
const { fetchApi } = useApi()

const profileForm = ref<ProfileForm>({ display_name: user.value?.display_name ?? '' })
const profileIsLoading = ref(false)
const profileSuccessMessage = ref('')
const profileErrorMessage = ref('')

const handleProfileSave = async () => {
  if (profileIsLoading.value) return
  profileSuccessMessage.value = ''
  profileErrorMessage.value = ''
  const result = profileSchema.safeParse(profileForm.value)
  if (!result.success) {
    profileErrorMessage.value = result.error.issues[0]?.message ?? '表示名を確認してください。'
    return
  }

  profileIsLoading.value = true
  try {
    const response = await fetchApi<CurrentUserResponse>('/auth/me', {
      method: 'PATCH',
      body: result.data,
    })
    user.value = response.user
    profileForm.value.display_name = response.user.display_name
    familyMembers.value = familyMembers.value.map((member) =>
      member.id === response.user.id
        ? { ...member, display_name: response.user.display_name }
        : member
    )
    profileSuccessMessage.value = '表示名を変更しました。'
  } catch (error: unknown) {
    const response = error as ApiRequestError
    profileErrorMessage.value =
      response.data?.errors?.display_name?.[0] ??
      response.data?.message ??
      '表示名を変更できませんでした。通信環境を確認してください。'
  } finally {
    profileIsLoading.value = false
  }
}

const pwdForm = ref<PasswordForm>({
  current_password: '',
  new_password: '',
  new_password_confirmation: '',
})
const pwdIsLoading = ref(false)
const pwdSuccessMessage = ref('')
const pwdErrorMessage = ref('')

const userForm = ref<CreateUserForm>({
  username: '',
  display_name: '',
  email: '',
  password: '',
  password_confirmation: '',
  role: 'child',
})
const userIsLoading = ref(false)
const userSuccessMessage = ref('')
const userErrorMessage = ref('')

const familyMembers = ref<User[]>([])

// カテゴリ管理（親のみ）
const categories = ref<Category[]>([])
const categoryForm = ref({ name: '', color_code: '#3B82F6' })
const categoryIsLoading = ref(false)
const categorySuccessMessage = ref('')
const categoryErrorMessage = ref('')

const fetchCategories = async () => {
  try {
    categories.value = await fetchApi<Category[]>('/categories')
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
  } catch (err: unknown) {
    const e = err as ApiRequestError
    categoryErrorMessage.value = e.data?.message || 'カテゴリの追加に失敗しました。'
  } finally {
    categoryIsLoading.value = false
  }
}

const fetchMembers = async () => {
  try {
    familyMembers.value = await fetchApi<User[]>('/users')
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
    pwdForm.value = {
      current_password: '',
      new_password: '',
      new_password_confirmation: '',
    }
  } catch (err: unknown) {
    const e = err as ApiRequestError
    pwdErrorMessage.value = e.data?.message || 'パスワードの変更に失敗しました。'
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
    userForm.value = {
      username: '',
      display_name: '',
      email: '',
      password: '',
      password_confirmation: '',
      role: 'child',
    }
    await fetchMembers()
  } catch (err: unknown) {
    const e = err as ApiRequestError
    userErrorMessage.value = e.data?.message || 'アカウントの作成に失敗しました。'
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
    <SettingsCard title="ログイン情報" :initial-open="true">
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
      <form class="space-y-3 pt-3" novalidate @submit.prevent="handleProfileSave">
        <div>
          <label for="display-name" class="block text-xs font-semibold text-slate-600 mb-1">自分の表示名</label>
          <input
            id="display-name"
            v-model="profileForm.display_name"
            type="text"
            autocomplete="nickname"
            :aria-invalid="!!profileErrorMessage"
            aria-describedby="display-name-help display-name-error"
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
          <p id="display-name-help" class="mt-1 text-xs text-slate-500">50文字以内。前後の空白は取り除きます。</p>
        </div>
        <p id="display-name-error" role="alert" class="text-xs text-red-600">{{ profileErrorMessage }}</p>
        <p v-if="profileSuccessMessage" role="status" class="text-xs text-emerald-600">{{ profileSuccessMessage }}</p>
        <button type="submit" :disabled="profileIsLoading" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg disabled:opacity-50">
          {{ profileIsLoading ? '保存中…' : '表示名を保存する' }}
        </button>
      </form>
    </SettingsCard>

    <SettingsCard title="家族メンバー">
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
    </SettingsCard>

    <SettingsCard title="カテゴリ">
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
        <div class="min-w-0 flex-1">
          <label for="category-name" class="block text-xs font-semibold text-slate-600 mb-1">新しいカテゴリ名</label>
          <input
            id="category-name"
            v-model="categoryForm.name"
            type="text"
            required
            placeholder="例: 読書"
            class="h-11 w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
        </div>
        <div>
          <label for="category-color" class="block text-xs font-semibold text-slate-600 mb-1">色</label>
          <input id="category-color" v-model="categoryForm.color_code" type="color" class="block h-11 w-12 rounded-lg border border-slate-300">
        </div>
        <button
          type="submit"
          :disabled="categoryIsLoading"
          class="h-11 shrink-0 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition disabled:opacity-50"
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
    </SettingsCard>

    <SettingsCard v-if="isParent" title="新しい家族を追加" badge="管理者機能" class="border-blue-200">

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
          <label for="initial-password" class="block text-xs font-semibold text-slate-600 mb-1">初期パスワード<span aria-hidden="true" class="text-red-500">*</span></label>
          <PasswordInput
            id="initial-password"
            label="初期パスワード"
            v-model="userForm.password"
            autocomplete="new-password"
            required
            placeholder="6文字以上"
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label for="initial-password-confirmation" class="block text-xs font-semibold text-slate-600 mb-1">初期パスワード（確認）<span aria-hidden="true" class="text-red-500">*</span></label>
          <PasswordInput
            id="initial-password-confirmation"
            label="初期パスワード（確認）"
            v-model="userForm.password_confirmation"
            autocomplete="new-password"
            required
            placeholder="もう一度入力してください"
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
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
    </SettingsCard>

    <SettingsCard title="パスワードの変更">

      <div v-if="pwdSuccessMessage" class="p-3 bg-emerald-50 text-emerald-600 text-xs rounded-lg">
        {{ pwdSuccessMessage }}
      </div>
      <div v-if="pwdErrorMessage" class="p-3 bg-red-50 text-red-600 text-xs rounded-lg">
        {{ pwdErrorMessage }}
      </div>

      <form class="space-y-3" @submit.prevent="handlePasswordChange">
        <div>
          <label for="current-password" class="block text-xs font-semibold text-slate-600 mb-1">現在のパスワード</label>
          <PasswordInput
            id="current-password"
            label="現在のパスワード"
            v-model="pwdForm.current_password"
            autocomplete="current-password"
            required
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label for="new-password" class="block text-xs font-semibold text-slate-600 mb-1">新しいパスワード</label>
          <PasswordInput
            id="new-password"
            label="新しいパスワード"
            v-model="pwdForm.new_password"
            autocomplete="new-password"
            required
            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label for="new-password-confirmation" class="block text-xs font-semibold text-slate-600 mb-1">新しいパスワード（確認）</label>
          <PasswordInput
            id="new-password-confirmation"
            label="新しいパスワード（確認）"
            v-model="pwdForm.new_password_confirmation"
            autocomplete="new-password"
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
    </SettingsCard>
    <SettingsCard title="アプリについて">
      <dl class="text-sm text-slate-600">
        <div class="flex justify-between gap-4"><dt>バージョン</dt><dd>{{ publicConfig.appVersion }}</dd></div>
      </dl>
      <p class="text-center text-xs text-slate-500">© MAGIC METHODS</p>
    </SettingsCard>
  </div>
</template>
