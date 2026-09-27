<script setup lang="ts">
import {
  buildWeekDays,
  formatLocalDate,
  getCalendarDisplayLocale,
  parseLocalDate,
} from '#shared/utils/calendar'
import arrowLeftIcon from '~/assets/icons/arrow-left.svg'
import arrowRightIcon from '~/assets/icons/arrow-right.svg'

const props = defineProps<{
  selectedDate: string
  locale: string
  firstDay: number
}>()

const emit = defineEmits<{
  previous: []
  next: []
  select: [date: string]
}>()

const arrowLeftStyle = { maskImage: `url("${arrowLeftIcon}")` }
const arrowRightStyle = { maskImage: `url("${arrowRightIcon}")` }
const today = computed(() => formatLocalDate(new Date()))
const displayLocale = computed(() => getCalendarDisplayLocale(props.locale))
const days = computed(() => buildWeekDays(parseLocalDate(props.selectedDate), props.firstDay))

const weekdayLabel = (date: string) =>
  new Intl.DateTimeFormat(displayLocale.value, { weekday: 'short' }).format(parseLocalDate(date))

const accessibleLabel = (date: string) =>
  new Intl.DateTimeFormat(props.locale, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    weekday: 'short',
  }).format(parseLocalDate(date))

const weekdayClass = (dayOfWeek: number) => {
  if (dayOfWeek === 0) return 'text-red-600'
  if (dayOfWeek === 6) return 'text-blue-600'
  return 'text-slate-600'
}
</script>

<template>
  <section aria-label="週カレンダー" class="rounded-xl border border-slate-200 bg-white p-2">
    <div class="grid grid-cols-[2.5rem_minmax(0,1fr)_2.5rem] items-stretch gap-1">
      <button type="button" aria-label="前週" class="week-nav-button" @click="emit('previous')">
        <span aria-hidden="true" class="week-nav-icon" :style="arrowLeftStyle" />
      </button>

      <div class="grid min-w-0 grid-cols-7 gap-0.5">
        <button
          v-for="day in days"
          :key="day.date"
          type="button"
          :aria-label="accessibleLabel(day.date)"
          :aria-current="day.date === today ? 'date' : undefined"
          :aria-pressed="day.date === selectedDate"
          class="flex min-w-0 flex-col items-center gap-1 rounded-lg border border-transparent py-1.5 text-xs font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-blue-600"
          :class="[
            weekdayClass(day.dayOfWeek),
            day.date === selectedDate ? 'border-blue-300 bg-blue-50 shadow-xs' : '',
          ]"
          @click="emit('select', day.date)"
        >
          <span>{{ weekdayLabel(day.date) }}</span>
          <span
            class="flex h-6 w-6 items-center justify-center rounded-full"
            :class="day.date === today ? 'bg-violet-600 text-white' : ''"
          >
            {{ day.day }}
          </span>
        </button>
      </div>

      <button type="button" aria-label="次週" class="week-nav-button" @click="emit('next')">
        <span aria-hidden="true" class="week-nav-icon" :style="arrowRightStyle" />
      </button>
    </div>
  </section>
</template>

<style scoped>
.week-nav-button {
  display: flex;
  min-height: 3.5rem;
  align-items: center;
  justify-content: center;
  border-radius: 0.625rem;
  color: var(--color-slate-600);
}

.week-nav-button:focus-visible {
  outline: 2px solid var(--color-blue-600);
  outline-offset: 1px;
}

.week-nav-icon {
  width: 1.125rem;
  height: 1.125rem;
  background: currentColor;
  mask-position: center;
  mask-repeat: no-repeat;
  mask-size: contain;
}
</style>
