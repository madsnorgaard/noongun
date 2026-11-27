#!/usr/bin/env bash
# The image path of the Facebook adapter against the real Graph API, in one
# run on one Page. Nothing in the application has met the real API on this
# path yet; this script is how that is closed (issue #25).
#
# What it does on the Page named by META_PAGE_ID, and only there:
#
#   1. uploads SPIKE_PHOTO_FILE as an unpublished photo with a description
#   2. reads back whether an unpublished photo shows on the Page
#   3. publishes a post with that one photo through attached_media, with a
#      link as the last line of the text, and reads its permalink
#   4. uploads three more photos and publishes one post with all three
#   5. asks for a post with a media_fbid that does not exist, to record the error
#   6. asks for a post with 11 photos, to learn whether there is a count limit
#   7. uploads photos with descriptions of growing length, to find that limit
#   8. deletes every post and every photo it created, and checks that they are gone
#   9. prints a summary as JSON, without credentials, for docs/platform-notes/meta.md
#
# It creates posts and photos, reads them, and deletes what it created. It
# touches nothing else on the Page. Posts are public for the seconds or
# minutes between step 3 and step 8 if the app is in Live mode.
#
# It refuses to run unless SPIKE_CONFIRM_PAGE_ID repeats the Page id:
#
#   SPIKE_CONFIRM_PAGE_ID=1234567890 ./07-upload-photo-post.sh
#
# SPIKE_PAUSE=1 waits for Enter before deleting, so that the posts can be
# looked at in a logged-out browser first.
source "$(dirname "$0")/lib.sh"
page_token="$(require_state page_token)"
: "${META_PAGE_ID:?}" "${SPIKE_PHOTO_FILE:?set SPIKE_PHOTO_FILE in .env to a local JPEG or PNG}"

[[ "$META_PAGE_ID" =~ ^[0-9]+$ ]] || { echo "META_PAGE_ID must be the numeric id of the Page." >&2; exit 1; }

if [[ "${SPIKE_CONFIRM_PAGE_ID:-}" != "$META_PAGE_ID" ]]; then
  cat >&2 <<MSG
This script publishes real posts to Page $META_PAGE_ID and deletes them again.
To run it, repeat the Page id:

  SPIKE_CONFIRM_PAGE_ID=$META_PAGE_ID $0
MSG
  exit 1
fi

[[ -f "$SPIKE_PHOTO_FILE" ]] || { echo "No such file: $SPIKE_PHOTO_FILE" >&2; exit 1; }

case "${SPIKE_PHOTO_FILE,,}" in
  *.png) mime="image/png" ;;
  *.jpg|*.jpeg) mime="image/jpeg" ;;
  *) echo "SPIKE_PHOTO_FILE must end in .jpg, .jpeg or .png" >&2; exit 1 ;;
esac

proof="$(appsecret_proof "$page_token")"

# Never print a credential, whatever the Graph API echoes back.
redact() {
  sed -e "s/$page_token/[redacted]/g" -e "s/$proof/[redacted]/g" -e "s/$META_APP_SECRET/[redacted]/g"
}

STATUS=""
BODY=""
SUMMARY='{}'
created_posts=()
created_photos=()

# call METHOD PATH [key=value ...]
# Sets STATUS and BODY. Never exits: a refusal is a finding here.
call() {
  local method="$1" path="$2" out kv; shift 2
  local args=(--silent --show-error --write-out '\n%{http_code}' --request "$method")
  [[ "$method" == "GET" || "$method" == "DELETE" ]] && args+=(--get)
  for kv in "$@"; do args+=(--data-urlencode "$kv"); done
  out="$(printf 'data-urlencode = "access_token=%s"\ndata-urlencode = "appsecret_proof=%s"\n' "$page_token" "$proof" \
    | curl "${args[@]}" --config - "$GRAPH/$path" || true)"
  STATUS="${out##*$'\n'}"
  BODY="${out%$'\n'*}"
}

# upload [DESCRIPTION]
# Sends the file as multipart, unpublished. The token, the proof and the
# file travel in the body. Sets STATUS and BODY.
upload() {
  local alt="${1:-}" out extra=()
  [[ -n "$alt" ]] && extra=(--form-string "alt_text_custom=$alt")
  out="$(printf 'form-string = "access_token=%s"\nform-string = "appsecret_proof=%s"\n' "$page_token" "$proof" \
    | curl --silent --show-error --write-out '\n%{http_code}' --request POST --config - \
        --form-string "published=false" "${extra[@]}" \
        --form "source=@\"$SPIKE_PHOTO_FILE\";type=$mime" \
        "$GRAPH/$META_PAGE_ID/photos" || true)"
  STATUS="${out##*$'\n'}"
  BODY="${out%$'\n'*}"
}

ok() { [[ "$STATUS" == 2* ]] && ! jq -e '.error' <<<"$BODY" >/dev/null 2>&1; }

# The Graph error of the last call as JSON, or what came back when it is not one.
graph_error() {
  jq -c --arg status "$STATUS" '{http_status: ($status | tonumber? // $status), code: .error.code, subcode: .error.error_subcode,
    type: .error.type, message: .error.message, user_title: .error.error_user_title, user_msg: .error.error_user_msg}' <<<"$BODY" 2>/dev/null \
    || jq -cn --arg status "$STATUS" --arg body "${BODY:0:300}" '{http_status: $status, unreadable: $body}'
}

# note KEY JSON
note() {
  SUMMARY="$(jq -c --arg k "$1" --argjson v "$2" '.[$k] = $v' <<<"$SUMMARY")"
  echo "  $1: $(redact <<<"$2")"
}

# upload_one [DESCRIPTION] -> sets PHOTO_ID, empty when refused, and
# remembers the photo for the clean-up
PHOTO_ID=""
upload_one() {
  PHOTO_ID=""
  upload "${1:-}"
  ok || return 0
  PHOTO_ID="$(jq -r '.id // empty' <<<"$BODY")"
  [[ -n "$PHOTO_ID" ]] && created_photos+=("$PHOTO_ID")
  return 0
}

# attached N id... -> the attached_media parameters for those ids
attached() {
  local i=0 id
  for id in "$@"; do printf 'attached_media[%d]={"media_fbid":"%s"}\n' "$i" "$id"; i=$((i + 1)); done
}

# post_with MESSAGE id... -> sets STATUS and BODY
post_with() {
  local message="$1"; shift
  local params=()
  mapfile -t params < <(attached "$@")
  call POST "$META_PAGE_ID/feed" "message=$message" "${params[@]}"
}

# read_post ID -> JSON of what the post looks like
read_post() {
  call GET "$1" "fields=id,permalink_url,is_published,message,attachments{media_type,type,subattachments.limit(20){type,target{id}}}"
  if ok; then
    jq -c --arg guide "https://www.facebook.com/$1" '{id, is_published, permalink_url, guide_address: $guide,
      permalink_differs_from_guide: (.permalink_url != $guide),
      message_ends_with_link: ((.message // "") | endswith("https://example.com/")),
      attachment_type: (.attachments.data[0].type // null), media_type: (.attachments.data[0].media_type // null),
      photos_in_order: [(.attachments.data[0].subattachments.data // [])[] | .target.id]}' <<<"$BODY"
  else
    graph_error
  fi
}

cleanup() {
  local id
  [[ ${#created_posts[@]} -eq 0 && ${#created_photos[@]} -eq 0 ]] && return 0
  echo
  echo "Deleting what this run created ..."
  local posts_deleted=0 posts_left=() photos_gone_with_post=0 photos_deleted=0 photos_left=()
  for id in "${created_posts[@]}"; do
    call DELETE "$id"
    if ok; then
      posts_deleted=$((posts_deleted + 1))
      # No longer one for 06-delete.sh to remove.
      grep -vxF "$id" "$STATE_DIR/posts" > "$STATE_DIR/posts.tmp" || true
      mv "$STATE_DIR/posts.tmp" "$STATE_DIR/posts"
    else
      posts_left+=("$id"); echo "  could not delete post $id: $(graph_error | redact)" >&2
    fi
  done
  for id in "${created_photos[@]}"; do
    call GET "$id" "fields=id"
    if ! ok; then photos_gone_with_post=$((photos_gone_with_post + 1)); continue; fi
    call DELETE "$id"
    if ok; then photos_deleted=$((photos_deleted + 1)); else photos_left+=("$id"); echo "  could not delete photo $id: $(graph_error | redact)" >&2; fi
  done
  note cleanup "$(jq -cn --argjson pd "$posts_deleted" --argjson pg "$photos_gone_with_post" --argjson phd "$photos_deleted" \
    --arg pl "${posts_left[*]:-}" --arg phl "${photos_left[*]:-}" \
    '{posts_deleted: $pd, photos_already_gone: $pg, photos_deleted: $phd,
      posts_left: ($pl | split(" ")), photos_left: ($phl | split(" "))}')"
  created_posts=()
  created_photos=()
}

finish() {
  local code=$?
  trap - EXIT
  cleanup || true
  echo
  echo "----- summary for docs/platform-notes/meta.md (no credentials) -----"
  jq --arg at "$(date -u +%Y-%m-%dT%H:%MZ)" --arg version "$META_GRAPH_VERSION" --arg mime "$mime" \
     --argjson bytes "$(wc -c < "$SPIKE_PHOTO_FILE")" --argjson complete "$([[ $code -eq 0 ]] && echo true || echo false)" \
     '{run_at: $at, graph_version: $version, file: {type: $mime, bytes: $bytes}, run_completed: $complete} + .' <<<"$SUMMARY" | redact
  echo "----- end of summary -----"
  exit "$code"
}
trap finish EXIT

# Also written where 06-delete.sh looks, in case this run is cut short.
remember_post() { created_posts+=("$1"); echo "$1" >> "$STATE_DIR/posts"; }

stamp="$(date -u +%Y-%m-%dT%H:%MZ)"
alt_text="Noon Gun spike: a test image that will be deleted shortly."

echo "1. One unpublished photo with a description"
upload "$alt_text"
ok || { note upload "$(graph_error)"; echo "The upload was refused. Nothing else can be tried." >&2; exit 1; }
first="$(jq -r '.id // empty' <<<"$BODY")"
[[ "$first" =~ ^[0-9]+$ ]] || { note upload "$(jq -cn --arg b "${BODY:0:300}" '{no_numeric_id: $b}')"; exit 1; }
created_photos+=("$first")
note upload "$(jq -c '{id_is_numeric: (.id | test("^[0-9]+$")), answer_has_post_id: has("post_id"), answer_keys: keys}' <<<"$BODY")"

call GET "$first" "fields=id,alt_text,alt_text_custom,width,height"
if ok; then
  note photo_read_back "$(jq -c --arg sent "$alt_text" '{alt_text_custom_kept: (.alt_text_custom == $sent), alt_text_custom_length: ((.alt_text_custom // "") | length), width, height}' <<<"$BODY")"
else
  note photo_read_back "$(graph_error)"
fi

echo "2. Is an unpublished photo visible on the Page?"
call GET "$META_PAGE_ID/photos" "type=uploaded" "fields=id" "limit=100"
if ok; then in_photos="$(jq --arg id "$first" '[.data[].id] | index($id) != null' <<<"$BODY")"; else in_photos='"unknown"'; fi
call GET "$META_PAGE_ID/feed" "fields=id,attachments{target{id}}" "limit=25"
if ok; then in_feed="$(jq --arg id "$first" '[.data[] | (.attachments.data // [])[] | .target.id] | index($id) != null' <<<"$BODY")"; else in_feed='"unknown"'; fi
note unpublished_photo "$(jq -cn --argjson p "$in_photos" --argjson f "$in_feed" '{listed_in_page_photos: $p, shown_in_page_feed: $f}')"

echo "3. A post with that one photo and a link as the last line of the text"
post_with "Noon Gun spike, one photo, $stamp. This will be deleted shortly.

https://example.com/" "$first"
if ok; then
  one="$(jq -r '.id' <<<"$BODY")"
  remember_post "$one"
  note post_one_photo "$(jq -c --argjson answer "$(jq -c 'keys' <<<"$BODY")" '. + {answer_keys: $answer}' <<<"$(read_post "$one")")"
else
  note post_one_photo "$(graph_error)"
fi

echo "4. A post with three photos"
three=()
for n in 1 2 3; do
  upload_one "Noon Gun spike: test image $n of 3."
  [[ -n "$PHOTO_ID" ]] && three+=("$PHOTO_ID")
done
if [[ ${#three[@]} -eq 3 ]]; then
  post_with "Noon Gun spike, three photos, $stamp. This will be deleted shortly." "${three[@]}"
  if ok; then
    many="$(jq -r '.id' <<<"$BODY")"
    remember_post "$many"
    note post_three_photos "$(jq -c --arg sent "${three[*]}" '. + {order_kept: (.photos_in_order == ($sent | split(" ")))}' <<<"$(read_post "$many")")"
  else
    note post_three_photos "$(graph_error)"
  fi
else
  note post_three_photos "$(jq -cn --argjson n "${#three[@]}" '{uploads_taken: $n, of: 3}')"
fi

echo "5. A post with a media_fbid that does not exist"
post_with "Noon Gun spike, invalid photo id, $stamp. This must not appear." "1"
if ok; then
  id="$(jq -r '.id' <<<"$BODY")"; remember_post "$id"
  note invalid_media_fbid '{"refused": false, "note": "a post was created and is deleted below"}'
else
  note invalid_media_fbid "$(jq -c '. + {refused: true}' <<<"$(graph_error)")"
fi

echo "6. A post with 11 photos"
eleven=()
for n in $(seq 1 11); do
  upload_one
  [[ -n "$PHOTO_ID" ]] || break
  eleven+=("$PHOTO_ID")
done
if [[ ${#eleven[@]} -eq 11 ]]; then
  post_with "Noon Gun spike, eleven photos, $stamp. This will be deleted shortly." "${eleven[@]}"
  if ok; then
    id="$(jq -r '.id' <<<"$BODY")"; remember_post "$id"
    note eleven_photos "$(jq -c '{refused: false, photos_on_post: (.photos_in_order | length)}' <<<"$(read_post "$id")")"
  else
    note eleven_photos "$(jq -c '. + {refused: true}' <<<"$(graph_error)")"
  fi
else
  note eleven_photos "$(jq -cn --argjson n "${#eleven[@]}" --argjson last "$(graph_error)" '{uploads_taken: $n, of: 11, last_refusal: $last}')"
fi

echo "7. Descriptions of growing length"
lengths='[]'
for length in 101 1000 1001 5000 20000; do
  text="$(printf 'a%.0s' $(seq 1 "$length"))"
  upload "$text"
  if ok; then
    id="$(jq -r '.id' <<<"$BODY")"; created_photos+=("$id")
    call GET "$id" "fields=alt_text_custom"
    kept="$(jq '(.alt_text_custom // "") | length' <<<"$BODY" 2>/dev/null || echo null)"
    lengths="$(jq -c --argjson l "$length" --argjson k "${kept:-null}" '. + [{sent: $l, taken: true, kept: $k}]' <<<"$lengths")"
  else
    lengths="$(jq -c --argjson l "$length" --argjson e "$(graph_error)" '. + [{sent: $l, taken: false, error: $e}]' <<<"$lengths")"
  fi
done
note description_lengths "$lengths"

if [[ -n "${SPIKE_PAUSE:-}" ]]; then
  cat <<MSG

The posts are still on the Page. In a private browser window that is not
logged in to Facebook, open:
$(for id in "${created_posts[@]}"; do echo "  https://www.facebook.com/$id"; done)
Record whether they show. Press Enter to delete everything this run created.
MSG
  read -r _
fi

echo "8. Deleting, then checking that the posts are gone"
posts=("${created_posts[@]}")
cleanup
gone=0
for id in "${posts[@]}"; do
  call GET "$id" "fields=id"
  ok || gone=$((gone + 1))
done
note posts_gone_after_delete "$(jq -cn --argjson g "$gone" --argjson n "${#posts[@]}" '{gone: $g, of: $n}')"
