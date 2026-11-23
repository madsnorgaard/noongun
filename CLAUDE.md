# Noon Gun - working agreement for Claude Code

Read `docs/BRIEF.md` in full before writing any code. It is the source of truth for
scope, architecture and milestones. This file holds the standing rules.

## What this is

Noon Gun is a self-hosted social publishing hub. It drafts posts with an LLM from a
maintained content brief, holds them for human approval, and publishes them on
schedule to Facebook Pages and LinkedIn. Phase 1 serves one person on a home server.
Phase 3 is a low-priced multi-tenant SaaS for South African and international users.
Every design choice must keep both possible from the same codebase.

## Stack

- PHP (current stable) with Laravel (current stable LTS-supported release)
- Inertia + Vue 3 + Tailwind for the UI
- PostgreSQL, Redis, Laravel Horizon for queues, Laravel scheduler for timing
- Prism (`prism-php/prism`) as the LLM layer, wrapped behind our own interface
- Pest for tests, Pint for style, Larastan for static analysis
- Docker Compose for every environment

Check the current stable version of each dependency before pinning it. Do not rely
on remembered version numbers.

## Rules that are never broken

1. **Nothing publishes without approval.** A post reaches a platform only from the
   `approved` state, and only a human action sets that state. No exceptions, no
   "auto-approve" default.
2. **Every tenant-owned row carries `workspace_id`** and every query is scoped by
   it, even in self-hosted mode where there is only one workspace. Add a test that
   proves cross-workspace access fails for each new model.
3. **Platform tokens and API keys are encrypted at rest** (Laravel encrypted casts)
   and never appear in logs, exceptions, fixtures or commits.
4. **Vendor code stays behind our interfaces.** Controllers, jobs and UI never call
   the Graph API, the LinkedIn API, Prism or a payment SDK directly. They call
   `ChannelAdapter`, `DraftGenerator` and `BillingGateway`.
5. **No model names, API versions or prices hardcoded in logic.** They live in
   `config/noongun.php` and environment variables.
6. **Tests never call a real external API.** Use `Http::fake()` and recorded
   fixtures. Real calls only happen in scripts under `scripts/spike/`.
7. **All times stored in UTC.** Each workspace has its own timezone for display and
   scheduling. Test with `Europe/Copenhagen` and `Africa/Johannesburg`.

## Commits and pull requests

- No AI attribution of any kind: no `Co-Authored-By`, no session links, no
  "Generated with" lines.
- Conventional commit prefixes (`feat:`, `fix:`, `chore:`, `docs:`, `test:`).
- One logical change per commit. Reference the GitHub issue number.
- Work on a feature branch and open a pull request. Never push to `main`.

## Writing style for docs, README and UI copy

- No contractions ("do not", not the short form).
- Short hyphens only, never em dashes.
- Plain, direct language. No marketing filler.

## UI design

The interface must not look like a generated template. Avoid numbered menus, small
letter-spaced capital labels, coloured accent side-bars on cards, gradient hero
blocks and emoji as icons. Aim for a calm, dense, tool-like interface in the spirit
of a good mail client: a queue, a calendar, an editor.

## Definition of done for any change

- Pest tests pass, Pint is clean, Larastan passes at the configured level
- New behaviour has a test, including the workspace isolation test where relevant
- Migrations run forwards and backwards
- `docker compose up` still gives a working app from a clean checkout
- Docs updated if behaviour, configuration or environment variables changed

## When unsure

Platform rules (Meta, LinkedIn) change often. Before building against an endpoint,
read the current official documentation linked in `docs/BRIEF.md` and record what
you found in `docs/platform-notes/`. If the documentation contradicts the brief,
stop and raise it instead of guessing. Record significant choices as short ADRs in
`docs/adr/`.
