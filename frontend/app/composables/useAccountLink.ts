export const useAccountLink = () => {
  const router = useRouter()
  const id = ref('')
  const token = ref('')
  onMounted(() => {
    const parameters = new URLSearchParams(window.location.hash.slice(1))
    id.value = parameters.get('id') ?? ''
    token.value = parameters.get('token') ?? ''
    window.history.replaceState(window.history.state, '', window.location.pathname)
    void router.replace({ path: window.location.pathname, hash: '' })
  })
  const valid = computed(() => /^\d+$/.test(id.value) && /^[A-Za-z0-9]{64}$/.test(token.value))
  return { id, token, valid }
}
