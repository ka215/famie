import { createHash } from 'node:crypto'
import { existsSync, mkdirSync, readFileSync, realpathSync, writeFileSync } from 'node:fs'
import { dirname, relative, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { marked } from 'marked'
import sanitizeHtml from 'sanitize-html'

const ids = new Set(['terms', 'privacy', 'cookies'])
const locales = ['ja', 'en']
const escapeHtml = (text) =>
  String(text).replace(
    /[&<>"']/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]
  )
const fail = (message) => {
  throw new Error(`Legal documents: ${message}`)
}
const urlAllowed = (url) =>
  /^(https?:\/\/|mailto:|\/(?!\/)|#)/i.test(url) &&
  !/[\s\\]/u.test(url) &&
  !Array.from(url).some(
    (character) => character.charCodeAt(0) < 32 || character.charCodeAt(0) === 127
  )

export function renderMarkdown(source) {
  if (source.startsWith('\uFEFF') || source.includes('\r'))
    fail('Use UTF-8 without BOM and LF line endings')
  if (!source.trim() || /\bREVIEW\b|DEVELOPMENT DRAFT|NOT FOR PUBLICATION/.test(source))
    fail('Unresolved draft')
  const tokens = marked.lexer(source)
  marked.walkTokens(tokens, (token) => {
    if (['html', 'image', 'code'].includes(token.type)) fail(`Unsupported Markdown: ${token.type}`)
    if (token.type === 'link' && !urlAllowed(token.href)) fail('Unsafe link')
  })
  const html = marked.parser(tokens)
  return sanitizeHtml(html, {
    allowedTags: [
      'h1',
      'h2',
      'h3',
      'h4',
      'h5',
      'h6',
      'p',
      'ul',
      'ol',
      'li',
      'strong',
      'em',
      'del',
      'blockquote',
      'hr',
      'br',
      'code',
      'a',
      'table',
      'thead',
      'tbody',
      'tr',
      'th',
      'td',
    ],
    allowedAttributes: { a: ['href', 'title'], ol: ['start'] },
    allowedSchemes: ['http', 'https', 'mailto'],
    allowProtocolRelative: false,
  })
}

function dateOrNull(value) {
  return (
    value === null ||
    (typeof value === 'string' &&
      /^\d{4}-\d{2}-\d{2}$/.test(value) &&
      new Date(`${value}T00:00:00Z`).toISOString().slice(0, 10) === value)
  )
}

export function buildLegal({ sourceRoot, outputRoot }) {
  const root = realpathSync(sourceRoot)
  const manifest = JSON.parse(readFileSync(resolve(root, 'manifest.json'), 'utf8'))
  if (manifest.schemaVersion !== 1 || !Array.isArray(manifest.documents)) fail('Invalid manifest')
  const seen = new Set()
  const current = new Set()
  const outputs = []
  const documents = manifest.documents.map((document) => {
    const { id, version, publishedOn, effectiveAt, effectiveOnRelease } = document
    if (!ids.has(id) || typeof version !== 'string' || !/^\d+\.\d+\.\d+$/.test(version))
      fail('Invalid document identity')
    const key = `${id}/${version}`
    if (seen.has(key)) fail('Duplicate document version')
    seen.add(key)
    const preview = document.preview === true
    if (!dateOrNull(publishedOn) || (!preview && publishedOn === null) || !dateOrNull(effectiveAt))
      fail('Invalid dates')
    if (effectiveAt === null ? effectiveOnRelease !== '1.0.0' : effectiveOnRelease !== null)
      fail('Specify effective date or release')
    if (
      document.authoritativeLocale !== 'ja' ||
      typeof document.current !== 'boolean' ||
      typeof document.requiresAcceptance !== 'boolean'
    )
      fail('Invalid document metadata')
    if (document.current && current.has(id)) fail('Multiple current versions')
    if (document.current) current.add(id)
    if (!document.titles || Object.keys(document.titles).sort().join() !== 'en,ja')
      fail('Missing language pair')
    const translations = {}
    for (const locale of locales) {
      const title = document.titles[locale]
      if (typeof title !== 'string' || !title.trim()) fail('Missing title')
      const file = realpathSync(resolve(root, id, version, `${locale}.md`))
      if (relative(root, file).startsWith('..')) fail('Source escapes legal root')
      const source = readFileSync(file, 'utf8')
      const body = renderMarkdown(source)
      const sha256 = createHash('sha256').update(source, 'utf8').digest('hex')
      const url = `/legal/${id}/${version}/${locale}/`
      const other = locale === 'ja' ? 'en' : 'ja'
      const effective =
        effectiveAt ??
        (locale === 'ja'
          ? 'v1.0.0公開日予定（未施行）'
          : 'Planned for the v1.0.0 release (not yet effective)')
      const previewNotice = preview
        ? `<aside role="note"><strong>${locale === 'ja' ? '先行確認版・未施行' : 'Preview — not yet effective'}</strong><p>${locale === 'ja' ? 'v1.0.0公開日からの施行を予定する確認用文書です。施行後の取扱方針を含み、現在の実装完了を示すものではありません。' : 'This document is provided for review ahead of its planned effective date at the v1.0.0 release. It includes future policies and does not indicate that all measures are already implemented.'}</p></aside>`
        : ''
      const html = `<!doctype html>\n<html lang="${locale}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${escapeHtml(title)} | Famie</title><style>body{font:1rem/1.8 system-ui,sans-serif;max-width:52rem;margin:2rem auto;padding:0 1rem;overflow-wrap:anywhere;color:#1f2937;background:#fff}a{color:#175cd3}table{border-collapse:collapse;display:block;overflow:auto}th,td{border:1px solid #aaa;padding:.5rem}a:focus-visible{outline:3px solid currentColor}</style></head><body><nav><a href="/legal/${id}/${version}/${other}/" lang="${other}">${other === 'ja' ? '日本語' : 'English'}</a></nav><main><h1>${escapeHtml(title)}</h1><p>${locale === 'ja' ? '文書版' : 'Version'}: ${escapeHtml(version)} · ${locale === 'ja' ? '公開日' : 'Published'}: ${publishedOn}</p><p>${locale === 'ja' ? '施行' : 'Effective'}: ${escapeHtml(effective)}</p><p>${locale === 'ja' ? '日本語版が正文です。' : 'The Japanese version is authoritative.'}</p>${body}</main><footer><a href="/login/">${locale === 'ja' ? 'ログインへ戻る' : 'Back to sign in'}</a></footer></body></html>\n`
      const page = html
        .replace(
          '</head>',
          `${preview ? '<meta name="robots" content="noindex,nofollow">' : ''}</head>`
        )
        .replace('<main>', `<main>${previewNotice}`)
        .replace(
          `: ${publishedOn}</p>`,
          `: ${publishedOn ?? (locale === 'ja' ? '未公開（確認用）' : 'Not published (for review)')}</p>`
        )
      outputs.push([`${id}/${version}/${locale}/index.html`, page])
      if (document.current)
        outputs.push([locale === 'ja' ? `${id}/index.html` : `${id}/en/index.html`, page])
      translations[locale] = { title, url, sha256 }
    }
    return {
      id,
      version,
      publishedOn,
      effectiveAt,
      effectiveOnRelease,
      authoritativeLocale: 'ja',
      current: document.current,
      requiresAcceptance: document.requiresAcceptance,
      preview,
      translations,
    }
  })
  if (documents.length && (current.size !== ids.size || [...ids].some((id) => !current.has(id))))
    fail('Current terms, privacy and cookies must be supplied together')
  const previousPath = resolve(outputRoot, 'manifest.json')
  if (existsSync(previousPath)) {
    const previous = JSON.parse(readFileSync(previousPath, 'utf8'))
    for (const old of previous.documents) {
      const next = documents.find((entry) => entry.id === old.id && entry.version === old.version)
      if (
        !next ||
        locales.some(
          (locale) => old.translations[locale].sha256 !== next.translations[locale].sha256
        )
      )
        fail('Existing version must not be removed or overwritten')
    }
  }
  // Validate every source before writing any output. Never recursively delete an output directory.
  const generated = { schemaVersion: 1, documents }
  for (const [path, html] of [
    ...outputs,
    ['manifest.json', `${JSON.stringify(generated, null, 2)}\n`],
  ]) {
    const destination = resolve(outputRoot, path)
    mkdirSync(dirname(destination), { recursive: true })
    writeFileSync(destination, html, 'utf8')
  }
  return generated
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const frontend = fileURLToPath(new URL('../', import.meta.url))
  const generated = buildLegal({
    sourceRoot: resolve(frontend, 'content/legal'),
    outputRoot: resolve(frontend, 'public/legal'),
  })
  console.log(`Legal documents generated: ${generated.documents.length}`)
  const backendManifest = resolve(frontend, '../backend/resources/legal-documents.json')
  mkdirSync(dirname(backendManifest), { recursive: true })
  writeFileSync(backendManifest, `${JSON.stringify(generated, null, 2)}\n`, 'utf8')
}
