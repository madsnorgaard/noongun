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
