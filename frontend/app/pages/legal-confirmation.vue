<script setup lang="ts">
import type { ApiRequestError } from '#shared/types/api'

definePageMeta({ layout: false })
const { t } = useI18n()
const { fetchApi } = useApi()
const { user, membership, token, legal, fetchUser, logout } = useAuth()
const {
  documents,
  enabled,
  loaded,
  error: legalError,
  termsAccepted,
  privacyAcknowledged,
  language,
  ready,
  load,
  payload,
} = useLegalConsent()
const busy = ref(false)
const error = ref('')
const withdrawalOpen = ref(false)
const submit = async () => {
  if (!ready.value || !enabled.value || busy.value) return
  busy.value = true
  error.value = ''
  try {
    await fetchApi('/auth/legal-acceptances', { method: 'POST', body: { legal: payload() } })
    await fetchUser()
    if (legal.value?.required) {
      error.value = t('legal.versionChanged')
      await load()
      return
    }
    await navigateTo('/')
  } catch (cause) {
    const failure = cause as ApiRequestError
    if (failure.data?.code === 'legal_version_changed') {
      await load()
      error.value = t('legal.versionChanged')
    } else error.value = failure.data?.message || t('errors.operationFailed')
  } finally {
    busy.value = false
  }
}
const withdrawn = async () => {
  withdrawalOpen.value = false
  token.value = null
  user.value = null
  membership.value = null
  legal.value = null
  await navigateTo('/lifecycle-status')
}
useHead(() => ({ title: `${t('legal.confirmTitle')} | Famie` }))
</script>

<template>
  <main class="min-h-screen bg-slate-100 px-4 py-8 text-slate-800 dark:bg-slate-900 dark:text-slate-100">
    <section class="relative mx-auto w-full max-w-lg space-y-5 rounded-2xl bg-white p-6 shadow-md dark:bg-slate-800">
      <LanguageSwitcher />
      <h1 class="pr-8 text-xl font-bold">{{ t('legal.confirmTitle') }}</h1>
      <p class="text-sm">{{ t('legal.reconfirmHelp') }}</p>
      <form v-if="loaded && enabled && legal?.required" class="space-y-4" @submit.prevent="submit">
        <LegalConsentFields v-model:terms="termsAccepted" v-model:privacy="privacyAcknowledged" :documents="documents" :language="language" />
        <p v-if="error" role="alert" class="text-sm text-red-600 dark:text-red-300">{{ error }}</p>
        <button type="submit" :disabled="busy || !ready" class="w-full rounded-lg bg-blue-600 px-4 py-3 text-white disabled:opacity-50">{{ t('legal.confirmSubmit') }}</button>
      </form>
      <div v-if="legalError" role="alert" class="text-sm text-red-600 dark:text-red-300">
        <p>{{ legalError }}</p>
        <button type="button" class="mt-2 text-blue-600 dark:text-blue-300" @click="load">{{ t('legal.retry') }}</button>
      </div>
      <NuxtLink v-if="loaded && (!enabled || !legal?.required)" to="/" class="block text-center text-sm text-blue-600 dark:text-blue-300">{{ t('legal.continue') }}</NuxtLink>
      <LegalLinks />
      <div class="flex flex-wrap justify-center gap-4 text-sm">
        <a href="mailto:support@famie.co" class="text-blue-600 dark:text-blue-300">{{ t('legal.contact') }}</a>
        <button type="button" class="text-blue-600 dark:text-blue-300" @click="logout">{{ t('legal.logout') }}</button>
        <button v-if="membership?.is_representative" type="button" class="text-red-700 dark:text-red-300" @click="withdrawalOpen = true">{{ t('settings.lifecycle.withdrawFamily') }}</button>
      </div>
    </section>
    <AccountLifecycleDialog v-if="withdrawalOpen" kind="group_withdraw" @close="withdrawalOpen = false" @committed="withdrawn" />
  </main>
</template>
