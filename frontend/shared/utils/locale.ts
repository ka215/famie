export const uiLocales = [
  { code: 'ja', name: '日本語', intl: 'ja-JP', translation: 'ja' },
  { code: 'en', name: 'English', intl: 'en-US', translation: 'en' },
] as const

export type UiLocale = (typeof uiLocales)[number]['code']
export type UiLocalePreference = UiLocale | null
export const fallbackLocale: UiLocale = 'en'
export const guestLocaleKey = 'famie.uiLocale.guest'

export const isUiLocale = (value: unknown): value is UiLocale =>
  uiLocales.some((locale) => locale.code === value)

export function localeFromTag(tag: unknown): UiLocale {
  if (typeof tag !== 'string') return fallbackLocale
  try {
    const canonical = Intl.getCanonicalLocales(tag)[0]
    if (!canonical) return fallbackLocale
    const exact = uiLocales.find((locale) => locale.code.toLowerCase() === canonical.toLowerCase())
    if (exact) return exact.code
    const language = new Intl.Locale(canonical).language
    return uiLocales.find((locale) => locale.code === language)?.code ?? fallbackLocale
  } catch {
    return fallbackLocale
  }
}

export function browserLocale(): UiLocale {
  if (typeof navigator === 'undefined') return fallbackLocale
  try {
    return localeFromTag(navigator.languages?.[0] || navigator.language)
  } catch {
    return fallbackLocale
  }
}
