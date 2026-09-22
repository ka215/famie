<script setup lang="ts">
useHead({
  link: [{ rel: 'icon', href: '/icon.svg', type: 'image/svg+xml' }],
})
</script>

<template>
  <div>
    <NuxtRouteAnnouncer />
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </div>
</template>
