<script setup lang="ts">
import type { LegalDocument } from '#shared/types/api'

withDefaults(
  defineProps<{
    documents: LegalDocument[]
    language: 'ja' | 'en'
    returnTo?: 'register' | 'legal-confirmation'
  }>(),
  { returnTo: 'register' }
)
const terms = defineModel<boolean>('terms', { required: true })
const privacy = defineModel<boolean>('privacy', { required: true })
const { t } = useI18n()
</script>

<template>
  <fieldset class="space-y-3 rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-600">
    <legend class="px-1 font-medium">{{ t('legal.confirmTitle') }}</legend>
    <p class="text-xs text-slate-600 dark:text-slate-300">{{ t('legal.checkHelp') }}</p>
    <div v-for="document in documents.filter(entry => entry.id !== 'cookies')" :key="document.id" class="space-y-2">
      <LegalDocumentLink :href="`/legal/${document.id}/${document.version}/${language}/`" :title="document.translations[language].title" :return-to="returnTo">{{ document.translations[language].title }} (v{{ document.version }})</LegalDocumentLink>
      <label v-if="document.id === 'terms'" class="flex items-start gap-2">
        <input v-model="terms" type="checkbox" required class="mt-1 size-4 shrink-0">
        <span>{{ t('legal.acceptTerms') }}</span>
      </label>
      <label v-else class="flex items-start gap-2">
        <input v-model="privacy" type="checkbox" required class="mt-1 size-4 shrink-0">
        <span>{{ t('legal.acknowledgePrivacy') }}</span>
      </label>
    </div>
    <p class="text-xs text-slate-600 dark:text-slate-300">{{ t('legal.privacyHelp') }}</p>
  </fieldset>
</template>
