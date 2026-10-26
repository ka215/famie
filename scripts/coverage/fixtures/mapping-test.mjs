import { test } from 'node:test'
import assert from 'node:assert/strict'
import { called } from './mapping.ts'

test('execute one TypeScript branch', () => assert.equal(called(true), 'yes'))
