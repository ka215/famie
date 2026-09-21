Laravelバックエンドの設計を受け、Nuxt 4側のフロントエンド実装コードを作成しました。

Nuxt 4のディレクトリ構造（`app/` ディレクトリ配置推奨）に準拠し、`@vite-pwa/nuxt` を活用したPWA構成および `useFetch` をラップしたAPI通信Composable、ログイン画面の実装となっています。

---

## 1. モジュール追加・設定 (`nuxt.config.ts`)

Nuxt 4 の設定ファイルに PWA モジュール（`@vite-pwa/nuxt`）および Tailwind CSS、環境変数のプロキシ/API設定を記述します。

```typescript
// nuxt.config.ts
export default defineNuxtConfig({
  // Nuxt 4 の標準ディレクトリ構造の設定
  future: {
    compatibilityVersion: 4,
  },

  modules: [
    '@vite-pwa/nuxt',
    '@tailwindcss/nuxt'
  ],

  runtimeConfig: {
    public: {
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8000/api/v1',
    },
  },

  pwa: {
    registerType: 'autoUpdate',
    manifest: {
      name: 'Family Activity Log',
      short_name: 'ファミログ',
      description: '家族のデイリーアクティビティ記録アプリ',
      theme_color: '#3B82F6',
      background_color: '#FFFFFF',
      display: 'standalone', // スタンドアロン表示（URLバーなし）
      icons: [
        {
          src: 'pwa-192x192.png',
          sizes: '192x192',
          type: 'image/png',
        },
        {
          src: 'pwa-512x512.png',
          sizes: '512x512',
          type: 'image/png',
        },
      ],
    },
    client: {
      installPrompt: true,
    },
    workbox: {
      navigateFallback: '/',
    },
  },

  compatibilityDate: '2026-09-21',
})

```

---

## 2. API 通信 Composable (`app/composables/useApi.ts`)

Laravel API 通信時に Bearer トークンを自動設定し、401（未認証）エラー時にログイン画面へリダイレクトする汎用フックです。

```typescript
// app/composables/useApi.ts
export const useApi = () => {
  const config = useRuntimeConfig()
  const token = useCookie<string | null>('auth_token')

  const fetchApi = async <T>(request: string, options: Parameters<typeof $fetch>[1] = {}) => {
    return await $fetch<T>(request, {
      baseURL: config.public.apiBase,
      ...options,
      headers: {
        Accept: 'application/json',
        ...(token.value ? { Authorization: `Bearer ${token.value}` } : {}),
        ...options.headers,
      },
      onResponseError({ response }) {
        if (response.status === 401) {
          token.value = null
          navigateTo('/login')
        }
      },
    })
  }

  return { fetchApi }
}

```

---

## 3. 認証状態管理 Composable (`app/composables/useAuth.ts`)

ログイン、ログアウト、ログインユーザー情報の保持を行う Composable です。

```typescript
// app/composables/useAuth.ts
interface User {
  id: number
  username: string
  display_name: string
  role: 'parent' | 'child'
}

interface LoginResponse {
  access_token: string
  user: User
}

export const useAuth = () => {
  const user = useState<User | null>('auth_user', () => null)
  const token = useCookie<string | null>('auth_token', { maxAge: 60 * 60 * 24 * 30 }) // 30日保存
  const { fetchApi } = useApi()

  // ログイン
  const login = async (loginId: string, password: string) => {
    const res = await fetchApi<LoginResponse>('/auth/login', {
      method: 'POST',
      body: { login: loginId, password },
    })

    token.value = res.access_token
    user.value = res.user
    return res
  }

  // ログアウト
  const logout = async () => {
    try {
      if (token.value) {
        await fetchApi('/auth/logout', { method: 'POST' })
      }
    } finally {
      token.value = null
      user.value = null
      navigateTo('/login')
    }
  }

  // カレントユーザー取得
  const fetchUser = async () => {
    if (!token.value) return null
    try {
      const res = await fetchApi<{ user: User }>('/auth/me')
      user.value = res.user
      return res.user
    } catch {
      token.value = null
      user.value = null
      return null
    }
  }

  return {
    user,
    token,
    login,
    logout,
    fetchUser,
    isParent: computed(() => user.value?.role === 'parent'),
  }
}

```

---

## 4. ログイン画面 (`app/pages/login.vue`)

モバイルファーストで調整されたログインUIです。PWAとしてホーム画面に追加した際にも操作しやすいデザインにしています。

```vue
<!-- app/pages/login.vue -->
<script setup lang="ts">
definePageMeta({
  layout: false, // ログイン画面は独立レイアウト
})

const { login } = useAuth()
const loginId = ref('')
const password = ref('')
const errorMessage = ref('')
const isLoading = ref(false)

const handleLogin = async () => {
  if (!loginId.value || !password.value) {
    errorMessage.value = 'ログインIDとパスワードを入力してください。'
    return;
  }

  isLoading.value = true
  errorMessage.value = ''

  try {
    await login(loginId.value, password.value)
    await navigateTo('/')
  } catch (err: any) {
    errorMessage.value = err.data?.message || 'ログインに失敗しました。'
  } finally {
    isLoading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-slate-100 flex items-center justify-center p-4">
    <div class="w-full max-w-sm bg-white rounded-2xl shadow-md p-6 space-y-6">
      <div class="text-center space-y-1">
        <h1 class="text-2xl font-bold text-slate-800">ファミログ</h1>
        <p class="text-sm text-slate-500">家族のアクティビティ記録</p>
      </div>

      <form @submit.prevent="handleLogin" class="space-y-4">
        <div v-if="errorMessage" class="p-3 bg-red-50 text-red-600 text-sm rounded-lg border border-red-200">
          {{ errorMessage }}
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">ログインID / メード</label>
          <input
            v-model="loginId"
            type="text"
            required
            placeholder="例: parent1 または child1"
            class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-blue-500 focus:outline-none"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">パスワード</label>
          <input
            v-model="password"
            type="password"
            required
            class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:ring-2 focus:ring-blue-500 focus:outline-none"
          />
        </div>

        <button
          type="submit"
          :disabled="isLoading"
          class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition duration-200 disabled:opacity-50"
        >
          <span v-if="isLoading">ログイン中...</span>
          <span v-else>ログイン</span>
        </button>
      </form>
    </div>
  </div>
</template>

```

---

## 5. ルート保護ミドルウェア (`app/middleware/auth.global.ts`)

全ページ移動時に認証チェックを行うグローバルミドルウェアです。

```typescript
// app/middleware/auth.global.ts
export default defineNuxtRouteMiddleware(async (to) => {
  const token = useCookie<string | null>('auth_token')
  const { user, fetchUser } = useAuth()

  // 未ログインでログイン画面以外にアクセスした場合
  if (!token.value && to.path !== '/login') {
    return navigateTo('/login')
  }

  // トークンがあり、ユーザー情報未読み込みの場合は取得
  if (token.value && !user.value && to.path !== '/login') {
    const fetchedUser = await fetchUser()
    if (!fetchedUser) {
      return navigateTo('/login')
    }
  }

  // ログイン済みでログイン画面にアクセスした場合はホームにリダイレクト
  if (token.value && to.path === '/login') {
    return navigateTo('/')
  }
})

```

---