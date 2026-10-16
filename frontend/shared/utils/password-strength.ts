export const PASSWORD_GUIDANCE =
  '15文字以上・72バイト以内で、よく使われる語や単純な繰り返しを避けてください。'

export function passwordStrength(password: string) {
  const compact = password.toLowerCase().replace(/[^\p{L}\p{N}]/gu, '')
  const simple =
    !compact ||
    /^(.{1,16})\1+$/u.test(compact) ||
    /^(password|qwerty|letmein|welcome|iloveyou|admin|famie|パスワード)[0-9]*$/u.test(compact) ||
    [
      '0123456789',
      '9876543210',
      'abcdefghijklmnopqrstuvwxyz',
      'zyxwvutsrqponmlkjihgfedcba',
      'qwertyuiop',
    ].some((sequence) => sequence.repeat(8).includes(compact))
  const acceptable =
    [...password].length >= 15 &&
    new TextEncoder().encode(password).length <= 72 &&
    !/\p{Cc}/u.test(password) &&
    !simple

  return {
    acceptable,
    score: !password ? 0 : !acceptable ? 1 : [...password].length >= 24 ? 3 : 2,
  }
}
