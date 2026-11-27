#!/usr/bin/env bash
# LinkedIn spike (M5, and images for #25): publish one text post and one link
# post to the profile the token belongs to, and with SPIKE_IMAGE set also a
# post with one image and, with SPIKE_IMAGE_2, one with two. Print their ids
# and permalinks, wait, then delete them.
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

# What the run found, one line each, without the token, the member id, an
# upload address or a URN. Printed at the end, to paste into
# docs/platform-notes/linkedin.md.
summary=()
note() {
  summary+=("- $1")
  echo "  -> $1"
}

# said: the status, code and message of the last answer, on one line.
said() {
  local code message
  code="$(jq -r '.code // "-"' <<<"$body" 2>/dev/null || echo -)"
  message="$(jq -r '.message // "-"' <<<"$body" 2>/dev/null | tr '\n' ' ' | cut -c1-300 || echo -)"
  # A message may repeat a URN. The id part says nothing and is cut.
  message="$(sed -E 's#(urn:li:[A-Za-z]+:)[A-Za-z0-9_-]+#\1...#g' <<<"$message")"
  echo "HTTP $status, code $code, message \"$message\""
}

deleted=()
delete_created() {
  for urn in "${created[@]:-}"; do
    [[ -n "$urn" ]] || continue
    api DELETE "rest/posts/$(urlencode "$urn")"
    echo "Deleted $urn: HTTP $status"
    deleted+=("$status")
  done
  rm -rf "$WORK"
  if (( ${#summary[@]} > 0 )); then
    echo
    echo "== Summary for docs/platform-notes/linkedin.md (no token, no ids)"
    echo "- Date run: $(date -u +%Y-%m-%d), Linkedin-Version $LINKEDIN_API_VERSION"
    printf '%s\n' "${summary[@]}"
    echo "- Deletes: ${#created[@]} posts created, statuses: ${deleted[*]:-none}"
  fi
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
  note "The $1 was accepted (HTTP 201, a ${urn%:*} URN)."
}

# attempt LABEL JSON
# Creates a post that LinkedIn may well refuse, and writes down what it
# answers instead of stopping. A post that is created after all is deleted
# with the rest. Sent once: a post call is never repeated here.
attempt() {
  local urn
  api POST rest/posts "$2"
  if [[ "$status" == "201" ]]; then
    urn="$(grep -i '^x-restli-id:' "$WORK/headers" | tr -d '\r' | awk '{print $2}')"
    [[ -n "$urn" ]] && created+=("$urn")
    echo "  permalink: $WEB/feed/update/$urn/"
    note "$1: accepted with HTTP 201."
    return 0
  fi
  note "$1: refused with $(said)."
  return 1
}

# ask QUESTION
# For what only a person can see. The answer goes into the summary.
ask() {
  local answer
  read -r -p "$1 (yes / no / not sure): " answer
  summary+=("- $1 ${answer:-not answered}")
}

post_json() {
  # post_json COMMENTARY CONTENT-JSON
  jq -n --arg author "$author" --arg commentary "$1" --argjson content "$2" '{
    author: $author,
    commentary: $commentary,
    visibility: "PUBLIC",
    distribution: {feedDistribution: "MAIN_FEED", targetEntities: [], thirdPartyDistributionChannels: []},
    content: $content,
    lifecycleState: "PUBLISHED",
    isReshareDisabledByAuthor: false
  }'
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

# initialise_upload
# Asks LinkedIn where an image goes. Leaves the address in $upload_url, after
# the check, and the URN in $image_urn.
initialise_upload() {
  local host
  api POST "rest/images?action=initializeUpload" "$(jq -n --arg owner "$author" '{initializeUploadRequest: {owner: $owner}}')"
  [[ "$status" == "200" ]] || fail "Initialising the upload"
  upload_url="$(jq -r .value.uploadUrl <<<"$body")"
  image_urn="$(jq -r .value.image <<<"$body")"
  upload_expires="$(jq -r '((.value.uploadUrlExpiresAt // 0) / 1000 | floor | todate)' <<<"$body")"
  # The token and the image only go to a plain HTTPS address on linkedin.com:
  # a host name, port 443 or none, then a path. No user name, no query
  # straight after the host, no fragment, no backslash, no white space.
  # The same rule as UploadAddress in the application.
  if [[ "$upload_url" == *\\* || "$upload_url" == *'#'* || "$upload_url" =~ [[:space:]] ]]; then
    echo "The upload address holds a backslash, a fragment or white space. Nothing was sent to it." >&2
    exit 1
  fi
  if [[ ! "$upload_url" =~ ^https://([A-Za-z0-9.-]+)(:443)?/ ]]; then
    echo "The upload address is not a plain HTTPS address with a path. Nothing was sent to it." >&2
    exit 1
  fi
  host="$(tr '[:upper:]' '[:lower:]' <<<"${BASH_REMATCH[1]}")"
  if [[ ! "$host" =~ ^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)*linkedin\.com$ ]]; then
    echo "The upload address is not on linkedin.com. Nothing was sent to it." >&2
    exit 1
  fi
  upload_host="$host"
}

# put_image FILE HEADERS
# Sends the file to $upload_url and leaves the status in $upload_status.
# HEADERS: "token" (as documented), "token+type" (as the application sends
# it, with Content-Type: application/octet-stream) or "none" (no token).
put_image() {
  local file="$1" headers="$2" config=""
  [[ "$headers" == token* ]] && config+="$(printf 'header = "Authorization: Bearer %s"' "$LINKEDIN_ACCESS_TOKEN")"$'\n'
  [[ "$headers" == *type ]] && config+='header = "Content-Type: application/octet-stream"'$'\n'
  upload_status="$(printf '%s' "$config" \
    | curl --silent --show-error --max-redirs 0 --output /dev/null --write-out '%{http_code}' \
        --upload-file "$file" --config - "$upload_url")"
}

# upload_image FILE
# Sends one image the way the application does and leaves its URN in
# $image_urn.
upload_image() {
  local file="$1" started
  [[ -f "$file" ]] || { echo "No such image: $file" >&2; exit 1; }
  initialise_upload
  started="$(date +%s)"
  put_image "$file" "token+type"
  echo "  PUT answered HTTP $upload_status after $(( $(date +%s) - started )) s ($(wc -c <"$file" | tr -d ' ') bytes)"
  [[ "$upload_status" == 2* ]] || { echo "The upload was not accepted." >&2; exit 1; }
}

thumbnail=""
if [[ -n "${SPIKE_THUMBNAIL:-}" ]]; then
  echo "== Thumbnail upload (POST /rest/images?action=initializeUpload, then PUT)"
  upload_image "$SPIKE_THUMBNAIL"
  thumbnail="$image_urn"
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

if [[ -n "${SPIKE_IMAGE:-}" ]]; then
  [[ -f "$SPIKE_IMAGE" ]] || { echo "No such image: $SPIKE_IMAGE" >&2; exit 1; }
  bytes="$(wc -c <"$SPIKE_IMAGE" | tr -d ' ')"

  echo "== Which headers the upload needs (three uploads of the same file)"
  initialise_upload
  note "Upload address: host $upload_host, valid until $upload_expires."
  put_image "$SPIKE_IMAGE" "token"
  note "PUT with the token only, as documented: HTTP $upload_status."
  initialise_upload
  put_image "$SPIKE_IMAGE" "none"
  note "PUT without the token: HTTP $upload_status."
  initialise_upload
  started="$(date +%s)"
  put_image "$SPIKE_IMAGE" "token+type"
  note "PUT with the token and Content-Type: application/octet-stream, as the application sends it: HTTP $upload_status, $bytes bytes in $(( $(date +%s) - started )) s."
  [[ "$upload_status" == 2* ]] || { echo "The upload the application makes was not accepted. See the summary." >&2; exit 1; }
  first_image="$image_urn"

  echo "== Whether this token may read the status of an image (GET /rest/images/{urn})"
  api GET "rest/images/$(urlencode "$first_image")"
  if [[ "$status" == "200" ]]; then
    note "GET /rest/images/{urn} straight after the upload: HTTP 200, status $(jq -r '.status // "-"' <<<"$body")."
  else
    note "GET /rest/images/{urn}: $(said)."
  fi

  # Created at once, without waiting: this is what the application does. A
  # refusal here is the answer for an image that is not ready, and its
  # status, code and message are what the adapter must recognise. The link
  # is written as the application writes it: its underscores escaped.
  echo "== Post with one image, an alternative text and a link as the last line of the text"
  image_post="$(post_json \
    "Noon Gun spike test image post $stamp. This will be deleted shortly."$'\n\n'"https://github.com/madsnorgaard/noongun?utm\\_source=linkedin&utm\\_medium=social" \
    "$(jq -n --arg image "$first_image" '{media: {id: $image, altText: "Alternative text of the spike image."}}')")"
  waited=0
  until attempt "Post with one image, ${waited} s after the upload" "$image_post"; do
    # Only a refusal in LinkedIn's own format is tried again, and only a
    # few times: after anything else the post may exist.
    if [[ "$status" != 4* || "$status" == "401" || "$status" == "403" || "$status" == "429" || "$waited" -ge 30 ]] \
      || ! jq -e '.message // .code' <<<"$body" >/dev/null 2>&1; then
      echo "Giving up on the image post. See the summary." >&2
      break
    fi
    sleep 10
    waited=$(( waited + 10 ))
  done

  if [[ -n "${SPIKE_IMAGE_2:-}" ]]; then
    echo "== Second image upload"
    upload_image "$SPIKE_IMAGE_2"

    # The first image is used a second time, on purpose.
    echo "== Post with two images, each with an alternative text"
    attempt "Post with two images, the first of them used a second time" "$(post_json \
      "Noon Gun spike test post with two images $stamp. This will be deleted shortly." \
      "$(jq -n --arg first "$first_image" --arg second "$image_urn" '{multiImage: {images: [
        {id: $first, altText: "First spike image."},
        {id: $second, altText: "Second spike image."}
      ]}}')")" || true
  fi

  # The two answers the adapter guesses at. Both posts should be refused;
  # one that is created after all is deleted with the rest.
  echo "== Post with an image whose bytes were never sent (the answer for \"not ready\")"
  initialise_upload
  attempt "Post with an image that is still waiting for its upload" "$(post_json \
    "Noon Gun spike test post with a pending image $stamp. This will be deleted shortly." \
    "$(jq -n --arg image "$image_urn" '{media: {id: $image}}')")" || true

  echo "== Post with an image URN that does not exist (the answer for \"no longer there\")"
  attempt "Post with an image URN that does not exist" "$(post_json \
    "Noon Gun spike test post with an unknown image $stamp. This will be deleted shortly." \
    '{"media": {"id": "urn:li:image:C4E10AQNoonGunSpikeNoSuchImage"}}')" || true
fi

echo
echo "Open every permalink, logged in and in a logged-out browser, and answer"
echo "what follows. The posts are deleted after the last question."
ask "Text post: do the escaped reserved characters show as plain characters, and is the hashtag a hashtag?"
ask "Link post: is the link shown as a card with the typed title?"
if [[ -n "${SPIKE_IMAGE:-}" ]]; then
  ask "Image post: is the image shown at once?"
  ask "Image post: is the address at the end of the text a link that can be clicked?"
  ask "Image post: does the address show and open with utm_source and utm_medium intact, without backslashes?"
  ask "Image post: is the alternative text on the image (inspect the image, or use a screen reader)?"
fi
if [[ -n "${SPIKE_IMAGE_2:-}" ]]; then
  ask "Post with two images: are both shown, in the order first, second?"
fi
