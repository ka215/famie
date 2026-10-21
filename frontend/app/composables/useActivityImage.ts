import {
  ActivityImageError,
  imageErrorKeys,
  isImageErrorCode,
} from '#shared/utils/activityImageError'

export const useActivityImage = () => {
  const { fetchApi } = useApi()
  const { t } = useI18n()
  const { intlLocale } = useUiLocale()
  const formatError = (error: unknown) => {
    const failure =
      error instanceof ActivityImageError ? error : new ActivityImageError('convertFailed')
    return failure.code === 'pixels'
      ? t('activity.imagePixels', {
          pixels: new Intl.NumberFormat(intlLocale.value).format(failure.pixelLimit ?? 0),
        })
      : t(imageErrorKeys[failure.code])
  }
  const { groupId } = useAuth()
  const objectUrls = new Set<string>()

  const imageUrl = async (id: number): Promise<string> => {
    const blob = await fetchApi<Blob>(`/groups/${groupId.value}/images/${id}`, {
      responseType: 'blob',
    })
    const url = URL.createObjectURL(blob)
    objectUrls.add(url)
    return url
  }

  const releaseUrl = (url: string): void => {
    if (objectUrls.delete(url)) URL.revokeObjectURL(url)
  }

  const releaseAll = (): void => {
    for (const url of objectUrls) URL.revokeObjectURL(url)
    objectUrls.clear()
  }

  const prepareFile = async (file: File): Promise<File> => {
    if (file.size > 20 * 1024 * 1024) throw new ActivityImageError('tooLarge')
    const header = new Uint8Array(await file.slice(0, 16).arrayBuffer())
    const brand = String.fromCharCode(...header.slice(8, 12))
    const isHeif =
      String.fromCharCode(...header.slice(4, 8)) === 'ftyp' &&
      ['heic', 'heix', 'mif1', 'msf1', 'hevc'].includes(brand)
    if (!isHeif) {
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type))
        throw new ActivityImageError('unsupported')
      return file
    }
    const worker = new Worker(new URL('../utils/heic.worker.ts', import.meta.url), {
      type: 'module',
    })
    try {
      const result = await new Promise<Blob>((resolve, reject) => {
        const timeout = window.setTimeout(() => reject(new ActivityImageError('timeout')), 90_000)
        worker.onmessage = async (
          event: MessageEvent<{
            blob?: Blob
            pixels?: ArrayBuffer
            width?: number
            height?: number
            error?: string
            pixelLimit?: number
          }>
        ) => {
          window.clearTimeout(timeout)
          if (event.data.blob) {
            resolve(event.data.blob)
          } else if (event.data.pixels && event.data.width && event.data.height) {
            try {
              const { pixels, width, height } = event.data
              const source = document.createElement('canvas')
              source.width = width
              source.height = height
              const context = source.getContext('2d')
              if (!context) throw new ActivityImageError('unavailable')
              context.putImageData(
                new ImageData(new Uint8ClampedArray(pixels), width, height),
                0,
                0
              )
              const scale = Math.min(1, 1600 / Math.max(width, height))
              const output = document.createElement('canvas')
              output.width = Math.round(width * scale)
              output.height = Math.round(height * scale)
              const outputContext = output.getContext('2d')
              if (!outputContext) throw new ActivityImageError('unavailable')
              outputContext.drawImage(source, 0, 0, output.width, output.height)
              output.toBlob(
                (blob) => (blob ? resolve(blob) : reject(new ActivityImageError('pngFailed'))),
                'image/png'
              )
            } catch (error) {
              reject(error)
            }
          } else {
            reject(
              new ActivityImageError(
                isImageErrorCode(event.data.error) ? event.data.error : 'heicFailed',
                event.data.pixelLimit
              )
            )
          }
        }
        worker.onerror = () => {
          window.clearTimeout(timeout)
          reject(new ActivityImageError('heicFailed'))
        }
        file.arrayBuffer().then((buffer) => worker.postMessage(buffer, [buffer]), reject)
      })
      return new File([result], 'activity-image.png', { type: 'image/png' })
    } finally {
      worker.terminate()
    }
  }

  onBeforeUnmount(releaseAll)
  return { imageUrl, releaseUrl, prepareFile, formatError }
}
