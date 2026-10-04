<script setup lang="ts">
import type { ApiRequestError, DataResponse, FamilyMember, MembershipRole } from '#shared/types/api'
import { profileSchema } from '#shared/utils/profileSchema'

const props = defineProps<{ member: FamilyMember; inactive?: boolean }>()
const emit = defineEmits<{ close: []; saved: [member: FamilyMember] }>()
const { user, groupId } = useAuth()
const { fetchApi } = useApi()
const displayName = ref(props.member.display_name)
const role = ref<MembershipRole>(props.member.role)
const busy = ref(false)
const error = ref('')
const confirming = ref(false)
const isSelf = computed(() => user.value?.id === props.member.id)
const changed = computed(
  () => displayName.value !== props.member.display_name || role.value !== props.member.role
)

const save = async (status?: 'active' | 'inactive') => {
  if (busy.value) return
  error.value = ''
  const parsed = profileSchema.safeParse({ display_name: displayName.value })
  if (!status && !parsed.success) {
    error.value = parsed.error.issues[0]?.message ?? '表示名を確認してください。'
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
      Object.values(data?.errors ?? {}).flat()[0] ??
      data?.message ??
      '変更できませんでした。通信環境を確認してください。'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <SettingsDialog :title="inactive ? 'メンバーを復元' : 'メンバーを管理'" :busy="busy" @close="emit('close')">
    <p class="mb-3 text-sm break-all">{{ member.display_name }}（@{{ member.username }}）</p>
    <p v-if="error" role="alert" class="mb-3 text-sm text-red-600 dark:text-red-300">{{ error }}</p>
    <form v-if="!inactive && !confirming" class="space-y-4" @submit.prevent="save()">
      <div>
        <label for="member-display-name" class="block text-sm mb-1">メンバーの表示名</label>
        <input id="member-display-name" v-model="displayName" :disabled="busy" class="w-full border border-slate-300 dark:border-slate-600 rounded-lg p-2" required>
      </div>
      <div>
        <label for="member-role" class="block text-sm mb-1">メンバーの役割</label>
        <select id="member-role" v-model="role" :disabled="busy || isSelf" class="w-full border border-slate-300 dark:border-slate-600 rounded-lg p-2">
          <option value="member">子（一般）</option>
          <option value="admin">親（管理者）</option>
        </select>
        <p class="mt-2 text-xs text-slate-600 dark:text-slate-300">親は家族のメンバーとカテゴリを管理できます。</p>
      </div>
      <button type="submit" :disabled="busy || !changed" class="w-full min-h-11 rounded-lg bg-blue-600 text-white disabled:opacity-50">変更を保存</button>
      <p v-if="isSelf" class="text-sm text-slate-600 dark:text-slate-300">自分自身の役割変更・無効化はできません。</p>
      <button v-else type="button" :disabled="busy" class="w-full min-h-11 rounded-lg border border-red-300 dark:border-red-700 text-red-600 dark:text-red-300" @click="confirming = true; error = ''">このメンバーを無効化</button>
    </form>
    <div v-else class="space-y-4">
      <p v-if="inactive" class="text-sm">{{ member.role === 'admin' ? '親（管理者）' : '子（一般）' }}として復元します。再ログインできるようになり、これまでの活動記録が再表示されます。</p>
      <p v-else class="text-sm">このメンバーはログインできなくなり、活動記録が全員の画面から非表示になります。記録は保持され、復元すると再表示されます。未保存の編集内容は反映されません。</p>
      <button type="button" :disabled="busy" class="w-full min-h-11 rounded-lg bg-blue-600 text-white disabled:opacity-50" @click="save(inactive ? 'active' : 'inactive')">{{ inactive ? '復元する' : '無効化する' }}</button>
      <button v-if="!inactive" type="button" :disabled="busy" class="w-full min-h-11 rounded-lg border border-slate-300 dark:border-slate-600" @click="confirming = false; error = ''">編集に戻る</button>
    </div>
  </SettingsDialog>
</template>
