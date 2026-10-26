import { pathToFileURL } from 'node:url'
import { utilityFiles } from './utility-files.mjs'

// Load definitions to include uncalled functions in the denominator; do not call them.
for (const file of await utilityFiles()) await import(pathToFileURL(file))
