<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
import type { ActivityLikeState, ActivityLog } from '#shared/types/api'
import { parseLocalDate } from '#shared/utils/calendar'
import { categoryStyle } from '#shared/utils/categoryColor'
import noteIcon from '~/assets/icons/note-text-outline.svg'
import editIcon from '~/assets/icons/square-edit-outline.svg'

const props = defineProps<{
  log: ActivityLog
  state: ActivityLikeState
  pending?: boolean
  uncertain?: boolean
  waiting?: boolean
  waitSeconds: number
  error?: string
  editTo?: RouteLocationRaw
}>()
const emit = defineEmits<{ toggle: []; edit: [event: MouseEvent] }>()
const { user } = useAuth()
const { t } = useI18n()
const { intlLocale } = useUiLocale()
const own = computed(() => props.log.user_id === user.value?.id)
const badgeStyle = categoryStyle
const dateLabel = computed(() =>
  new Intl.DateTimeFormat(intlLocale.value, {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(parseLocalDate(props.log.activity_date))
)
const noteIconStyle = { maskImage: `url("${noteIcon}")` }
const editIconStyle = { maskImage: `url("${editIcon}")` }
</script>

<template>
  <article :data-log-id="log.id" class="block min-w-0 bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-slate-700 shadow-2xs space-y-2 relative transition" :class="own ? 'focus-within:ring-2 focus-within:ring-blue-500' : ''">
    <div class="flex flex-wrap gap-2 items-center justify-between">
      <div class="flex flex-wrap gap-2 items-center">
        <span class="px-2 py-0.5 text-xs font-semibold text-white rounded-full wrap-anywhere" :style="badgeStyle(log.category?.color_code)">{{ log.category?.name }}</span>
        <span class="text-xs font-bold text-slate-700 dark:text-slate-200 wrap-anywhere">{{ log.user?.display_name }}</span>
      </div>
      <span class="ml-auto text-xs text-slate-400 dark:text-slate-400 whitespace-nowrap">{{ dateLabel }} {{ log.activity_time ? log.activity_time.slice(0, 5) : '' }}</span>
    </div>
    <p class="text-sm text-slate-800 dark:text-slate-100 whitespace-pre-wrap wrap-anywhere font-normal">{{ log.content }}</p>
    <p v-if="log.note" class="flex items-start gap-1.5 text-xs text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-950 p-2 rounded-lg">
      <span aria-hidden="true" class="mt-0.5 h-4 w-4 shrink-0 bg-current [mask-size:contain] [mask-repeat:no-repeat] [mask-position:center]" :style="noteIconStyle" />
      <span class="sr-only">{{ t('activity.noteLabel') }}: </span>
      <span class="min-w-0 whitespace-pre-wrap wrap-anywhere">{{ log.note }}</span>
    </p>
    <ActivityImageView v-if="log.image" :image-id="log.image.id" :width="log.image.width" :height="log.image.height" />
    <div class="flex items-center justify-between">
      <ActivityLikeButton :state="state" :own="own" :pending="pending" :uncertain="uncertain" :waiting="waiting" @toggle="emit('toggle')" />
      <NuxtLink v-if="own" :to="editTo ?? { path: `/logs/${log.id}`, query: { mode: 'edit' } }" :aria-label="t('activity.editLabel', { date: dateLabel })" class="edit-link flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-blue-600 dark:text-blue-300 bg-blue-50 dark:bg-blue-950" @click="emit('edit', $event)">
        <span aria-hidden="true" class="h-5 w-5 bg-current [mask-size:contain] [mask-repeat:no-repeat] [mask-position:center]" :style="editIconStyle" />
      </NuxtLink>
    </div>
    <p v-if="error" role="alert" class="relative z-1 text-xs text-red-600 dark:text-red-300">{{ error }}</p>
    <p v-if="waiting && !own" role="status" class="text-xs text-slate-500 dark:text-slate-400">{{ t('activity.wait', { count: new Intl.NumberFormat(intlLocale).format(waitSeconds) }, waitSeconds) }}</p>
  </article>
</template>
