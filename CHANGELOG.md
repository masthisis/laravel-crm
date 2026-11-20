# Changelog

All notable changes to this project are documented in this file.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] — 2026-11-20

Persona-driven milestone: 14 issues explored as the admin, the sales agent
and the developer, specified in #17-#30, all shipped through the issue ->
branch -> PR -> CI -> merge pipeline.

### Added
- **Sales conversion**: marking a lead won now creates/links the customer, an order (+ active subscription for recurring products) and a sale activity — revenue and the 360 view reflect real sales (#17, PR #31).
- **Agent workflow**: follow-ups (`follow_up_at`), My-day dashboard widget, quick actions (log activity, schedule follow-up, mark lost with reason, reopen) and pipeline filters (today, due, stale) (#19, PR #32).
- **Notifications**: Filament database notifications on both panels, batched assignment notifications, daily follow-up digest command (#20, PR #33).
- **Triage**: unassigned lead-pool filter, stale filter, arrived-today filter and an unassigned-open badge on Leads (#18, PR #34).
- **Safe agent deactivation** with lead reassignment (pool / all-to-one / distribute), login blocking, active-agents scope shared by every agent select (#22, PR #36).
- **Import hardening**: duplicate policy (skip/import), persisted per-batch report (counters + row errors), rejected-rows CSV download, CSV template (#21, PR #37).
- **Capture architecture**: pluggable `LeadChannel` adapters + registry, domain events (`LeadCaptured`, `LeadReengaged`, `DuplicateCaptureIgnored`), new `docs/API.md` (#24, PR #40).
- **External sync v2**: multi-connection, chunked ordered reads, incremental cursor, `--dry-run`, `sync_runs` audit table + admin resource, overlap-guarded schedule, failure notifications (#25, PR #41).
- **Rule simulator** on the Commission rules page (#26, PR #38).
- **Analytics periods** on dashboard charts; stock metrics labelled all-time (#27, PR #39).
- **Customer 360**: renewal alerts (30-day window), merged customer+lead activity timeline, log-customer-note action, renewals widget (#28, PR #42).
- **Token security**: integration tokens hashed at rest (SHA-256), reveal-once + regenerate, embed forms post their public integration id (#29, PR #43).

### Changed
- Commissions are no longer created for unassigned won leads (sale recorded, admin notified why) (#17).
- `commission_rules` are fetched once per request (scoped singleton + memoization) — pipeline tables no longer issue per-row rule queries (#23, PR #35).

### Fixed
- PHPStan quality gate restored (see 1.0.1).

## [1.0.1] — 2026-11-20

### Added
- Bidirectional issue-driven agent workflow: **Agent Task / Feature request /
  Bug report** issue templates, label taxonomy (`type/*`, `status/*`,
  `priority/*`, `area/*`, `agent/ready`), process documentation in
  `CONTRIBUTING.md` and `docs/GIT_WORKFLOW.md` §5, and a README
  "How we work" section (#10, PR #14).

### Fixed
- PHPStan clean again: removed ten redundant nullsafe property accesses in
  front of `??` and pruned two stale `ignoreErrors` patterns (#9, PR #13).

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
