#!/usr/bin/env bash
# Publish one photo post to the Page from a public image URL.
source "$(dirname "$0")/lib.sh"
page_token="$(require_state page_token)"
: "${META_PAGE_ID:?}" "${SPIKE_PHOTO_URL:?set SPIKE_PHOTO_URL in .env}"

caption="Noon Gun spike test photo $(date -u +%Y-%m-%dT%H:%MZ). This will be deleted shortly."
resp="$(graph POST "$META_PAGE_ID/photos" "$page_token" "url=$SPIKE_PHOTO_URL" "message=$caption")"
echo "Response: $(jq -c . <<<"$resp")"
post_id="$(jq -r '.post_id // empty' <<<"$resp")"
[[ -n "$post_id" ]] || { echo "No post_id returned; photo may not have created a feed post." >&2; exit 1; }
echo "$post_id" >> "$STATE_DIR/posts"
echo "Published photo post: $post_id (photo id $(jq -r .id <<<"$resp"))"

"$(dirname "$0")/05-permalink.sh" "$post_id"
