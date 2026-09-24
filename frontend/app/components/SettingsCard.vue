<script setup lang="ts">
defineProps<{ title: string; initialOpen?: boolean; badge?: string }>()
</script>

<template>
  <details :open="initialOpen" class="group bg-white p-4 rounded-xl border border-slate-200 shadow-2xs">
    <summary class="flex cursor-pointer items-center justify-between gap-3 list-none rounded focus-visible:outline-2 focus-visible:outline-blue-600">
      <h2 class="text-sm font-bold text-slate-800">{{ title }}</h2>
      <span class="flex items-center gap-2">
        <span v-if="badge" class="text-2xs bg-blue-50 text-blue-600 px-2 py-0.5 rounded font-medium">{{ badge }}</span>
        <!-- assets/icons/caret-down.svg のパスを使用し、上下中心（y=136）を回転中心に合わせる。 -->
        <svg aria-hidden="true" focusable="false" viewBox="0 8 256 256" fill="currentColor" class="block size-4 shrink-0 origin-center text-slate-400 transition-transform group-open:rotate-180">
          <path d="M216.49,104.49l-80,80a12,12,0,0,1-17,0l-80-80a12,12,0,0,1,17-17L128,159l71.51-71.52a12,12,0,0,1,17,17Z" />
        </svg>
      </span>
    </summary>
    <div class="pt-4 space-y-3">
      <slot />
    </div>
  </details>
</template>
