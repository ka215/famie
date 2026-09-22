import type { UserRole } from './api'

export interface ActivityLogForm {
  category_id: number | null
  activity_date: string
  activity_time: string
  content: string
  note: string
}

export interface CreateUserForm {
  username: string
  display_name: string
  email: string
  password: string
  password_confirmation: string
  role: UserRole
}

export interface PasswordForm {
  current_password: string
  new_password: string
  new_password_confirmation: string
}

export type FilterPeriod = 'this_week' | 'this_month' | 'custom'
