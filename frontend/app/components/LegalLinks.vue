<script setup lang="ts">
const { t, locale } = useI18n()
const props = withDefaults(
  defineProps<{ returnTo?: 'login' | 'settings' | 'legal-confirmation' }>(),
  {
    returnTo: 'login',
  }
)
const suffix = computed(() => (locale.value === 'ja' ? '' : 'en/'))
</script>

<template>
  <nav :aria-label="t('legal.linksLabel')" class="flex gap-x-4 gap-y-2 border-t border-slate-200 pt-4 text-sm dark:border-slate-700" :class="props.returnTo === 'settings' ? 'flex-col items-start' : 'flex-wrap justify-center'">
    <LegalDocumentLink v-for="document in ['terms', 'privacy', 'cookies']" :key="document" :href="`/legal/${document}/${suffix}`" :title="t(`legal.${document}`)" :return-to="returnTo" />
  </nav>
</template>
