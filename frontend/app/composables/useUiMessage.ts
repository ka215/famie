export const useUiMessage = (initial = '') => {
  const { locale, t } = useNuxtApp().$i18n
  const raw = ref(initial)
  const local = shallowRef<(() => string) | null>(null)
  const message = computed({
    get: () => local.value?.() ?? raw.value,
    set: (value: string) => {
      local.value = null
      raw.value = value
    },
  })
  watch(locale, () => {
    raw.value = ''
  })
  return Object.assign(message, {
    setKey: (key: string) => {
      local.value = () => t(key)
    },
    setLocal: (translate: () => string) => {
      local.value = translate
    },
  })
}
