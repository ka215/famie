import type { ApiRequestError, CurrentUserResponse, LoginResponse, User } from '#shared/types/api'

export const useAuth = () => {
  const user = useState<User | null>('auth_user', () => null)
  const token = useCookie<string | null>('auth_token', {
    maxAge: 60 * 60 * 24 * 30,
    path: '/',
    sameSite: 'lax',
    secure: import.meta.env.PROD,
  })
  const { fetchApi } = useApi()
  const { isMaintenance } = useMaintenance()

  const login = async (loginId: string, password: string) => {
    const res = await fetchApi<LoginResponse>('/auth/login', {
      method: 'POST',
      body: { login: loginId, password },
    })

    token.value = res.access_token
    user.value = res.user
    return res
  }

  const logout = async () => {
    try {
      if (token.value) {
        await fetchApi('/auth/logout', { method: 'POST' })
      }
    } catch (error: unknown) {
      if (!isMaintenance.value) throw error
    } finally {
      if (!isMaintenance.value) {
        token.value = null
        user.value = null
        navigateTo('/login')
      }
    }
  }

  const fetchUser = async () => {
    if (!token.value) return null
    try {
      const res = await fetchApi<CurrentUserResponse>('/auth/me')
      user.value = res.user
      return res.user
    } catch (error: unknown) {
      if (isMaintenance.value) throw error
      user.value = null
      const status =
        (error as ApiRequestError).response?.status ?? (error as ApiRequestError).statusCode
      if (status === 401) {
        return null
      }
      throw new Error('認証情報を確認できませんでした。通信環境を確認してください。')
    }
  }

  return {
    user,
    token,
    login,
    logout,
    fetchUser,
    isParent: computed(() => user.value?.role === 'parent'),
  }
}
