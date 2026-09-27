import { createReadStream } from 'node:fs'
import { stat } from 'node:fs/promises'
import { createServer } from 'node:http'
import { extname, join, normalize, resolve, sep } from 'node:path'

const root = resolve(process.argv[2] ?? '.output/public')
const port = Number(process.argv[3] ?? 3200)
const mime = {
  '.css': 'text/css; charset=utf-8',
  '.html': 'text/html; charset=utf-8',
  '.ico': 'image/x-icon',
  '.js': 'application/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
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

createServer(async (request, response) => {
  const file = await resolveFile(request.url ?? '/')
  if (!file) {
    response.writeHead(400).end('Bad request')
    return
  }
  response.setHeader('Content-Type', mime[extname(file)] ?? 'application/octet-stream')
  createReadStream(file).pipe(response)
}).listen(port, '127.0.0.1', () => console.log(`Static release server listening on ${port}`))
