<script setup lang="ts">
const props = defineProps<{
  href: string
  title: string
  returnTo?: 'login' | 'register' | 'settings' | 'legal-confirmation'
}>()
const open = ref(false)
const documentTitle = ref(props.title)
const viewerId = useId()
const frame = ref<HTMLIFrameElement | null>(null)
const updateTitle = () => {
  documentTitle.value =
    frame.value?.contentDocument?.querySelector('h1')?.textContent || props.title
}
const source = computed(() => {
  const url = new URL(props.href, 'https://famie.invalid')
  url.searchParams.set('from', props.returnTo ?? 'login')
  url.searchParams.set('embedded', '1')
  return `${url.pathname}${url.search}`
})
const close = () => {
  if (window.history.state?.famieLegalViewer === viewerId) window.history.back()
  else open.value = false
}
const show = (event: MouseEvent) => {
  if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return
  event.preventDefault()
  documentTitle.value = props.title
  window.history.pushState({ ...window.history.state, famieLegalViewer: viewerId }, '')
  open.value = true
}
const onHistory = () => {
  open.value = window.history.state?.famieLegalViewer === viewerId
}
const receive = (event: MessageEvent) => {
  if (
    open.value &&
    event.origin === window.location.origin &&
    event.source === frame.value?.contentWindow &&
    event.data?.type === 'famie:legal-back'
  )
    close()
}
onMounted(() => {
  window.addEventListener('message', receive)
  window.addEventListener('popstate', onHistory)
})
onBeforeUnmount(() => {
  window.removeEventListener('message', receive)
  window.removeEventListener('popstate', onHistory)
})
</script>

<template>
  <a :href="`${href}${href.includes('?') ? '&' : '?'}from=${returnTo ?? 'login'}`" class="text-blue-600 dark:text-blue-300" @click="show"><slot>{{ title }}</slot></a>
  <SettingsDialog v-if="open" :title="documentTitle" :busy="false" class="legal-document-dialog" @close="close">
    <iframe ref="frame" :src="source" :title="documentTitle" class="h-[65dvh] w-full rounded-lg border-0" @load="updateTitle" />
  </SettingsDialog>
</template>

<style scoped>
.legal-document-dialog :deep(#settings-dialog-title) {
  font-size: 1.25rem;
  line-height: 1.4;
}
</style>
