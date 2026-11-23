#!/usr/bin/env bash
# Delete every post created by this spike (ids in .state/posts), or the ids given.
source "$(dirname "$0")/lib.sh"
page_token="$(require_state page_token)"

if (( $# )); then ids=("$@"); else mapfile -t ids < <(state_get posts); fi
(( ${#ids[@]} )) || { echo "Nothing to delete."; exit 0; }

for id in "${ids[@]}"; do
  [[ -n "$id" ]] || continue
  graph DELETE "$id" "$page_token" >/dev/null && echo "Deleted $id"
done
: > "$STATE_DIR/posts"
