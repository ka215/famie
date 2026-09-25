<script setup lang="ts">
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
      choice?.outcome === 'accepted'
        ? '追加を受け付けました。ホーム画面をご確認ください。'
        : '追加は行われませんでした。ブラウザのメニューからも追加できます。'
  } catch {
    installMessage.value = '追加ダイアログを開けませんでした。下の手順で追加してください。'
  } finally {
    isInstalling.value = false
  }
}
</script>

<template>
  <section v-if="!isStandalone" aria-label="ホーム画面への追加ガイド" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4">
    <h2 class="font-bold text-slate-800">ホーム画面に追加してご利用ください</h2>
    <p class="text-sm text-slate-600">アイコンからFamieを開けます。ログイン済みならタイムライン、未ログインならログイン画面が開きます。</p>
    <figure class="flex flex-col items-center gap-2">
      <img :src="qrCode" width="160" height="160" alt="Famieのトップページを開くQRコード" class="bg-white">
      <figcaption class="text-xs text-slate-500">別の端末で開く場合は読み取ってください。</figcaption>
      <a href="https://famie.ka2.org/" class="text-sm text-blue-600 underline">https://famie.ka2.org/</a>
    </figure>
    <button v-if="canInstall" type="button" :disabled="isInstalling" class="w-full rounded-xl bg-blue-600 px-4 py-3 font-medium text-white disabled:opacity-50" @click="installApp">
      {{ isInstalling ? '追加を確認中…' : 'ホーム画面に追加する' }}
    </button>
    <p v-if="installMessage" role="status" class="text-sm text-slate-600">{{ installMessage }}</p>
    <div class="flex gap-2" role="group" aria-label="手順を表示する端末">
      <button v-for="device in (['iphone', 'android'] as const)" :key="device" type="button" :aria-pressed="guideDevice === device" class="flex-1 rounded-lg border px-3 py-2 text-sm" :class="guideDevice === device ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-slate-300 text-slate-600'" @click="guideDevice = device">
        {{ device === 'iphone' ? 'iPhone' : 'Android' }}
      </button>
    </div>
    <div v-if="guideDevice === 'iphone'" class="rounded-xl bg-blue-50 p-4 space-y-3">
      <p class="text-sm font-semibold text-blue-900">Safariで開き、次の順にタップしてください。</p>
      <div class="flex flex-wrap items-center gap-2 text-sm text-blue-900" aria-label="共有、ホーム画面に追加の順にタップ">
        <span class="rounded-lg bg-white px-3 py-2">共有ボタン</span>
        <span aria-hidden="true">→</span>
        <span class="rounded-lg bg-white px-3 py-2">ホーム画面に追加</span>
      </div>
      <p class="text-sm text-slate-600">共有ボタンが見つからない場合はブラウザのメニューを開きます。続いて画面の案内に沿って「追加」をタップしてください。</p>
    </div>
    <ol v-else class="list-decimal pl-5 space-y-2 text-sm text-slate-600">
      <li>Chromeなどの対応ブラウザでFamieを開きます。</li>
      <li>上の追加ボタンが表示されている場合はタップします。表示されない場合はブラウザのメニュー（︙）を開きます。</li>
      <li>「ホーム画面に追加」または「アプリをインストール」を選び、画面の案内に沿って追加します。</li>
    </ol>
    <p class="text-xs text-slate-500">端末やブラウザによって表示が異なります。追加済みの場合など、追加ボタンが表示されないことがあります。</p>
  </section>
</template>
