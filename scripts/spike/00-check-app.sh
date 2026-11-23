#!/usr/bin/env bash
# Sanity check: app credentials work and the Explorer user token has the expected scopes.
source "$(dirname "$0")/lib.sh"

app="$(printf 'data-urlencode = "access_token=%s|%s"\n' "$META_APP_ID" "$META_APP_SECRET" \
       | curl --silent --show-error --get --config - --data-urlencode "fields=id,name" "$GRAPH/$META_APP_ID")"
echo "App: $(jq -c . <<<"$app")"

if [[ -n "${META_SHORT_USER_TOKEN:-}" ]]; then
  echo "User token:"
  describe_token "$META_SHORT_USER_TOKEN"
fi
