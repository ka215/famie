export type TrophyColor = 'gold' | 'silver' | 'bronze'
export type TrophyTotals = Record<TrophyColor, number>
export interface RewardAwardsPage extends RewardPage<RewardAward> {
  meta: { trophy_totals: TrophyTotals }
}
export interface RewardVersion {
  id: number
  name: string
  description: string
  metric: 'activities' | 'likes'
  color: TrophyColor
  count_mode: 'fixed' | 'period_days'
  required_count: number
  category_id: number | null
  category_name: string | null
  target_ids: number[]
  effective_from: string
}
export interface Reward {
  id: number
  status: 'draft' | 'active' | 'stopped'
  revision: number
  start_month: string
  cycle_months: number
  timezone: string
  version: RewardVersion
  pending_version: RewardVersion | null
  starts_on: string
  ends_on: string
  started: boolean
  progress: number | null
  awarded: boolean
  achieved_count: number | null
}
export interface RewardAward {
  id: number
  reward_id: number
  awarded_at: string
  read_at: string | null
  achieved_count: number
  deleted?: boolean
  snapshot: Pick<
    RewardVersion,
    'name' | 'description' | 'metric' | 'color' | 'required_count' | 'category_name'
  > & {
    count_mode?: RewardVersion['count_mode']
    starts_on: string
    ends_on: string
    timezone: string
  }
}
export type RewardSyncStatus = 'idle' | 'processing' | 'complete' | 'failed'
export interface RewardPage<T> {
  data: T[]
  current_page: number
  last_page: number
  total: number
  sync_status?: RewardSyncStatus
  limits?: { active: number; management: number }
  counts?: { active: number; management: number }
}
