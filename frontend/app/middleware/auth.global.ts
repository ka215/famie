export default defineNuxtRouteMiddleware(async (to) => {
  const token = useCookie<string | null>('auth_token')
  const { user, fetchUser } = useAuth()

  if (!token.value && to.path !== '/login') {
    return navigateTo('/login')
  }

  if (token.value && !user.value && to.path !== '/login') {
    try {
      const fetchedUser = await fetchUser()
      if (!fetchedUser) {
        return navigateTo('/login')
      }
    } catch {
      return abortNavigation(
        createError({
          statusCode: 503,
          statusMessage: '認証情報を確認できませんでした。通信環境を確認してください。',
        })
      )
    }
  }

  if (token.value && to.path === '/login') {
    return navigateTo('/')
  }
})
