<script setup lang="ts">
import type { ApiRequestError, Category, FamilyMember } from '#shared/types/api'
import type { Reward, RewardAward, RewardPage, RewardSyncStatus } from '#shared/types/rewards'
import inventoryIcon from '~/assets/icons/inventory.svg'
import medalIcon from '~/assets/icons/medal-outline.svg'
import managementIcon from '~/assets/icons/star-cog-outline.svg'

const tabs = computed(() => [
  { value: 'conditions' as const, label: '条件・進捗', icon: medalIcon },
  { value: 'awards' as const, label: 'インベントリ', icon: inventoryIcon },
  ...(isParent.value ? [{ value: 'manage' as const, label: '管理', icon: managementIcon }] : []),
])
const tabIconStyle = (icon: string) => ({ maskImage: `url("${icon}")` })

const { groupId, membership, isParent } = useAuth()
const { fetchApi } = useApi()
const mode = ref<'conditions' | 'awards' | 'manage'>('conditions')
const page = ref(1)
const lastPage = ref(1)
const rewards = ref<Reward[]>([])
const awards = ref<RewardAward[]>([])
const notifications = ref<RewardAward[]>([])
const members = ref<FamilyMember[]>([])
const categories = ref<Category[]>([])
const selectedMember = ref('')
const conditionStatus = ref<'active' | 'stopped'>('active')
const syncStatus = ref<RewardSyncStatus>('idle')
const limits = ref({ active: 3, management: 6 })
const counts = ref({ active: 0, management: 0 })
const busy = ref(false)
const error = ref('')
const editing = ref<Reward | null>(null)
const dialogOpen = ref(false)
const deleting = ref<Reward | null>(null)
let timer: ReturnType<typeof setTimeout> | undefined
let alive = true
let generation = 0
let claimingNotifications = false
const base = computed(() => `/groups/${groupId.value}/rewards`)
const manageable = computed(() => syncStatus.value !== 'processing' && !busy.value)
const message = (cause: unknown) => {
  const data = (cause as ApiRequestError).data
  return (
    Object.values(data?.errors ?? {}).flat()[0] ??
    data?.message ??
    '取得できませんでした。通信環境を確認してください。'
  )
}
const fetchPage = async () => {
  const own = ++generation
  busy.value = true
  error.value = ''
  try {
    const endpoint = mode.value === 'manage' ? '/manage' : mode.value === 'awards' ? '/awards' : ''
    const query = new URLSearchParams({ page: String(page.value) })
    if (mode.value !== 'manage' && selectedMember.value)
      query.set('member_id', selectedMember.value)
    if (mode.value === 'conditions') query.set('status', conditionStatus.value)
    const result = await fetchApi<RewardPage<Reward> | RewardPage<RewardAward>>(
      `${base.value}${endpoint}?${query}`
    )
    if (!alive || own !== generation) return
    if (mode.value === 'awards') awards.value = result.data as RewardAward[]
    else rewards.value = result.data as Reward[]
    lastPage.value = result.last_page
    if (result.sync_status) syncStatus.value = result.sync_status
    if (result.limits) limits.value = result.limits
    if (result.counts) counts.value = result.counts
  } catch (cause: unknown) {
    if (alive && own === generation) error.value = message(cause)
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
      error.value = message(cause)
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
    if (alive) error.value = message(cause)
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
    error.value = message(cause)
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
    error.value = message(cause)
  } finally {
    busy.value = false
  }
}
const changePage = (next: number) => {
  page.value = next
  fetchPage()
}
watch(selectedMember, () => {
  page.value = 1
  fetchPage()
})
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
    error.value = message(cause)
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
    <h2 class="text-xl font-bold">リワード</h2>
    <div class="flex rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800" aria-label="表示切り替え">
      <button v-for="item in tabs" :key="item.value" type="button" :aria-pressed="mode === item.value" class="reward-tab" :class="mode === item.value ? 'text-blue-600 dark:text-blue-300' : 'text-slate-500 dark:text-slate-400'" @click="changeMode(item.value)"><span aria-hidden="true" class="reward-tab-icon" :style="tabIconStyle(item.icon)" /><span class="text-xs font-medium whitespace-nowrap">{{ item.label }}</span></button>
    </div>
    <div v-if="mode !== 'manage'" class="grid gap-3" :class="isParent && mode === 'conditions' ? 'grid-cols-2' : 'grid-cols-1'">
      <label v-if="isParent" class="block min-w-0 text-sm">対象者<select v-model="selectedMember" class="mt-1 w-full min-h-11 rounded-lg border border-slate-400 bg-transparent p-2"><option v-for="member in members" :key="member.membership_id" :value="String(member.membership_id)">{{ member.display_name }}</option></select></label>
      <label v-if="mode === 'conditions'" class="block min-w-0 text-sm">条件の状態<select v-model="conditionStatus" class="mt-1 w-full min-h-11 rounded-lg border border-slate-400 bg-transparent p-2"><option value="active">有効・開始前</option><option value="stopped">停止中</option></select></label>
    </div>
    <p v-if="syncStatus === 'processing'" role="status" class="rounded-lg bg-blue-50 dark:bg-slate-800 p-3 text-sm">集計中です。進捗は更新前の値です。集計完了まで編集できません。</p>
    <div v-if="syncStatus === 'failed'" role="alert" class="text-sm text-red-600 dark:text-red-300">集計に失敗しました。保存済みの獲得履歴は維持されています。<button type="button" class="reward-button mt-2" @click="prepare">再試行</button></div>
    <div v-if="error" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ error }}<button type="button" class="reward-button mt-2" :disabled="busy" @click="fetchPage">再読み込み</button></div>
    <LoadingIndicator v-if="busy" />
    <div v-if="mode === 'manage'" class="space-y-2">
      <p class="text-sm">有効 {{ counts.active }}/{{ limits.active }}件・管理 {{ counts.management }}/{{ limits.management }}件</p>
      <button type="button" :disabled="!manageable || counts.management >= limits.management" class="reward-button w-full bg-blue-600 text-white" @click="edit(null)">リワードを作成</button>
    </div>
    <template v-if="mode !== 'awards'">
      <p v-if="!busy && !error && !rewards.length" class="py-8 text-center text-sm text-slate-500">{{ mode === 'manage' ? 'リワードがまだありません。' : '対象のリワードはまだありません。' }}</p>
      <article v-for="reward in rewards" :key="reward.id" class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 space-y-2">
        <div class="flex flex-wrap items-center gap-2"><RewardTrophy :color="reward.version.color" label-only badge /><span class="rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap" :class="reward.status === 'draft' ? 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200' : reward.status === 'stopped' ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200' : 'bg-green-100 text-green-800 dark:bg-green-600 dark:text-green-50'">{{ reward.status === 'draft' ? '下書き' : reward.status === 'stopped' ? '停止' : !reward.started ? '有効（開始前）' : '有効' }}</span></div>
        <h3 class="flex items-start gap-2 font-bold break-all"><RewardTrophy :color="reward.version.color" icon-only /><span class="min-w-0">{{ reward.version.name }}</span></h3>
        <p class="text-sm whitespace-pre-wrap break-all">{{ reward.version.description }}</p>
        <p class="text-xs text-slate-600 dark:text-slate-300">{{ reward.starts_on }}〜{{ reward.ends_on }}（{{ reward.timezone }}）・{{ reward.cycle_months }}か月周期</p>
        <p class="text-sm">{{ reward.version.count_mode === 'period_days' ? '期間内日数・' : '' }}{{ reward.version.category_name || '全カテゴリ' }}・{{ reward.version.metric === 'activities' ? '活動日数（1日1回）' : '受信いいね数' }} {{ reward.version.required_count }}{{ reward.version.metric === 'activities' ? '日' : '件' }}</p>
        <template v-if="mode === 'conditions'">
          <RewardProgress v-if="reward.started" :current="reward.progress" :required-count="reward.version.required_count" />
          <p v-if="reward.started && reward.awarded" class="text-xs">獲得済み（獲得時 {{ reward.achieved_count }}）</p>
          <p v-if="reward.status === 'stopped'" class="text-xs">停止中は達成判定を行いません。再開時に現在期間の活動を再集計します。</p>
        </template>
        <template v-else>
          <p class="text-sm">対象：{{ reward.version.target_ids.map(id => members.find(member => member.membership_id === id)?.display_name ?? '無効化済みの対象者').join('、') }}</p>
          <p v-if="reward.pending_version" class="rounded-lg bg-blue-50 dark:bg-slate-900 p-2 text-xs">次期変更あり：{{ reward.pending_version.effective_from }}から「{{ reward.pending_version.name }}」（{{ reward.pending_version.count_mode === 'period_days' ? '期間内日数・' : '' }}必要数 {{ reward.pending_version.required_count }}）</p>
          <div class="flex flex-wrap gap-2">
            <button type="button" :disabled="!manageable" class="reward-button" @click="edit(reward)">編集</button>
            <button v-if="reward.status !== 'active'" type="button" :disabled="!manageable || counts.active >= limits.active" class="reward-button" @click="changeStatus(reward, 'active')">{{ reward.status === 'draft' ? '有効化' : '再開' }}</button>
            <button v-if="reward.status === 'active'" type="button" :disabled="!manageable" class="reward-button" @click="changeStatus(reward, 'stopped')">停止</button>
            <button v-if="reward.status !== 'active'" type="button" :disabled="!manageable" class="reward-button text-red-600 dark:text-red-300" @click="deleting = reward">削除</button>
          </div>
        </template>
      </article>
    </template>
    <template v-else>
      <p v-if="!busy && !error && !awards.length" class="py-8 text-center text-sm text-slate-500">獲得履歴はまだありません。</p>
      <article v-for="award in awards" :key="award.id" class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 space-y-2">
        <RewardTrophy :color="award.snapshot.color" label-only badge /><h3 class="flex items-start gap-2 font-bold break-all"><RewardTrophy :color="award.snapshot.color" icon-only /><span class="min-w-0">{{ award.snapshot.name }}</span></h3>
        <p class="text-sm whitespace-pre-wrap break-all">{{ award.snapshot.description }}</p>
        <p class="text-xs">{{ award.snapshot.starts_on }}〜{{ award.snapshot.ends_on }}・獲得時 {{ award.achieved_count }}/{{ award.snapshot.required_count }}</p>
        <p class="text-xs text-slate-600 dark:text-slate-300">獲得：{{ new Date(award.awarded_at).toLocaleString() }}</p>
        <p v-if="award.deleted" class="text-xs">削除済みリワード</p>
      </article>
    </template>
    <div v-if="lastPage > 1" class="flex items-center justify-between gap-3">
      <button type="button" class="reward-button" :disabled="busy || page <= 1" @click="changePage(page - 1)">前へ</button><span class="text-sm">{{ page }}/{{ lastPage }}</span><button type="button" class="reward-button" :disabled="busy || page >= lastPage" @click="changePage(page + 1)">次へ</button>
    </div>
  </section>
  <RewardManageDialog v-if="dialogOpen" :reward="editing" :members="members" :categories="categories" @close="dialogOpen = false" @saved="saved" />
  <SettingsDialog v-if="deleting" title="リワードを削除" :busy="busy" @close="deleting = null">
    <p class="text-sm break-all">「{{ deleting.version.name }}」を削除します。{{ deleting.status === 'draft' ? '未有効化の下書きと対象者設定を完全に削除します。' : '新たな獲得は停止します。獲得履歴は残ります。' }}削除後は元に戻せません。</p>
    <button type="button" class="reward-button mt-4 w-full bg-red-600 text-white" :disabled="!manageable" @click="remove">削除する</button>
  </SettingsDialog>
  <SettingsDialog v-if="notifications.length" title="リワードを獲得しました！" :busy="false" @close="notifications = []">
    <div class="space-y-4"><article v-for="award in notifications" :key="award.id" class="space-y-2"><RewardTrophy :color="award.snapshot.color" label-only /><h3 class="flex items-start gap-2 font-bold break-all"><RewardTrophy :color="award.snapshot.color" icon-only /><span class="min-w-0">{{ award.snapshot.name }}</span></h3><p class="text-sm whitespace-pre-wrap break-all">{{ award.snapshot.description }}</p><p class="text-xs">{{ award.snapshot.starts_on }}〜{{ award.snapshot.ends_on }}</p></article></div>
    <p class="mt-4 text-sm">獲得履歴はインベントリでいつでも確認できます。</p>
  </SettingsDialog>
</template>
<style scoped>
.reward-button { min-height: 44px; padding: 8px 12px; border: 1px solid #94a3b8; border-radius: 8px; font-size: 14px; }
.reward-button:disabled { opacity: .5; }
.reward-tab { display: flex; flex: 1 1 0; min-width: 0; min-height: 64px; flex-direction: column; align-items: center; justify-content: center; gap: 4px; }
.reward-tab-icon { width: 26px; height: 26px; background-color: currentColor; mask-size: contain; mask-repeat: no-repeat; mask-position: center; }
</style>
