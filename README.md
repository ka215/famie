# Famie

Famie is a comprehensive workspace guide designed to streamline development processes for various projects within the Famie organization. This document provides guidelines, environment policies, and essential commands to ensure a consistent and efficient development environment across different projects.

## Table of Contents
1. [Workspace Structure](#workspace-structure)
2. [Environment Policy](#environment-policy)
3. [Command Prioritization](#command-prioritization)
4. [Repository Actions](#repository-actions)
5. [Validation Policy](#validation-policy)
6. [Working with Windows Shell](#working-with-windows-shell)
7. [Error Handling](#error-handling)
8. [Prohibited Practices](#prohibited-practices)
9. [Document Management](#document-management)

## Workspace Structure

The workspace is organized as follows:

```
famie/
├── frontend/     # Frontend project
├── backend/      # Backend project
├── other/        # Other projects or utilities
└── docs/         # Documentation
```

## Environment Policy

- **Development Environment:** Windows
- **Recommended Shell:** PowerShell
- **Package Manager:** npm / pnpm / composer
- **Python is discouraged:** No Python scripts should be generated or executed without confirmation.

### Startup Checks (Windows)

```powershell
Get-Location
node -v
pnpm -v
php -v
composer -V
git --version
python --version   # Continue if it fails
```

- `node -v` or `pnpm -v` failing → Explain missing tools and stop
- `python --version` failing → Do not attempt Python-based alternatives

## Command Prioritization

Select commands based on the following priority:

1. Scripts defined in `package.json` / `composer.json`
2. Existing scripts committed to the repository (`scripts/`, `beta/start_server.php`, etc.)
3. PowerShell commands/scripts
4. Node.js one-off scripts
5. Bash (only if explicitly required and available)
6. Python (only if confirmed and necessary to resolve issues)

### Search Command Rules

- Text search: Use `rg` (ripgrep) as the first choice.
- File list search: Use `rg --files` as the first choice.
- Filter with `rg` first, use additional commands (PowerShell `Select-String`, etc.) if needed.
- Use alternative commands if `rg` is not available in the environment.

## Workspace-Specific Command Cheat Sheet

### frontend/ (pnpm + Nuxt)

```powershell
pnpm install          # Install dependencies
pnpm dev              # Start development server (http://localhost:3000)
pnpm build            # Production build (remove .output directory first)
pnpm preview          # Preview built version
pnpm generate         # Static export
pnpm clean            # Cache clean
npx @biomejs/biome check --write .   # Lint + format
```

### backend/ (composer + Laravel)

```powershell
composer install
php artisan key:generate
composer dev          # Start PHP server / queue / log / Vite in parallel
composer test         # Run tests
```

### other/ (npm)

```powershell
npm install
```

## Repository Actions

- Reuse existing scripts, composerables, utilities, and type definitions.
- Do not modify files outside the task's directory.
- Structural changes are only allowed if explicitly required by the task.
- Adhere to existing naming, formatting, and architecture conventions.

## Validation Policy

Minimize cost by validating first.

1. Lint / type check of changed files
2. Tests covering the affected scope
3. Full build only when necessary

```powershell
# frontend
npx @biomejs/biome check .
pnpm build

# backend
php artisan test
```

If validation fails, review the command output and consider a different approach before repeating the same failure.

## Working with Windows Shell

Use PowerShell as the primary shell. Avoid Unix-specific commands.

```powershell
# Unix alternatives
Get-ChildItem          # ls
Get-Content .\file.txt # cat
Set-Location .\app     # cd
Test-Path .\node_modules # test -d
```

## Error Handling

1. Explain the specific reason for the failure
2. Do not blindly repeat the same approach in another runtime
3. Windows alternatives: PowerShell → Existing repository scripts → Node.js
4. Do not use Python-based alternatives if Python is not confirmed
5. If necessary tools are missing, explain and stop

## Prohibited Practices (Common to All Workspaces)

- Do not generate or execute Python scripts without confirming Python availability
- Do not perform file operations, text replacements, or configuration checks via Python
- Do not use `npm` as a substitute for `pnpm` (frontend)
- Do not invent custom commands without confirming existing `package.json` scripts
- Do not make unrelated file changes or cleanups

## Document Management

- `docs/` contains long-term reference documents (`*-plan.md`, `*-spec.md`, `*-notes.md`).
- Temporary notes and investigations should be placed in `.temp/`.
- The main copy of GitHub issues should be in GitHub. Use `.github/ISSUE_TEMPLATE/` templates.
