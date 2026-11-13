;(() => {
  // This selects navigation only; it does not grant access to settings.
  const params = new URLSearchParams(window.location.search)
  const from = params.get('from')
  const destinations = {
    login: '/login/',
    register: '/register/',
    settings: '/settings/',
    'legal-confirmation': '/legal-confirmation/',
  }
  const destination = Object.hasOwn(destinations, from) ? destinations[from] : '/login/'
  const embedded = params.get('embedded') === '1' && window.parent !== window
  if (embedded) document.documentElement.dataset.embedded = 'true'
  const back = document.getElementById('legal-back')
  if (back) {
    back.setAttribute('href', destination)
    back.textContent =
      document.documentElement.lang === 'ja' ? '元のページへ戻る' : 'Back to previous page'
    if (embedded) {
      back.addEventListener('click', (event) => {
        event.preventDefault()
        window.parent.postMessage({ type: 'famie:legal-back' }, window.location.origin)
      })
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          event.preventDefault()
          window.parent.postMessage({ type: 'famie:legal-back' }, window.location.origin)
        }
      })
    }
  }
  if (Object.hasOwn(destinations, from) || embedded) {
    for (const link of document.querySelectorAll('a[href^="/legal/"]')) {
      const url = new URL(link.getAttribute('href'), window.location.origin)
      if (url.origin !== window.location.origin) continue
      if (Object.hasOwn(destinations, from)) url.searchParams.set('from', from)
      if (embedded) url.searchParams.set('embedded', '1')
      link.setAttribute('href', `${url.pathname}${url.search}${url.hash}`)
      if (embedded) {
        link.addEventListener('click', (event) => {
          if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return
          event.preventDefault()
          window.location.replace(link.href)
        })
      }
    }
  }
})()
