<script setup lang="ts">
import type { ActivityLikeState } from '#shared/types/api'
import heart from '~/assets/icons/heart.svg'
import outline from '~/assets/icons/heart-outline.svg'

const props = defineProps<{
  state: ActivityLikeState
  own: boolean
  pending?: boolean
  uncertain?: boolean
  waiting?: boolean
}>()
const emit = defineEmits<{ toggle: [] }>()
const filled = computed(() => (props.own ? props.state.likes_count > 0 : props.state.liked_by_me))
const iconStyle = computed(() => ({ maskImage: `url("${filled.value ? heart : outline}")` }))
</script>

<template>
  <span v-if="own" class="relative z-1 inline-flex min-h-11 items-center gap-1.5 px-2" :aria-label="`受け取ったいいね：${state.likes_count}件`">
    <span aria-hidden="true" class="h-5 w-5 bg-current [mask-size:contain] [mask-repeat:no-repeat] [mask-position:center]" :class="state.likes_count > 0 ? 'text-pink-300 dark:text-pink-400' : 'text-slate-300 dark:text-slate-500'" :style="iconStyle" />
    <span v-if="state.likes_count > 0" aria-hidden="true" class="text-xs text-slate-500 dark:text-slate-400">{{ state.likes_count }}</span>
  </span>
  <button v-else type="button" :disabled="pending || waiting || !state.can_like" :aria-pressed="state.liked_by_me" :aria-busy="pending" :aria-label="`${uncertain ? 'いいねを再同期' : state.liked_by_me ? 'いいねを取り消す' : 'いいねする'}：${state.likes_count}件`" class="relative z-1 inline-flex min-h-11 min-w-11 items-center justify-center gap-1.5 rounded-lg px-2 text-xs transition hover:bg-rose-50 focus-visible:outline-2 focus-visible:outline-rose-500 disabled:cursor-wait dark:hover:bg-slate-700" :class="state.liked_by_me ? 'text-rose-500 dark:text-rose-400' : 'text-slate-400 dark:text-slate-400'" @click.stop="emit('toggle')">
    <span aria-hidden="true" class="h-5 w-5 bg-current [mask-size:contain] [mask-repeat:no-repeat] [mask-position:center]" :style="iconStyle" />
    <span aria-hidden="true">{{ state.likes_count }}</span>
    <span v-if="uncertain" class="text-slate-600 dark:text-slate-300">再同期</span>
  </button>
</template>
