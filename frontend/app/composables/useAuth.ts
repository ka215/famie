import type {
  ApiRequestError,
  CurrentUserResponse,
  LoginResponse,
  Membership,
  User,
} from '#shared/types/api'
import type { RegisterForm } from '#shared/types/forms'

export const useAuth = () => {
  const user = useState<User | null>('auth_user', () => null)
  const membership = useState<Membership | null>('auth_membership', () => null)
  const accessError = useState<string>('auth_access_error', () => '')
  const token = useCookie<string | null>('auth_token', {
    maxAge: 60 * 60 * 24 * 30,
    path: '/',
    sameSite: 'lax',
    secure: import.meta.env.PROD,
  })
  const { fetchApi } = useApi()
  const { isMaintenance } = useMaintenance()
  const resetLikes = () => {
    useState('activity_like_operations').value = {}
    useState('activity_like_retry_until').value = 0
    useState('activity_like_owner').value = ''
  }

  const login = async (loginId: string, password: string) => {
    const res = await fetchApi<LoginResponse>('/auth/login', {
      method: 'POST',
      body: { login: loginId, password },
    })

    token.value = res.access_token
    resetLikes()
    user.value = res.user
    membership.value = res.membership
    accessError.value = ''
    return res
  }

  const register = async (form: RegisterForm) => {
    const res = await fetchApi<LoginResponse>('/auth/register', { method: 'POST', body: form })
    resetLikes()
    token.value = res.access_token
    user.value = res.user
    membership.value = res.membership
    accessError.value = ''

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
        resetLikes()
        user.value = null
        membership.value = null
        accessError.value = ''
        navigateTo('/login')
      }
    }
  }

  const fetchUser = async () => {
    if (!token.value) return null
    try {
      const res = await fetchApi<CurrentUserResponse>('/auth/me')
      user.value = res.user
      membership.value = res.membership
      return res.user
    } catch (error: unknown) {
      if (isMaintenance.value) throw error
      user.value = null
      membership.value = null
      const status =
        (error as ApiRequestError).response?.status ?? (error as ApiRequestError).statusCode
      if (status === 401) {
        return null
      }
      if (status === 403 || status === 409) {
        token.value = null
        accessError.value =
          status === 403
            ? 'このアカウントは現在利用できません。家族の管理者へ確認してください。'
            : '所属情報を特定できません。管理者へ連絡してください。'
        return null
      }
      throw new Error('認証情報を確認できませんでした。通信環境を確認してください。')
    }
  }

  return {
    user,
    membership,
    accessError,
    token,
    login,
    register,
    logout,
    fetchUser,
    isParent: computed(() => membership.value?.role === 'admin'),
    groupId: computed(() => membership.value?.group.id ?? null),
  }
}
