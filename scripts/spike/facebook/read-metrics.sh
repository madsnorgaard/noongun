#!/usr/bin/env bash
# Reads the numbers of one Page post from the real Graph API and prints every
# answer as Facebook sent it, with the token removed. It only reads.
#
#   ./read-metrics.sh <post-id>
#
# The answers replace the hand-written fixtures in
# tests/Fixtures/facebook/insights/ and settle the points listed as not
# confirmed in docs/platform-notes/meta.md. See README.md in this folder.

set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

[[ -f "$DIR/.env" ]] || { echo "Missing $DIR/.env - copy .env.example and fill it in." >&2; exit 1; }
# shellcheck disable=SC1091
source "$DIR/.env"

: "${META_GRAPH_VERSION:?set META_GRAPH_VERSION in .env}"
: "${META_APP_SECRET:?set META_APP_SECRET in .env}"
: "${META_PAGE_TOKEN:?set META_PAGE_TOKEN in .env}"

POST_FIELDS="${SPIKE_POST_FIELDS:-reactions.summary(total_count).limit(0),comments.filter(stream).summary(true).limit(0),shares}"
INSIGHT_METRICS="${SPIKE_INSIGHT_METRICS:-post_clicks,post_total_media_view_unique}"
INSIGHT_PERIOD="${SPIKE_INSIGHT_PERIOD:-lifetime}"

post_id="${1:?usage: read-metrics.sh <post-id>}"
[[ "$post_id" =~ ^[0-9]+(_[0-9]+)?$ ]] || { echo "A post id is digits, or digits_digits." >&2; exit 1; }

for bin in curl jq openssl perl; do
  command -v "$bin" >/dev/null || { echo "Required tool not found: $bin" >&2; exit 1; }
done

# The only address this script talks to. No redirect is followed.
GRAPH="https://graph.facebook.com/$META_GRAPH_VERSION"
PROOF="$(printf '%s' "$META_PAGE_TOKEN" | openssl dgst -sha256 -hmac "$META_APP_SECRET" | awk '{print $NF}')"

# Removes the token, the proof and the app secret from whatever passes
# through, also where Facebook repeats them in a paging address.
redact() {
  TOKEN="$META_PAGE_TOKEN" PROOF="$PROOF" SECRET="$META_APP_SECRET" perl -pe '
    s/\Q$ENV{TOKEN}\E/[redacted]/g;
    s/\Q$ENV{PROOF}\E/[redacted]/g;
    s/\Q$ENV{SECRET}\E/[redacted]/g;
    s/(access_token|appsecret_proof)=[^&"\\\s]+/$1=[redacted]/g;
  '
}

# call TITLE METHOD PATH [key=value ...]
# The token and the proof go to curl on stdin, never on the command line.
call() {
  local title="$1" method="$2" path="$3"; shift 3
  local args=(--silent --show-error --max-redirs 0 --proto '=https' --include --request "$method")
  [[ "$method" == "GET" ]] && args+=(--get)
  for kv in "$@"; do args+=(--data-urlencode "$kv"); done

  printf '\n===== %s =====\n' "$title"
  printf '%s /%s' "$method" "$path"
  for kv in "$@"; do printf ' [%s]' "$kv"; done
  printf '\n\n'

  printf 'data-urlencode = "access_token=%s"\ndata-urlencode = "appsecret_proof=%s"\n' "$META_PAGE_TOKEN" "$PROOF" \
    | curl "${args[@]}" --config - "$GRAPH/$path" \
    | tr -d '\r' \
    | grep -iv '^set-cookie:' \
    | pretty \
    | redact || true
}

# Keeps the status line and the headers that matter, and indents the body.
pretty() {
  local in_body=0 line body=""
  while IFS= read -r line || [[ -n "$line" ]]; do
    if (( in_body )); then
      body+="$line"$'\n'
    elif [[ -z "$line" ]]; then
      in_body=1
    elif [[ "$line" =~ ^(HTTP/|[Xx]-[Bb]usiness-[Uu]se-[Cc]ase-[Uu]sage:|[Xx]-[Aa]pp-[Uu]sage:|[Xx]-[Pp]age-[Uu]sage:|[Rr]etry-[Aa]fter:|[Ww][Ww][Ww]-[Aa]uthenticate:|[Ll]ocation:|[Ff]acebook-[Aa][Pp][Ii]-[Vv]ersion:) ]]; then
      printf '%s\n' "$line"
    fi
  done
  printf '\n'
  jq . <<<"$body" 2>/dev/null || printf '%s' "$body"
}

echo "Reading post $post_id with Graph API $META_GRAPH_VERSION. Nothing is changed on the Page."

call "1. The post: reactions, comments, shares" GET "$post_id" "fields=$POST_FIELDS"

call "2. The insights of the post" GET "$post_id/insights" "metric=$INSIGHT_METRICS" "period=$INSIGHT_PERIOD"

call "3. The same without a period (is lifetime the default?)" GET "$post_id/insights" "metric=$INSIGHT_METRICS"

call "4. Insights nested as a field of the post (would one read be enough?)" GET "$post_id" \
  "fields=$POST_FIELDS,insights.metric($INSIGHT_METRICS).period($INSIGHT_PERIOD)"

call "5. The ids= form (is the answer keyed by id?)" GET "" "ids=$post_id" "fields=$POST_FIELDS"

call "6. A metric name that does not exist (the error for a retired metric)" GET "$post_id/insights" \
  "metric=post_impressions_unique" "period=$INSIGHT_PERIOD"

missing="${post_id%_*}_1"
call "7. A post that does not exist (the error for a deleted post)" GET "$missing" "fields=$POST_FIELDS"

batch="$(jq -cn --arg id "$post_id" --arg missing "$missing" --arg fields "$POST_FIELDS" --arg metrics "$INSIGHT_METRICS" --arg period "$INSIGHT_PERIOD" '[
  {method: "GET", relative_url: ($id + "?fields=" + ($fields | @uri))},
  {method: "GET", relative_url: ($id + "/insights?metric=" + ($metrics | @uri) + "&period=" + ($period | @uri))},
  {method: "GET", relative_url: ($missing + "?fields=" + ($fields | @uri))}
]')"

call "8. The batch the adapter sends: the post, its insights, and a post that does not exist" POST "" \
  "batch=$batch" "include_headers=true"

cat <<MSG

===== What to record =====
In docs/platform-notes/meta.md, under the insights section:
  1. Whether 'shares' is absent or {"count": 0} for a post nobody shared.
  2. The entries of the insights answer: name, period, values[0].value.
  3. Whether call 4 answered with insights inside the post. If it did, one
     read for each post is enough and metricCallsPerVariant() can become 1.
  4. The shape of call 5, and what call 7 answers (code and error_subcode).
  5. What call 6 answers for a retired metric.
  6. In call 8: the envelope of each entry, and whether the usage header is
     on the batch, on each entry, or on both.
  7. On a Page with fewer than 100 likes: what calls 2 and 8 answer.
  8. With a token without read_insights: what calls 2 and 8 answer.
Then replace the files in tests/Fixtures/facebook/insights/ with these
answers, with ids and trace ids swapped for the placeholder values.
MSG
