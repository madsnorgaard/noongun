# Shared helpers for the Meta spike. Source this file; do not run it.
#
# Secrets never go on the command line: curl reads the token and appsecret_proof
# from a config block on stdin, so they do not show up in `ps` or shell history.

set -euo pipefail

SPIKE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STATE_DIR="$SPIKE_DIR/.state"

[[ -f "$SPIKE_DIR/.env" ]] || { echo "Missing $SPIKE_DIR/.env - copy .env.example and fill it in." >&2; exit 1; }
# shellcheck disable=SC1091
source "$SPIKE_DIR/.env"

: "${META_GRAPH_VERSION:?set META_GRAPH_VERSION in .env}"
: "${META_APP_ID:?set META_APP_ID in .env}"
: "${META_APP_SECRET:?set META_APP_SECRET in .env}"

GRAPH="https://graph.facebook.com/$META_GRAPH_VERSION"

umask 077
mkdir -p "$STATE_DIR"

for bin in curl jq openssl; do
  command -v "$bin" >/dev/null || { echo "Required tool not found: $bin" >&2; exit 1; }
done

state_get() { [[ -f "$STATE_DIR/$1" ]] && cat "$STATE_DIR/$1" || true; }
state_put() { printf '%s' "$2" > "$STATE_DIR/$1"; }
require_state() {
  local v; v="$(state_get "$1")"
  [[ -n "$v" ]] || { echo "No $1 in .state - run the earlier step first." >&2; exit 1; }
  printf '%s' "$v"
}

appsecret_proof() {
  printf '%s' "$1" | openssl dgst -sha256 -hmac "$META_APP_SECRET" | awk '{print $NF}'
}

# graph METHOD PATH TOKEN [key=value ...]
# Prints the JSON body. Exits non-zero and prints the Graph error on failure.
graph() {
  local method="$1" path="$2" token="$3"; shift 3
  local cfg body status
  cfg="data-urlencode = \"access_token=$token\"
data-urlencode = \"appsecret_proof=$(appsecret_proof "$token")\""
  local args=(--silent --show-error --write-out '\n%{http_code}' --request "$method")
  [[ "$method" == "GET" || "$method" == "DELETE" ]] && args+=(--get)
  for kv in "$@"; do args+=(--data-urlencode "$kv"); done
  body="$(printf '%s\n' "$cfg" | curl "${args[@]}" --config - "$GRAPH/$path")"
  status="${body##*$'\n'}"
  body="${body%$'\n'*}"
  if [[ "$status" != 2* ]]; then
    echo "Graph API $method /$path failed with HTTP $status:" >&2
    echo "$body" | jq '.error | {message, type, code, error_subcode, fbtrace_id}' >&2 || echo "$body" >&2
    return 1
  fi
  printf '%s' "$body"
}

# Show what a token is without printing it.
describe_token() {
  local token="$1" app_token="$META_APP_ID|$META_APP_SECRET"
  printf 'data-urlencode = "input_token=%s"\ndata-urlencode = "access_token=%s"\n' "$token" "$app_token" \
    | curl --silent --show-error --get --config - "$GRAPH/debug_token" \
    | jq '.data | {type, app_id, application, is_valid, scopes, granular_scopes,
                   expires_at: (if .expires_at == 0 then "never" else (.expires_at | todate) end),
                   data_access_expires_at: (if (.data_access_expires_at // 0) == 0 then null else (.data_access_expires_at | todate) end)}'
}
