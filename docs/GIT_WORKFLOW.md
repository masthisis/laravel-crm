# Git Workflow, Versioning & Releases

## 1. Branching model

```
main            stable, always releasable; only receives merges from develop
   │
develop         integration branch; feature branches merge here
   │
feature/*       one branch per feature/fix, branched off develop
hotfix/*        urgent fixes branched off main, merged back to main + develop
release/vX.Y.Z  optional: final stabilization before a release tag
```

Conventions:

- branch names: `feature/short-desc`, `fix/short-desc`, `chore/short-desc`, `hotfix/short-desc`
- keep branches short-lived; rebase onto `develop` when needed before merging
- merge with `--no-ff` so history keeps the feature grouping:

```bash
git checkout develop
git merge --no-ff feature/my-feature -m "merge: feature/my-feature into develop"
```

## 2. Commits — Conventional Commits

Format: `<type>(<optional scope>): <summary>`

| Type | Use for |
| --- | --- |
| `feat` | new feature (minor version bump) |
| `fix` | bug fix (patch version bump) |
| `chore` | tooling, dependencies, repo upkeep |
| `docs` | documentation only |
| `refactor` | code change that neither fixes nor adds |
| `test` | tests only |
| `ci` | pipeline changes |
| `perf` | performance |

Examples from this repo's history:

```
feat(commissions): specificity-based rule matching with wildcard fallback
feat(leads): CSV import page with source auto-creation and assignment strategies
fix(capture): idempotent response for duplicate webhook submissions
build(docker): multi-stage Dockerfile with dev/app/nginx targets
ci: GHCR image pipeline with branch, semver and sha tags
docs: installation and operations manuals
```

## 3. Versioning — SemVer

`vMAJOR.MINOR.PATCH` — MAJOR for breaking changes, MINOR for features,
PATCH for fixes. `composer.json` `config.platform.php` and CI matrix document
the supported PHP range; breaking changes to the commission engine or DB
schema must be called out in the release notes.

## 4. Release process

```bash
# 1. finish work on develop; CI green
git checkout main
git merge --no-ff develop -m "chore(release): merge develop for v1.x.y"

# 2. update CHANGELOG.md (Keep a Changelog format)

# 3. tag and push
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin main develop vX.Y.Z
```

Automation that fires:

| Tag/branch | Workflow | Result |
| --- | --- | --- |
| push to `develop`/`main`/tag | `Build & Push Image` | image `ghcr.io/masthisis/laravel-crm` tagged `branch` / `vX.Y` / `vX.Y.Z` / `sha` |
| tag `v*` | `Release` | GitHub release with generated notes |
| push `develop` | `Deploy (staging)` | SSH deploy + migrate (if enabled) |
| release published | `Deploy (production)` | SSH deploy + migrate (if enabled) |

## 5. The issue-driven loop (agent + owner)

Work moves through GitHub in one direction only: **issue → branch → PR →
merge → release** — whoever starts it.

### 5.1 Forward flow — agent turns a request into an issue

```
request ──► issue (goal + investigation + plan + acceptance criteria)
        ──► branch <type>/issue-<N>-<slug> off develop
        ──► conventional commits referencing #N
        ──► PR (base develop, body contains "Fixes #N")
        ──► CI green ──► merge commit ──► issue auto-closed
        ──► release PR develop → main ──► tag vX.Y.Z ──► GitHub Release
```

### 5.2 Reverse flow — the owner files an issue and the agent fetches it

1. Open an issue with the **Agent Task** template
   (`.github/ISSUE_TEMPLATE/agent-task.yml`): a *Prompt / instruction*,
   context/constraints and acceptance criteria. It is auto-labelled
   `agent/ready` + `status/triage`.
2. Tell the agent: *"fetch issue #N and work on it"* (add *"and release"*
   if you want the release train run afterwards).
3. The agent: investigates the codebase, comments its plan on the issue,
   creates the branch, implements, runs local gates, opens the PR, waits
   for CI, merges, and optionally cuts the release. The issue comment trail
   becomes the investigation record.

### 5.3 Branch naming and labels

| Item | Convention |
| --- | --- |
| Branches | `feature/issue-12-queued-import`, `fix/issue-9-phpstan-quality`, `docs/issue-10-agent-workflow`, `chore/…`, `hotfix/…` |
| Type labels | `type/feature` `type/bug` `type/chore` `type/docs` `type/release` |
| Status labels | `status/triage` → `status/in-progress` → `status/in-review` → closed |
| Priority | `priority/P0` (now) `priority/P1` (next milestone) `priority/P2` (backlog) |
| Area | `area/backend` `area/frontend` `area/infra` `area/quality` `area/docs` |
| Agent hand-off | `agent/ready` = fully specified, safe to fetch and execute unattended |

### 5.4 Release train

1. All milestone issues merged into `develop`, CI green.
2. Update `CHANGELOG.md` on a `chore/release-…` branch → PR → merge.
3. Release PR: `develop` → `main`, title `chore(release): vX.Y.Z`.
4. Merge, then tag `vX.Y.Z` on `main` and publish the GitHub Release
   (generated notes + image tags fire automatically, see §4).

## 6. Recommended repository settings

- Protect `main`: require PRs, passing `CI`, no force push; allow only release merges.
- Protect `develop`: require `CI` to pass; allow direct pushes from trusted maintainers.
- Enable Dependabot auto-merge for `minor-and-patch` groups after CI passes.
- `Settings → Environments → production`: require reviewer approval before
  the production deploy job runs.

## 7. Hotfixes

```bash
git checkout main
git checkout -b hotfix/vX.Y.Z-fix-description
# ...fix + commit...
git checkout main && git merge --no-ff hotfix/...
git tag -a vX.Y.(Z+1) && git push origin main vX.Y.(Z+1)
# port the fix back:
git checkout develop && git merge --no-ff hotfix/...
```

## 8. Local setup of the remote

```bash
git remote add origin https://github.com/masthisis/laravel-crm.git
git fetch origin
# pushes use your own credentials (gh CLI, SSH key or a fine-grained PAT).
```
