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
registration is closed. The setup screen stays closed for as long as a
workspace exists, and the only owner of a workspace cannot delete their
account.

If port 8080 is taken, choose another before the first run:

```sh
NOONGUN_HTTP_PORT=8081 ./setup
```

The app is published on `127.0.0.1` only, so other machines on the network
cannot reach it. To open it to them, set `NOONGUN_HTTP_PORT` in `.env` to an
address and a port, for example `0.0.0.0:8080`, and run `./dev up`. Docker
bypasses host firewalls for published ports, so do this only on a network you
trust.

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
  an optional link and an optional planned time. Each channel is a column with
  its own count against the limit of its platform. The columns stand next to
  each other on a wide screen and under each other on a narrow one. A channel
  you add starts from the first text, and "Copy text from" brings the text of
  another column in at any time; each text is then changed by itself. One
  approval covers every text of the post, and they fire together. The page
  says what stands in the way of approval, per channel.

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

A workspace follows `NOONGUN_DRAFT_PROVIDER` only until it is settled on a
provider: when an owner chooses one, when an owner stores a key for the
provider in use, or when its first draft is asked of a provider. From then on
changing `NOONGUN_DRAFT_PROVIDER` does not move it. Its brief has gone to one
company and goes to another only when an owner chooses that, with a
confirmation that says what is sent and who pays. There is no setting to
follow the default again.

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
whether drafting is available and nothing about keys. Each draft run records
whether the instance key or the workspace key paid for it.

Choosing the provider and storing, replacing or removing a key need a recently
confirmed password, like the security settings, so a session that someone else
got hold of cannot swap the key. The page asks for the password first: until
it is confirmed, it shows no key field and no buttons, only **Confirm your
password**, which leads to the password page and back to Settings, Drafting.
Then the form appears and the key is stored when you submit it. Paste the key
only after that, because a provider shows a key once. A confirmation holds for
`AUTH_PASSWORD_TIMEOUT` seconds (three hours unless set otherwise), and the
page says until when. If it runs out while the page is open, you are asked for
the password again and a key you had pasted must be pasted once more: Noon Gun
does not keep a key anywhere while it waits for a password.

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
| `NOONGUN_DRAFT_PROMPT_VERSION` | `v3` | The folder under `resources/prompts/draft/` that holds the prompt. `v3` asks for a text written for each channel and tells the model which examples did well; `v2` and `v1` are kept for the runs that used them. |
| `NOONGUN_DRAFT_PRICES`, `NOONGUN_DRAFT_PRICE_CURRENCY` | empty, `USD` | The price table for the cost estimate. |
| `NOONGUN_DRAFT_EXAMPLES` | `5` | How many texts the workspace approved are sent with a draft request as examples, for each target channel. `0` sends none. Never more than 10. |
| `NOONGUN_DRAFT_EXAMPLES_MAX_CHARS` | `6000` | The most characters all examples of one request hold together. Over that, the texts chosen for how they did are left out first, then the oldest; a text is never cut. `0` sends none. Never more than 40000. |
| `NOONGUN_DRAFT_EXAMPLES_TOP_SHARE` | `50` | The share of the examples of a channel, in percent, that goes to the approved posts that did best instead of the most recent ones. Rounded down, and one example always stays with the most recent post. `0` takes only the most recent. |
| `NOONGUN_DRAFT_EXAMPLES_TOP_DAYS` | `90` | How many days back a post may have been published to be taken as an example for how it did. `0` takes none that way. Never more than 365. |

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
  platform, such as the longest text;
- texts the workspace approved, as examples of its voice and of what has
  already been said: for each target channel `NOONGUN_DRAFT_EXAMPLES` texts
  of that channel, the most recent ones and those that did best (see
  "Examples from approved posts" below). With each text goes the platform,
  the number of the channel when the draft is for that channel too, the name
  of the pillar the post had, and a mark when the text was chosen because it
  did well. `NOONGUN_DRAFT_EXAMPLES=0` sends none;
- a few words about the other posts of the same week, so that the same post
  is not written twice: for each at most 60 characters of the name of its
  pillar, of the title of its idea and of the first line of its text, for at
  most 12 posts. Never a whole text. A draft without a planned time gets no
  such list.

The Drafting settings page names the same parts to the owner who chooses a
provider; a test holds that list against the request.

Never sent: platform tokens, API keys, the names of your Pages and profiles,
the name of the workspace, names or email addresses of users, and anything
that belongs to another workspace. Of an earlier post the text is sent and
nothing else about it: not its link or the card of the link, not who wrote or
approved it, not when, not its address on the platform and no figures: of
how a post did, the provider learns only whether it was marked as one that did
well, never a score or a count. The API
key travels in a request header to the provider and nowhere else. It is not
written to logs, error messages, the queue or the draft run.

What the provider does with a request is set by its own terms. Do not put into
the brief what you would not send to it.

### Examples from approved posts

A draft is written from the brief, and from what you approved before. The
texts you approved most recently go along with each draft request, in the
form you approved them, so that an edit you made before approving is what the
next draft learns from. The model is told that they show your voice and what
has already been said, that it must not repeat their topics, openings or
phrases, and that it must never copy a sentence.

- A text counts when its post carries an approval: approved, scheduled, being
  published, published, or failed on the way to the platform. A draft that
  waits for approval, a rejected post and a post that was returned to draft
  do not count.
- For each channel a draft is for, `NOONGUN_DRAFT_EXAMPLES` texts of that
  channel are taken. Some of them are the posts that did best (see below),
  the rest are the most recent, newest approval first. A channel with fewer
  is topped up with the most recent texts of your other channels on the same
  platform. Nothing is taken from another platform.
- All examples of one request hold at most `NOONGUN_DRAFT_EXAMPLES_MAX_CHARS`
  characters together. Over that, the texts chosen for how they did are left
  out first, then the oldest. A text is never cut.
- They are chosen when the draft is written, not when it is asked for. The
  drafts of one proposed week therefore do not see each other as examples
  until you approve them. What they do get is the list of the other posts of
  the week described above.
- They go to the provider the workspace drafts with, like the brief.
- Only texts of your own workspace can be chosen.

The pages that start a draft say when examples are sent. The editor says on
a drafted post how many earlier posts it was written with and how many of
them were chosen because they did well; the draft run keeps the numbers of
those posts and no text.

#### Posts that did well

Once Noon Gun reads how your posts do (see "Insights"), some of the examples
are chosen for that:

- Of the examples of a channel, `NOONGUN_DRAFT_EXAMPLES_TOP_SHARE` percent
  (default 50, which is 2 of 5) go to posts that did well. The others stay
  with the most recent posts, and at least one always does, so a draft still
  sees what you said last.
- A post did well when its engagement score on that channel is above the
  average of your approved posts on the same channel, among those published
  in the last `NOONGUN_DRAFT_EXAMPLES_TOP_DAYS` days (default 90). After that
  a post is no longer chosen for how it did, however well it did.
- At most one post of each pillar is chosen this way, so that the drafts do
  not all learn from the same theme.
- A post without numbers is not a post that did badly. It is simply not
  chosen this way and can still be one of the most recent. On a channel
  without numbers, such as LinkedIn today, and in a new workspace, the
  examples are the most recent posts, as before.
- A post of another channel is never chosen for how it did there. Numbers of
  two Pages cannot be compared.
- The model is told which examples did well and to learn from how they are
  made: the opening, one clear point, a concrete detail. It is also told not
  to write about their topics again and not to reuse their wording. It is not
  sent any number.

`NOONGUN_DRAFT_EXAMPLES_TOP_SHARE=0` turns this off and takes only the most
recent posts. Marking the examples needs prompt `v3`, the default; with `v2`
the same posts are sent without the mark.

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

LinkedIn shows a link only with a title you give it. The model writes that
title, and an optional description, with the post, and they are stored on the
LinkedIn text only. When the model writes no title, the title of the idea is
used. A title or a description that is too long is cut to what LinkedIn takes.
A draft with a link can therefore be approved from the queue without opening
it. When there is neither a title nor an idea, the draft says that the link
needs a title.

When a channel can no longer publish at the moment a shared post is drafted,
the draft is written for the other channels and says which one was left out.
The slot of that channel stays open: connect the channel and propose the week
again.

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
copy `resources/prompts/draft/v3` to a new folder, edit it and point
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
   each channel, because a post is what you approve. It is one idea, drafted
   in one call and written for each channel by its own rules and limits.
   Channels with slots at different times get separate posts on separate
   ideas, and the page says how many posts reach one channel only. To get one
   idea on every channel, give the channels the same slot time.
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

### Drafting one post from an idea

"Draft a post" on an idea (`/ideas`) drafts one post from that idea without
proposing a week. Owners and editors see it; viewers do not. It leads to a
page that shows, before anything is spent:

- the idea, and the pillar it belongs to. An idea without a pillar gives a
  post without one. If the idea is already used, the page says in how many
  posts; drafting from it again is allowed.
- the channels to write for: every connected channel that can publish, all
  ticked to begin with. At least one must stay ticked. The result is one post
  with a text for each ticked channel, from one call to the provider.
- an optional planned time on the clock of the workspace. Left empty, the
  time is decided after approval, as for a post written by hand. A time that
  is given must be more than `NOONGUN_DRAFT_LEAD_MINUTES` away and not more
  than a year ahead, and no ticked channel may lose its access before it.
- the model, the expected cost and the worst case for this one post, and the
  drafts of the last 24 hours.

No slot is needed. Everything else that refuses a proposed week refuses here,
with the same sentence and the same link: no brief, no channel that can
publish, no API key, a provider that is not offered, the limits below, and a
draft that is still at work.

"Draft the post" opens one draft run and queues one job, and leads to the
queue, where the progress card shows it and "Stop waiting" works as for a
week. A second press, from a second click or a second tab, is refused. The
draft that arrives carries the idea and its pillar, so the idea counts as
used and the next proposed week does not take it. If its planned time is the
slot time of a channel, a proposed week leaves that slot alone, as it does for
any post at that time; moving the post opens the slot again.

The run counts towards the limits like any other, and is tried again, given
up and stopped by the same code.

### What a proposal may spend

- One proposal drafts at most `NOONGUN_DRAFT_MAX_POSTS_PER_PROPOSAL` posts.
  The earliest times are kept; the rest are left for a second proposal.
- A workspace drafts at most `NOONGUN_DRAFT_MAX_RUNS_PER_DAY` posts in 24
  hours. A run counts when it reached the provider, whether it succeeded or
  failed, or is still waiting or at work. A run that spent nothing does not
  count: one that could not be queued, one no worker picked up, one the
  provider turned away for a wrong key or an unknown model, one whose
  provider could not be reached at all. A call that timed out counts as a
  run that reached the provider, with no tokens known: the provider may have
  billed it.
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

Both figures include the examples from approved posts. The expected cost
counts the examples that would be sent now, chosen the way a draft run
chooses them. The worst case counts the most
there could be, at four tokens for each of the
`NOONGUN_DRAFT_EXAMPLES_MAX_CHARS` characters, because a post can be approved
between the start and the moment a draft is written. Lower that setting or
`NOONGUN_DRAFT_EXAMPLES` to lower both.

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

## Insights

Noon Gun reads how published posts did: reactions, comments, shares, clicks
and reach. See `docs/adr/0016-insights.md`.

### How often

The `scheduler` container runs `noongun:poll-metrics` every ten minutes. It
queues one `FetchMetricsJob` for each active channel that has a published
post due for a reading, and the job reads those posts in one call. A post is
read every hour during its first day, once a day until it is three weeks old,
and not after that (`NOONGUN_INSIGHTS_CADENCE`). That is about 45 readings
for one post.

### What is read from Facebook

| Number | Where it comes from | Needs |
|---|---|---|
| Reactions | The total of all reactions on the post. | Nothing more than a connected Page. |
| Comments | The total of all comments on the post, replies included. | Nothing more. |
| Shares | How often the post was shared. | Nothing more. |
| Clicks | The post insight `post_clicks`: clicks anywhere on the post. | `read_insights` |
| Reach | The post insight `post_total_media_view_unique`: how many people saw the post. | `read_insights` |

Only totals are asked for, never who reacted or commented. The posts of a
channel are read together, at least 25 posts in one request, and Meta counts
each post as one call against the rate limit of the Page (two with reach and
clicks).

Meta gives reach and clicks only for Pages with 100 or more likes, updates
reach about once a day, and renames these metrics from time to time. When it
does, set the new names with `FACEBOOK_METRIC_CLICKS` and
`FACEBOOK_METRIC_REACH`; the log line "Facebook refused to give the insights
of a post" says that a name is no longer accepted.

### Getting reach and clicks: `read_insights`

A Page as it is connected by default gives reactions, comments and shares.
Reach and clicks stay empty until the Page has one more permission:

1. In the Meta app at developers.facebook.com, add the permission
   `read_insights` to the use case **Manage everything on your Page**.
2. Add it to the permissions Noon Gun asks for, in `.env`:

   ```
   FACEBOOK_SCOPES=pages_show_list,pages_read_engagement,pages_manage_posts,read_insights
   ```

   If `FACEBOOK_SCOPES` already has other entries, such as
   `business_management`, keep them and add `read_insights` at the end.
3. Restart with `docker compose up -d`.
4. Open **Channels**, choose **Connect Facebook** again and allow the new
   permission. The Page keeps publishing while you do this.

The person who connects must be able to see the insights of the Page on
Facebook. From the next reading on, reach and clicks are filled for posts
that are younger than three weeks. Numbers of the time before are not
fetched.

`read_insights` is optional in every respect: a Page that is connected
without it, or that loses it later, publishes as before and only lacks these
two numbers. It is never a reason for "connect again".

### What to know when operating it

- **A number that is missing is not zero.** A platform that does not give a
  number, or a channel without the permission for it, leaves it empty.
- **LinkedIn gives no numbers yet.** A self-serve LinkedIn app may not read
  them (see `docs/adr/0007-linkedin-self-serve-until-m9.md`). LinkedIn posts
  are shown as not measured.
- **Publishing comes first.** Reading uses at most
  `NOONGUN_INSIGHTS_RATE_LIMIT_PERCENT` of a channel's rate limit and stops
  for a channel when the platform asks it to wait. It runs on its own queue
  (`insights`) with one worker of its own (`supervisor-insights` in
  `config/horizon.php`), so readings and publishing never wait for each
  other. A failed reading changes nothing about a post or a channel; it is
  logged and tried again later.
- **A token that Facebook refuses for reading** pauses reading for that Page
  for six hours and changes nothing else. Whether the Page needs to be
  connected again is decided by publishing and the token check.
  Connecting the Page again ends the pause at once.
- **A post that was deleted on Facebook** is not read for ever. After
  Facebook has answered three times in a row that it does not have the post
  (`NOONGUN_INSIGHTS_GONE_AFTER`), Noon Gun stops asking about it. The numbers
  it had are kept. Connecting the Page again makes Noon Gun ask once more.
- **When the workers are down** the queue does not fill up: at most one
  reading for each channel waits in it.
- A reading is only stored when a number changed, so the table grows with
  what happens to a post, not with how often it is read.
- The answers of Meta that this is built on were read in its documentation,
  not yet recorded from a real Page. `scripts/spike/facebook/` holds a script
  that prints them for one post; `docs/platform-notes/meta.md` lists what is
  still assumed.

### Where the numbers are shown

Everyone in the workspace who can see posts can see their numbers, viewers
included. Nothing on these pages changes anything.

- **Insights** (`/insights`) covers the last 7, 28 or 90 days, counted in days
  of the workspace timezone, today included. The period is in the address
  (`/insights?period=7`), so a view can be linked. It shows what the period
  added up to, a table for each pillar sorted by average score, the ten posts
  with the highest score, and a table for each channel that also says why a
  channel has no numbers.
- **A published post** (`/posts/{id}`) shows its numbers for each channel, a
  total when it is on several, when they were last checked, and the readings
  over time.
- **History** in the queue shows the total of each published post on one line.
- **The dashboard** shows the totals of the last 7 days and the best post, once
  a post of that week is measured.

How to read them:

- A dash is a number the platform did not give. It is left out of totals and
  averages. An average is taken over the posts that have the number, and the
  page says "from 3 of 5" when that is fewer than the measured posts.
- The score is reactions x 1 + comments x 2 + shares x 3 + clicks x 1, with
  the weights from `NOONGUN_INSIGHTS_WEIGHT_*`. The page prints the formula
  with the weights in use. Reach is not part of it.
- A post that was just published says when its first reading is expected: by
  the next run of `noongun:poll-metrics`, about ten minutes after it went out.
  If it says that a reading is due and nothing changes within the hour, check
  that the `scheduler` and `horizon` containers are running.
- A post older than the last step of `NOONGUN_INSIGHTS_CADENCE` says "Last
  measured on" with the time of its last reading.
- A channel that needs to be connected again keeps its last numbers, and the
  pages say that they are not updated until it is connected.
- With `NOONGUN_INSIGHTS_ENABLED=false` the pages say that insights are turned
  off and still show what was read before.

## Running on a home server

`./setup` gives a development stack: it mounts the source code and runs in debug
mode. On a server, run the production image instead. The app container then holds
the code and the built assets, and nothing on the host needs PHP or Node. The
production image runs PHP with `php.ini-production`, so PHP errors go to the
container log and never to the browser; `docker/app/php.ini` is applied on top
of it in both images.

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

`NOONGUN_TRUSTED_PROXIES` lists the proxies whose `X-Forwarded-For` and
`X-Forwarded-Proto` headers the app trusts. Without it the app sees plain HTTP
behind the proxy and builds `http://` links and redirects. A request from a
proxy on the host arrives from the Docker network gateway, which is why the
value above names the private ranges Docker uses. Leave it empty when no proxy
is in front of the app.

The app never reads the host name from `X-Forwarded-Host` or the port from
`X-Forwarded-Port`. It uses the `Host` header, so the proxy must pass that
header on unchanged. `tailscale serve` and Caddy do. nginx needs
`proxy_set_header Host $host;`.

Outside the `local` environment the app only answers requests whose host is the
host of `APP_URL`, or `localhost`, `127.0.0.1` or `[::1]`, which the container
health check uses. A request for any other host gets a 400 response. If every
page answers 400 after an update, `APP_URL` does not match the address in the
browser, or the proxy rewrites the `Host` header.

Every page is sent with `X-Content-Type-Options: nosniff`,
`X-Frame-Options: DENY`, `Content-Security-Policy: frame-ancestors 'none'` and
`Referrer-Policy: same-origin`, so the app cannot be shown inside a frame on
another site. Over HTTPS it also sends `Strict-Transport-Security` for one
year, without subdomains: a browser that has opened the app over HTTPS refuses
plain HTTP for that host name from then on. The Content-Security-Policy does
not restrict scripts or styles yet.

To update, pull and rebuild. Migrations run when the `app` container starts.

```sh
git pull
docker compose up -d --build --wait
```

### Troubleshooting: "Facebook could not be reached"

If connecting a channel says "Facebook could not be reached", the containers
may have no DNS. This happens on a host whose `/etc/resolv.conf` has no
`nameserver` line, because the host runs a local resolver. Docker's embedded
DNS then has no upstream on the compose network, so no outside name resolves.
Give the containers a resolver in a local compose file, `compose.local.yml`,
which Git ignores:

```yaml
x-dns: &dns
  dns:
    - 1.1.1.1
    - 9.9.9.9

services:
  app:
    <<: *dns
  horizon:
    <<: *dns
  scheduler:
    <<: *dns
```

List it in `.env` after the main file and start the stack again:

```
COMPOSE_FILE=docker-compose.yml:compose.local.yml
```

```sh
docker compose up -d --wait
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
| `NOONGUN_HTTP_PORT` | `127.0.0.1:8080` | Where the web app is published on the host, as `address:port`. Keep the port of `APP_URL` in step. A bare port, for example `8080`, is published on every network interface; an `.env` from before this default still has that form. |
| `NOONGUN_VITE_PORT` | `5173` | Host port for `./dev vite`. |
| `NOONGUN_AUTO_MIGRATE` | `true` | Run migrations when the `app` container starts. |
| `NOONGUN_TRUSTED_PROXIES` | empty | Reverse proxies whose `X-Forwarded-For` and `X-Forwarded-Proto` headers are trusted: comma separated addresses or CIDR ranges, or `*`. |
| `NOONGUN_OPERATOR_EMAILS` | empty | Who may open the Horizon dashboard at `/horizon`: email addresses of accounts with a verified address, comma separated. Empty: the owners of the first workspace in `self_hosted` mode, nobody in `saas` mode. The rule applies in every environment, including `local`. |
| `NOONGUN_DEFAULT_TIMEZONE` | `Europe/Copenhagen` | Preselected timezone on the setup screen if the browser timezone is unknown. |
| `NOONGUN_UID`, `NOONGUN_GID` | `1000` | User and group the containers run as. |
| `APP_URL` | `http://localhost:8080` | Public address of the app. Outside the `local` environment its host is the only one the app answers to, besides the loopback names. |
| `DB_*` | `pgsql`, host `postgres` | Database connection. The compose file uses the same values to create the database. |
| `REDIS_HOST` | `redis` | Redis host. |
| `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET` | empty | Credentials of the Meta app this instance connects Pages with. |
| `FACEBOOK_GRAPH_VERSION` | `v27.0` | Graph API version used for every Facebook call. |
| `FACEBOOK_SCOPES` | `pages_show_list,pages_read_engagement,pages_manage_posts` | Permissions requested when a Page is connected, comma separated. A Page must be granted all of them, except `read_insights`, which only adds the reach and clicks of posts (see "Insights"). |
| `FACEBOOK_METRIC_CLICKS`, `FACEBOOK_METRIC_REACH` | `post_clicks`, `post_total_media_view_unique` | Names of the post insights that are read as clicks and reach. Change them when Meta renames a metric. An empty name means the number is not asked for. |
| `FACEBOOK_GRAPH_URL`, `FACEBOOK_DIALOG_URL` | `https://graph.facebook.com`, `https://www.facebook.com` | Base addresses of the Graph API and the login dialog. Change only for testing. |
| `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET` | empty | Credentials of the LinkedIn app this instance connects profiles with. |
| `LINKEDIN_API_VERSION` | `202611` | Value of the `Linkedin-Version` header on every LinkedIn API call, `YYYYMM`. LinkedIn refuses a version about a year after it came out. |
| `LINKEDIN_SCOPES` | `openid,profile,w_member_social` | Permissions requested when a profile is connected, comma separated. |
| `LINKEDIN_API_URL`, `LINKEDIN_WEB_URL` | `https://api.linkedin.com`, `https://www.linkedin.com` | Base addresses of the API and of the sign-in. Must be HTTPS. Change only for testing. |
| `NOONGUN_PUBLISH_MAX_ATTEMPTS` | `5` | How many times one variant is sent before the post is marked as failed. Only retryable errors are tried again. |
| `NOONGUN_PUBLISH_BACKOFF` | `60,300,900,3600` | Seconds to wait before each retry, comma separated. The last value repeats. The largest value is also the longest wait a platform may ask for; a longer one fails the post. |
| `NOONGUN_PUBLISH_TIMEOUT` | `30` | Seconds allowed for one platform call. The time limit of a publishing job is this value for each variant it sends, plus 15 seconds. |
| `NOONGUN_PUBLISH_CALLS_PER_JOB` | `3` | How many variants one run of the publishing job sends before it hands the rest to a successor. Bounds the time limit of a job; see "Publishing" for what the queue needs. |
| `NOONGUN_PUBLISH_LOCK_SECONDS` | `120` | Shortest lifetime of the lock on one variant. The lock always lives at least 30 seconds longer than the longest job. |
| `REDIS_QUEUE_RETRY_AFTER`, `DB_QUEUE_RETRY_AFTER` | `300` | Seconds after which the queue treats a running job as lost. Must be greater than the longest time limit of any job: 255 seconds for a drafting job with the defaults, 105 for a publishing job. Raise it when you raise `NOONGUN_DRAFT_TIMEOUT`. |
| `NOONGUN_PUBLISH_STUCK_AFTER_MINUTES` | `30` | How long a post may stay in the publishing state without a sign of life from its job before it is treated as failed. The largest backoff value is added to this, because a job that waits for a retry is silent. |
| `NOONGUN_PUBLISH_QUEUE` | `default` | Queue that publishing jobs run on. If you change it, add the queue to the supervisor in `config/horizon.php`. |
| `NOONGUN_INSIGHTS_ENABLED` | `true` | Whether the numbers of published posts are read at all. `false` stops the scheduler command and the job. |
| `NOONGUN_INSIGHTS_CADENCE` | `24:60,504:1440` | How often a published post is read, by its age, as `hours:minutes` pairs: until the post is that many hours old, read it every that many minutes. A post older than the last pair is not read any more. |
| `NOONGUN_INSIGHTS_VARIANTS_PER_RUN` | `25` | Most posts of one channel that one run of the job reads. Never more than 500. |
| `NOONGUN_INSIGHTS_RATE_LIMIT_PERCENT` | `20` | The share of a channel's rate limit that reading may use, so that publishing always has calls left. From 1 to 90. |
| `NOONGUN_INSIGHTS_PAUSE_MINUTES` | `60` | How long a channel is left alone after the platform refused a reading. A longer wait the platform asks for is respected, up to a day. |
| `NOONGUN_INSIGHTS_TIMEOUT` | `45` | Time limit in seconds for one run of the job. Must stay below the `timeout` of `supervisor-insights` in `config/horizon.php`. |
| `NOONGUN_INSIGHTS_QUEUE` | `insights` | Queue that the job runs on. `supervisor-insights` in `config/horizon.php` reads the same setting, so the queue always has a worker. Do not set it to the publishing queue. |
| `NOONGUN_INSIGHTS_GONE_AFTER` | `3` | How many times in a row a platform must answer that a post is gone before Noon Gun stops asking for its numbers. |
| `NOONGUN_INSIGHTS_WEIGHT_REACTIONS`, `_COMMENTS`, `_SHARES`, `_CLICKS` | `1`, `2`, `3`, `1` | Weights of the engagement score, the one number posts are compared by: each count times its weight, added up. Reach is not part of it. |
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
| `NOONGUN_DRAFT_PROMPT_VERSION` | `v3` | Which folder under `resources/prompts/draft/` holds the prompt. Stored on every draft run. |
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

### A Page is missing

Noon Gun can only connect what Facebook hands over. A Page that Facebook
listed but that cannot be connected is named after the connect, with the
reason. A Page that Facebook did not list at all cannot be named, so check
these causes:

- **It was not ticked in Facebook's consent screen.** Choose **Connect
  Facebook** again and, in the Facebook dialog, edit the access and tick the
  Page.
- **Your role on the Page does not allow creating content.** Noon Gun shows
  this as "Your role on this Page does not allow creating posts." Change the
  role under **Page access** in the settings of the Page, then connect again.
- **The Page is held in a business portfolio.** Facebook leaves such Pages out
  unless the app also has the `business_management` permission. Add
  `business_management` to the use case in the Meta app, set this in `.env`,
  restart with `docker compose up -d` and connect again:

  ```
  FACEBOOK_SCOPES=pages_show_list,pages_read_engagement,pages_manage_posts,business_management
  ```

  The Facebook dialog then has an extra step, **Choose the Businesses**. The
  least that is needed there is **Opt in to current Businesses only**, with
  the portfolios that hold the Pages ticked. We confirmed this with a real
  connect; see the entries of 2026-11-26 in `docs/platform-notes/meta.md`.

A profile in professional mode is not a Page and cannot be connected.

Noon Gun stores one access token for each connected Page, encrypted with
`APP_KEY`. It does not store your own Facebook token. A Page token has no
expiry date, but Facebook withdraws it when you change your password, lose
your role on the Page or remove the app. The channel then shows **Needs
reconnecting**; choose **Connect again**. **Disconnect** erases the token and
keeps the history of what was published.

Only owners of a workspace can connect and disconnect channels, because a
channel holds the access to an account on a platform. Editors and viewers can
see them.

## Connecting a LinkedIn profile

Noon Gun posts to your own LinkedIn profile. Company pages are not supported
yet (see `docs/adr/0007-linkedin-self-serve-until-m9.md`). Each instance
connects through a LinkedIn app that its owner creates. The two products it
needs are open to every developer and need no review.
`docs/platform-notes/linkedin.md` has the longer checklist; the short version:

1. Create an app at linkedin.com/developers/apps. LinkedIn asks for a LinkedIn
   Page to associate the app with; a Page of your own is fine. Do not use
   "LinkedIn" in the app name.
2. On the **Products** tab, add **Share on LinkedIn** and **Sign In with
   LinkedIn using OpenID Connect**. Both are needed: the first lets Noon Gun
   post, the second tells it whose profile it is. The **Auth** tab then lists
   the scopes `openid`, `profile` and `w_member_social`.
3. On the **Auth** tab, add this address as an authorised redirect URL:

   ```
   <APP_URL>/auth/linkedin/callback
   ```

   It must match `APP_URL` exactly and start with `https://`. The Channels
   page shows the address this instance uses. If `APP_URL` changes, change it
   in the app as well.
4. Put the Client ID and Client Secret from the **Auth** tab in `.env` as
   `LINKEDIN_CLIENT_ID` and `LINKEDIN_CLIENT_SECRET`, then restart with
   `docker compose up -d`.
5. Open **Channels** in Noon Gun and choose **Connect LinkedIn**. Sign in to
   LinkedIn if asked and allow what is asked for.

What to know about a LinkedIn channel:

- **The access lasts 60 days.** LinkedIn gives an app like this no way to
  renew it without you. The Channels page shows the date, warns two weeks
  before it, and offers **Connect again**. If you are still signed in to
  LinkedIn, connecting again takes one click. After the date nothing is
  published to the profile until you have done so.
- **Connecting again keeps the channel.** The same profile gets a new token on
  the channel it already has. A profile you disconnected earlier is shown for
  you to confirm first, as for Facebook.
- **A link needs a title.** LinkedIn does not read the page behind a link. It
  shows the link as a card with the title and description you type in the
  editor, without an image for now. Noon Gun never fetches the page to fill
  them in. A post with a link and no title cannot be approved.
- **Text only, up to 3000 characters.** A hashtag works when it stands at the
  start of a word and holds only letters and digits, such as `#coffee`. A tag
  with an underscore and the `#` inside a web address are sent as plain text.
  Mentions do not work: an `@` is sent as plain text. Image posts are not
  built yet.
- **A post cannot be planned for after the access ends.** If a post is due
  after the date the Channels page shows for the profile, it cannot be
  approved or scheduled. The editor and the queue say so, with the date.
  Connect the profile again first, which gives it a new date, or choose an
  earlier time.
- **If a post fails with "the outcome is unknown"**, LinkedIn may or may not
  have published it: the connection broke, or LinkedIn answered with an error,
  after the post was sent. LinkedIn offers no way to ask, so Noon Gun does not
  send it again by itself. Look at your profile.
  - **The post is there:** leave it as it is. Do not choose **Send again**,
    which would publish a second copy. The post stays listed as failed in Noon
    Gun; there is no way yet to mark it as published.
  - **The post is not there:** open the post in Noon Gun and choose **Send
    again**.
- **A withdrawn access is noticed late.** If you remove the app's access on
  LinkedIn, Noon Gun finds out when the next post fails. The channel then
  shows **Needs reconnecting**.
- **Set `LINKEDIN_API_VERSION` to a current version about once a year.**
  LinkedIn stops accepting a version roughly a year after it came out. A post
  that fails for this reason says so.

Noon Gun stores one access token for each connected profile, encrypted with
`APP_KEY`. It asks LinkedIn for your name and member id and for nothing else:
not your email address, your connections or your posts.

## Tests

The Pest suite runs against the `noongun_testing` database, which the
PostgreSQL container creates on first start. Tests never call external APIs.
GitHub Actions runs Pint, Larastan, the migration check and Pest on every pull
request (`.github/workflows/ci.yml`).

## Licence

AGPL-3.0. See `LICENSE`.
