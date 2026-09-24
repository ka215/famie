<script setup lang="ts">
const { isMaintenance } = useMaintenance()
useHead({
  link: [{ rel: 'icon', href: '/icon.svg', type: 'image/svg+xml' }],
})
</script>

<template>
  <div>
    <NuxtRouteAnnouncer />
    <MaintenanceNotice v-if="isMaintenance" />
    <div v-show="!isMaintenance" :inert="isMaintenance">
      <NuxtLayout>
        <NuxtPage />
      </NuxtLayout>
    </div>
  </div>
</template>
