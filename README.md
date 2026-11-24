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

## Running on a home server

`./setup` gives a development stack: it mounts the source code and runs in debug
mode. On a server, run the production image instead. The app container then holds
the code and the built assets, and nothing on the host needs PHP or Node.

The steps below put the app behind `tailscale serve`, which provides HTTPS on the
machine's tailnet name and keeps it off the public internet. Another TLS reverse
proxy works the same way.

1. Clone the repository and create `.env`:

   ```sh
   git clone git@github.com:madsnorgaard/noongun.git && cd noongun
   cp .env.example .env && chmod 600 .env
   ```

2. Set these values in `.env`:

   | Variable | Value |
   |---|---|
   | `COMPOSE_FILE` | `docker-compose.yml`, so the development override is not loaded |
   | `APP_ENV` | `production` |
   | `APP_DEBUG` | `false` |
   | `APP_URL` | The HTTPS address, for example `https://myserver.example.ts.net` |
   | `NOONGUN_HTTP_PORT` | `127.0.0.1:8090`, so only the proxy on the same machine reaches the app |
   | `NOONGUN_TRUSTED_PROXIES` | `172.16.0.0/12,192.168.0.0/16` (see below) |
   | `SESSION_SECURE_COOKIE` | `true` |
   | `LOG_LEVEL` | `info` |
   | `DB_PASSWORD` | A long random value |
   | `NOONGUN_UID`, `NOONGUN_GID` | Output of `id -u` and `id -g` |

3. Build the image, generate the application key and start the stack:

   ```sh
   docker compose build app
   docker compose run --rm --no-deps --entrypoint "" app php artisan key:generate --show
   ```

   Put the printed value in `.env` as `APP_KEY`, then:

   ```sh
   docker compose up -d --wait
   ```

4. Publish it on the tailnet over HTTPS:

   ```sh
   sudo tailscale serve --bg --https=443 http://127.0.0.1:8090
   ```

5. Open `APP_URL` and complete the setup screen.

`NOONGUN_TRUSTED_PROXIES` lists the proxies whose `X-Forwarded-*` headers the app
trusts. Without it the app sees plain HTTP behind the proxy and builds `http://`
links and redirects. A request from a proxy on the host arrives from the Docker
network gateway, which is why the value above names the private ranges Docker
uses. Leave it empty when no proxy is in front of the app.

To update, pull and rebuild. Migrations run when the `app` container starts.

```sh
git pull
docker compose up -d --build --wait
```

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
| `NOONGUN_HTTP_PORT` | `8080` | Host port for the web app. Keep `APP_URL` in step. Accepts an address too, for example `127.0.0.1:8090`. |
| `NOONGUN_VITE_PORT` | `5173` | Host port for `./dev vite`. |
| `NOONGUN_AUTO_MIGRATE` | `true` | Run migrations when the `app` container starts. |
| `NOONGUN_TRUSTED_PROXIES` | empty | Reverse proxies whose forwarded headers are trusted: comma separated addresses or CIDR ranges, or `*`. |
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
