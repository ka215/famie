export type UserRole = 'parent' | 'child'

export interface User {
  id: number
  username: string
  display_name: string
  email?: string | null
  role: UserRole
}

export interface Category {
  id: number
  name: string
  color_code: string
}

export interface ActivityLogUser {
  id: number
  display_name: string
  role: UserRole
}

export interface ActivityLog {
  id: number
  user_id: number
  category_id: number
  activity_date: string
  activity_time: string | null
  content: string
  note: string | null
  user: ActivityLogUser
  category: Category
}

export interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

export interface PaginatedResponse<T> {
  current_page: number
  data: T[]
  first_page_url: string
  from: number | null
  last_page: number
  last_page_url: string
  links: PaginationLink[]
  next_page_url: string | null
  path: string
  per_page: number
  prev_page_url: string | null
  to: number | null
  total: number
}

export interface DataResponse<T> {
  data: T
  message?: string
}

export interface MessageResponse {
  message: string
}

export interface LoginResponse extends MessageResponse {
  access_token: string
  token_type: 'Bearer'
  user: User
}

export interface CurrentUserResponse {
  user: User
}

export interface ApiErrorBody {
  code?: string
  message?: string
  errors?: Record<string, string[]>
}

export interface ApiRequestError {
  data?: ApiErrorBody
  response?: { status?: number }
  statusCode?: number
}
