import { execFileSync } from 'node:child_process'
import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { fallbackLocale, uiLocales } from '../shared/utils/locale.ts'

const frontend = fileURLToPath(new URL('../', import.meta.url))
const write = process.argv.includes('--write')
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')
const catalogs = Object.fromEntries(
  uiLocales.map(({ code }) => [code, JSON.parse(read(`i18n/locales/${code}.json`))])
)
const flatten = (object, prefix = '') =>
  Object.fromEntries(
    Object.entries(object).flatMap(([key, value]) => {
      const path = prefix ? `${prefix}.${key}` : key
      return value !== null && typeof value === 'object'
        ? Object.entries(flatten(value, path))
        : [[path, value]]
    })
  )
const flattened = Object.fromEntries(
  Object.entries(catalogs).map(([code, dictionary]) => [code, flatten(dictionary)])
)
const same = (a, b) => JSON.stringify([...a].sort()) === JSON.stringify([...b].sort())
function verify(catalogs, placeholder) {
  const reference = catalogs[fallbackLocale]
  if (!reference) throw new Error('Missing fallback dictionary')
  for (const [locale, dictionary] of Object.entries(catalogs)) {
    if (!same(Object.keys(dictionary), Object.keys(reference)))
      throw new Error(`${locale}: translation keys differ`)
    for (const [key, text] of Object.entries(dictionary)) {
      if (typeof text !== 'string' || !text.trim())
        throw new Error(`${locale}.${key}: empty translation`)
      const parameters = (text) => new Set([...text.matchAll(placeholder)].map((match) => match[1]))
      if (!same(parameters(text), parameters(reference[key])))
        throw new Error(`${locale}.${key}: parameters differ`)
    }
  }
}
verify(flattened, /\{(\w+)\}/g)
const backend = JSON.parse(
  execFileSync('php', [fileURLToPath(new URL('../../scripts/export-i18n.php', import.meta.url))], {
    encoding: 'utf8',
  })
)
if (
  !same(
    uiLocales.map((x) => x.code),
    Object.keys(backend.config.locales)
  ) ||
  backend.config.fallback !== fallbackLocale ||
  !same(
    uiLocales.map((locale) => locale.code),
    Object.keys(backend.catalogs)
  )
)
  throw new Error('Frontend/backend locale definitions differ')
for (const locale of uiLocales) {
  const other = backend.config.locales[locale.code]
  if (
    other.name !== locale.name ||
    other.intl !== locale.intl ||
    other.translation !== locale.translation
  )
    throw new Error(`${locale.code}: locale metadata differs`)
}
verify(
  Object.fromEntries(
    Object.entries(backend.catalogs).map(([code, dictionary]) => [code, flatten(dictionary)])
  ),
  /:([a-zA-Z_]\w*)/g
)
function checkReferences(directory, regex, dictionary) {
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const path = `${directory}/${entry.name}`
    if (entry.isDirectory()) checkReferences(path, regex, dictionary)
    else if (/\.(vue|ts|php)$/.test(entry.name)) {
      for (const match of readFileSync(path, 'utf8').matchAll(regex)) {
        if (!Object.hasOwn(dictionary, match[1])) throw new Error(`${path}: missing ${match[1]}`)
      }
    }
  }
}
checkReferences(
  `${frontend}/app`,
  /\b(?:t|\$t|setKey)\(\s*['"]([\w.]+)['"]/g,
  flattened[fallbackLocale]
)
checkReferences(
  `${frontend}/../backend/app`,
  /__\(\s*['"]((?:messages|errors|validation)\.[\w.]+)['"]/g,
  flatten(backend.catalogs[fallbackLocale])
)

const staticLocales = ['ja', 'en']
const html = (value) =>
  value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
const bilingual = (key) => staticLocales.map((code) => catalogs[code].static[key]).join(' / ')
const spans = (key) =>
  staticLocales
    .map((code) => `<span lang="${code}">${html(catalogs[code].static[key])}</span>`)
    .join('<br>')
function update(path, transform) {
  const previous = read(path)
  const next = transform(previous)
  if (next === previous) return
  if (!write) throw new Error(`${path}: generated translations are stale; run pnpm i18n:generate`)
  writeFileSync(new URL(`../${path}`, import.meta.url), next)
}
function region(source, name, content) {
  const pattern = new RegExp(`(<!-- i18n:${name} -->)[\\s\\S]*?(<!-- /i18n:${name} -->)`)
  if (!pattern.test(source)) throw new Error(`Missing static marker ${name}`)
  return source.replace(pattern, `$1${content}$2`)
}
update('public/maintenance.html', (source) => {
  source = source.replace(
    /<title data-i18n="title">[\s\S]*?<\/title>/,
    `<title data-i18n="title">${html(bilingual('maintenanceTitle'))}</title>`
  )
  for (const [marker, key] of Object.entries({
    heading: 'maintenanceHeading',
    help: 'maintenanceHelp',
    retry: 'retry',
    noscript: 'noScript',
  }))
    source = region(source, marker, spans(key))
  const messages = Object.fromEntries(
    ['retry', 'checking', 'stillDown', 'checkFailed'].map((key) => [key, bilingual(key)])
  )
  return source.replace(
    /(\/\* i18n:messages \*\/)[\s\S]*?(\/\* \/i18n:messages \*\/)/,
    (_, start, end) => `${start}${JSON.stringify(messages)}${end}`
  )
})
update(
  'public/maintenance.json',
  () =>
    `${JSON.stringify({ code: 'maintenance', message: `${bilingual('maintenanceHeading')}. ${bilingual('maintenanceHelp')}` }, null, 2)}\n`
)
console.log(
  `i18n: ${uiLocales.length} locales, ${Object.keys(flattened[fallbackLocale]).length} frontend messages; backend, references and static assets OK`
)
