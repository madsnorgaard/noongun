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

- Date run:
- App mode (Development or Live):
- Scopes on the working user token:
- Page token `expires_at` from `debug_token`:
- Text post id, permalink, `permalink_url` returned (yes or no):
- Photo post id, caption shown (yes or no):
- Visible from a logged-out browser (text, photo):
- Delete worked (text, photo):
- Errors met and how they were resolved:
