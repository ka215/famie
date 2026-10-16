<script setup lang="ts">
import { PASSWORD_GUIDANCE, passwordStrength } from '#shared/utils/password-strength'

const props = defineProps<{ password: string }>()
const guidance = PASSWORD_GUIDANCE
const strength = computed(() => passwordStrength(props.password))
</script>

<template>
  <div class="mt-2 space-y-1 text-xs">
    <meter aria-label="パスワード強度の目安" min="0" max="3" low="2" high="3" optimum="3" :value="strength.score" class="w-full" />
    <p aria-live="polite" :class="strength.acceptable ? 'text-emerald-700 dark:text-emerald-300' : 'text-slate-600 dark:text-slate-300'">{{ !password ? '長いフレーズの利用をおすすめします。' : strength.acceptable ? '新しいパスワードの設定基準を満たしています。' : '設定基準を満たしていません。このままでは設定できません。' }}</p>
    <p class="text-slate-500 dark:text-slate-400">{{ guidance }} 大文字や記号は必須ではありません。強度表示は目安です。</p>
  </div>
</template>
