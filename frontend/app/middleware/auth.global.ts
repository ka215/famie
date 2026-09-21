export default defineNuxtRouteMiddleware(async (to) => {
  const token = useCookie<string | null>('auth_token')
  const { user, fetchUser } = useAuth()

  if (!token.value && to.path !== '/login') {
    return navigateTo('/login')
  }

  if (token.value && !user.value && to.path !== '/login') {
    const fetchedUser = await fetchUser()
    if (!fetchedUser) {
      return navigateTo('/login')
    }
  }

  if (token.value && to.path === '/login') {
    return navigateTo('/')
  }
})
