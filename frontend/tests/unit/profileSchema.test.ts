import assert from 'node:assert/strict'
import { test } from 'node:test'
import { profileSchema } from '../../shared/utils/profileSchema.ts'

test('表示名の空欄・空白・文字数・絵文字をLaravelと同じ条件で検証する', () => {
  for (const input of ['', ' \t\n　', '\u200B\uFEFF', 'あ'.repeat(51), '😀'.repeat(51)]) {
    const result = profileSchema.safeParse({ display_name: input })
    assert.equal(result.success, false, `Rejected: ${JSON.stringify(input)}`)
    if (!result.success) assert.match(result.error.issues[0].message, /表示名/)
  }
  for (const [input, expected] of [
    ['  名前　', '名前'],
    ['\u0085\u200B\uFEFF名前\u00AD\u3000', '名前'],
    ['家族 👨‍👩‍👧‍👦', '家族 👨‍👩‍👧‍👦'],
    ['あ'.repeat(50), 'あ'.repeat(50)],
    ['😀'.repeat(50), '😀'.repeat(50)],
  ]) {
    const result = profileSchema.safeParse({ display_name: input })
    assert.equal(result.success, true)
    if (result.success) assert.equal(result.data.display_name, expected)
  }
})
