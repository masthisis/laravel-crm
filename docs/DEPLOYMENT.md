# Deployment Guide

The CRM ships with three compose files and a CI/CD pipeline that builds one
image and promotes it across environments.

| Environment | Compose file | Image tag | Migrations |
| --- | --- | --- | --- |
| Local | `docker-compose.yml` | built locally (`dev` target) | automatic |
| Staging | `docker-compose.staging.yml` | `:staging` (from `develop`) | automatic |
| Production | `docker-compose.production.yml` | release tag / `:latest` | explicit step |

---

## 1. How the image works

`docker/Dockerfile` has three stages:

- `dev` — full dependencies, bind-mounted code, auto composer install;
- `app` — production PHP-FPM with optimized autoloader (pushed to GHCR by CI);
- `nginx` — nginx with the baked `public/` assets.

The `app` image also runs **workers** and the **scheduler** via the
`CONTAINER_ROLE` environment variable (`app` | `queue` | `scheduler`) — one
artifact, three roles.

Image name: `ghcr.io/masthisis/laravel-crm`
(see `.github/workflows/build.yml`).

---

## 2. Staging deployment (auto on `develop`)

### 2.1 Server preparation (one time)

```bash
# on the staging host
git clone https://github.com/masthisis/laravel-crm.git /srv/crm-staging
cd /srv/crm-staging

cp .env.example .env.staging        # then edit:
#   APP_ENV=staging, APP_URL=https://staging.example.com
#   DB_* (staging credentials), RUN_MIGRATIONS not needed (compose sets it)
```

### 2.2 GitHub configuration (one time)

| Item | Value |
| --- | --- |
| Repository **variable** | `STAGING_DEPLOY_ENABLED=true` |
| Repository **variable** | `STAGING_PATH=/srv/crm-staging` |
| Repository **secrets** | `STAGING_HOST`, `STAGING_USER`, `STAGING_SSH_KEY` |

Every push to `develop` then: CI runs → image `:staging` pushed to GHCR →
workflow runs `git fetch && docker compose pull && up -d && migrate --force`
on the server (over SSH).

Manual deploy:

```bash
make staging-up && make staging-migrate
```

---

## 3. Production deployment (releases)

1. Merge `develop` → `main` (see `docs/GIT_WORKFLOW.md`).
2. Tag: `git tag -a v1.1.0 -m "..." && git push origin main v1.1.0`.
3. The `Release` workflow creates the GitHub release; `Build & Push Image`
   pushes the `v1.1.0`, `v1.1`, `latest` and SHA images to GHCR.
4. If `PROD_DEPLOY_ENABLED=true` (+ `PROD_HOST`, `PROD_USER`, `PROD_SSH_KEY`,
   `PROD_PATH` variables/secrets), the `Deploy (production)` workflow runs
   the same pull-up-migrate sequence against the release tag.

Manual production deploy:

```bash
APP_IMAGE=ghcr.io/masthisis/laravel-crm:v1.1.0 make prod-up
make prod-migrate
```

Migrations in production are **explicit** (`RUN_MIGRATIONS=false` in the
production compose) so they run exactly once per deploy, after new code is up.

### 3.1 Production checklist

- [ ] `APP_DEBUG=false`, real `APP_KEY`, correct `APP_URL`
- [ ] Strong DB credentials; Postgres not exposed publicly (remove the port mapping)
- [ ] Redis in use (`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=database`)
- [ ] HTTPS terminated in front of nginx (reverse proxy / LB)
- [ ] Default admin/agent passwords changed
- [ ] Backups: `production_pgdata` volume + `storage/` uploads
- [ ] Monitoring on `http://<host>/up`

---

## 4. PaaS deployment

The `app` image is a standard PHP-FPM container, so PaaS options are:

### Railway / Fly.io / Render (single container)

Add a PaaS-specific Dockerfile stage using FrankenPHP (Caddy + PHP in one
container), or run two services (app + nginx) and let nginx `fastcgi_pass`
to the app service — identical to `docker-compose.staging.yml`.

Minimal single-container variant (`docker/Dockerfile.paas`, optional):

```dockerfile
FROM dunglas/frankenphp:1-php8.3-alpine AS paas
WORKDIR /app
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction || \
    composer update --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 8080
ENTRYPOINT ["./docker/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/app/docker/frankenphp.Caddyfile"]
```

Common commands (all PaaSes):

| Purpose | Command |
| --- | --- |
| Release command | `php artisan migrate --force` |
| Worker process | same image, `CONTAINER_ROLE=queue` |
| Scheduler | same image, `CONTAINER_ROLE=scheduler` |

### Heroku (classic)

Use the `php` buildpack with `heroku-php-nginx` or `heroku-php-apache2`
document root `public/`; release phase: `php artisan migrate --force`.

---

## 5. CI/CD pipeline map

```
push/PR → ci.yml            (tests 8.3+8.4, Pint, PHPStan)
push main/develop/tag
        └→ build.yml        (build & push image to GHCR; tags: branch / semver / sha)
push develop
        └→ deploy-staging   (workflow_run after build; SSH deploy + migrate)
tag v* (release published)
        └→ deploy-production (SSH deploy + migrate from release tag)
        └→ release.yml       (GitHub release with generated notes)
dependabot.yml               (weekly composer/actions/docker updates)
```

Required secrets/variables are listed in §2.2 and §3. Without SSH secrets the
deploy steps are skipped (`continue-on-error`) — images are still published, so
any server can pull manually.
