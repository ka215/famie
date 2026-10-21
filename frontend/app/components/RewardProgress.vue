<script setup lang="ts">
const { t } = useI18n()
const { intlLocale } = useUiLocale()
const number = (value: number) => new Intl.NumberFormat(intlLocale.value).format(value)
const props = defineProps<{ current: number | null; requiredCount: number }>()
const percent = computed(() =>
  props.current === null
    ? null
    : Math.min(100, Math.max(0, Math.floor((props.current / props.requiredCount) * 100)))
)
</script>
<template>
  <div class="flex flex-wrap min-w-0 items-center gap-2 text-xs">
    <span class="shrink-0 whitespace-nowrap">{{ t('reward.progress', { current: current === null ? t('reward.notCalculated') : number(current), required: number(requiredCount) }) }}</span>
    <div role="progressbar" :aria-label="t('reward.progressLabel')" :aria-valuenow="percent ?? undefined" :aria-valuemin="0" :aria-valuemax="100" :aria-valuetext="current === null ? t('reward.notCalculated') : t('reward.progressAria', { current: number(current), required: number(requiredCount), percent: number(percent ?? 0) })" class="h-2 min-w-6 flex-1 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
      <div class="h-full rounded-full bg-blue-500 dark:bg-blue-400" :style="{ width: `${percent ?? 0}%` }" />
    </div>
    <span class="w-9 shrink-0 whitespace-nowrap text-right tabular-nums">{{ percent === null ? '—' : number(percent) }}%</span>
  </div>
</template>
