#!/usr/bin/env bash
# Upload one image from a local file as an unpublished photo, publish a Page
# post that names it, print the ids and the permalink, then delete the post.
# This is the path the adapter takes for a post with images. It answers the
# open questions in docs/platform-notes/meta.md under 2026-11-26.
#
#   ./07-upload-photo-post.sh            upload, post, read back, delete
#   KEEP_POST=1 ./07-upload-photo-post.sh    leave the post for 06-delete.sh
source "$(dirname "$0")/lib.sh"
page_token="$(require_state page_token)"
: "${META_PAGE_ID:?}" "${SPIKE_PHOTO_FILE:?set SPIKE_PHOTO_FILE in .env to a local JPEG or PNG}"
[[ -f "$SPIKE_PHOTO_FILE" ]] || { echo "No such file: $SPIKE_PHOTO_FILE" >&2; exit 1; }

case "${SPIKE_PHOTO_FILE,,}" in
  *.png) mime="image/png" ;;
  *.jpg|*.jpeg) mime="image/jpeg" ;;
  *) echo "SPIKE_PHOTO_FILE must end in .jpg, .jpeg or .png" >&2; exit 1 ;;
esac

# Never print a credential, whatever the Graph API echoes back.
redact() {
  local proof; proof="$(appsecret_proof "$page_token")"
  sed -e "s/$page_token/[redacted]/g" -e "s/$proof/[redacted]/g" -e "s/$META_APP_SECRET/[redacted]/g"
}

alt_text="Noon Gun spike: a test image that will be deleted shortly."

# Multipart upload. The token, the proof and the file travel in the body; the
# credentials come from a config block on stdin, as in lib.sh.
upload="$(printf 'form-string = "access_token=%s"\nform-string = "appsecret_proof=%s"\n' \
    "$page_token" "$(appsecret_proof "$page_token")" \
  | curl --silent --show-error --write-out '\n%{http_code}' --request POST --config - \
      --form-string "published=false" \
      --form-string "alt_text_custom=$alt_text" \
      --form "source=@\"$SPIKE_PHOTO_FILE\";type=$mime" \
      "$GRAPH/$META_PAGE_ID/photos")"
status="${upload##*$'\n'}"
upload="${upload%$'\n'*}"
echo "Upload: HTTP $status $(redact <<<"$upload" | jq -c . 2>/dev/null || redact <<<"$upload")"
[[ "$status" == 2* ]] || exit 1

photo_id="$(jq -r '.id // empty' <<<"$upload")"
[[ "$photo_id" =~ ^[0-9]+$ ]] || { echo "No numeric photo id returned." >&2; exit 1; }
echo "Unpublished photo id: $photo_id (post_id in the answer: $(jq -r '.post_id // "none"' <<<"$upload"))"

# What Facebook says about the unpublished photo, alt text included.
graph GET "$photo_id" "$page_token" "fields=id,alt_text,alt_text_custom,width,height,created_time" | redact | jq -c .

message="Noon Gun spike test post with an uploaded photo $(date -u +%Y-%m-%dT%H:%MZ). This will be deleted shortly.

https://example.com/"
resp="$(graph POST "$META_PAGE_ID/feed" "$page_token" "message=$message" "attached_media[0]={\"media_fbid\":\"$photo_id\"}")"
echo "Post: $(redact <<<"$resp" | jq -c .)"
post_id="$(jq -r '.id // empty' <<<"$resp")"
[[ -n "$post_id" ]] || { echo "No post id returned." >&2; exit 1; }
echo "$post_id" >> "$STATE_DIR/posts"
echo "Published post: $post_id"

"$(dirname "$0")/05-permalink.sh" "$post_id" | redact

if [[ -n "${KEEP_POST:-}" ]]; then
  echo "Post kept. Check the permalink, then run ./06-delete.sh"
  exit 0
fi

graph DELETE "$post_id" "$page_token" >/dev/null && echo "Deleted $post_id"
grep -vxF "$post_id" "$STATE_DIR/posts" > "$STATE_DIR/posts.tmp" || true
mv "$STATE_DIR/posts.tmp" "$STATE_DIR/posts"
