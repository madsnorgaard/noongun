# Meta - Facebook Pages API

Findings for M1. Dated entries; newest last. Sources are official Meta documentation.

## 2026-11-23 - documentation review before the spike

**Graph API version.** v27.0 is the latest, released 2026-11-04.
Source: developers.facebook.com/docs/graph-api/changelog

**Long-lived user token.**
`GET /{version}/oauth/access_token?grant_type=fb_exchange_token&client_id=&client_secret=&fb_exchange_token=`.
Returns `access_token`, `token_type`, `expires_in` (about 60 days). An expired token
cannot be exchanged. Server side only, because it needs the app secret.
Source: developers.facebook.com/docs/facebook-login/guides/access-tokens/get-long-lived

**Page token.** `GET /{user-id}/accounts` (or `/me/accounts`) with the long-lived user
token returns `id`, `name`, `tasks` and `access_token` per Page. The user needs a role
on the Page. A Page token obtained from a long-lived user token "does not have an
expiration date" and expires only under certain conditions (password change, role
removed, permissions revoked). To be confirmed with `debug_token` in the spike.

**Token inspection.** `GET /debug_token?input_token=&access_token=` with an app token
(`{app-id}|{app-secret}`) from the same app. Returns `is_valid`, `type`, `scopes`,
`expires_at` (0 means never), `data_access_expires_at`.

**Text post.** `POST /{page-id}/feed` with `message`, optional `link`. Returns `{id}`
(the Page post id). `published=false` plus `scheduled_publish_time` (10 minutes to 30
days ahead) is native scheduling; we will not use it (brief section 13.5).
Source: developers.facebook.com/docs/pages-api/posts

**Photo post.** `POST /{page-id}/photos` with `url`. Returns `{id, post_id}`. The
posts page documents only `url`; a caption parameter and multipart `source` upload
are not documented there. The spike sends `message` as the caption and records
whether it appears.

**Permalink.** The posts page says the permalink is `https://www.facebook.com/{page_post_id}`.
It does not mention a `permalink_url` field. The spike requests `permalink_url` and
records whether it is returned.

**Delete.** `DELETE /{page_post_id}` returns `{success: true}`.

**appsecret_proof.** HMAC-SHA256 of the access token keyed with the app secret.
Recommended, not required unless "Require App Secret" is enabled under App settings
> Advanced. The spike sends it on every call; the adapter should too.
Source: developers.facebook.com/docs/graph-api/guides/secure-requests

### Differences from the brief (to resolve)

1. **Permissions.** The brief lists `pages_manage_posts`, `pages_read_engagement`,
   `pages_show_list`. The permissions reference agrees (`pages_manage_posts` depends
   on the other two). The Pages getting-started page also lists
   `pages_manage_metadata`, and the posts page lists `pages_manage_engagement` and
   `pages_read_user_engagement`. The "Manage everything on your Page" use case in
   the app dashboard adds `business_management` and `pages_manage_engagement` by
   default. The spike records the exact scopes on the working token; the adapter
   should request the minimum set that works.
2. **Development mode visibility.** The docs reviewed do not state whether posts
   made by an app in Development mode are visible to the public. The app modes page
   returned 404. The brief assumes standard access without App Review is enough for
   a Page the owner manages. This is the main question M1 must answer with a real
   post viewed from a logged-out browser.

## Spike results

To be filled in after running `scripts/spike/`.

The app modes page states that data created in Development mode "can only be seen
by role users". Whether a Business app in Live mode can still use `pages_manage_posts`
with Standard Access for its own role users, without App Review, is not stated. The
spike therefore posts twice: once in Development mode, once in Live mode.

- Date run:
- App type and use case:
- Scopes on the working user token:
- Page token `expires_at` from `debug_token`:

Run A - Development mode

- Text post id, permalink, `permalink_url` returned (yes or no):
- Photo post id, caption shown (yes or no):
- Visible from a logged-out browser (expected: no):
- Delete worked (text, photo):

Run B - Live mode (switch the app mode toggle in the dashboard toolbar, then get a
fresh Explorer token and rerun steps 01 to 06)

- What the dashboard required before it allowed Live mode (privacy policy URL, data
  deletion, category, icon, anything else):
- Did the Explorer still grant `pages_manage_posts` without App Review (yes or no):
- Text post published (yes, or the exact error):
- Visible from a logged-out browser (yes or no):
- Delete worked:

Conclusion for Phase 1 (one of):

- Live mode with Standard Access is enough. The brief stands.
- App Review for `pages_manage_posts` is needed even for our own Page. Raise it:
  Phase 1 then depends on App Review and probably Business Verification.

- Errors met and how they were resolved:

## 2026-11-23 - OAuth connect flow for a self-hosted instance

Findings for the in-app connect flow (issue #3). Meta pages show no "last updated"
date, so none is given. The spike scripts get tokens from the Graph API Explorer; the
app itself needs a real redirect flow.

### 1. Authorization dialog and code exchange

**Dialog.** Redirect the user's browser to:

```
https://www.facebook.com/v27.0/dialog/oauth?client_id={app-id}
&redirect_uri={redirect-uri}
&state={state-param}
```

Required: `client_id`, `redirect_uri`, `state` (CSRF guard). Optional: `response_type`
(`code` is the default and is what we want), `scope`, `auth_type=rerequest`. The Graph
API version in the path comes from `config/noongun.php`.
Source: developers.facebook.com/docs/facebook-login/guides/advanced/manual-flow

**How permissions are passed.**

- Classic Facebook Login: `scope`, a comma or space separated list, for example
  `scope=pages_show_list,pages_read_engagement,pages_manage_posts`.
- Facebook Login for Business: create a **configuration** in the dashboard and pass
  `config_id={configuration-id}` instead. For user access token configurations
  "config_id has replaced scope (although scope can still be included, we recommend
  that you do not use it)". For system-user token configurations `scope` "should not be
  used". The app must be "a business type app or an app with a use case that supports
  Facebook Login for Business". The docs give no full dialog URL with `config_id`, only
  the parameter itself, to be added to the manual flow URL above.

Source: developers.facebook.com/docs/facebook-login/facebook-login-for-business

Recommendation: start with `scope`. It works with the "Manage everything on your Page"
use case, keeps the request list in our config, and avoids one more dashboard step for
self-hosters. `config_id` can be added later if Meta stops honouring `scope` for
business apps. The spike must confirm that `scope` is accepted on an app created with
that use case.

**Code exchange.** Server side, because it needs the app secret:

```
GET https://graph.facebook.com/v27.0/oauth/access_token?client_id={app-id}
&redirect_uri={redirect-uri}
&client_secret={app-secret}
&code={code-parameter}
```

`redirect_uri` must be identical to the one used in the dialog. Returns
`access_token`, `token_type`, `expires_in`. This is a short-lived user token; it then
goes through the long-lived exchange and `/me/accounts` steps already described above.
Source: manual flow page (above)

### 2. Valid OAuth Redirect URI rules

- The list lives under **Facebook Login > Settings > Client OAuth Settings > Valid OAuth
  Redirect URIs** (manual flow page). With the "Manage everything on your Page" use case
  and Facebook Login for Business it is under **Facebook Login for Business > Settings**,
  where there is also a **Check URI** button (developers.facebook.com/documentation/pages-api/create-an-app).
- Strict Mode is required for all apps: "The full URI must be an exact match, including
  all parameters", except `state`, whose value is ignored. Extra query parameters are
  refused (developers.facebook.com/docs/facebook-login/security).
- HTTPS: "Enforce HTTPS ... requires HTTPS for OAuth Redirects". It has been on by
  default for all apps created since March 2018 (security page).
- `http://localhost`: the only official statement found is a 2018 developer blog post:
  "You will still be able to use HTTP with "localhost" addresses, but only while your
  app is still in development mode."
  (developers.facebook.com/blog/post/2018/06/08/enforce-https-facebook-login/). No
  current docs page repeats or withdraws it. Treat it as probably still true for
  Development mode only.
- Private hostnames such as `noongun.tail1234.ts.net`: not mentioned anywhere. The rules
  are about exact match and HTTPS, not about reachability. The redirect happens in the
  user's browser and only our server calls `graph.facebook.com`, so Meta never needs to
  reach the host. `tailscale serve` gives the hostname a valid public certificate.
  Likely accepted, but unconfirmed. The **Check URI** button and a real login in the
  spike will settle it.

### 3. Does each self-hosting user need their own Meta app

Yes, and the docs support the brief here:

- "Permissions with Standard Access can only be requested from app users who have a role
  on the requesting app." An app used only by people with a role on it needs only
  Standard Access (developers.facebook.com/docs/graph-api/overview/access-levels).
- Serving anyone without a role needs Advanced Access through App Review, and serving
  Pages of other businesses needs business verification and the Tech Provider step
  (create-an-app page).

A shared "Noon Gun" app for all self-hosters would therefore need App Review and
business verification, and would put our app secret in every install. Each self-hoster
creates their own app and is its administrator.

**Development mode and public visibility.** The app modes page now loads (it returned
404 at the earlier review). It says: "Any data generated while an app is in Development
mode, such as test posts, can only be seen by role users. However, that data will be
visible to non-role users once the app is switched to Live mode." and "All newly
created apps start out in Development mode". It also says "Apps in Live mode can request
permissions from anyone, but only permissions approved through App Review", while for
Consumer apps "permissions with Standard Access can only be requested from role users".
Source: developers.facebook.com/docs/development/build-and-test/app-modes

This answers part of open question 2 above: posts made in Development mode are not
public. It raises a new one: whether a business-type app in Live mode can still use
Standard Access permissions for its own role users without App Review. The page
describes this only for Consumer apps. See the differences list below.

**Onboarding checklist for a self-hoster (Meta).** Field and screen names are from the
pages cited above; the spike must check them against the live dashboard.

1. Log in to Facebook with the account that manages the Page. That account must have
   full control of the Page (be able to create content on it).
2. Go to developers.facebook.com and click **Get Started**. Accept the Platform Terms
   and Developer Policies, confirm your phone number and email address with the codes
   Meta sends, and pick an occupation
   (developers.facebook.com/docs/development/register).
3. Go to developers.facebook.com/apps/creation/.
4. **App details:** enter an app name (for example "Noon Gun - <your name>") and your
   contact email. Click **Next**.
5. **Use cases:** select **Manage everything on your Page**. Click **Next**.
6. **Business:** choose **I don't want to connect a business portfolio yet**, unless you
   already have one. Click **Next**.
7. **Requirements:** click **Next**.
8. **Overview:** check the details and click **Go to dashboard**.
9. In the dashboard, open the **Manage everything on your Page** use case and click
   **Customize**. Under **Permissions and features**, make sure `pages_show_list`,
   `pages_read_engagement` and `pages_manage_posts` are added. Click **Add** next to any
   that are missing.
10. In the left menu, open **Facebook Login for Business > Settings** (or **Facebook
    Login > Settings** if that is what the app shows). In **Valid OAuth Redirect URIs**,
    paste the exact callback URL Noon Gun shows in its setup wizard, for example
    `https://noongun.tail1234.ts.net/auth/facebook/callback`. Click **Check URI**, then
    **Save changes**. Leave **Enforce HTTPS** and **Use Strict Mode for redirect URIs**
    on.
11. In the left menu, open **App settings > Basic**. Copy the **App ID** and the **App
    secret** (click **Show**) into the Noon Gun setup wizard. Do not share the secret
    anywhere else.
12. Still under **App settings > Basic**, fill in a **Privacy Policy URL**, an **App
    icon** (512x512 to 1024x1024 px) and a category. The **Data Deletion Request URL**
    sits in the Facebook Login for Business settings from step 10. These are needed to
    publish the app. Noon Gun can serve a privacy page
    and data deletion instructions for this.
13. In Noon Gun, click **Connect Facebook**, log in, choose the Page and grant all
    requested permissions.
14. Publishing to Live mode: until the open question below is answered by the spike,
    the wizard should not tell users to switch. If the spike shows that posts must be
    made in Live mode to be public, add a step here: **Publish** in the left menu, then
    **Publish**.

### Differences from the brief (to resolve)

3. **Public visibility needs Live mode.** The brief (section 8) assumes the owner's own
   Page works with standard access and no App Review, and M1 is to prove posts are
   public. The app modes page says posts made while the app is in Development mode are
   seen only by people with a role on the app. So Phase 1 needs the app in Live mode.
   Whether a business-type app in Live mode keeps Standard Access permissions for its
   own role users without App Review is not stated for business apps. If it does not,
   Phase 1 needs App Review for `pages_manage_posts`. This is now the main question for
   M1.
4. **`config_id` versus `scope`.** Not in the brief. Meta recommends `config_id` for
   Facebook Login for Business. We plan to use `scope` unless the spike shows it is
   refused.

## 2026-11-24 - Facebook adapter for M3

What was checked again in Meta's documentation while building
`App\Domain\Channels\Adapters\Facebook\FacebookAdapter`, and what was decided.
The spike has still not been run: nothing below is confirmed against the real API.
`tests/Fixtures/facebook/README.md` lists every response shape the tests assume.

### Verified in the documentation

**Read-after-write.** `POST /{page-id}/feed` "supports read-after-write and can
immediately return any fields returned by read operations": adding
`fields=id,permalink_url` to the POST would return the permalink in the same call. "If
the read fails for any reason ... the Graph API will respond with a standard error
response."
Sources: developers.facebook.com/docs/graph-api/reference/page/feed,
developers.facebook.com/docs/graph-api/overview

**`permalink_url` exists.** The Page Post reference lists it: "The permanent static URL
to the post on www.facebook.com." The posts guide still gives
`https://www.facebook.com/{page_post_id}` as the permalink. This answers half of the
earlier open point; whether the two addresses differ is for the spike.
Source: developers.facebook.com/docs/graph-api/reference/page-post

**Permissions for publishing.** The feed reference lists exactly the three scopes in the
brief: `pages_manage_posts`, `pages_read_engagement`, `pages_show_list`, with a Page
token from someone who can perform the `CREATE_CONTENT` task. The posts guide lists a
longer set (difference 1 above). The adapter requests the three, from
`FACEBOOK_SCOPES`.

**No body length in the API documentation.** Neither the posts guide nor the feed
reference gives a limit for `message`. "Either link or message must be supplied."

**Errors.** The envelope is `error.message`, `type`, `code`, `error_subcode`,
`error_user_title`, `error_user_msg`, `fbtrace_id`. Codes: 1 unknown (retry), 2 service
(retry), 4 and 17 throttling, 10 and 200 to 299 permission, 102 session, 190 token
expired, 341 application limit, 368 temporarily blocked for policy violations, 506
duplicate post, 1609005 link could not be read. Subcodes 458, 459, 460, 463, 464, 467
and 492 all mean the token or the user's access is no longer good. The guide does not
give HTTP status codes and does not mention `is_transient`.
Source: developers.facebook.com/docs/graph-api/guides/error-handling

**Rate limits.** Calls with a Page token fall under business use case limits: "Calls
within 24 hours = 4800 * Number of Engaged Users", where engaged users are those who
engaged with the Page in 24 hours. Codes 4, 17, 32, 613 and 80000 to 80014 (80001 for
Pages). The `X-Business-Use-Case-Usage` header carries
`estimated_time_to_regain_access` in minutes.
Source: developers.facebook.com/docs/graph-api/overview/rate-limiting

**Page tokens.** "Long-lived Page access token do not have an expiration date and only
expire or are invalidated under certain conditions." There is no call that renews one.
Source: developers.facebook.com/docs/facebook-login/guides/access-tokens/get-long-lived

**Denied consent.** The browser comes back with `error=access_denied`,
`error_reason=user_denied` and `error_description`. `state` is returned unchanged.
Source: developers.facebook.com/docs/facebook-login/guides/advanced/manual-flow

### Decisions

1. **Publishing does not use read-after-write yet.** `publish()` sends `message` and
   `link` only, reads `id` from the answer and builds the permalink as
   `{FACEBOOK_DIALOG_URL}/{id}`, which is the form the posts guide documents. Reason:
   the documentation does not say whether a post stays published when the read part
   fails. If it does, the adapter would see an error for a post that is public, and a
   retry would publish it twice, which is the one failure this system must not have.
   The spike should make one POST with `fields=id,permalink_url` and one with a field
   that does not exist, and record what happens to the post. If a failed read never
   leaves a post behind, switch to read-after-write.
2. **Only Page tokens are stored.** The short-lived and long-lived user tokens are
   used during `connect()` and dropped. `refresh_token` stays empty for Facebook.
3. **Expiry and scopes come from `debug_token`.** `connect()` inspects each Page token
   with the app token and stores `expires_at` (`0` becomes no expiry) and `scopes`. If
   a scope from `FACEBOOK_SCOPES` is missing, nothing is saved and the user is told
   which permission to allow. This depends on `scopes` being filled for a Page token,
   which the spike prints in step 02.
4. **`refreshToken()` checks, it does not renew.** It calls `debug_token`, updates
   expiry and scopes, and marks the channel as needing a reconnect when the token is
   invalid, expired or has lost a scope. A failed check (rate limit, outage) changes
   nothing. A channel that needs a reconnect becomes active again only by connecting.
5. **Pages the user cannot publish to are not connected.** A Page whose `tasks` lacks
   `CREATE_CONTENT` is skipped. If `tasks` is absent the Page is kept.
6. **`/me/accounts` paging.** The adapter asks for 100 Pages at a time and continues
   with `paging.cursors.after` while `paging.next` is present. It never requests
   `paging.next` itself, because that URL holds the user token.
7. **`scope`, not `config_id`,** as recommended above, plus `auth_type=rerequest` so
   that a permission declined earlier is asked for again.
8. **Redirect address.** `{APP_URL}/auth/facebook/callback`, built from `APP_URL` and
   not from the request, because Meta compares it exactly. The Channels page shows it.
9. **`appsecret_proof`** is sent on every call that carries a user or Page token. It
   is not sent with the app token on `debug_token`, as in the spike scripts.
10. **Error mapping** (`GraphClient`). Reconnect: codes 102, 190, 10, 200 to 299 and
    the subcodes listed above. Everything that is neither a reconnect nor a retry is
    permanent, including 368: Meta says to wait and retry, but a policy block should
    be looked at by a person and not retried by a job.

    What counts as a retry depends on the call, because `PublishPostJob` resends on
    `retryable` alone and a second public post is the worst failure there is. Every
    call to `GraphClient::send()` states whether it is safe to repeat, and the default
    is that it is not. (Revised later on 2026-11-24; the first version retried
    timeouts and 5xx on publish too.)

    - **Publish (`POST /{page-id}/feed`), not safe to repeat.** Retryable only when
      Facebook certainly made no post:
      - a Graph error with a rate limit code: 4, 17, 32, 613 or 80000 to 80014,
        whatever the HTTP status, with the wait from `X-Business-Use-Case-Usage` or
        `Retry-After` when given. These are documented purely as throttling, so the
        call was turned away and not attempted;
      - a connection that was never made: host or proxy not resolved, connection
        refused, TLS handshake failed. Guzzle 8 reports these as `ConnectException`.

      Not retryable, with "the outcome is unknown, check the Page before trying
      again" and `outcome_unknown` in the context:
      - any timeout, also one reported as a connect timeout (Guzzle tells the two
        apart by the text of the curl error, which is not a guarantee), and any
        connection that broke after it was made;
      - codes 1 (unknown), 2 (service) and 341 (application limit). Meta describes
        all three as "temporary downtime" (341 as "downtime or throttling"), which
        does not say how far the call got;
      - any 5xx, and a 429, without a rate limit code in a readable Graph error;
      - a 2xx answer that cannot be read or that has no post id.
    - **Token exchange, `/me/accounts`, `debug_token`, delete: safe to repeat.**
      Retryable for all of the above: rate limit codes, codes 1, 2 and 341, HTTP 429,
      any 5xx and every network failure.
11. **Capabilities.** Body: 63,206 characters. This is not in the Graph API
    documentation; it is the limit Facebook announced for posts in 2011 and is still
    widely reported. Setting `max_body_length` in the `facebook` entry of
    `config/noongun.php` replaces it. Media: none, because photo posts are not part of
    M3. Link previews: yes. Rate limit: 4800 calls in 24 hours, which is the documented
    formula at one engaged user; the real limit is higher for a Page with an audience.
12. **UTM values** on a variant are added to the link by the adapter when it
    publishes. A value already on the link is kept.

### Open questions for the spike

- Does a failed read-after-write leave the post published (decision 1)?
- Does `debug_token` return the granted permissions in `scopes` for a Page token, and
  `expires_at` `0` (decision 3)?
- Does a network timeout or a 5xx on `POST /{page-id}/feed` ever follow a post that
  was created? The adapter assumes it can, and does not retry (decision 10). Meta
  documents no idempotency key for this call. A post that fails this way needs a
  person to look at the Page; ADR 0009 leaves an automatic check to M7.
- Do the rate limit codes really mean that nothing was created? The documentation
  describes them only as throttling, and the adapter relies on that.
- Which HTTP status do Graph errors carry? The mapping uses the error code first, so
  only 429 and 5xx without a rate limit code depend on it.
- Does `data_access_expires_at` (about 90 days on the token) stop a Page token from
  publishing when it passes? If so it must be stored and shown as the expiry.

## 2026-11-24 - review fixes: confirmation before a Page can publish, and credentials in URLs

### A Page is connected only after the user confirmed it

The first version of `connect()` saved every Page Facebook returned as active, before
the selection step. A Page the user had disconnected came back with a new token as
soon as anyone reconnected another Page, and stayed connected if the selection was
left open. Changed as follows.

- New channel status `pending`: saved by a connect, holds the Page token, cannot
  publish (`Channel::canPublish()` is false for it).
- `Channel::recordConnection()` decides the status for every adapter. A channel that
  is `active` or `needs_reconnect` was chosen by the user and not disconnected since:
  it is renewed and active at once, so it keeps publishing while the user is asked
  about other Pages. A new Page, a `disconnected` one and one that is still `pending`
  are saved as `pending`.
- Only the user's choice makes a pending channel active (`Channel::confirm()`).
- One Page that is new to the workspace is confirmed without a question, because the
  user granted exactly that Page in the dialog a moment ago.
- One Page that was disconnected earlier is asked about. Reason: the grant stays with
  Facebook after a disconnect in Noon Gun, so the dialog can return the Page without
  the user picking it again. Whether it really does is a question for the spike (see
  the fixture README); asking is the safe side either way.
- A pending channel that is not confirmed is discarded: deleted, or set back to
  `disconnected` with its token erased when post variants point at it. This happens
  when the user declines, when a new connect starts, when the choice is older than 15
  minutes (`Channel::PENDING_MINUTES`), and from the scheduled command
  `noongun:discard-pending-channels` when nobody comes back at all.
- The selection can only ever touch channels that are pending and were returned by
  that connect. It cannot disconnect a channel that was already connected.

The Page token of a pending channel is therefore stored, encrypted, for at most about
20 minutes (15 minutes plus the five minute schedule) without a confirmation. Holding
it somewhere is unavoidable: the code can be exchanged only once, so the token cannot
be fetched again after the user has chosen.

### Can credentials be kept out of the URL

Checked on 2026-11-24. No "last updated" dates are shown on these pages.

| Question | What the documentation says | Source |
|---|---|---|
| Is `Authorization: Bearer` accepted for Graph API or Pages API calls? | Not stated anywhere for these APIs. Every example on the access token guide, the Graph API overview and the Pages getting-started page passes `access_token` as a parameter. Only the WhatsApp Business documentation, a different product on the same host, uses `Authorization: Bearer` in its examples, and no page says the two forms are interchangeable. | developers.facebook.com/docs/facebook-login/guides/access-tokens, developers.facebook.com/docs/graph-api/overview, developers.facebook.com/docs/pages-api/getting-started |
| May a POST carry `access_token` and `appsecret_proof` in its body? | Yes. The Pages getting-started example for publishing sends `access_token` in the request body, and the secure requests page shows `access_token` and `appsecret_proof` as form fields of a POST. | developers.facebook.com/docs/pages-api/getting-started, developers.facebook.com/docs/graph-api/guides/secure-requests |
| Do the token endpoints (`/oauth/access_token` for the code exchange and for `fb_exchange_token`) accept POST with a form body? | Not stated. Only GET with query parameters is shown. | developers.facebook.com/docs/facebook-login/guides/advanced/manual-flow, developers.facebook.com/docs/facebook-login/guides/access-tokens/get-long-lived |
| Does `debug_token` accept POST or a header? | Not stated. The reference gives only `GET /debug_token?input_token=...`. | developers.facebook.com/docs/graph-api/reference/debug_token |
| Can a GET or DELETE carry its parameters in a body? | Not stated. The secure requests example reaches GET calls through a batch request, which is a POST with the credentials as form fields. | developers.facebook.com/docs/graph-api/guides/secure-requests |

What follows for the adapter:

- **Publish** (`POST /{page-id}/feed`) already sends the Page token and
  `appsecret_proof` in the form body. Its URL holds no credential, and a test asserts
  the exact URL.
- **Code exchange, long-lived exchange, `/me/accounts`, `debug_token`, delete** stay
  as documented, with their credentials in the query string. The documentation does
  not support any other form for them, and an undocumented form that fails would break
  connecting. Nothing was changed for these calls.
- Our own failure path does not leak them: exceptions are rebuilt without the URL. The
  remaining exposure is anything that records outgoing URLs: a proxy, HTTP client
  events, Telescope if it is added. Until the spike has answered the questions below,
  do not log outgoing request URLs for the Graph host.
- The documented way to move GET and DELETE credentials into a body is a batch
  request. It changes every response shape (an array of `code`, `headers`, `body`), so
  it is not adopted before the spike has shown the real shapes.

Questions for the spike, also listed in `tests/Fixtures/facebook/README.md`:

1. Is `Authorization: Bearer {token}` accepted on `GET /me/accounts`, on
   `DELETE /{post-id}` and on `GET /debug_token` (with the app token), with
   `appsecret_proof` still as a parameter?
2. Do the two `/oauth/access_token` calls work as POST with a form body?
3. Does `GET /debug_token` accept `input_token` anywhere but the query string?
4. After a Page was disconnected in Noon Gun only, does the login dialog return it
   again without the user selecting it?

## 2026-11-26 - first real connect returned 3 of 5 granted Pages

Observed, not from documentation. On the first connect against the real Graph API the
owner ticked five Pages in Facebook's consent screen. `GET /me/accounts` listed three.
Nothing in the answer said that two were left out, and Noon Gun said nothing either.

**Cause: the two Pages are held in business portfolios** (confirmed the same day, see
"Confirmed by a second connect" below). `/me/accounts` leaves such Pages out unless
the app also has `business_management`. The 2026-11-23 entry notes that the "Manage
everything on your Page" use case adds `business_management` by default;
`FACEBOOK_SCOPES` does not request it by default. No documentation page was found that
states this behaviour; it rests on our own connect.

What the adapter does since this date:

- A Page that `/me/accounts` lists but that cannot be connected is no longer dropped
  silently. It is reported with its id, its name and a reason: `tasks` lacks
  `CREATE_CONTENT` (role), or the entry has no `access_token` (no access). No token is
  kept for it. The connect pages show the list.
- A Page that is not listed cannot be known. The connect pages therefore always show
  one line about business portfolios and `business_management`, and the README section
  "A Page is missing" lists the causes.

### Confirmed by a second connect

Observed on 2026-11-26 against the real Graph API, with `business_management` added to
the use case in the Meta app and to `FACEBOOK_SCOPES`:

- The Facebook dialog showed an extra consent step, **Choose the Businesses**. The
  least that is needed there is **Opt in to current Businesses only**, with the
  portfolios that hold the Pages ticked.
- `/me/accounts` then returned the two Pages that were missing before. Both are held
  in business portfolios.
- Connect succeeded, and four Pages are connected and active. So `debug_token` lists
  `business_management` in `data.scopes` of a Page token, and the check in `connect()`
  that refuses a Page whose token lacks a configured scope passes with
  `business_management` in `FACEBOOK_SCOPES`. This closes that question.

Still to confirm:

1. Does `/me/accounts` ever return an entry without `access_token`? The "no access"
   reason exists for that case; it has not been seen in a real answer.
2. Development mode visibility, as raised in the 2026-11-23 entry: whether posts made
   by an app in Development mode are visible to the public.

## 2026-11-26 - reading the numbers of a post (M6)

What was read in Meta's documentation on 2026-11-26 before the metrics contract was
designed (`App\Domain\Channels\Contracts\ReadsMetrics`, ADR 0016). Every reference page
showed Graph API `v27.0`, the configured version. Nothing below has been run against
the real API: `FacebookAdapter::fetchMetrics()` is not built yet, and
`tests/Fixtures/facebook/insights/README.md` lists what a first real call must show.

### Confirmed in the documentation

**Reactions, comments and shares are read from the post itself.**

- `reactions` is an edge of the post. Its total is asked for with the summary:
  `GET /{post-id}?fields=reactions.summary(total_count)`, which answers with
  `reactions.summary.total_count`, "Total number of reactions" (unsigned int32). Without
  `limit(0)` the answer also lists the people who reacted, in `data`, which Noon Gun
  must not ask for.
- `comments` is an edge with `summary` (bool). With it the answer holds `total_count`
  and `order`. `filter=toplevel` is the default and counts top-level comments;
  `filter=stream` counts "all comments (including replies)". The count "can be greater
  than or equal to the actual number of comments returned" because of privacy and
  deletions.
- `shares` is a field of the post, a struct with `count`: "Number of times the post has
  been shared". The reference shows no example answer. Whether the field is absent or
  zero for a post nobody shared is not stated.

Sources: developers.facebook.com/docs/graph-api/reference/page-post,
developers.facebook.com/docs/graph-api/reference/v27.0/object/reactions,
developers.facebook.com/docs/graph-api/reference/v27.0/object/comments

**Reach and clicks are post insights, and the old names are gone.**

- `GET /{post-id}/insights?metric={metric-1},{metric-2}` answers with `data` (a list of
  InsightsResult) and `paging`. `period` can be `day`, `week`, `days_28`, `month`,
  `lifetime` or `total_over_range`.
- `post_clicks` ("clicks anywhere on a post that did not generate a story") and
  `post_clicks_by_type` exist in v27.0, with `lifetime` as their only period. They are
  not on the list of deprecated metrics.
- `post_impressions` and `post_impressions_unique` are deprecated for all API versions:
  `post_impressions_unique` since 15 June 2025 ("Alternative:
  post_total_media_view_unique"), `post_impressions` since 15 November 2025
  ("Alternative: post_media_view"). The insights reference marks them "deprecated above
  Graph API v25" and says a deprecated metric answers with "an invalid metric error".
  The paid, fan, organic, viral and nonviral variants are deprecated as well.
- What exists instead: `post_media_view`, the "times content was played or displayed"
  (breakdowns `is_from_ads`, `is_from_followers`), and `post_total_media_view_unique`,
  the unique viewers of the post. So "reach" in Noon Gun is
  `post_total_media_view_unique`. Views (`post_media_view`) are not stored; the brief
  names reach.
- Reactions by type are also insights (`post_reactions_like_total` and so on,
  `post_reactions_by_type_total`). Not needed: the edge gives the total without the
  insights permission.

Sources: developers.facebook.com/docs/graph-api/reference/v27.0/insights,
developers.facebook.com/docs/platforminsights/page/deprecated-metrics

**Insights need one more permission.** The insights reference requires `read_insights`
and `pages_read_engagement`, with a Page token of someone who can perform the `ANALYZE`
task on the Page. The permissions reference: `read_insights` "allows your app to read
the Insights data for Pages, apps and web domains the person owns" and depends on
`pages_read_engagement` and `pages_show_list`. `FACEBOOK_SCOPES` does not request
`read_insights` today. So a Page as it is connected now gives reactions, comments and
shares, and **reach and clicks need `read_insights` added to the Meta app and to
`FACEBOOK_SCOPES`, and the Page connected again.** Until then they are stored as "not
given" (null), never as zero.

**Limits of insights.** "Page Insights data is only available for Pages with 100 or more
likes." Most metrics update once every 24 hours; the ones marked with a star in the
reference, `post_clicks` among them, several times a day. Only the last two years are
kept. `_unique` metrics are approximate. When a request times out or metrics are
missing, the advice is to ask for fewer metrics in one request. A missing `metric`
parameter is error 3001 with subcode 1504028; an invalid parameter is error 100; error
200 is "Permissions error".

**Reading many posts in one request does not save calls.** "Each call within the batch
is counted separately for the purposes of calculating API call limits and resource
limits", and the rate limiting page says the same of ids combined in one request: three
ids in `ids=` count as three calls. Combining is recommended only because it answers
faster. A batch holds at most 50 requests, needs an access token as a top-level
parameter, reports the failure of one request in that request's own `code` and `body`
while the others complete, and returns `null` for requests it did not finish in time.

Sources: developers.facebook.com/docs/graph-api/batch-requests,
developers.facebook.com/docs/graph-api/overview/rate-limiting

**Rate limit.** Unchanged from the 2026-11-24 entry: calls with a Page token fall under
the business use case limit, "Calls within 24 hours = 4800 * Number of Engaged Users",
error 80001 for Pages, and `X-Business-Use-Case-Usage` with `call_count`,
`total_cputime`, `total_time` (each a percentage) and `estimated_time_to_regain_access`
in minutes. The advice is to stop calling once the limit is reached, because further
calls make the wait longer.

### Not confirmed

1. **Which permission the reactions and comments edges of a Page post need.** The Page
   Post reference names no permission for reading; its error 283 speaks of
   `pages_read_engagement` "and/or" `pages_read_user_content`. The reactions reference
   lists `pages_show_list` for "Page management apps". The comments reference says
   "the same permissions required to view the parent object", and that reading comment
   ids needs the `MODERATE` task. Assumed: the totals are readable with
   `pages_read_engagement`, which every connected Page has. If the comment total turns
   out to need `pages_read_user_content`, comments become a metric that needs a
   permission, like reach.
2. **The `ids=` form.** That `GET /?ids={id1},{id2}&fields=...` answers with an object
   keyed by id is not on any page that was read, and neither is a limit of 50 ids. The
   batch endpoint is documented and is the safe choice.
3. **The answer of the insights edge.** The reference shows only `{"data": [],
   "paging": {}}`. That each entry holds `name`, `period` and `values` with one `value`
   for `lifetime` is how the Graph API has answered for years; it is from memory.
4. **What a Page with fewer than 100 likes gets**: an error, or an empty `data`. The
   adapter must treat both as "reach and clicks not given".
5. **What a missing `read_insights` looks like**: presumably error 200 or 10, which
   `GraphClient` reports as "connect again". For metrics that must not happen: the
   adapter does not ask for insights at all when the channel lacks the scope, and a
   permission error on the insights call alone leaves reach and clicks null.
6. **Whether insights can be nested as a field** (`fields=insights.metric(post_clicks)`)
   so that one request per post is enough. Not documented on the pages that were read.
   `metricCallsPerVariant()` is 2 until this is known.
7. **Whether a deleted post answers with error 100 and subcode 33**, as other objects
   do. The adapter must report such a variant as "no reading", not as a failure of the
   whole call.

### Decisions

1. Five numbers are stored: reactions, comments, shares, clicks (`post_clicks`,
   lifetime) and reach (`post_total_media_view_unique`, lifetime). Whether comments are
   counted with or without replies is decided when the call is built, and written here.
   Metric names are Meta's and change; they belong in the adapter's settings under
   `noongun.channels.facebook`, not in logic.
2. The name of the insights permission is the setting `insights_scope`
   (`read_insights`). `FacebookAdapter::metricsFor()` gives reach and clicks only for a
   channel whose stored scopes hold it.
3. Reading is budgeted in calls, not requests, because Meta counts every id:
   `metricCallsPerVariant()` is 2, and `FetchMetricsJob` spends at most
   `NOONGUN_INSIGHTS_RATE_LIMIT_PERCENT` of the 4800 calls a day that the adapter
   declares, which is the limit at one engaged user.
4. No list of people is ever asked for: `limit(0)` on both edges. Noon Gun stores
   counts only.
