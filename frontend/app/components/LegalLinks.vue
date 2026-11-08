<script setup lang="ts">
const { t, locale } = useI18n()
const suffix = computed(() => (locale.value === 'ja' ? '' : 'en/'))
</script>

<template>
  <nav :aria-label="t('legal.linksLabel')" class="flex flex-wrap justify-center gap-x-4 gap-y-2 border-t border-slate-200 pt-4 text-sm dark:border-slate-700">
    <a v-for="document in ['terms', 'privacy', 'cookies']" :key="document" :href="`/legal/${document}/${suffix}`" target="_blank" rel="noopener" class="text-blue-600 dark:text-blue-300">{{ t(`legal.${document}`) }}<span class="sr-only"> {{ t('legal.newTab') }}</span></a>
  </nav>
</template>
