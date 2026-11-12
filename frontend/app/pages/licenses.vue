<script setup lang="ts">
const { t } = useI18n()
const notices = ref('')
const busy = ref(false)
const failed = ref(false)
const load = async () => {
  if (busy.value) return
  busy.value = true
  failed.value = false
  try {
    notices.value = await $fetch<string>('/THIRD_PARTY_NOTICES.txt', { responseType: 'text' })
  } catch {
    failed.value = true
  } finally {
    busy.value = false
  }
}
onMounted(load)
useHead(() => ({ title: `${t('settings.about.licenses')} | Famie` }))
</script>

<template>
  <section class="space-y-4 rounded-2xl bg-white p-5 text-slate-800 shadow dark:bg-slate-800 dark:text-slate-100">
    <h1 class="text-xl font-bold">{{ t('settings.about.licenses') }}</h1>
    <p v-if="busy" role="status">{{ t('common.loading') }}</p>
    <div v-else-if="failed" role="alert">
      <p>{{ t('legal.loadFailed') }}</p>
      <button type="button" class="mt-2 text-blue-600 dark:text-blue-300" @click="load">{{ t('common.retry') }}</button>
    </div>
    <pre v-else class="whitespace-pre-wrap break-words text-xs [overflow-wrap:anywhere]">{{ notices }}</pre>
    <NuxtLink to="/settings" class="block text-blue-600 dark:text-blue-300">{{ t('navigation.settings') }}</NuxtLink>
  </section>
</template>
