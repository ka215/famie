export const useApi = () => {
  const config = useRuntimeConfig()
  const token = useCookie<string | null>('auth_token')
  const { isMaintenance } = useMaintenance()
  const { effective } = useUiLocale()
  const t = useNuxtApp().$i18n.t

  const fetchApi = async <T>(
    request: string,
    options: Parameters<typeof $fetch>[1] = {},
    behavior: { redirectOnUnauthorized?: boolean } = {}
  ) => {
    const requestToken = token.value
    const requestLocale = effective.value
    return await $fetch<T>(request, {
      baseURL: config.public.apiEndpoint,
      ...options,
      retry: 0,
      headers: {
        Accept: 'application/json',
        ...(requestToken ? { Authorization: `Bearer ${requestToken}` } : {}),
        ...options.headers,
        'Accept-Language': requestLocale,
      },
      onResponse({ response }) {
        if (
          requestLocale !== effective.value &&
          response._data &&
          typeof response._data === 'object'
        ) {
          delete response._data.errors
          if (response._data.message)
            response._data.message = t(response.ok ? 'common.completed' : 'errors.operationFailed')
        }
      },
      onResponseError({ response }) {
        if (requestToken !== token.value) return
        if (response.status === 503 && response._data?.code === 'maintenance') {
          isMaintenance.value = true
        }
        if (response.status === 401 && !isMaintenance.value) {
          token.value = null
          useState('activity_like_operations').value = {}
          useState('activity_like_retry_until').value = 0
          useState('activity_like_owner').value = ''
          useState('auth_user').value = null
          useState('auth_membership').value = null
          if (behavior.redirectOnUnauthorized !== false) navigateTo('/login')
        }
      },
    })
  }

  return { fetchApi }
}
