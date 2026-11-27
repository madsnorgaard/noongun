<script setup lang="ts">
import { ChevronDown, ChevronUp, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import ImageViewer from '@/components/posts/ImageViewer.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { altFor, imageCount, megabytes, typeList } from '@/lib/media';
import { textLength } from '@/lib/posts';
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
 * page that holds the columns keeps the list and saves it.
 */
const props = defineProps<{
    channel: ChannelOption;
    uploads: UploadRules;
    /** Every image the page knows, by id. */
    library: Record<number, MediaItem>;
    /** The images this text has as it is saved. */
    savedIds: number[];
    /** The other texts of the post that could take an image of this one. */
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
}>();

const emit = defineEmits<{
    uploaded: [image: MediaItem];
    share: [mediaId: number, channelIds: number[]];
    uploading: [count: number];
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

const limits = computed(() => {
    const types = typeList(props.uploads.accept);
    // The smaller of what this instance stores and what the platform takes.
    const size = megabytes(
        Math.min(
            props.uploads.max_bytes,
            rules.value.max_bytes ?? props.uploads.max_bytes,
        ),
    );

    return rules.value.max_items === 1
        ? `1 image, ${types}, up to ${size} MB.`
        : `Up to ${rules.value.max_items} images, ${types}, ${size} MB each.`;
});

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

// Describing

function altOver(item: MediaInput): number {
    return altLimit.value === null
        ? 0
        : Math.max(0, textLength(item.alt_text.trim()) - altLimit.value);
}

function setAltText(mediaId: number, event: Event): void {
    const text = (event.target as HTMLTextAreaElement).value;

    media.value = media.value.map((item) =>
        item.id === mediaId ? { ...item, alt_text: text } : item,
    );
}

/**
 * Why this image cannot go out on this channel, from what is known of the
 * file. What only the server knows comes in with `problems`.
 */
function fileProblem(item: MediaInput): string | null {
    const image = props.library[item.id];

    if (!accepts.value) {
        return null;
    }

    if (image) {
        if (!rules.value.types.includes(image.mime)) {
            return `${platform.value} does not take this type of image. The post cannot be approved until it is taken off. Add it again as ${typeList(rules.value.types)}.`;
        }

        if (
            rules.value.max_bytes !== null &&
            image.byte_size > rules.value.max_bytes
        ) {
            return `Too large for ${platform.value}: ${megabytes(image.byte_size)} MB, and the limit is ${megabytes(rules.value.max_bytes)} MB. The post cannot be approved until it is taken off. Add a smaller file in its place.`;
        }

        if (
            rules.value.max_pixels !== null &&
            image.width * image.height > rules.value.max_pixels
        ) {
            return `Too large for ${platform.value}: ${image.width} x ${image.height} pixels, and the limit is ${Math.round(rules.value.max_pixels / 100000) / 10} megapixels. The post cannot be approved until it is taken off. Add a smaller image in its place.`;
        }
    }

    return props.problems[String(item.id)] ?? null;
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
        `The image was added for ${targets.map((target) => target.name).join(', ')}. It is one file, and each channel has its own description.`,
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

// Uploading

type Pending = {
    key: number;
    file: File;
    name: string;
    status: 'waiting' | 'sending' | 'failed';
    /** Percent of the file that has left this computer. */
    progress: number;
    error: string;
    retryable: boolean;
};

const pending = ref<Pending[]>([]);
const input = ref<HTMLInputElement | null>(null);
let nextKey = 0;
let request: XMLHttpRequest | null = null;

const inFlight = computed(
    () => pending.value.filter((item) => item.status !== 'failed').length,
);
const free = computed(() =>
    Math.max(0, rules.value.max_items - media.value.length - inFlight.value),
);

watch(inFlight, (count) => emit('uploading', count), { immediate: true });

function refuse(file: File, error: string): void {
    pending.value.push({
        key: nextKey++,
        file,
        name: file.name,
        status: 'failed',
        progress: 0,
        error,
        retryable: false,
    });
    announce(error);
}

/**
 * Takes files from the picker, a drop or the clipboard. What cannot be
 * stored is refused here, before anything is sent.
 */
function addFiles(files: File[]): void {
    if (!accepts.value) {
        return;
    }

    const limit = megabytes(props.uploads.max_bytes);
    let room = free.value;

    for (const file of files) {
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
        } else if (room < 1) {
            refuse(
                file,
                `${file.name} was not added. ${platform.value} takes ${imageCount(rules.value.max_items)} at most on a post. Take one off to add another.`,
            );
        } else {
            room--;
            pending.value.push({
                key: nextKey++,
                file,
                name: file.name,
                status: 'waiting',
                progress: 0,
                error: '',
                retryable: false,
            });
        }
    }

    sendNext();
}

function onPicked(event: Event): void {
    const field = event.target as HTMLInputElement;

    addFiles(Array.from(field.files ?? []));
    // So that the same file can be picked again after it was taken off.
    field.value = '';
}

function csrfToken(): string | null {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : null;
}

function refusal(
    status: number,
    body: string,
    fileName: string,
): { error: string; retryable: boolean } {
    const limit = megabytes(props.uploads.max_bytes);

    if (status === 422) {
        let sentence: unknown;

        try {
            sentence = (JSON.parse(body) as { errors?: { file?: unknown[] } })
                .errors?.file?.[0];
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

    switch (status) {
        case 413:
            return {
                error: `${fileName} is larger than this instance accepts (${limit} MB). Choose a smaller file.`,
                retryable: false,
            };
        case 429:
            return {
                error: 'Too many uploads at once. Wait a minute, then try again.',
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

// One request at a time, in the order the files were given.
function sendNext(): void {
    if (request !== null) {
        return;
    }

    const item = pending.value.find(
        (candidate) => candidate.status === 'waiting',
    );

    if (!item) {
        return;
    }

    const form = new FormData();
    const sending = new XMLHttpRequest();
    const token = csrfToken();

    form.append(props.uploads.field, item.file, item.name);
    request = sending;
    item.status = 'sending';
    item.progress = 0;

    const finish = (failure: { error: string; retryable: boolean } | null) => {
        request = null;

        if (failure === null) {
            pending.value = pending.value.filter(
                (candidate) => candidate.key !== item.key,
            );
        } else {
            item.status = 'failed';
            item.error = failure.error;
            item.retryable = failure.retryable;
            announce(failure.error);
        }

        sendNext();
    };

    sending.upload.onprogress = (event) => {
        if (event.lengthComputable && event.total > 0) {
            item.progress = Math.round((event.loaded / event.total) * 100);
        }
    };

    sending.onload = () => {
        if (sending.status !== 201 && sending.status !== 200) {
            finish(refusal(sending.status, sending.responseText, item.name));

            return;
        }

        let image: MediaItem | null;

        try {
            image = JSON.parse(sending.responseText) as MediaItem;
        } catch {
            image = null;
        }

        if (image === null || typeof image.id !== 'number') {
            finish(refusal(0, '', item.name));

            return;
        }

        emit('uploaded', image);

        if (media.value.some((existing) => existing.id === image.id)) {
            announce(`${item.name} is already on the text for ${name.value}.`);
        } else {
            media.value = [...media.value, { id: image.id, alt_text: '' }];
            announce(
                `${item.name} was uploaded. It is on the post once the draft is saved.`,
            );
        }

        finish(null);
    };

    sending.onerror = sending.ontimeout = () =>
        finish({
            error: `${item.name} did not reach the server. Check the connection, then try again.`,
            retryable: true,
        });

    sending.onabort = () => (request = null);

    sending.open('POST', props.uploads.url);
    sending.setRequestHeader('Accept', 'application/json');
    sending.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    if (token !== null) {
        sending.setRequestHeader('X-XSRF-TOKEN', token);
    }

    sending.send(form);
}

function retry(item: Pending): void {
    if (free.value < 1) {
        item.error = `${item.name} was not added. ${platform.value} takes ${imageCount(rules.value.max_items)} at most on a post. Take one off to add another.`;
        item.retryable = false;
        announce(item.error);

        return;
    }

    item.status = 'waiting';
    item.error = '';
    sendNext();
}

function dismiss(item: Pending): void {
    pending.value = pending.value.filter(
        (candidate) => candidate.key !== item.key,
    );
    focus(elementId('add-images'));
}

onBeforeUnmount(() => {
    request?.abort();
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
                            @input="setAltText(item.id, $event)"
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
                                    Too long for {{ platform }}. The post cannot
                                    be approved until this description fits.
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
                            {{ platform }} takes no description for an image.
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
                        class="flex flex-wrap items-center gap-2"
                        :data-test="`image-share-${channel.id}-${item.id}`"
                    >
                        <Button
                            v-if="targetsFor(item.id).length === 1"
                            type="button"
                            size="sm"
                            variant="outline"
                            :aria-label="`Also add image ${index + 1} for ${name} to ${targetsFor(item.id)[0].name}`"
                            @click="share(item.id, targetsFor(item.id))"
                        >
                            Also add to {{ targetsFor(item.id)[0].name }}
                        </Button>
                        <template v-else>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                :aria-label="`Add image ${index + 1} for ${name} to ${everyOtherTakesImages ? 'all channels' : 'the other channels that take images'}`"
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
                                    :aria-label="`Also add image ${index + 1} for ${name} to one other channel`"
                                    @update:model-value="
                                        (value: string | undefined) =>
                                            sharePicked(item.id, value)
                                    "
                                >
                                    <option value="">Also add to...</option>
                                    <option
                                        v-for="target in targetsFor(item.id)"
                                        :key="target.channel_id"
                                        :value="String(target.channel_id)"
                                    >
                                        {{ target.name }}
                                    </option>
                                </NativeSelect>
                            </div>
                        </template>
                    </div>
                </div>
            </li>
        </ol>

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
            Too many images for {{ platform }}: it takes
            {{ rules.max_items }} at most. Take {{ tooMany }} off before the
            post can be approved.
        </p>
        <p
            v-else-if="problems[''] !== undefined"
            class="text-sm text-destructive"
            :data-test="`images-problem-${channel.id}`"
        >
            {{ problems[''] }}
        </p>

        <ul v-if="errors.length" class="grid gap-1">
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
                            <template v-else-if="item.progress < 100">
                                Uploading, {{ item.progress }}%
                            </template>
                            <template v-else>
                                Uploaded, being checked
                            </template>
                        </span>
                    </p>
                    <progress
                        class="h-1.5 w-full overflow-hidden rounded-full accent-primary"
                        max="100"
                        :value="item.progress"
                        :aria-label="`Upload of ${item.name}`"
                    ></progress>
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
                    That is as many as {{ platform }} takes on a post.
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
                The files are stored. They are on the post once you save the
                draft.
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
