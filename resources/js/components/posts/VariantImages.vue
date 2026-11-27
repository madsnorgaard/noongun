<script setup lang="ts">
import { ChevronDown, ChevronUp, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import ImageViewer from '@/components/posts/ImageViewer.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { altFor, imageCount, megabytes, typeList, typeName } from '@/lib/media';
import { textLength } from '@/lib/posts';
import { enqueue, fileKey, storedCopy } from '@/lib/uploadQueue';
import type { UploadFailure, UploadState } from '@/lib/uploadQueue';
import type {
    ChannelOption,
    MediaInput,
    MediaItem,
    UploadRules,
} from '@/types/posts';

/**
 * The images of one text in the editor: adding them, describing them,
 * putting them in order and taking them off. A file is stored the moment
 * it is uploaded, but it is on the post only once the draft is saved; the
 * page that holds the columns keeps the list and saves it. What reaches
 * into other columns (a description that several channels share, the same
 * order everywhere) is asked of that page.
 */
const props = defineProps<{
    channel: ChannelOption;
    uploads: UploadRules;
    /** Every image the page knows, by id. */
    library: Record<number, MediaItem>;
    /** The images this text has as it is saved. */
    savedIds: number[];
    /** The other texts of the post, with the images they have. */
    others: {
        channel_id: number;
        name: string;
        media: MediaInput[];
        max_items: number;
    }[];
    /**
     * What the server said about the saved images of this text, keyed by
     * "" for all of them, "{id}" for one and "{id}.alt_text" for its
     * description. Empty while the draft has changes that are not saved.
     */
    problems: Record<string, string>;
    /** What the last save refused about the images of this text. */
    errors: string[];
    /** Images the last save said no longer exist. */
    goneIds: number[];
    /** A description a model suggested with the draft. Offered, never filled in. */
    suggestedAltText: string | null;
}>();

const emit = defineEmits<{
    uploaded: [image: MediaItem];
    share: [mediaId: number, channelIds: number[]];
    uploading: [count: number];
    describe: [mediaId: number, text: string];
    describeAll: [mediaId: number];
    orderAll: [];
}>();

const media = defineModel<MediaInput[]>('media', { required: true });

const rules = computed(() => props.channel.media);
const accepts = computed(() => rules.value.max_items > 0);
const name = computed(() => props.channel.display_name);
const platform = computed(() => props.channel.platform_label);

const altLimit = computed(() =>
    rules.value.alt_text_length === null
        ? null
        : Math.min(rules.value.alt_text_length, props.uploads.alt_text_length),
);

const tooMany = computed(() =>
    accepts.value ? Math.max(0, media.value.length - rules.value.max_items) : 0,
);

const hasUnsaved = computed(() =>
    media.value.some((item) => !props.savedIds.includes(item.id)),
);

const gone = computed(() =>
    media.value.filter((item) => props.goneIds.includes(item.id)),
);

// What can be uploaded is the limit of this instance. The limit of a
// platform is held against the stored image, which is usually far smaller
// than the file that was sent.
const limits = computed(
    () =>
        `Noon Gun sends up to ${imageCount(rules.value.max_items)} to ${platform.value} on one post. ${typeList(props.uploads.accept)}, up to ${megabytes(props.uploads.max_bytes)} MB each. Each image is resized and stored again for publishing, so a large photo usually ends up much smaller.`,
);

// Read out politely: what was added, moved, removed or refused.
const announcement = ref('');

function announce(text: string): void {
    announcement.value = '';
    void nextTick(() => (announcement.value = text));
}

function focus(id: string): void {
    void nextTick(() => document.getElementById(id)?.focus());
}

function elementId(part: string, mediaId?: number): string {
    return mediaId === undefined
        ? `${part}-${props.channel.id}`
        : `${part}-${props.channel.id}-${mediaId}`;
}

function names(list: { name: string }[]): string {
    return list.map((each) => each.name).join(', ');
}

// Describing

function altOver(item: MediaInput): number {
    return altLimit.value === null
        ? 0
        : Math.max(0, textLength(item.alt_text.trim()) - altLimit.value);
}

// The other channels that have this image, by whether what is typed here
// is typed there too: their description is the same, or still empty.
function sharing(item: MediaInput): {
    together: { name: string }[];
    apart: { name: string }[];
} {
    const together: { name: string }[] = [];
    const apart: { name: string }[] = [];

    props.others.forEach((other) => {
        const theirs = other.media.find((each) => each.id === item.id);

        if (theirs) {
            (theirs.alt_text === '' || theirs.alt_text === item.alt_text
                ? together
                : apart
            ).push({ name: other.name });
        }
    });

    return { together, apart };
}

function describeEverywhere(mediaId: number): void {
    emit('describeAll', mediaId);
    announce(
        'The description is now the same on every channel that has this image.',
    );
    focus(elementId('image-alt', mediaId));
}

function useSuggestion(mediaId: number): void {
    if (props.suggestedAltText !== null) {
        emit('describe', mediaId, props.suggestedAltText);
        focus(elementId('image-alt', mediaId));
    }
}

/**
 * Why this image cannot go out on this channel, from what is known of the
 * stored file. What only the server knows comes in with `problems`. The
 * next step is one that works: the stored file is not the file that was
 * uploaded, so "a smaller file" may store at the same size. Fewer pixels
 * do not, and neither does JPEG where the stored file is a PNG.
 */
function fileProblem(item: MediaInput): string | null {
    const image = props.library[item.id];

    if (props.goneIds.includes(item.id)) {
        return 'This image is gone. An upload that is on no saved post is removed after a day. Take it off and add the file again.';
    }

    if (!accepts.value) {
        return null;
    }

    if (image) {
        const until = 'The post cannot be approved until it is taken off.';

        if (!rules.value.types.includes(image.mime)) {
            return `Stored as a ${typeName(image.mime)} file, which is not sent to ${platform.value}. ${until} Add it again as ${typeList(rules.value.types)}.`;
        }

        if (
            rules.value.max_bytes !== null &&
            image.byte_size > rules.value.max_bytes
        ) {
            const limit = `Noon Gun sends ${megabytes(rules.value.max_bytes)} MB at most to ${platform.value} for one image.`;

            return image.mime === 'image/png'
                ? `Stored as a PNG file of ${megabytes(image.byte_size)} MB. ${limit} ${until} Add it again as a JPEG, or with fewer pixels.`
                : `Stored at ${megabytes(image.byte_size)} MB. ${limit} ${until} Add it again with fewer pixels.`;
        }

        if (
            rules.value.max_pixels !== null &&
            image.width * image.height > rules.value.max_pixels
        ) {
            return `Stored at ${image.width} x ${image.height} pixels. Noon Gun sends ${Math.round(rules.value.max_pixels / 100000) / 10} megapixels at most to ${platform.value}. ${until} Add it again with fewer pixels.`;
        }
    }

    return props.problems[String(item.id)] ?? null;
}

function removeGone(): void {
    media.value = media.value.filter(
        (item) => !props.goneIds.includes(item.id),
    );
    announce(
        `The images that are gone were taken off the text for ${name.value}.`,
    );
    focus(elementId('add-images'));
}

// Order

function move(index: number, by: -1 | 1): void {
    const target = index + by;

    if (target < 0 || target >= media.value.length) {
        return;
    }

    const next = [...media.value];
    const [moved] = next.splice(index, 1);

    next.splice(target, 0, moved);
    media.value = next;

    announce(
        `Image moved to position ${target + 1} of ${next.length} for ${name.value}.`,
    );

    // The keyboard stays with the image that moved. At either end the
    // button that was pressed is switched off, so the other one takes it.
    const atEnd = by === -1 ? target === 0 : target === next.length - 1;
    const same = by === -1 ? 'image-earlier' : 'image-later';
    const other = by === -1 ? 'image-later' : 'image-earlier';

    focus(elementId(atEnd ? other : same, moved.id));
}

// The order is kept per channel: moving an image here moves it nowhere
// else. The channels where the images both have are in another order.
const otherOrders = computed(() => {
    const mine = media.value.map((item) => item.id);

    return props.others.filter((other) => {
        const theirs = other.media
            .map((item) => item.id)
            .filter((id) => mine.includes(id));
        const shared = mine.filter((id) => theirs.includes(id));

        return (
            shared.length > 1 &&
            shared.some((id, position) => id !== theirs[position])
        );
    });
});

function orderEverywhere(): void {
    const changed = names(otherOrders.value);

    emit('orderAll');
    announce(
        `The images for ${changed} are now in the order of ${name.value}.`,
    );
    focus(elementId('images'));
}

function remove(index: number): void {
    const next = media.value.filter((_, position) => position !== index);
    const follower = next[index] ?? next[index - 1];

    media.value = next;
    announce(`Image ${index + 1} was taken off the text for ${name.value}.`);

    // To the image that took its place, or to where one can be added.
    if (follower) {
        focus(elementId('image-remove', follower.id));
    } else {
        focus(elementId(accepts.value ? 'add-images' : 'images'));
    }
}

// The same image on other channels

function targetsFor(mediaId: number): { channel_id: number; name: string }[] {
    return props.others.filter(
        (other) =>
            other.max_items > 0 &&
            other.media.length < other.max_items &&
            !other.media.some((item) => item.id === mediaId),
    );
}

const everyOtherTakesImages = computed(() =>
    props.others.every((other) => other.max_items > 0),
);

function share(
    mediaId: number,
    targets: { channel_id: number; name: string }[],
): void {
    if (targets.length === 0) {
        return;
    }

    emit(
        'share',
        mediaId,
        targets.map((target) => target.channel_id),
    );
    announce(
        `The image was added for ${names(targets)}, with its description. It is one file.`,
    );
    // The button that was pressed may be gone now.
    void nextTick(() =>
        (
            document.getElementById(elementId('image-alt', mediaId)) ??
            document.getElementById(elementId('image-remove', mediaId))
        )?.focus(),
    );
}

// The list goes back to its prompt once a channel was picked from it.
const picks = ref<Record<number, string>>({});

function sharePicked(mediaId: number, channelId: string | undefined): void {
    if (channelId === undefined || channelId === '') {
        return;
    }

    picks.value[mediaId] = channelId;
    share(
        mediaId,
        targetsFor(mediaId).filter(
            (target) => String(target.channel_id) === channelId,
        ),
    );
    void nextTick(() => (picks.value[mediaId] = ''));
}

// Uploading. The files of every column go through one queue for the page
// (lib/uploadQueue), one request at a time.

type Pending = {
    key: number;
    file: File;
    name: string;
    status: UploadState | 'failed';
    /** Percent of the file that has left this computer. */
    progress: number;
    error: string;
    retryable: boolean;
    cancel: (() => void) | null;
};

const pending = ref<Pending[]>([]);
const input = ref<HTMLInputElement | null>(null);
let nextKey = 0;

const inFlight = computed(
    () => pending.value.filter((item) => item.status !== 'failed').length,
);
const free = computed(() =>
    Math.max(0, rules.value.max_items - media.value.length - inFlight.value),
);

watch(inFlight, (count) => emit('uploading', count), { immediate: true });

function row(key: number): Pending | undefined {
    return pending.value.find((candidate) => candidate.key === key);
}

function drop(key: number): void {
    pending.value = pending.value.filter((candidate) => candidate.key !== key);
}

function refuse(file: File, error: string): void {
    pending.value.push({
        key: nextKey++,
        file,
        name: file.name,
        status: 'failed',
        progress: 0,
        error,
        retryable: false,
        cancel: null,
    });
    announce(error);
}

function fullMessage(fileName: string): string {
    return `${fileName} was not added. Noon Gun sends ${imageCount(rules.value.max_items)} at most to ${platform.value} on one post. Take one off to add another.`;
}

function refusal(
    failure: UploadFailure,
    fileName: string,
): { error: string; retryable: boolean } {
    const limit = megabytes(props.uploads.max_bytes);

    if (failure.status === 422) {
        let sentence: unknown;

        try {
            sentence = (
                JSON.parse(failure.body) as { errors?: { file?: unknown[] } }
            ).errors?.file?.[0];
        } catch {
            sentence = undefined;
        }

        return {
            error:
                typeof sentence === 'string' && sentence !== ''
                    ? sentence
                    : `${fileName} was refused. Choose another file.`,
            retryable: false,
        };
    }

    switch (failure.status) {
        case 0:
            return {
                error: `${fileName} did not reach the server. Check the connection, then try again.`,
                retryable: true,
            };
        case 413:
            return {
                error: `${fileName} is larger than this instance accepts (${limit} MB). Choose a smaller file.`,
                retryable: false,
            };
        case 429:
            return {
                error: `The server stayed busy with other images, so ${fileName} was not sent. Wait a minute, then try again.`,
                retryable: true,
            };
        case 403:
            return {
                error: 'You are not allowed to add images in this workspace. Ask an owner to make you an editor.',
                retryable: false,
            };
        case 401:
        case 419:
            return {
                error: `This page is out of date. Save what you can, reload the page, then add ${fileName} again.`,
                retryable: false,
            };
        default:
            return {
                error: `${fileName} could not be stored. Try again. If it keeps failing, tell the person who runs this instance.`,
                retryable: true,
            };
    }
}

// Puts a stored image on this text. The same image is never on it twice.
function attach(image: MediaItem, fileName: string, reused: boolean): void {
    emit('uploaded', image);

    if (media.value.some((existing) => existing.id === image.id)) {
        refuse(
            new File([], fileName),
            `${fileName} is already on the text for ${name.value}. It was not added a second time.`,
        );

        return;
    }

    media.value = [...media.value, { id: image.id, alt_text: '' }];
    announce(
        reused
            ? `${fileName} was already stored from this page, so it was added without a second upload. It is on the post once the draft is saved.`
            : `${fileName} was uploaded. It is on the post once the draft is saved.`,
    );
}

function start(key: number): void {
    const item = row(key);

    if (!item) {
        return;
    }

    item.status = 'waiting';
    item.error = '';
    item.cancel = enqueue({
        file: item.file,
        url: props.uploads.url,
        field: props.uploads.field,
        onState: (state, progress) => {
            const current = row(key);

            if (current) {
                current.status = state;
                current.progress = progress;
            }
        },
        onDone: (image) => {
            const fileName = row(key)?.name ?? item.name;

            drop(key);
            attach(image, fileName, false);
        },
        onFail: (failure) => {
            const current = row(key);

            if (current) {
                const told = refusal(failure, current.name);

                current.status = 'failed';
                current.error = told.error;
                current.retryable = told.retryable;
                current.cancel = null;
                announce(told.error);
            }
        },
    });
}

/**
 * Takes files from the picker, a drop or the clipboard. What cannot be
 * stored is refused here, before anything is sent, and a file that was
 * already stored from this page is not sent again.
 */
function addFiles(files: File[]): void {
    if (!accepts.value) {
        return;
    }

    const limit = megabytes(props.uploads.max_bytes);
    let room = free.value;

    for (const file of files) {
        const copy = storedCopy(file);
        const sameInFlight = pending.value.some(
            (item) =>
                item.status !== 'failed' &&
                fileKey(item.file) === fileKey(file),
        );

        if (file.type !== '' && !props.uploads.accept.includes(file.type)) {
            refuse(
                file,
                `${file.name} is not a ${typeList(props.uploads.accept)} file. Save it as one of these, then add it again.`,
            );
        } else if (file.size > props.uploads.max_bytes) {
            refuse(
                file,
                `${file.name} is ${megabytes(file.size)} MB. This instance accepts ${limit} MB at most. Choose a smaller file.`,
            );
        } else if (sameInFlight) {
            refuse(
                file,
                `${file.name} is already being uploaded for ${name.value}. It was not added a second time.`,
            );
        } else if (copy && media.value.some((item) => item.id === copy.id)) {
            attach(copy, file.name, true);
        } else if (room < 1) {
            refuse(file, fullMessage(file.name));
        } else if (copy) {
            room--;
            attach(copy, file.name, true);
        } else {
            room--;

            const key = nextKey++;

            pending.value.push({
                key,
                file,
                name: file.name,
                status: 'waiting',
                progress: 0,
                error: '',
                retryable: false,
                cancel: null,
            });
            start(key);
        }
    }
}

function onPicked(event: Event): void {
    const field = event.target as HTMLInputElement;

    addFiles(Array.from(field.files ?? []));
    // So that the same file can be picked again after it was taken off.
    field.value = '';
}

function retry(item: Pending): void {
    if (free.value < 1) {
        item.error = fullMessage(item.name);
        item.retryable = false;
        announce(item.error);

        return;
    }

    start(item.key);
}

function cancel(item: Pending): void {
    item.cancel?.();
    drop(item.key);
    announce(`The upload of ${item.name} was cancelled.`);
    focus(elementId('add-images'));
}

function dismiss(item: Pending): void {
    drop(item.key);
    focus(elementId('add-images'));
}

onBeforeUnmount(() => {
    pending.value.forEach((item) => item.cancel?.());
    emit('uploading', 0);
});

// Looking at one image

const viewing = ref<number | null>(null);
const viewerOpen = ref(false);

const viewed = computed(() => {
    const index = media.value.findIndex((item) => item.id === viewing.value);

    return index === -1
        ? null
        : {
              index,
              image: props.library[media.value[index].id] ?? null,
              altText: media.value[index].alt_text,
          };
});

function view(mediaId: number): void {
    viewing.value = mediaId;
    viewerOpen.value = true;
}

defineExpose({ addFiles });
</script>

<template>
    <div
        :id="elementId('images')"
        tabindex="-1"
        role="group"
        class="grid gap-3 rounded-lg outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        :aria-labelledby="elementId('images-title')"
        :data-test="`images-${channel.id}`"
    >
        <div class="flex items-baseline justify-between gap-3">
            <h3
                :id="elementId('images-title')"
                class="text-sm text-muted-foreground"
            >
                Images for {{ name }}
            </h3>
            <span
                v-if="accepts"
                class="font-mono text-sm"
                :class="
                    tooMany > 0 ? 'text-destructive' : 'text-muted-foreground'
                "
                :data-test="`image-count-${channel.id}`"
            >
                <span class="sr-only">
                    {{ media.length }} of {{ rules.max_items }} images:
                </span>
                {{ media.length }} / {{ rules.max_items }}
            </span>
        </div>

        <p class="sr-only" role="status" aria-live="polite">
            {{ announcement }}
        </p>

        <p
            v-if="!accepts && media.length === 0"
            class="text-sm text-muted-foreground"
            :data-test="`no-images-${channel.id}`"
        >
            Noon Gun cannot send images to {{ platform }} yet. The text for
            {{ name }} goes out without images.
        </p>

        <ol v-if="media.length" class="grid gap-3">
            <li
                v-for="(item, index) in media"
                :key="item.id"
                class="grid grid-cols-[4.5rem_minmax(0,1fr)] items-start gap-3 rounded-lg border border-border p-3"
                :data-test="`image-${channel.id}-${item.id}`"
            >
                <button
                    v-if="library[item.id]"
                    type="button"
                    class="block rounded-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring"
                    :aria-label="`Open image ${index + 1} for ${name} at full size`"
                    @click="view(item.id)"
                >
                    <img
                        :src="library[item.id].thumb_url"
                        :alt="altFor(item.alt_text)"
                        width="72"
                        height="72"
                        loading="lazy"
                        decoding="async"
                        class="size-18 rounded-sm bg-secondary object-cover"
                    />
                </button>
                <span
                    v-else
                    class="flex size-18 items-center justify-center rounded-sm bg-secondary text-xs text-muted-foreground"
                >
                    Image {{ index + 1 }}
                </span>

                <div class="grid min-w-0 gap-2">
                    <div
                        v-if="altLimit !== null && accepts"
                        class="grid gap-1.5"
                    >
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-x-3"
                        >
                            <Label :for="elementId('image-alt', item.id)">
                                Description of image {{ index + 1 }} for
                                {{ name }}
                            </Label>
                            <span
                                v-if="!savedIds.includes(item.id)"
                                class="rounded-sm bg-secondary px-2 py-0.5 text-xs"
                            >
                                Not saved yet
                            </span>
                        </div>
                        <textarea
                            :id="elementId('image-alt', item.id)"
                            :value="item.alt_text"
                            rows="2"
                            class="w-full resize-y rounded-lg border border-input bg-card px-3 py-2 text-base outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            :aria-invalid="
                                altOver(item) > 0 ? 'true' : undefined
                            "
                            :aria-describedby="`${elementId('image-alt-count', item.id)} ${elementId('image-alt-note', item.id)}`"
                            @input="
                                emit(
                                    'describe',
                                    item.id,
                                    ($event.target as HTMLTextAreaElement)
                                        .value,
                                )
                            "
                        ></textarea>
                        <div
                            class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1"
                        >
                            <div
                                :id="elementId('image-alt-note', item.id)"
                                class="grid min-w-0 flex-[1_1_12rem] gap-1"
                            >
                                <p
                                    v-if="altOver(item) > 0"
                                    class="text-sm text-destructive"
                                    :data-test="`image-alt-too-long-${channel.id}-${item.id}`"
                                >
                                    Too long: Noon Gun sends
                                    {{ altLimit }} characters at most to
                                    {{ platform }} as a description. The post
                                    cannot be approved until it fits.
                                </p>
                                <p
                                    v-else-if="
                                        problems[`${item.id}.alt_text`] !==
                                        undefined
                                    "
                                    class="text-sm text-destructive"
                                >
                                    {{ problems[`${item.id}.alt_text`] }}
                                </p>
                                <!-- Advice, not a problem: it carries the
                                     Check tag and holds nothing back. -->
                                <p
                                    v-else-if="item.alt_text.trim() === ''"
                                    class="rounded-md bg-warning-surface px-3 py-2 text-sm"
                                    :data-test="`image-alt-advice-${channel.id}-${item.id}`"
                                >
                                    <span
                                        class="mr-1.5 rounded-sm bg-warning-fill px-2 py-0.5 text-xs font-bold text-white"
                                    >
                                        Check
                                    </span>
                                    No description yet. People who use a screen
                                    reader hear this instead of the picture. The
                                    post can be approved without one.
                                </p>
                                <!-- One description for an image on several
                                     channels, until a channel is given its
                                     own. -->
                                <p
                                    v-if="sharing(item).together.length"
                                    class="text-sm text-muted-foreground"
                                    :data-test="`image-alt-shared-${channel.id}-${item.id}`"
                                >
                                    What you type here is also the description
                                    on {{ names(sharing(item).together) }}.
                                </p>
                                <p
                                    v-if="sharing(item).apart.length"
                                    class="text-sm text-muted-foreground"
                                    :data-test="`image-alt-apart-${channel.id}-${item.id}`"
                                >
                                    {{ names(sharing(item).apart) }}
                                    {{
                                        sharing(item).apart.length === 1
                                            ? 'has a description of its own'
                                            : 'have descriptions of their own'
                                    }}
                                    for this image.
                                </p>
                            </div>
                            <span
                                :id="elementId('image-alt-count', item.id)"
                                class="ml-auto font-mono text-sm"
                                :class="
                                    altOver(item) > 0
                                        ? 'text-destructive'
                                        : 'text-muted-foreground'
                                "
                            >
                                <template v-if="altOver(item) > 0">
                                    {{ altOver(item) }} over the limit of
                                    {{ altLimit }}
                                </template>
                                <template v-else>
                                    <span class="sr-only">
                                        {{
                                            altLimit -
                                            textLength(item.alt_text.trim())
                                        }}
                                        characters left:
                                    </span>
                                    {{ textLength(item.alt_text.trim()) }} /
                                    {{ altLimit }}
                                </template>
                            </span>
                        </div>
                        <Button
                            v-if="
                                sharing(item).apart.length &&
                                item.alt_text.trim() !== ''
                            "
                            type="button"
                            size="sm"
                            variant="outline"
                            class="w-fit"
                            :aria-label="`Use the description of image ${index + 1} for ${name} on all channels that have this image`"
                            :data-test="`image-alt-all-${channel.id}-${item.id}`"
                            @click="describeEverywhere(item.id)"
                        >
                            Use this description on all channels
                        </Button>
                        <!-- What a model suggested with the draft. Shown
                             as text and used only when asked for. -->
                        <div
                            v-if="
                                index === 0 &&
                                suggestedAltText !== null &&
                                suggestedAltText.trim() !== '' &&
                                item.alt_text.trim() === ''
                            "
                            class="grid gap-1.5 rounded-md bg-secondary px-3 py-2 text-sm"
                            :data-test="`image-alt-suggestion-${channel.id}`"
                        >
                            <p class="break-words whitespace-pre-line">
                                <span class="text-muted-foreground">
                                    Suggested with the draft:
                                </span>
                                {{ suggestedAltText }}
                            </p>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                class="w-fit"
                                :aria-label="`Use the suggested description for image 1 for ${name}`"
                                @click="useSuggestion(item.id)"
                            >
                                Use the suggested description
                            </Button>
                        </div>
                    </div>
                    <div v-else class="grid gap-1">
                        <p class="text-base">
                            Image {{ index + 1 }} for {{ name }}
                            <span
                                v-if="!savedIds.includes(item.id)"
                                class="ml-1 rounded-sm bg-secondary px-2 py-0.5 text-xs"
                            >
                                Not saved yet
                            </span>
                        </p>
                        <p v-if="accepts" class="text-sm text-muted-foreground">
                            Noon Gun sends no description to {{ platform }} for
                            an image.
                        </p>
                    </div>

                    <p
                        v-if="fileProblem(item)"
                        class="text-sm text-destructive"
                        :data-test="`image-problem-${channel.id}-${item.id}`"
                    >
                        {{ fileProblem(item) }}
                    </p>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            :id="elementId('image-earlier', item.id)"
                            type="button"
                            size="icon-sm"
                            variant="outline"
                            :disabled="index === 0"
                            :aria-label="`Move image ${index + 1} for ${name} earlier`"
                            @click="move(index, -1)"
                        >
                            <ChevronUp />
                        </Button>
                        <Button
                            :id="elementId('image-later', item.id)"
                            type="button"
                            size="icon-sm"
                            variant="outline"
                            :disabled="index === media.length - 1"
                            :aria-label="`Move image ${index + 1} for ${name} later`"
                            @click="move(index, 1)"
                        >
                            <ChevronDown />
                        </Button>
                        <Button
                            :id="elementId('image-remove', item.id)"
                            type="button"
                            size="sm"
                            variant="ghost"
                            class="text-muted-foreground"
                            :aria-label="`Take image ${index + 1} off the text for ${name}`"
                            :data-test="`image-remove-${channel.id}-${item.id}`"
                            @click="remove(index)"
                        >
                            <X />
                            Take off
                        </Button>
                    </div>

                    <!-- The same file on another channel, not a second
                         upload. -->
                    <div
                        v-if="targetsFor(item.id).length"
                        class="grid gap-1.5"
                        :data-test="`image-share-${channel.id}-${item.id}`"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                v-if="targetsFor(item.id).length === 1"
                                type="button"
                                size="sm"
                                variant="outline"
                                :aria-label="`Also add image ${index + 1} for ${name} to ${targetsFor(item.id)[0].name}, with its description`"
                                @click="share(item.id, targetsFor(item.id))"
                            >
                                Also add to {{ targetsFor(item.id)[0].name }}
                            </Button>
                            <template v-else>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    :aria-label="`Add image ${index + 1} for ${name} to ${everyOtherTakesImages ? 'all channels' : 'the other channels that take images'}, with its description`"
                                    @click="share(item.id, targetsFor(item.id))"
                                >
                                    {{
                                        everyOtherTakesImages
                                            ? 'Add to all channels'
                                            : 'Add to the other channels that take images'
                                    }}
                                </Button>
                                <div class="w-full sm:w-56">
                                    <NativeSelect
                                        :model-value="picks[item.id] ?? ''"
                                        :aria-label="`Also add image ${index + 1} for ${name} to one other channel, with its description`"
                                        @update:model-value="
                                            (value: string | undefined) =>
                                                sharePicked(item.id, value)
                                        "
                                    >
                                        <option value="">Also add to...</option>
                                        <option
                                            v-for="target in targetsFor(
                                                item.id,
                                            )"
                                            :key="target.channel_id"
                                            :value="String(target.channel_id)"
                                        >
                                            {{ target.name }}
                                        </option>
                                    </NativeSelect>
                                </div>
                            </template>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            It is the same file, and the description goes with
                            it.
                        </p>
                    </div>
                </div>
            </li>
        </ol>

        <!-- The order is for this channel. It is said, and one press
             makes the other channels follow. -->
        <div
            v-if="otherOrders.length"
            class="flex flex-wrap items-center gap-x-3 gap-y-1.5"
            :data-test="`images-order-${channel.id}`"
        >
            <p class="flex-[1_1_14rem] text-sm text-muted-foreground">
                This order is for {{ name }} only.
                {{ names(otherOrders) }}
                {{ otherOrders.length === 1 ? 'has' : 'have' }} the same images
                in another order.
            </p>
            <Button
                type="button"
                size="sm"
                variant="outline"
                :aria-label="`Use the order of the images for ${name} on all channels`"
                @click="orderEverywhere"
            >
                Use this order on all channels
            </Button>
        </div>

        <p
            v-if="!accepts && media.length > 0"
            class="text-sm text-destructive"
            :data-test="`images-problem-${channel.id}`"
        >
            Noon Gun cannot send images to {{ platform }} yet. Take these images
            off before the post can be approved.
        </p>
        <p
            v-else-if="tooMany > 0"
            class="text-sm text-destructive"
            :data-test="`images-problem-${channel.id}`"
        >
            Too many images: Noon Gun sends {{ rules.max_items }} at most to
            {{ platform }} on one post. Take {{ tooMany }} off before the post
            can be approved.
        </p>
        <p
            v-else-if="problems[''] !== undefined"
            class="text-sm text-destructive"
            :data-test="`images-problem-${channel.id}`"
        >
            {{ problems[''] }}
        </p>

        <div
            v-if="gone.length"
            class="flex flex-wrap items-center gap-x-3 gap-y-1.5"
            :data-test="`images-gone-${channel.id}`"
        >
            <p class="flex-[1_1_14rem] text-sm text-destructive">
                The draft was not saved:
                {{
                    gone.length === 1
                        ? '1 image for this channel is gone'
                        : `${gone.length} images for this channel are gone`
                }}. An upload that is on no saved post is removed after a day.
                Take
                {{ gone.length === 1 ? 'it' : 'them' }}
                off, save the draft, then add the
                {{ gone.length === 1 ? 'file' : 'files' }} again.
            </p>
            <Button
                type="button"
                size="sm"
                variant="outline"
                :aria-label="`Take the images that are gone off the text for ${name}`"
                @click="removeGone"
            >
                {{
                    gone.length === 1
                        ? 'Take off the image that is gone'
                        : 'Take off the images that are gone'
                }}
            </Button>
        </div>
        <ul v-else-if="errors.length" class="grid gap-1">
            <li
                v-for="message in errors"
                :key="message"
                class="text-sm text-destructive"
            >
                {{ message }}
            </li>
        </ul>

        <ul
            v-if="pending.length"
            class="grid gap-2"
            :data-test="`uploads-${channel.id}`"
        >
            <li
                v-for="item in pending"
                :key="item.key"
                class="grid gap-1.5 rounded-lg border border-border px-3 py-2.5 text-sm"
            >
                <template v-if="item.status === 'failed'">
                    <p class="break-words text-destructive">
                        <span class="font-semibold">Not added.</span>
                        {{ item.error }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="item.retryable"
                            type="button"
                            size="sm"
                            variant="outline"
                            :aria-label="`Try again to upload ${item.name}`"
                            @click="retry(item)"
                        >
                            Try again
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            class="text-muted-foreground"
                            :aria-label="`Dismiss the message about ${item.name}`"
                            @click="dismiss(item)"
                        >
                            Dismiss
                        </Button>
                    </div>
                </template>
                <template v-else>
                    <p
                        class="flex flex-wrap items-baseline justify-between gap-x-3"
                    >
                        <span class="min-w-0 truncate">{{ item.name }}</span>
                        <span class="font-mono text-muted-foreground">
                            <template v-if="item.status === 'waiting'">
                                Waiting for its turn
                            </template>
                            <template v-else-if="item.status === 'held'">
                                Waiting for the server to finish another image
                            </template>
                            <template v-else-if="item.progress < 100">
                                Uploading, {{ item.progress }}%
                            </template>
                            <template v-else>
                                Uploaded, being checked
                            </template>
                        </span>
                    </p>
                    <div class="flex items-center gap-3">
                        <progress
                            class="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full accent-primary"
                            max="100"
                            :value="item.progress"
                            :aria-label="`Upload of ${item.name}`"
                        ></progress>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            class="text-muted-foreground"
                            :aria-label="`Cancel the upload of ${item.name}`"
                            @click="cancel(item)"
                        >
                            Cancel
                        </Button>
                    </div>
                </template>
            </li>
        </ul>

        <div v-if="accepts" class="grid gap-1.5">
            <input
                ref="input"
                type="file"
                multiple
                hidden
                :accept="uploads.accept.join(',')"
                :data-test="`image-input-${channel.id}`"
                @change="onPicked"
            />
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <Button
                    :id="elementId('add-images')"
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="free < 1"
                    :aria-label="`Add images for ${name}`"
                    :aria-describedby="elementId('image-limits')"
                    :data-test="`add-images-${channel.id}`"
                    @click="input?.click()"
                >
                    Add images
                </Button>
                <p
                    v-if="free < 1 && tooMany === 0"
                    class="text-sm text-muted-foreground"
                >
                    That is as many as Noon Gun sends to {{ platform }} on one
                    post.
                </p>
            </div>
            <p
                :id="elementId('image-limits')"
                class="text-sm text-muted-foreground"
                :data-test="`image-limits-${channel.id}`"
            >
                {{ limits }} Choose files, drop them on this column, or paste an
                image into the text.
            </p>
            <p
                v-if="hasUnsaved"
                class="text-sm text-muted-foreground"
                :data-test="`images-unsaved-${channel.id}`"
            >
                The files are stored and kept for a day. Save the draft to keep
                them on the post.
            </p>
        </div>

        <ImageViewer
            v-model:open="viewerOpen"
            :image="viewed?.image ?? null"
            :alt-text="viewed?.altText ?? null"
            :title="
                viewed
                    ? `Image ${viewed.index + 1} of ${media.length} for ${name}`
                    : ''
            "
        />
    </div>
</template>
