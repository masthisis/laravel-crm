# Contributing

## Setup

See [`docs/INSTALLATION.md`](docs/INSTALLATION.md). Quick version:

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan migrate:fresh --seed --force
```

## Workflow

1. Branch off `develop`: `feature/<topic>` / `fix/<topic>`.
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
