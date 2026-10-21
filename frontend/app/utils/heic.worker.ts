import libheif from 'libheif-js/libheif-wasm/libheif-bundle.mjs'
import { ActivityImageError } from '../../shared/utils/activityImageError'

self.onmessage = async (event: MessageEvent<ArrayBuffer>) => {
  try {
    const module = await libheif()
    const decoder = new module.HeifDecoder()
    const images = decoder.decode(new Uint8Array(event.data))
    const image =
      images.find((candidate: { is_primary?: () => boolean }) => candidate.is_primary?.()) ??
      images[0]
    if (!image) throw new ActivityImageError('loadFailed')
    const width = image.get_width()
    const height = image.get_height()
    const supportsOffscreen = typeof OffscreenCanvas !== 'undefined'
    const maxPixels = supportsOffscreen ? 24_000_000 : 12_000_000
    if (width < 1 || height < 1 || width * height > maxPixels) {
      throw new ActivityImageError('pixels', maxPixels)
    }
    const pixels = { data: new Uint8ClampedArray(width * height * 4), width, height }
    await new Promise<void>((resolve, reject) => {
      image.display(pixels, (result: { data: Uint8ClampedArray } | null) =>
        result ? resolve() : reject(new ActivityImageError('heicFailed'))
      )
    })
    if (!supportsOffscreen) {
      self.postMessage({ pixels: pixels.data.buffer, width, height }, [pixels.data.buffer])
      return
    }
    const source = new OffscreenCanvas(width, height)
    const context = source.getContext('2d')
    if (!context) throw new ActivityImageError('unavailable')
    context.putImageData(new ImageData(pixels.data, width, height), 0, 0)
    const scale = Math.min(1, 1600 / Math.max(width, height))
    const output = new OffscreenCanvas(Math.round(width * scale), Math.round(height * scale))
    const outputContext = output.getContext('2d')
    if (!outputContext) throw new ActivityImageError('unavailable')
    outputContext.drawImage(source, 0, 0, output.width, output.height)
    const blob = await output.convertToBlob({ type: 'image/png' })
    self.postMessage({ blob })
  } catch (error) {
    const failure =
      error instanceof ActivityImageError ? error : new ActivityImageError('heicFailed')
    self.postMessage({ error: failure.code, pixelLimit: failure.pixelLimit })
  }
}
