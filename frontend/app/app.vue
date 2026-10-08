<script setup lang="ts">
const { isMaintenance } = useMaintenance()
const { theme } = useTheme()
useHead(() => ({
  meta: [{ name: 'theme-color', content: theme.value === 'dark' ? '#020617' : '#f8fafc' }],
}))
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
