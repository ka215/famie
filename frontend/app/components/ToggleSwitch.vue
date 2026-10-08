<script setup lang="ts">
defineProps<{ modelValue: boolean; label: string; disabled?: boolean; busy?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
</script>

<template>
  <button type="button" role="switch" :aria-checked="modelValue" :aria-label="label" :aria-busy="busy" :disabled="disabled || busy" class="flex min-h-11 w-full items-center justify-between gap-3 rounded-lg py-2 text-left text-sm text-slate-700 focus-visible:outline-2 focus-visible:outline-blue-500 disabled:opacity-60 dark:text-slate-200" @click="emit('update:modelValue', !modelValue)">
    <span>{{ label }}</span>
    <span aria-hidden="true" class="flex h-6 w-11 shrink-0 items-center rounded-full p-0.5 transition-colors" :class="modelValue ? 'bg-blue-600' : 'bg-slate-300 dark:bg-slate-600'">
      <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="modelValue ? 'translate-x-5' : ''" />
    </span>
  </button>
</template>
