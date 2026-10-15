<script setup lang="ts">
import type { TrophyColor } from '#shared/types/rewards'

const color = defineModel<TrophyColor>({ required: true })
const props = defineProps<{ disabled?: boolean }>()
const colors: TrophyColor[] = ['gold', 'silver', 'bronze']
const open = ref(false)
const root = useTemplateRef('root')
const trigger = useTemplateRef('trigger')
const menuId = useId()
let focused = 0
const focusOption = async (index: number) => {
  focused = (index + colors.length) % colors.length
  open.value = true
  await nextTick()
  root.value?.querySelectorAll<HTMLElement>('[role="option"]')[focused]?.focus()
}
const close = (restoreFocus = false) => {
  open.value = false
  if (restoreFocus) trigger.value?.focus()
}
const choose = (value: TrophyColor) => {
  if (props.disabled) return
  color.value = value
  close(true)
}
const toggle = () => {
  if (open.value) close()
  else focusOption(colors.indexOf(color.value))
}
const keydown = (event: KeyboardEvent) => {
  if (props.disabled) return
  if (event.key === 'Tab') {
    close()
    return
  }
  if (event.key === 'Escape') {
    if (open.value) {
      event.preventDefault()
      event.stopPropagation()
      close(true)
    }
    return
  }
  if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
    event.preventDefault()
    const index =
      event.key === 'Home'
        ? 0
        : event.key === 'End'
          ? colors.length - 1
          : open.value
            ? focused + (event.key === 'ArrowDown' ? 1 : -1)
            : colors.indexOf(color.value)
    focusOption(index)
  } else if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault()
    if (open.value) choose(colors[focused] ?? 'bronze')
    else focusOption(colors.indexOf(color.value))
  }
}
const outside = (event: PointerEvent) => {
  if (event.target instanceof Node && !root.value?.contains(event.target)) close()
}
const focusout = (event: FocusEvent) => {
  if (!(event.relatedTarget instanceof Node) || !root.value?.contains(event.relatedTarget)) close()
}
onMounted(() => document.addEventListener('pointerdown', outside))
onBeforeUnmount(() => document.removeEventListener('pointerdown', outside))
</script>
<template>
  <div ref="root" class="relative space-y-1" @keydown="keydown" @focusout="focusout">
    <span class="block text-sm">トロフィー</span>
    <button ref="trigger" type="button" role="combobox" aria-label="トロフィー" aria-haspopup="listbox" :aria-expanded="open" :aria-controls="menuId" :disabled="disabled" class="flex min-h-11 w-full items-center justify-between rounded-lg border border-slate-400 bg-transparent px-3 py-2 text-left disabled:opacity-50" @click="toggle"><RewardTrophy :color="color" /><span aria-hidden="true" class="text-sm">▼</span></button>
    <ul v-if="open" :id="menuId" role="listbox" aria-label="トロフィー" class="absolute inset-x-0 z-20 rounded-lg border border-slate-300 bg-white p-1 shadow-lg dark:border-slate-600 dark:bg-slate-800">
      <li v-for="value in colors" :key="value" role="option" :aria-selected="value === color" tabindex="-1" class="flex min-h-11 cursor-pointer items-center justify-between rounded-md px-3 py-2 hover:bg-slate-100 focus:bg-slate-100 focus:outline-none dark:hover:bg-slate-700 dark:focus:bg-slate-700" @click="choose(value)"><RewardTrophy :color="value" /><span v-if="value === color" aria-hidden="true">✓</span></li>
    </ul>
  </div>
</template>
