<script setup lang="ts">
const { t, locale } = useI18n()
defineProps<{ title: string }>()
</script>

<template>
  <div class="flex min-h-screen items-start justify-center bg-slate-100 px-4 py-8 dark:bg-slate-900">
    <main class="relative w-full max-w-sm space-y-5 rounded-2xl bg-white p-6 shadow-md dark:bg-slate-800">
      <LanguageSwitcher />
      <h1 class="pr-10 text-xl font-bold">{{ title }}</h1>
      <slot />
      <NuxtLink to="/login" class="block text-sm text-blue-600 dark:text-blue-300">{{ t('auth.openLogin') }}</NuxtLink>
      <NuxtLink to="/settings" class="block text-sm text-blue-600 dark:text-blue-300">{{ t('auth.openSettings') }}</NuxtLink>
    </main>
  </div>
</template>
