import { readFile, readdir, stat } from 'node:fs/promises'
import { extname, join, relative, resolve, sep } from 'node:path'

const publicDir = resolve(process.argv[2] ?? 'frontend/.output/public')
const forbidden = [
  { pattern: /@vite\/client/i, label: '@vite/client' },
  { pattern: /\b(?:[A-Za-z]:[\\/]|file:\/\/)/i, label: 'local absolute path' },
  { pattern: /\blocalhost(?::\d+)?\b/i, label: 'localhost' },
  { pattern: /\bnode_modules\b/i, label: 'node_modules' },
  { pattern: /entry\.async\.js/i, label: 'development entry' },
]

async function walk(directory) {
  const files = []
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name)
    if (entry.isDirectory()) files.push(...(await walk(path)))
    else if (entry.isFile()) files.push(path)
  }
  return files
}

function fail(message) {
  console.error(`ERROR: ${message}`)
  process.exitCode = 1
}

const files = await walk(publicDir)
const htmlFiles = files.filter((file) => extname(file).toLowerCase() === '.html')
if (htmlFiles.length === 0) throw new Error(`No HTML files found below ${publicDir}`)

let referenceCount = 0
for (const htmlFile of htmlFiles) {
  const html = await readFile(htmlFile, 'utf8')
  const name = relative(publicDir, htmlFile)
  for (const rule of forbidden) {
    if (rule.pattern.test(html)) fail(`${rule.label} found in ${name}`)
  }

  const references = html.matchAll(/(?:src|href)=["'](\/_nuxt\/[^"'?#]+\.(?:js|css))["']/gi)
  for (const match of references) {
    referenceCount += 1
    const urlPath = match[1]
    if (/\\|\.\.|[A-Za-z]:/.test(urlPath)) {
      fail(`Unsafe asset path in ${name}: ${urlPath}`)
      continue
    }
    const asset = resolve(publicDir, urlPath.slice(1).split('/').join(sep))
    if (!asset.startsWith(`${publicDir}${sep}`)) {
      fail(`Asset escapes public directory in ${name}: ${urlPath}`)
      continue
    }
    try {
      if (!(await stat(asset)).isFile()) fail(`Referenced asset is not a file in ${name}: ${urlPath}`)
    } catch {
      fail(`Missing referenced asset in ${name}: ${urlPath}`)
    }
  }
}

for (const required of ['index.html', 'sw.js', 'manifest.webmanifest', 'maintenance.html', 'maintenance.json']) {
  try {
    if (!(await stat(join(publicDir, required))).isFile()) fail(`Required release asset is not a file: ${required}`)
  } catch {
    fail(`Missing required release asset: ${required}`)
  }
}

if (referenceCount === 0) fail('No entry JavaScript or CSS references found in release HTML')
if (process.exitCode) process.exit(process.exitCode)
console.log(`Static asset verification passed: ${htmlFiles.length} HTML files, ${referenceCount} references.`)
