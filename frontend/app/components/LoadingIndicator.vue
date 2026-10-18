<script setup lang="ts">
const { t, locale } = useI18n()

import loadingSpinnerIcon from '~/assets/icons/loading-spinner.svg'

const spinnerSrc = loadingSpinnerIcon

const props = defineProps<{ label?: string }>()
const label = computed(() => props.label ?? t('common.loading'))
</script>

<template>
  <div class="flex items-center justify-center gap-2 py-4 text-sm text-slate-500 dark:text-slate-400" role="status" aria-live="polite">
    <img :src="spinnerSrc" alt="" class="h-6 w-6 dark:invert" aria-hidden="true">
    <span>{{ label }}</span>
  </div>
</template>
