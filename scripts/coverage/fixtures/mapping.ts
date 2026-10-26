export class NeverCalled {
  constructor(readonly value: string) {
    throw new Error(value)
  }
}

export const called = (value: boolean): string => {
  if (value) return 'yes'
  return 'no'
}
