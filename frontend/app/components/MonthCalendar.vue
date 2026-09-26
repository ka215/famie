<script setup lang="ts">
import {
  buildCalendarCells,
  formatLocalDate,
  getCalendarDisplayLocale,
  parseLocalDate,
} from '#shared/utils/calendar'
import arrowLeftIcon from '~/assets/icons/arrow-left.svg'
import arrowRightIcon from '~/assets/icons/arrow-right.svg'

const arrowLeftStyle = { maskImage: `url("${arrowLeftIcon}")` }
const arrowRightStyle = { maskImage: `url("${arrowRightIcon}")` }

const props = defineProps<{
  year: number
  month: number
  locale: string
  firstDay: number
  counts: Record<string, number>
  isLoading: boolean
  selectedDate?: string | null
}>()

const emit = defineEmits<{
  previous: []
  next: []
  current: []
  select: [date: string, inCurrentMonth: boolean]
}>()

const cells = computed(() => buildCalendarCells(props.year, props.month, props.firstDay))
const today = computed(() => formatLocalDate(new Date()))
const displayLocale = computed(() => getCalendarDisplayLocale(props.locale))
const monthLabel = computed(() =>
  new Intl.DateTimeFormat(displayLocale.value, { month: 'long' }).format(
    new Date(props.year, props.month, 1, 12)
  )
)
const weekdays = computed(() => {
  const sunday = new Date(2026, 0, 4, 12)
  return Array.from({ length: 7 }, (_, index) => {
    const day = (props.firstDay + index) % 7
    const date = new Date(sunday)
    date.setDate(sunday.getDate() + day)
    return {
      day,
      label: new Intl.DateTimeFormat(displayLocale.value, { weekday: 'short' }).format(date),
    }
  })
})

const weekdayTextClass = (day: number) => {
  if (day === 0) return 'text-red-600'
  if (day === 6) return 'text-blue-600'
  return 'text-slate-500'
}

const calendarTileClass = (date: string, hasActivities: boolean) => {
  if (hasActivities) return 'border-amber-300 bg-amber-100'
  const day = parseLocalDate(date).getDay()
  if (day === 0) return 'border-red-100 bg-red-50'
  if (day === 6) return 'border-blue-100 bg-blue-50'
  return 'border-slate-200 bg-white'
}

const accessibleLabel = (date: string) => {
  const count = props.counts[date] ?? 0
  const label = new Intl.DateTimeFormat(props.locale, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    weekday: 'short',
  }).format(parseLocalDate(date))
  return count > 0 ? `${label}、アクティビティ${count}件` : `${label}、アクティビティなし`
}
</script>

<template>
  <section aria-label="月間カレンダー" class="space-y-3">
    <div class="flex items-center justify-between gap-2">
      <button type="button" aria-label="前月" class="calendar-nav-button" @click="emit('previous')">
        <span aria-hidden="true" class="calendar-nav-icon" :style="arrowLeftStyle" />
      </button>
      <div class="text-center">
        <h2 class="text-base font-bold text-slate-800" aria-live="polite">{{ monthLabel }}</h2>
        <button type="button" class="text-xs font-medium text-blue-600" @click="emit('current')">
          今月へ戻る
        </button>
      </div>
      <button type="button" aria-label="次月" class="calendar-nav-button" @click="emit('next')">
        <span aria-hidden="true" class="calendar-nav-icon" :style="arrowRightStyle" />
      </button>
    </div>

    <div class="grid grid-cols-7 text-center" aria-hidden="true">
      <span
        v-for="weekday in weekdays"
        :key="weekday.day"
        class="py-1 text-xs font-semibold"
        :class="weekdayTextClass(weekday.day)"
      >
        {{ weekday.label }}
      </span>
    </div>

    <div class="grid grid-cols-7 gap-1" :aria-busy="isLoading">
      <button
        v-for="cell in cells"
        :key="cell.date"
        type="button"
        :aria-label="accessibleLabel(cell.date)"
        :aria-current="cell.date === today ? 'date' : undefined"
        :aria-pressed="cell.date === selectedDate"
        class="relative flex aspect-square min-w-0 flex-col items-center justify-center rounded-lg border text-sm transition focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-blue-600"
        :class="[
          calendarTileClass(cell.date, Boolean(counts[cell.date])),
          cell.inCurrentMonth ? 'text-slate-700' : 'text-slate-400 opacity-60',
          cell.date === today ? 'ring-2 ring-violet-500' : '',
          cell.date === selectedDate ? 'border-blue-500 ring-2 ring-blue-400 text-blue-800' : '',
        ]"
        @click="emit('select', cell.date, cell.inCurrentMonth)"
      >
        <span>{{ cell.day }}</span>
        <span
          v-if="cell.inCurrentMonth && counts[cell.date]"
          class="absolute bottom-0.5 right-0.5 min-w-4 rounded-full bg-blue-600 px-1 text-center text-2xs font-bold leading-4 text-white"
          aria-hidden="true"
        >
          {{ counts[cell.date] }}
        </span>
      </button>
    </div>

    <p v-if="isLoading" class="text-center text-xs text-slate-500" role="status">
      月の記録を読み込み中...
    </p>
  </section>
</template>

<style scoped>
.calendar-nav-button {
  display: flex;
  width: 2.75rem;
  height: 2.75rem;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--color-slate-300);
  border-radius: 0.75rem;
  background: white;
  color: var(--color-slate-700);
}

.calendar-nav-icon {
  width: 1.25rem;
  height: 1.25rem;
  background: currentColor;
  mask-position: center;
  mask-repeat: no-repeat;
  mask-size: contain;
}
</style>
