<script setup lang="ts">
const { t, locale } = useI18n()

import qrImage from '~/assets/icons/famie-qr.svg'

const qrCode = qrImage
const { $pwa } = useNuxtApp()
const guideDevice = ref<'iphone' | 'android'>('iphone')
const isStandalone = ref(false)
const isInstalling = ref(false)
const installMessage = ref('')
const canInstall = computed(() => !!$pwa?.showInstallPrompt)

const markInstalled = () => {
  isStandalone.value = true
}

onMounted(() => {
  guideDevice.value = /Android/i.test(navigator.userAgent) ? 'android' : 'iphone'
  isStandalone.value =
    window.matchMedia('(display-mode: standalone)').matches ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true
  window.addEventListener('appinstalled', markInstalled)
})

onBeforeUnmount(() => window.removeEventListener('appinstalled', markInstalled))

const installApp = async () => {
  if (!canInstall.value || isInstalling.value) return
  isInstalling.value = true
  installMessage.value = ''
  try {
    const choice = await $pwa?.install()
    installMessage.value =
      choice?.outcome === 'accepted' ? t('install.accepted') : t('install.dismissed')
  } catch {
    installMessage.value = t('install.failed')
  } finally {
    isInstalling.value = false
  }
}
watch(locale, () => {
  installMessage.value = ''
})
</script>

<template>
  <section v-if="!isStandalone" :aria-label="t('install.guideLabel')" class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 p-6 space-y-4">
    <h2 class="font-bold text-slate-800 dark:text-slate-100">{{ t('install.title') }}</h2>
    <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('install.intro') }}</p>
    <figure class="flex flex-col items-center gap-2">
      <img :src="qrCode" width="160" height="160" :alt="t('install.qrLabel')" class="bg-white">
      <figcaption class="text-xs text-slate-500 dark:text-slate-400">{{ t('install.qrHelp') }}</figcaption>
      <a href="https://famie.co/" class="text-sm text-blue-600 dark:text-blue-300 underline">https://famie.co/</a>
    </figure>
    <button v-if="canInstall" type="button" :disabled="isInstalling" class="w-full rounded-xl bg-blue-600 px-4 py-3 font-medium text-white disabled:opacity-50" @click="installApp">
      {{ isInstalling ? t('install.checking') : t('install.submit') }}
    </button>
    <p v-if="installMessage" role="status" class="text-sm text-slate-600 dark:text-slate-300">{{ installMessage }}</p>
    <div class="flex gap-2" role="group" :aria-label="t('install.device')">
      <button v-for="device in (['iphone', 'android'] as const)" :key="device" type="button" :aria-pressed="guideDevice === device" class="flex-1 rounded-lg border px-3 py-2 text-sm" :class="guideDevice === device ? 'border-blue-600 bg-blue-50 dark:bg-blue-950 text-blue-700 dark:text-blue-300' : 'border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300'" @click="guideDevice = device">
        {{ device === 'iphone' ? 'iPhone' : 'Android' }}
      </button>
    </div>
    <div v-if="guideDevice === 'iphone'" class="rounded-xl bg-blue-50 dark:bg-blue-950 p-4 space-y-3">
      <p class="text-sm font-semibold text-blue-900 dark:text-blue-100">{{ t('install.iosIntro') }}</p>
      <div class="flex flex-wrap items-center gap-2 text-sm text-blue-900 dark:text-blue-100" :aria-label="t('install.iosDiagram')">
        <span class="rounded-lg bg-white dark:bg-slate-800 px-3 py-2">{{ t('install.shareButton') }}</span>
        <span aria-hidden="true">→</span>
        <span class="rounded-lg bg-white dark:bg-slate-800 px-3 py-2">{{ t('install.addAction') }}</span>
      </div>
      <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('install.iosHelp') }}</p>
    </div>
    <ol v-else class="list-decimal pl-5 space-y-2 text-sm text-slate-600 dark:text-slate-300">
      <li>{{ t('install.androidIntro') }}</li>
      <li>{{ t('install.androidMenu') }}</li>
      <li>{{ t('install.androidAction') }}</li>
    </ol>
    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('install.compatibility') }}</p>
  </section>
</template>
