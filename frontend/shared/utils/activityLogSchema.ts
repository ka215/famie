import { z } from 'zod'
import ja from '../../i18n/locales/ja.json' with { type: 'json' }
import { trimLaravelString } from './laravelString.ts'

const trimmedString = z.string().transform(trimLaravelString)

export const createActivityLogSchema = (translate: (key: string) => string) => {
  const categoryMessage = { error: () => translate('activity.categoryRequired') }
  return z.object({
    category_id: z
      .number(categoryMessage)
      .nullable()
      .pipe(z.number(categoryMessage).int(categoryMessage).positive(categoryMessage)),
    activity_date: trimmedString
      .pipe(z.string().min(1, { error: () => translate('activity.dateRequired') }))
      .pipe(z.iso.date({ error: () => translate('activity.dateInvalid') }))
      .refine((value) => !value.startsWith('0000-'), {
        error: () => translate('activity.dateInvalid'),
      }),
    activity_time: trimmedString
      .refine((value) => value === '' || /^([01]\d|2[0-3]):[0-5]\d$/.test(value), {
        error: () => translate('activity.timeInvalid'),
      })
      .transform((value) => value || null),
    content: trimmedString
      .refine((value) => value.length > 0, { error: () => translate('activity.contentRequired') })
      .refine((value) => Array.from(value).length <= 1000, {
        error: () => translate('activity.contentMax'),
      }),
    note: trimmedString
      .refine((value) => Array.from(value).length <= 1000, {
        error: () => translate('activity.noteMax'),
      })
      .transform((value) => value || null),
  })
}

export const activityLogSchema = createActivityLogSchema(
  (key) => (ja.activity as Record<string, string>)[key.replace('activity.', '')] ?? key
)

export type ActivityLogForm = z.input<typeof activityLogSchema>
