interface User {
  id: number
  username: string
  display_name: string
  role: 'parent' | 'child'
}

interface LoginResponse {
  access_token: string
  user: User
}

export const useAuth = () => {
  const user = useState<User | null>('auth_user', () => null)
  const token = useCookie<string | null>('auth_token', {
    maxAge: 60 * 60 * 24 * 30,
  })
  const { fetchApi } = useApi()

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
    } finally {
      token.value = null
      user.value = null
      navigateTo('/login')
    }
  }

  const fetchUser = async () => {
    if (!token.value) return null
    try {
      const res = await fetchApi<{ user: User }>('/auth/me')
      user.value = res.user
      return res.user
    } catch {
      token.value = null
      user.value = null
      return null
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
