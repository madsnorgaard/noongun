#!/usr/bin/env bash
# Read back a post and print its permalink, then explain the logged-out check.
source "$(dirname "$0")/lib.sh"
page_token="$(require_state page_token)"
post_id="${1:?usage: 05-permalink.sh <post_id>}"

resp="$(graph GET "$post_id" "$page_token" "fields=id,created_time,permalink_url,is_published,message")"
jq '{id, created_time, is_published, permalink_url, message}' <<<"$resp"
permalink="$(jq -r '.permalink_url // empty' <<<"$resp")"
[[ -n "$permalink" ]] || permalink="https://www.facebook.com/$post_id"

cat <<MSG

Public visibility check (manual, required for M1):
  1. Open a private browser window that is not logged in to Facebook.
  2. Visit: $permalink
  3. Confirm the post content is shown, not a login wall or "content not available".
  4. Record the result in docs/platform-notes/meta.md.
MSG
