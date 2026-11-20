# Architecture

## 1. Stack

```
┌────────────┐   ┌─────────────────────────────────────────────┐
│   nginx    │──▶│  PHP-FPM  Laravel 12 + Filament 3           │
│ (static /  │   │  ├── Admin panel   /admin  (super_admin,    │
│  fastcgi)  │   │  │                          admin)          │
└────────────┘   │  ├── Agent panel   /agent  (sales_staff,    │
                 │  │                          super_admin)    │
┌────────────┐   │  ├── API  /api/leads  (token auth)          │
│ PostgreSQL │◀──│  ├── Embed /embed/leads/{integration}       │
│  or MySQL  │   │  └── Queue worker / scheduler (same image)  │
└────────────┘   └───────────────┬─────────────────────────────┘
┌────────────┐                   │
│   Redis    │◀──────────────────┘  cache / queues / sessions
└────────────┘
```

## 2. Domain model

```
LeadSource ──< LeadIntegration        # capture integrations (token → source)
LeadSource ──< Lead                   # original source
LeadSource ──< Lead (re_engaged)      # re-engagement source
LeadSource ──< Lead (sold)            # closing source
Lead ──< LeadActivity                # call/email/meeting log
Lead ──< Commission                  # agent payout per sale
CommissionRule                       # rate × source combination (null = wildcard)
User ──(roles)── super_admin / admin / sales_staff
User ──< Lead (assigned_agent)       # assignment (single / distributed batches)
Customer ──< Order ──> Product       # purchase history
Customer ──< Subscription            # recurring products
AssignmentBatch                      # import/assignment audit trail
```

Key enums: `LeadStatus` (new → contacted → qualified → re_engaged → won/lost),
`SourceType`, `IntegrationDriver`, `CommissionStatus` (pending → approved → paid),
`AssignmentStrategy`, `ProductType`, `SubscriptionStatus`, `ActivityType`.

## 3. Commission rules engine (`App\Services\CommissionService`)

A rule matches a lead when **every source it defines equals the lead's**
corresponding source. `NULL` on a rule source is a wildcard.

Specificity scoring (higher wins):

| Rule field | Matches lead field | Points |
| --- | --- | --- |
| `original_source_id` | `lead.source_id` | +4 |
| `re_engaged_source_id` | `lead.re_engaged_source_id` | +2 |
| `sold_source_id` | `lead.sold_source_id` | +2 |

Ties → higher `priority` → newest. `recordSale()` transitions the lead to
`won`, snapshots the source chain onto the commission and stores
`amount = sold_amount × rate / 100` (rounded per `CRM_COMMISSION_ROUNDING`).
Idempotent: a lead can only have one commission.

Examples:

| Rule | Meaning |
| --- | --- |
| original=Blog, re=Facebook, rate 25% | Blog lead that came back via Facebook Ads pays 25% |
| original=Blog, rate 15% | any Blog lead pays 15% |
| all empty, rate 10% | wildcard fallback for everything else |

## 4. Lead capture & re-engagement flow

```
External system ──POST /api/leads──▶ token ─▶ LeadIntegration ─▶ source
                                     │
             phone already open? ─────┤
                │ yes                 │ no
       same source?                   ▼
        │ yes: 200 duplicate    create Lead (new)
        │ no:  set re_engaged_source_id,
        │      status=re_engaged → commission rules can react
```

Embeddable forms (`/embed/leads/{id}`) and the external-DB sync command
(`crm:sync-external-leads`, hourly, idempotent by external ref) feed the same
pipeline. New drivers only need the token + POST — see `docs/RUNNING.md` §4.4.

## 5. Assignment

`LeadAssignmentService` implements two strategies used by both the import page
and table bulk actions:

- **single** — all leads to one agent (`AssignmentBatch` records it);
- **distribute** — balanced round-robin: agents with fewer *open* leads get
  leads first, so the workload evens out (checked before each assignment).

## 6. Authorization

| Ability | super_admin | admin | sales_staff |
| --- | --- | --- | --- |
| Admin panel | yes | yes | no |
| Agent panel | yes | no | yes |
| Leads (all, CRUD, import, assign) | yes | yes | own only (via policy + query scope) |
| Commissions approve/pay | yes | yes | view own only |
| Customers 360 | yes | yes | read |
| Products / orders / subscriptions / users / sources / rules | yes | yes | no |

`super_admin` bypasses all gates (`Gate::before`); spatie/laravel-permission
seeds the roles/permissions; panel access is gated by `User::canAccessPanel()`.

## 7. Filament panels

- `AdminPanelProvider` — discovers `app/Filament/Admin/**` (resources, pages,
  widgets). Custom dashboard page composes the analytics widgets.
- `AgentPanelProvider` — discovers `app/Filament/Agent/**`. Lead resources
  scope queries to `assigned_agent_id = auth::id()`; the customer resource
  reuses the shared customer-360 infolist.

## 8. Testing strategy

- `tests/Unit/CommissionServiceTest` — rule matching matrix (specificity,
  wildcards, fallback, duplicate sale protection).
- `tests/Feature/LeadCaptureApiTest` — webhook auth, validation, dedupe,
  re-engagement, embed form.
- `tests/Feature/LeadAssignmentTest` — single/even distribution incl. remainders.
- `tests/Feature/PanelAccessTest` — role ↔ panel matrix.

CI runs the suite on PHP 8.3 and 8.4 with an in-memory SQLite database;
migrations are provider-neutral (PostgreSQL and MySQL both covered).
