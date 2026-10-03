<script setup lang="ts">
import type {
  ApiRequestError,
  Category,
  CurrentUserResponse,
  DataResponse,
  FamilyMember,
  Group,
} from '#shared/types/api'
import type { CreateUserForm, PasswordForm } from '#shared/types/forms'
import { categoryStyle } from '#shared/utils/categoryColor'
import { type ProfileForm, profileSchema } from '#shared/utils/profileSchema'

const { user, membership, groupId, isParent, logout } = useAuth()
const groupPath = (path = '') => `/groups/${groupId.value}${path}`
const { public: publicConfig } = useRuntimeConfig()
const { fetchApi } = useApi()
const { theme, setTheme, storageError } = useTheme()
const badgeStyle = categoryStyle

const profileForm = ref<ProfileForm>({ display_name: user.value?.display_name ?? '' })
const profileIsLoading = ref(false)
const profileSuccessMessage = ref('')
const profileErrorMessage = ref('')
const savedDisplayName = ref(user.value?.display_name ?? '')
const parsedProfile = computed(() => profileSchema.safeParse(profileForm.value))
const profileValidationMessage = computed(() => {
  if (profileForm.value.display_name === savedDisplayName.value || parsedProfile.value.success) {
    return ''
  }
  return parsedProfile.value.error.issues[0]?.message ?? '表示名を確認してください。'
})
const displayedProfileError = computed(
  () => profileErrorMessage.value || profileValidationMessage.value
)
const canSaveProfile = computed(
  () =>
    !profileIsLoading.value &&
    parsedProfile.value.success &&
    parsedProfile.value.data.display_name !== savedDisplayName.value
)

watch(
  () => profileForm.value.display_name,
  (displayName) => {
    if (displayName === savedDisplayName.value) return
    profileErrorMessage.value = ''
    profileSuccessMessage.value = ''
  }
)

const handleProfileSave = async () => {
  if (!canSaveProfile.value) return
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
    membership.value = response.membership
    profileForm.value.display_name = response.user.display_name
    savedDisplayName.value = response.user.display_name
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
  password: '',
  password_confirmation: '',
  role: 'member',
})
const userIsLoading = ref(false)
const userSuccessMessage = ref('')
const userErrorMessage = ref('')

const familyMembers = ref<FamilyMember[]>([])
const inactiveMembers = ref<FamilyMember[]>([])
const inactiveLoaded = ref(false)
const inactiveLoading = ref(false)
const memberMessage = ref('')
const memberError = ref('')
const selectedMember = ref<FamilyMember | null>(null)
const restoringMember = ref(false)
const selectedCategory = ref<Category | null>(null)
const groupName = ref(membership.value?.group.name ?? '')
const groupNameIsLoading = ref(false)
const groupNameMessage = ref('')

// カテゴリ管理（親のみ）
const categories = ref<Category[]>([])
const categoryForm = ref({ name: '', color_code: '#3B82F6' })
const categoryIsLoading = ref(false)
const categorySuccessMessage = ref('')
const categoryErrorMessage = ref('')

const fetchCategories = async () => {
  try {
    categories.value = await fetchApi<Category[]>(groupPath('/categories'))
  } catch (err) {
    categoryErrorMessage.value = 'カテゴリ一覧を取得できませんでした。再読み込みしてください。'
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
    await fetchApi(groupPath('/categories'), {
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
    familyMembers.value = await fetchApi<FamilyMember[]>(groupPath('/members'))
  } catch (err) {
    memberError.value = 'メンバー一覧を取得できませんでした。再読み込みしてください。'
  }
}

const fetchInactiveMembers = async () => {
  if (!isParent.value || inactiveLoading.value) return
  inactiveLoading.value = true
  try {
    inactiveMembers.value = await fetchApi<FamilyMember[]>(groupPath('/members/inactive'))
    inactiveLoaded.value = true
  } catch (cause: unknown) {
    memberError.value =
      (cause as ApiRequestError).data?.message ??
      '復元対象を取得できませんでした。もう一度お試しください。'
  } finally {
    inactiveLoading.value = false
  }
}

const openMember = (member: FamilyMember, inactive = false) => {
  selectedMember.value = member
  restoringMember.value = inactive
  memberMessage.value = ''
  memberError.value = ''
}

const memberSaved = async (member: FamilyMember) => {
  selectedMember.value = null
  memberMessage.value = 'メンバー情報を更新しました。'
  if (user.value?.id === member.id) {
    user.value.display_name = member.display_name
    profileForm.value.display_name = member.display_name
    savedDisplayName.value = member.display_name
  }
  await fetchMembers()
  if (inactiveLoaded.value) await fetchInactiveMembers()
}

const categorySaved = async () => {
  selectedCategory.value = null
  categorySuccessMessage.value = 'カテゴリ情報を更新しました。'
  categoryErrorMessage.value = ''
  await fetchCategories()
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
    await fetchApi('/auth/me/password', {
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
    await fetchApi(groupPath('/members'), {
      method: 'POST',
      body: userForm.value,
    })
    userSuccessMessage.value = `${userForm.value.display_name} さんのアカウントを作成しました。`
    userForm.value = {
      username: '',
      display_name: '',
      password: '',
      password_confirmation: '',
      role: 'member',
    }
    await fetchMembers()
  } catch (err: unknown) {
    const e = err as ApiRequestError
    userErrorMessage.value = e.data?.message || 'アカウントの作成に失敗しました。'
  } finally {
    userIsLoading.value = false
  }
}

const handleGroupNameSave = async () => {
  if (!groupName.value.trim() || !isParent.value) return
  groupNameIsLoading.value = true
  groupNameMessage.value = ''
  try {
    const response = await fetchApi<DataResponse<Group>>(groupPath(), {
      method: 'PATCH',
      body: { name: groupName.value },
    })
    groupName.value = response.data.name
    if (membership.value) membership.value.group.name = response.data.name
    groupNameMessage.value = '家族名を変更しました。'
  } catch (error: unknown) {
    const apiError = error as ApiRequestError
    groupNameMessage.value = apiError.data?.message || '家族名を変更できませんでした。'
  } finally {
    groupNameIsLoading.value = false
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
          <p class="text-base font-bold text-slate-800 dark:text-slate-100">{{ user?.display_name }}</p>
          <p class="text-xs text-slate-500 dark:text-slate-400">ユーザー名: {{ user?.username }}</p>
        </div>
        <span
          :class="[
            'px-2.5 py-1 text-xs font-semibold rounded-full',
            isParent ? 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300' : 'bg-slate-100 dark:bg-slate-900 text-slate-600 dark:text-slate-300',
          ]"
        >
          {{ isParent ? '親（管理者）' : '子供（一般）' }}
        </span>
      </div>
      <form class="space-y-3 pt-3" novalidate @submit.prevent="handleProfileSave">
        <div>
          <label for="display-name" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">自分の表示名</label>
          <div class="flex w-full">
            <input
              id="display-name"
              v-model="profileForm.display_name"
              type="text"
              autocomplete="nickname"
              :aria-invalid="!!displayedProfileError"
              aria-describedby="display-name-help display-name-error"
              class="min-w-0 flex-1 rounded-l-lg border border-r-0 border-slate-300 dark:border-slate-600 px-3 py-2 text-sm focus:relative focus:z-10 focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
            <button
              type="submit"
              :disabled="!canSaveProfile"
              class="shrink-0 rounded-r-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {{ profileIsLoading ? '変更中…' : '変更' }}
            </button>
          </div>
          <p id="display-name-help" class="mt-1 text-xs text-slate-500 dark:text-slate-400">50文字以内。前後の空白は取り除きます。</p>
        </div>
        <p id="display-name-error" role="alert" class="text-xs text-red-600 dark:text-red-300">{{ displayedProfileError }}</p>
        <p v-if="profileSuccessMessage" role="status" class="text-xs text-emerald-600 dark:text-emerald-300">{{ profileSuccessMessage }}</p>
      </form>
      <div class="flex justify-end pt-3 border-t border-slate-100 dark:border-slate-700">
        <button type="button" class="px-4 py-2 text-sm font-medium text-red-600 dark:text-red-300 border border-red-200 dark:border-red-800 rounded-lg hover:bg-red-50 dark:hover:bg-red-950" @click="logout">
          ログアウト
        </button>
      </div>
    </SettingsCard>

    <SettingsCard title="家族設定">
      <form class="space-y-2" @submit.prevent="handleGroupNameSave">
        <label for="group-name" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">家族名</label>
        <div class="flex">
          <input id="group-name" v-model="groupName" maxlength="50" :disabled="!isParent" class="min-w-0 flex-1 rounded-l-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 dark:border-slate-600 dark:disabled:bg-slate-900">
          <button v-if="isParent" type="submit" :disabled="groupNameIsLoading" class="rounded-r-lg bg-blue-600 px-4 py-2 text-sm text-white disabled:opacity-50">変更</button>
        </div>
        <p v-if="groupNameMessage" role="status" class="text-xs text-slate-500 dark:text-slate-400">{{ groupNameMessage }}</p>
      </form>
    </SettingsCard>

    <SettingsCard title="家族メンバー">
      <div class="divide-y divide-slate-100 dark:divide-slate-700">
        <div v-for="member in familyMembers" :key="member.id" class="py-2.5 flex justify-between items-center first:pt-0 last:pb-0">
          <div class="min-w-0 break-all">
            <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ member.display_name }}</p>
            <p class="text-xs text-slate-400 dark:text-slate-400">@{{ member.username }}</p>
          </div>
          <div class="flex shrink-0 items-center gap-2">
          <span class="text-xs text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-900 px-2 py-0.5 rounded-md">
            {{ member.role === 'admin' ? '親' : '子' }}
          </span>
          <button v-if="isParent" type="button" :aria-label="`${member.display_name}を管理`" class="min-h-11 px-3 text-sm rounded-lg border border-slate-300 dark:border-slate-600" @click="openMember(member)">管理</button>
          </div>
        </div>
      </div>
      <div v-if="isParent" class="space-y-3 border-t border-slate-200 dark:border-slate-700 pt-3">
        <button type="button" :disabled="inactiveLoading" class="min-h-11 text-sm text-blue-600 dark:text-blue-300 disabled:opacity-50" @click="memberError = ''; fetchInactiveMembers()">無効化済みメンバーを復元</button>
        <p v-if="inactiveLoaded && !inactiveMembers.length" class="text-sm text-slate-600 dark:text-slate-300">無効化済みメンバーはいません。</p>
        <div v-for="member in inactiveMembers" :key="member.membership_id" class="flex items-center justify-between gap-3">
          <div class="min-w-0 text-sm break-all">{{ member.display_name }}（@{{ member.username }}）<span class="ml-2">{{ member.role === 'admin' ? '親' : '子' }}</span></div>
          <button type="button" :aria-label="`${member.display_name}を復元`" class="min-h-11 shrink-0 px-3 text-sm rounded-lg border border-slate-300 dark:border-slate-600" @click="openMember(member, true)">復元</button>
        </div>
      </div>
      <p v-if="memberMessage" role="status" class="text-sm text-emerald-600 dark:text-emerald-300">{{ memberMessage }}</p>
      <p v-if="memberError" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ memberError }}</p>
    </SettingsCard>

    <SettingsCard title="カテゴリ">
      <div class="flex flex-wrap gap-2">
        <component
          v-for="category in categories"
          :is="isParent ? 'button' : 'span'"
          :key="category.id"
          :type="isParent ? 'button' : undefined"
          :aria-label="isParent ? `${category.name}を管理` : undefined"
          class="px-2 py-0.5 text-xs font-semibold text-white rounded-full"
          :class="isParent ? 'min-h-11' : ''"
          :style="badgeStyle(category.color_code)"
          @click="isParent && (selectedCategory = category)"
        >
          {{ category.name }}
        </component>
      </div>

      <form v-if="isParent" class="flex items-end space-x-2 pt-2 border-t border-slate-100 dark:border-slate-700" @submit.prevent="handleAddCategory">
        <div class="min-w-0 flex-1">
          <label for="category-name" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">新しいカテゴリ名</label>
          <input
            id="category-name"
            v-model="categoryForm.name"
            type="text"
            required
            placeholder="例: 読書"
            class="h-11 w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
        </div>
        <div>
          <label for="category-color" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">色</label>
          <input id="category-color" v-model="categoryForm.color_code" type="color" class="block h-11 w-12 rounded-lg border border-slate-300 dark:border-slate-600">
        </div>
        <button
          type="submit"
          :disabled="categoryIsLoading"
          class="h-11 shrink-0 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition disabled:opacity-50"
        >
          追加
        </button>
      </form>

      <div v-if="categorySuccessMessage" role="status" class="p-3 bg-emerald-50 dark:bg-emerald-950 text-emerald-600 dark:text-emerald-300 text-xs rounded-lg">
        {{ categorySuccessMessage }}
      </div>
      <div v-if="categoryErrorMessage" role="alert" class="p-3 bg-red-50 dark:bg-red-950 text-red-600 dark:text-red-300 text-xs rounded-lg">
        {{ categoryErrorMessage }}
      </div>
    </SettingsCard>

    <SettingsCard v-if="isParent" title="新しい家族メンバーを追加" badge="管理者機能" class="border-blue-200 dark:border-blue-800">

      <div v-if="userSuccessMessage" class="p-3 bg-emerald-50 dark:bg-emerald-950 text-emerald-600 dark:text-emerald-300 text-xs rounded-lg">
        {{ userSuccessMessage }}
      </div>
      <div v-if="userErrorMessage" class="p-3 bg-red-50 dark:bg-red-950 text-red-600 dark:text-red-300 text-xs rounded-lg">
        {{ userErrorMessage }}
      </div>

      <form class="space-y-3" @submit.prevent="handleAddUser">
        <div>
          <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">表示名（名前）<span class="text-red-500 dark:text-red-400">*</span></label>
          <input
            v-model="userForm.display_name"
            type="text"
            required
            placeholder="例: たろう"
            class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">ログインID（ユーザー名）<span class="text-red-500 dark:text-red-400">*</span></label>
          <input
            v-model="userForm.username"
            type="text"
            required
            placeholder="例: taro"
            class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
        </div>

        <div>
          <label for="initial-password" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">初期パスワード<span aria-hidden="true" class="text-red-500 dark:text-red-400">*</span></label>
          <PasswordInput
            id="initial-password"
            label="初期パスワード"
            v-model="userForm.password"
            autocomplete="new-password"
            required
            placeholder="6文字以上"
            class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label for="initial-password-confirmation" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">初期パスワード（確認）<span aria-hidden="true" class="text-red-500 dark:text-red-400">*</span></label>
          <PasswordInput
            id="initial-password-confirmation"
            label="初期パスワード（確認）"
            v-model="userForm.password_confirmation"
            autocomplete="new-password"
            required
            placeholder="もう一度入力してください"
            class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">役割（ロール）</label>
          <div class="flex space-x-4 pt-1">
            <label class="flex items-center space-x-2 text-sm text-slate-700 dark:text-slate-200 cursor-pointer">
              <input v-model="userForm.role" type="radio" value="member" class="text-blue-600 dark:text-blue-300">
              <span>子供（一般）</span>
            </label>
            <label class="flex items-center space-x-2 text-sm text-slate-700 dark:text-slate-200 cursor-pointer">
              <input v-model="userForm.role" type="radio" value="admin" class="text-blue-600 dark:text-blue-300">
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

      <div v-if="pwdSuccessMessage" class="p-3 bg-emerald-50 dark:bg-emerald-950 text-emerald-600 dark:text-emerald-300 text-xs rounded-lg">
        {{ pwdSuccessMessage }}
      </div>
      <div v-if="pwdErrorMessage" class="p-3 bg-red-50 dark:bg-red-950 text-red-600 dark:text-red-300 text-xs rounded-lg">
        {{ pwdErrorMessage }}
      </div>

      <form class="space-y-3" @submit.prevent="handlePasswordChange">
        <div>
          <label for="current-password" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">現在のパスワード</label>
          <PasswordInput
            id="current-password"
            label="現在のパスワード"
            v-model="pwdForm.current_password"
            autocomplete="current-password"
            required
            class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label for="new-password" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">新しいパスワード</label>
          <PasswordInput
            id="new-password"
            label="新しいパスワード"
            v-model="pwdForm.new_password"
            autocomplete="new-password"
            required
            class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label for="new-password-confirmation" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">新しいパスワード（確認）</label>
          <PasswordInput
            id="new-password-confirmation"
            label="新しいパスワード（確認）"
            v-model="pwdForm.new_password_confirmation"
            autocomplete="new-password"
            required
            class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <button
          type="submit"
          :disabled="pwdIsLoading"
          class="w-full py-2.5 bg-slate-800 dark:bg-slate-700 hover:bg-slate-900 dark:hover:bg-slate-600 text-white font-medium text-sm rounded-lg transition disabled:opacity-50"
        >
          {{ pwdIsLoading ? '更新中...' : 'パスワードを変更する' }}
        </button>
      </form>
    </SettingsCard>
    <SettingsCard title="表示設定">
      <fieldset class="space-y-3">
        <legend class="text-sm font-semibold">表示モード</legend>
        <div class="flex gap-4">
          <label class="flex min-h-11 items-center gap-2 text-sm"><input type="radio" name="theme" value="light" :checked="theme === 'light'" @change="setTheme('light')">ライト</label>
          <label class="flex min-h-11 items-center gap-2 text-sm"><input type="radio" name="theme" value="dark" :checked="theme === 'dark'" @change="setTheme('dark')">ダーク</label>
        </div>
        <p class="text-xs text-slate-600 dark:text-slate-300">この端末のブラウザ・アプリに保存します。端末の表示設定が変わっても、選んだモードを維持します。</p>
        <p v-if="storageError" role="alert" class="text-sm text-red-600 dark:text-red-300">表示設定を保存できませんでした。現在の画面には反映していますが、再起動後は保持されない場合があります。</p>
      </fieldset>
    </SettingsCard>
    <SettingsCard title="アプリについて">
      <dl class="text-sm text-slate-600 dark:text-slate-300">
        <div class="flex justify-between gap-4"><dt>バージョン</dt><dd>{{ publicConfig.appVersion }}</dd></div>
      </dl>
      <p class="text-center text-xs text-slate-500 dark:text-slate-400">© MAGIC METHODS</p>
    </SettingsCard>
    <MemberManageDialog v-if="selectedMember && isParent" :member="selectedMember" :inactive="restoringMember" @close="selectedMember = null" @saved="memberSaved" />
    <CategoryManageDialog v-if="selectedCategory && isParent" :category="selectedCategory" @close="selectedCategory = null" @saved="categorySaved" />
  </div>
</template>
