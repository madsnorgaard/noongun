#!/usr/bin/env bash
# Exchange the short-lived Explorer user token for a long-lived (about 60 days) user token.
source "$(dirname "$0")/lib.sh"
: "${META_SHORT_USER_TOKEN:?set META_SHORT_USER_TOKEN in .env}"

echo "Short-lived token:"
describe_token "$META_SHORT_USER_TOKEN"

resp="$(printf 'data-urlencode = "client_secret=%s"\ndata-urlencode = "fb_exchange_token=%s"\n' \
          "$META_APP_SECRET" "$META_SHORT_USER_TOKEN" \
        | curl --silent --show-error --get --config - \
            --data-urlencode "grant_type=fb_exchange_token" \
            --data-urlencode "client_id=$META_APP_ID" \
            "$GRAPH/oauth/access_token")"

token="$(jq -r '.access_token // empty' <<<"$resp")"
[[ -n "$token" ]] || { echo "Exchange failed:" >&2; jq '.error // .' <<<"$resp" >&2; exit 1; }

state_put user_token "$token"
echo "Long-lived user token stored in .state/user_token (expires_in: $(jq -r '.expires_in // "not returned"' <<<"$resp") s)."
describe_token "$token"
