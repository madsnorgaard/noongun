# Noon Gun - project brief

Every day at 12:00 a cannon on Signal Hill in Cape Town fires, as it has for over two
centuries. Noon Gun does the same for social channels: posts go out on schedule, from
hardware you control.

## 1. Vision and phases

**Phase 1 - personal tool.** One user, one workspace, running on a home server
(Debian 13, Docker, reachable only over Tailscale). Publishes to one Facebook Page.
Drafts come from the Claude API and a content brief. Purpose: steady visibility for
IT solutions, web applications and DevOps services, mixed with photography, online
activism, gaming and nerdy themes.

**Phase 2 - multi-channel.** LinkedIn added. One idea becomes one variant per
channel. Engagement metrics feed back into drafting. OpenAI and other LLM vendors
selectable.

**Phase 3 - SaaS.** The same codebase runs as a hosted, multi-tenant service at a
low monthly price, aimed first at South African freelancers, small businesses and
NGOs, then international users. The self-hosted edition stays available.

## 2. Positioning - why this exists next to Postiz and Mixpost

Two mature open source schedulers already exist. Noon Gun must not try to out-feature
them. Its reason to exist:

- **Brief-driven drafting.** The user maintains a description of who they are, what
  they sell and how they sound. Noon Gun proposes a week of posts from it. Existing
  tools start from an empty editor with an AI button.
- **Approval-first and small.** A queue, a calendar, an editor. Two channels done
  well instead of thirty done thinly.
- **Priced and billed for South Africa.** Rand pricing, local payment methods,
  light pages for slow connections, and a bring-your-own-API-key tier that keeps the
  entry price very low.

If a feature does not serve one of those three points, it waits.

## 3. Reference projects

Study these for patterns. Respect their licences.

| Project | Licence | Stack | Use it for |
|---|---|---|---|
| Postiz - github.com/gitroomhq/postiz-app | AGPL-3.0 | Next.js, NestJS, Prisma, Postgres, Temporal | Provider abstraction, OAuth flows per platform, calendar UX, how it handles platform approval for self-hosters |
| Mixpost Lite - github.com/inovector/mixpost | MIT | Laravel, Vue, Inertia, Redis | Closest to our stack. Service provider per social network, account and post models, queue design, media handling. Docs: docs.mixpost.app/lite |
| Prism - github.com/prism-php/prism | MIT | Laravel package | Unified LLM interface across Anthropic, OpenAI, Gemini, Ollama and others |
| Laravel Cashier (Stripe and Paddle) | MIT | Laravel package | Subscription state, webhooks, plan changes |
| Laravel Socialite | MIT | Laravel package | OAuth handshake for Facebook and LinkedIn |
| Laravel Horizon | MIT | Laravel package | Queue supervision and failed job handling |

Licence rule: read Postiz for ideas only. Do not copy or port its code unless the
project licence decision (section 13) lands on AGPL-3.0. Mixpost Lite is MIT, so
patterns and small pieces may be reused with attribution. Mixpost Pro and Enterprise
are commercial products; do not reproduce their gated features from their source.

## 4. Architecture

```
Browser (Inertia + Vue)
        |
   Laravel app  ----------------------------  PostgreSQL
        |                                      Redis
   Horizon workers
     - PublishPostJob        -> ChannelAdapter  -> Facebook Graph API / LinkedIn API
     - GenerateDraftsJob     -> DraftGenerator  -> Claude / OpenAI / other (via Prism)
     - FetchMetricsJob       -> ChannelAdapter
     - RefreshTokenJob       -> ChannelAdapter
   Scheduler (every minute)
     - dispatches due posts, token checks, metric pulls
```

Two run modes from one codebase, set by `NOONGUN_MODE`:

- `self_hosted` - first run creates a single workspace and owner, registration is
  closed, billing code is inactive, the instance owner supplies their own Meta and
  LinkedIn app credentials and LLM keys.
- `saas` - open registration, workspaces per customer, plans and entitlements
  enforced, platform-level Meta and LinkedIn apps, billing active.

All platform traffic is outbound. Phase 1 needs no public ingress: the OAuth redirect
happens in the user's browser, so a tailnet HTTPS hostname is sufficient. Metrics are
polled, not received by webhook, until Phase 3.

## 5. Directory structure

```
app/
  Domain/
    Workspaces/      Workspace, Membership, roles, WorkspaceScope, policies
    Channels/        Channel model, token storage
      Contracts/     ChannelAdapter, capability value objects
      Adapters/      Facebook/, LinkedIn/  (one folder per platform)
    Content/         Idea, Post, PostVariant, Media, Pillar, PostState enum
    Scheduling/      Slot, PostingPlan, DispatchDuePosts command, PublishPostJob
    Drafting/        Brief, DraftRequest, DraftResult, VoiceRules
      Contracts/     DraftGenerator
      Generators/    PrismDraftGenerator
    Insights/        MetricSnapshot, FetchMetricsJob, performance summary
    Billing/         Plan, Entitlement, UsageRecord        (Phase 3)
      Contracts/     BillingGateway
      Gateways/      Paddle/ or Stripe/, Paystack/
  Http/              Controllers, Requests, Middleware (thin, no vendor calls)
config/noongun.php   mode, default models, limits, feature flags
resources/js/        Inertia pages: Queue, Calendar, Editor, Channels, Brief, Settings
resources/prompts/   Blade prompt templates, versioned
database/            migrations, factories, seeders
docker/              Dockerfile, compose files, entrypoints
docs/
  BRIEF.md           this file
  adr/               decision records
  platform-notes/    findings from Meta and LinkedIn documentation, dated
scripts/spike/       throwaway scripts that call real APIs
tests/               Feature/, Unit/, Fixtures/ (recorded API responses)
```

## 6. Domain model

All tables except `users` carry `workspace_id`.

| Table | Key fields | Notes |
|---|---|---|
| `users` | name, email, password, locale | |
| `workspaces` | name, slug, timezone, locale, mode flags | One in self-hosted mode |
| `memberships` | user_id, role (owner, editor, viewer) | |
| `channels` | platform, external_id, display_name, access_token (encrypted), refresh_token (encrypted), token_expires_at, scopes, status | One row per connected Page or profile |
| `briefs` | title, body (markdown), voice_rules (json), calls_to_action (json) | The content brief; versioned |
| `pillars` | name, description, weekly_target | Content themes and their mix |
| `ideas` | title, source_url, notes, pillar_id | A topic before it becomes posts |
| `posts` | idea_id, state, scheduled_at, approved_by, approved_at | The cross-channel unit |
| `post_variants` | post_id, channel_id, body, link, utm, external_id, permalink, published_at, error | One per channel |
| `media` | disk, path, mime, width, height, alt_text | Attached to variants |
| `slots` | channel_id, weekday, time | Preferred posting times |
| `draft_runs` | provider, model, prompt_version, input_tokens, output_tokens, cost_estimate | One per generation, for cost tracking and metering |
| `metric_snapshots` | post_variant_id, captured_at, reactions, comments, shares, clicks, reach | |
| `llm_credentials` | provider, api_key (encrypted), scope (instance or workspace) | Supports bring-your-own-key |
| `audit_events` | actor, action, subject, payload | Approvals, publishes, token changes |

Post states: `draft -> approved -> scheduled -> publishing -> published`, with
`failed` reachable from `publishing` and `rejected` from `draft`. Only a human
action moves a post to `approved`.

## 7. Core interfaces

```php
interface ChannelAdapter
{
    public function platform(): string;
    public function capabilities(): ChannelCapabilities;   // max length, media types, link previews
    public function authorizationUrl(Workspace $w, string $state): string;
    public function connect(Workspace $w, string $code): Collection;   // returns Channel(s)
    public function publish(PostVariant $variant): PublishResult;       // external id + permalink
    public function delete(PostVariant $variant): void;
    public function fetchMetrics(PostVariant $variant): MetricSnapshot;
    public function refreshToken(Channel $channel): Channel;
}

interface DraftGenerator
{
    /** @return DraftResult[] one per requested channel variant */
    public function generate(DraftRequest $request): array;
}

interface BillingGateway   // Phase 3
{
    public function checkoutUrl(Workspace $w, Plan $plan): string;
    public function portalUrl(Workspace $w): string;
    public function handleWebhook(Request $request): void;
}
```

Adapters are registered in a `ChannelManager` keyed by platform name, in the style
of Laravel's own manager classes. Adding a platform later means adding one folder
and one config entry.

Publishing must be idempotent: `PublishPostJob` takes a lock per variant, checks
`external_id` before calling the platform, and retries with backoff only on errors
the adapter marks as retryable. A duplicate public post is the worst failure this
system can have.

## 8. Platform integration notes

Verify every point below against current documentation before building, and record
findings in `docs/platform-notes/`.

**Facebook Pages**
- Publishing uses the Pages API with a Page access token and the
  `pages_manage_posts`, `pages_read_engagement` and `pages_show_list` permissions.
  The connecting user must be able to create content on the Page.
- Phase 1: the app serves only a Page its owner manages, which Meta treats as
  standard access with no App Review. Milestone 1 must prove with a real post that
  it is publicly visible.
- Phase 3: serving Pages owned by other businesses requires advanced access through
  App Review, business verification of the company behind the app, and ongoing
  review. App Review requires at least one successful API call per requested
  permission within the 30 days before submission. Plan for a screencast, a privacy
  policy URL, terms of service and a data deletion route.
- Exchange the short-lived user token for a long-lived one, then fetch the Page
  token. Store the expiry and alert before it lapses.
- Docs: developers.facebook.com/docs/pages-api/getting-started,
  developers.facebook.com/docs/pages-api/posts,
  developers.facebook.com/docs/permissions

**LinkedIn**
- Posting as a person uses `w_member_social`. Posting as a company page uses
  `w_organization_social` and requires the member to be a page administrator.
- Company page access comes through the Community Management API, a vetted product
  with a Development tier (limited call volume, twelve months to finish the
  integration) and a Standard tier. The application needs a verified organisation
  website and an associated LinkedIn Page.
- Phase 2 starts with personal profile posting. Apply for the Community Management
  API early, because approval takes time and gates Phase 3.
- Confirm which self-serve product currently grants `w_member_social`, the access
  token lifetime, and whether refresh tokens are issued for our app type.
- Docs: learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api,
  learn.microsoft.com/en-us/linkedin/marketing/increasing-access

**Both**
- Respect rate limits; adapters expose them through `ChannelCapabilities`.
- Never automate likes, comments or follows. Publishing and reading own metrics only.

## 9. LLM layer

- `DraftGenerator` is our interface. `PrismDraftGenerator` is the only
  implementation at first. Provider and model are chosen per workspace from
  configuration: `anthropic` first, then `openai`, with `gemini`, `mistral` and
  `ollama` available because Prism supports them.
- A `DraftRequest` contains: the brief, voice rules, the pillar, the idea or source
  URL, the target channels with their capabilities, and a few recent
  best-performing posts as examples.
- Output is structured (JSON schema): one body per channel, suggested link, alt
  text, and a one-line rationale. Validate it; on invalid output retry once, then
  fail the run visibly.
- Prompts are Blade templates in `resources/prompts/` with a version string that is
  stored on each `draft_run`.
- Voice rules are data, not code. The first workspace uses: no contractions, short
  hyphens instead of em dashes, an old school Facebook tone on Facebook (personal,
  conversational, no hashtag walls), and a professional but human tone on LinkedIn.
- Record tokens and estimated cost per run. This is the basis for metering in
  Phase 3.
- Key handling supports two sources from day one: instance keys (set by the
  operator) and workspace keys (bring your own key). The cheapest SaaS tier is
  bring-your-own-key.
- Never send platform tokens, other workspaces' content or personal data beyond the
  brief to an LLM provider.

## 10. SaaS readiness (build now, switch on later)

Cheap to do from the first commit, expensive to retrofit:

- `workspace_id` on every tenant row, a global scope, and isolation tests
- Roles and policies, even with one user
- Plans and entitlements as configuration (`channels`, `posts_per_month`,
  `ai_drafts_per_month`, `members`), checked through one `Entitlements` service that
  returns "unlimited" in self-hosted mode
- Usage metering from `draft_runs` and published variants
- Audit trail
- Translatable UI strings (English first; Afrikaans, isiZulu and Danish later)
- Data export and account deletion, needed for GDPR, POPIA and Meta's requirements
- Light pages: server-rendered first paint, small bundles, no heavy client libraries

Phase 3 only:

- Billing. Start with a merchant of record (Paddle, or Stripe with tax handling) so
  that VAT on digital services, including South African VAT on foreign-supplied
  services, is handled by the provider. Add Paystack for rand pricing and local
  payment methods once there is demand and a suitable legal entity. PayFast settles
  only in rand and is a gateway, not a merchant of record.
- Regional pricing with a rand price list
- Platform-level Meta and LinkedIn apps with completed reviews
- Onboarding flow, transactional email, status page, terms, privacy policy, DPA
- Abuse controls: sign-up throttling, content policy, per-workspace rate limits

## 11. Deployment and operations

- One `docker-compose.yml`: `app` (web), `horizon`, `scheduler`, `postgres`,
  `redis`. A `compose.override.yml` for local development.
- Phase 1 target: home server, HTTPS through `tailscale serve`, no public ports.
- Phase 3 target: a VPS behind Traefik v3 with GitHub Actions deploying tagged
  images. Keep the image identical between the two; only environment differs.
- Health endpoint `/up` for Uptime Kuma. Prometheus metrics at `/metrics`
  (queue depth, publishes, failures, token days-to-expiry, LLM spend). Logs as JSON
  to stdout for Loki.
- Alerts that must exist before Phase 1 is called done: publish failed, token
  expires within 7 days, queue stalled, scheduler not running.
- Nightly `pg_dump` and media backup, with a documented restore that has actually
  been run once.

## 12. Milestones

Each milestone is one or more GitHub issues and ends in a working, deployable state.

**M1 Spike** - `scripts/spike/`: create the Meta app, get a long-lived Page token,
publish one text post and one photo post, confirm public visibility from a
logged-out browser, delete them. Write up findings in `docs/platform-notes/meta.md`.
No application code yet.

**M2 Skeleton** - Laravel app in Docker, auth, workspace model with scoping and
isolation tests, CI (Pest, Pint, Larastan), health endpoint, self-hosted first-run
setup.

**M3 Publish** - `ChannelAdapter`, Facebook adapter with connect flow, posts and
variants, approval, scheduler, idempotent `PublishPostJob`, queue and calendar UI.
Acceptance: a manually written post, approved in the UI, goes out at its scheduled
minute and shows its permalink.

**M4 Draft** - brief editor, pillars, `DraftGenerator` with Claude, "propose a
week" action, edit and approve flow, cost recording. Acceptance: a week of drafts
can be reviewed and approved in one sitting.

**M5 LinkedIn** - adapter, personal profile posting, per-channel variants from one
idea. Second LLM provider (OpenAI) selectable per workspace.

**M6 Insights** - metric polling, per-post and per-pillar summary, top posts fed
into drafting.

**M7 Hardening** - alerts, backups and restore test, token expiry handling, audit
trail UI. End of Phase 2.

**M8 SaaS foundations** - `saas` mode, registration, onboarding, entitlements
enforced, bring-your-own-key, data export and deletion.

**M9 Billing and approvals** - billing gateway, plans, regional pricing; Meta App
Review and LinkedIn Community Management submissions.

**M10 Launch** - public deployment, status page, legal pages, closed beta.

## 13. Decisions to confirm before M2

1. **Stack.** This brief assumes Laravel. If Symfony or Python is preferred, the
   architecture, interfaces and data model stand; only sections 5 and the package
   choices change.
2. **Licence.** AGPL-3.0 (hosted forks must share changes, compatible with learning
   from Postiz) or MIT (maximum reuse, no protection against closed hosted forks).
3. **Which legal entity runs the SaaS**, since it decides payment providers and
   which business Meta and LinkedIn verify.
4. **Media storage.** Local disk in Phase 1; S3-compatible storage for Phase 3.
5. **Scheduling authority.** This brief keeps all timing in our scheduler rather
   than using each platform's native scheduling, so that approval, cancellation and
   the audit trail stay in one place.
