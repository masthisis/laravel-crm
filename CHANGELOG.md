# Changelog

All notable changes to this project are documented in this file.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] — 2026-11-20

### Added

#### Core
- Laravel 12 + Filament 3 application skeleton with Docker-first configuration.
- Role-based access control (`super_admin`, `admin`, `sales_staff`) via
  spatie/laravel-permission, seeded permissions, and panel-level access rules.

#### Leads & sources
- Lead sources with type, colour and active flag; capture integrations bound
  to sources by token.
- Webhook API `POST /api/leads` with `X-Integration-Token` auth, throttling,
  validation and deduplication.
- Embeddable lead form (`/embed/leads/{integration}`) for blog articles and
  landing pages.
- Cross-database lead sync (`crm:sync-external-leads`) with configurable
  connection, table and column mapping; scheduled hourly.
- Re-engagement tracking: known open leads returning from another source are
  flagged with a re-engagement source instead of duplicating.
- Per-lead tracking of original source, re-engagement source and closing
  (sold) source.

#### Commissions
- Commission rules engine: admin-defined percentage rules per source
  combination with specificity scoring, priority tie-breaking and wildcard
  fallback.
- Automatic commission creation when a lead is marked won; approve/pay
  workflow for admins.
- Agent-facing estimated commission per pipeline lead.

#### Import & assignment
- CSV lead import page with per-row or default source selection and automatic
  source creation.
- Assignment strategies: all-to-one and workload-balanced even distribution;
  available as bulk actions too; assignment batch audit trail.

#### Panels
- Admin panel: leads, sources, integrations, commission rules, commissions,
  customers, products, orders, subscriptions, users, import batches,
  import/assignment page.
- Agent panel: my leads (call-prep view with customer history and activity
  logging), my commissions grouped by source, customer-360 lookup.
- Analytics dashboards: leads by source, revenue by sold source, agent
  performance table (assigned/won/conversion/commissions), agent pipeline
  widgets.

#### Infrastructure
- Multi-stage Dockerfile (`dev`, `app`, `nginx` targets) with role-based
  containers (`app`, `queue`, `scheduler`).
- Docker Compose stacks for local, staging and production.
- Makefile with common operations.
- GitHub Actions: CI (tests on PHP 8.3/8.4, Pint, PHPStan), GHCR image
  builds, staging and production deploy pipelines, release automation,
  Dependabot.
- Pest test suite covering the commission engine, capture API, assignment
  strategies and panel access.
- Documentation: installation, operations, deployment, architecture and
  git workflow manuals.
