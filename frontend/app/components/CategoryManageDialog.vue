<script setup lang="ts">
import type { ApiRequestError, Category } from '#shared/types/api'
import { categoryStyle } from '#shared/utils/categoryColor'
import { trimLaravelString } from '#shared/utils/laravelString'

const props = defineProps<{ category: Category }>()
const emit = defineEmits<{ close: []; saved: [] }>()
const { groupId } = useAuth()
const { fetchApi } = useApi()
const name = ref(props.category.name)
const color = ref(props.category.color_code || '#3B82F6')
const previewStyle = computed(() => categoryStyle(color.value))
const busy = ref(false)
const error = ref('')
const confirming = ref(false)

const save = async (remove = false) => {
  if (busy.value) return
  error.value = ''
  const trimmed = trimLaravelString(name.value)
  if (!remove && (!trimmed || Array.from(trimmed).length > 255)) {
    error.value = 'カテゴリ名は1〜255文字で入力してください。'
    return
  }
  busy.value = true
  try {
    await fetchApi(`/groups/${groupId.value}/categories/${props.category.id}`, {
      method: remove ? 'DELETE' : 'PUT',
      ...(remove ? {} : { body: { name: trimmed, color_code: color.value } }),
    })
    emit('saved')
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
  <SettingsDialog title="カテゴリを管理" :busy="busy" @close="emit('close')">
    <p v-if="error" role="alert" class="mb-3 text-sm text-red-600 dark:text-red-300">{{ error }}</p>
    <form v-if="!confirming" class="space-y-4" @submit.prevent="save()">
      <div>
        <label for="edit-category-name" class="block text-sm mb-1">カテゴリ名</label>
        <input id="edit-category-name" v-model="name" :disabled="busy" required class="w-full border border-slate-300 dark:border-slate-600 rounded-lg p-2">
      </div>
      <div>
        <label for="edit-category-color" class="block text-sm mb-1">カテゴリの色</label>
        <input id="edit-category-color" v-model="color" :disabled="busy" type="color" class="h-11 w-14">
      </div>
      <span class="inline-block max-w-full break-all rounded-full px-3 py-1 text-sm" :style="previewStyle">{{ name }}</span>
      <p class="text-sm text-slate-600 dark:text-slate-300">名前・色の変更は、過去の活動記録の表示にも反映されます。</p>
      <button type="submit" :disabled="busy" class="w-full min-h-11 rounded-lg bg-blue-600 text-white disabled:opacity-50">変更を保存</button>
      <button type="button" :disabled="busy" class="w-full min-h-11 rounded-lg border border-red-300 dark:border-red-700 text-red-600 dark:text-red-300" @click="confirming = true; error = ''">このカテゴリを削除</button>
    </form>
    <div v-else class="space-y-4">
      <p class="text-sm break-all">「{{ category.name }}」を削除しますか？この操作は取り消せません。非表示の履歴を含め、活動記録で使用されているカテゴリは削除できません。</p>
      <button type="button" :disabled="busy" class="w-full min-h-11 rounded-lg bg-red-600 text-white disabled:opacity-50" @click="save(true)">削除する</button>
      <button type="button" :disabled="busy" class="w-full min-h-11 rounded-lg border border-slate-300 dark:border-slate-600" @click="confirming = false; error = ''">編集に戻る</button>
    </div>
  </SettingsDialog>
</template>
