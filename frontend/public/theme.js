(() => {
  const key = 'famie-theme'
  const valid = (value) => value === 'light' || value === 'dark'
  const apply = (value, persist = true) => {
    if (!valid(value)) return false
    document.documentElement.dataset.theme = value
    document.documentElement.style.colorScheme = value
    for (const meta of document.querySelectorAll('meta[name="theme-color"]')) {
      meta.removeAttribute('media')
      meta.content = value === 'dark' ? '#020617' : '#f8fafc'
    }
    let saved = true
    if (persist) {
      try { localStorage.setItem(key, value) } catch { saved = false }
    }
    window.dispatchEvent(new Event('famie-theme-change'))
    return saved
  }
  window.famieTheme = { apply }
  let initial
  try { initial = localStorage.getItem(key) } catch { /* Storage may be unavailable. */ }
  if (!valid(initial)) initial = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
  apply(initial)
  window.addEventListener('storage', (event) => {
    if (event.key === key && valid(event.newValue)) apply(event.newValue, false)
  })
})()
