type Theme = 'light' | 'dark'

declare global {
  interface Window {
    famieTheme?: { apply: (value: Theme) => boolean }
  }
}

export const useTheme = () => {
  const theme = useState<Theme>('display_theme', () =>
    import.meta.client && document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light'
  )
  const storageError = useState('theme_storage_error', () => false)
  const sync = () => {
    theme.value = document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light'
  }
  onMounted(() => {
    sync()
    window.addEventListener('famie-theme-change', sync)
  })
  onBeforeUnmount(() => window.removeEventListener('famie-theme-change', sync))
  const setTheme = (value: Theme) => {
    if (!window.famieTheme) {
      document.documentElement.dataset.theme = value
      document.documentElement.style.colorScheme = value
      theme.value = value
      storageError.value = true
      return
    }
    storageError.value = !window.famieTheme.apply(value)
  }
  return { theme, setTheme, storageError }
}
