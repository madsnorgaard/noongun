# 0015 - LinkedIn: publishing without an idempotency key, and the typed link card

Date: 2026-11-25. Status: accepted.

## Context

M5 publishes to a member's own LinkedIn profile through the self-serve
products (ADR 0007). Two properties of that API shape the adapter. The
findings and their sources are in `docs/platform-notes/linkedin.md`.

**Nothing makes a publish safe to repeat.**

- The Posts API takes no idempotency key. Sending the same post twice makes
  two public posts.
- A self-serve app cannot read a member's posts back. That needs
  `r_member_social`, which is restricted to approved partners. So after a call
  whose answer never arrived, nothing can ask LinkedIn whether the post exists.

**LinkedIn builds no link preview.** The Posts API does not read the page
behind a link. A link is shown as a card only from the `title` (required),
`description` and `thumbnail` (both optional) sent with the post.

## Decision

### Which failures of a publish are sent again

`PublishPostJob` already gives each round and step out once, sends a variant
only inside its lock, and marks the variant (`outcome_unknown_since`) before
the platform is called (ADR 0009). On top of that the LinkedIn adapter marks a
failed publish as retryable only when LinkedIn certainly made no post:

| What happened | Treated as |
|---|---|
| The connection was never established (no host, refused, TLS failed) | retryable: nothing was sent |
| `429` in LinkedIn's own error format | retryable: a throttled call is turned away |
| `401`, `403` | certain refusal; the channel needs a reconnect |
| `400`, `404`, `422`, `426` in LinkedIn's error format | certain refusal; permanent |
| Timeout or broken connection after the request may have been sent | outcome unknown |
| `5xx` | outcome unknown |
| `409` | outcome unknown |
| `429` or another `4xx` that is not in LinkedIn's error format | outcome unknown |
| A redirect (never followed) | outcome unknown |
| `201` without a URN that can be read in `x-restli-id` | outcome unknown |

"Outcome unknown" is never retryable. The variant keeps its mark, the post is
failed, and nothing sends it again by itself.

`409` is documented as "A write conflict occurred. Retry the request." It is
not trusted for a publish: the text does not say how far the write got, and a
wrong guess costs a duplicate in public while a careful one costs a click.

The `429` rule rests on what throttling means. The documentation does not say
in so many words that a throttled call is never carried out.

### The link card

- The author types the title and description in the editor, for channels
  whose capabilities say that the platform builds no preview
  (`ChannelCapabilities::$linkTitleLength`). They are stored on the variant
  (`link_title`, `link_description`).
- The app never fetches the linked page to fill them in. A server-side request
  to an address a member chose is a way into the network the instance runs in,
  and on a home server that network is somebody's home.
- No thumbnail is sent until the media model of ADR 0003 exists. A thumbnail
  is an image the member uploads; it cannot be typed, and fetching it from an
  address is the request ruled out above.
- The card is part of what is approved: it is in the fingerprint the approve
  request sends back, and it locks with the text.
- A link without a title, or with a title or description longer than the
  platform allows, blocks approval. The adapter refuses such a variant too,
  before anything is sent.

### Images (added 2026-11-26, with ADR 0017)

An image post is the calls ADR 0017 describes: for each image
`POST /rest/images?action=initializeUpload` and a `PUT` of the bytes, then
the one call that creates the post. The table above is for that last call
and is unchanged by images.

**Uploads are retried freely.** Initialising and the `PUT` make nothing
public, and the variant is not marked while they run. A timeout, a `5xx`, a
`409` and an answer that cannot be read are all retryable there. `401` and,
for initialising, `403` ask for a reconnect. The `PUT` goes to a host that
does not answer in the format of the API, so only its status is read: `400`,
`413`, `415` and `422` are permanent, everything else is retryable, because
the next attempt starts with a new address.

**The upload address is not believed.** It comes out of a response, and the
documentation asks for the member's token on it. It is used only when it is
`https`, on a host in `LINKEDIN_UPLOAD_HOSTS` (default `linkedin.com`, each
name with everything below it), with port 443 or none, and without a user
name, an IP address, a fragment, a backslash or white space
(`UploadAddress`). Otherwise the upload fails for good with nothing sent.
Redirects are not followed. The address is signed and is never logged; its
host is.

**Nothing waits for an image to be ready.** The Images API has a status
(`WAITING_UPLOAD`, `PROCESSING`, `AVAILABLE`, `PROCESSING_FAILED`), but a
token with only `w_member_social` may not read it on the versioned API. So
the post is created straight after the upload. Two things follow.

- When LinkedIn refuses the post and says an image is not ready, that is a
  certain refusal (a `400`, `404` or `422` in LinkedIn's own error format),
  so nothing was posted, and it is retryable: the job waits its backoff and
  creates the post again, with the same uploads. The words of that refusal
  are not documented and are guessed at. An answer that leaves the outcome
  unknown is never read as "not ready", whatever it says.
- When LinkedIn accepts the post and then fails to process an image, the
  Assets API page says "the post won't be visible to members". Noon Gun
  cannot see that. The post is recorded as published. This is a known gap
  until the status can be read (M9, or the legacy read the documentation
  hints at, which the spike will try).

**An upload that LinkedIn no longer has is made again.** An uploaded image
is used for later attempts for `LINKEDIN_IMAGE_LIFETIME` (a day, an
assumption: LinkedIn does not say how long it keeps one). When the creating
call is refused in LinkedIn's error format with words that say an image is
not known, not found or failed, and an image of the post was sent more than
ten minutes ago, the adapter answers with
`PublishFailed::uploadsNoLongerValid()`: the job forgets the uploads and its
next attempt sends the images again. The same refusal for images that were
only just sent is about the files themselves, and stands: the member is told
to return the post to draft and to take the images off and put them on
again. The words are guessed at here too; `INVALID_IMAGE_ID` and `NOT_FOUND`
are documented for the Images API only.

### The thumbnail of the link card (decided 2026-11-26)

The documentation confirms that `content.article.thumbnail` takes an image
URN from the same Images API, with an optional `thumbnailAltText`. A post
still holds one kind of content: images or an article, not both.

A thumbnail could be had without a new column by a rule: "on LinkedIn, one
image on a text with a link becomes the picture of the card". That rule is
not built, because it is not predictable for an author:

- The same gesture would mean two things. One image and a link would give a
  card with a small cropped picture on LinkedIn and a full image with the
  link in the text on Facebook. A second image would turn the LinkedIn post
  from a card into an image post and move the link into the text.
- It takes away the post many authors want: a full image with the link
  below the text. They would have to type the link into the text by hand
  and lose its campaign parameters.
- The author does not see how the card crops the image.
- `MediaLinkRule::Both` does not describe it: the image is not shown as an
  image. It would need a new rule that depends on the number of images, and
  the readiness check, `textFor()` and the editor would all have to know it.

So LinkedIn stays `in_text`, and a link card has no picture. What a
thumbnail takes:

1. A choice by the author, stored: an attachment with a role ("picture of
   the link card") in `post_variant_media`, so that the upload protocol, the
   remembered URN and its lifetime work unchanged. At most one per variant,
   only with a link, and not counted as an image of the post.
2. The role in the fingerprint of the approval, and a readiness rule for it.
3. A control in the editor on the link card, with a preview at the ratio of
   the card.
4. In the adapter: `thumbnail` and `thumbnailAltText` in `article()`, about
   ten lines.

## Consequences

- A LinkedIn post can end as failed although it is public. The member is told
  to look at the profile: if the post is there, leave it as it is; if it is
  not, send it again. Sending again when the post is there makes a duplicate.
- **Known gap.** There is no action for "it is there". A post that went out
  with an unknown outcome stays failed, without a permalink, and the only
  actions offered are "Send again" and "Return to draft". Recording that the
  post exists (state published, permalink typed or left empty) is a state
  transition that belongs to M7. Until then the README and the retry dialog
  say to leave such a post alone.
- An image post will make three calls (initialise, upload, create). Only the
  last one creates anything, but the time limit of the publishing job, which
  allows one call per variant today, must allow for all three.
- If the Community Management API is granted in M9, `r_member_social` or the
  organisation scopes may allow a read-back. This decision should be looked at
  again then.
- Link cards on LinkedIn have no image for now, and drafts from the LLM that
  carry a link need a title typed by a person before they can be approved.
- A LinkedIn post whose image LinkedIn could not process can be recorded as
  published and yet not be visible. See "Images" above.
