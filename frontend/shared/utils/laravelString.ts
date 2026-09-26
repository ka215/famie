// Laravel Str::trim / TrimStrings と同じ境界の空白・不可視文字を取り除く。
const boundaryCharacters =
  String.raw`\s\u0000\u0085\u00AD\u034F\u061C\u115F\u1160\u17B4\u17B5\u180E` +
  String.raw`\u2000-\u200F\u202F\u205F\u2060-\u2065\u206A-\u206F\u2800\u3000\u3164\uFEFF\uFFA0` +
  String.raw`\u{1D159}\u{1D173}-\u{1D17A}\u{E0020}`
const boundaryWhitespace = new RegExp(`^[${boundaryCharacters}]+|[${boundaryCharacters}]+$`, 'gu')

export const trimLaravelString = (value: string): string => value.replace(boundaryWhitespace, '')
