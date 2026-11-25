#!/usr/bin/env bash
# LinkedIn spike (M5): publish one text post and one link post to the profile
# the token belongs to, print their ids and permalinks, wait, then delete them.
# Makes real calls. See README.md in this folder before running.
#
# Secrets never go on the command line: curl reads the Authorization header
# and the client secret from a config block on stdin.

set -euo pipefail

SPIKE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck disable=SC1091
[[ -f "$SPIKE_DIR/.env" ]] && source "$SPIKE_DIR/.env"

: "${LINKEDIN_ACCESS_TOKEN:?set LINKEDIN_ACCESS_TOKEN in .env or in the environment}"
: "${LINKEDIN_API_VERSION:?set LINKEDIN_API_VERSION (YYYYMM) in .env or in the environment}"

API="https://api.linkedin.com"
WEB="https://www.linkedin.com"

for bin in curl jq; do
  command -v "$bin" >/dev/null || { echo "Required tool not found: $bin" >&2; exit 1; }
done

umask 077
WORK="$(mktemp -d)"
created=()

# api METHOD PATH [json-body]
# Leaves the status in $status, the body in $body and the headers in $WORK/headers.
api() {
  local method="$1" path="$2" data="${3:-}" out
  local args=(--silent --show-error --max-redirs 0 --request "$method"
    --dump-header "$WORK/headers" --write-out '\n%{http_code}'
    --header "X-Restli-Protocol-Version: 2.0.0")
  [[ "$path" == rest/* ]] && args+=(--header "Linkedin-Version: $LINKEDIN_API_VERSION")
  [[ "$method" == "DELETE" ]] && args+=(--header "X-RestLi-Method: DELETE")
  [[ -n "$data" ]] && args+=(--header "Content-Type: application/json" --data "$data")
  out="$(printf 'header = "Authorization: Bearer %s"\n' "$LINKEDIN_ACCESS_TOKEN" | curl "${args[@]}" --config - "$API/$path")"
  status="${out##*$'\n'}"
  body="${out%$'\n'*}"
}

fail() {
  echo "$1 failed with HTTP $status:" >&2
  echo "$body" | jq . >&2 2>/dev/null || echo "$body" >&2
  exit 1
}

urlencode() { jq -rn --arg v "$1" '$v|@uri'; }

delete_created() {
  for urn in "${created[@]:-}"; do
    [[ -n "$urn" ]] || continue
    api DELETE "rest/posts/$(urlencode "$urn")"
    echo "Deleted $urn: HTTP $status"
  done
  rm -rf "$WORK"
}
trap delete_created EXIT

# publish LABEL JSON
publish() {
  api POST rest/posts "$2"
  [[ "$status" == "201" ]] || fail "Creating the $1"
  local urn
  urn="$(grep -i '^x-restli-id:' "$WORK/headers" | tr -d '\r' | awk '{print $2}')"
  [[ -n "$urn" ]] || { echo "The $1 was created (HTTP 201) but no x-restli-id header came back." >&2; exit 1; }
  created+=("$urn")
  echo "Published the $1"
  echo "  id:        $urn"
  echo "  permalink: $WEB/feed/update/$urn/"
}

echo "== Who the token belongs to (GET /v2/userinfo)"
api GET v2/userinfo
[[ "$status" == "200" ]] || fail "Reading userinfo"
sub="$(jq -r .sub <<<"$body")"
echo "  sub:  $sub"
echo "  name: $(jq -r .name <<<"$body")"
author="urn:li:person:$sub"

if [[ -n "${LINKEDIN_CLIENT_ID:-}" && -n "${LINKEDIN_CLIENT_SECRET:-}" ]]; then
  echo "== What LinkedIn says about the token (POST /oauth/v2/introspectToken)"
  printf 'data-urlencode = "client_id=%s"\ndata-urlencode = "client_secret=%s"\ndata-urlencode = "token=%s"\n' \
    "$LINKEDIN_CLIENT_ID" "$LINKEDIN_CLIENT_SECRET" "$LINKEDIN_ACCESS_TOKEN" \
    | curl --silent --show-error --max-redirs 0 --config - "$WEB/oauth/v2/introspectToken" \
    | jq '{active, status, scope, auth_type, expires_at: ((.expires_at // 0) | todate)}'
fi

stamp="$(date -u +%Y-%m-%dT%H:%MZ)"

echo "== Text post, with every reserved character escaped and one hashtag"
publish "text post" "$(jq -n --arg author "$author" --arg stamp "$stamp" '{
  author: $author,
  commentary: ("Noon Gun spike test post " + $stamp + ". This will be deleted shortly. Reserved characters: \\| \\{ \\} \\@ \\[ \\] \\( \\) \\< \\> \\# \\\\ \\* \\_ \\~ and a hashtag #noongunspike"),
  visibility: "PUBLIC",
  distribution: {feedDistribution: "MAIN_FEED", targetEntities: [], thirdPartyDistributionChannels: []},
  lifecycleState: "PUBLISHED",
  isReshareDisabledByAuthor: false
}')"

thumbnail=""
if [[ -n "${SPIKE_THUMBNAIL:-}" ]]; then
  echo "== Thumbnail upload (POST /rest/images?action=initializeUpload, then PUT)"
  api POST "rest/images?action=initializeUpload" "$(jq -n --arg owner "$author" '{initializeUploadRequest: {owner: $owner}}')"
  [[ "$status" == "200" ]] || fail "Initialising the upload"
  upload_url="$(jq -r .value.uploadUrl <<<"$body")"
  thumbnail="$(jq -r .value.image <<<"$body")"
  host="$(sed -E 's#^https://([^/:?]+).*$#\1#' <<<"$upload_url")"
  echo "  upload host: $host"
  # The token and the image only go to an HTTPS address on linkedin.com.
  if [[ "$upload_url" != https://* || ! "$host" =~ ^([a-z0-9-]+\.)*linkedin\.com$ ]]; then
    echo "The upload address is not an HTTPS address on linkedin.com. Nothing was sent to it." >&2
    exit 1
  fi
  upload_status="$(printf 'header = "Authorization: Bearer %s"\n' "$LINKEDIN_ACCESS_TOKEN" \
    | curl --silent --show-error --max-redirs 0 --output /dev/null --write-out '%{http_code}' \
        --upload-file "$SPIKE_THUMBNAIL" --config - "$upload_url")"
  echo "  PUT answered HTTP $upload_status, image $thumbnail"
fi

echo "== Link post, with a title and description supplied by us"
publish "link post" "$(jq -n --arg author "$author" --arg stamp "$stamp" --arg thumbnail "$thumbnail" '{
  author: $author,
  commentary: ("Noon Gun spike test link post " + $stamp + ". This will be deleted shortly."),
  visibility: "PUBLIC",
  distribution: {feedDistribution: "MAIN_FEED", targetEntities: [], thirdPartyDistributionChannels: []},
  content: {article: ({
    source: "https://github.com/madsnorgaard/noongun?utm_source=linkedin&utm_medium=social",
    title: "Noon Gun (spike title, typed by hand)",
    description: "A description that LinkedIn did not read from the page."
  } + (if $thumbnail == "" then {} else {thumbnail: $thumbnail} end))},
  lifecycleState: "PUBLISHED",
  isReshareDisabledByAuthor: false
}')"

echo
echo "Open both permalinks, logged in and in a logged-out browser, and note what"
echo "you see for docs/platform-notes/linkedin.md. Then press Enter to delete both posts."
read -r _
