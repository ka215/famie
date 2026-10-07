export type MembershipRole = 'admin' | 'member'

export interface User {
  id: number
  username: string
  display_name: string
  email?: string | null
  email_verified_at?: string | null
  pending_email?: string | null
  email_verification_expires_at?: string | null
}

export interface Group {
  id: number
  name: string
  type: 'family'
}

export interface Membership {
  id: number
  role: MembershipRole
  status: 'active'
  group: Group
}

export interface FamilyMember {
  id: number
  username: string
  display_name: string
  membership_id: number
  role: MembershipRole
}

export interface Category {
  id: number
  group_id: number
  name: string
  color_code: string
  sort_order: number
}

export interface ActivityLogUser {
  id: number
  display_name: string
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
  membership: Membership
}

export interface CurrentUserResponse {
  user: User
  membership: Membership
}

export interface ApiErrorBody {
  code?: string
  message?: string
  errors?: Record<string, string[]>
}

export interface ApiRequestError {
  data?: ApiErrorBody
  response?: { status?: number; headers?: Headers }
  statusCode?: number
}
