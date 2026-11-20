# Laravel CRM

A CRM built entirely with **Laravel 12 + Filament 3** — multi-source lead capture, a fully
admin-configurable commission rules engine, sales agent panels and analytics.

[![CI](https://github.com/masthisis/laravel-crm/actions/workflows/ci.yml/badge.svg)](https://github.com/masthisis/laravel-crm/actions/workflows/ci.yml)
[![Build & Push Image](https://github.com/masthisis/laravel-crm/actions/workflows/build.yml/badge.svg)](https://github.com/masthisis/laravel-crm/actions/workflows/build.yml)
![Release](https://img.shields.io/github/v/release/masthisis/laravel-crm)

## Feature overview

| Area | What you get |
| --- | --- |
| **Panels** | Admin panel (`/admin`) and agent panel (`/agent`), both Filament, role-gated |
| **Roles & permissions** | `super_admin`, `admin` (back office), `sales_staff` (agents) via spatie/laravel-permission |
| **Lead sources** | Fully manageable sources with type, colour and per-source capture integrations |
| **Capture integrations** | Webhook API (`POST /api/leads`), embeddable iframe forms, external-DB sync, CSV import — extensible by design |
| **Source chain tracking** | Every lead tracks its **original source**, **re-engagement source** and **closing (sold) source** |
| **Commission rules** | Admin-defined percentage rules per source combination (original × re-engaged × sold), with specificity + priority resolution and a wildcard fallback |
| **Agent experience** | Pipeline with per-lead *estimated commission*, commissions grouped by source, customer-360 page for pre-call research, activity logging |
| **Import & assignment** | CSV import (auto-creating sources), then assign all to one agent or distribute evenly (workload-balanced) — also as table bulk actions |
| **Customers** | Purchase history, subscriptions, lead history — full 360 view for admin and agents |
| **Analytics** | Leads by source, revenue by sold source, agent conversion table, commissions payable/paid, agent pipeline widgets |
| **Docker** | One multi-stage `Dockerfile` (dev / app / nginx targets) + `docker-compose` files for **local**, **staging**, **production** |
| **CI/CD** | GitHub Actions: tests on 8.3/8.4, Pint, PHPStan, GHCR image builds, staging & production deploys, automatic releases |
| **Queue & scheduler** | Dedicated `worker` / `scheduler` containers (roles in the same image) |

## Quick start (Docker)

```bash
cp .env.example .env
make up          # builds & starts app + nginx + postgres + redis + mailpit
make fresh       # migrate + seed (admin user, sources, commission rules)
open http://localhost:8080
```

Default seeded logins:

| Role | URL | Email | Password |
| --- | --- | --- | --- |
| Super admin | `http://localhost:8080/admin` | `admin@example.com` | `ChangeMe!2024` |
| Sales agent | `http://localhost:8080/agent` | `agent@example.com` | `ChangeMe!2024` |

Optional demo dataset (24 leads, 8 commissions, customers with orders):

```bash
make seed-demo
```

## Documentation

| Document | Purpose |
| --- | --- |
| [`docs/INSTALLATION.md`](docs/INSTALLATION.md) | Full installation manual (Docker and manual PHP setup) |
| [`docs/RUNNING.md`](docs/RUNNING.md) | Day-to-day operations manual, API usage, troubleshooting |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Staging / production / PaaS deployment guide |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Domain model, commission engine, extensibility guide |
| [`docs/GIT_WORKFLOW.md`](docs/GIT_WORKFLOW.md) | Branching model, commit style, versioning & release process |

## Capturing leads from anywhere

```bash
# 1. Create a source + webhook integration in the admin panel
#    (Sources → Integrations → copy the token)

# 2. Push a lead from any external system
curl -X POST https://your-crm.example.com/api/leads \
  -H "X-Integration-Token: <token>" \
  -H "Content-Type: application/json" \
  -d '{"name": "Jane Doe", "phone": "+15551234567", "email": "jane@example.com"}'

# 3. Embeddable form for blog articles (iframe):
#    https://your-crm.example.com/embed/leads/<integration-id>
```

When a known lead re-appears from a *different* source, the CRM records it as a
**re-engagement** instead of creating a duplicate — which drives the commission rules.

## Tech stack

- PHP 8.2+ (8.3 recommended), Laravel 12, Filament 3
- PostgreSQL (default) or MySQL — switch with `DB_CONNECTION`
- Redis for queues/cache in production (database driver locally)
- Docker Compose, GitHub Actions, GHCR

## How we work

All work moves through GitHub: **issue → branch → pull request → merge → release** — in both directions. The agent turns requests into fully specified issues (investigation included) and implements them on `feature/issue-N-…` branches; you can also open an **Agent Task** issue with a prompt and simply say *"fetch issue #N and work on it"*. Releases run through a release PR (`develop` → `main`), a semver tag and a GitHub Release.

- Process: [`CONTRIBUTING.md`](CONTRIBUTING.md) · [`docs/GIT_WORKFLOW.md`](docs/GIT_WORKFLOW.md)
- Templates: *Agent Task*, *Feature request*, *Bug report* (Issues → New issue)

## License

MIT — see [LICENSE](LICENSE).
