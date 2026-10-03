<script setup lang="ts">
defineProps<{ title: string; busy: boolean }>()
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
  <dialog ref="dialog" aria-labelledby="settings-dialog-title" :aria-busy="busy" class="settings-dialog bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 rounded-2xl p-5" @cancel.prevent="!busy && emit('close')">
    <div class="flex items-center justify-between gap-4 mb-4">
      <h2 id="settings-dialog-title" class="font-bold">{{ title }}</h2>
      <button type="button" :disabled="busy" class="min-h-11 px-3 rounded-lg border border-slate-300 dark:border-slate-600 disabled:opacity-50" @click="emit('close')">閉じる</button>
    </div>
    <slot />
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
</style>
