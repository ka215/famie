import { createReadStream, readFileSync } from 'node:fs'
import { stat } from 'node:fs/promises'
import { createServer, request as proxyRequest } from 'node:http'
import { createServer as createSecureServer } from 'node:https'
import { extname, join, normalize, resolve, sep } from 'node:path'

const root = resolve(process.argv[2] ?? '.output/public')
const port = Number(process.argv[3] ?? 3200)
// Only application E2E passes an API origin; release smoke stays static-only.
const apiOrigin = process.argv[4]
const tlsKey = process.argv[5]
const tlsCert = process.argv[6]
const server =
  tlsKey && tlsCert
    ? createSecureServer({ key: readFileSync(tlsKey), cert: readFileSync(tlsCert) })
    : createServer()
const mime = {
  '.css': 'text/css; charset=utf-8',
  '.html': 'text/html; charset=utf-8',
  '.ico': 'image/x-icon',
  '.js': 'application/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
  '.txt': 'text/plain; charset=utf-8',
}

async function resolveFile(url) {
  const pathname = decodeURIComponent(new URL(url, 'http://127.0.0.1').pathname)
  const normalized = normalize(pathname).replace(/^[/\\]+/, '')
  let file = resolve(root, normalized)
  if (!file.startsWith(`${root}${sep}`) && file !== root) return null
  try {
    const info = await stat(file)
    if (info.isDirectory()) file = join(file, 'index.html')
    if ((await stat(file)).isFile()) return file
  } catch {}
  return join(root, 'index.html')
}

server.on('request', async (request, response) => {
  if (apiOrigin && /^\/api\/v1(?:\/|\?|$)/.test(request.url ?? '')) {
    const target = new URL(request.url.slice('/api'.length), apiOrigin)
    const upstream = proxyRequest(
      target,
      { method: request.method, headers: { ...request.headers, host: target.host } },
      (received) => {
        response.writeHead(received.statusCode, received.headers)
        received.pipe(response)
      }
    )
    upstream.on('error', () => {
      if (!response.headersSent) response.writeHead(502)
      response.end('E2E API unavailable')
    })
    request.pipe(upstream)
    return
  }
  const file = await resolveFile(request.url ?? '/')
  if (!file) {
    response.writeHead(400).end('Bad request')
    return
  }
  response.setHeader('Content-Type', mime[extname(file)] ?? 'application/octet-stream')
  createReadStream(file).pipe(response)
})
server.listen(port, '127.0.0.1', () => console.log(`Static test server listening on ${port}`))
