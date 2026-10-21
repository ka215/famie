<script setup lang="ts">
const { t, locale } = useI18n()

import { passwordStrength } from '#shared/utils/password-strength'

const props = defineProps<{ password: string }>()
const guidance = computed(() => t('auth.strength.guidance'))
const strength = computed(() => passwordStrength(props.password))
</script>

<template>
  <div class="mt-2 space-y-1 text-xs">
    <meter :aria-label="t('auth.strength.label')" min="0" max="3" low="2" high="3" optimum="3" :value="strength.score" class="w-full" />
    <p aria-live="polite" :class="strength.acceptable ? 'text-emerald-700 dark:text-emerald-300' : 'text-slate-600 dark:text-slate-300'">{{ !password ? t('auth.strength.intro') : strength.acceptable ? t('auth.strength.acceptable') : t('auth.strength.unacceptable') }}</p>
    <p class="text-slate-500 dark:text-slate-400">{{ guidance }} {{ t('auth.strength.note') }}</p>
  </div>
</template>
