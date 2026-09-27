<script setup lang="ts">
import loadingSpinnerIcon from '~/assets/icons/loading-spinner.svg'

const spinnerSrc = loadingSpinnerIcon

withDefaults(
  defineProps<{
    label?: string
  }>(),
  { label: '読み込み中...' }
)
</script>

<template>
  <div class="flex items-center justify-center gap-2 py-4 text-sm text-slate-500" role="status" aria-live="polite">
    <img :src="spinnerSrc" alt="" class="h-6 w-6" aria-hidden="true">
    <span>{{ label }}</span>
  </div>
</template>
