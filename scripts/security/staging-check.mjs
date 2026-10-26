import assert from 'node:assert/strict'
import { pathToFileURL } from 'node:url'

const origin = 'https://stg-famie.ka2.org'
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

export function validateFixture(fixture) {
  const { family, other } = fixture
  for (const value of [family?.group_id, family?.log_id, family?.image_id, other?.group_id, other?.image_id]) {
    assert(Number.isSafeInteger(value) && value > 0, 'Invalid fixture ID')
  }
  assert.notEqual(family.group_id, other.group_id)
  assert.notEqual(family.image_id, other.image_id)
  const accounts = [family.parent, family.child, other.parent]
  assert.equal(new Set(accounts.map((account) => account?.login)).size, 3)
  for (const account of accounts) {
    assert(/^security_[a-z0-9_]{1,30}$/.test(account?.login), 'Dedicated security account required')
    assert(typeof account.password === 'string' && account.password.length >= 16, 'Strong fixture credentials required')
  }
  return fixture
}

export async function runChecks(fixture, fetcher = fetch) {
  const { family, other } = validateFixture(fixture)
  const tokens = []
  const deadline = Date.now() + 120_000
  let requests = 0
  let checks = 0
  const allowed = new Set([
    '/api/v1/status', '/api/v1/auth/login', '/api/v1/auth/logout', '/api/v1/auth/me',
    `/api/v1/groups/${family.group_id}/logs/${family.log_id}`,
    `/api/v1/groups/${family.group_id}/images/${family.image_id}`,
    `/api/v1/groups/${other.group_id}/images/${other.image_id}`,
    `/api/v1/groups/${family.group_id}/members`,
  ])
  async function request(path, expected, { method = 'GET', token, body, headers = {} } = {}) {
    assert(allowed.has(path), 'Path is outside the scan boundary')
    assert(++requests <= 30 && Date.now() < deadline, 'Request budget exceeded')
    const response = await fetcher(`${origin}${path}`, {
      method, redirect: 'manual', signal: AbortSignal.timeout(10_000),
      headers: { Accept: 'application/json', ...headers, ...(token ? { Authorization: `Bearer ${token}` } : {}), ...(body ? { 'Content-Type': 'application/json' } : {}) },
      ...(body ? { body: JSON.stringify(body) } : {}),
    })
    assert.equal(response.status, expected, 'Unexpected HTTP status')
    assert.match(response.headers.get('x-request-id') || '', uuid, 'Missing request trace')
    const chunks = []
    let size = 0
    for await (const chunk of response.body || []) {
      size += chunk.length
      assert(size <= 2_000_000, 'Response exceeds fixture size limit')
      chunks.push(chunk)
    }
    checks++
    const bytes = Buffer.concat(chunks)
    const json = response.headers.get('content-type')?.includes('application/json') ? JSON.parse(bytes.toString('utf8')) : null
    if (json) assert(!('exception' in json) && !('trace' in json), 'Debug details in response')
    return { response, json }
  }
  async function login(account, group, role) {
    const { json } = await request('/api/v1/auth/login', 200, { method: 'POST', body: account })
    const token = json?.access_token
    assert(typeof token === 'string' && token.length > 20, 'Missing test token')
    tokens.push(token)
    assert.equal(json.membership?.group?.id, group)
    assert.equal(json.membership?.role, role)
    return token
  }
  try {
    await request('/api/v1/status', 200)
    await request('/api/v1/auth/me', 401)
    const parent = await login(family.parent, family.group_id, 'admin')
    const child = await login(family.child, family.group_id, 'member')
    const outsider = await login(other.parent, other.group_id, 'admin')
    await request('/api/v1/auth/me', 200, { token: parent })
    const ownPath = `/api/v1/groups/${family.group_id}/logs/${family.log_id}`
    await request(ownPath, 200, { token: parent })
    await request(ownPath, 200, { token: child })
    await request(ownPath, 404, { token: outsider })
    const image = `/api/v1/groups/${family.group_id}/images/${family.image_id}`
    await request(image, 401)
    const { response } = await request(image, 200, { token: parent })
    assert.match(response.headers.get('content-type') || '', /^image\//)
    assert.match(response.headers.get('cache-control') || '', /no-store/)
    assert.match(response.headers.get('cache-control') || '', /private/)
    await request(image, 200, { token: child })
    await request(image, 404, { token: outsider })
    await request(`/api/v1/groups/${other.group_id}/images/${other.image_id}`, 404, { token: parent })
    // Empty input cannot create a member even if a role regression occurs.
    await request(`/api/v1/groups/${family.group_id}/members`, 403, { method: 'POST', token: child, body: {} })
    const cors = await request('/api/v1/status', 200, { headers: { Origin: 'https://untrusted.example' } })
    assert(!['*', 'https://untrusted.example'].includes(cors.response.headers.get('access-control-allow-origin')), 'Untrusted CORS origin allowed')
    await request('/api/v1/auth/logout', 200, { method: 'POST', token: parent })
    tokens.splice(tokens.indexOf(parent), 1)
    await request('/api/v1/auth/me', 401, { token: parent })
  } finally {
    let cleanupFailed = false
    for (const token of tokens) {
      try { await request('/api/v1/auth/logout', 200, { method: 'POST', token }) }
      catch { cleanupFailed = true }
    }
    assert(!cleanupFailed, 'Test token cleanup failed; revoke dedicated account sessions')
  }
  return { checks, requests }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    assert(/^[0-9a-f]{40}$/.test(process.env.FAMIE_SECURITY_CANDIDATE || ''), 'A verified candidate SHA is required')
    const result = await runChecks(JSON.parse(process.env.FAMIE_SECURITY_FIXTURE || 'null'))
    console.log(`Staging security checks passed: ${result.checks}; requests: ${result.requests}; candidate: ${process.env.FAMIE_SECURITY_CANDIDATE}`)
  } catch {
    // No credentials, response bodies, assertion values or stack traces in public logs.
    console.error('Staging security checks failed. Inspect fixture configuration and private application logs.')
    process.exitCode = 1
  }
}
