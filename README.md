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

## Publishing

Nothing is published without a human approval. A post goes
`draft -> approved -> scheduled -> publishing -> published` (or `failed`), and
only a signed-in owner or editor can approve it. See
`docs/adr/0009-post-state-machine.md`.

The `scheduler` container runs two commands:

| Command | Runs | What it does |
|---|---|---|
| `noongun:dispatch-due-posts` | every minute | Finds scheduled posts whose time has come, claims each one and queues a `PublishPostJob` for it. A post is claimed once, however often the command runs. |
| `noongun:recover-stuck-posts` | every five minutes | Finds posts that have been in the publishing state with no sign of life from their job, and settles them: published if every variant is out, otherwise failed. It never sends anything. |

Both can be run by hand, for example `./dev artisan noongun:dispatch-due-posts`.

`PublishPostJob` runs in the `horizon` container. It sends each variant of
the post once, under a lock for that variant, and never sends a variant that
already has an id from its platform. It tries again only when the platform
answers with an error that can pass (rate limit, outage), after the wait set
in `NOONGUN_PUBLISH_BACKOFF`. Any other error fails the post at once. A failed
post is only sent again when a person chooses to retry it, and then only the
variants that are still missing are sent.

Two things to know when operating it:

- The cache store holds the publishing locks, so it must be shared by all
  workers. Keep `CACHE_STORE=redis` (or `database`); `array` and `file` are
  not safe with more than one worker.
- A publishing job is never run twice by the queue. Keep
  `REDIS_QUEUE_RETRY_AFTER` (90 seconds by default) above the time limit of a
  job, or the queue reports long jobs as failed while they are still running.
  The post itself is not affected.
- If a worker dies in the middle of a platform call, the post may be on the
  platform without the app knowing. Such a post is marked as failed with the
  text "Publishing was interrupted before a result was recorded". Look at the
  channel before you retry it, or the post can appear twice.

### Queue, calendar and editor

- **Queue** (`/queue`) is the working list: drafts that wait for approval,
  then approved and scheduled posts in time order, then posts that failed.
  A draft can be approved or rejected right there, one at a time or as a
  selection that you tick yourself. Nothing is ever ticked for you. Published
  and rejected posts are under History.
- **Calendar** (`/calendar`) shows a week or a month in the timezone of the
  workspace. A draft or an approved post with a planned time is drawn with a
  dashed outline: it is a plan and does not go out until it is scheduled.
- **Editor** (`/posts/{id}`) is where a post is written: one text per channel,
  an optional link and an optional planned time. It shows the characters that
  are left for each channel and what stands in the way of approval.

Times are entered and shown on the clock of the workspace and stored in UTC.

A post can only be edited while it is a draft. To change an approved or
scheduled post, return it to draft. That removes the approval, and the post
must be approved again. Approving is refused when the post was changed after
the page was opened, so that nobody approves a text they have not read.

Only drafts and rejected posts can be deleted, and only when none of their
texts is on a platform. Everything that is approved or later is kept, because
it is, or is about to become, the record of something public. Deleting a post
in Noon Gun never deletes anything from a platform. See
`docs/adr/0010-post-editing-and-deletion.md`.

Owners and editors write, approve, schedule and delete. Viewers can look.

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
| `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET` | empty | Credentials of the Meta app this instance connects Pages with. |
| `FACEBOOK_GRAPH_VERSION` | `v27.0` | Graph API version used for every Facebook call. |
| `FACEBOOK_SCOPES` | `pages_show_list,pages_read_engagement,pages_manage_posts` | Permissions requested when a Page is connected, comma separated. |
| `FACEBOOK_GRAPH_URL`, `FACEBOOK_DIALOG_URL` | `https://graph.facebook.com`, `https://www.facebook.com` | Base addresses of the Graph API and the login dialog. Change only for testing. |
| `NOONGUN_PUBLISH_MAX_ATTEMPTS` | `5` | How many times one variant is sent before the post is marked as failed. Only retryable errors are tried again. |
| `NOONGUN_PUBLISH_BACKOFF` | `60,300,900,3600` | Seconds to wait before each retry, comma separated. The last value repeats. The largest value is also the longest wait a platform may ask for; a longer one fails the post. |
| `NOONGUN_PUBLISH_TIMEOUT` | `30` | Seconds allowed for one platform call. The time limit of a publishing job is this value for each variant it sends, plus 15 seconds. |
| `NOONGUN_PUBLISH_LOCK_SECONDS` | `120` | Shortest lifetime of the lock on one variant. Must be longer than the timeout. The lock always outlives the time limit of the job that holds it. |
| `NOONGUN_PUBLISH_STUCK_AFTER_MINUTES` | `30` | How long a post may stay in the publishing state without a sign of life from its job before it is treated as failed. The largest backoff value is added to this, because a job that waits for a retry is silent. |
| `NOONGUN_PUBLISH_QUEUE` | `default` | Queue that publishing jobs run on. If you change it, add the queue to the supervisor in `config/horizon.php`. |

Times are stored in UTC. Each workspace has its own timezone for display and
scheduling. The application timezone is fixed to UTC in `config/app.php` and
is not an environment setting.

Other settings (timezone list, locales, plan limits, feature flags, the list
of platforms and their adapters) live in `config/noongun.php`.

## Connecting a Facebook Page

Each instance connects through a Meta app that its owner creates. The app
needs no review as long as only people with a role on it use it.
`docs/platform-notes/meta.md` has the full checklist for creating the app; the
short version:

1. Create the app at developers.facebook.com with the use case **Manage
   everything on your Page** and add the permissions listed in
   `FACEBOOK_SCOPES`.
2. In the app, under **Facebook Login for Business > Settings** (or **Facebook
   Login > Settings**), add this address to **Valid OAuth Redirect URIs**:

   ```
   <APP_URL>/auth/facebook/callback
   ```

   Meta compares the address character by character, so it must match
   `APP_URL` exactly, including `https` and any port. The Channels page shows
   the address this instance uses. If `APP_URL` changes, change it in the app
   as well. Meta requires HTTPS; plain `http://localhost` is accepted only
   while the app is in Development mode.
3. Put the App ID and App secret in `.env` as `FACEBOOK_APP_ID` and
   `FACEBOOK_APP_SECRET`, then restart with `docker compose up -d`.
4. Open **Channels** in Noon Gun and choose **Connect Facebook**. Sign in,
   pick the Pages and allow every permission that is asked for.

What happens when Facebook sends you back:

- A single Page that is new to the workspace is connected at once.
- A Page that is already connected gets a fresh token and keeps publishing
  without a pause. Nothing is asked.
- Every other Page is shown for you to tick: several new Pages, or a Page you
  disconnected earlier, which Facebook can return again because it still
  remembers the grant. Nothing is ticked for you. Until you choose, such a
  Page is listed as **Not confirmed** and nothing is published to it.
- If you choose **Connect none of them**, leave the page or wait longer than
  15 minutes, the Pages you did not confirm are removed again and their access
  is erased. A Page you had disconnected stays disconnected. The scheduled
  command `noongun:discard-pending-channels` does this every five minutes, so
  it also happens when nobody opens Noon Gun.

Noon Gun stores one access token for each connected Page, encrypted with
`APP_KEY`. It does not store your own Facebook token. A Page token has no
expiry date, but Facebook withdraws it when you change your password, lose
your role on the Page or remove the app. The channel then shows **Needs
reconnecting**; choose **Connect again**. **Disconnect** erases the token and
keeps the history of what was published.

Owners and editors of a workspace can connect and disconnect channels. Viewers
can only see them.

## Tests

The Pest suite runs against the `noongun_testing` database, which the
PostgreSQL container creates on first start. Tests never call external APIs.
GitHub Actions runs Pint, Larastan, the migration check and Pest on every pull
request (`.github/workflows/ci.yml`).

## Licence

AGPL-3.0. See `LICENSE`.
