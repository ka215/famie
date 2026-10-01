/** 保存色は維持し、バッジ文字に黒・白のうちコントラストが高い方を使用する。 */
export const categoryStyle = (value?: string | null) => {
  const backgroundColor = value && /^#[\da-f]{6}$/i.test(value) ? value : '#3B82F6'
  const channels = [1, 3, 5].map((offset) => {
    const channel = Number.parseInt(backgroundColor.slice(offset, offset + 2), 16) / 255
    return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
  })
  const luminance =
    (channels[0] ?? 0) * 0.2126 + (channels[1] ?? 0) * 0.7152 + (channels[2] ?? 0) * 0.0722
  return { backgroundColor, color: luminance > 0.179 ? '#000000' : '#ffffff' }
}
