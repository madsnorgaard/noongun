# Facebook fixtures

These files are **not recorded responses**. The M1 spike (`scripts/spike/`) has
not been run against the real Graph API yet, so every file here was written by
hand from Meta's documentation as reviewed on 2026-11-23 and 2026-11-24 (see
`docs/platform-notes/meta.md`). Ids, names, tokens and trace ids are invented.
No value in this folder is, or was ever, a real credential.

When the spike has run, replace each file with the recorded response (with the
tokens swapped for the placeholder values used here) and delete the matching
line below.

## What the spike must confirm

Each point is an assumption that the adapter or its tests rely on.

| File | Assumption |
|---|---|
| `oauth_code_exchange.json` | `GET /oauth/access_token` with `client_id`, `redirect_uri`, `client_secret`, `code` answers with `access_token`, `token_type`, `expires_in`. Documented; the values are invented. |
| `oauth_long_lived_exchange.json` | The `fb_exchange_token` call answers in the same shape. Documented. |
| `me_accounts*.json` | `GET /me/accounts?fields=id,name,tasks,access_token&limit=100` returns `data[]` with those fields. `tasks` holds `CREATE_CONTENT` for a Page the user can publish to; the adapter skips a Page whose `tasks` lacks it and reports it with that reason. An entry without `access_token` (`me_accounts_one_page_two_skipped.json`, `me_accounts_none_connectable.json`) is invented: it has not been seen in a real answer, and is skipped and reported as "no access". `category` and `category_list` are in the documented example and are not read. |
| `me_accounts_first_of_two.json` | A further result page is announced by `paging.next`, and `paging.cursors.after` passed as `after` returns it. The adapter never requests `paging.next` itself, because that URL holds the user token. |
| `debug_token_page.json` | For a Page token, `GET /debug_token` answers with `data.is_valid`, `data.type` `PAGE`, `data.expires_at` `0` (never) and `data.scopes` listing the granted permissions. **The adapter refuses to connect when a configured scope is missing from `data.scopes`**, so the exact content of `scopes` on a Page token matters. `data_access_expires_at` is present but not used. |
| `debug_token_expiring.json` | A Page token can report a non-zero `expires_at` (Unix seconds). Not expected for a Page token made from a long-lived user token; kept so the code path is tested. |
| `debug_token_invalid.json` | An invalid token is reported with HTTP 200, `is_valid` `false` and a `data.error` object, not with a Graph error. |
| `debug_token_missing_scope.json` | A declined permission is simply absent from `data.scopes`. |
| `feed_post.json` | `POST /{page-id}/feed` with `message` and optional `link` in a form body returns `{"id": "{page-id}_{post-id}"}` and nothing else. |
| (no file) | The permalink is `https://www.facebook.com/{id}` as the posts page says, and it opens for a logged-out visitor. `permalink_url` is **not** requested; see the 2026-11-24 entry in the platform notes. |
| `delete_post.json` | `DELETE /{page-post-id}` returns `{"success": true}`. |
| `error_*.json` | The error envelope is `error.message`, `error.type`, `error.code`, optional `error.error_subcode`, `error_user_title`, `error_user_msg`, and `error.fbtrace_id`. Documented. |
| `error_token_expired.json` | Code 190 with subcode 463. Documented codes; the message text is illustrative. |
| `error_permission.json` | Code 200 for a missing permission. The code range 200 to 299 is documented; this message is illustrative. |
| `error_rate_limit.json` | Code 80001 for the Page rate limit. Documented. **The adapter treats a rate limit code as proof that no post was made, and it is the only Graph error after which a publish is sent again automatically.** The spike cannot easily provoke one; the claim rests on the documentation calling these codes throttling. |
| `error_service.json` | Code 2 for a temporary service error. Documented. On publish this is treated as "outcome unknown" and is not retried. |
| `error_validation.json` | Code 100 for an invalid parameter. Code 100 is not in the error handling guide; it is what the API is known to use. |
| `error_duplicate_post.json` | Code 506 is documented. Subcode 1455006 and the user message are from memory, not from the documentation. |
| `error_code_used.json` | The code exchange refuses a used code with a Graph error envelope. The manual flow page only says "an explanatory error message"; code 100 and subcode 36009 are from memory. |
| `error_echoes_credentials.json` | Invented. It exists to prove that credentials in an error message never reach an exception. |

Not in a file, but assumed by the tests:

- **HTTP status codes.** Meta's documentation does not say which status an
  error carries. The tests use 400, 403, 429 and 500. The adapter decides by
  `error.code` first; the status only matters for 429 and 5xx without a rate
  limit code. On publish those are "outcome unknown" and are not retried; on
  calls that are safe to repeat they are retried.
- **Network failures.** The tests build them from Guzzle 8 exception classes:
  `ConnectException` for a connection that was never made (retried, also on
  publish), `NetworkTimeoutException`, `ConnectTimeoutException` and
  `NetworkException` for everything else (not retried on publish). Nothing
  here was observed against the real API.
- **`X-Business-Use-Case-Usage`.** Documented as JSON with
  `estimated_time_to_regain_access` in minutes. The tests key it by Page id.
- **`appsecret_proof`** is accepted on `/me/accounts`, `/{page-id}/feed` and
  `DELETE /{post-id}`. It is not sent on `/oauth/access_token` or on
  `/debug_token` with the app token, as in the spike scripts.
- **The login dialog** accepts `scope` for an app made with the "Manage
  everything on your Page" use case, and `auth_type=rerequest` does no harm on
  a first request.
- **Denied consent** comes back as `error=access_denied` and
  `error_reason=user_denied`. Documented.

## Questions for the spike about where credentials travel

The tests record that these calls carry credentials in the query string, because
that is the only form Meta documents for them (checked 2026-11-24, details in
`docs/platform-notes/meta.md`). If the spike shows that a safer form works, the
adapter and the tests should move to it.

- Is `Authorization: Bearer {token}` accepted on `GET /me/accounts`, on
  `DELETE /{post-id}` and on `GET /debug_token` with the app token, with
  `appsecret_proof` still sent as a parameter?
- Do the code exchange and the `fb_exchange_token` call on
  `/oauth/access_token` work as POST with a form body?
- Does `/debug_token` accept `input_token` anywhere but the query string?

`POST /{page-id}/feed` already keeps the token and `appsecret_proof` in the
form body, as documented, and the publish test asserts that its URL holds
nothing else.

## Question for the spike about returned Pages

- After a Page was disconnected in Noon Gun only (the grant still exists at
  Facebook), does the login dialog return it again in `/me/accounts` without
  the user selecting it? The connect flow assumes it can, and asks before
  such a Page is connected again.

## Photos (issue #25)

Written by hand on 2026-11-26 from the Page Photos reference; see the entry of that
date in `docs/platform-notes/meta.md`. `scripts/spike/07-upload-photo-post.sh` exists
to record the real answers and has not been run.

| File | Documented or assumed |
|---|---|
| `photo_upload.json` | **Documented:** `POST /{page-id}/photos` with `published=false` and a file as `multipart/form-data` answers with the photo id, a numeric string. **Assumed:** that the answer of an unpublished upload holds no `post_id` (the return type lists `id` and `post_id`). The adapter reads `id` only. |
| `feed_post.json` (used again) | **Documented:** a feed post with `attached_media[n]={"media_fbid":"..."}` answers with the Page post id. **Assumed:** that one entry works like several, that the id has the form `{page-id}_{post-id}`, and that `https://www.facebook.com/{id}` is the address of a post with photos. |
| `error_invalid_image.json` | **Documented:** code 324, "Missing or invalid image file", in the error list of the Page Photos reference. The message text is illustrative. |
| `error_photo_expired.json` | **Assumed throughout.** Meta does not document what a feed post gets whose `media_fbid` has expired or is unknown. Code 100 is "Invalid parameter" in the documented list; the user title and message are invented. The adapter does not read the text: it goes by the code (100 or 324) and by the age of the uploads. |

Not in a file, but assumed by the tests:

- **The fields of the upload.** `published`, `alt_text_custom` and the file in
  `source` are documented. That `access_token` and `appsecret_proof` are accepted as
  fields of a multipart body is assumed from the documented form body of other POST
  calls.
- **`attached_media` in a form body** is written as `attached_media[0]=...`, as in the
  documented curl example.
- **The limits** (10 images, 1000 characters of description) are assumptions. The
  tests use other numbers on purpose, so that nothing depends on the defaults.
- **Errors of the upload** other than 324 use the fixtures of the feed call: the same
  envelope is assumed for both edges.
