#!/usr/bin/env bash
# List the Pages the user manages and store the Page access token for META_PAGE_ID.
source "$(dirname "$0")/lib.sh"
user_token="$(require_state user_token)"

pages="$(graph GET me/accounts "$user_token" "fields=id,name,tasks,access_token")"
echo "Pages this user manages:"
jq '[.data[] | {id, name, tasks}]' <<<"$pages"

: "${META_PAGE_ID:?set META_PAGE_ID in .env to one of the ids above, then rerun}"
page_token="$(jq -r --arg id "$META_PAGE_ID" '.data[] | select(.id == $id) | .access_token' <<<"$pages")"
[[ -n "$page_token" ]] || { echo "Page $META_PAGE_ID not in the list, or no token returned." >&2; exit 1; }

state_put page_token "$page_token"
echo "Page token stored in .state/page_token. Expected expires_at: never."
describe_token "$page_token"
