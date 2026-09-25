import { z } from 'zod'
import { trimLaravelString } from './laravelString.ts'

const trimmedString = z.string().transform(trimLaravelString)
const categoryMessage = 'カテゴリを選択してください。'

export const activityLogSchema = z.object({
  category_id: z
    .number({ error: categoryMessage })
    .nullable()
    .pipe(z.number({ error: categoryMessage }).int(categoryMessage).positive(categoryMessage)),
  activity_date: trimmedString
    .pipe(z.string().min(1, '実施日を入力してください。'))
    .pipe(z.iso.date({ error: '実施日は有効な日付で入力してください。' }))
    .refine((value) => !value.startsWith('0000-'), '実施日は有効な日付で入力してください。'),
  activity_time: trimmedString
    .refine(
      (value) => value === '' || /^([01]\d|2[0-3]):[0-5]\d$/.test(value),
      '時刻は00:00〜23:59の形式で入力してください。'
    )
    .transform((value) => value || null),
  content: trimmedString
    .refine((value) => value.length > 0, '活動内容を入力してください。')
    .refine(
      (value) => Array.from(value).length <= 1000,
      '活動内容は1000文字以内で入力してください。'
    ),
  note: trimmedString
    .refine(
      (value) => Array.from(value).length <= 1000,
      '補足メモは1000文字以内で入力してください。'
    )
    .transform((value) => value || null),
})

export type ActivityLogForm = z.input<typeof activityLogSchema>
