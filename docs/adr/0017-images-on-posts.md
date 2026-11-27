# 0017 - Images on posts

Date: 2026-11-26. Status: accepted. Extends ADR 0003.

## Context

Posts were text and a link. Issue #25 asks for media, starting with images.
ADR 0003 already says where files live: on a Laravel disk, local in Phase 1
and S3-compatible later, with `disk` and `path` in the `media` table and no
code that assumes a local path. This record settles everything else: what an
upload is turned into, who can see it, how an image belongs to a post, what
an approval covers, and how a post with images is published without ever
being published twice.

Three facts shape the decisions.

- An upload is the classic way into a web application. The file comes from a
  browser, and nothing it says about itself can be believed.
- A photo carries more than pixels. A phone writes the place it was taken
  into the file. A post that goes public must not take that along.
- The instance may be reachable on a tailnet only (ADR 0008). A platform
  cannot fetch an image from it. And a platform takes an image in one call
  and the post in another, so publishing is no longer one call.

## Decision

### An upload is decoded and written again

`StoreMedia` takes one file. What it is, is decided from its content, never
from its name or from the type the browser gave:

1. `ImageInspector` reads the header (`getimagesize` and a few bytes of our
   own). It gives the type and the size in pixels without decoding anything.
   Only JPEG, PNG and WebP go on. SVG, GIF, HEIC, an animated PNG, an
   animated WebP and everything else are refused.
2. The size in pixels is held against `noongun.media.max_megapixels` and
   `max_side`. Decoding takes four bytes of memory for each pixel, so a file
   of a few hundred bytes that claims 30000 x 30000 pixels is refused here.
3. `ImageProcessor` decodes the image, scales it down when its longer side
   is above `stored_side`, turns it upright, and encodes it again. Only the
   result is stored. The file that was sent is never kept.

Encoding again is what makes the stored file trustworthy. It holds pixels
and nothing else: no EXIF and so no GPS position, no comments, no colour
profile, no second file behind the image data. GD writes a comment of its
own into every JPEG, naming itself and the quality. That is taken out of the
file again, so a stored file does not say how it was made either.

`ImageProcessor` is an interface, and `GdImageProcessor` is the one class
that touches the image library. The inspector, the limits and the two small
JPEG readers are plain PHP and stay in front of whatever engine decodes. An
open question for the owner is libvips, for output that keeps colours right
(see Consequences); changing the engine is a second class and one line.

The orientation of a JPEG is the one EXIF value that is used, because
without it a photo from a phone would lie on its side. It is read by a small
parser of our own (`JpegOrientation`) that looks at nothing else in the
block, and it is applied to the pixels before the block is dropped. PHP's
`exif` extension is not installed: it would parse the whole of an untrusted
metadata block for one number.

**A stored image is a JPEG or a PNG.** Those are the types both platforms
take. Which of the two is decided from the pixels, not from the type the
file came as:

- An image in which any pixel is not opaque is a PNG. The header only says
  whether the format could carry transparency; whether it does is found by
  looking at the decoded pixels.
- An opaque image that came in a lossless format (PNG, lossless WebP) and
  has at most `lossless_choice_megapixels` (9) is written as PNG and as
  JPEG, and the smaller file is kept. A screenshot or a drawing stays a
  sharp, exact PNG. A photograph that was saved as PNG becomes a JPEG: a
  12 megapixel PNG photo of 7.7 MB was stored as 3.0 MB, where it used to
  be stored larger than it came and above what either platform takes.
- Everything else is a JPEG.

**A JPEG does not grow.** The configured quality (90) is the most. A JPEG
that is not scaled down is written at the quality it came with when that is
lower, never below 60. The quality is estimated from the quantisation table
in the file (`JpegQuality`), which for the common encoders is right to a
point. The estimate can only lower the quality, so a wrong one costs a
little sharpness on one file. An image that is scaled down is a new image
and gets the configured quality.

**An animation is refused, not flattened.** GD reads the first frame of a
GIF, an animated PNG or an animated WebP. Storing that would turn
somebody's animation into a still picture without a word. GIF is refused as
a type; an animated PNG is known by its `acTL` chunk and an animated WebP
by a flag in its header, both before anything is decoded.

**The same picture is stored once in a workspace.** Encoding is the same
every time, so the same picture sent twice gives the same stored bytes. An
upload whose result the workspace already holds is given that image back:
no second file, nothing more on the quota, and the note of the clean-up on
that image is cleared. This is only ever asked within the workspace. Every
member can already see every image of their workspace, so it tells nobody
anything new; across workspaces it would tell one customer that another has
the picture.

Every upload also gets a small JPEG copy (`thumbnail_side`, 480 pixels) next
to it, for the lists.

The image library is GD, which the Docker image now installs. It reads and
writes JPEG, PNG and WebP, and a test fails if WebP support is missing from
the container.

### What limits an upload

All in `config/noongun.php` under `media`, each with an environment variable:

| Limit | Default | Checked |
|---|---|---|
| Bytes of one file | 20 MB | on the file, before anything else |
| Pixels of one image | 50 megapixels, 16000 on a side | from the header, before decoding |
| Storage of one workspace | none when self-hosted, 2 GB in saas mode, unless set | before decoding, and again under a lock on the workspace when the row is written |
| Uploads of one user | 30 a minute | by the `media-uploads` rate limiter |
| Images decoded at once | 2 on the instance, 1 for each user | by `ProcessingSlots`, around the decoding |
| Images on one text | 10 | when a draft is saved |

Owners and editors upload. Viewers never do. PHP's memory limit is raised to
`memory_limit` (512 MB) for the request that processes an image, and never
lowered.

### Where the file is and what it is called

The disk is named by `NOONGUN_MEDIA_DISK` and defaults to `media`, a local
disk rooted at `storage/app/media` on a Docker volume of its own. The public
disk is refused. A row stores the name of the disk it was written to, so
changing the setting moves nothing and old images keep working.

The path is `w{workspace}/{year}/{month}/{40 random hex characters}.jpg`.
Nothing in it comes from the user. The name the file was uploaded with is
not stored anywhere.

Code reaches a file through the disk only, as a stream (`Media::readStream()`).
The tests run uploads, serving, publishing and the clean-up against a disk
that throws when it is asked for a local path or an address.

### Images are private

There is no address that reaches a file without the application. The disk has
no `url`, is not served by the framework and is not linked into `public/`.

`GET /media/{id}` and `GET /media/{id}/thumb` answer a member of the workspace
the image belongs to. Everybody else gets 404: a member of another workspace,
a visitor who is not signed in, an account that is not verified. The routes
carry no `auth` middleware on purpose, because a redirect to the sign-in page
would confirm that the image exists.

The response is a stream with the `Content-Type` from our own record,
`X-Content-Type-Options: nosniff`, `Content-Disposition: inline` with a name
made here, `Content-Security-Policy: default-src 'none'; sandbox`,
`Cross-Origin-Resource-Policy: same-origin`, an `ETag` from the hash of the
stored file and `Cache-Control: private, no-cache`. The browser keeps its
copy and asks every time whether it may show it. A request with a matching
`If-None-Match` gets 304 without the file, after the same checks, so an
image stops opening the moment its viewer signs out or leaves the
workspace. A copy that could be shown for a day without asking would not.

An image is not a page. The two routes are loaded outside the `web` group
and read the session without writing it (`ReadSessionOnly`): a request for
an image is not recorded as the page to go back to, does not write the
session and sends no cookie. Otherwise every thumbnail on a page would
write the session, and "back" after a failed form could lead to a picture.

Platforms get the bytes by upload from our server. They are never given an
address to fetch from.

### An image is attached, not owned

`media` is the file. `post_variant_media` puts an image on a variant: the
image, its position and its alternative text. One image can be on several
variants, of one post or of several, and that is one row in `media` and one
file. The editor copies an image to another channel by sending the same id.

The alternative text is on the attachment and not on the image, as the brief
had it. It is content a person approves, and it may differ per channel. On
the image it would change under an approved post whenever another post that
uses the same image was edited.

Both tables carry `workspace_id` with composite foreign keys, so an image and
the variant it is on are always in one workspace. `SaveDraft` takes an ordered
list of `{id, alt_text}` per variant. A request names images by id, and an id
of another workspace fails validation exactly as an id that does not exist.
A save that does not mention images leaves them alone; an empty list takes
them all off.

### Images are part of what is approved

An attachment locks with the text. It can be added, changed, moved or removed
only while the post is a draft and the variant is not published; the model
refuses anything else, whoever asks. The fingerprint that an approval sends
back (`PostPresenter::version()`, ADR 0010) includes, for every variant, the
ids of its images in order and each alternative text. Adding, removing,
reordering or describing an image differently changes it.

The readiness check holds back a post when a variant has images on a platform
that takes none, more images than the platform takes, an image of a type or
size it refuses, or an alternative text over its limit.

**A missing alternative text is advice, not a refusal.** The editor says, for
each image without one, that people who use a screen reader will not know
what it shows. Whether a picture needs describing is for the author to
decide; a decorative image may not. Advice is a second list
(`PostPreconditions::advice()`, `post.advice` on the pages), keyed like the
problems, and never blocks an approval.

### Images or a link preview

On both platforms a post with a picture and a post with a link preview are
different kinds of post. `ChannelCapabilities::$mediaLinkRule` says what a
platform does with a variant that has both:

- `in_text`, the default: the images win. The link goes out as the last line
  of the text, after an empty line. No preview and no card is shown, so the
  typed card of ADR 0015 is not asked for.
- `both`: the platform shows both, and nothing changes.
- `refused`: the platform takes one or the other, and the post cannot be
  approved until one is taken off.

For `in_text` the text the platform gets is `PostVariant::textFor()`. The
readiness check measures that text against the limit, the adapters send
exactly that text, and the page says that the link moves. A reader gets the
link once: a text that already ends with its link, as typed or as sent, is
not given a second one, and a typed link at the end is replaced by the link
that is sent, with its campaign parameters. Blocking the
combination was the alternative. It was not chosen as the default because a
drafted post carries its link apart from its text, and adding a picture to
such a draft would stop with an error that the author has to work around by
hand.

A text is still required. A post that is only a picture is not built.

### Publishing a post with images

Publishing a variant with images is several calls: one upload for each image,
then the call that creates the post. Only the last one makes anything public.
ADR 0009 allows one call per variant and marks the variant as "outcome
unknown" before it. That stays true for the creating call. The uploads are a
step before it:

1. An adapter whose platform takes images implements `UploadsMedia`:
   `uploadMedia(PostVariant, MediaAttachment): string`. It sends one image
   and returns what the platform calls it. It must not make anything public.
2. `PublishPostJob`, inside the lock of the variant and **before** it marks
   the variant, calls `uploadMedia()` for every attachment the platform does
   not hold yet, in order, and writes each id on its attachment at once
   (`external_id`, `uploaded_at`), in a statement of its own.
3. Then it marks the variant and calls `publish()`, which reads the ids from
   `$variant->images()` and uploads nothing.

What this gives:

- **A retry never uploads twice and never posts twice.** A job that fails or
  dies between two calls leaves the ids of what it sent. The next one sends
  only what is missing. A second post is impossible for the same reason as
  before: the creating call is still made once, under the mark.
- **A failed upload is never in doubt.** Nothing public was attempted and the
  variant is not marked, so the post can simply be sent again. An adapter may
  therefore mark upload failures as retryable freely, including timeouts and
  server errors, which `publish()` may never treat as retryable. The worst
  case is an unused upload left behind on the platform.
- **The adapter stays stateless.** The job owns the steps and the memory.
- **Time stays bounded.** One run uploads at most `media_uploads_per_job`
  images (2), each within `media_timeout` seconds (60). A variant that needs
  more goes to the successor at once, without counting as an attempt. The
  longest publishing job is now 3 x 30 + 2 x 60 + 15 = 225 seconds, still
  below the `retry_after` of the queue (300).

An upload is forgotten, so that the file is sent again, when the alternative
text of its attachment changes (a platform may have stored the text with the
upload), when the variant moves to another channel, and when it is older than
`ChannelCapabilities::$mediaUploadLifetime`, for a platform that drops
uploads it has not seen used.

Just before uploading, the job asks the readiness check for the images again
and looks for each file on the disk. A variant that fails there is failed
with nothing sent.

### Unused images are cleaned up

Removing an image from a draft, or deleting a draft, removes attachments and
no file. `noongun:prune-unused-media` runs every hour. It notes when it first
sees an image on no variant, forgets the note when the image is in use again,
and deletes the row and then the files once the note is older than
`unused_grace_hours` (24). Attaching an image clears the note at once, so an
image that was put on a post and taken off again between two runs has its
full day from then. So an upload abandoned in the editor goes after a
day, and an image somebody is about to attach is not pulled away.

An image on any variant is never removed, whatever the state of the post. A
published post cannot be deleted (ADR 0010), so its images stay as long as it
does. The database refuses to delete an image that is attached, and saving a
draft holds a shared lock on the images it names, so the clean-up cannot
remove an image in the moment it is being attached.

### Capabilities

`ChannelCapabilities` gains, after the existing fields and with defaults that
change nothing for an adapter that does not set them: `maxMediaBytes`,
`maxMediaPixels`, `altTextLength` (null: the platform takes no alternative
text), `mediaLinkRule` and `mediaUploadLifetime`. No aspect ratio rule was
added: neither platform documents one that refuses an image.

Both adapters declare what they take, from their block in `config/noongun.php`.

The Facebook adapter takes JPEG and PNG, 10 images (an assumption, Meta
documents no count), 10 MB, an alternative text of 1000 characters (an
assumption), `in_text` for the link, and uploads that are used within 20
hours, because Facebook deletes an unpublished photo after about a day. Each
image is uploaded unpublished and one feed call attaches them, for one image
as for several, so only that call makes anything public.

An adapter that learns from a certain refusal of the publish call that its
uploads are gone throws `PublishFailed::uploadsNoLongerValid()`. The job then
forgets the upload state of the variant and the next attempt uploads again.
Uploads made a moment ago are never blamed, so a second refusal is final.

The LinkedIn adapter takes: JPEG and PNG, 20 images,
no limit in bytes (LinkedIn documents none, and refuses an upload it finds
too large), 36,152,319 pixels, an
alternative text of 4086 characters, `in_text` for the link, and uploads
that are used for a day. ADR 0015 has the reasons and what is assumed.

### Drafting is unchanged

A drafted post has no image until a member adds one, so the sentence in the
prompt that only the text and the link are published stays true for what the
model writes. The `alt_text` a draft run suggests is still shown as a
suggestion and is not attached to anything.

## Consequences

- **The Docker image changed.** The `base` stage installs GD, so both the
  `dev` and the `prod` image must be rebuilt. Without GD an upload is refused
  with a sentence that says so.
- **A new volume.** `media` is mounted at `/app/storage/app/media` in `app`,
  `horizon` and `scheduler`. Backups must include it (ADR 0003). In
  development the folder is in the checkout.
- **Three places bound the size of an upload:** `NOONGUN_MEDIA_MAX_BYTES`,
  PHP (`upload_max_filesize`, `post_max_size` in `docker/app/php.ini`, 32 MB)
  and the web server (`NOONGUN_MAX_REQUEST_SIZE`, 32 MiB, in
  `docker-compose.yml`). The smallest applies, and the editor is told that
  one.
- Colour profiles are dropped with the rest of the metadata. A photo in a
  wide colour space (Display P3 from a recent phone) is stored without its
  profile and looks slightly less saturated. Converting to sRGB needs
  ImageMagick and is left until somebody misses it.
- HEIC is refused. A browser on a phone converts to JPEG when the file field
  asks for JPEG, PNG and WebP, which the editor should do.
- Sequential ids are in the addresses of images, as they are for posts. An id
  of another workspace gives 404, as an id that was never given out does.
- One upload decodes one image in the web process. The rate limit does not
  bound that: it counts requests in a minute, not how many run together.
  What bounds it is `ProcessingSlots`: two images at once on the instance
  and one for each user, held as cache locks with a lease of two minutes,
  so a place is given back also when the process is killed. A request waits
  up to five seconds for its turn and is then answered 429 with
  `Retry-After` and `reason: busy`; the limit per minute answers 429 with
  `reason: throttled`. Measured peaks of resident memory for one image:
  282 MB for a 45 megapixel JPEG, 314 MB for 50, and 641 MB for a lossless
  WebP of 49 megapixels, where the codec holds buffers that PHP's memory
  limit does not count. So the web container needs room for about 1.3 GB on
  top of what it uses anyway, with the defaults.
- No memory limit is set on the `app` container. Publishing runs in
  `horizon`, a container of its own, so a limit on `app` could not take
  publishing down; but a limit that is wrong for the machine kills the web
  app in ordinary use, and with the places above the need is bounded. The
  README says how to set one.
- A busy hosted instance will still want decoding on a queue.
- The quota counts the stored images, not their small copies. A self-hosted
  instance has none unless it sets one: the disk belongs to the person who
  runs it, and a photographer posting three photos a day would have met a
  default of 2 GB within months.
- Looking for transparency reads every pixel of an image that could have
  some, in PHP: about a fifth of a second for 11 megapixels.
- Files are written before their row. A process killed in between, a failed
  delete and a deleted workspace leave files that no row points to.
  `noongun:sweep-orphaned-media-files` runs once a week, lists the media
  disks through the Storage API and removes files that have the form of a
  stored image, that no row names, and that are older than
  `orphan_file_hours` (48). It works the same on a bucket; there it costs
  one listing of the media files.
- An image removed from the last post that used it can be attached again for
  a day, but the editor has no list of earlier uploads to pick it from.
- There is no "delete this image now". Unused images go after the grace
  period.
- A variant with images takes a run of the publishing job for itself more
  often, and publishing a post with many images takes several runs.
- `post_variants` gets a unique index on `(id, workspace_id)` as the target of
  the composite foreign key.

## Later

- An image library per workspace, to pick an earlier upload from.
- Alternative text from the model: a member adds an image, and the model
  describes it, as its own kind of run with its own cost.
- A thumbnail for the typed link card on LinkedIn. It is an uploaded image
  and LinkedIn takes one, but an author must be able to say which image it
  is. ADR 0015 records why that is not built and what it takes.
- Video, which needs chunked uploads and processing off the web request.
