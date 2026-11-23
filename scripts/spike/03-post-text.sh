#!/usr/bin/env bash
# Publish one text post with a link to the Page.
source "$(dirname "$0")/lib.sh"
page_token="$(require_state page_token)"
: "${META_PAGE_ID:?}"

message="Noon Gun spike test post $(date -u +%Y-%m-%dT%H:%MZ). This will be deleted shortly."
resp="$(graph POST "$META_PAGE_ID/feed" "$page_token" "message=$message" "link=https://github.com/madsnorgaard/noongun")"
post_id="$(jq -r .id <<<"$resp")"
echo "$post_id" >> "$STATE_DIR/posts"
echo "Published text post: $post_id"

"$(dirname "$0")/05-permalink.sh" "$post_id"
