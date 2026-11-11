import { getServers } from 'node:dns'
import { Resolver } from 'node:dns/promises'

if (process.env.GITHUB_ACTIONS !== 'true' || process.env.RUNNER_OS !== 'Linux') {
  throw new Error('Run DNS diagnostics only on a GitHub Actions Linux runner.')
}

// Public dependency/control hosts only. No system configuration is changed.
const hosts = ['archive.ubuntu.com', 'azure.archive.ubuntu.com', 'apt.postgresql.org', 'github.com']
const resolvers = [
  ['configured', getServers()],
  ['google', ['8.8.8.8']],
  ['cloudflare', ['1.1.1.1']],
]

await Promise.all(resolvers.map(async ([label, servers]) => {
  const resolver = new Resolver({ timeout: 2000, tries: 1 })
  resolver.setServers(servers)
  console.log(`DNS ${label}: servers=${servers.join(',')}`)
  await Promise.all(hosts.map(async (host) => {
    try {
      const records = await resolver.resolve4(host)
      console.log(`DNS ${label}: ${host} OK A_records=${records.length}`)
    } catch (error) {
      const code = typeof error.code === 'string' && /^[A-Z_]+$/.test(error.code) ? error.code : 'FAILED'
      console.log(`DNS ${label}: ${host} ${code}`)
    }
  }))
}))
