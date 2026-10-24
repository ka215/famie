<script setup lang="ts">
const { t, locale } = useI18n()

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
import type { UiLocalePreference } from '#shared/utils/locale'
import { createProfileSchema, type ProfileForm } from '#shared/utils/profileSchema'

const { user, membership, groupId, isParent, logout, token } = useAuth()
const securityAlerts = useSecurityAlerts()
const route = useRoute()
const groupPath = (path = '') => `/groups/${groupId.value}${path}`
const { public: publicConfig } = useRuntimeConfig()
const { fetchApi } = useApi()
const { theme, setTheme, storageError } = useTheme()
const { choices: localeChoices, effective, intlLocale, automatic } = useUiLocale()
const localeSelection = ref<UiLocalePreference>(user.value?.ui_locale ?? null)
const localeIsLoading = ref(false)
const localeMessage = ref('')
const localeError = ref('')
const savedLocale = computed(() => user.value?.ui_locale ?? null)
watch(savedLocale, (value) => {
  localeSelection.value = value
})
const saveLocale = async () => {
  if (localeIsLoading.value || profileIsLoading.value || suffixIsLoading.value) return
  localeIsLoading.value = true
  localeMessage.value = ''
  localeError.value = ''
  const requestToken = token.value
  try {
    const response = await fetchApi<CurrentUserResponse>('/auth/me', {
      method: 'PATCH',
      body: { ui_locale: localeSelection.value },
    })
    if (requestToken !== token.value) return
    user.value = response.user
    membership.value = response.membership
    await nextTick()
    localeMessage.value = t('locale.saved')
  } catch {
    if (requestToken !== token.value) return
    localeSelection.value = savedLocale.value
    localeError.value = t('locale.failed')
  } finally {
    localeIsLoading.value = false
  }
}
const badgeStyle = categoryStyle

const profileForm = ref<ProfileForm>({ display_name: user.value?.display_name ?? '' })
const profileIsLoading = ref(false)
const suffixIsLoading = ref(false)
const suffixError = ref('')
const suffixSuccess = ref('')
const saveSuffix = async (value: boolean) => {
  if (suffixIsLoading.value || profileIsLoading.value || localeIsLoading.value) return
  suffixIsLoading.value = true
  suffixError.value = ''
  suffixSuccess.value = ''
  const requestToken = token.value
  try {
    const response = await fetchApi<CurrentUserResponse>('/auth/me', {
      method: 'PATCH',
      body: { show_name_suffix: value },
    })
    if (requestToken !== token.value) return
    user.value = response.user
    membership.value = response.membership
    suffixSuccess.value = t('settings.suffix.saved')
  } catch {
    if (requestToken !== token.value) return
    suffixError.value = t('settings.suffix.failed')
  } finally {
    suffixIsLoading.value = false
  }
}
const profileSuccessMessage = ref('')
const profileErrorMessage = ref('')
const savedDisplayName = ref(user.value?.display_name ?? '')
const parsedProfile = computed(() => createProfileSchema(t).safeParse(profileForm.value))
const profileValidationMessage = computed(() => {
  if (profileForm.value.display_name === savedDisplayName.value || parsedProfile.value.success) {
    return ''
  }
  return parsedProfile.value.error.issues[0]?.message ?? t('validation.checkDisplayName')
})
const displayedProfileError = computed(
  () => profileErrorMessage.value || profileValidationMessage.value
)
const canSaveProfile = computed(
  () =>
    !profileIsLoading.value &&
    !localeIsLoading.value &&
    !suffixIsLoading.value &&
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
  const result = createProfileSchema(t).safeParse(profileForm.value)
  if (!result.success) {
    profileErrorMessage.value = result.error.issues[0]?.message ?? t('validation.checkDisplayName')
    return
  }

  profileIsLoading.value = true
  const requestToken = token.value
  try {
    const response = await fetchApi<CurrentUserResponse>('/auth/me', {
      method: 'PATCH',
      body: result.data,
    })
    if (requestToken !== token.value) return
    user.value = response.user
    membership.value = response.membership
    profileForm.value.display_name = response.user.display_name
    savedDisplayName.value = response.user.display_name
    familyMembers.value = familyMembers.value.map((member) =>
      member.id === response.user.id
        ? { ...member, display_name: response.user.display_name }
        : member
    )
    profileSuccessMessage.value = t('settings.profile.saved')
  } catch (error: unknown) {
    if (requestToken !== token.value) return
    const response = error as ApiRequestError
    profileErrorMessage.value =
      response.data?.errors?.display_name?.[0] ??
      response.data?.message ??
      t('settings.profile.failed')
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
const pwdErrorMessage = useUiMessage()

const userForm = ref<CreateUserForm>({
  username: '',
  display_name: '',
  password: '',
  password_confirmation: '',
  role: 'member',
})
const userIsLoading = ref(false)
const userSuccessMessage = ref('')
const userErrorMessage = useUiMessage()

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
const imageSettings = ref<Group['images']>()
const storageUsedBytes = computed(() => Math.max(0, imageSettings.value?.used_bytes ?? 0))
const storageQuotaBytes = computed(() => Math.max(0, imageSettings.value?.quota_bytes ?? 0))
const storageRemainingBytes = computed(() =>
  Math.max(0, storageQuotaBytes.value - storageUsedBytes.value)
)
const storageUsagePercent = computed(() =>
  storageQuotaBytes.value > 0
    ? Math.min(100, Math.round((storageUsedBytes.value / storageQuotaBytes.value) * 100))
    : 0
)
const formatStorageSize = (bytes: number) => {
  const { size, unit } = [
    { size: 1_000_000_000, unit: 'GB' },
    { size: 1_000_000, unit: 'MB' },
    { size: 1_000, unit: 'KB' },
  ].find(({ size }) => bytes >= size) ?? { size: 1, unit: 'B' }
  return `${(bytes / size).toLocaleString(intlLocale.value, { maximumFractionDigits: size === 1 ? 0 : 1 })} ${unit}`
}
const groupNameMessage = ref('')

// カテゴリ管理（親のみ）
const categories = ref<Category[]>([])
const categoryForm = ref({ name: '', color_code: '#3B82F6' })
const categoryIsLoading = ref(false)
const categorySuccessMessage = ref('')
const categoryErrorMessage = useUiMessage()

const fetchCategories = async () => {
  try {
    categories.value = await fetchApi<Category[]>(groupPath('/categories'))
  } catch (err) {
    categoryErrorMessage.setKey('settings.category.loadFailed')
  }
}

const handleAddCategory = async () => {
  if (!categoryForm.value.name) {
    categoryErrorMessage.setKey('validation.categoryRequired')
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
    categorySuccessMessage.value = t('settings.category.added', { value1: categoryForm.value.name })
    categoryForm.value = { name: '', color_code: '#3B82F6' }
    await fetchCategories()
  } catch (err: unknown) {
    const e = err as ApiRequestError
    categoryErrorMessage.value = e.data?.message || t('settings.category.addFailed')
  } finally {
    categoryIsLoading.value = false
  }
}

const fetchMembers = async () => {
  try {
    familyMembers.value = await fetchApi<FamilyMember[]>(groupPath('/members'))
  } catch (err) {
    memberError.value = t('settings.members.loadFailed')
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
      (cause as ApiRequestError).data?.message ?? t('settings.members.inactiveFailed')
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
  memberMessage.value = t('settings.members.saved')
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
  categorySuccessMessage.value = t('settings.category.saved')
  categoryErrorMessage.value = ''
  await fetchCategories()
}

const handlePasswordChange = async () => {
  if (pwdForm.value.new_password !== pwdForm.value.new_password_confirmation) {
    pwdErrorMessage.setKey('validation.newPasswordMismatch')
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
    pwdSuccessMessage.value = t('settings.password.saved')
    if (user.value) {
      user.value.password_security = { status: 'ok', snoozed_until: null }
      user.value.pending_email = null
      user.value.email_verification_expires_at = null
    }
    pwdForm.value = {
      current_password: '',
      new_password: '',
      new_password_confirmation: '',
    }
  } catch (err: unknown) {
    const e = err as ApiRequestError
    pwdErrorMessage.value = e.data?.message || t('settings.password.failed')
  } finally {
    pwdIsLoading.value = false
  }
}

const handleAddUser = async () => {
  if (!userForm.value.username || !userForm.value.display_name || !userForm.value.password) {
    userErrorMessage.setKey('validation.requiredFields')
    return
  }

  if (userForm.value.password !== userForm.value.password_confirmation) {
    userErrorMessage.setKey('validation.passwordMismatch')
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
    userSuccessMessage.value = t('settings.members.created', {
      value1: userForm.value.display_name,
    })
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
    userErrorMessage.value = e.data?.message || t('settings.members.createFailed')
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
    groupNameMessage.value = t('settings.family.saved')
  } catch (error: unknown) {
    const apiError = error as ApiRequestError
    groupNameMessage.value = apiError.data?.message || t('settings.family.failed')
  } finally {
    groupNameIsLoading.value = false
  }
}

onMounted(() => {
  fetchMembers()
  fetchCategories()
  fetchApi<DataResponse<Group>>(groupPath())
    .then((response) => {
      imageSettings.value = response.data.images
    })
    .catch(() => {
      imageSettings.value = undefined
    })
})
useHead(() => ({ title: `${t('navigation.settings')} | Famie` }))
watch(locale, () => {
  localeMessage.value = ''
  localeError.value = ''
  suffixError.value = ''
  suffixSuccess.value = ''
  profileSuccessMessage.value = ''
  profileErrorMessage.value = ''
  pwdSuccessMessage.value = ''
  userSuccessMessage.value = ''
  memberMessage.value = ''
  memberError.value = ''
  groupNameMessage.value = ''
  categorySuccessMessage.value = ''
})
</script>

<template>
  <div class="space-y-6">
    <SettingsCard :title="t('settings.profile.title')" :initial-open="true">
      <div class="flex justify-between items-center pt-1">
        <div>
          <p class="text-base font-bold text-slate-800 dark:text-slate-100">{{ user?.display_name }}</p>
          <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('settings.profile.username') }} {{ user?.username }}</p>
        </div>
        <span
          :class="[
            'px-2.5 py-1 text-xs font-semibold rounded-full',
            isParent ? 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300' : 'bg-slate-100 dark:bg-slate-900 text-slate-600 dark:text-slate-300',
          ]"
        >
          {{ isParent ? t('settings.role.parent') : t('settings.role.child') }}
        </span>
      </div>
      <form class="space-y-3 pt-3" novalidate @submit.prevent="handleProfileSave">
        <div>
          <label for="display-name" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('settings.profile.displayName') }}</label>
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
              {{ profileIsLoading ? t('common.changing') : t('common.change') }}
            </button>
          </div>
          <p id="display-name-help" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('settings.profile.nameHelp') }}</p>
        </div>
        <p id="display-name-error" role="alert" class="text-xs text-red-600 dark:text-red-300">{{ displayedProfileError }}</p>
        <p v-if="profileSuccessMessage" role="status" class="text-xs text-emerald-600 dark:text-emerald-300">{{ profileSuccessMessage }}</p>
      </form>
      <template v-if="locale === 'ja'">
        <ToggleSwitch :model-value="user?.show_name_suffix !== false" :label="t('settings.suffix.label')" :busy="suffixIsLoading" :disabled="profileIsLoading || localeIsLoading" @update:model-value="saveSuffix" />
        <p v-if="suffixError" role="alert" class="text-xs text-red-600 dark:text-red-300">{{ suffixError }}</p>
        <p v-if="suffixSuccess" role="status" class="text-xs text-emerald-600 dark:text-emerald-300">{{ suffixSuccess }}</p>
      </template>
      <div class="flex justify-end pt-3 border-t border-slate-100 dark:border-slate-700">
        <button type="button" class="px-4 py-2 text-sm font-medium text-red-600 dark:text-red-300 border border-red-200 dark:border-red-800 rounded-lg hover:bg-red-50 dark:hover:bg-red-950" @click="logout">
          {{ t('auth.logout') }}
        </button>
      </div>
    </SettingsCard>

    <SettingsCard :title="t('settings.family.title')">
      <form class="space-y-2" @submit.prevent="handleGroupNameSave">
        <label for="group-name" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">{{ t('settings.family.name') }}</label>
        <div class="flex">
          <input id="group-name" v-model="groupName" maxlength="50" :disabled="!isParent" class="min-w-0 flex-1 rounded-l-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 dark:border-slate-600 dark:disabled:bg-slate-900">
          <button v-if="isParent" type="submit" :disabled="groupNameIsLoading" class="rounded-r-lg bg-blue-600 px-4 py-2 text-sm text-white disabled:opacity-50">{{ t('common.change') }}</button>
        </div>
        <p v-if="groupNameMessage" role="status" class="text-xs text-slate-500 dark:text-slate-400">{{ groupNameMessage }}</p>
      </form>
      <div v-if="imageSettings?.enabled" class="rounded-lg bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-900 dark:text-slate-300">
        <div class="flex items-center justify-between gap-3 font-semibold text-slate-800 dark:text-slate-100">
          <span>{{ t('settings.storage.enabled') }}</span>
          <span class="text-blue-600 dark:text-blue-300">{{ storageUsagePercent }}%</span>
        </div>
        <div role="progressbar" :aria-label="t('settings.storage.title')" :aria-valuenow="storageUsagePercent" aria-valuemin="0" aria-valuemax="100" :aria-valuetext="t('settings.storage.usage', { value1: formatStorageSize(storageUsedBytes), value2: formatStorageSize(storageQuotaBytes) })" class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
          <div class="h-full rounded-full bg-blue-500 transition-[width] dark:bg-blue-400" :style="{ width: `${storageUsagePercent}%` }" />
        </div>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-slate-500 dark:text-slate-400">
          <span>{{ formatStorageSize(storageUsedBytes) }} {{ t('settings.storage.used') }}</span>
          <span>{{ t('settings.storage.remaining') }} {{ formatStorageSize(storageRemainingBytes) }} / {{ formatStorageSize(storageQuotaBytes) }}</span>
        </div>
      </div>
    </SettingsCard>

    <SettingsCard id="family-members" :title="t('settings.members.title')" :initial-open="route.hash === '#family-members'">
      <div class="divide-y divide-slate-100 dark:divide-slate-700">
        <div v-for="member in familyMembers" :key="member.id" class="py-2.5 flex justify-between items-center first:pt-0 last:pb-0">
          <div class="min-w-0 break-all">
            <div class="flex items-center" tabindex="-1" data-alert-focus>
              <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ member.display_name }}</p>
              <MemberSecurityAlert v-if="isParent" :membership-id="member.membership_id" :name="member.display_name" />
            </div>
            <p class="text-xs text-slate-400 dark:text-slate-400">@{{ member.username }}</p>
          </div>
          <div class="flex shrink-0 items-center gap-2">
          <span class="text-xs text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-900 px-2 py-0.5 rounded-md">
            {{ member.role === 'admin' ? t('settings.role.parentShort') : t('settings.role.childShort') }}
          </span>
          <button v-if="isParent" type="button" :aria-label="t('settings.members.manageName', { value1: member.display_name })" class="min-h-11 px-3 text-sm rounded-lg border border-slate-300 dark:border-slate-600" @click="openMember(member)">{{ t('common.manage') }}</button>
          </div>
        </div>
      </div>
      <div v-if="isParent" class="space-y-3 border-t border-slate-200 dark:border-slate-700 pt-3">
        <button type="button" :disabled="inactiveLoading" class="min-h-11 text-sm text-blue-600 dark:text-blue-300 disabled:opacity-50" @click="memberError = ''; fetchInactiveMembers()">{{ t('settings.members.restoreInactive') }}</button>
        <p v-if="inactiveLoaded && !inactiveMembers.length" class="text-sm text-slate-600 dark:text-slate-300">{{ t('settings.members.noInactive') }}</p>
        <div v-for="member in inactiveMembers" :key="member.membership_id" class="flex items-center justify-between gap-3">
          <div class="min-w-0 text-sm break-all" tabindex="-1" data-alert-focus>{{ member.display_name }}<MemberSecurityAlert :membership-id="member.membership_id" :name="member.display_name" />（@{{ member.username }}）<span class="ml-2">{{ member.role === 'admin' ? t('settings.role.parentShort') : t('settings.role.childShort') }}</span></div>
          <button type="button" :aria-label="t('settings.members.restoreName', { value1: member.display_name })" class="min-h-11 shrink-0 px-3 text-sm rounded-lg border border-slate-300 dark:border-slate-600" @click="openMember(member, true)">{{ t('common.restore') }}</button>
        </div>
      </div>
      <p v-if="memberMessage" role="status" class="text-sm text-emerald-600 dark:text-emerald-300">{{ memberMessage }}</p>
      <p v-if="memberError" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ memberError }}</p>
      <div v-if="isParent && securityAlerts.error.value" role="alert" class="text-sm text-red-600 dark:text-red-300">
        {{ securityAlerts.error.value }}
        <button type="button" :disabled="securityAlerts.state.value.loading || securityAlerts.state.value.busy" class="min-h-11 px-3 underline" @click="securityAlerts.refresh(true)">{{ t('common.retry') }}</button>
      </div>
    </SettingsCard>

    <SettingsCard :title="t('settings.category.title')">
      <p v-if="isParent && categories.length > 0" class="text-xs text-slate-500 dark:text-slate-400">{{ t('settings.category.intro') }}</p>
      <div class="flex flex-wrap gap-2">
        <component
          v-for="category in categories"
          :is="isParent ? 'button' : 'span'"
          :key="category.id"
          :type="isParent ? 'button' : undefined"
          :aria-label="isParent ? t('settings.members.manageName', { value1: category.name }) : undefined"
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
          <label for="category-name" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('settings.category.newName') }}</label>
          <input
            id="category-name"
            v-model="categoryForm.name"
            type="text"
            required
            :placeholder="t('settings.category.example')"
            class="h-11 w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
        </div>
        <div>
          <label for="category-color" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('settings.category.color') }}</label>
          <input id="category-color" v-model="categoryForm.color_code" type="color" class="block h-11 w-12 rounded-lg border border-slate-300 dark:border-slate-600">
        </div>
        <button
          type="submit"
          :disabled="categoryIsLoading"
          class="h-11 shrink-0 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition disabled:opacity-50"
        >
          {{ t('common.add') }}
        </button>
      </form>

      <div v-if="categorySuccessMessage" role="status" class="p-3 bg-emerald-50 dark:bg-emerald-950 text-emerald-600 dark:text-emerald-300 text-xs rounded-lg">
        {{ categorySuccessMessage }}
      </div>
      <div v-if="categoryErrorMessage" role="alert" class="p-3 bg-red-50 dark:bg-red-950 text-red-600 dark:text-red-300 text-xs rounded-lg">
        {{ categoryErrorMessage }}
      </div>
    </SettingsCard>

    <SettingsCard v-if="isParent" :title="t('settings.members.addTitle')" :badge="t('settings.adminOnly')" class="border-blue-200 dark:border-blue-800">

      <div v-if="userSuccessMessage" class="p-3 bg-emerald-50 dark:bg-emerald-950 text-emerald-600 dark:text-emerald-300 text-xs rounded-lg">
        {{ userSuccessMessage }}
      </div>
      <div v-if="userErrorMessage" class="p-3 bg-red-50 dark:bg-red-950 text-red-600 dark:text-red-300 text-xs rounded-lg">
        {{ userErrorMessage }}
      </div>

      <form class="space-y-3" @submit.prevent="handleAddUser">
        <div>
          <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('settings.members.displayName') }}<span class="text-red-500 dark:text-red-400">*</span></label>
          <input
            v-model="userForm.display_name"
            type="text"
            required
            :placeholder="t('settings.members.nameExample')"
            class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('settings.members.loginId') }}<span class="text-red-500 dark:text-red-400">*</span></label>
          <input
            v-model="userForm.username"
            type="text"
            required
            :placeholder="t('settings.members.usernameExample')"
            class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
        </div>

        <div>
          <label for="initial-password" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('settings.members.initialPassword') }}<span aria-hidden="true" class="text-red-500 dark:text-red-400">*</span></label>
          <PasswordInput
            id="initial-password"
            :label="t('settings.members.initialPassword')"
            v-model="userForm.password"
            strength
            minlength="15"
            autocomplete="new-password"
            required
            :placeholder="t('validation.passwordMinimum')"
            class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label for="initial-password-confirmation" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('settings.members.confirmInitialPassword') }}<span aria-hidden="true" class="text-red-500 dark:text-red-400">*</span></label>
          <PasswordInput
            id="initial-password-confirmation"
            :label="t('settings.members.confirmInitialPassword')"
            v-model="userForm.password_confirmation"
            minlength="15"
            autocomplete="new-password"
            required
            :placeholder="t('auth.repeatInput')"
            class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('settings.members.role') }}</label>
          <div class="flex space-x-4 pt-1">
            <label class="flex items-center space-x-2 text-sm text-slate-700 dark:text-slate-200 cursor-pointer">
              <input v-model="userForm.role" type="radio" value="member" class="text-blue-600 dark:text-blue-300">
              <span>{{ t('settings.role.child') }}</span>
            </label>
            <label class="flex items-center space-x-2 text-sm text-slate-700 dark:text-slate-200 cursor-pointer">
              <input v-model="userForm.role" type="radio" value="admin" class="text-blue-600 dark:text-blue-300">
              <span>{{ t('settings.role.guardian') }}</span>
            </label>
          </div>
          <p class="mt-2 text-xs text-slate-600 dark:text-slate-300">{{ t('settings.members.roleHelp') }}</p>
        </div>

        <button
          type="submit"
          :disabled="userIsLoading"
          class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium text-sm rounded-lg transition disabled:opacity-50"
        >
          {{ userIsLoading ? t('common.creating') : t('settings.members.create') }}
        </button>
      </form>
    </SettingsCard>

    <EmailSettingsCard />

    <SettingsCard :title="t('settings.password.title')">

      <div v-if="pwdSuccessMessage" class="p-3 bg-emerald-50 dark:bg-emerald-950 text-emerald-600 dark:text-emerald-300 text-xs rounded-lg">
        {{ pwdSuccessMessage }}
      </div>
      <div v-if="pwdErrorMessage" class="p-3 bg-red-50 dark:bg-red-950 text-red-600 dark:text-red-300 text-xs rounded-lg">
        {{ pwdErrorMessage }}
      </div>

      <form class="space-y-3" @submit.prevent="handlePasswordChange">
        <div>
          <label for="current-password" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('auth.currentPassword') }}</label>
          <PasswordInput
            id="current-password"
            :label="t('auth.currentPassword')"
            v-model="pwdForm.current_password"
            autocomplete="current-password"
            required
            class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label for="new-password" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('auth.newPassword') }}</label>
          <PasswordInput
            id="new-password"
            :label="t('auth.newPassword')"
            v-model="pwdForm.new_password"
            strength
            minlength="15"
            autocomplete="new-password"
            required
            class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label for="new-password-confirmation" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('auth.confirmNewPassword') }}</label>
          <PasswordInput
            id="new-password-confirmation"
            :label="t('auth.confirmNewPassword')"
            v-model="pwdForm.new_password_confirmation"
            minlength="15"
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
          {{ pwdIsLoading ? t('common.updating') : t('settings.password.submit') }}
        </button>
      </form>
    </SettingsCard>
    <SettingsCard :title="t('settings.display.title')">
      <form class="space-y-3" @submit.prevent="saveLocale">
        <fieldset :disabled="localeIsLoading || profileIsLoading || suffixIsLoading" class="space-y-2">
          <legend class="text-sm font-semibold">{{ t('locale.title') }}</legend>
          <div class="flex flex-wrap gap-4">
            <label class="flex min-h-11 items-center gap-2"><input v-model="localeSelection" type="radio" name="ui-locale" :value="null">{{ t('locale.auto') }}</label>
            <label v-for="choice in localeChoices" :key="choice.code" class="flex min-h-11 items-center gap-2"><input v-model="localeSelection" type="radio" name="ui-locale" :value="choice.code">{{ choice.name }}</label>
          </div>
          <p class="text-xs">{{ t('locale.current', { language: localeChoices.find(choice => choice.code === effective)?.name }) }}</p>
          <p class="text-xs text-slate-600 dark:text-slate-300">{{ t('locale.help') }}</p>
          <button type="submit" :disabled="localeSelection === savedLocale" class="min-h-11 rounded-lg bg-blue-600 px-4 py-2 text-sm text-white disabled:opacity-50">{{ t(localeIsLoading ? 'common.updating' : 'common.save') }}</button>
        </fieldset>
        <p v-if="localeMessage" role="status" class="text-sm text-green-700 dark:text-green-300">{{ localeMessage }}</p>
        <p v-if="localeError" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ localeError }}</p>
      </form>
      <fieldset class="space-y-3">
        <legend class="text-sm font-semibold">{{ t('settings.display.mode') }}</legend>
        <div class="flex gap-4">
          <label class="flex min-h-11 items-center gap-2 text-sm"><input type="radio" name="theme" value="light" :checked="theme === 'light'" @change="setTheme('light')">{{ t('settings.display.light') }}</label>
          <label class="flex min-h-11 items-center gap-2 text-sm"><input type="radio" name="theme" value="dark" :checked="theme === 'dark'" @change="setTheme('dark')">{{ t('settings.display.dark') }}</label>
        </div>
        <p class="text-xs text-slate-600 dark:text-slate-300">{{ t('settings.display.help') }}</p>
        <p v-if="storageError" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ t('settings.display.storageFailed') }}</p>
      </fieldset>
    </SettingsCard>
    <SettingsCard :title="t('settings.about.title')">
      <dl class="text-sm text-slate-600 dark:text-slate-300">
        <div class="flex justify-between gap-4"><dt>{{ t('settings.about.version') }}</dt><dd>{{ publicConfig.appVersion }}</dd></div>
      </dl>
      <a href="/THIRD_PARTY_NOTICES.txt" target="_blank" rel="noopener noreferrer" class="text-xs text-blue-600 underline dark:text-blue-300">{{ t('settings.about.licenses') }}</a>
      <p class="text-center text-xs text-slate-500 dark:text-slate-400">© MAGIC METHODS</p>
    </SettingsCard>
    <MemberManageDialog v-if="selectedMember && isParent" :member="selectedMember" :inactive="restoringMember" @close="selectedMember = null" @saved="memberSaved" />
    <CategoryManageDialog v-if="selectedCategory && isParent" :category="selectedCategory" @close="selectedCategory = null" @saved="categorySaved" />
  </div>
</template>
