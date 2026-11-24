# Noon Gun

Every day at 12:00 a cannon on Signal Hill in Cape Town fires. Noon Gun does the
same for social channels: posts go out on schedule, from hardware you control.

Noon Gun is a self-hosted social publishing hub. It drafts posts from a content
brief, holds them for human approval, and publishes them on schedule. See
`docs/BRIEF.md` for scope and milestones, and `docs/adr/` for decisions.

Status: milestone M2 (skeleton). There is a login, a first-run setup and a
workspace, but no channels or posts yet.

## Getting started

You need Docker with the Compose plugin (v2.24 or newer) and bash. Nothing else
runs on the host.

```sh
git clone git@github.com:madsnorgaard/noongun.git
cd noongun
./setup
```

`./setup` builds the image, creates `.env`, installs dependencies, builds the
frontend, starts the containers, waits until they are healthy and runs the
migrations. The first run takes a few minutes. It is safe to run again at any
time, for example after pulling changes.

When it finishes, open <http://localhost:8080>. A fresh instance shows the setup
screen: create the owner account and the workspace (name, timezone, language).
You land on the dashboard, logged in. After that the setup screen is gone and
registration is closed.

If port 8080 is taken, choose another before the first run:

```sh
NOONGUN_HTTP_PORT=8081 ./setup
```

## Daily commands

```sh
./dev up           # start the stack
./dev down         # stop it (data is kept)
./dev logs app     # follow logs (app, horizon, scheduler, postgres, redis)
./dev shell        # shell in the app container

./dev test         # Pest
./dev lint         # Pint, check only
./dev fix          # Pint, apply fixes
./dev stan         # Larastan
./dev check        # lint, stan and test
./dev migrations   # migrations forwards, backwards and forwards (test database)

./dev artisan ...  # any artisan command
./dev composer ... # any composer command
./dev npm ...      # any npm command
./dev build        # build frontend assets once
./dev vite         # Vite dev server with hot reload
```

Run `./dev help` for the full list.

## How it runs

`docker-compose.yml` defines five services:

| Service | Purpose |
|---|---|
| `app` | FrankenPHP serving Laravel on container port 8080 |
| `horizon` | Queue workers (Laravel Horizon) |
| `scheduler` | Laravel scheduler (`schedule:work`) |
| `postgres` | PostgreSQL, only reachable inside the compose network |
| `redis` | Redis for cache, sessions and queues, only reachable inside the compose network |

`app`, `horizon` and `scheduler` use the same image; only the command differs.
`compose.override.yml` is picked up automatically in development: it builds the
`dev` image target and mounts the source code. To run the production image
without the override:

```sh
docker compose -f docker-compose.yml up -d --build
```

The health endpoint `/up` returns 200 only when PostgreSQL and Redis respond.

## Environment and secrets

`.env` is created from `.env.example` by `./setup` and is never committed. Setup
fills in:

- `APP_KEY` with `php artisan key:generate`
- `DB_PASSWORD` with a random value
- `NOONGUN_UID` and `NOONGUN_GID` with your user and group id, so files written
  by the containers belong to you

Keep `.env` private and back it up together with the database: without the
`APP_KEY` encrypted values (such as platform tokens, later) cannot be read.
Do not change `APP_KEY` on an instance that has data.

| Variable | Default | Meaning |
|---|---|---|
| `NOONGUN_MODE` | `self_hosted` | `self_hosted`: one workspace, created on first run, registration closed. `saas` is not built yet. |
| `NOONGUN_HTTP_PORT` | `8080` | Host port for the web app. Keep `APP_URL` in step. |
| `NOONGUN_VITE_PORT` | `5173` | Host port for `./dev vite`. |
| `NOONGUN_AUTO_MIGRATE` | `true` | Run migrations when the `app` container starts. |
| `NOONGUN_DEFAULT_TIMEZONE` | `Europe/Copenhagen` | Preselected timezone on the setup screen if the browser timezone is unknown. |
| `NOONGUN_UID`, `NOONGUN_GID` | `1000` | User and group the containers run as. |
| `APP_URL` | `http://localhost:8080` | Public address of the app. |
| `DB_*` | `pgsql`, host `postgres` | Database connection. The compose file uses the same values to create the database. |
| `REDIS_HOST` | `redis` | Redis host. |

Times are stored in UTC. Each workspace has its own timezone for display and
scheduling. The application timezone is fixed to UTC in `config/app.php` and
is not an environment setting.

Other settings (timezone list, locales, plan limits, feature flags) live in
`config/noongun.php`.

## Tests

The Pest suite runs against the `noongun_testing` database, which the
PostgreSQL container creates on first start. Tests never call external APIs.
GitHub Actions runs Pint, Larastan, the migration check and Pest on every pull
request (`.github/workflows/ci.yml`).

## Licence

AGPL-3.0. See `LICENSE`.
