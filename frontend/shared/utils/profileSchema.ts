import { z } from 'zod'
import { trimLaravelString } from './laravelString.ts'

export const profileSchema = z.object({
  display_name: z
    .string()
    .transform(trimLaravelString)
    .refine((value) => value.length > 0, '表示名を入力してください。')
    .refine((value) => Array.from(value).length <= 50, '表示名は50文字以内で入力してください。'),
})

export type ProfileForm = z.infer<typeof profileSchema>
