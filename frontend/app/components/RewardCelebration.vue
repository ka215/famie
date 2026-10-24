<script setup lang="ts">
const moving = ref(false)
let preference: MediaQueryList | undefined

function stop() {
  moving.value = false
}
function reduceMotion(event: MediaQueryListEvent) {
  if (event.matches) stop()
}
function visibilityChanged() {
  if (document.hidden) stop()
}
onMounted(() => {
  preference = window.matchMedia('(prefers-reduced-motion: reduce)')
  preference.addEventListener('change', reduceMotion)
  document.addEventListener('visibilitychange', visibilityChanged)
  if (!preference.matches && !document.hidden) {
    moving.value = true
  }
})
onBeforeUnmount(() => {
  stop()
  preference?.removeEventListener('change', reduceMotion)
  document.removeEventListener('visibilitychange', visibilityChanged)
})
</script>

<template>
  <div aria-hidden="true" class="reward-celebration" :class="{ 'is-moving': moving }" @animationend="stop" />
</template>

<style scoped>
.reward-celebration {
  position: absolute;
  inset: 0;
  overflow: hidden;
  pointer-events: none;
}
.reward-celebration::before {
  content: '';
  position: absolute;
  left: 50%;
  top: 50%;
  width: 150vmax;
  height: 150vmax;
  background:
    repeating-conic-gradient(from 5deg, transparent 0deg 11deg, rgb(255 255 255 / 48%) 11deg 11.7deg, transparent 11.7deg 17deg),
    repeating-conic-gradient(from 2deg, transparent 0deg 8deg, rgb(255 255 255 / 24%) 8deg 8.4deg, transparent 8.4deg 13deg),
    repeating-conic-gradient(transparent 0deg 19deg, rgb(255 255 255 / 65%) 19deg 20deg, transparent 20deg 29deg);
  mask-image: radial-gradient(circle, transparent 0% 12%, #000 48%);
  animation: reward-focus 12s linear 2 both;
  animation-play-state: paused;
}
.is-moving::before { animation-play-state: running; }
@keyframes reward-focus {
  from { transform: translate(-50%, -50%) rotate(0deg); }
  to { transform: translate(-50%, -50%) rotate(360deg); }
}
@media (prefers-reduced-motion: reduce) {
  .reward-celebration::before { animation-play-state: paused; }
}
</style>
