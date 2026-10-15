import { execFileSync } from 'node:child_process'
import { resolve } from 'node:path'
import { type APIResponse, expect, test } from '@playwright/test'
import Ajv2020 from 'ajv/dist/2020.js'
import addFormats from 'ajv-formats'

const spec = JSON.parse(
  execFileSync('yq', ['-o=json', '.', resolve('../docs/api/openapi.yaml')], { encoding: 'utf8' })
)
const ajv = new Ajv2020({ strict: false, allErrors: true })
addFormats(ajv)

async function contract(response: APIResponse, path: string, method: string, status = 200) {
  expect(response.status()).toBe(status)
  let definition = spec.paths[path][method].responses[String(status)]
  if (definition.$ref) definition = spec.components.responses[definition.$ref.split('/').at(-1)]
  const validate = ajv.compile({
    ...definition.content['application/json'].schema,
    components: spec.components,
  })
  const payload = await response.json()
  expect(validate(payload), JSON.stringify(validate.errors)).toBeTruthy()
  return payload
}

test('実APIの認証・一覧・活動・リワード・入力エラーがOpenAPI応答スキーマと一致する', async ({
  request,
}) => {
  const api = 'http://127.0.0.1:8100/v1'
  const today = new Date().toISOString().slice(0, 10)
  await contract(await request.get(`${api}/status`), '/status', 'get')
  const identity = await contract(
    await request.post(`${api}/auth/login`, {
      data: { login: 'parent1', password: 'Famie-E2E-only-123!' },
    }),
    '/auth/login',
    'post'
  )
  const headers = { Authorization: `Bearer ${identity.access_token}`, Accept: 'application/json' }
  const prefix = `${api}/groups/${identity.membership.group.id}`
  await contract(await request.get(`${api}/auth/me`, { headers }), '/auth/me', 'get')
  await contract(await request.get(prefix, { headers }), '/groups/{group}', 'get')
  const categories = await contract(
    await request.get(`${prefix}/categories`, { headers }),
    '/groups/{group}/categories',
    'get'
  )
  await contract(
    await request.get(`${prefix}/members`, { headers }),
    '/groups/{group}/members',
    'get'
  )
  await contract(await request.get(`${prefix}/logs`, { headers }), '/groups/{group}/logs', 'get')
  await contract(
    await request.get(`${prefix}/gallery`, { headers }),
    '/groups/{group}/gallery',
    'get'
  )
  await contract(
    await request.post(`${prefix}/logs`, { headers, data: {} }),
    '/groups/{group}/logs',
    'post',
    422
  )
  const created = await contract(
    await request.post(`${prefix}/logs`, {
      headers,
      data: {
        category_id: categories[0].id,
        activity_date: today,
        content: 'OpenAPI契約確認',
      },
    }),
    '/groups/{group}/logs',
    'post',
    201
  )
  let rewardId: number | undefined
  let revision = 1
  try {
    await contract(
      await request.get(`${prefix}/logs/${created.data.id}`, { headers }),
      '/groups/{group}/logs/{log}',
      'get'
    )
    await contract(
      await request.get(`${prefix}/logs/counts?from=${today}&to=${today}`, { headers }),
      '/groups/{group}/logs/counts',
      'get'
    )
    const reward = await contract(
      await request.post(`${prefix}/rewards`, {
        headers,
        data: {
          name: '契約確認',
          description: '契約テスト用',
          metric: 'activities',
          color: 'gold',
          count_mode: 'fixed',
          required_count: 1,
          target_ids: [identity.membership.id],
          start_month: new Date().toISOString().slice(0, 7),
          cycle_months: 1,
          timezone: 'UTC',
        },
      }),
      '/groups/{group}/rewards',
      'post',
      201
    )
    rewardId = reward.data.id
    const active = await contract(
      await request.put(`${prefix}/rewards/${rewardId}/status`, {
        headers,
        data: { revision, status: 'active' },
      }),
      '/groups/{group}/rewards/{reward}/status',
      'put'
    )
    revision = active.data.revision
    await contract(
      await request.get(`${prefix}/rewards`, { headers }),
      '/groups/{group}/rewards',
      'get'
    )
    await contract(
      await request.get(`${prefix}/rewards/manage`, { headers }),
      '/groups/{group}/rewards/manage',
      'get'
    )
    await contract(
      await request.get(`${prefix}/rewards/awards`, { headers }),
      '/groups/{group}/rewards/awards',
      'get'
    )
    await contract(
      await request.post(`${prefix}/rewards/notifications`, { headers }),
      '/groups/{group}/rewards/notifications',
      'post'
    )
  } finally {
    if (rewardId) {
      const stopped = await request.put(`${prefix}/rewards/${rewardId}/status`, {
        headers,
        data: { revision, status: 'stopped' },
      })
      const result = await stopped.json()
      await request.delete(`${prefix}/rewards/${rewardId}`, {
        headers,
        data: { revision: result.data?.revision ?? revision },
      })
    }
    await request.delete(`${prefix}/logs/${created.data.id}`, { headers })
  }
})
