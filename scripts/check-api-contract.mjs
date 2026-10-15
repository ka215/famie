import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { resolve, dirname } from 'node:path'
import assert from 'node:assert/strict'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const routes = JSON.parse(execFileSync('php', ['artisan', 'route:list', '--path=v1', '--except-vendor', '--json'], { cwd: resolve(root, 'backend'), encoding: 'utf8' }))
const spec = JSON.parse(execFileSync('yq', ['-o=json', '.', resolve(root, 'docs/api/openapi.yaml')], { encoding: 'utf8' }))
const actual = new Set()
for (const route of routes) {
  for (const method of route.method.split('|')) {
    if (method !== 'HEAD') actual.add(`${method} /${route.uri.replace(/^v1\/?/, '')}`)
  }
}
const documented = new Set()
const operationIds = new Set()
for (const [path, item] of Object.entries(spec.paths)) {
  for (const [method, operation] of Object.entries(item)) {
    if (!['get', 'post', 'put', 'patch', 'delete', 'head', 'options'].includes(method)) continue
    assert(operation.operationId, `${method} ${path}: operationId missing`)
    assert(!operationIds.has(operation.operationId), `duplicate operationId: ${operation.operationId}`)
    operationIds.add(operation.operationId)
    if (operation['x-method-override']) {
      assert.equal(method, 'post')
      assert(actual.has(`${operation['x-method-override']} ${path}`), `method override target missing: ${path}`)
      continue
    }
    documented.add(`${method.toUpperCase()} ${path}`)
  }
}
assert.deepEqual([...actual].sort(), [...documented].sort(), 'Laravel routes and OpenAPI method/path differ')
console.log(`OpenAPI route coverage: ${actual.size} operations + documented multipart method override`)
