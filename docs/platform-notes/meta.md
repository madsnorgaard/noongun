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
