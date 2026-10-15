<script setup lang="ts">
import type { TrophyColor } from '#shared/types/rewards'
import trophyIcon from '~/assets/icons/trophy-variant.svg'

defineProps<{ color: TrophyColor; labelOnly?: boolean; iconOnly?: boolean; badge?: boolean }>()
const labels = { gold: '金', silver: '銀', bronze: '銅' }
const colors = { gold: '#f59e0b', silver: '#8593a3', bronze: '#b87333' }
const trophyStyle = (color: TrophyColor) => ({
  backgroundColor: colors[color],
  maskImage: `url("${trophyIcon}")`,
  maskSize: 'contain',
  maskRepeat: 'no-repeat',
})
</script>
<template>
  <span class="inline-flex shrink-0 items-center gap-2 font-medium" :class="badge ? 'rounded-full px-2 py-0.5 text-white dark:text-slate-900' : ''" :style="badge ? { backgroundColor: colors[color] } : { color: colors[color] }" :aria-hidden="iconOnly ? true : undefined">
    <span v-if="!labelOnly" aria-hidden="true" class="inline-block h-6 w-6" :style="trophyStyle(color)" />
    <span v-if="!iconOnly" class="text-xs whitespace-nowrap">{{ labels[color] }}トロフィー</span>
  </span>
</template>
