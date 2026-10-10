declare module 'libheif-js/libheif-wasm/libheif-bundle.mjs' {
  interface DecodedImage {
    get_width(): number
    get_height(): number
    is_primary?(): boolean
    display(
      data: { data: Uint8ClampedArray; width: number; height: number },
      callback: (result: { data: Uint8ClampedArray } | null) => void
    ): void
  }
  interface Decoder {
    decode(data: Uint8Array): DecodedImage[]
  }
  const createModule: () => Promise<{ HeifDecoder: new () => Decoder }>
  export default createModule
}
