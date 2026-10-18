<script setup lang="ts">
const { t, locale } = useI18n()

import type { ApiRequestError, DataResponse, FamilyMember, MembershipRole } from '#shared/types/api'
import { passwordStrength } from '#shared/utils/password-strength'
import { createProfileSchema } from '#shared/utils/profileSchema'

const props = defineProps<{ member: FamilyMember; inactive?: boolean }>()
const emit = defineEmits<{ close: []; saved: [member: FamilyMember] }>()
const { user, groupId } = useAuth()
const { fetchApi } = useApi()
const displayName = ref(props.member.display_name)
const role = ref<MembershipRole>(props.member.role)
const busy = ref(false)
const error = useUiMessage()
const confirming = ref(false)
const resetting = ref(false)
const resetMessage = ref('')
const passwords = ref({ current_password: '', new_password: '', new_password_confirmation: '' })
const isSelf = computed(() => user.value?.id === props.member.id)
const changed = computed(
  () => displayName.value !== props.member.display_name || role.value !== props.member.role
)

const resetPassword = async () => {
  if (busy.value) return
  error.value = ''
  if (!passwordStrength(passwords.value.new_password).acceptable) {
    error.setKey('auth.strength.guidance')
    return
  }
  if (passwords.value.new_password !== passwords.value.new_password_confirmation) {
    error.setKey('validation.newPasswordMismatch')
    return
  }
  busy.value = true
  try {
    const result = await fetchApi<{ message: string }>(
      `/groups/${groupId.value}/members/${props.member.membership_id}/password`,
      { method: 'PUT', body: { ...passwords.value } }
    )
    resetMessage.value = result.message
    passwords.value = { current_password: '', new_password: '', new_password_confirmation: '' }
    resetting.value = false
  } catch (cause: unknown) {
    const data = (cause as ApiRequestError).data
    error.value =
      Object.values(data?.errors ?? {}).flat()[0] ??
      data?.message ??
      t('settings.members.resetFailed')
  } finally {
    busy.value = false
  }
}

const cancelReset = () => {
  resetting.value = false
  error.value = ''
  passwords.value = { current_password: '', new_password: '', new_password_confirmation: '' }
}

const save = async (status?: 'active' | 'inactive') => {
  if (busy.value) return
  error.value = ''
  const parsed = createProfileSchema(t).safeParse({ display_name: displayName.value })
  if (!status && !parsed.success) {
    error.setLocal(() => {
      const current = createProfileSchema(t).safeParse({ display_name: displayName.value })
      return current.success
        ? ''
        : (current.error.issues[0]?.message ?? t('validation.checkDisplayName'))
    })
    return
  }
  busy.value = true
  try {
    const result = await fetchApi<DataResponse<FamilyMember>>(
      `/groups/${groupId.value}/members/${props.member.membership_id}${status ? '/status' : ''}`,
      {
        method: status ? 'PUT' : 'PATCH',
        body: status
          ? { status }
          : { display_name: parsed.success ? parsed.data.display_name : '', role: role.value },
      }
    )
    emit('saved', result.data)
  } catch (cause: unknown) {
    const data = (cause as ApiRequestError).data
    error.value =
      Object.values(data?.errors ?? {}).flat()[0] ?? data?.message ?? t('errors.saveFailed')
  } finally {
    busy.value = false
  }
}
watch(locale, () => {
  resetMessage.value = ''
})
</script>

<template>
  <SettingsDialog :title="inactive ? t('settings.members.restoreTitle') : t('settings.members.manageTitle')" :busy="busy" @close="emit('close')">
    <p class="mb-3 text-sm break-all">{{ member.display_name }}（@{{ member.username }}）</p>
    <p v-if="error" role="alert" class="mb-3 text-sm text-red-600 dark:text-red-300">{{ error }}</p>
    <p v-if="resetMessage" role="status" class="mb-3 text-sm text-green-700 dark:text-green-300">{{ resetMessage }}</p>
    <form v-if="resetting" class="space-y-4" @submit.prevent="resetPassword">
      <p class="text-sm">{{ t('settings.members.resetHelp') }}</p>
      <div><label for="parent-confirm-password" class="block text-sm">{{ t('settings.members.parentPassword') }}</label><PasswordInput id="parent-confirm-password" v-model="passwords.current_password" :label="t('settings.members.parentPassword')" autocomplete="current-password" required :disabled="busy" class="mt-1 w-full rounded-lg border border-slate-400 px-3 py-2" /></div>
      <div><label for="member-new-password" class="block text-sm">{{ t('settings.members.newPassword') }}</label><PasswordInput id="member-new-password" v-model="passwords.new_password" :label="t('settings.members.newPassword')" autocomplete="new-password" required minlength="15" strength :disabled="busy" class="mt-1 w-full rounded-lg border border-slate-400 px-3 py-2" /></div>
      <div><label for="member-confirm-password" class="block text-sm">{{ t('settings.members.confirmPassword') }}</label><PasswordInput id="member-confirm-password" v-model="passwords.new_password_confirmation" :label="t('settings.members.confirmPassword')" autocomplete="new-password" required minlength="15" :disabled="busy" class="mt-1 w-full rounded-lg border border-slate-400 px-3 py-2" /></div>
      <button type="submit" :disabled="busy" class="w-full min-h-11 rounded-lg bg-blue-600 text-white disabled:opacity-50">{{ busy ? t('settings.members.resetting') : t('settings.members.resetSubmit') }}</button>
      <button type="button" :disabled="busy" class="w-full min-h-11 rounded-lg border border-slate-400" @click="cancelReset">{{ t('settings.members.back') }}</button>
    </form>
    <form v-else-if="!inactive && !confirming" class="space-y-4" @submit.prevent="save()">
      <div>
        <label for="member-display-name" class="block text-sm mb-1">{{ t('settings.members.nameLabel') }}</label>
        <input id="member-display-name" v-model="displayName" :disabled="busy" class="w-full border border-slate-300 dark:border-slate-600 rounded-lg p-2" required>
      </div>
      <div>
        <label for="member-role" class="block text-sm mb-1">{{ t('settings.members.roleLabel') }}</label>
        <select id="member-role" v-model="role" :disabled="busy || isSelf" class="w-full border border-slate-300 dark:border-slate-600 rounded-lg p-2">
          <option value="member">{{ t('settings.role.member') }}</option>
          <option value="admin">{{ t('settings.role.parent') }}</option>
        </select>
        <p class="mt-2 text-xs text-slate-600 dark:text-slate-300">{{ t('settings.members.parentHelp') }}</p>
      </div>
      <button type="submit" :disabled="busy || !changed" class="w-full min-h-11 rounded-lg bg-blue-600 text-white disabled:opacity-50">{{ t('common.saveChanges') }}</button>
      <p v-if="isSelf" class="text-sm text-slate-600 dark:text-slate-300">{{ t('settings.members.selfHelp') }}</p>
      <button v-if="!isSelf" type="button" :disabled="busy" class="w-full min-h-11 rounded-lg border border-blue-400 text-blue-700 dark:text-blue-300" @click="resetting = true; error = ''; resetMessage = ''">{{ t('settings.members.reset') }}</button>
      <button v-if="!isSelf" type="button" :disabled="busy" class="w-full min-h-11 rounded-lg border border-red-300 dark:border-red-700 text-red-600 dark:text-red-300" @click="confirming = true; error = ''">{{ t('settings.members.deactivate') }}</button>
    </form>
    <div v-else class="space-y-4">
      <p v-if="inactive" class="text-sm">{{ t('settings.members.restoreConfirm', { role: t(member.role === 'admin' ? 'settings.role.parent' : 'settings.role.member') }) }}</p>
      <p v-else class="text-sm">{{ t('settings.members.deactivateHelp') }}</p>
      <button type="button" :disabled="busy" class="w-full min-h-11 rounded-lg bg-blue-600 text-white disabled:opacity-50" @click="save(inactive ? 'active' : 'inactive')">{{ inactive ? t('settings.members.restoreSubmit') : t('settings.members.deactivateSubmit') }}</button>
      <button v-if="!inactive" type="button" :disabled="busy" class="w-full min-h-11 rounded-lg border border-slate-300 dark:border-slate-600" @click="confirming = false; error = ''">{{ t('common.backToEdit') }}</button>
    </div>
  </SettingsDialog>
</template>
