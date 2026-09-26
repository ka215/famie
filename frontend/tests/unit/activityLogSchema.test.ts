import assert from 'node:assert/strict'
import { test } from 'node:test'
import { activityLogSchema } from '../../shared/utils/activityLogSchema.ts'

const validForm = {
  category_id: 1,
  activity_date: '2026-09-25',
  activity_time: '12:34',
  content: '活動内容',
  note: 'メモ',
}

test('カテゴリは正の整数を必須とする', () => {
  for (const category_id of [null, 0, -1, 1.5, '1', NaN]) {
    const result = activityLogSchema.safeParse({ ...validForm, category_id })
    assert.equal(result.success, false)
    if (!result.success) assert.match(result.error.issues[0].message, /カテゴリ/)
  }
  assert.equal(activityLogSchema.safeParse(validForm).success, true)
})

test('実施日は必須で、存在する年月日のみ許可する', () => {
  for (const activity_date of [
    '',
    '　',
    '2026-02-29',
    '2026-04-31',
    '2026-13-01',
    '0000-01-01',
    '2026-9-1',
    'tomorrow',
  ]) {
    const result = activityLogSchema.safeParse({ ...validForm, activity_date })
    assert.equal(result.success, false, activity_date)
    if (!result.success) assert.match(result.error.issues[0].message, /実施日/)
  }
  for (const activity_date of ['2024-02-29', '2000-02-29', '2026-12-31']) {
    assert.equal(activityLogSchema.safeParse({ ...validForm, activity_date }).success, true)
  }
  assert.equal(
    activityLogSchema.safeParse({ ...validForm, activity_date: '1900-02-29' }).success,
    false
  )
})

test('時刻は任意、入力時は分単位の24時間表記に限定する', () => {
  for (const activity_time of ['24:00', '12:60', '9:00', '12:00:00', 'noon']) {
    const result = activityLogSchema.safeParse({ ...validForm, activity_time })
    assert.equal(result.success, false)
    if (!result.success) assert.match(result.error.issues[0].message, /時刻/)
  }
  for (const activity_time of ['00:00', '23:59', '12:34']) {
    assert.equal(activityLogSchema.safeParse({ ...validForm, activity_time }).success, true)
  }
})

test('活動内容は空白だけを拒否し、内容とメモはUnicodeコードポイントで1000文字まで許可する', () => {
  for (const content of ['', ' \t\n　', '\u0085\u200B\uFEFF\u00AD']) {
    const result = activityLogSchema.safeParse({ ...validForm, content })
    assert.equal(result.success, false)
    if (!result.success)
      assert.equal(result.error.issues[0].message, '活動内容を入力してください。')
  }
  for (const field of ['content', 'note']) {
    for (const character of ['あ', '😀']) {
      assert.equal(
        activityLogSchema.safeParse({ ...validForm, [field]: character.repeat(1000) }).success,
        true
      )
      const result = activityLogSchema.safeParse({ ...validForm, [field]: character.repeat(1001) })
      assert.equal(result.success, false)
      if (!result.success) assert.match(result.error.issues[0].message, /1000文字以内/)
    }
  }
})

test('Laravelと同じ前後空白除去を行い、任意の空欄をnullへ変換する', () => {
  const result = activityLogSchema.parse({
    ...validForm,
    activity_date: '　2026-09-25 ',
    activity_time: ' \t',
    content: '\u200B　活動\n内容 👨‍👩‍👧‍👦　\u00AD',
    note: '\u200B\uFEFF　',
  })
  assert.deepEqual(result, {
    category_id: 1,
    activity_date: '2026-09-25',
    activity_time: null,
    content: '活動\n内容 👨‍👩‍👧‍👦',
    note: null,
  })
  const zero = activityLogSchema.parse({ ...validForm, content: '0', note: '0' })
  assert.equal(zero.content, '0')
  assert.equal(zero.note, '0')
})
