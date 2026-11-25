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
already has an id from its platform. It tries again by itself only when the
platform certainly made no post: it refused the call because of a rate limit,
or the connection to it was never made. It then waits as set in
`NOONGUN_PUBLISH_BACKOFF`. Every other error fails the post at once,
including a timeout or a server error after the request was sent, because
the post may be public by then. A failed post is only sent again when a
person chooses to retry it, and then only the variants that are still missing
are sent.

Four things to know when operating it:

- **Never retry a `PublishPostJob` by hand** with `queue:retry` or the retry
  button in Horizon. A job that failed may have reached the platform, and the
  way to send a post again is the retry button on the post, which a person
  presses after looking at the channel. If someone does retry the job, nothing
  happens: every run of the job takes a numbered step on the post row in the
  database, each step is given out once, and a job whose step is taken does
  nothing. Failed publishing jobs can be removed with `queue:flush` or
  `queue:forget`.
- The cache store holds the publishing locks, so it must be shared by all
  workers. Keep `CACHE_STORE=redis` (or `database`). With `array`, `file` or
  `null` the scheduler commands and the job refuse to run and say why, and
  nothing is published until the store is changed.
- `REDIS_QUEUE_RETRY_AFTER` (and `DB_QUEUE_RETRY_AFTER` for the database
  queue) must be greater than the longest time limit of a publishing job,
  which is `NOONGUN_PUBLISH_TIMEOUT` x `NOONGUN_PUBLISH_CALLS_PER_JOB` + 15
  seconds, and of a drafting job (see "Proposing a week"). The defaults are
  300 against 105 for publishing and 255 for drafting. If it is lower, the
  queue hands a job that is still running to a second worker; that copy does
  nothing, but it is listed as a failed job.
- If a worker dies in the middle of a platform call, or the platform does
  not answer, the post may be public without the app knowing. The variant is
  marked for that before the call is made, and the post ends as failed with
  a text that says to check the channel. Look at the channel before you retry
  the post, or it can appear twice. Such a post cannot be deleted, and the
  channel in doubt cannot be removed from it.

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

## Brief, pillars and slots

Posts are drafted from what a workspace keeps on these pages.

- **Brief** (`/brief`) holds the title, the brief itself, the voice rules and
  the calls to action. The brief is plain text or markdown and is kept exactly
  as it is typed. Voice rules are lists: one for every channel and one for each
  platform. A new workspace is offered a set of house rules to start from.
  They are put in the form and are not saved until the brief is saved.
- Saving the brief always adds a version. Earlier versions are listed, can be
  read, and are never changed. There is no restore: to go back to an earlier
  version, copy its text into the brief and save it as a new version.
- **Pillars** (`/brief/pillars`) are the themes of the workspace, each with a
  number of posts per week. Deleting a pillar keeps its ideas and posts and
  leaves them without a pillar. The page says how many before it happens.
- **Slots** (`/brief/slots`) are the preferred posting times of a channel: a
  weekday and a time on the clock of the workspace. A slot stays at the same
  time of day when the clocks change. It proposes a planned time for a draft
  and schedules nothing. Channels that are disconnected or not yet confirmed
  cannot be given slots.
- **Ideas** (`/ideas`) is a list of topics: a title, and optionally a pillar,
  a source and notes. The source is stored as an address and is given to a
  draft as text. Noon Gun never opens it.

Do not put passwords or private data about other people in the brief, an idea
or a pillar. This text is what is sent to the drafting service.

Owners and editors change these pages. Viewers can read them. See
`docs/adr/0011-drafting-contract.md`.

## Drafting with an LLM

Noon Gun can write drafts from the brief of a workspace with a language model.
A generated post is a draft like one you typed: nothing is approved, scheduled
or published until a person does so. Drafting is optional. Without an API key
the rest of the application works as before.

### The provider

Two providers are built in: Anthropic (`anthropic`) and OpenAI (`openai`).
`NOONGUN_DRAFT_PROVIDER` names the one a workspace drafts with until its owner
chooses another, and is `anthropic` unless you change it.

An owner can choose another provider under Settings, Drafting, but only among
those the operator offers. `NOONGUN_DRAFT_PROVIDERS` lists them, comma
separated; the default provider is always offered, and with the variable empty
it is the only one. To offer both:

```
NOONGUN_DRAFT_PROVIDERS=anthropic,openai
```

Offering a provider means that the brief, the voice rules, the pillar and the
idea of a workspace that chooses it are sent to that company. Decide that as
the operator before you list it. When a provider is taken off the list,
workspaces that chose it stop drafting until an owner chooses again; they are
not moved to the default provider, because their content would then go to a
company they did not choose.

Changing the provider opens no new allowance: the daily limits count draft
runs whatever provider wrote them. A draft that was asked for before the
change is not written with the new provider; it fails and says so, and
nothing is spent.

### The key

Drafting needs an API key of the provider in use. Each provider has its own
keys, and a key is only ever used with the provider it was stored for. There
are two places for a key:

- **The instance key** is set by the operator in `.env`: `ANTHROPIC_API_KEY`
  for Anthropic, `OPENAI_API_KEY` for OpenAI. Every workspace that has no key
  of its own for that provider uses it, and the operator's account is billed.
- **A workspace key** is stored by an owner of the workspace under Settings,
  Drafting, for one provider. It goes before the instance key of that
  provider, and the account it belongs to is billed. Removing it puts the
  workspace back on the instance key of that provider. A workspace can hold a
  key for each provider.

The key form refuses a key that looks like the key of another provider (an
Anthropic key pasted under OpenAI, or the reverse), so that it is not sent to
the wrong company.

A workspace key is stored encrypted with `APP_KEY` and is write-only: after it
is saved, the page shows the provider, the last four characters, when it was
stored and whose key drafting uses, and never the key. Editors and viewers see
whether drafting is available and nothing about keys. Storing, replacing
and removing the key needs a recently confirmed password, like the security
settings, so a session that someone else got hold of cannot swap the key. Each
draft run records whether the instance key or the workspace key paid for it.

To create an Anthropic key, sign in to the Claude Console at
`platform.claude.com`, add credit to the account and create an API key. To
create an OpenAI key, sign in at `platform.openai.com`, add credit and create
a key for a project. Set a spending limit for the account or the project
there: Noon Gun estimates cost, it does not cap what the provider charges.

### Settings

| Variable | Default | Meaning |
|---|---|---|
| `ANTHROPIC_API_KEY` | empty | The instance key for Anthropic. |
| `OPENAI_API_KEY` | empty | The instance key for OpenAI. |
| `NOONGUN_DRAFT_PROVIDER` | `anthropic` | The default provider. Must be listed under `drafting.providers` in `config/noongun.php`. |
| `NOONGUN_DRAFT_PROVIDERS` | empty | The providers an owner may choose from, comma separated. The default provider is always one of them. |
| `NOONGUN_DRAFT_ANTHROPIC_MODEL` | `claude-opus-5-5` | The Anthropic model. |
| `NOONGUN_DRAFT_ANTHROPIC_EFFORT` | empty | How much work the model puts into one answer: `low`, `medium`, `high`, `xhigh` or `max`. Empty leaves it to the default of the model. |
| `NOONGUN_DRAFT_OPENAI_MODEL` | `gpt-6.2-sol` | The OpenAI model. |
| `NOONGUN_DRAFT_OPENAI_EFFORT` | empty | The reasoning effort for OpenAI: `minimal`, `low`, `medium`, `high`, `xhigh` or `max`, as far as the model accepts it. Empty leaves it to the default of the model. |
| `NOONGUN_DRAFT_MAX_TOKENS` | `16000` | Most tokens one answer may use, including the tokens the model spends on thinking. |
| `NOONGUN_DRAFT_TIMEOUT` | `120` | Seconds allowed for one call. |
| `NOONGUN_DRAFT_PROMPT_VERSION` | `v1` | The folder under `resources/prompts/draft/` that holds the prompt. |
| `NOONGUN_DRAFT_PRICES`, `NOONGUN_DRAFT_PRICE_CURRENCY` | empty, `USD` | The price table for the cost estimate. |

### What is sent to the provider

One request is sent for each post. It holds the prompt and the format the
answer must have, and nothing else: no tools, no files, no earlier
conversation. The prompt is built in one place
(`App\Domain\Drafting\PromptRenderer`) from:

- the title and text of the brief, its voice rules and its calls to action;
- the name and description of the pillar, and the title and notes of the idea,
  when the draft is asked for from one;
- a source address, when one is given. The address is sent as text. Noon Gun
  does not open it, and neither does the model;
- for each target channel its number, its platform and the limits of that
  platform, such as the longest text.

Never sent: platform tokens, API keys, the names of your Pages and profiles,
the name of the workspace, names or email addresses of users, and anything
that belongs to another workspace. The API
key travels in a request header to the provider and nowhere else. It is not
written to logs, error messages, the queue or the draft run.

What the provider does with a request is set by its own terms. Do not put into
the brief what you would not send to it.

### What comes back

The model answers in a fixed format: one text for each channel, at most one
link, a suggestion for alternative text and one line on why the post fits. The
answer is treated as data. It is checked (a text for each channel that was
asked for and for no other, no text over the limit of its platform), stored as
plain text and shown as plain text. Nothing in it is followed, opened or run.

A draft only ever carries a link that you gave: the source address of the
request, or the address of a call to action in the brief. If the model
suggests any other address, the draft is created without a link and the draft
run records that a link was left out. Text that is pasted into an idea can
therefore not make a post point somewhere you did not choose. Add the link by
hand in the editor when you do want another one.

When an answer does not pass the check, the model is asked once more. After a
second answer that cannot be used the run fails and says why. A run also fails,
without a second request, when the model declines to write the post or the
answer is cut off at `NOONGUN_DRAFT_MAX_TOKENS`. A rate limit, an overloaded
provider, a provider error and a timeout are reported as failures that can be
tried again.

### Cost

Each draft run stores the tokens the provider reported and a cost estimate:
tokens times the price per million tokens in `drafting.prices` of
`config/noongun.php`, in `NOONGUN_DRAFT_PRICE_CURRENCY`. A run that needed a
second request counts both. A failed run keeps the tokens it used.

The price is that of the provider and model the run used, and both are stored
on the run, so the estimate of an old run stays what it was when the workspace
changes its provider or the operator changes a model. Input that a provider
read from or wrote to its prompt cache is priced with `cached_input` and
`cache_write` of the model when the table has them (OpenAI caches long prompts
by itself), and as plain input otherwise. The worst case shown before a
proposal counts all input at the dearest of these prices.

It is an estimate, not an invoice. It leaves out anything the provider bills
that is not an input or output token, and it is as good as the price table.
The built-in prices were checked against the price lists of Anthropic and
OpenAI on 2026-11-25.
Prices change: check them when you change the model, and add or replace
entries with `NOONGUN_DRAFT_PRICES`. A model without a price gives runs with
tokens and no estimate.

### Changing the model

Set `NOONGUN_DRAFT_ANTHROPIC_MODEL` to the identifier of another Claude model
and restart the containers. If the model is not in the price table, add its
price with `NOONGUN_DRAFT_PRICES`:

```
NOONGUN_DRAFT_ANTHROPIC_MODEL=claude-sonnet-5-5
NOONGUN_DRAFT_PRICES={"anthropic":{"claude-sonnet-5-5":{"input":2,"output":10}}}
```

For OpenAI the variables are `NOONGUN_DRAFT_OPENAI_MODEL` and
`NOONGUN_DRAFT_OPENAI_EFFORT`, and the prices go under `"openai"`, with
`cached_input` and `cache_write` when you know them. OpenAI recommends room
for 25,000 tokens of reasoning and answer when you start with a reasoning
model; with the default of 16,000 in `NOONGUN_DRAFT_MAX_TOKENS`, set a low
effort or raise the limit if answers are cut off.

The model must support structured outputs. A smaller model is cheaper and
faster and writes plainer drafts. Thinking counts against
`NOONGUN_DRAFT_MAX_TOKENS`, so if answers are cut off, raise that limit or set
a lower `NOONGUN_DRAFT_ANTHROPIC_EFFORT`. To change what the model is told,
copy `resources/prompts/draft/v1` to a new folder, edit it and point
`NOONGUN_DRAFT_PROMPT_VERSION` at it. Details are in
`docs/platform-notes/llm.md` and `docs/adr/0011-drafting-contract.md`.

## Proposing a week

"Propose a week" on the queue drafts the posts of one week from the brief,
the pillars and the slots. It needs a brief, a channel that can publish with
at least one slot, and an API key for the LLM provider. When one is missing,
the page says which and links to where it is set.

What it makes are drafts. Each one arrives in the queue like a post written
by hand, with its slot time as planned time, and is approved or rejected by a
person, one post at a time. There is no "approve all", and nothing a proposal
creates is ever published by itself. See `docs/adr/0012-proposing-a-week.md`.

Before anything starts, the page shows the week, every post that would be
drafted with its time, channels, pillar and idea, the model, and what it is
expected to cost. The plan is worked out by these rules, in this order:

1. Every slot of a channel that can publish gives one time in the week, on
   the clock of the workspace. A time is left out when it has passed, when it
   is less than `NOONGUN_DRAFT_LEAD_MINUTES` away (a draft could not be read
   and approved before then), or when that channel already has a post at that
   time that is not rejected. A slot that an earlier proposal for the week
   already drafted a post for is left out as well, also when that post was
   moved to another time since. Proposing a week twice therefore fills the
   gaps and repeats nothing. Rejecting or deleting a draft opens its slot
   again.
2. Channels whose slots fall on the same time share one post, with a text for
   each channel, because a post is what you approve.
3. Each pillar needs its weekly target, minus the posts the week already holds
   for it. The pillars take turns, the largest target first and then by name,
   one post per turn, until every need is met. That sequence is laid over the
   posts in time order. If the targets ask for more posts than the week has
   times, the end of the sequence is dropped and the page says which pillars
   come up short. If they ask for fewer, the remaining posts are drafted from
   the brief alone, without a pillar. When no pillar has a target, every post
   is, and the page says so.
4. A post takes the oldest unused idea of its pillar, then the oldest unused
   idea without a pillar. An idea counts as used while a post that is not
   rejected was drafted from it; rejecting that post frees the idea. An idea
   of another pillar is never borrowed.

The same brief, pillars, slots and posts always give the same plan. If the
plan changes between looking at it and starting it, the start is refused and
the new plan is shown.

Starting opens one draft run for each post and queues one job for each run.
The queue page shows every run as waiting, being drafted, drafted or failed,
and asks the server for that every five seconds until all are settled. A run
that failed says why in plain words; what the person who runs the instance
needs to know beyond that is in the log.

A run is opened for one provider, model and prompt version, and for one payer:
the key of the workspace or the key of the instance. If any of these is
different when its job runs, because a key was added or removed or the model
was changed in between, the run fails with that reason and nothing is spent.

### What a proposal may spend

- One proposal drafts at most `NOONGUN_DRAFT_MAX_POSTS_PER_PROPOSAL` posts.
  The earliest times are kept; the rest are left for a second proposal.
- A workspace drafts at most `NOONGUN_DRAFT_MAX_RUNS_PER_DAY` posts in 24
  hours. A run counts when it reached the provider, whether it succeeded or
  failed, or is still waiting or at work. A run that spent nothing does not
  count: one that could not be queued, one no worker picked up, one the
  provider turned away for a wrong key or an unknown model.
- All workspaces together draft at most
  `NOONGUN_DRAFT_MAX_INSTANCE_RUNS_PER_DAY` posts in 24 hours on the key of the
  instance, counted the same way. A workspace with a key of its own is not
  held by this limit.
- A workspace has one proposal at work at a time. A second one is refused
  until every run of the first is settled, also when two people press the
  button at the same moment and while a run waits for its retry.
- Starting and stopping are each throttled to six requests a minute for a
  user. A request over that gets a sentence on the page.
- A post is asked for at most `NOONGUN_DRAFT_MAX_ATTEMPTS` times, and a second
  time only when the provider failed in a way worth trying again (a rate
  limit, an overloaded provider, a timeout), after the wait in
  `NOONGUN_DRAFT_BACKOFF`. An answer that cannot be used is asked for once
  more by the generator and then fails the run. Each attempt is a draft run of
  its own, so that what every attempt used stays on record.

### What it costs

Before the start the page shows two figures from the price table:

- **Expected, about**: one answer for each post. The prompt each post would
  be sent is rendered and measured, at one token for three bytes, and the
  answer is taken as `NOONGUN_DRAFT_EXPECTED_OUTPUT_TOKENS` tokens.
- **Worst case**: every post uses every attempt, every attempt asks twice,
  and every answer runs to `NOONGUN_DRAFT_MAX_TOKENS`. The prompt is counted
  at one token for every byte, plus an allowance for what the provider wraps
  around it. A token is never shorter than a byte, so a proposal cannot cost
  more than this, however long the brief is.

While a proposal is at work and after it, the queue shows what its runs used
so far, failed attempts included. The editor shows on a drafted post what
that one draft used. All of these are estimates from the price table, not an
invoice, and without a price for the model only tokens are shown.

### When drafts do not arrive

The `scheduler` container runs one command for drafting:

| Command | Runs | What it does |
|---|---|---|
| `noongun:recover-stuck-draft-runs` | every five minutes | Marks a draft run as failed when it has been running for longer than `NOONGUN_DRAFT_STUCK_AFTER_MINUTES` (its worker died), or has been waiting longer than that plus the largest backoff (its job was lost). It never asks a provider again: a run whose worker died may already have been paid for, so a person decides whether to propose the week again. |

With the defaults a run is given up after 6 minutes at work or 8 minutes of
waiting, found at the next run of the command. Until then the workspace
cannot start another proposal.

The queue page does not wait for that in silence. Once a draft has waited two
minutes for a worker, it says "Waiting longer than expected. Check that the
queue is running." and offers "Stop waiting" to owners and editors. Stopping
marks every run of the workspace that is still open as failed, after a
confirmation. It asks nothing of the provider, and the week can be proposed
again at once. It also works when the scheduler is down. A draft that is with
the provider at that moment is discarded and may still be charged for; its
tokens are not recorded. A job that arrives later for a stopped run does
nothing.

Three things to know when operating it:

- `GenerateDraftJob` runs in the `horizon` container on the default queue.
  Running it again by hand, with `queue:retry` or the retry button in Horizon,
  does nothing: a draft run is started once, by one conditional update in the
  database.
- The time limit of the job is `NOONGUN_DRAFT_TIMEOUT` x 2 + 15 seconds, which
  is 255 with the defaults. `REDIS_QUEUE_RETRY_AFTER` (and
  `DB_QUEUE_RETRY_AFTER` for the database queue) must stay above it; the
  default is 300. If you raise `NOONGUN_DRAFT_TIMEOUT`, raise those too.
  When a draft takes longer than `REDIS_QUEUE_RETRY_AFTER`, the queue hands
  the job to a second worker; that copy does nothing, but it is listed as a
  failed job.
- Every failed run is logged as "A draft run failed." with the class of the
  failure, its message and, where the provider gave one, a detail for the
  operator. Keys are removed before anything is logged. When the page says
  that drafting is not set up on this instance, the log line "Drafting is not
  set up, so a week cannot be proposed." names the setting that is missing.

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
`APP_KEY` encrypted values (platform tokens and LLM keys) cannot be read.
Do not change `APP_KEY` on an instance that has data. If it was changed, a
stored LLM key can no longer be read: drafting stops for that workspace, the
Drafting settings page says so, and an owner enters the key again or removes
it there.

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
| `NOONGUN_PUBLISH_CALLS_PER_JOB` | `3` | How many variants one run of the publishing job sends before it hands the rest to a successor. Bounds the time limit of a job; see "Publishing" for what the queue needs. |
| `NOONGUN_PUBLISH_LOCK_SECONDS` | `120` | Shortest lifetime of the lock on one variant. The lock always lives at least 30 seconds longer than the longest job. |
| `REDIS_QUEUE_RETRY_AFTER`, `DB_QUEUE_RETRY_AFTER` | `300` | Seconds after which the queue treats a running job as lost. Must be greater than the longest time limit of any job: 255 seconds for a drafting job with the defaults, 105 for a publishing job. Raise it when you raise `NOONGUN_DRAFT_TIMEOUT`. |
| `NOONGUN_PUBLISH_STUCK_AFTER_MINUTES` | `30` | How long a post may stay in the publishing state without a sign of life from its job before it is treated as failed. The largest backoff value is added to this, because a job that waits for a retry is silent. |
| `NOONGUN_PUBLISH_QUEUE` | `default` | Queue that publishing jobs run on. If you change it, add the queue to the supervisor in `config/horizon.php`. |
| `ANTHROPIC_API_KEY` | empty | The instance key for drafting with Claude, used by every workspace that has no key of its own. Leave empty when every workspace brings its own key. |
| `OPENAI_API_KEY` | empty | The instance key for drafting with OpenAI, used by every workspace that drafts with OpenAI and has no OpenAI key of its own. |
| `NOONGUN_DRAFT_PROVIDER` | `anthropic` | The LLM provider a workspace drafts with until its owner chooses another. Must be listed under `drafting.providers` in `config/noongun.php`. |
| `NOONGUN_DRAFT_PROVIDERS` | empty | The providers an owner may choose from, comma separated, for example `anthropic,openai`. The default provider is always one of them. |
| `NOONGUN_DRAFT_OPENAI_MODEL` | `gpt-6.2-sol` | The OpenAI model drafts are generated with. |
| `NOONGUN_DRAFT_OPENAI_EFFORT` | empty | The reasoning effort for OpenAI. Empty leaves it to the default of the model. |
| `NOONGUN_DRAFT_ANTHROPIC_MODEL` | `claude-opus-5-5` | The Claude model drafts are generated with. |
| `NOONGUN_DRAFT_ANTHROPIC_EFFORT` | empty | How much work the model puts into one answer: `low`, `medium`, `high`, `xhigh` or `max`. Empty leaves it to the default of the model. |
| `NOONGUN_DRAFT_MAX_TOKENS` | `16000` | Most tokens one answer may use, including the tokens the model spends on thinking. |
| `NOONGUN_DRAFT_TIMEOUT` | `120` | Seconds allowed for one call to the provider. |
| `NOONGUN_DRAFT_PROMPT_VERSION` | `v1` | Which folder under `resources/prompts/draft/` holds the prompt. Stored on every draft run. |
| `NOONGUN_DRAFT_PRICES` | empty | Prices per million tokens, as JSON, added to the table in `config/noongun.php`: `{"anthropic":{"model-name":{"input":4,"output":20}}}`. Only used to estimate what a draft run cost. |
| `NOONGUN_DRAFT_PRICE_CURRENCY` | `USD` | Currency of the price table. |
| `NOONGUN_DRAFT_MAX_POSTS_PER_PROPOSAL` | `14` | Most posts one "Propose a week" drafts. |
| `NOONGUN_DRAFT_MAX_RUNS_PER_DAY` | `50` | Most posts a workspace may draft in 24 hours. A draft run counts when it reached the provider or is still open; one that spent nothing does not. |
| `NOONGUN_DRAFT_MAX_INSTANCE_RUNS_PER_DAY` | `200` | Most posts all workspaces together may draft in 24 hours on the key of the instance, counted the same way. Runs on a key a workspace brought do not count. |
| `NOONGUN_DRAFT_MAX_ATTEMPTS` | `3` | How many times one planned post is asked for at most. Only failures the provider marks as worth trying again are retried. |
| `NOONGUN_DRAFT_BACKOFF` | `30,120` | Seconds to wait before each retry of a draft, comma separated. The last value repeats. |
| `NOONGUN_DRAFT_STUCK_AFTER_MINUTES` | `6` | How long a draft run may be running before it is marked as failed. Never less than the time limit of the job plus a minute. A waiting run is given this plus the largest backoff. |
| `NOONGUN_DRAFT_LEAD_MINUTES` | `60` | How far ahead a slot time must be to get a draft, so that the draft can be read and approved before its time. |
| `NOONGUN_DRAFT_EXPECTED_OUTPUT_TOKENS` | `1500` | Size of a usual answer, thinking included, for the expected cost shown before a proposal starts. Lower it when you set a low effort, raise it for a model that thinks at length. |

Times are stored in UTC. Each workspace has its own timezone for display and
scheduling. The application timezone is fixed to UTC in `config/app.php` and
is not an environment setting.

Other settings (timezone list, locales, plan limits, feature flags, the list
of platforms and their adapters, the LLM providers and their price table) live
in `config/noongun.php`.

The cost on a draft run is an estimate from that price table, not an invoice.
Check the prices against the provider's price list when you change the model.

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
