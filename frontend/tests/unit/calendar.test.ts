import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
  buildCalendarCells,
  countActivitiesByDate,
  formatLocalDate,
  getCalendarDisplayLocale,
  getFirstDayOfWeek,
  getMonthRange,
  getPreferredLocale,
  getWeekRange,
} from '../../shared/utils/calendar.ts'

test('優先ロケールを選び、取得不能時は日本語へフォールバックする', () => {
  assert.equal(getPreferredLocale(['ja-JP', 'en-US'], 'en-US'), 'ja-JP')
  assert.equal(getPreferredLocale([], 'en-GB'), 'en-GB')
  assert.equal(getPreferredLocale(), 'ja-JP')
})

test('カレンダーの表示言語は日本語ロケールだけ日本語、それ以外は英語にする', () => {
  assert.equal(getCalendarDisplayLocale('ja-JP'), 'ja-JP')
  assert.equal(getCalendarDisplayLocale('ja'), 'ja-JP')
  assert.equal(getCalendarDisplayLocale('en-US'), 'en-US')
  assert.equal(getCalendarDisplayLocale('fr-FR'), 'en-US')
})

test('ロケールの週情報を0始まりへ変換し、不正値は日曜へフォールバックする', () => {
  assert.equal(
    getFirstDayOfWeek('ja-JP', () => ({ firstDay: 7 })),
    0
  )
  assert.equal(
    getFirstDayOfWeek('en-GB', () => ({ firstDay: 1 })),
    1
  )
  assert.equal(
    getFirstDayOfWeek('invalid', () => ({ firstDay: 9 })),
    0
  )
  assert.equal(
    getFirstDayOfWeek('invalid', () => {
      throw new RangeError('invalid locale')
    }),
    0
  )
})

test('今週の範囲はロケールの週開始曜日に従う', () => {
  const wednesday = new Date(2026, 8, 30, 12)
  assert.deepEqual(getWeekRange(wednesday, 0), { from: '2026-09-27', to: '2026-10-03' })
  assert.deepEqual(getWeekRange(wednesday, 1), { from: '2026-09-28', to: '2026-10-04' })
})

test('月範囲とローカル日付を月境界・うるう年でも正しく生成する', () => {
  assert.equal(formatLocalDate(new Date(2026, 0, 1, 12)), '2026-01-01')
  assert.deepEqual(getMonthRange(2024, 1), { from: '2024-02-01', to: '2024-02-29' })
  assert.deepEqual(getMonthRange(2026, 11), { from: '2026-12-01', to: '2026-12-31' })
})

test('カレンダーは週開始曜日に合わせて月を週単位で囲む', () => {
  const sundayFirst = buildCalendarCells(2026, 8, 0)
  assert.equal(sundayFirst[0]?.date, '2026-08-30')
  assert.equal(sundayFirst.at(-1)?.date, '2026-10-03')
  assert.equal(sundayFirst.length, 35)

  const mondayFirst = buildCalendarCells(2026, 8, 1)
  assert.equal(mondayFirst[0]?.date, '2026-08-31')
  assert.equal(mondayFirst.at(-1)?.date, '2026-10-04')
  assert.equal(mondayFirst.length, 35)
})

test('日付ごとのアクティビティ件数を集計する', () => {
  assert.deepEqual(countActivitiesByDate(['2026-09-01', '2026-09-01', '2026-09-30']), {
    '2026-09-01': 2,
    '2026-09-30': 1,
  })
})
