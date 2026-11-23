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
