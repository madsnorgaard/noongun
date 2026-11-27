# Meta spike (M1)

Throwaway scripts that call the real Graph API. They prove that Noon Gun can publish
to a Facebook Page we manage, that the posts are publicly visible, and that we can
delete them. Findings go into `docs/platform-notes/meta.md`. No application code
depends on anything in this folder.

## Requirements

`bash`, `curl`, `jq`, `openssl`.

## Secrets

- `.env` holds the app credentials and the short-lived Explorer token. It is
  gitignored. Start from `.env.example`.
- `.state/` holds the derived long-lived user token, the Page token and the ids of
  posts created by the spike. It is gitignored and created with mode 700.
- Tokens are passed to curl on stdin, never on the command line, and never printed.
  Token details are shown through `debug_token` only (type, scopes, expiry).
- Every call sends `appsecret_proof`.

## Run order

```
cp .env.example .env            # fill in app id, secret, short-lived token, photo URL
./00-check-app.sh               # app credentials and token scopes
./01-long-lived-user-token.sh   # short-lived -> long-lived user token
./02-page-token.sh              # list Pages; set META_PAGE_ID, run again
./03-post-text.sh               # text post with link, prints permalink
./04-post-photo.sh              # photo post, prints permalink
                                # check both permalinks in a logged-out browser
./06-delete.sh                  # delete every post the spike created
```

### The image path: `07-upload-photo-post.sh`

The application publishes images in two steps: each image goes up as an
unpublished photo, and one call to the Page feed names the photos (issue
#25). That path has not met the real API. This script walks it once, on one
Page, and answers what Meta's documentation leaves open:

1. one unpublished photo, uploaded from `SPIKE_PHOTO_FILE` with a description
2. whether an unpublished photo shows on the Page
3. a post with one photo through `attached_media`, a link as the last line of
   its text, and its permalink
4. a post with three photos, and whether their order is kept
5. the error for a `media_fbid` that does not exist
6. whether a post takes 11 photos
7. how long a description may be (101, 1000, 1001, 5000 and 20000 characters)
8. deleting every post and photo it created

It needs steps 00 to 02 first, for the Page token. It makes about 20 uploads
and four posts, reads them back, and deletes what it made. It reads and
deletes nothing else on the Page. If the app is in Live mode the posts are
public until step 8.

It refuses to run until the Page id is repeated:

```
SPIKE_CONFIRM_PAGE_ID=<the value of META_PAGE_ID> ./07-upload-photo-post.sh
```

Add `SPIKE_PAUSE=1` to stop before the delete and look at the posts in a
logged-out browser. A run that is cut short still deletes what it created; a
post it could not delete stays in `.state/posts` for `./06-delete.sh`.

At the end it prints a block of JSON between two marker lines, without any
credential. Paste that block under "Spike results for the image path" in
`docs/platform-notes/meta.md`, then:

- put the code and subcode of step 5 into
  `tests/Fixtures/facebook/error_photo_expired.json`
- set `FACEBOOK_MAX_MEDIA_ITEMS` and `FACEBOOK_ALT_TEXT_LENGTH` in
  `config/noongun.php` to what steps 6 and 7 showed
- replace the fixtures the README of that folder marks as assumed

Run the sequence twice:

1. **Development mode** (the default for a new app). Expect the posts to be
   hidden from a logged-out browser; Meta shows Development mode content to app
   role users only.
2. **Live mode.** Switch the app mode toggle in the App Dashboard toolbar, note
   everything the dashboard asks for before it allows the switch, get a fresh
   token in Graph API Explorer, update `META_SHORT_USER_TOKEN`, and run 01 to 06
   again. This run answers whether Phase 1 works without App Review.

Record both runs in `docs/platform-notes/meta.md` under "Spike results".

`./05-permalink.sh <post_id>` reads a post back at any time.

When done, remove the app secret from `.env` or delete `.env` and `.state/`.
