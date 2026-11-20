# Installation Manual

This guide covers installing the CRM in three ways:

1. **Docker (recommended)** — the whole stack in one command.
2. **Manual PHP setup** — bare metal / VPS with PHP 8.3.
3. **PostgreSQL or MySQL** — both are supported, pick one.

---

## 1. Prerequisites

| Tool | Version | Notes |
| --- | --- | --- |
| Docker | 24+ | with the compose v2 plugin (`docker compose version`) |
| — or PHP | 8.2 / 8.3 / 8.4 | with `mbstring xml sqlite3 curl zip intl bcmath pdo_pgsql` |
| Composer | 2.x | only needed for the manual setup |
| Git | 2.x | to clone the repository |

---

## 2. Clone the repository

```bash
git clone https://github.com/masthisis/laravel-crm.git
cd laravel-crm
```

---

## 3. Docker installation (recommended)

### 3.1 Configure environment

```bash
cp .env.example .env
```

Key settings in `.env` (defaults work out of the box with `docker-compose.yml`):

```dotenv
APP_URL=http://localhost:8080
DB_CONNECTION=pgsql          # or "mysql"
DB_HOST=postgres             # service name inside docker network
DB_DATABASE=crm
DB_USERNAME=crm
DB_PASSWORD=secret
QUEUE_CONNECTION=database    # switch to "redis" once you scale
CACHE_STORE=database
```

### 3.2 Start the stack

```bash
make up
# or: docker compose up -d --build
```

This starts:

| Service | Purpose | URL / Port |
| --- | --- | --- |
| `app` | PHP-FPM (Laravel) | internal |
| `nginx` | web server | `http://localhost:8080` |
| `postgres` | PostgreSQL 16 | `localhost:5432` |
| `redis` | Redis 7 | internal |
| `mailpit` | local mail catcher | `http://localhost:8025` |

The entrypoint automatically waits for the DB, runs migrations and links storage on first boot.

### 3.3 Seed base data

```bash
make fresh
# or: docker compose exec app php artisan migrate:fresh --seed --force
```

This creates:

- roles & permissions (`super_admin`, `admin`, `sales_staff`),
- the default users (see table below),
- starter lead sources + capture integrations,
- starter commission rules.

**Default logins** (change the passwords immediately in production):

| Role | URL | Email | Password |
| --- | --- | --- | --- |
| Super admin | `/admin` | `admin@example.com` | `ChangeMe!2024` |
| Sales agent | `/agent` | `agent@example.com` | `ChangeMe!2024` |

### 3.4 Optional demo dataset

```bash
make seed-demo
# 24 leads (some won with commissions), 5 customers with orders/subscriptions
```

---

## 4. Manual installation (PHP on the host)

```bash
# 1. Install dependencies
composer install

# 2. Create your database (PostgreSQL example)
createdb crm

# 3. Configure
cp .env.example .env
# edit DB_HOST=127.0.0.1 and DB credentials
php artisan key:generate

# 4. Migrate + seed
php artisan migrate:fresh --seed

# 5. Run the app
php artisan serve                      # http://localhost:8000
# queue worker (recommended):
php artisan queue:work
# scheduler (in another terminal):
php artisan schedule:work
```

For production-grade manual hosting, mirror the nginx + PHP-FPM layout used in
`docker/Dockerfile` and `docker/nginx/default.conf`.

---

## 5. Choosing PostgreSQL vs MySQL

The application is database-agnostic. Switch with a single variable:

```dotenv
# PostgreSQL (default, used by docker-compose)
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432

# MySQL / MariaDB
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
```

If you use MySQL, replace the `postgres` service in `docker-compose.yml` with:

```yaml
  mysql:
    image: mysql:8.4
    environment:
      MYSQL_DATABASE: crm
      MYSQL_USER: crm
      MYSQL_PASSWORD: secret
      MYSQL_ROOT_PASSWORD: secret
    volumes:
      - mysqldata:/var/lib/mysql
```

---

## 6. First-run checklist

- [ ] Log in to `/admin` with the seeded admin account
- [ ] Change admin & agent passwords (Administration → Users)
- [ ] Create your real **lead sources** (Sources → Lead sources)
- [ ] Create a **capture integration** per source and copy the token
- [ ] Configure **commission rules** (Commissions → Commission rules)
- [ ] Invite your sales staff as users with the `sales_staff` role
- [ ] Set `APP_URL` correctly (used for embedded form links)
