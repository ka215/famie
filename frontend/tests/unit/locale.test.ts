import assert from 'node:assert/strict'
import { test } from 'node:test'
import { isUiLocale, localeFromTag } from '../../shared/utils/locale.ts'

test('normalizes the preferred tag without searching lower-priority languages', () => {
  for (const input of ['ja', 'ja-JP', 'ja-jp', 'ja-Latn-JP'])
    assert.equal(localeFromTag(input), 'ja')
  for (const input of ['en-US', 'en-JP', 'fr-FR', '', undefined, '../../ja', 'japan'])
    assert.equal(localeFromTag(input), 'en')
  assert.equal(isUiLocale('ja-JP'), false)
  assert.equal(isUiLocale('auto'), false)
  assert.equal(isUiLocale(null), false)
  assert.equal(isUiLocale('ja'), true)
  assert.equal(isUiLocale('en'), true)
})
