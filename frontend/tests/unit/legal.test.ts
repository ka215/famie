import assert from 'node:assert/strict'
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { test } from 'node:test'
import { buildLegal, renderMarkdown } from '../../scripts/legal.mjs'

test('legal Markdown rejects active content, unsafe links and unresolved drafts', () => {
  for (const source of [
    '<script>alert(1)</script>',
    '[x](javascript:alert)',
    '[x](//evil.example)',
    '![x](https://example.com/x)',
    'REVIEW-P01',
    '```js\nalert(1)\n```',
    '[x](https://example.com\\evil)',
  ]) {
    assert.throws(() => renderMarkdown(source))
  }
  assert.match(
    renderMarkdown('## Terms\n\n**Read** [contact](mailto:support@example.com).\n'),
    /<h2>Terms<\/h2>/
  )
})

function fixture() {
  const folder = mkdtempSync(join(tmpdir(), 'famie-legal-synthetic-'))
  const sourceRoot = join(folder, 'source')
  const outputRoot = join(folder, 'output')
  mkdirSync(sourceRoot)
  const documents = ['terms', 'privacy', 'cookies'].map((id) => {
    const path = join(sourceRoot, id, '1.0.0')
    mkdirSync(path, { recursive: true })
    for (const locale of ['ja', 'en'])
      writeFileSync(join(path, `${locale}.md`), '## Synthetic document\n\nTest text.\n')
    return {
      id,
      version: '1.0.0',
      titles: { ja: '合成文書', en: 'Synthetic document' },
      publishedOn: '2026-11-08',
      effectiveAt: null,
      effectiveOnRelease: '1.0.0',
      authoritativeLocale: 'ja',
      current: true,
      requiresAcceptance: id !== 'cookies',
    }
  })
  const save = () =>
    writeFileSync(
      join(sourceRoot, 'manifest.json'),
      JSON.stringify({ schemaVersion: 1, documents })
    )
  save()
  return { sourceRoot, outputRoot, documents, save }
}

test('build embeds text without JavaScript, retains version URLs and hashes each language', () => {
  const f = fixture()
  const result = buildLegal(f)
  const html = readFileSync(join(f.outputRoot, 'terms/1.0.0/ja/index.html'), 'utf8')
  assert.match(html, /Synthetic document/)
  assert.match(html, /未施行/)
  assert.doesNotMatch(html, /<script|fetch\(/)
  assert.match(result.documents[0].translations.en.sha256, /^[a-f0-9]{64}$/)
  assert.equal(readFileSync(join(f.outputRoot, 'terms/index.html'), 'utf8'), html)
})

test('reject missing language and conflicting current versions before writing', () => {
  const f = fixture()
  f.documents[0].titles = { ja: '合成文書' }
  f.save()
  assert.throws(() => buildLegal(f), /language pair/)
  const g = fixture()
  g.documents.push(g.documents[0])
  g.save()
  assert.throws(() => buildLegal(g), /Duplicate/)
})

test('an existing generated version cannot silently change its content', () => {
  const f = fixture()
  const before = buildLegal(f).documents[0].translations.en.sha256
  writeFileSync(join(f.sourceRoot, 'terms/1.0.0/en.md'), 'Different synthetic text.\n')
  assert.throws(() => buildLegal(f), /must not be removed or overwritten/)
  const next = buildLegal({ ...f, outputRoot: join(f.outputRoot, 'independent-build') })
  assert.notEqual(next.documents[0].translations.en.sha256, before)
})

test('preview has no invented publication date and is marked before it becomes effective', () => {
  const f = fixture()
  for (const document of f.documents) {
    document.publishedOn = null
    document.preview = true
  }
  f.save()
  const generated = buildLegal(f)
  const html = readFileSync(join(f.outputRoot, 'terms/index.html'), 'utf8')
  assert.equal(generated.documents[0].publishedOn, null)
  assert.match(html, /先行確認版・未施行/)
  assert.match(html, /未公開（確認用）/)
  assert.match(html, /<head>[\s\S]*<meta name="robots" content="noindex,nofollow">[\s\S]*<\/head>/)
  assert.doesNotMatch(html, /Published: null|公開日: null/)
})
