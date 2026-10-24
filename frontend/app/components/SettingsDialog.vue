<script setup lang="ts">
const { t, locale } = useI18n()
defineProps<{ title: string; busy: boolean; celebrate?: boolean }>()
const emit = defineEmits<{ close: [] }>()
const dialog = ref<HTMLDialogElement | null>(null)
const { isMaintenance } = useMaintenance()
onMounted(() => dialog.value?.showModal())
onBeforeUnmount(() => dialog.value?.close())
watch(isMaintenance, (active) => {
  if (active) emit('close')
})
</script>

<template>
  <dialog ref="dialog" aria-labelledby="settings-dialog-title" :aria-busy="busy" class="settings-dialog text-slate-800 dark:text-slate-100" :class="celebrate ? 'settings-dialog--celebration' : 'bg-white dark:bg-slate-800 rounded-2xl p-5'" @cancel.prevent="!busy && emit('close')">
    <RewardCelebration v-if="celebrate" />
    <div :class="celebrate ? 'settings-dialog-panel bg-white dark:bg-slate-800 rounded-2xl p-5' : undefined">
      <div class="flex items-center justify-between gap-4 mb-4">
        <h2 id="settings-dialog-title" class="font-bold">{{ title }}</h2>
        <button type="button" :disabled="busy" class="min-h-11 px-3 rounded-lg border border-slate-300 dark:border-slate-600 disabled:opacity-50" @click="emit('close')">{{ t('common.close') }}</button>
      </div>
      <slot />
    </div>
  </dialog>
</template>

<style scoped>
.settings-dialog {
  margin: auto;
  width: min(28rem, calc(100% - 2rem));
  max-height: 85dvh;
  overflow-y: auto;
}
.settings-dialog::backdrop { background: rgb(15 23 42 / 55%); }
.settings-dialog--celebration {
  place-items: center;
  width: 100%;
  height: 100%;
  max-width: none;
  max-height: none;
  padding: 0;
  border: 0;
  overflow: hidden;
  background: transparent;
}
.settings-dialog--celebration[open] { display: grid; }
.settings-dialog-panel {
  position: relative;
  width: min(28rem, calc(100% - 2rem));
  max-height: 85dvh;
  overflow-y: auto;
}
</style>
