export interface CalendarCell {
  date: string
  day: number
  inCurrentMonth: boolean
}

export interface WeekInfoLike {
  firstDay: number
}

const dateParts = (value: string) => value.split('-').map(Number)

export const formatLocalDate = (date: Date): string => {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

export const parseLocalDate = (value: string): Date => {
  const [year = 0, month = 1, day = 1] = dateParts(value)
  return new Date(year, month - 1, day, 12)
}

export const getPreferredLocale = (languages?: readonly string[], language?: string): string =>
  languages?.find(Boolean) || language || 'ja-JP'

export const getCalendarDisplayLocale = (locale: string): 'ja-JP' | 'en-US' =>
  locale.toLowerCase().startsWith('ja') ? 'ja-JP' : 'en-US'

export const getFirstDayOfWeek = (
  locale: string,
  localeFactory: (locale: string) => WeekInfoLike = (value) => {
    const localeObject = new Intl.Locale(value) as Intl.Locale & {
      getWeekInfo?: () => WeekInfoLike
      weekInfo?: WeekInfoLike
    }
    return localeObject.getWeekInfo?.() ?? localeObject.weekInfo ?? { firstDay: 7 }
  }
): number => {
  try {
    const firstDay = localeFactory(locale).firstDay
    return Number.isInteger(firstDay) && firstDay >= 1 && firstDay <= 7 ? firstDay % 7 : 0
  } catch {
    return 0
  }
}

export const getWeekRange = (date: Date, firstDay: number): { from: string; to: string } => {
  const from = new Date(date.getFullYear(), date.getMonth(), date.getDate(), 12)
  const offset = (from.getDay() - firstDay + 7) % 7
  from.setDate(from.getDate() - offset)
  const to = new Date(from)
  to.setDate(from.getDate() + 6)
  return { from: formatLocalDate(from), to: formatLocalDate(to) }
}

export const getMonthRange = (year: number, month: number): { from: string; to: string } => ({
  from: formatLocalDate(new Date(year, month, 1, 12)),
  to: formatLocalDate(new Date(year, month + 1, 0, 12)),
})

export const buildCalendarCells = (
  year: number,
  month: number,
  firstDay: number
): CalendarCell[] => {
  const first = new Date(year, month, 1, 12)
  const offset = (first.getDay() - firstDay + 7) % 7
  const start = new Date(year, month, 1 - offset, 12)
  const last = new Date(year, month + 1, 0, 12)
  const lastOffset = (firstDay + 6 - last.getDay() + 7) % 7
  const numberOfDays = offset + last.getDate() + lastOffset

  return Array.from({ length: numberOfDays }, (_, index) => {
    const date = new Date(start)
    date.setDate(start.getDate() + index)
    return {
      date: formatLocalDate(date),
      day: date.getDate(),
      inCurrentMonth: date.getFullYear() === year && date.getMonth() === month,
    }
  })
}

export const countActivitiesByDate = (dates: string[]): Record<string, number> =>
  dates.reduce<Record<string, number>>((counts, date) => {
    counts[date] = (counts[date] ?? 0) + 1
    return counts
  }, {})
