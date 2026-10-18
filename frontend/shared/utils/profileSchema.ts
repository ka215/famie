import { z } from 'zod'
import ja from '../../i18n/locales/ja.json' with { type: 'json' }
import { trimLaravelString } from './laravelString.ts'

export const createProfileSchema = (translate: (key: string) => string) =>
  z.object({
    display_name: z
      .string()
      .transform(trimLaravelString)
      .refine((value) => value.length > 0, {
        error: () => translate('validation.displayNameRequired'),
      })
      .refine((value) => Array.from(value).length <= 50, {
        error: () => translate('validation.displayNameMax'),
      }),
  })

export const profileSchema = createProfileSchema((key) =>
  key === 'validation.displayNameRequired'
    ? ja.validation.displayNameRequired
    : ja.validation.displayNameMax
)

export type ProfileForm = z.infer<typeof profileSchema>
