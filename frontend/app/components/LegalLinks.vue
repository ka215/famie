<script setup lang="ts">
const { t, locale } = useI18n()
const props = withDefaults(defineProps<{ returnTo?: 'login' | 'settings' }>(), {
  returnTo: 'login',
})
const suffix = computed(() => (locale.value === 'ja' ? '' : 'en/'))
const context = computed(() => (props.returnTo === 'settings' ? '?from=settings' : ''))
</script>

<template>
  <nav :aria-label="t('legal.linksLabel')" class="flex flex-wrap justify-center gap-x-4 gap-y-2 border-t border-slate-200 pt-4 text-sm dark:border-slate-700">
    <a v-for="document in ['terms', 'privacy', 'cookies']" :key="document" :href="`/legal/${document}/${suffix}${context}`" class="text-blue-600 dark:text-blue-300">{{ t(`legal.${document}`) }}</a>
  </nav>
</template>
