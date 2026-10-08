export const useApi = () => {
  const config = useRuntimeConfig()
  const token = useCookie<string | null>('auth_token')
  const { isMaintenance } = useMaintenance()

  const fetchApi = async <T>(request: string, options: Parameters<typeof $fetch>[1] = {}) => {
    return await $fetch<T>(request, {
      baseURL: config.public.apiEndpoint,
      ...options,
      retry: 0,
      headers: {
        Accept: 'application/json',
        ...(token.value ? { Authorization: `Bearer ${token.value}` } : {}),
        ...options.headers,
      },
      onResponseError({ response }) {
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
          navigateTo('/login')
        }
      },
    })
  }

  return { fetchApi }
}
