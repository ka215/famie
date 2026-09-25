<script setup lang="ts">
import settingsIcon from '~/assets/icons/cog-outline.svg'
import timelineIcon from '~/assets/icons/timeline.svg'
import trophyIcon from '~/assets/icons/trophy.svg'

const { user, logout } = useAuth()
const route = useRoute()
const iconStyles = {
  timeline: { maskImage: `url("${timelineIcon}")` },
  trophy: { maskImage: `url("${trophyIcon}")` },
  settings: { maskImage: `url("${settingsIcon}")` },
}
</script>

<template>
  <div class="min-h-screen bg-slate-50 pb-[calc(5rem+env(safe-area-inset-bottom))]">
    <header class="sticky top-0 z-10 bg-white border-b border-slate-200 px-4 py-3 flex items-center justify-between shadow-xs">
      <h1 class="text-lg font-bold text-slate-800">Famie</h1>
      <div class="flex items-center space-x-3 text-sm">
        <span class="text-slate-600 font-medium">{{ user?.display_name }} さん</span>
        <button class="text-slate-400 hover:text-red-500 text-xs" @click="logout">
          ログアウト
        </button>
      </div>
    </header>

    <main class="max-w-md mx-auto p-4">
      <slot />
    </main>

    <nav aria-label="メインメニュー" class="fixed bottom-0 left-0 right-0 bg-white border-t border-slate-200 px-4 pb-[env(safe-area-inset-bottom)] z-10">
      <div class="grid grid-cols-3 max-w-md mx-auto">
      <NuxtLink to="/" :aria-current="route.path === '/' || route.path.startsWith('/logs/') ? 'page' : undefined" class="footer-item" :class="route.path === '/' || route.path.startsWith('/logs/') ? 'text-blue-600' : 'text-slate-500'">
        <span aria-hidden="true" class="footer-icon" :style="iconStyles.timeline" />
        <span class="text-xs font-medium">タイムライン</span>
      </NuxtLink>
      <button type="button" disabled aria-label="リワード（準備中）" class="footer-item text-slate-300 cursor-not-allowed">
        <span aria-hidden="true" class="footer-icon" :style="iconStyles.trophy" />
        <span class="text-xs font-medium">リワード</span>
      </button>
      <NuxtLink to="/settings" class="footer-item" :class="route.path === '/settings' ? 'text-blue-600' : 'text-slate-500'">
        <span aria-hidden="true" class="footer-icon" :style="iconStyles.settings" />
        <span class="text-xs font-medium">設定</span>
      </NuxtLink>
      </div>
    </nav>
  </div>
</template>

<style scoped>
.footer-item {
  display: flex;
  min-height: 64px;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
}

.footer-icon {
  width: 26px;
  height: 26px;
  background-color: currentColor;
  mask-size: contain;
  mask-repeat: no-repeat;
  mask-position: center;
}
</style>
