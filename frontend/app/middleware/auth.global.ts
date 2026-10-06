export default defineNuxtRouteMiddleware(async (to) => {
  const token = useCookie<string | null>('auth_token')
  const { user, fetchUser } = useAuth()
  const { isMaintenance } = useMaintenance()

  if (isMaintenance.value) return

  const accountPaths = ['/verify-email', '/forgot-password', '/reset-password']
  const publicPaths = ['/login', '/register', ...accountPaths]

  if (!token.value && !publicPaths.includes(to.path)) {
    return navigateTo('/login')
  }

  if (!token.value && publicPaths.includes(to.path)) {
    const { fetchApi } = useApi()
    try {
      const status = await fetchApi<{ status: string }>('/status', { cache: 'no-store' })
      if (status.status !== 'ok') throw new Error('Unexpected status')
    } catch {
      if (isMaintenance.value) return
      return abortNavigation(
        createError({
          statusCode: 503,
          statusMessage: 'サービスの状態を確認できませんでした。通信環境を確認してください。',
        })
      )
    }
  }

  if (token.value && !user.value && !publicPaths.includes(to.path)) {
    try {
      const fetchedUser = await fetchUser()
      if (!fetchedUser) {
        return navigateTo('/login')
      }
    } catch {
      if (isMaintenance.value) return
      return abortNavigation(
        createError({
          statusCode: 503,
          statusMessage: '認証情報を確認できませんでした。通信環境を確認してください。',
        })
      )
    }
  }

  if (token.value && ['/login', '/register'].includes(to.path)) {
    return navigateTo('/')
  }
})
