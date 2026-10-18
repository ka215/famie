export default defineNuxtRouteMiddleware(async (to) => {
  const t = useNuxtApp().$i18n.t
  const token = useCookie<string | null>('auth_token')
  const { user, fetchUser } = useAuth()
  const { isMaintenance } = useMaintenance()

  if (isMaintenance.value) return

  const accountPaths = ['/verify-email', '/forgot-password', '/reset-password']
  const publicPaths = ['/login', '/register', ...accountPaths]
  // Apache adds a trailing slash when serving prerendered page directories.
  const path = to.path.replace(/\/+$/, '') || '/'

  if (!token.value && !publicPaths.includes(path)) {
    return navigateTo('/login')
  }

  if (!token.value && publicPaths.includes(path)) {
    const { fetchApi } = useApi()
    try {
      const status = await fetchApi<{ status: string }>('/status', { cache: 'no-store' })
      if (status.status !== 'ok') throw new Error('Unexpected status')
    } catch {
      if (isMaintenance.value) return
      return abortNavigation(
        createError({
          statusCode: 503,
          statusMessage: t('errors.statusCheckFailed'),
          data: { reason: 'status-check' },
        })
      )
    }
  }

  if (token.value && !user.value && !['/login', '/register'].includes(path)) {
    try {
      const fetchedUser = await fetchUser(accountPaths.includes(path))
      if (!fetchedUser) {
        if (accountPaths.includes(path)) return
        return navigateTo('/login')
      }
    } catch {
      if (isMaintenance.value) return
      return abortNavigation(
        createError({
          statusCode: 503,
          statusMessage: t('errors.authCheckFailed'),
          data: { reason: 'auth-check' },
        })
      )
    }
  }

  if (token.value && ['/login', '/register'].includes(path)) {
    return navigateTo('/')
  }
})
