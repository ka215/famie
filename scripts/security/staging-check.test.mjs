import assert from 'node:assert/strict'
import { test } from 'node:test'
import { runChecks, validateFixture } from './staging-check.mjs'

const fixture = {
  family: {
    group_id: 101, log_id: 201, image_id: 301,
    parent: { login: 'security_parent', password: 'test-only-passphrase-parent' },
    child: { login: 'security_child', password: 'test-only-passphrase-child' },
  },
  other: {
    group_id: 102, image_id: 302,
    parent: { login: 'security_other', password: 'test-only-passphrase-other' },
  },
}

function server({ redirect = false, denyOwnLog = false } = {}) {
  const calls = []
  const sessions = new Map()
  async function fetcher(url, options) {
    const parsed = new URL(url)
    assert.equal(parsed.origin, 'https://stg-famie.ka2.org')
    assert.equal(options.redirect, 'manual')
    const path = parsed.pathname
    calls.push({ path, method: options.method })
    const headers = { 'content-type': 'application/json', 'x-request-id': '11111111-1111-4111-8111-111111111111' }
    let status = 200
    let body = {}
    const token = options.headers.Authorization?.replace('Bearer ', '')
    const account = sessions.get(token)
    if (redirect) status = 302
    else if (path.endsWith('/auth/login')) {
      const { login } = JSON.parse(options.body)
      const newToken = `test-token-${login}-long-enough`
      sessions.set(newToken, login)
      body = { access_token: newToken, membership: { group: { id: login === 'security_other' ? 102 : 101 }, role: login === 'security_child' ? 'member' : 'admin' } }
    } else if (path.endsWith('/status')) body = { status: 'ok' }
    else if (!account) status = 401
    else if (path.endsWith('/auth/logout')) sessions.delete(token)
    else if (path.endsWith('/members')) status = 403
    else if (path.includes('/groups/101/') && account === 'security_other') status = 404
    else if (path.includes('/groups/102/') && account !== 'security_other') status = 404
    else if (denyOwnLog && path.endsWith('/logs/201')) status = 403
    else if (path.includes('/images/')) {
      headers['content-type'] = 'image/png'
      headers['cache-control'] = 'no-store, private'
    }
    return new Response(JSON.stringify(body), { status, headers })
  }
  return { fetcher, calls, sessions }
}

test('only dedicated, distinct fixture families/accounts are accepted', () => {
  assert.equal(validateFixture(fixture), fixture)
  assert.throws(() => validateFixture({ ...fixture, other: { ...fixture.other, group_id: 101 } }))
  assert.throws(() => validateFixture({ ...fixture, family: { ...fixture.family, parent: { ...fixture.family.parent, login: 'real_account' } } }))
})

test('bounded staging checks verify isolation, role denial, images and revocation', async () => {
  const mock = server()
  const result = await runChecks(fixture, mock.fetcher)
  assert(result.requests <= 30)
  assert.equal(result.checks, 20)
  assert.equal(mock.sessions.size, 0)
  assert.equal(mock.calls.filter((call) => call.method === 'POST' && call.path.endsWith('/members')).length, 1)
})

test('redirects fail without following another URL', async () => {
  const mock = server({ redirect: true })
  await assert.rejects(runChecks(fixture, mock.fetcher))
  assert.equal(mock.calls.length, 1)
})

test('a failed check still revokes all dedicated test sessions', async () => {
  const mock = server({ denyOwnLog: true })
  await assert.rejects(runChecks(fixture, mock.fetcher))
  assert.equal(mock.sessions.size, 0)
  assert.equal(mock.calls.filter((call) => call.path.endsWith('/auth/logout')).length, 3)
})
