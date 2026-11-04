<script setup lang="ts">
import type {
  ApiRequestError,
  DataResponse,
  FamilyMember,
  LifecycleKind,
  LifecyclePrepared,
  LifecycleStatus,
} from '#shared/types/api'

const props = defineProps<{ kind: LifecycleKind; member?: FamilyMember }>()
const emit = defineEmits<{ close: []; committed: [status: LifecycleStatus] }>()
const { t } = useI18n()
const { groupId } = useAuth()
const { fetchApi } = useApi()
const { intlLocale } = useUiLocale()
const prepared = ref<LifecyclePrepared | null>(null)
const operationGroupId = ref<number | null>(null)
const currentPassword = ref('')
const savedCopy = ref(false)
const busy = ref(false)
const error = ref('')
const commitError = ref<HTMLParagraphElement | null>(null)

const prepare = async () => {
  if (busy.value || !groupId.value) return
  busy.value = true
  error.value = ''
  const targetGroupId = groupId.value
  try {
    const response = await fetchApi<DataResponse<LifecyclePrepared>>(
      `/groups/${targetGroupId}/lifecycle-operations`,
      {
        method: 'POST',
        body: {
          kind: props.kind,
          ...(props.kind === 'member_delete' ? { membership_id: props.member?.membership_id } : {}),
        },
      }
    )
    prepared.value = response.data
    operationGroupId.value = targetGroupId
  } catch (cause: unknown) {
    const data = (cause as ApiRequestError).data
    error.value =
      Object.values(data?.errors ?? {}).flat()[0] ?? data?.message ?? t('errors.operationFailed')
  } finally {
    busy.value = false
  }
}

const close = async () => {
  if (busy.value) return
  if (!prepared.value || !operationGroupId.value) {
    emit('close')
    return
  }
  busy.value = true
  error.value = ''
  try {
    await fetchApi(
      `/groups/${operationGroupId.value}/lifecycle-operations/${prepared.value.receipt_id}/cancel`,
      { method: 'POST' }
    )
    currentPassword.value = ''
    savedCopy.value = false
    prepared.value = null
    emit('close')
  } catch (cause: unknown) {
    const data = (cause as ApiRequestError).data
    error.value = data?.message ?? t('settings.lifecycle.cancelFailed')
  } finally {
    busy.value = false
  }
}

const saveText = () => {
  if (!prepared.value) return
  const text = `${t('settings.lifecycle.receipt')}: ${prepared.value.receipt_id}\n${t('settings.lifecycle.code')}: ${prepared.value.confirmation_code}\n`
  const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }))
  const link = document.createElement('a')
  link.href = url
  link.download = `famie-${prepared.value.receipt_id}.txt`
  link.click()
  URL.revokeObjectURL(url)
}

const commit = async () => {
  if (
    !prepared.value ||
    !operationGroupId.value ||
    !savedCopy.value ||
    !currentPassword.value ||
    busy.value
  )
    return
  busy.value = true
  error.value = ''
  try {
    const response = await fetchApi<DataResponse<LifecycleStatus>>(
      `/groups/${operationGroupId.value}/lifecycle-operations/${prepared.value.receipt_id}/commit`,
      {
        method: 'POST',
        body: {
          current_password: currentPassword.value,
          confirmation_code: prepared.value.confirmation_code,
          confirmed: true,
        },
      }
    )
    useState<{ receipt_id: string; confirmation_code: string } | null>(
      'lifecycle_status_receipt',
      () => null
    ).value = {
      receipt_id: prepared.value.receipt_id,
      confirmation_code: prepared.value.confirmation_code,
    }
    currentPassword.value = ''
    emit('committed', response.data)
  } catch (cause: unknown) {
    const data = (cause as ApiRequestError).data
    error.value =
      Object.values(data?.errors ?? {}).flat()[0] ?? data?.message ?? t('errors.operationFailed')
    await nextTick()
    commitError.value?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
  } finally {
    busy.value = false
  }
}

onMounted(prepare)
</script>

<template>
  <SettingsDialog :title="t(kind === 'group_withdraw' ? 'settings.lifecycle.withdrawTitle' : 'settings.lifecycle.deleteTitle')" :busy="busy" @close="close">
    <div class="space-y-4">
      <p v-if="error && !prepared" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ error }}</p>
      <template v-if="prepared">
        <p class="text-sm font-semibold text-red-700 dark:text-red-300">{{ t(kind === 'group_withdraw' ? 'settings.lifecycle.withdrawWarning' : 'settings.lifecycle.deleteWarning', { name: member?.display_name }) }}</p>
        <p class="text-xs text-slate-600 dark:text-slate-300">{{ t('settings.lifecycle.prepareDeadline', { date: new Date(prepared.prepared_expires_at).toLocaleString(intlLocale) }) }}</p>
        <p class="text-xs text-slate-600 dark:text-slate-300">{{ t('settings.lifecycle.closeHelp') }}</p>
        <dl class="space-y-2 rounded-lg bg-slate-100 p-3 text-xs dark:bg-slate-900">
          <div><dt class="font-semibold">{{ t('settings.lifecycle.receipt') }}</dt><dd class="break-all select-all">{{ prepared.receipt_id }}</dd></div>
          <div><dt class="font-semibold">{{ t('settings.lifecycle.code') }}</dt><dd class="break-all select-all">{{ prepared.confirmation_code }}</dd></div>
        </dl>
        <p class="text-xs text-slate-600 dark:text-slate-300">{{ t('settings.lifecycle.retentionHelp') }}</p>
        <button type="button" class="min-h-11 w-full rounded-lg border border-slate-400" @click="saveText">{{ t('settings.lifecycle.download') }}</button>
        <label class="flex items-start gap-2 text-sm"><input v-model="savedCopy" type="checkbox" class="mt-1">{{ t('settings.lifecycle.savedConfirm') }}</label>
        <div>
          <label for="lifecycle-current-password" class="block text-sm">{{ t('settings.lifecycle.password') }}</label>
          <PasswordInput id="lifecycle-current-password" v-model="currentPassword" :label="t('settings.lifecycle.password')" autocomplete="current-password" required :disabled="busy" class="mt-1 w-full rounded-lg border border-slate-400 px-3 py-2" />
        </div>
        <p v-if="error" ref="commitError" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ error }}</p>
        <button type="button" :disabled="busy || !savedCopy || !currentPassword" class="min-h-11 w-full rounded-lg bg-red-700 text-white disabled:opacity-50" @click="commit">{{ busy ? t('common.processing') : t(kind === 'group_withdraw' ? 'settings.lifecycle.withdrawSubmit' : 'settings.lifecycle.deleteSubmit') }}</button>
      </template>
      <button v-else-if="!busy" type="button" class="min-h-11 w-full rounded-lg border border-slate-400" @click="prepare">{{ t('common.retry') }}</button>
    </div>
  </SettingsDialog>
</template>
