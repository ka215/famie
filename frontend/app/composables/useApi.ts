export const useApi = () => {
  const config = useRuntimeConfig()
  const token = useCookie<string | null>('auth_token')

  const fetchApi = async <T>(request: string, options: Parameters<typeof $fetch>[1] = {}) => {
    return await $fetch<T>(request, {
      baseURL: config.public.apiBase,
      ...options,
      headers: {
        Accept: 'application/json',
        ...(token.value ? { Authorization: `Bearer ${token.value}` } : {}),
        ...options.headers,
      },
      onResponseError({ response }) {
        if (response.status === 401) {
          token.value = null
          navigateTo('/login')
        }
      },
    })
  }

  return { fetchApi }
}
