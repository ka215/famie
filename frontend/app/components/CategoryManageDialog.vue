<script setup lang="ts">
const { t, locale } = useI18n()

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
const error = useUiMessage()
const confirming = ref(false)

const save = async (remove = false) => {
  if (busy.value) return
  error.value = ''
  const trimmed = trimLaravelString(name.value)
  if (!remove && (!trimmed || Array.from(trimmed).length > 255)) {
    error.setKey('validation.categoryLength')
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
      Object.values(data?.errors ?? {}).flat()[0] ?? data?.message ?? t('errors.saveFailed')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <SettingsDialog :title="t('settings.category.manage')" :busy="busy" @close="emit('close')">
    <p v-if="error" role="alert" class="mb-3 text-sm text-red-600 dark:text-red-300">{{ error }}</p>
    <form v-if="!confirming" class="space-y-4" @submit.prevent="save()">
      <div>
        <label for="edit-category-name" class="block text-sm mb-1">{{ t('settings.category.name') }}</label>
        <input id="edit-category-name" v-model="name" :disabled="busy" required class="w-full border border-slate-300 dark:border-slate-600 rounded-lg p-2">
      </div>
      <div>
        <label for="edit-category-color" class="block text-sm mb-1">{{ t('settings.category.colorLabel') }}</label>
        <input id="edit-category-color" v-model="color" :disabled="busy" type="color" class="h-11 w-14">
      </div>
      <span class="inline-block max-w-full break-all rounded-full px-3 py-1 text-sm" :style="previewStyle">{{ name }}</span>
      <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('settings.category.editHelp') }}</p>
      <button type="submit" :disabled="busy" class="w-full min-h-11 rounded-lg bg-blue-600 text-white disabled:opacity-50">{{ t('common.saveChanges') }}</button>
      <button type="button" :disabled="busy" class="w-full min-h-11 rounded-lg border border-red-300 dark:border-red-700 text-red-600 dark:text-red-300" @click="confirming = true; error = ''">{{ t('settings.category.delete') }}</button>
    </form>
    <div v-else class="space-y-4">
      <p class="text-sm break-all">{{ t('settings.category.deleteConfirm', { name: category.name }) }}</p>
      <button type="button" :disabled="busy" class="w-full min-h-11 rounded-lg bg-red-600 text-white disabled:opacity-50" @click="save(true)">{{ t('common.delete') }}</button>
      <button type="button" :disabled="busy" class="w-full min-h-11 rounded-lg border border-slate-300 dark:border-slate-600" @click="confirming = false; error = ''">{{ t('common.backToEdit') }}</button>
    </div>
  </SettingsDialog>
</template>
