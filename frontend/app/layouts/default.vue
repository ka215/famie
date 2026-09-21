<script setup lang="ts">
const { user, logout } = useAuth()
</script>

<template>
  <div class="min-h-screen bg-slate-50 pb-20">
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

    <nav class="fixed bottom-0 left-0 right-0 bg-white border-t border-slate-200 py-2 px-6 flex justify-around items-center z-10">
      <NuxtLink to="/" class="flex flex-col items-center text-blue-600">
        <span class="text-xs font-medium">タイムライン</span>
      </NuxtLink>
      <NuxtLink to="/settings" class="flex flex-col items-center text-slate-400 hover:text-slate-600">
        <span class="text-xs font-medium">設定</span>
      </NuxtLink>
    </nav>
  </div>
</template>
