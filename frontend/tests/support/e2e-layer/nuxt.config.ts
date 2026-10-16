import { fileURLToPath } from 'node:url'
import { defineNuxtConfig } from 'nuxt/config'

// Keep test builds separate from the running dev server and release artifacts.
export default defineNuxtConfig({
  nitro: {
    output: {
      dir: fileURLToPath(new URL('../../../.cache/e2e-output', import.meta.url)),
      publicDir: fileURLToPath(new URL('../../../.cache/e2e-output/public', import.meta.url)),
    },
  },
})
