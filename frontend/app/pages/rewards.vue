<script setup lang="ts">
import type { ApiRequestError, Category, FamilyMember } from '#shared/types/api'
import type {
  Reward,
  RewardAward,
  RewardAwardsPage,
  RewardPage,
  RewardSyncStatus,
  TrophyColor,
  TrophyTotals,
} from '#shared/types/rewards'
import { parseLocalDate } from '#shared/utils/calendar'
import inventoryIcon from '~/assets/icons/inventory.svg'
import medalIcon from '~/assets/icons/medal-outline.svg'
import managementIcon from '~/assets/icons/star-cog-outline.svg'

const { t } = useI18n()
const { intlLocale } = useUiLocale()
const number = (value: number) => new Intl.NumberFormat(intlLocale.value).format(value)
const date = (value: string) =>
  new Intl.DateTimeFormat(intlLocale.value, {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(parseLocalDate(value))
const timestamp = (value: string) =>
  new Intl.DateTimeFormat(intlLocale.value, {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  }).format(new Date(value))
const range = (from: string, to: string) => t('reward.range', { from: date(from), to: date(to) })
const months = (count: number) => t('reward.months', { count: number(count) }, count)
const target = (metric: string, count: number) =>
  t(metric === 'activities' ? 'reward.activeDays' : 'reward.likes', { count: number(count) }, count)
const targets = (ids: number[]) =>
  new Intl.ListFormat(intlLocale.value).format(
    ids.map(
      (id) =>
        members.value.find((member) => member.membership_id === id)?.display_name ??
        t('reward.inactiveMember')
    )
  )
useHead(() => ({ title: `${t('reward.title')} | Famie` }))
const tabs = computed(() => [
  { value: 'conditions' as const, label: t('reward.conditions'), icon: medalIcon },
  { value: 'awards' as const, label: t('reward.inventory'), icon: inventoryIcon },
  ...(isParent.value
    ? [{ value: 'manage' as const, label: t('reward.manage'), icon: managementIcon }]
    : []),
])
const tabIconStyle = (icon: string) => ({ maskImage: `url("${icon}")` })

const { groupId, membership, isParent } = useAuth()
const { fetchApi } = useApi()
const mode = ref<'conditions' | 'awards' | 'manage'>('conditions')
const page = ref(1)
const lastPage = ref(1)
const rewards = ref<Reward[]>([])
const awards = ref<RewardAward[]>([])
const trophyColors: TrophyColor[] = ['gold', 'silver', 'bronze']
const trophyTotals = ref<TrophyTotals | null>(null)
const notifications = ref<RewardAward[]>([])
const members = ref<FamilyMember[]>([])
const categories = ref<Category[]>([])
const selectedMember = ref('')
const conditionStatus = ref<'active' | 'stopped'>('active')
const syncStatus = ref<RewardSyncStatus>('idle')
const limits = ref({ active: 3, management: 6 })
const counts = ref({ active: 0, management: 0 })
const busy = ref(false)
const error = useUiMessage()
const editing = ref<Reward | null>(null)
const dialogOpen = ref(false)
const deleting = ref<Reward | null>(null)
let timer: ReturnType<typeof setTimeout> | undefined
let alive = true
let generation = 0
let claimingNotifications = false
const base = computed(() => `/groups/${groupId.value}/rewards`)
const manageable = computed(() => syncStatus.value !== 'processing' && !busy.value)
const showError = (cause: unknown) => {
  const data = (cause as ApiRequestError).data
  const message = Object.values(data?.errors ?? {}).flat()[0] ?? data?.message
  if (message) error.value = message
  else error.setKey('reward.loadFailed')
}
const fetchPage = async () => {
  const own = ++generation
  const requestedGroup = groupId.value
  busy.value = true
  error.value = ''
  trophyTotals.value = null
  if (mode.value === 'awards') awards.value = []
  try {
    const endpoint = mode.value === 'manage' ? '/manage' : mode.value === 'awards' ? '/awards' : ''
    const query = new URLSearchParams({ page: String(page.value) })
    if (mode.value !== 'manage' && selectedMember.value)
      query.set('member_id', selectedMember.value)
    if (mode.value === 'conditions') query.set('status', conditionStatus.value)
    const result = await fetchApi<RewardPage<Reward> | RewardAwardsPage>(
      `${base.value}${endpoint}?${query}`
    )
    if (!alive || own !== generation || requestedGroup !== groupId.value) return
    if (mode.value === 'awards') {
      const totals = 'meta' in result ? result.meta?.trophy_totals : undefined
      if (
        !Array.isArray(result.data) ||
        !totals ||
        trophyColors.some((color) => !Number.isSafeInteger(totals[color]) || totals[color] < 0)
      )
        throw new Error('Invalid award totals')
      awards.value = result.data as RewardAward[]
      trophyTotals.value = { gold: totals.gold, silver: totals.silver, bronze: totals.bronze }
    } else rewards.value = result.data as Reward[]
    lastPage.value = result.last_page
    if (result.sync_status) syncStatus.value = result.sync_status
    if (result.limits) limits.value = result.limits
    if (result.counts) counts.value = result.counts
  } catch (cause: unknown) {
    if (alive && own === generation) showError(cause)
  } finally {
    if (own === generation) busy.value = false
  }
}
const prepare = async () => {
  try {
    const result = await fetchApi<{ status: RewardSyncStatus }>(`${base.value}/prepare`, {
      method: 'POST',
    })
    if (!alive) return
    syncStatus.value = result.status
    await fetchPage()
    if (syncStatus.value === 'processing' && alive) timer = setTimeout(prepare, 1500)
    else if (syncStatus.value === 'complete') await claimNotifications()
  } catch (cause: unknown) {
    if (alive) {
      syncStatus.value = 'failed'
      showError(cause)
    }
  }
}
const claimNotifications = async () => {
  if (notifications.value.length || claimingNotifications) return
  claimingNotifications = true
  try {
    const result = await fetchApi<{ data: RewardAward[] }>(`${base.value}/notifications`, {
      method: 'POST',
    })
    if (alive && result.data.length) notifications.value = result.data
  } catch (cause: unknown) {
    if (alive) showError(cause)
  } finally {
    claimingNotifications = false
  }
}
const changeMode = (next: typeof mode.value) => {
  mode.value = next
  page.value = 1
  rewards.value = []
  awards.value = []
  fetchPage()
}
const edit = (reward: Reward | null) => {
  editing.value = reward
  dialogOpen.value = true
}
const saved = async () => {
  dialogOpen.value = false
  await fetchPage()
  await prepare()
}
const changeStatus = async (reward: Reward, status: 'active' | 'stopped') => {
  if (!manageable.value) return
  busy.value = true
  error.value = ''
  try {
    await fetchApi(`${base.value}/${reward.id}/status`, {
      method: 'PUT',
      body: { status, revision: reward.revision },
    })
    await fetchPage()
    await prepare()
  } catch (cause: unknown) {
    showError(cause)
  } finally {
    busy.value = false
  }
}
const remove = async () => {
  const reward = deleting.value
  if (!reward || !manageable.value) return
  busy.value = true
  error.value = ''
  try {
    await fetchApi(`${base.value}/${reward.id}`, {
      method: 'DELETE',
      body: { revision: reward.revision },
    })
    deleting.value = null
    await fetchPage()
  } catch (cause: unknown) {
    showError(cause)
  } finally {
    busy.value = false
  }
}
const changePage = (next: number) => {
  page.value = next
  fetchPage()
}
watch(
  selectedMember,
  () => {
    page.value = 1
    fetchPage()
  },
  { flush: 'sync' }
)
watch(conditionStatus, () => {
  page.value = 1
  fetchPage()
})
onMounted(async () => {
  if (!groupId.value) return
  selectedMember.value = String(membership.value?.id ?? '')
  try {
    if (isParent.value) {
      const results = await Promise.all([
        fetchApi<FamilyMember[]>(`/groups/${groupId.value}/members`),
        fetchApi<Category[]>(`/groups/${groupId.value}/categories`),
      ])
      members.value = results[0]
      categories.value = results[1]
    }
    await prepare()
  } catch (cause: unknown) {
    showError(cause)
  }
})
onBeforeUnmount(() => {
  alive = false
  generation++
  if (timer) clearTimeout(timer)
})
</script>
<template>
  <section class="space-y-4 text-slate-800 dark:text-slate-100">
    <h2 class="text-xl font-bold">{{ t('reward.title') }}</h2>
    <div class="flex rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800" :aria-label="t('reward.viewSwitch')">
      <button v-for="item in tabs" :key="item.value" type="button" :aria-pressed="mode === item.value" class="reward-tab" :class="mode === item.value ? 'text-blue-600 dark:text-blue-300' : 'text-slate-500 dark:text-slate-400'" @click="changeMode(item.value)"><span aria-hidden="true" class="reward-tab-icon" :style="tabIconStyle(item.icon)" /><span class="text-xs font-medium text-center break-words">{{ item.label }}</span></button>
    </div>
    <div v-if="mode !== 'manage'" class="reward-filters grid gap-3" :class="isParent && mode === 'conditions' ? 'grid-cols-2' : 'grid-cols-1'">
      <label v-if="isParent" class="block min-w-0 text-sm">{{ t('reward.member') }}<select v-model="selectedMember" class="mt-1 w-full min-h-11 rounded-lg border border-slate-400 bg-white text-slate-900 dark:bg-slate-800 dark:text-slate-100 p-2"><option v-for="member in members" :key="member.membership_id" :value="String(member.membership_id)">{{ member.display_name }}</option></select></label>
      <label v-if="mode === 'conditions'" class="block min-w-0 text-sm">{{ t('reward.goalStatus') }}<select v-model="conditionStatus" class="mt-1 w-full min-h-11 rounded-lg border border-slate-400 bg-white text-slate-900 dark:bg-slate-800 dark:text-slate-100 p-2"><option value="active">{{ t('reward.activeUpcoming') }}</option><option value="stopped">{{ t('reward.stopped') }}</option></select></label>
    </div>
    <p v-if="syncStatus === 'processing'" role="status" class="rounded-lg bg-blue-50 dark:bg-slate-800 p-3 text-sm">{{ t('reward.processing') }}</p>
    <div v-if="syncStatus === 'failed'" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ t('reward.syncFailed') }}<button type="button" class="reward-button mt-2" @click="prepare">{{ t('reward.retry') }}</button></div>
    <div v-if="error" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ error }}<button type="button" class="reward-button mt-2" :disabled="busy" @click="fetchPage">{{ t('reward.reload') }}</button></div>
    <LoadingIndicator v-if="busy" />
    <div v-if="mode === 'manage'" class="space-y-2">
      <p class="text-sm">{{ t('reward.limits', { active: number(counts.active), activeLimit: number(limits.active), management: number(counts.management), managementLimit: number(limits.management) }) }}</p>
      <button type="button" :disabled="!manageable || counts.management >= limits.management" class="reward-button w-full bg-blue-600 text-white" @click="edit(null)">{{ t('reward.create') }}</button>
    </div>
    <template v-if="mode !== 'awards'">
      <p v-if="!busy && !error && !rewards.length" class="py-8 text-center text-sm text-slate-500">{{ mode === 'manage' ? t('reward.emptyManage') : t('reward.emptyConditions') }}</p>
      <article v-for="reward in rewards" :key="reward.id" class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 space-y-2">
        <div class="flex flex-wrap items-center gap-2"><RewardTrophy :color="reward.version.color" label-only badge /><span class="rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap" :class="reward.status === 'draft' ? 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200' : reward.status === 'stopped' ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200' : 'bg-green-100 text-green-800 dark:bg-green-600 dark:text-green-50'">{{ t(reward.status === 'draft' ? 'reward.draft' : reward.status === 'stopped' ? 'reward.stopped' : !reward.started ? 'reward.notStarted' : 'reward.active') }}</span><span v-if="mode === 'conditions' && reward.started && reward.awarded" class="rounded-full bg-red-600 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-white">{{ t('reward.earned') }}</span></div>
        <h3 class="flex items-start gap-2 font-bold break-all"><RewardTrophy :color="reward.version.color" icon-only /><span class="min-w-0">{{ reward.version.name }}</span></h3>
        <p class="text-sm whitespace-pre-wrap break-all">{{ reward.version.description }}</p>
        <p class="text-xs text-slate-600 dark:text-slate-300">{{ t('reward.periodCycle', { range: range(reward.starts_on, reward.ends_on), cycle: months(reward.cycle_months) }) }}</p>
        <p class="text-sm">{{ t(reward.version.count_mode === 'period_days' ? 'reward.conditionDays' : 'reward.condition', { category: reward.version.category_name ?? t('reward.allCategories'), target: target(reward.version.metric, reward.version.required_count) }) }}</p>
        <template v-if="mode === 'conditions'">
          <RewardProgress v-if="reward.started" :current="reward.progress" :required-count="reward.version.required_count" />
          <p v-if="reward.status === 'stopped'" class="text-xs">{{ t('reward.stoppedHelp') }}</p>
        </template>
        <template v-else>
          <p class="text-sm">{{ t('reward.targets', { members: targets(reward.version.target_ids) }) }}</p>
          <p v-if="reward.pending_version" class="rounded-lg bg-blue-50 dark:bg-slate-900 p-2 text-xs">{{ t(reward.pending_version.count_mode === 'period_days' ? 'reward.pendingDays' : 'reward.pending', { date: date(reward.pending_version.effective_from), name: reward.pending_version.name, target: number(reward.pending_version.required_count) }) }}</p>
          <div class="flex flex-wrap gap-2">
            <button type="button" :disabled="!manageable" class="reward-button" @click="edit(reward)">{{ t('reward.edit') }}</button>
            <button v-if="reward.status !== 'active'" type="button" :disabled="!manageable || counts.active >= limits.active" class="reward-button" @click="changeStatus(reward, 'active')">{{ reward.status === 'draft' ? t('reward.activate') : t('reward.resume') }}</button>
            <button v-if="reward.status === 'active'" type="button" :disabled="!manageable" class="reward-button" @click="changeStatus(reward, 'stopped')">{{ t('reward.stop') }}</button>
            <button v-if="reward.status !== 'active'" type="button" :disabled="!manageable" class="reward-button text-red-600 dark:text-red-300" @click="deleting = reward">{{ t('reward.delete') }}</button>
          </div>
        </template>
      </article>
    </template>
    <template v-else>
      <div role="group" :aria-label="t('reward.trophyTotals')" class="relative flex max-w-full items-center gap-3 overflow-x-auto whitespace-nowrap py-1 text-sm tabular-nums" data-testid="trophy-totals">
        <template v-for="(color, index) in trophyColors" :key="color">
          <span v-if="index" aria-hidden="true" class="text-slate-400">|</span>
          <span class="inline-flex shrink-0 items-center gap-1.5">
            <RewardTrophy :color="color" icon-only />
            <span aria-hidden="true">{{ trophyTotals ? number(trophyTotals[color]) : '—' }}</span>
            <span class="sr-only">{{ trophyTotals ? t('reward.trophyTotal', { trophy: t(`reward.trophy${color}`), count: number(trophyTotals[color]) }, trophyTotals[color]) : t('reward.trophyTotalUnknown', { trophy: t(`reward.trophy${color}`) }) }}</span>
          </span>
        </template>
      </div>
      <p v-if="!busy && !error && !awards.length" class="py-8 text-center text-sm text-slate-500">{{ t('reward.emptyAwards') }}</p>
      <article v-for="award in awards" :key="award.id" class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 space-y-2">
        <RewardTrophy :color="award.snapshot.color" label-only badge /><h3 class="flex items-start gap-2 font-bold break-all"><RewardTrophy :color="award.snapshot.color" icon-only /><span class="min-w-0">{{ award.snapshot.name }}</span></h3>
        <p class="text-sm whitespace-pre-wrap break-all">{{ award.snapshot.description }}</p>
        <p class="text-xs">{{ t('reward.earnedProgress', { range: range(award.snapshot.starts_on, award.snapshot.ends_on), current: number(award.achieved_count), required: number(award.snapshot.required_count) }) }}</p>
        <p class="text-xs text-slate-600 dark:text-slate-300">{{ t('reward.earnedAt', { date: timestamp(award.awarded_at) }) }}</p>
        <p v-if="award.deleted" class="text-xs">{{ t('reward.deleted') }}</p>
      </article>
    </template>
    <div v-if="lastPage > 1" class="flex items-center justify-between gap-3">
      <button type="button" class="reward-button" :disabled="busy || page <= 1" @click="changePage(page - 1)">{{ t('reward.previous') }}</button><span class="text-sm">{{ t('reward.page', { page: number(page), total: number(lastPage) }) }}</span><button type="button" class="reward-button" :disabled="busy || page >= lastPage" @click="changePage(page + 1)">{{ t('reward.next') }}</button>
    </div>
  </section>
  <RewardManageDialog v-if="dialogOpen" :reward="editing" :members="members" :categories="categories" @close="dialogOpen = false" @saved="saved" />
  <SettingsDialog v-if="deleting" :title="t('reward.deleteTitle')" :busy="busy" @close="deleting = null">
    <p class="text-sm break-all">{{ t(deleting.status === 'draft' ? 'reward.deleteDraftConfirm' : 'reward.deleteConfirm', { name: deleting.version.name }) }}</p>
    <button type="button" class="reward-button mt-4 w-full bg-red-600 text-white" :disabled="!manageable" @click="remove">{{ t('reward.deleteAction') }}</button>
  </SettingsDialog>
  <SettingsDialog v-if="notifications.length" :title="t('reward.earnedTitle')" :busy="false" celebrate @close="notifications = []">
    <div class="space-y-4"><article v-for="award in notifications" :key="award.id" class="space-y-2"><RewardTrophy :color="award.snapshot.color" label-only /><h3 class="flex items-start gap-2 font-bold break-all"><RewardTrophy :color="award.snapshot.color" icon-only /><span class="min-w-0">{{ award.snapshot.name }}</span></h3><p class="text-sm whitespace-pre-wrap break-all">{{ award.snapshot.description }}</p><p class="text-xs">{{ range(award.snapshot.starts_on, award.snapshot.ends_on) }}</p></article></div>
    <p class="mt-4 text-sm">{{ t('reward.inventoryHelp') }}</p>
  </SettingsDialog>
</template>
<style scoped>
.reward-filters select {
  appearance: none;
  padding-right: 2rem;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right .5rem center;
  background-size: 1rem 1rem;
}
.reward-filters option { background-color: #fff; color: #0f172a; }
[data-theme='dark'] .reward-filters option { background-color: #1e293b; color: #f1f5f9; }
.reward-button { min-height: 44px; padding: 8px 12px; border: 1px solid #94a3b8; border-radius: 8px; font-size: 14px; }
.reward-button:disabled { opacity: .5; }
.reward-tab { display: flex; flex: 1 1 0; min-width: 0; min-height: 64px; flex-direction: column; align-items: center; justify-content: center; gap: 4px; }
.reward-tab-icon { width: 26px; height: 26px; background-color: currentColor; mask-size: contain; mask-repeat: no-repeat; mask-position: center; }
</style>
