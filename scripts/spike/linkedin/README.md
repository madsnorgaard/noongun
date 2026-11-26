# LinkedIn spike (M5)

A throwaway script that calls the real LinkedIn API. It proves that a
self-serve app (Share on LinkedIn plus Sign In with LinkedIn using OpenID
Connect) can publish to the profile of the member who signed in, and that the
posts can be deleted again. Findings go into
`docs/platform-notes/linkedin.md` under "Spike results". No application code
depends on anything in this folder.

The posts are public for as long as the script waits. Run it on your own
profile only.

## Requirements

`bash`, `curl`, `jq`.

## Secrets

- `.env` in this folder holds the access token and, optionally, the app
  credentials. It is gitignored. Start from `.env.example`.
- The token and the client secret are passed to curl on stdin, never on the
  command line, and never printed.
- The script follows no redirects and sends the token to `api.linkedin.com`
  and `www.linkedin.com` only. An upload address that LinkedIn returns is used
  only if it is an HTTPS address on `linkedin.com`.

## Run

```
cp .env.example .env     # fill in the token and the API version
./publish-and-delete.sh
```

The script:

1. Reads `GET /v2/userinfo` and prints `sub` and the name.
2. With the client id and secret set, prints what token introspection reports.
3. Publishes a text post whose text holds every reserved character of the
   commentary format, escaped, and one hashtag. Prints the URN and permalink.
4. With `SPIKE_THUMBNAIL` set, uploads that image through the Images API.
5. Publishes a link post with a title and description (and the thumbnail, if
   one was uploaded). Prints the URN and permalink.
6. Waits for Enter, then deletes both posts. It also deletes what it created
   when it is interrupted or fails half way.

## What to write down

Fill in "Spike results" in `docs/platform-notes/linkedin.md`:

- Whether `/rest/posts` accepts a token from an app with only the two
  self-serve products, and `urn:li:person:{sub}` as the author.
- The type of URN in `x-restli-id` (share or ugcPost) and whether the
  permalink opens, logged in and logged out.
- How the escaped characters render, and whether the hashtag is a hashtag.
- How the link post looks with and without a thumbnail.
- What `scope` holds in the introspection answer (are `openid` and `profile`
  listed next to `w_member_social`?), and its separator.
- The HTTP status of the image upload with `PUT`.
- The status of the two deletes.

The connect flow (redirect address, consent, `expires_in`, `refresh_token`,
the `scope` of the token answer) is tested through the app itself: set
`LINKEDIN_CLIENT_ID` and `LINKEDIN_CLIENT_SECRET` in the app's `.env` and use
**Connect LinkedIn** on the Channels page.

When done, delete `.env`.
