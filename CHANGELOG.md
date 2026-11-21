# Changelog

All notable changes to this project are documented in this file.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] — 2026-11-21

Spec-implementation milestone: the ARM Invest CRM specification
(`crm-laravel-docs`) implemented end to end, specified in #47–#50 and
shipped through the issue → branch → PR → CI → merge pipeline.

### Added — LeadService core (#47, PR #51)
- **Canonical phones**: `PhoneNormalizer` (Persian/Arabic digits, separators, `+98`/`0098`/`98`/`0` prefixes, valid = 10 digits starting 9) + lookup variants, used everywhere.
- **Contact/entry model**: `lead_contacts` (one person per canonical phone, owner columns), `lead_entries` (`UNIQUE(source_id, origin_ref)` idempotency, returning flag, invalid phones kept flagged — never dropped), `lead_entry_facets`, `lead_contact_sources`.
- **Sources**: `kind`/`driver`/`crm_lead_type`(+map)/`config`/`importable`/`commission_percent` on `lead_sources`, 15 seeded system sources, badge + text-luminance helpers.
- **File import**: csv/txt/xlsx/ods (own OOXML/ODF reader, no new dependency), Persian header aliases, ≤12 extra payload columns, returning-not-duplicate semantics, exact in-file repeats skipped with the row they repeat, SHA-1 duplicate-file warning, custom source creation.
- **Legacy copy**: `crm:copy-legacy-leads` — 9 adapters over the read-only `legacy` connection, id cursors, resumable/idempotent.
- **Jalali + Persian support** (jdf conversions, month keys/ranges, Persian digits/toman).

### Added — Intake & assignment (#48, PR #52)
- **Event contract**: `POST /api/events` with HMAC-SHA256 verification, replay receipts, handlers for lead/account/invoice/call events (`lead.verified`/`lead.paid` propagate the updates the legacy copy missed, 11 #29).
- **Polling fallback**: `crm:poll-legacy` — 13 producer tables by id cursor + status windows by `update_date` with overlap; shared idempotency keys with the webhook path.
- **ارجاع engine**: eligible-operator gate, re-checked targets, unique-by-phone (latest entry = trigger), 30-day duplicate window with confirm/force, single/round_robin, per-batch transaction writing `crm_referrals` + `lead_assignments` + owner + frozen engagement context; `trackReferral`/`trackSnooze`/`trackReject` (reject enforces ownership, 11 #5).
- **Engagement tags**: first/latest from entries (+UTM visits), frozen per-referral contexts (complete/inferred/unknown).
- **Conversion report**: history vs owner-at-purchase views, DISTINCT contacts, Jalali weekly/monthly/agent grouping.

### Added — Operator workspace & phone system (#49, PR #53)
- **ارجاع روز** page: badges, engagement tags, commission badge, applied filters (11 #9), عدم خرید dialog, SMS templates with part counter + working server handler (11 #11), click-to-call.
- **Call logs** with correct missed-call precedence (11 #8), **call queue** with outcomes, **reminders**, **sales** chart (ExtenSalesCond, operator 1 skipped, product 22 excluded) with conflict detection, **unknown-number register**.
- **Dashboard** widgets: unpaid invoices of my sales (scoped count, 11 #13), last 40 calls, last 40 notes.
- **PBX**: authenticated call-event webhook (11 #3) with one row per ringing extension, event-driven `IncomingCall` broadcast (11 #18), click-to-call proxy with AES-encrypted params, `crm:verify-cdr`, token-based desktop/mobile lookups (11 #2).
- **Customer file**: CRM sections (owner/sources, notes, calls, referrals, installments, talking points), lookup seeders, `customer_admin_log` audit trail.

### Added — UTM, segments, commission (#50, PR #54)
- **UTM & attribution**: immutable links (+automatic dedupe), visit records with first-identity-wins identification, per-lead organic attribution with vt_/ft_/lnd_ dimensions, spec-order referrer classifier, `utm.identified`/`attribution.recorded` intake handlers.
- **Segment engine**: rule tree (depth 3 / 20 rules, negate), 15+ criteria incl. the paid = 2/3/4 + BazaarPay/Myket status-3 rule and `exclude_paid_in_period`, app-activity criteria via the legacy connection, 600 s snapshot cache, four system segments.
- **Commission PRD**: insert-only earnings keyed by invoice, recipient = agent discount-code owner (ambiguous → review), rate from the latest assignment source (else PRD lead-type rates, else 2 %), Jalali month, `crm:commission-sync` with lock, manager page (breakdowns, payout modal unique per agent/month) + agent earnings page.
- **Presentation**: Persian locale (RTL panels), Jalali dates + toman amounts, `.env.example` documents every integration variable.

### Fixed
- Legacy bugs from spec 11 fixed by design: plaintext passwords (Laravel hashing), query-string credentials (tokens), unauthenticated PBX/CDR endpoints (shared secret), missing ownership checks (policies/service guards), missed-call OR precedence, ignored referral filters, UI-only SMS, cosmetic unpaid total, live-call polling (push events).

**Full diff**: https://github.com/masthisis/laravel-crm/compare/v1.1.0...v1.2.0

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
