# Running & Operations Manual

Everything you need day-to-day once the CRM is installed.

---

## 1. URLs and panels

| Panel | URL | Who |
| --- | --- | --- |
| Admin panel | `http://<host>/admin` | `super_admin`, `admin` |
| Agent panel | `http://<host>/agent` | `sales_staff`, `super_admin` |
| Health check | `http://<host>/up` | monitoring |
| Mail catcher (local) | `http://localhost:8025` | dev only |

---

## 2. Make targets (Docker)

```bash
make up            # build + start stack
make down          # stop
make logs          # tail logs
make shell         # sh inside the app container
make tinker        # Laravel tinker
make migrate       # run migrations
make fresh         # drop + migrate + seed
make seed-demo     # demo dataset
make test          # run the Pest suite
make lint          # Pint code style
make analyse       # PHPStan
make cache-clear   # clear config/route/view caches
```

Staging / production equivalents: `make staging-up`, `make staging-migrate`,
`make prod-up`, `make prod-migrate` (see `Makefile`).

---

## 3. Admin panel guide

### 3.1 Lead sources (Sources → Lead sources)

Every lead must have an **original source**. Sources carry a type
(embedded form / webhook / API / external-DB sync / import / manual), a colour
used in charts, and an active flag.

### 3.2 Capture integrations (Sources → Integrations)

Each integration binds a **token** to a source. Developers use this token from
external systems — see §4 below. Create one integration per external system
(e.g. one token per blog article, one per ad platform).

### 3.3 Commission rules (Commissions → Commission rules)

A rule = a percentage + an optional source combination:

- **Original source** — required-ish; empty = matches any source (wildcard).
- **Re-engagement source** — set when a rule applies only to leads that came
  back through a specific channel.
- **Closing source** — set when the rule depends on where the deal finally closed.

Resolution: the **most specific** matching rule wins (each non-empty source adds
specificity), ties break by **priority**, then by recency. Always keep a
wildcard fallback rule (all sources empty) so agents always see a rate.

### 3.4 Importing leads (Leads → Lead import & assignment)

1. Upload a CSV with header row: `name, phone, email, source, estimated_value`
   (only `name` is required; rows may carry their own `source` value —
   unknown sources are created automatically).
2. Pick a default source (or type a new one) for rows without their own.
3. Choose the assignment strategy: *unassigned*, *all to one agent*, or
   *distribute evenly* (balanced by each agent's current open workload).

You can also select leads in the list and use the bulk actions
**Assign to agent** / **Distribute evenly** at any time.

### 3.5 Marking deals & commissions

Use the **Mark won** action on a lead (list row or detail page). The CRM:

1. sets the closing source + sold amount,
2. matches the commission rule for the source combination,
3. creates a `pending` commission for the assigned agent.

Admins then **Approve** and **Mark paid** commissions in the Commissions screen.

### 3.6 Analytics (Dashboard)

- totals: leads, won + conversion, revenue, commissions payable/paid;
- leads by original source (bar), revenue by sold source (pie);
- per-agent table: assigned / won / conversion % / earned commissions.

---

## 4. External lead capture (developer guide)

### 4.1 Webhook API

```bash
curl -X POST https://<host>/api/leads \
  -H "X-Integration-Token: <token>" \
  -H "Content-Type: application/json" \
  -d '{"name":"Jane","phone":"+15551234567","email":"jane@example.com","notes":"blog form"}'
```

- `201` — new lead created (deduplicated by phone),
- `200` + `"duplicate": true` — same source, lead already open,
- `200` + `"re_engaged": true` — the lead came back from another source;
  the CRM stored the new source as the **re-engagement source**,
- `404` — missing/inactive token, `422` — validation error.

### 4.2 Embeddable form (blog articles, landing pages)

```
https://<host>/embed/leads/<integration-id>
```

Put it in an `<iframe>`. The form posts to the same API and shows a success
state. Submissions without JSON headers are redirected back with `?submitted=1`.

### 4.3 Cross-database lead tracking

Configure a read-only connection to ANY other database:

```dotenv
EXTERNAL_SYNC_ENABLED=true
EXTERNAL_DB_DRIVER=pgsql            # or mysql
EXTERNAL_DB_HOST=legacy.example.com
EXTERNAL_DB_DATABASE=old_crm
EXTERNAL_DB_USERNAME=readonly
EXTERNAL_DB_PASSWORD=...
EXTERNAL_SYNC_TABLE=external_leads
```

Then either run manually or let the scheduler (hourly) handle it:

```bash
php artisan crm:sync-external-leads            # pulls up to 500 rows/run
php artisan crm:sync-external-leads --source="Legacy CRM"
```

Rows are tracked by their external id in the lead's `meta` JSON, so re-runs
are idempotent. Column names are remappable via `EXTERNAL_SYNC_COL_*`.

### 4.4 Writing your own driver

The capture pipeline is intentionally thin: resolve token → `LeadIntegration`
→ create/update a `Lead`. New drivers (e.g. Twilio inbox, Zapier) only need to
POST to `/api/leads` or add an `IntegrationDriver` enum case and a small
controller/connector — no core changes.

---

## 5. Agent panel guide

- **Dashboard** — open pipeline count/value, deals won, pending commissions,
  pipeline-by-status chart, commissions-by-source chart.
- **My leads** — only the agent's own leads. Each row shows the *estimated
  commission* they would earn.
- **Lead detail (call-prep page)** — contact info, original + re-engagement
  sources, commission preview, existing customer history (purchases &
  subscriptions), full activity log. Agents can **Log activity** (call / email /
  SMS / meeting / note) and **Mark won**.
- **My commissions** — all commissions grouped collapsibly by original or
  closing source.
- **Customers** — searchable customer-360: profile, every purchase, every
  subscription, lead history.

---

## 6. Queues & scheduler

Local default is the `database` queue (no worker needed). In staging/production
dedicated containers run automatically:

```bash
docker compose -f docker-compose.production.yml ps   # app, worker, scheduler
```

To run manually:

```bash
php artisan queue:work --sleep=3 --tries=3
php artisan schedule:work        # runs crm:sync-external-leads hourly
```

---

## 7. Testing

```bash
vendor/bin/pest              # full suite (sqlite in-memory)
vendor/bin/pint --test       # code style
vendor/bin/phpstan analyse   # static analysis
```

---

## 8. Troubleshooting

| Symptom | Fix |
| --- | --- |
| `2002`/connection refused to DB | DB not up yet — the entrypoint waits; check `docker compose logs postgres` |
| 500 on first load | `APP_KEY` missing → `php artisan key:generate` |
| Uploaded files fail | `storage/` permissions → `chown -R www-data:www-data storage bootstrap/cache` |
| Views look stale after deploy | caches are rebuilt by the entrypoint; force: `php artisan optimize:clear` |
| Leads not imported from external DB | check `EXTERNAL_SYNC_ENABLED=true`, `docker compose logs scheduler` |
| Panel login says no access | user lacks the panel role (assign `sales_staff` / `admin`) |
