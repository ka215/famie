<script setup lang="ts">
import { isUiLocale } from '#shared/utils/locale'
import translateIcon from '~/assets/icons/translate.svg'

const { t } = useI18n()
const open = ref(false)
const iconStyle = {
  maskImage: `url("${translateIcon}")`,
  maskSize: 'contain',
  maskRepeat: 'no-repeat',
}
const trigger = ref<HTMLButtonElement | null>(null)
async function close() {
  open.value = false
  await nextTick()
  trigger.value?.focus()
}
const { user } = useAuth()
const { guest, setGuest, choices, storageError } = useUiLocale()
const selected = computed({
  get: () => guest.value ?? 'auto',
  set: (value: string) => setGuest(isUiLocale(value) ? value : null),
})
</script>

<template>
  <div class="absolute right-3 top-3 text-sm">
    <button ref="trigger" type="button" :aria-label="t('locale.title')" :title="t('locale.title')" aria-haspopup="dialog" :aria-expanded="open" class="flex h-11 w-11 items-center justify-center rounded-full text-slate-500 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-blue-600 dark:text-slate-400 dark:hover:bg-slate-700" @click="open = true">
      <span aria-hidden="true" class="h-6 w-6 bg-current" :style="iconStyle" />
    </button>
    <SettingsDialog v-if="open" :title="t('locale.title')" :busy="false" @close="close">
      <div class="space-y-3">
        <template v-if="!user">
          <label class="flex flex-wrap items-center gap-2">
            <span>{{ t('locale.title') }}</span>
            <select v-model="selected" :aria-label="t('locale.title')" class="min-h-11 max-w-full rounded-lg border border-slate-400 bg-white px-3 dark:bg-slate-800">
              <option value="auto">{{ t('locale.auto') }}</option>
              <option v-for="choice in choices" :key="choice.code" :value="choice.code">{{ choice.name }}</option>
            </select>
          </label>
          <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('locale.guestHelp') }}</p>
          <p v-if="storageError" role="alert">{{ t('locale.storageFailed') }}</p>
        </template>
        <NuxtLink v-else to="/settings" class="inline-flex min-h-11 items-center text-blue-600 underline dark:text-blue-300">{{ t('locale.accountHelp') }}</NuxtLink>
      </div>
    </SettingsDialog>
  </div>
</template>
