import { fallbackLocale, type UiLocale } from '../shared/utils/locale'
import en from './locales/en.json'
import ja from './locales/ja.json'

export default defineI18nConfig(() => ({
  legacy: false,
  locale: fallbackLocale,
  fallbackLocale,
  messages: { ja, en } satisfies Record<UiLocale, Record<string, unknown>>,
  missingWarn: import.meta.dev,
  fallbackWarn: import.meta.dev,
}))
