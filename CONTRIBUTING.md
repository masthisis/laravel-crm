# Contributing

## Setup

See [`docs/INSTALLATION.md`](docs/INSTALLATION.md). Quick version:

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan migrate:fresh --seed --force
```

## Issue-driven workflow (mandatory)

Every change flows through GitHub issues — in **both** directions:

**A. Agent-driven (the agent turns a request into an issue):**
1. A request becomes a fully specified issue: goal, investigation (root
   causes, design decisions, alternatives), plan and acceptance criteria,
   labelled `type/*`, `area/*`, `priority/*`.
2. Branch off `develop` named `<type>/issue-<N>-<slug>`
   (e.g. `fix/issue-9-phpstan-quality`).
3. Implement with conventional commits that reference the issue.
4. Run the local gates (`pint`, `pest`, `phpstan`).
5. Open a PR against `develop` whose body contains `Fixes #N`; CI must be
   green; merge with a merge commit.
6. Periodically cut a release: release PR `develop` → `main`, semver tag,
   GitHub Release with generated notes.

**B. Owner-driven (you file, the agent fetches):**
1. Open an issue with the **Agent Task** template — a *Prompt / instruction*
   plus context and acceptance criteria. It is auto-labelled `agent/ready`.
2. Tell the agent: *"fetch issue #N and work on it"*.
3. The agent investigates, comments its plan on the issue, then follows
   steps 2–6 above — and releases if you ask for it.

Issue lifecycle labels: `status/triage` → `status/in-progress` →
`status/in-review` (PR open) → closed on merge.

## Workflow

1. Branch off `develop`: `<type>/issue-<N>-<slug>` or `feature/<topic>` /
   `fix/<topic>`.
2. Write conventional commits (`feat:`, `fix:`, `chore:` …) — see
   [`docs/GIT_WORKFLOW.md`](docs/GIT_WORKFLOW.md).
3. Before pushing:

```bash
vendor/bin/pint            # auto-fix code style
vendor/bin/pest            # tests must pass
vendor/bin/phpstan analyse # no new errors
```

4. Open a PR against `develop` using the template; keep it small and focused.
5. Update `CHANGELOG.md` for user-facing changes and `docs/` when behaviour,
   setup or operations change.

## Code guidelines

- Domain logic lives in `app/Services` (commission engine, assignment,
  import) — keep Filament resources thin and delegate to services.
- New DB-backed concepts get: migration + model + enum (if stateful) +
  factory + tests.
- Filament resources follow the existing patterns (navigation groups,
  infolists for rich views, services for actions).
- Never commit secrets; use `.env` (gitignored) and document new variables in
  `.env.example`.
