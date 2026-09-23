<script setup lang="ts">
defineProps<{ title: string; initialOpen?: boolean; badge?: string }>()
</script>

<template>
  <details :open="initialOpen" class="group bg-white p-4 rounded-xl border border-slate-200 shadow-2xs">
    <summary class="flex cursor-pointer items-center justify-between gap-3 list-none rounded focus-visible:outline-2 focus-visible:outline-blue-600">
      <h2 class="text-sm font-bold text-slate-800">{{ title }}</h2>
      <span class="flex items-center gap-2">
        <span v-if="badge" class="text-2xs bg-blue-50 text-blue-600 px-2 py-0.5 rounded font-medium">{{ badge }}</span>
        <span aria-hidden="true" class="text-slate-400 transition-transform group-open:rotate-180">⌄</span>
      </span>
    </summary>
    <div class="pt-4 space-y-3">
      <slot />
    </div>
  </details>
</template>
