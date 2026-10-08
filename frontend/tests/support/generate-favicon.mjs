import { readFile, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { chromium } from '@playwright/test'

// Render the existing vector asset; no image-generation dependency is needed.
const source = new URL('../../public/icon.svg', import.meta.url)
const destination = new URL('../../public/favicon.ico', import.meta.url)
const svg = await readFile(source, 'utf8')
const browser = await chromium.launch()
try {
  const page = await browser.newPage({ viewport: { width: 64, height: 64 }, deviceScaleFactor: 1 })
  await page.setContent(
    `<style>html,body{margin:0;width:64px;height:64px}svg{display:block;width:64px;height:64px}</style>${svg}`
  )
  const png = await page.screenshot({ omitBackground: true })
  const header = Buffer.alloc(22)
  header.writeUInt16LE(1, 2)
  header.writeUInt16LE(1, 4)
  header[6] = 64
  header[7] = 64
  header.writeUInt16LE(1, 10)
  header.writeUInt16LE(32, 12)
  header.writeUInt32LE(png.length, 14)
  header.writeUInt32LE(22, 18)
  await writeFile(destination, Buffer.concat([header, png]))
  process.stdout.write(`Generated ${fileURLToPath(destination)} from icon.svg\n`)
} finally {
  await browser.close()
}
