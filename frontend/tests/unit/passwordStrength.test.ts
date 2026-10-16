import assert from 'node:assert/strict'
import { test } from 'node:test'
import { passwordStrength } from '../../shared/utils/password-strength.ts'

test('短い入力、単純な繰り返し、連番、よく使われる語、制御文字を拒否する', () => {
  for (const value of [
    '',
    'short123',
    'aaaaaaaaaaaaaaa',
    'abcabcabcabcabc',
    'password12345678',
    '0123456789012345',
    'river\notter orchard',
    '静かな庭で毎朝家族とお茶を飲んでから今日の予定を話す',
  ]) {
    assert.equal(passwordStrength(value).acceptable, false)
  }
})

test('長い英語・日本語のフレーズを文字種の強制なしで受け入れる', () => {
  for (const value of ['river otter orchard', '静かな庭で家族と毎朝お茶を飲みます']) {
    assert.equal(passwordStrength(value).acceptable, true)
    assert.equal(passwordStrength(value).score, 2)
  }
})

test('文字数とUTF-8のバイト数を区別し、目安の値を返す', () => {
  assert.equal(passwordStrength('river otter ab').acceptable, false)
  assert.equal(passwordStrength('river otter abc').acceptable, true)
  assert.equal(passwordStrength(`river otter ${'x'.repeat(60)}`).acceptable, true)
  assert.equal(passwordStrength(`river otter ${'x'.repeat(61)}`).acceptable, false)
  assert.equal(passwordStrength('river otter orchard stone').score, 3)
  assert.equal(passwordStrength('').score, 0)
})
