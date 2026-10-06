<script setup lang="ts">
defineProps<{ title: string }>()
</script>

<template>
  <div class="flex min-h-screen items-start justify-center bg-slate-100 px-4 py-8 dark:bg-slate-900">
    <main class="w-full max-w-sm space-y-5 rounded-2xl bg-white p-6 shadow-md dark:bg-slate-800">
      <h1 class="text-xl font-bold">{{ title }}</h1>
      <slot />
      <NuxtLink to="/login" class="block text-sm text-blue-600 dark:text-blue-300">ログイン画面へ</NuxtLink>
      <NuxtLink to="/settings" class="block text-sm text-blue-600 dark:text-blue-300">設定画面へ</NuxtLink>
    </main>
  </div>
</template>
