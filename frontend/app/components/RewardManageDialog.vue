<script setup lang="ts">
import type { ApiRequestError, Category, FamilyMember } from '#shared/types/api'
import type { Reward, RewardVersion, TrophyColor } from '#shared/types/rewards'
import { trimLaravelString } from '#shared/utils/laravelString'

const props = defineProps<{
  reward: Reward | null
  members: FamilyMember[]
  categories: Category[]
}>()
const emit = defineEmits<{ close: []; saved: [] }>()
const { groupId } = useAuth()
const { fetchApi } = useApi()
const source = props.reward?.pending_version ?? props.reward?.version
let browserZone = 'UTC'
try {
  browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
} catch {
  /* UTC fallback */
}
const timezone = props.reward?.timezone ?? browserZone
const today = new Date()
const parts = new Intl.DateTimeFormat('en-CA', {
  timeZone: timezone,
  year: 'numeric',
  month: '2-digit',
}).formatToParts(today)
const currentMonth = `${parts.find((p) => p.type === 'year')?.value}-${parts.find((p) => p.type === 'month')?.value}`
const name = ref(source?.name ?? '')
const description = ref(source?.description ?? '')
const metric = ref<RewardVersion['metric']>(source?.metric ?? 'activities')
const color = ref<TrophyColor>(source?.color ?? 'bronze')
const requiredCount = ref(source?.required_count ?? 10)
const countMode = ref<RewardVersion['count_mode']>(source?.count_mode ?? 'fixed')
const categoryId = ref<number | null>(source?.category_id ?? null)
const targetIds = ref<number[]>(
  source ? [...source.target_ids] : props.members.map((member) => member.membership_id)
)
const startMonth = ref(props.reward?.start_month.slice(0, 7) ?? currentMonth)
const cycle = ref(props.reward?.cycle_months ?? 1)
const periodLocked = computed(() =>
  Boolean(props.reward?.started && props.reward.status !== 'draft')
)
const busy = ref(false)
const error = ref('')
const save = async () => {
  if (busy.value) return
  const trimmedName = trimLaravelString(name.value)
  const trimmedDescription = trimLaravelString(description.value)
  if (
    !trimmedName ||
    Array.from(trimmedName).length > 50 ||
    !trimmedDescription ||
    Array.from(trimmedDescription).length > 500 ||
    !targetIds.value.length
  ) {
    error.value = '名称（50文字以内）・報酬説明（500文字以内）・対象者を入力してください。'
    return
  }
  busy.value = true
  error.value = ''
  try {
    await fetchApi(`/groups/${groupId.value}/rewards${props.reward ? `/${props.reward.id}` : ''}`, {
      method: props.reward ? 'PUT' : 'POST',
      body: {
        name: trimmedName,
        description: trimmedDescription,
        metric: metric.value,
        color: color.value,
        count_mode: countMode.value,
        required_count: countMode.value === 'fixed' ? requiredCount.value : null,
        category_id: categoryId.value,
        target_ids: targetIds.value,
        start_month: startMonth.value,
        cycle_months: cycle.value,
        timezone,
        ...(props.reward ? { revision: props.reward.revision } : {}),
      },
    })
    emit('saved')
  } catch (cause: unknown) {
    const data = (cause as ApiRequestError).data
    error.value =
      Object.values(data?.errors ?? {}).flat()[0] ??
      data?.message ??
      '保存できませんでした。再読み込みして確認してください。'
  } finally {
    busy.value = false
  }
}
</script>
<template>
  <SettingsDialog class="reward-dialog" :title="reward ? 'リワードを編集' : 'リワードを作成'" :busy="busy" @close="emit('close')">
    <p v-if="error" role="alert" class="mb-3 text-sm text-red-600 dark:text-red-300">{{ error }}</p>
    <form class="reward-form min-w-0 max-w-full space-y-4" @submit.prevent="save">
      <fieldset :disabled="busy" class="space-y-4">
        <label class="block text-sm">名称<input v-model="name" required maxlength="50" class="reward-input" /></label>
        <label class="block text-sm">報酬の説明<textarea v-model="description" required maxlength="500" rows="3" class="reward-input" /></label>
        <label class="block text-sm">条件指標<select v-model="metric" class="reward-input"><option value="activities">活動した日数（1日1回）</option><option value="likes">受け取ったいいね数</option></select></label>
        <label class="block text-sm">必要数の指定<select v-model="countMode" aria-label="必要数の指定" class="reward-input"><option value="fixed">固定数</option><option value="period_days">期間内日数</option></select></label>
        <label v-if="countMode === 'fixed'" class="block text-sm">必要数<input v-model.number="requiredCount" type="number" min="1" max="10000" step="1" required class="reward-input" /></label>
        <p v-else class="text-sm text-slate-600 dark:text-slate-300">各サイクルの全日数を必要数にします（10月は31、11月は30）。{{ metric === 'activities' ? '期間中の全日で活動すると達成します。' : '日数と同じ件数の受信いいねで達成します。毎日の受信は不要です。' }}</p>
        <label class="block text-sm">カテゴリ<select v-model="categoryId" class="reward-input"><option :value="null">全カテゴリ</option><option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option></select></label>
        <RewardTrophySelect v-model="color" :disabled="busy" />
        <fieldset class="rounded-lg border border-slate-300 dark:border-slate-600 p-3"><legend class="text-sm">対象者（複数選択可）</legend>
          <div class="flex flex-wrap gap-2 pb-2"><button type="button" class="min-h-11 rounded-lg border border-slate-400 px-3 text-sm" @click="targetIds = members.map(member => member.membership_id)">全選択</button><button type="button" class="min-h-11 rounded-lg border border-slate-400 px-3 text-sm" @click="targetIds = []">全チェック解除</button></div>
          <label v-for="member in members" :key="member.membership_id" class="flex min-h-11 items-center gap-3 text-sm"><input v-model="targetIds" type="checkbox" :value="member.membership_id" />{{ member.display_name }}（{{ member.role === 'admin' ? '親' : '子' }}）</label>
        </fieldset>
        <label class="block text-sm">開始月<input v-model="startMonth" type="month" :min="periodLocked ? undefined : currentMonth" :disabled="periodLocked" required class="reward-input" /></label>
        <label class="block text-sm">繰り返し周期（月）<select v-model.number="cycle" :disabled="periodLocked" class="reward-input"><option v-for="month in 12" :key="month" :value="month">{{ month }}か月</option></select></label>
        <p v-if="periodLocked" class="text-sm text-slate-600 dark:text-slate-300">開始月・繰り返し周期は、期間開始後に一度でも有効化すると変更できません。</p>
        <p class="text-sm text-slate-600 dark:text-slate-300">タイムゾーン：{{ timezone }}。月初開始で停止するまで繰り返します。</p>
        <p v-if="reward?.started && reward.status !== 'draft'" class="text-sm text-slate-600 dark:text-slate-300">変更は次サイクルから適用されます。獲得済みトロフィーは維持されます。</p>
        <p v-else class="text-sm text-slate-600 dark:text-slate-300">{{ reward ? '変更は初回開始時の条件に反映されます。' : '下書きとして保存します。管理画面から有効化してください。' }}</p>
      </fieldset>
      <button type="submit" :disabled="busy" class="w-full min-h-11 rounded-lg bg-blue-600 text-white disabled:opacity-50">{{ busy ? '保存中…' : reward ? '変更を保存' : '下書きとして保存' }}</button>
    </form>
  </SettingsDialog>
</template>
<style scoped>
.reward-dialog { overflow-x: hidden; }
.reward-form fieldset, .reward-form label { min-inline-size: 0; max-inline-size: 100%; overflow-wrap: anywhere; }
.reward-input { box-sizing: border-box; display: block; width: 100%; min-width: 0; max-width: 100%; margin-top: 4px; padding: 10px; border: 1px solid #94a3b8; border-radius: 8px; background: transparent; font-size: 16px; }
.reward-input[type="month"] { appearance: none; min-inline-size: 0; }
.reward-input::-webkit-date-and-time-value { min-width: 0; text-align: left; }
.reward-input option { background-color: #fff; color: #0f172a; }
[data-theme='dark'] .reward-input option { background-color: #1e293b; color: #f1f5f9; }
.reward-input:disabled { cursor: not-allowed; opacity: 0.6; border-style: dashed; }
</style>
