import type { User } from '#shared/types/api'
import {
  browserLocale,
  fallbackLocale,
  guestLocaleKey,
  isUiLocale,
  type UiLocalePreference,
  uiLocales,
} from '#shared/utils/locale'

export const useUiLocale = () => {
  const { locale } = useNuxtApp().$i18n
  const user = useState<User | null>('auth_user', () => null)
  const guest = useState<UiLocalePreference>('ui_locale_guest', () => null)
  const storageError = useState('ui_locale_storage_error', () => false)
  const automatic = useState('ui_locale_browser', browserLocale)
  const effective = computed(() => {
    const preference = user.value ? user.value.ui_locale : guest.value
    if (preference == null) return automatic.value
    return isUiLocale(preference) ? preference : fallbackLocale
  })
  const setGuest = (value: UiLocalePreference) => {
    if (user.value) return
    guest.value = value
    storageError.value = false
    try {
      localStorage.setItem(guestLocaleKey, value ?? 'auto')
    } catch {
      storageError.value = true
    }
  }
  return {
    effective,
    guest,
    setGuest,
    storageError,
    choices: uiLocales,
    automatic,
    locale,
    intlLocale: computed(
      () => uiLocales.find((item) => item.code === effective.value)?.intl ?? fallbackLocale
    ),
  }
}
