import libheif from 'libheif-js/libheif-wasm/libheif-bundle.mjs'

self.onmessage = async (event: MessageEvent<ArrayBuffer>) => {
  try {
    const module = await libheif()
    const decoder = new module.HeifDecoder()
    const images = decoder.decode(new Uint8Array(event.data))
    const image =
      images.find((candidate: { is_primary?: () => boolean }) => candidate.is_primary?.()) ??
      images[0]
    if (!image) throw new Error('画像を読み込めません')
    const width = image.get_width()
    const height = image.get_height()
    const supportsOffscreen = typeof OffscreenCanvas !== 'undefined'
    const maxPixels = supportsOffscreen ? 24_000_000 : 12_000_000
    if (width < 1 || height < 1 || width * height > maxPixels) {
      throw new Error(`画像の画素数が上限を超えています（${maxPixels / 10_000}万画素）`)
    }
    const pixels = { data: new Uint8ClampedArray(width * height * 4), width, height }
    await new Promise<void>((resolve, reject) => {
      image.display(pixels, (result: { data: Uint8ClampedArray } | null) =>
        result ? resolve() : reject(new Error('HEIC画像を変換できません'))
      )
    })
    if (!supportsOffscreen) {
      self.postMessage({ pixels: pixels.data.buffer, width, height }, [pixels.data.buffer])
      return
    }
    const source = new OffscreenCanvas(width, height)
    const context = source.getContext('2d')
    if (!context) throw new Error('画像変換を利用できません')
    context.putImageData(new ImageData(pixels.data, width, height), 0, 0)
    const scale = Math.min(1, 1600 / Math.max(width, height))
    const output = new OffscreenCanvas(Math.round(width * scale), Math.round(height * scale))
    const outputContext = output.getContext('2d')
    if (!outputContext) throw new Error('画像変換を利用できません')
    outputContext.drawImage(source, 0, 0, output.width, output.height)
    const blob = await output.convertToBlob({ type: 'image/png' })
    self.postMessage({ blob })
  } catch (error) {
    self.postMessage({ error: error instanceof Error ? error.message : 'HEIC画像を変換できません' })
  }
}
