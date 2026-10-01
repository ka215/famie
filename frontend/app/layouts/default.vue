<script setup lang="ts">
import settingsIcon from '~/assets/icons/cog-outline.svg'
import timelineIcon from '~/assets/icons/timeline.svg'
import trophyIcon from '~/assets/icons/trophy.svg'

const { user } = useAuth()
const route = useRoute()
const iconStyles = {
  timeline: { maskImage: `url("${timelineIcon}")` },
  trophy: { maskImage: `url("${trophyIcon}")` },
  settings: { maskImage: `url("${settingsIcon}")` },
}
</script>

<template>
  <div class="app-shell bg-slate-50 dark:bg-slate-950">
    <header class="shrink-0 z-10 bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 px-4 py-3 flex items-center justify-between shadow-xs">
      <h1 class="text-lg font-bold text-slate-800 dark:text-slate-100">Famie</h1>
      <div class="flex items-center space-x-3 text-sm">
        <span class="text-slate-600 dark:text-slate-300 font-medium">{{ user?.display_name }} さん</span>
      </div>
    </header>

    <div class="app-scroll-area">
      <main class="max-w-md mx-auto p-4">
        <slot />
      </main>
    </div>

    <nav aria-label="メインメニュー" class="shrink-0 bg-white dark:bg-slate-800 border-t border-slate-200 dark:border-slate-700 px-4 pb-[env(safe-area-inset-bottom)] z-10">
      <div class="grid grid-cols-3 max-w-md mx-auto">
      <NuxtLink to="/" :aria-current="route.path === '/' || route.path.startsWith('/logs/') ? 'page' : undefined" class="footer-item" :class="route.path === '/' || route.path.startsWith('/logs/') ? 'text-blue-600 dark:text-blue-300' : 'text-slate-500 dark:text-slate-400'">
        <span aria-hidden="true" class="footer-icon" :style="iconStyles.timeline" />
        <span class="text-xs font-medium">タイムライン</span>
      </NuxtLink>
      <button type="button" disabled aria-label="リワード（準備中）" class="footer-item text-slate-300 dark:text-slate-500 cursor-not-allowed">
        <span aria-hidden="true" class="footer-icon" :style="iconStyles.trophy" />
        <span class="text-xs font-medium">リワード</span>
      </button>
      <NuxtLink to="/settings" class="footer-item" :class="route.path === '/settings' ? 'text-blue-600 dark:text-blue-300' : 'text-slate-500 dark:text-slate-400'">
        <span aria-hidden="true" class="footer-icon" :style="iconStyles.settings" />
        <span class="text-xs font-medium">設定</span>
      </NuxtLink>
      </div>
    </nav>
  </div>
</template>

<style scoped>
.app-shell {
  display: flex;
  height: 100vh;
  height: 100dvh;
  flex-direction: column;
  overflow: hidden;
}

.app-scroll-area {
  min-height: 0;
  flex: 1 1 auto;
  overflow-x: hidden;
  overflow-y: auto;
  overscroll-behavior-y: none;
  -webkit-overflow-scrolling: touch;
}

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
