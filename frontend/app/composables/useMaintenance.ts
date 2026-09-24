export const useMaintenance = () => {
  const isMaintenance = useState<boolean>('maintenance', () => false)
  return { isMaintenance }
}
