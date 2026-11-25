# LinkedIn - personal profile posting

Findings for M5 (issue #5). Dated entries; newest last. Sources are official LinkedIn
documentation on learn.microsoft.com/linkedin and linkedin.com. Each answer names the
page it comes from and the date that page reports as last updated.

## 2026-11-23 - documentation review before building the adapter

### 1. Which product grants `w_member_social`

**Share on LinkedIn** is the self-serve product that grants `w_member_social`. It is an
"Open Permission", available to all developers without approval, added under the
Products tab of the app in the Developer Portal.
Sources:
learn.microsoft.com/en-us/linkedin/shared/authentication/getting-access (updated 2026-06-03),
learn.microsoft.com/en-us/linkedin/consumer/integrations/self-serve/share-on-linkedin (updated 2023-12-14)

**Member id for the author URN.** The Share on LinkedIn page says the `author` is the
Person URN and points to **Sign In with LinkedIn using OpenID Connect** for how to get
it. That product is also self-serve and grants `openid`, `profile` and `email`. With
`openid profile` in the scope, `GET https://api.linkedin.com/v2/userinfo` returns
`sub`, and the ID token carries the same `sub` claim. The author URN is then
`urn:li:person:{sub}`.
Source: learn.microsoft.com/en-us/linkedin/consumer/integrations/self-serve/sign-in-with-linkedin-v2 (updated 2024-08-08)

So yes, both products are needed: Share on LinkedIn for posting, OpenID Connect to learn
who the member is. The other documented route, `GET /v2/me`, needs `r_basicprofile`,
which only comes with the vetted Advertising or Community Management products.

Ambiguous: no page says in one sentence "`sub` equals the id in `urn:li:person:{id}`".
The OpenID discovery document lists `subject_types_supported: ["pairwise"]`, which
normally means the identifier is specific to the app. Since we post with the same app
that signed the member in, this should not matter, but the spike must confirm that a
post with `urn:li:person:{sub}` as author succeeds.

Scope to request: `openid profile w_member_social`. `email` is not needed.

### 2. Posts API

**Endpoint.** `POST https://api.linkedin.com/rest/posts`. The Posts API page says it
replaces the ugcPosts API.
Source: learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api (updated 2026-05-13, default view li-lms-2026-11)

**Headers.** All calls need:

- `Authorization: Bearer {token}`
- `Linkedin-Version: YYYYMM`. The latest version is `202611` (November 2026). Each
  version is supported for at least one year. A missing or sunset version returns an
  error; there is no default. `202601` sunsets on 2027-01-15.
- `X-Restli-Protocol-Version: 2.0.0`
- `Content-Type: application/json` on writes

Source for versioning: learn.microsoft.com/en-us/linkedin/marketing/versioning (updated 2026-11-16)

The version value belongs in `config/noongun.php`, not in code (CLAUDE.md rule 5). Plan
to bump it at least once a year.

**Minimal text post.** From the Posts API page, with the author changed to a person:

```json
{
  "author": "urn:li:person:{sub}",
  "commentary": "Sample text Post",
  "visibility": "PUBLIC",
  "distribution": {
    "feedDistribution": "MAIN_FEED",
    "targetEntities": [],
    "thirdPartyDistributionChannels": []
  },
  "lifecycleState": "PUBLISHED",
  "isReshareDisabledByAuthor": false
}
```

`commentary` uses the "little" text format (mentions are written as
`@[Name](urn)`, hashtags come back as a template). Some reserved characters in user
text will need escaping. The little text format page was not read for this review;
read it and record the escaping rules before M5.

**Link (article) post.** The Posts API does not scrape URLs. The caller must supply
`source`, `title`, and optionally `description` and a `thumbnail` image URN uploaded
through the Images API first:

```json
{
  "author": "urn:li:person:{sub}",
  "commentary": "test article post",
  "visibility": "PUBLIC",
  "distribution": {
    "feedDistribution": "MAIN_FEED",
    "targetEntities": [],
    "thirdPartyDistributionChannels": []
  },
  "content": {
    "article": {
      "source": "https://example.com/page",
      "thumbnail": "urn:li:image:C49klciosC89",
      "title": "Title shown on the card",
      "description": "Optional description"
    }
  },
  "lifecycleState": "PUBLISHED",
  "isReshareDisabledByAuthor": false
}
```

This means Noon Gun must fetch the page title and image itself (Open Graph tags) if it
wants a link card. A plain URL inside `commentary` is the simple alternative.

**Image post.** Three steps.
Source: learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/images-api (updated 2026-06-24)

1. `POST https://api.linkedin.com/rest/images?action=initializeUpload` with
   ```json
   { "initializeUploadRequest": { "owner": "urn:li:person:{sub}" } }
   ```
   The response holds `value.uploadUrl`, `value.uploadUrlExpiresAt` and `value.image`
   (`urn:li:image:...`).
2. Upload the binary to `uploadUrl` with the same bearer token. The Images API page
   defers to the Assets API page for this step. The Share on LinkedIn page describes
   it as "send a `POST` request to the `uploadUrl`" but its example is
   `curl --upload-file`, which sends a `PUT`. The spike should record which method
   works.
3. `POST /rest/posts` with
   ```json
   "content": { "media": { "id": "urn:li:image:...", "altText": "..." } }
   ```
   added to the text post body.

Limits: JPG, GIF or PNG, under 36,152,320 pixels, GIF up to 250 frames. A token with
only `w_member_social` can upload but cannot `GET /rest/images` on the versioned API.
For member-owned images "the caller must match the image owner".

**Conflict between pages.** The Share on LinkedIn page (last updated 2023-12-14) still
documents the legacy `POST /v2/ugcPosts` and `POST /v2/assets?action=registerUpload`.
The Posts API page says Posts replaces ugcPosts and lists `w_member_social` as a valid
permission, but it lives under the Marketing (Community Management) docs. No page says
in so many words that an app with only Share on LinkedIn may call `/rest/posts`. The
spike must test `/rest/posts` with a self-serve app first and fall back to `/v2/ugcPosts`
only if it is refused.

### 3. Permalink of a created post

A successful create returns `201 Created`; the response header `x-restli-id` holds the
post URN, either `urn:li:share:{id}` or `urn:li:ugcPost:{id}`. Store the full URN, not
just the number.
Source: Posts API page (above)

The only URL form documented is in the dark posts section of the same page:
`https://www.linkedin.com/feed/update/urn:li:ugcPost:<id>/`. It is shown for ugcPost
URNs only. Using `https://www.linkedin.com/feed/update/{urn}/` for share URNs as well
is a reasonable guess but is not documented; the spike must open both forms in a
logged-out browser.

### 4. Delete

`DELETE https://api.linkedin.com/rest/posts/{url-encoded post URN}` with the version and
protocol headers and `X-RestLi-Method: DELETE`. Returns `204`. Deletion is idempotent:
deleting an already deleted post also returns `204`. Batch delete is not supported.
Source: Posts API page (above)

### 5. Token lifetimes and refresh

- Access tokens: "Currently, all access tokens are issued with a 60-day lifespan."
- Requesting a different scope than before invalidates all earlier access tokens for
  that member.
- The authorization code lives 30 minutes.
- Refresh tokens: "Programmatic refresh tokens are available for a limited set of
  partners." The refresh token page says they are for "approved Marketing Developer
  Platform (MDP) partners". A self-serve app with Share on LinkedIn and OpenID Connect
  will not get a `refresh_token`.
- Without refresh tokens, renewal means sending the member through the authorization
  URL again. LinkedIn skips the consent screen and redirects straight back if the
  member is still logged in to linkedin.com and the current token has not expired.
- For approved partners: refresh tokens last 365 days from the first authorization and
  are not extended by refreshing; each refresh gives a new 60-day access token.

Sources:
learn.microsoft.com/en-us/linkedin/shared/authentication/authorization-code-flow (updated 2026-05-15),
learn.microsoft.com/en-us/linkedin/shared/authentication/programmatic-refresh-tokens (updated 2025-10-08)

Consequence for Noon Gun: a LinkedIn channel needs a human click roughly every 60 days.
The "token expires within 7 days" alert (brief section 11) is the main mechanism, and
the UI should offer a one-click "reconnect" that reuses the same scope so the consent
screen is skipped. Plan column sizes for tokens of at least 1000 characters, as the
docs recommend.

### 6. Redirect URI rules

From the 3-legged OAuth page (Step 1):

- Add the redirect URL "via `HTTPS`" under the app's **Auth** tab.
- URLs must be absolute. Query parameters are ignored. A `#` is not allowed.
- The `redirect_uri` sent must match one registered in the app, and the same value must
  be sent again in the token exchange.

Source: learn.microsoft.com/en-us/linkedin/shared/authentication/authorization-code-flow (updated 2026-05-15)

Not documented:

- Whether `http://localhost` is accepted. The page asks for HTTPS and gives no
  exception.
- Whether a private hostname such as `noongun.tail1234.ts.net` is accepted. The redirect
  happens in the member's browser, and only our server calls LinkedIn's token endpoint,
  so LinkedIn never needs to reach our host. The page does say "LinkedIn servers will
  only communicate with URLs that you have identified as trusted", which reads as the
  registered list, not as a reachability check. `tailscale serve` gives the hostname a
  valid public certificate, so the HTTPS rule should be met.

Both must be tested in the spike by registering
`https://<machine>.<tailnet>.ts.net/auth/linkedin/callback` and completing a real
login. Until then, treat it as likely but unconfirmed.

### 7. Community Management API

**Tiers.** Every app starts at Development tier after approval. Development tier: 500
calls per app per 24 hours, 100 calls per member per 24 hours, no `BATCH_GET`, no
webhook push. The integration must be built within twelve months. Standard tier has no
restrictions and needs a separate application with a screencast and test credentials.
Source: learn.microsoft.com/en-us/linkedin/marketing/increasing-access (updated 2026-10-19)

**Requirements.** From the Community Management App Review page:

- "Community Management APIs are only available to registered legal organizations for
  commercial use cases only."
- Business email address, verified. "Personal email addresses won't pass the vetting
  process."
- Organisation legal name, registered address, website and privacy policy.
- A super admin of the LinkedIn Page associated with the organisation must verify the
  app.
- App name must not contain any part of the LinkedIn or Microsoft names or logos.
- A rejected app cannot re-apply; a new app must be created.
- Standard tier adds: full integration done, company and product overview, use case
  description, test credentials, a high-resolution narrated screencast of each use case,
  a valid privacy policy and compliance with the data storage requirements.

Source: learn.microsoft.com/en-us/linkedin/marketing/community-management-app-review (updated 2026-02-11)

**What it unlocks.** `w_organization_social`, `r_organization_social`,
`r_organization_social_feed`, `w_organization_social_feed`, `rw_organization_admin`,
`r_organization_followers`, `r_basicprofile`, `r_1st_connections_size`,
`w_member_social`, `w_member_social_feed`, `r_member_postAnalytics` and
`r_member_profileAnalytics`. Posting as an organisation needs the member to hold one of
the Page roles ADMINISTRATOR, DIRECT_SPONSORED_CONTENT_POSTER or CONTENT_ADMIN.
Sources: increasing-access page (above), Posts API page (above)

**Personal profile metrics.** Per-post metrics for the member's own posts come from
`GET /rest/memberCreatorPostAnalytics` with `r_member_postAnalytics` (impressions,
members reached, reactions, comments, reshares and, from 202604, saves, sends and link
clicks). That permission is only listed under the Community Management API. Reading
one's own posts back through `GET /rest/posts?author=` needs `r_member_social`, which
the Posts API page calls "restricted" and "available to approved users only". So a
self-serve app (Share on LinkedIn plus OpenID Connect) cannot read any metrics for
personal posts.
Source: learn.microsoft.com/en-us/linkedin/marketing/community-management/members/post-statistics (updated 2026-05-15)

### 8. Rate limits

- Share on LinkedIn page: 150 requests per member per day and 100,000 per application
  per day (UTC).
- General rate limits page: limits are per endpoint, reset at midnight UTC, and
  "Standard rate limits are not published in documentation." The real numbers show in
  the Developer Portal under the app's **Analytics** tab, but only for endpoints called
  at least once that day. Exceeding a limit returns `429`. Admins get an email at 75 %
  of the application limit, 1 to 2 hours late.
- Community Management Development tier: 500 per app and 100 per member per 24 hours.

Sources:
share-on-linkedin page (above),
learn.microsoft.com/en-us/linkedin/shared/api-guide/concepts/rate-limits (updated 2025-08-20),
increasing-access page (above)

For one person posting a few times a day, none of these limits matter. An image post
costs at least three calls (initialize, upload, create). `ChannelCapabilities` should
expose 150 per member per day as the conservative default, from config.

### Differences from the brief (to resolve)

1. **Refresh tokens.** The brief asks whether refresh tokens are issued for our app
   type. They are not: programmatic refresh tokens are for approved MDP partners only.
   Every LinkedIn connection must be re-authorised by the member at least every 60
   days. The brief's token expiry alert covers this, but M7 "token expiry handling"
   cannot be automatic for LinkedIn.
2. **Engagement metrics for personal posts.** Brief section 1 (Phase 2) says
   "Engagement metrics feed back into drafting", and M6 polls metrics. For personal
   profile posts this needs `r_member_postAnalytics`, which only the vetted Community
   Management API grants. With self-serve products alone, M6 can only cover Facebook.
3. **Applying early for the Community Management API.** The brief says to apply early.
   The docs say the API is only for "registered legal organizations for commercial use
   cases", with a verified business email (not personal), legal name and registered
   address. ADR 0005 defers the legal entity until M9. These two cannot both hold: an
   early application must be made by an existing legal entity, or the application waits
   for ADR 0005. A rejected app also cannot re-apply, so a weak early application has a
   cost.
4. **Community Management requirements.** The brief names a verified organisation
   website and an associated LinkedIn Page. The docs add a verified business email, the
   organisation's legal name, registered address and privacy policy, verification of
   the app by a Page super admin, and a ban on "Linked", "In" or Microsoft names in the
   app name. "Noon Gun" is fine.
5. **Page roles for organisation posting.** The brief says the member must be a page
   administrator. The docs accept ADMINISTRATOR, DIRECT_SPONSORED_CONTENT_POSTER or
   CONTENT_ADMIN.
6. **Link posts.** Not in the brief: the Posts API does not build link previews. The
   adapter must supply title, description and thumbnail itself, or post the URL as plain
   text.
7. **Endpoint ambiguity.** The brief links the Posts API page. The self-serve Share on
   LinkedIn page still documents `/v2/ugcPosts`. Which one a self-serve app should use
   is not stated; see section 2.

### Per-user app setup in self-hosted mode (onboarding checklist)

The brief assumes each self-hosting user creates their own LinkedIn developer app. The
docs support this. A shared app would mean shipping its client secret to every install,
and the OAuth page says "Do not share your *Client Secret* value with anyone". The token
exchange needs that secret, so it cannot be avoided. Share on LinkedIn and OpenID
Connect need no review, so a personal app costs the user a few minutes and nothing else.
(In SaaS mode, one platform-level app holds the secret on our server, which is fine.)

The create-app form at linkedin.com/developers/apps/new needs a login and could not be
read for this review. The field names below come from the documentation pages cited;
the spike must check them against the live form and correct this list.

1. Make sure you have a personal LinkedIn account. Posts will appear on this profile.
2. Make sure there is a **LinkedIn Page** you can use for the app. LinkedIn asks every
   app to be associated with a Page; "individual developers must select a default page
   to proceed with app creation" for products that need one. Your own company Page
   works. If you have none, create one first (LinkedIn Help article a543852). Source:
   linkedin.com/help/linkedin/answer/a548360 (outside the two source domains named for
   this review, but official).
3. Go to linkedin.com/developers/apps and click **Create app**.
4. Fill in **App name** (for example "Noon Gun - <your name>"; do not use "LinkedIn",
   "Linked" or "In"), choose the **LinkedIn Page**, add a **Privacy policy URL** if
   asked, upload an **App logo**, accept the legal agreement, and click **Create app**.
5. If the Page belongs to someone else, its super admin must approve the association
   within 30 days.
6. Open the app's **Products** tab. Add **Share on LinkedIn**, then **Sign In with
   LinkedIn using OpenID Connect**. Both are open permissions and need no review.
7. Open the **Auth** tab. Check that the scopes `openid`, `profile` and
   `w_member_social` are now listed ("Your app's Auth tab will show current scopes
   available").
8. On the same **Auth** tab, add the redirect (callback) URL exactly as Noon Gun shows
   it in its setup wizard, for example
   `https://noongun.tail1234.ts.net/auth/linkedin/callback`. It must start with
   `https://`, contain no `#`, and match character for character.
9. On the **Auth** tab, copy the **Client ID** and **Client Secret** into the Noon Gun
   setup wizard. Do not share the secret anywhere else.
10. In Noon Gun, click **Connect LinkedIn**. Sign in to LinkedIn if asked and click
    **Allow**.
11. Every 60 days Noon Gun will ask you to reconnect. Click **Reconnect** and, if you are
    still logged in to LinkedIn, it completes without a new consent screen.

## 2026-11-25 - second review, and what the adapter does (M5)

The pages below were read again on 2026-11-25, before the adapter was written.
Nothing in them contradicts the brief in a way that blocks the design. The
spike has still not been run, so everything here rests on documentation.

### Confirmed unchanged

- **Products and scopes.** Share on LinkedIn grants `w_member_social`; Sign In
  with LinkedIn using OpenID Connect grants `openid`, `profile` and `email`.
  Both are listed as open permissions, "available to all developers without
  special approval". The adapter asks for `openid profile w_member_social` and
  not for `email`.
  Source: getting-access (updated 2026-06-03), share-on-linkedin (updated
  2023-12-14), sign-in-with-linkedin-v2 (updated 2024-08-08).
- **Posts API.** `POST https://api.linkedin.com/rest/posts` with
  `Linkedin-Version` and `X-Restli-Protocol-Version: 2.0.0`; `201` with the URN
  in `x-restli-id` (`urn:li:share:{id}` or `urn:li:ugcPost:{id}`); delete with
  `X-RestLi-Method: DELETE`, `204`, idempotent. `w_member_social` is in the
  permission table of the page. Latest version is still `202611`.
  Source: posts-api (updated 2026-05-13), versioning (updated 2026-11-16).
- **Userinfo.** `GET https://api.linkedin.com/v2/userinfo` returns `sub`,
  `name`, `given_name`, `family_name`, `picture`, `locale`, and `email` only
  when that scope was granted.
- **Tokens.** 60 days for an access token, 30 minutes for the code, refresh
  tokens only "for all approved Marketing Developer Platform (MDP) partners".
  Renewal for a self-serve app is the member going through the sign-in again.
  Source: authorization-code-flow (updated 2026-05-15),
  programmatic-refresh-tokens (updated 2025-10-08).
- **Rate limits.** 150 requests per member and 100,000 per application per day
  (UTC) on the Share on LinkedIn page; "Standard rate limits are not published"
  on the general page. A throttled call answers `429`.

### New findings

1. **Article posts.** The article schema makes `source` and `title` required
   and `description`, `thumbnail` and `thumbnailAltText` optional. The title
   must be "less than 400 characters", the description "less than 4,086
   characters". "thumbnail is optional when creating an Article Post". The
   earlier note said Noon Gun "must fetch the page title and image itself";
   that is not what is built. See "Decisions" below.
   Source: article-ads-integrations (updated 2025-10-16).
2. **The commentary format.** The reserved characters of the "little" text
   format are `| { } @ [ ] ( ) < > # \ * _ ~`. "All reserved characters need
   to be escaped with a backslash, even if those characters are not used in
   one of the supported elements or templates." A hashtag is `#` followed by a
   single word. This closes the open point of the first review.
   Source: little-text-format (updated 2025-10-16).
3. **Image upload uses PUT.** The Images API page defers to the Assets API
   page, which says "Use a PUT method to upload the image", with
   `Authorization: Bearer` and a `201` answer in its sample. The sample upload
   addresses are on `www.linkedin.com/dms-uploads/`. No page states which
   hosts an upload address may use.
   Source: vector-asset-api (updated 2026-01-28), images-api (updated
   2026-06-24).
4. **Token introspection exists.** `POST https://www.linkedin.com/oauth/v2/introspectToken`
   as a form with `client_id`, `client_secret` and `token` answers with
   `active`, `status` (`active`, `expired`, `revoked`), `expires_at` in Unix
   seconds and a comma separated `scope`. The page does not say whether a
   self-serve app may use it.
   Source: token-introspection (updated 2023-12-01).
5. **Declined consent.** The browser comes back with
   `error=user_cancelled_login` or `error=user_cancelled_authorize`, not with
   the standard `access_denied`.
6. **Errors.** The Posts API page now has an error table: `400`
   (`MISSING_FIELD`, `FIELD_LENGTH_TOO_LONG` and others), `401`, `403`
   `ACCESS_DENIED`, `404`, `409` `CONFLICT` ("A write conflict occurred. Retry
   the request."), `422`, `429` `TOO_MANY_REQUESTS`, `500`, `503`. A sunset
   version answers `426` `NONEXISTENT_VERSION`; a missing version header `400`
   `VERSION_MISSING`. The body is `status`, `code`, `message` and sometimes
   `serviceErrorCode`. `401` covers an expired token and one "revoked by the
   member from their privacy settings". No `Retry-After` header is documented.
   Source: posts-api, error-handling (updated 2025-03-26), error-responses
   (updated 2025-10-16), rate-limits (updated 2025-08-20).

### Still not stated anywhere that was read

- The maximum length of `commentary`. The schema page gives none; the API
  answers `FIELD_LENGTH_TOO_LONG` above it. 3000 is the limit of the LinkedIn
  composer and is what the adapter uses, from `config/noongun.php`.
- Whether the backslashes of escaped text count towards that limit.
- Whether a throttled call (`429`) is certain not to have been carried out.
- Whether an app with only the two self-serve products may call
  `/rest/posts` (the Share on LinkedIn page still shows `/v2/ugcPosts`).
- Whether `urn:li:person:{sub}` is the right author for a `sub` from OpenID
  Connect.
- Whether the `scope` of the token answer lists `openid` and `profile`, and
  with which separator. The page says "space-delimited"; the introspection
  page says comma separated.
- A permalink form for share URNs.

### Decisions taken in the adapter

- **Idempotency.** LinkedIn has no idempotency key, and reading a member's
  posts back needs `r_member_social`, which a self-serve app does not get. So
  after a publish whose outcome is unknown, nothing can find out what
  happened. `PublishPostJob` already gives each round and step out once and
  marks the variant before the call; the adapter adds that a publish is
  retryable only when LinkedIn certainly made no post: the connection was
  never established, or the answer was a `429` in LinkedIn's own error format.
  A timeout after the request was sent, a `5xx`, a `409`, a `429` or `4xx`
  that is not in LinkedIn's format, a redirect and a `201` without a readable
  URN are all reported as "outcome unknown". The variant keeps its mark, the
  post fails, nothing is sent again by itself, and the editor asks the member
  to look at the profile before sending again.
- **Error mapping.** `401` and `403`: the channel needs a reconnect. `400`,
  `404`, `422`, `426`: permanent, with LinkedIn's message; `426` also names
  `LINKEDIN_API_VERSION`. `429` in LinkedIn's format: retryable. Calls that
  are safe to repeat (userinfo, introspection, the token endpoint, delete)
  are retryable on every temporary failure.
- **Member identity from userinfo, not from the ID token.** The ID token is
  not read, so no JWT validation is needed. The `sub` comes from a TLS call to
  LinkedIn made with the token that was just issued.
- **Link posts take a typed card.** The author types the title and
  description in the editor. The app never fetches the linked page, so there
  is no server-side request to an address a member chose. A link without a
  title is held back by the readiness check.
- **No thumbnail and no image posts yet.** Both need an image that the member
  uploads, and the media model of ADR 0003 is not built. A link card without a
  thumbnail is valid. When uploads are built: the upload address LinkedIn
  returns must be HTTPS and on `linkedin.com` before the token or the image is
  sent to it, the method is `PUT`, and an image post makes three calls, which
  the time limit of the publishing job must allow for.
- **No mentions.** Everything an author types is escaped except the `#` of a
  hashtag, so a typed `@[Name](urn)` is sent as plain text.
- **Token checks.** `refreshToken()` uses token introspection to record the
  expiry and to notice a revoked token. A stored refresh token (approved
  partners only) is exchanged instead.
- **Hosts.** The adapter calls `LINKEDIN_API_URL` and `LINKEDIN_WEB_URL` only,
  both must be HTTPS, and redirects are not followed.

### Onboarding checklist, corrected

Steps 1 to 9 of the checklist above stand. The redirect address to register
is `<APP_URL>/auth/linkedin/callback`. There is no setup wizard: the Client ID
and Client Secret go into `.env` as `LINKEDIN_CLIENT_ID` and
`LINKEDIN_CLIENT_SECRET`, and the Channels page shows the redirect address.

## Spike results

To be filled in after running `scripts/spike/linkedin/publish-and-delete.sh`
and one real connect through the Channels page. Until then the fixtures under
`tests/Fixtures/linkedin/` are hand-written, not recorded.

- Date run:
- `/rest/posts` accepted with Share on LinkedIn only (yes or no):
- `urn:li:person:{sub}` accepted as author (yes or no):
- `x-restli-id` type returned (share or ugcPost):
- Permalink form that opens logged out:
- Image upload method that worked (PUT or POST):
- Redirect URI on a `*.ts.net` hostname accepted (yes or no):
- `http://localhost` redirect accepted (yes or no):
- `expires_in` returned, `refresh_token` returned (yes or no):
- Rate limit shown in the Analytics tab for `/rest/posts`:
- `scope` in the token answer and in the introspection answer (values and separator):
- Token introspection allowed for a self-serve app (yes or no):
- How escaped reserved characters render, and whether the hashtag is one:
- Length limit of `commentary`, and whether backslashes count:
- Link post without a thumbnail: how the card looks:
