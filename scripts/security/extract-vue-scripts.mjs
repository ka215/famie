import { readdir, readFile, mkdir, writeFile } from 'node:fs/promises'
import { join, relative } from 'node:path'

const [source, destination] = process.argv.slice(2)
if (!source || !destination) throw new Error('Source and destination are required')
async function extract(directory) {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name)
    if (entry.isSymbolicLink()) throw new Error('Vue source must not contain symlinks')
    if (entry.isDirectory()) await extract(path)
    else if (entry.name.endsWith('.vue')) {
      const content = await readFile(path, 'utf8')
      const scripts = [...content.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script\s*>/gi)]
      if (!scripts.length) continue
      const target = join(destination, `${relative(source, path)}.ts`)
      await mkdir(join(target, '..'), { recursive: true })
      await writeFile(target, scripts.map((match) => match[1]).join('\n;\n'))
    }
  }
}
await extract(source)
