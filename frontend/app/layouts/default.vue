<script setup lang="ts">
const { t, locale } = useI18n()

import settingsIcon from '~/assets/icons/cog-outline.svg'
import imageAlbumIcon from '~/assets/icons/image-album.svg'
import timelineIcon from '~/assets/icons/timeline.svg'
import trophyIcon from '~/assets/icons/trophy.svg'

const { user } = useAuth()
const { groupId } = useAuth()
const { fetchApi } = useApi()
const galleryVisible = ref(false)
onMounted(() => {
  if (!groupId.value) return
  fetchApi<{ data: { images?: { enabled: boolean } } }>(`/groups/${groupId.value}`)
    .then((response) => {
      galleryVisible.value = Boolean(response.data.images?.enabled)
    })
    .catch(() => {
      galleryVisible.value = false
    })
})
const route = useRoute()
const { clear: clearListReturn } = useListReturn()
const iconStyles = {
  timeline: { maskImage: `url("${timelineIcon}")` },
  trophy: { maskImage: `url("${trophyIcon}")` },
  gallery: { maskImage: `url("${imageAlbumIcon}")` },
  settings: { maskImage: `url("${settingsIcon}")` },
}
</script>

<template>
  <div class="app-shell bg-slate-50 dark:bg-slate-950">
    <header class="shrink-0 z-10 bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 px-4 py-3 flex items-center justify-between shadow-xs">
      <h1 class="text-lg font-bold text-slate-800 dark:text-slate-100">Famie</h1>
      <div class="flex items-center space-x-3 text-sm">
        <span class="text-slate-600 dark:text-slate-300 font-medium">{{ user?.show_name_suffix !== false ? t('common.displayNameWithSuffix', { name: user?.display_name ?? '' }) : user?.display_name }}</span>
      </div>
    </header>

    <div class="app-scroll-area">
      <main class="max-w-md mx-auto p-4">
        <slot />
      </main>
    </div>

    <nav :aria-label="t('navigation.label')" class="shrink-0 bg-white dark:bg-slate-800 border-t border-slate-200 dark:border-slate-700 px-4 pb-[env(safe-area-inset-bottom)] z-10">
      <div class="grid max-w-md mx-auto" :class="galleryVisible ? 'grid-cols-4' : 'grid-cols-3'">
      <NuxtLink to="/" @click="clearListReturn" :aria-current="route.path === '/' || route.path.startsWith('/logs/') ? 'page' : undefined" class="footer-item" :class="route.path === '/' || route.path.startsWith('/logs/') ? 'text-blue-600 dark:text-blue-300' : 'text-slate-500 dark:text-slate-400'">
        <span aria-hidden="true" class="footer-icon" :style="iconStyles.timeline" />
        <span class="text-xs font-medium">{{ t('navigation.timeline') }}</span>
      </NuxtLink>
      <NuxtLink to="/rewards" :aria-current="route.path === '/rewards' ? 'page' : undefined" class="footer-item" :class="route.path === '/rewards' ? 'text-blue-600 dark:text-blue-300' : 'text-slate-500 dark:text-slate-400'">
        <span aria-hidden="true" class="footer-icon" :style="iconStyles.trophy" />
        <span class="text-xs font-medium">{{ t('navigation.rewards') }}</span>
      </NuxtLink>
      <NuxtLink v-if="galleryVisible" to="/gallery" @click="clearListReturn" class="footer-item" :class="route.path === '/gallery' ? 'text-blue-600 dark:text-blue-300' : 'text-slate-500 dark:text-slate-400'">
        <span aria-hidden="true" class="footer-icon" :style="iconStyles.gallery" />
        <span class="text-xs font-medium">{{ t('navigation.gallery') }}</span>
      </NuxtLink>
      <NuxtLink to="/settings" class="footer-item" :class="route.path === '/settings' ? 'text-blue-600 dark:text-blue-300' : 'text-slate-500 dark:text-slate-400'">
        <span aria-hidden="true" class="footer-icon" :style="iconStyles.settings" />
        <span class="text-xs font-medium">{{ t('navigation.settings') }}</span>
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
