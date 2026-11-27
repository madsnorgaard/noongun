# LinkedIn fixtures

These files are **not recorded responses**. The LinkedIn spike
(`scripts/spike/linkedin/`) has not been run against the real API yet, so every
file here was written by hand from LinkedIn's documentation as reviewed on
2026-11-23, 2026-11-25 and 2026-11-26 (see `docs/platform-notes/linkedin.md`). Ids, names,
tokens and URNs are invented. No value in this folder is, or was ever, a real
credential.

When the spike has run, replace each file with the recorded response (with the
tokens swapped for the placeholder values used here) and delete the matching
line below.

## What the spike must confirm

Each point is an assumption that the adapter or its tests rely on.

| File | Assumption |
|---|---|
| `oauth_token.json` | `POST /oauth/v2/accessToken` as a form answers with `access_token`, `expires_in` (seconds) and `scope`. Documented. The documentation calls `scope` "space-delimited" and shows a single scope in its example; the adapter accepts spaces and commas. The adapter refuses to connect only when `scope` is present and lacks the scope for posting (`w_member_social`). It does not ask for `openid` and `profile` there, because it is not known whether LinkedIn lists them; the userinfo call proves those. An answer without `scope` is accepted. |
| `oauth_token_with_refresh.json`, `oauth_token_refreshed.json` | An approved partner app also gets `refresh_token` and `refresh_token_expires_in`, and the refresh grant answers in the same shape. Documented. A self-serve app is documented not to get them; kept so the code path is tested. |
| `oauth_token_missing_scope.json` | A scope that was not granted is absent from `scope`. Assumed. |
| `userinfo.json` | `GET /v2/userinfo` with the bearer token answers with `sub`, `name`, `given_name`, `family_name`, `picture`, `locale`. Documented. The `sub` here is invented; the adapter accepts letters, digits, `_` and `-`. |
| (no file) | `urn:li:person:{sub}` is accepted as the `author` of a post made with the same app. No page says so in one sentence; see the notes. |
| `introspect_active.json` | `POST /oauth/v2/introspectToken` answers with `active`, `status`, `expires_at` (Unix seconds), `scope` (comma separated) and `auth_type`. Documented, on a page last updated in 2023. Whether a self-serve app may call it is not stated. |
| `introspect_revoked.json` | A revoked token is reported with HTTP 200 and `active` `false`. Documented. |
| `introspect_missing_scope.json` | Invented, to test the path. |
| (no file) | A created post answers `201` with an empty body and the URN in the `x-restli-id` header, as `urn:li:share:{id}` or `urn:li:ugcPost:{id}`. Documented. The tests build this answer in `linkedInCreated()`. |
| (no file) | The permalink is `https://www.linkedin.com/feed/update/{urn}/`. Documented for ugcPost URNs only; assumed for share URNs. |
| (no file) | `DELETE /rest/posts/{encoded urn}` answers `204`, also for a post that is already gone. Documented. |
| `error_*.json` | The error body is `status`, `code`, `message` and sometimes `serviceErrorCode`. The field names are documented; which of them a given error carries is not. |
| `error_token_expired.json`, `error_token_revoked.json` | HTTP 401 for an expired or revoked token. The status is documented. The `code`, `serviceErrorCode` and message text are from memory, not from the documentation. The adapter decides on the status alone. |
| `error_permission.json` | HTTP 403 with `ACCESS_DENIED` for a missing scope. Documented; the message text is illustrative. |
| `error_validation.json` | HTTP 422 `UNPROCESSABLE_ENTITY`. Documented; the message text is invented. |
| `error_field_too_long.json` | HTTP 400 `FIELD_LENGTH_TOO_LONG`. Documented. |
| `error_rate_limit.json` | HTTP 429 with the documented message "Resource level throttle limit for calls to this resource is reached." `TOO_MANY_REQUESTS` is documented for the Posts API; `serviceErrorCode` 101 is from memory. **The adapter treats a 429 in LinkedIn's error format as proof that no post was made, and it is the only answer after which a publish is sent again automatically.** The documentation does not say in so many words that a throttled call is never carried out; the claim rests on what throttling means. |
| `error_conflict.json` | HTTP 409 `CONFLICT`, "A write conflict occurred. Retry the request." Documented. On publish the adapter does not trust this and reports the outcome as unknown. |
| `error_server.json` | HTTP 500. Documented. On publish this is "outcome unknown" and is not retried. |
| `error_version.json` | HTTP 426 `NONEXISTENT_VERSION` for a sunset `Linkedin-Version`. Documented. |
| `error_code_used.json` | The token endpoint refuses a code with `error` and `error_description` and HTTP 401 or 400. Documented. |
| `error_refresh_token_invalid.json` | HTTP 400 `invalid_request` for a refresh token that is expired or revoked. Documented. |
| `error_echoes_credentials.json` | Invented. It exists to prove that credentials in an error message never reach an exception. |
| `image_initialize_upload.json` | `POST /rest/images?action=initializeUpload` with `initializeUploadRequest.owner` answers `200` with `value.uploadUrl`, `value.uploadUrlExpiresAt` (milliseconds) and `value.image` (`urn:li:image:{id}`). Documented, with an organisation as the owner in the example. That a member URN is accepted as the owner with `w_member_social` is documented in the permissions of the page, not shown. The host of the address here is the test host; the documented examples are on `www.linkedin.com/dms-uploads/` and, on the older Share on LinkedIn page, `api.linkedin.com/mediaUpload/`. |
| (no file) | The upload is a `PUT` of the bytes to `uploadUrl` with `Authorization: Bearer`, and answers `201` without a body. Documented on the Assets API page, which the Images API page points to. The adapter also sends `Content-Type: application/octet-stream` and `Content-Length`; the documented example sends neither header explicitly. Assumed to be accepted. |
| (no file) | What the upload address answers when it fails. Not documented at all. The adapter reads the status only: `401` reconnect, `400`, `413`, `415`, `422` permanent, everything else retryable. Assumed. |
| (no file) | A post with one image is `content.media` with `id` and `altText`; a post with several is `content.multiImage.images[]` with `id` and `altText`, 2 to 20 images. Documented. That a post is accepted straight after the upload, before LinkedIn has processed the image, is assumed. |
| `error_image_forbidden.json` | HTTP 403 with `message` and `status` and no `code`, "Accessing this image resource is forbidden". Documented for the Images API. |
| `error_image_invalid.json` | HTTP 400 `INVALID_IMAGE_ID`. Documented in the error table of the Images API; that the Posts API answers a post with an unknown image this way is assumed. For an image that was sent more than ten minutes ago the adapter reads it as "LinkedIn no longer has the image", and the images are sent again. |
| `error_image_not_ready.json` | **Invented.** No page documents what the Posts API answers when an image of the post is still being processed, or whether it refuses such a post at all. The adapter treats a `400`, `404` or `422` in LinkedIn's error format that speaks of an image and of processing, waiting or not being available, and not of failing, as "not ready yet" and retryable. The spike must record the real answer. |
| `error_image_processing_failed.json` | Invented, to prove that a refusal that speaks of a failure is not read as "not ready yet". It is read as "send the image again" when the image was sent some time ago, and as a refusal when it was only just sent. `PROCESSING_FAILED` is a documented status of an image. |

Not in a file, but assumed by the tests:

- **Network failures.** Built from Guzzle 8 exception classes, as for
  Facebook: `ConnectException` for a connection that was never made (retried,
  also on publish), everything else is not retried on publish.
- **`Retry-After`.** Not documented by LinkedIn. The adapter reads it if it is
  sent and otherwise uses the backoff of the publishing job.
- **Denied consent** comes back as `error=user_cancelled_authorize` or
  `error=user_cancelled_login`, with `state`. Documented.
- **Escaping.** The commentary is sent in the "little" text format with every
  reserved character escaped by a backslash, except the `#` of a hashtag at
  the start of a word that holds only letters and digits. The
  rule is documented; how escaped text renders, and whether LinkedIn counts
  the backslashes towards its length limit, is for the spike to show.
- **The length limit of a post.** 3000 characters is not in the documentation
  that was read.
