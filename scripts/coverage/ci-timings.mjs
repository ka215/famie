import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

// Input: gh run view RUN --json headSha,event,createdAt,updatedAt,jobs
const paths = process.argv.slice(2)
assert(paths.length > 0, 'Supply saved GitHub run JSON reports')
const seconds = (start, end) => Math.max(0, (Date.parse(end) - Date.parse(start)) / 1000)
for (const path of paths) {
  const run = JSON.parse(await readFile(path, 'utf8'))
  assert(/^[a-f0-9]{40}$/.test(run.headSha) && run.jobs.length > 0)
  const jobs = run.jobs.filter((job) => job.conclusion !== 'skipped').map((job) => ({
    name: job.name, result: job.conclusion,
    duration_seconds: seconds(job.startedAt, job.completedAt),
    // Includes dependencies and scheduling, not a pure runner queue measurement.
    wait_from_created_seconds: seconds(run.createdAt, job.startedAt),
    steps: job.steps.filter((step) => step.conclusion !== 'skipped').map((step) => ({
      name: step.name, duration_seconds: seconds(step.startedAt, step.completedAt),
    })),
  }))
  console.log(JSON.stringify({
    commit: run.headSha, event: run.event, created_at: run.createdAt,
    wall_seconds: Math.max(...run.jobs.map((job) => seconds(run.createdAt, job.completedAt))),
    runner_seconds: jobs.reduce((sum, job) => sum + job.duration_seconds, 0), jobs,
  }))
}
