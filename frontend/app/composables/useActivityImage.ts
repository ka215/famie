export const useActivityImage = () => {
  const { fetchApi } = useApi()
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
    if (file.size > 20 * 1024 * 1024) throw new Error('20MB以下の画像を選択してください。')
    const header = new Uint8Array(await file.slice(0, 16).arrayBuffer())
    const brand = String.fromCharCode(...header.slice(8, 12))
    const isHeif =
      String.fromCharCode(...header.slice(4, 8)) === 'ftyp' &&
      ['heic', 'heix', 'mif1', 'msf1', 'hevc'].includes(brand)
    if (!isHeif) {
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type))
        throw new Error('対応していない画像形式です。')
      return file
    }
    const worker = new Worker(new URL('../utils/heic.worker.ts', import.meta.url), {
      type: 'module',
    })
    try {
      const result = await new Promise<Blob>((resolve, reject) => {
        const timeout = window.setTimeout(
          () => reject(new Error('画像変換がタイムアウトしました。')),
          90_000
        )
        worker.onmessage = async (
          event: MessageEvent<{
            blob?: Blob
            pixels?: ArrayBuffer
            width?: number
            height?: number
            error?: string
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
              if (!context) throw new Error('画像変換を利用できません。')
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
              if (!outputContext) throw new Error('画像変換を利用できません。')
              outputContext.drawImage(source, 0, 0, output.width, output.height)
              output.toBlob(
                (blob) => (blob ? resolve(blob) : reject(new Error('PNG画像を生成できません。'))),
                'image/png'
              )
            } catch (error) {
              reject(error)
            }
          } else {
            reject(new Error(event.data.error ?? 'HEIC画像を変換できません。'))
          }
        }
        worker.onerror = () => {
          window.clearTimeout(timeout)
          reject(new Error('HEIC画像を変換できません。'))
        }
        file.arrayBuffer().then((buffer) => worker.postMessage(buffer, [buffer]), reject)
      })
      return new File([result], 'activity-image.png', { type: 'image/png' })
    } finally {
      worker.terminate()
    }
  }

  onBeforeUnmount(releaseAll)
  return { imageUrl, releaseUrl, prepareFile }
}
