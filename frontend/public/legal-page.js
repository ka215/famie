;(() => {
  // This selects navigation only; it does not grant access to settings.
  const fromSettings = new URLSearchParams(window.location.search).get('from') === 'settings'
  const back = document.getElementById('legal-back')
  if (back && fromSettings) {
    back.setAttribute('href', '/settings/')
    back.textContent = document.documentElement.lang === 'ja' ? '設定へ戻る' : 'Back to settings'
  }
  if (fromSettings) {
    for (const link of document.querySelectorAll('a[href^="/legal/"]')) {
      const url = new URL(link.getAttribute('href'), window.location.origin)
      if (url.origin !== window.location.origin) continue
      url.searchParams.set('from', 'settings')
      link.setAttribute('href', `${url.pathname}${url.search}${url.hash}`)
    }
  }
})()
