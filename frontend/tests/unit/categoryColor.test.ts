import assert from 'node:assert/strict'
import { test } from 'node:test'
import { categoryStyle } from '../../shared/utils/categoryColor.ts'

test('カテゴリの保存色を維持し、明るい背景は黒文字・暗い背景は白文字にする', () => {
  assert.deepEqual(categoryStyle('#ffffff'), { backgroundColor: '#ffffff', color: '#000000' })
  assert.deepEqual(categoryStyle('#000000'), { backgroundColor: '#000000', color: '#ffffff' })
  assert.equal(categoryStyle('#10B981').color, '#000000')
  assert.equal(categoryStyle('#1e293b').color, '#ffffff')
  assert.equal(categoryStyle('invalid').backgroundColor, '#3B82F6')
  assert.equal(categoryStyle(null).backgroundColor, '#3B82F6')
})
