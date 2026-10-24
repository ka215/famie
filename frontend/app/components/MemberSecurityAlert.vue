<script setup lang="ts">
defineProps<{ membershipId: number; name: string }>()
const { t } = useI18n()
const { state, hasUnread, open } = useSecurityAlerts()
</script>

<template>
  <button v-if="hasUnread(membershipId)" type="button" :aria-label="t('auth.alerts.openMember', { name })" :disabled="state.loading || state.busy" class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-md text-amber-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 disabled:opacity-50 dark:text-amber-300" @click="open(membershipId)">
    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" class="h-5 w-5">
      <path d="M16 22H12V24H6V13.32C3.58 12.17 2 9.72 2 7C2 3.14 5.14 0 9 0C12.86 0 16 3.14 16 7C16 9.72 14.42 12.18 12 13.32V16H16V22M14 18H10V11.94L10.67 11.71C12.66 11 14 9.11 14 7C14 4.24 11.76 2 9 2S4 4.24 4 7C4 9.11 5.34 11 7.33 11.71L8 11.94V22H10V20H14V18M12 7C12 8.66 10.66 10 9 10S6 8.66 6 7 7.34 4 9 4 12 5.34 12 7M9 8C9.55 8 10 7.55 10 7S9.55 6 9 6 8 6.45 8 7 8.45 8 9 8M20 7H22V13H20M20 17H22V15H20" />
    </svg>
  </button>
</template>
