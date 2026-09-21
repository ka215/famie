// https://nuxt.com/docs/api/configuration/nuxt-config
import tailwindcss from '@tailwindcss/vite'

export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  // Apache(127.0.0.1経由のProxyPass)から到達できるようIPv4でも待ち受ける
  devServer: {
    host: '0.0.0.0',
    port: 3000,
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
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8000/api/v1',
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
      theme_color: '#3B82F6',
      background_color: '#FFFFFF',
      display: 'standalone',
      icons: [
        { src: 'pwa-192x192.png', sizes: '192x192', type: 'image/png' },
        { src: 'pwa-512x512.png', sizes: '512x512', type: 'image/png' },
      ],
    },
    client: {
      installPrompt: true,
    },
    workbox: {
      navigateFallback: '/',
    },
  },
})
