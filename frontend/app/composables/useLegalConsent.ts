import type { LegalDocument, LegalInput } from '#shared/types/api'

export const useLegalConsent = () => {
  const { locale, t } = useI18n()
  const { fetchApi } = useApi()
  const documents = ref<LegalDocument[]>([])
  const enabled = ref(false)
  const loaded = ref(false)
  const error = ref('')
  const termsAccepted = ref(false)
  const privacyAcknowledged = ref(false)
  const language = computed(() => (locale.value === 'ja' ? 'ja' : 'en'))
  const ready = computed(
    () => loaded.value && (!enabled.value || (termsAccepted.value && privacyAcknowledged.value))
  )
  const reset = () => {
    termsAccepted.value = false
    privacyAcknowledged.value = false
  }
  watch(language, reset)
  const load = async () => {
    loaded.value = false
    error.value = ''
    reset()
    try {
      const response = await fetchApi<{
        data: LegalDocument[]
        meta: { acceptance_enabled: boolean }
      }>('/legal/documents', { cache: 'no-store' })
      for (const id of ['terms', 'privacy']) {
        const document = response.data.find((entry) => entry.id === id)
        if (
          !document ||
          !/^\d+\.\d+\.\d+$/.test(document.version) ||
          !document.translations.ja ||
          !document.translations.en
        )
          throw new Error('Incomplete legal documents')
      }
      documents.value = response.data
      enabled.value = response.meta.acceptance_enabled
      loaded.value = true
    } catch {
      error.value = t('legal.loadFailed')
    }
  }
  const payload = (): LegalInput => ({
    terms: {
      version: documents.value.find((entry) => entry.id === 'terms')?.version ?? '',
      locale: language.value,
      accepted: termsAccepted.value,
    },
    privacy: {
      version: documents.value.find((entry) => entry.id === 'privacy')?.version ?? '',
      locale: language.value,
      acknowledged: privacyAcknowledged.value,
    },
  })
  onMounted(load)

  return {
    documents,
    enabled,
    loaded,
    error,
    termsAccepted,
    privacyAcknowledged,
    language,
    ready,
    load,
    payload,
  }
}
