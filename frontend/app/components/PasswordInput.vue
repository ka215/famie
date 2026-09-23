<script setup lang="ts">
defineOptions({ inheritAttrs: false })
defineProps<{ id: string; label: string; disabled?: boolean }>()
const model = defineModel<string>({ required: true })
const visible = ref(false)

watch(model, (value) => {
  if (!value) visible.value = false
})
</script>

<template>
  <div class="relative">
    <input
      v-bind="$attrs"
      :id="id"
      v-model="model"
      :type="visible ? 'text' : 'password'"
      :disabled="disabled"
      class="pr-12"
    >
    <button
      type="button"
      :aria-label="`${label}を${visible ? '隠す' : '表示する'}`"
      :aria-pressed="visible"
      :aria-controls="id"
      :disabled="disabled"
      class="absolute inset-y-0 right-0 w-11 flex items-center justify-center rounded-r-lg text-slate-500 hover:text-slate-800 focus-visible:outline-2 focus-visible:outline-blue-500 disabled:opacity-50"
      @click="visible = !visible"
    >
      <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5">
        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" />
        <circle cx="12" cy="12" r="3" />
        <path v-if="visible" d="m3 3 18 18" />
      </svg>
    </button>
  </div>
</template>
