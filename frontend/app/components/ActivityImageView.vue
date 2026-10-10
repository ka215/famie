<script setup lang="ts">
import closeIcon from '~/assets/icons/close.svg'

const closeIconStyle = { maskImage: `url("${closeIcon}")` }
const props = defineProps<{ imageId: number; width: number; height: number; square?: boolean }>()
const { imageUrl, releaseUrl } = useActivityImage()
const url = ref('')
const expanded = ref(false)
const error = ref(false)
const trigger = ref<HTMLButtonElement | null>(null)
const container = ref<HTMLElement | null>(null)
let requestId = 0
let observer: IntersectionObserver | null = null

const load = async (id: number) => {
  const current = ++requestId
  if (url.value) releaseUrl(url.value)
  url.value = ''
  error.value = false
  try {
    const next = await imageUrl(id)
    if (current === requestId) url.value = next
    else releaseUrl(next)
  } catch {
    if (current === requestId) error.value = true
  }
}

watch(
  () => props.imageId,
  (id) => {
    if (url.value) releaseUrl(url.value)
    url.value = ''
    if (container.value) load(id)
  }
)

onMounted(() => {
  observer = new IntersectionObserver(
    (entries) => {
      if (entries[0]?.isIntersecting) {
        observer?.disconnect()
        load(props.imageId)
      }
    },
    { root: document.querySelector('.app-scroll-area'), rootMargin: '250px' }
  )
  if (container.value) observer.observe(container.value)
})

onBeforeUnmount(() => {
  requestId++
  observer?.disconnect()
  if (url.value) releaseUrl(url.value)
})
const close = () => {
  expanded.value = false
  nextTick(() => trigger.value?.focus())
}
</script>

<template>
  <div ref="container" :style="{ aspectRatio: square ? '1 / 1' : `${width} / ${height}` }" class="relative overflow-hidden rounded-lg bg-slate-100 dark:bg-slate-900">
    <button v-if="url" ref="trigger" type="button" class="h-full w-full" aria-label="画像を拡大" @click="expanded = true">
      <img :src="url" alt="アクティビティの添付画像" class="h-full w-full" :class="square ? 'object-cover object-center' : 'object-contain'" loading="lazy">
    </button>
    <p v-else-if="error" class="p-2 text-xs text-red-600" role="alert">画像を読み込めませんでした。</p>
  </div>
  <Teleport to="body">
    <div v-if="expanded && url" role="dialog" aria-modal="true" aria-label="添付画像" class="fixed inset-0 z-50 flex flex-col items-center justify-center bg-black/90 p-4" @keydown.esc="close">
      <button type="button" aria-label="閉じる" class="flex h-11 w-11 self-end items-center justify-center border-0 bg-transparent text-white" autofocus @click="close">
        <span aria-hidden="true" class="h-7 w-7 bg-current [mask-size:contain] [mask-repeat:no-repeat] [mask-position:center]" :style="closeIconStyle" />
      </button>
      <img :src="url" alt="アクティビティの添付画像" class="min-h-0 max-h-full max-w-full object-contain">
    </div>
  </Teleport>
</template>
