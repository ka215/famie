import { readFileSync } from 'node:fs'

const translateIcon = readFileSync(
  new URL('../app/assets/icons/translate.svg', import.meta.url),
  'utf8'
)
const escapeHtml = (text) =>
  String(text).replace(
    /[&<>"']/g,
    (character) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]
  )

const styles = `
:root{color-scheme:light;--bg:#f1f5f9;--card:#fff;--text:#1e293b;--muted:#64748b;--line:#cbd5e1;--link:#2563eb}
@media(prefers-color-scheme:dark){:root{color-scheme:dark;--bg:#0f172a;--card:#1e293b;--text:#f1f5f9;--muted:#cbd5e1;--line:#475569;--link:#93c5fd}}
:root[data-theme=light]{color-scheme:light;--bg:#f1f5f9;--card:#fff;--text:#1e293b;--muted:#64748b;--line:#cbd5e1;--link:#2563eb}
:root[data-theme=dark]{color-scheme:dark;--bg:#0f172a;--card:#1e293b;--text:#f1f5f9;--muted:#cbd5e1;--line:#475569;--link:#93c5fd}
*{box-sizing:border-box}body{margin:0;min-height:100vh;padding:32px 16px;background:var(--bg);color:var(--text);font:1rem/1.8 system-ui,sans-serif;overflow-wrap:anywhere}
.legal-card{position:relative;max-width:56rem;margin:0 auto;padding:24px;border-radius:16px;background:var(--card);box-shadow:0 4px 6px -1px #0000001a}
h1{font-size:1.5rem;line-height:1.4;margin:0 48px 24px 0}h2{font-size:1.15rem;margin-top:28px}a{color:var(--link);text-decoration:none}a:hover{text-decoration:underline}a:focus-visible,summary:focus-visible{outline:2px solid var(--link);outline-offset:3px}
.metadata{font-size:.875rem;color:var(--muted)}.metadata p{margin:4px 0}aside{border:1px solid var(--line);border-radius:8px;padding:12px;margin:20px 0;font-size:.875rem}aside p{margin:8px 0 0}
table{border-collapse:collapse;display:block;max-width:100%;overflow:auto}th,td{border:1px solid var(--line);padding:8px;text-align:start}
.language-picker{position:absolute;top:12px;right:12px;z-index:1}.language-picker summary{list-style:none;cursor:pointer;display:grid;place-items:center;width:44px;height:44px;border-radius:50%;color:var(--muted)}.language-picker summary::-webkit-details-marker{display:none}.language-picker summary:hover{background:var(--bg)}.language-picker svg{width:24px;height:24px;fill:currentColor}
.language-menu{position:absolute;right:0;min-width:140px;padding:8px;border:1px solid var(--line);border-radius:8px;background:var(--card);box-shadow:0 4px 6px #0000001a}.language-menu a{display:block;padding:8px}.language-menu a[aria-current=page]{font-weight:bold;background:var(--bg);border-radius:4px}
footer{margin-top:28px;padding-top:20px;border-top:1px solid var(--line);text-align:center;font-size:.875rem}footer a{display:block;margin:8px 0}
@media(max-width:400px){body{padding:20px 12px}.legal-card{padding:20px}h1{font-size:1.25rem}}
`

export function renderLegalPage({
  id,
  version,
  locale,
  title,
  body,
  publishedOn,
  effective,
  preview,
  previewNotice,
}) {
  const japanese = locale === 'ja'
  const languageLabel = japanese ? '言語' : 'Language'
  const links = ['ja', 'en']
    .map(
      (language) =>
        `<a href="/legal/${id}/${version}/${language}/" lang="${language}"${language === locale ? ' aria-current="page"' : ''}>${language === 'ja' ? '日本語' : 'English'}</a>`
    )
    .join('')
  const published = publishedOn ?? (japanese ? '未公開（確認用）' : 'Not published (for review)')
  return `<!doctype html>
<html lang="${locale}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f8fafc"><title>${escapeHtml(title)} | Famie</title>${preview ? '<meta name="robots" content="noindex,nofollow">' : ''}<style>${styles}</style><script src="/theme.js"></script><script src="/legal-page.js" defer></script></head>
<body><main class="legal-card"><details class="language-picker"><summary aria-label="${languageLabel}" title="${languageLabel}">${translateIcon}</summary><nav class="language-menu" aria-label="${languageLabel}">${links}</nav></details>
<h1>${escapeHtml(title)}</h1><div class="metadata"><p>${japanese ? '文書版' : 'Version'}: ${escapeHtml(version)} · ${japanese ? '公開日' : 'Published'}: ${escapeHtml(published)}</p><p>${japanese ? '施行' : 'Effective'}: ${escapeHtml(effective)}</p><p>${japanese ? '日本語版が正文です。' : 'The Japanese version is authoritative.'}</p></div>${previewNotice}<article>${body}</article>
<footer><a id="legal-back" href="/login/">${japanese ? 'ログインへ戻る' : 'Back to sign in'}</a></footer></main></body></html>
`
}
