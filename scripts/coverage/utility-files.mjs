import { readdir } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { join } from 'node:path'

export const frontendRoot = fileURLToPath(new URL('../../frontend/', import.meta.url))
export async function utilityFiles() {
  const directory = join(frontendRoot, 'shared/utils')
  return (await readdir(directory)).filter((name) => name.endsWith('.ts')).sort()
    .map((name) => join(directory, name))
}
