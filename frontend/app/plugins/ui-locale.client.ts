import type { User } from '#shared/types/api'
import { browserLocale, fallbackLocale, guestLocaleKey, isUiLocale } from '#shared/utils/locale'

export default defineNuxtPlugin((nuxtApp) => {
  const { guest, automatic, effective, locale } = useUiLocale()
  try {
    const saved = localStorage.getItem(guestLocaleKey)
    guest.value =
      saved === null || saved === 'auto' ? null : isUiLocale(saved) ? saved : fallbackLocale
  } catch {
    guest.value = null
  }
  const updateBrowser = () => {
    automatic.value = browserLocale()
  }
  const syncGuest = (event: StorageEvent) => {
    if (event.key !== guestLocaleKey && event.key !== null) return
    guest.value =
      event.newValue === null || event.newValue === 'auto'
        ? null
        : isUiLocale(event.newValue)
          ? event.newValue
          : fallbackLocale
  }
  watch(
    effective,
    (value) => {
      locale.value = value
    },
    { immediate: true, flush: 'sync' }
  )
  watch(useState<User | null>('auth_user'), updateBrowser)
  window.addEventListener('languagechange', updateBrowser)
  window.addEventListener('focus', updateBrowser)
  const onVisible = () => {
    if (!document.hidden) updateBrowser()
  }
  document.addEventListener('visibilitychange', onVisible)
  window.addEventListener('storage', syncGuest)
  nuxtApp.vueApp.onUnmount(() => {
    window.removeEventListener('languagechange', updateBrowser)
    window.removeEventListener('focus', updateBrowser)
    document.removeEventListener('visibilitychange', onVisible)
    window.removeEventListener('storage', syncGuest)
  })
})
