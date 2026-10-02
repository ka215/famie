import type { MembershipRole } from './api'

export interface CreateUserForm {
  username: string
  display_name: string
  password: string
  password_confirmation: string
  role: MembershipRole
}

export interface RegisterForm {
  group_name: string
  username: string
  display_name: string
  password: string
  password_confirmation: string
}

export interface PasswordForm {
  current_password: string
  new_password: string
  new_password_confirmation: string
}

export type FilterPeriod = 'this_week' | 'month' | 'custom'
