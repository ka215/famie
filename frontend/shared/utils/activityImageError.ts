export const imageErrorKeys = {
  tooLarge: 'activity.imageTooLarge',
  unsupported: 'activity.imageUnsupported',
  timeout: 'activity.imageTimeout',
  unavailable: 'activity.imageUnavailable',
  pngFailed: 'activity.imagePngFailed',
  heicFailed: 'activity.imageHeicFailed',
  loadFailed: 'activity.imageLoadFailed',
  pixels: 'activity.imagePixels',
  convertFailed: 'activity.imageConvertFailed',
} as const

export type ImageErrorCode = keyof typeof imageErrorKeys

export const isImageErrorCode = (code: unknown): code is ImageErrorCode =>
  typeof code === 'string' && Object.hasOwn(imageErrorKeys, code)

export class ActivityImageError extends Error {
  constructor(
    readonly code: ImageErrorCode,
    readonly pixelLimit?: number
  ) {
    super(code)
    this.name = 'ActivityImageError'
  }
}
