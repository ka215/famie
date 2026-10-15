<script setup lang="ts">
const props = defineProps<{ current: number | null; requiredCount: number }>()
const percent = computed(() =>
  props.current === null
    ? null
    : Math.min(100, Math.max(0, Math.floor((props.current / props.requiredCount) * 100)))
)
</script>
<template>
  <div class="flex min-w-0 items-center gap-2 text-xs">
    <span class="shrink-0 whitespace-nowrap">現在 {{ current ?? '未集計' }}/{{ requiredCount }}</span>
    <div role="progressbar" aria-label="リワードの進捗" :aria-valuenow="percent ?? undefined" :aria-valuemin="0" :aria-valuemax="100" :aria-valuetext="current === null ? '未集計' : `${current}/${requiredCount}（${percent}%）`" class="h-2 min-w-6 flex-1 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
      <div class="h-full rounded-full bg-blue-500 dark:bg-blue-400" :style="{ width: `${percent ?? 0}%` }" />
    </div>
    <span class="w-9 shrink-0 whitespace-nowrap text-right tabular-nums">{{ percent === null ? '—' : percent }}%</span>
  </div>
</template>
