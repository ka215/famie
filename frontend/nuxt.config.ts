// https://nuxt.com/docs/api/configuration/nuxt-config
import tailwindcss from '@tailwindcss/vite'

const isProduction = import.meta.env.NODE_ENV === 'production'

export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  ssr: false,
  nitro: {
    devProxy:
      import.meta.env.FAMIE_E2E === '1'
        ? { '/api/v1': { target: 'http://127.0.0.1:8100/v1', changeOrigin: true } }
        : {},
  },
  devtools: { enabled: !isProduction },

  // Apache(127.0.0.1経由のProxyPass)から到達できるようIPv4でも待ち受ける
  devServer: {
    host: '0.0.0.0',
    port: 3000,
  },

  typescript: {
    strict: true,
    typeCheck: true,
    // 追加の compilerOptions が必要な場合はここに記述
    // tsConfig: {
    //   compilerOptions: {
    //     types: ['node'],
    //   }
    // }
  },

  modules: ['@vite-pwa/nuxt'],

  css: ['~/assets/css/main.css'],

  vite: {
    plugins: [tailwindcss()],
    // Apache(famie.local)からのHostヘッダーをViteの開発サーバーに許可する
    server: {
      allowedHosts: ['famie.local'],
    },
  },

  runtimeConfig: {
    public: {
      // 静的生成時に値が組み込まれる。本番は同一 Origin の API を使用する。
      apiEndpoint: isProduction
        ? '/api/v1'
        : import.meta.env.NUXT_DEV_API_BASE ||
          import.meta.env.NUXT_PUBLIC_API_BASE ||
          'http://localhost:8000/v1',
    },
  },

  pwa: {
    registerType: 'autoUpdate',
    // 開発中はService Workerが古いレスポンスをキャッシュしないよう無効化する
    devOptions: {
      enabled: false,
    },
    manifest: {
      name: 'Famie',
      short_name: 'ファミー',
      description: '家族のデイリーアクティビティ記録アプリ',
      lang: 'ja',
      theme_color: '#3B82F6',
      background_color: '#FFFFFF',
      display: 'standalone',
      icons: [{ src: 'icon.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'any maskable' }],
    },
    client: {
      installPrompt: true,
    },
    workbox: {
      navigateFallback: '/',
      navigateFallbackDenylist: [/^\/api(?:\/|$)/],
      cleanupOutdatedCaches: true,
      runtimeCaching: [
        {
          urlPattern: /\/api(?:\/|$)/,
          handler: 'NetworkOnly',
          method: 'GET',
        },
      ],
    },
  },
})
