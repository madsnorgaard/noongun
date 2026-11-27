<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import VariantImages from '@/components/posts/VariantImages.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    endsWithLink as textEndsWithLink,
    linkGoesInText,
    textAsSent,
    withCampaign,
} from '@/lib/media';
import { textLength } from '@/lib/posts';
import type {
    ChannelOption,
    CopyKind,
    MediaInput,
    MediaItem,
    UploadRules,
} from '@/types/posts';

/**
 * What a draft says on one channel, as one column of the editor: the text
 * with its count against the limit of that channel, its images, and the
 * link, with the card for it where the platform reads nothing from the
 * linked page. The page that holds the columns copies a text or images in
 * and takes a column off, so that it can ask first; this component only
 * asks for it.
 */
const props = withDefaults(
    defineProps<{
        channel: ChannelOption;
        /** What the post has for its other channels. */
        others: {
            channel_id: number;
            name: string;
            body: string;
            media: MediaInput[];
            max_items: number;
        }[];
        errors: {
            body?: string;
            channel?: string;
            link?: string;
            linkTitle?: string;
            linkDescription?: string;
            media?: string[];
        };
        uploads: UploadRules;
        /** Every image the page knows, by id. */
        library: Record<number, MediaItem>;
        /** The images this text has as it is saved. */
        savedMediaIds?: number[];
        /**
         * The saved link and what the platform gets for it, with its
         * campaign parameters. Used for the count while the link is as saved.
         */
        savedLink?: { typed: string; sent: string } | null;
        /** The campaign parameters the server puts on the link of this text. */
        campaign?: string | null;
        /** What the server said about the saved images, see VariantImages. */
        imageProblems?: Record<string, string>;
        /** Images the last save said no longer exist. */
        goneMediaIds?: number[];
        /** A description for an image that came with the draft. */
        suggestedAltText?: string | null;
        /** False for a text the server keeps on the post whatever is sent. */
        removable?: boolean;
    }>(),
    {
        removable: true,
        savedMediaIds: () => [],
        savedLink: null,
        campaign: null,
        imageProblems: () => ({}),
        goneMediaIds: () => [],
        suggestedAltText: null,
    },
);

const emit = defineEmits<{
    remove: [];
    copy: [channelId: number, what: CopyKind];
    uploaded: [image: MediaItem];
    share: [mediaId: number, channelIds: number[]];
    uploading: [count: number];
    describe: [mediaId: number, text: string];
    describeAll: [mediaId: number];
    orderAll: [];
}>();

const body = defineModel<string>('body', { required: true });
const link = defineModel<string>('link', { required: true });
const linkTitle = defineModel<string>('linkTitle', { default: '' });
const linkDescription = defineModel<string>('linkDescription', {
    default: '',
});
const media = defineModel<MediaInput[]>('media', { required: true });

const rules = computed(() => props.channel.media);

// Next to images most platforms show no card for a link. What happens to
// the link then is a rule of the platform.
const linkInText = computed(() =>
    linkGoesInText(link.value, media.value.length, rules.value.link_rule),
);
const linkRefused = computed(
    () =>
        rules.value.link_rule === 'refused' &&
        rules.value.max_items > 0 &&
        media.value.length > 0 &&
        link.value.trim() !== '',
);

// A platform that reads nothing from the linked page shows the link as the
// card typed here. There is no card when the link goes out in the text.
const needsLinkCard = computed(
    () =>
        props.channel.link_title_length !== null &&
        link.value.trim() !== '' &&
        !linkInText.value,
);

// The text as the platform gets it: with the link as its last line where
// the images take the place of the card.
// The link as the platform gets it: what the server said for the saved
// link, and for one that is being typed the same campaign parameters put
// on it here.
const sentLink = computed(() =>
    props.savedLink !== null && props.savedLink.typed === link.value
        ? props.savedLink.sent
        : withCampaign(link.value, props.campaign),
);

const sent = computed(() =>
    textAsSent(
        body.value,
        sentLink.value,
        media.value.length,
        rules.value.link_rule,
        link.value,
    ),
);

// The text already ends with the link, as it is typed or as it is sent.
// It is then not added a second time.
const endsWithLink = computed(
    () =>
        linkInText.value &&
        (textEndsWithLink(body.value.trimEnd(), link.value.trim()) ||
            textEndsWithLink(body.value.trimEnd(), sentLink.value.trim())),
);
// The typed link at the end of the text goes out with its campaign
// parameters, so what is sent is not letter for letter what is typed.
const linkGetsCampaign = computed(
    () => endsWithLink.value && sent.value !== body.value,
);

// A picture without a text is not built yet.
const needsText = computed(
    () => media.value.length > 0 && body.value.trim() === '',
);

function overBy(text: string, limit: number | null): number {
    return limit === null ? 0 : Math.max(0, textLength(text) - limit);
}

const length = computed(() => textLength(sent.value));
const over = computed(() => overBy(sent.value, props.channel.max_body_length));

const titleLength = computed(() => textLength(linkTitle.value.trim()));
const titleOver = computed(() =>
    overBy(linkTitle.value.trim(), props.channel.link_title_length),
);
const titleMissing = computed(
    () => needsLinkCard.value && linkTitle.value.trim() === '',
);
const descriptionLength = computed(() => textLength(linkDescription.value));
const descriptionOver = computed(() =>
    overBy(linkDescription.value, props.channel.link_description_length),
);

// Read out once when a limit is crossed, in either direction, and not on
// every key in between.
const announcement = computed(() => {
    const name = props.channel.display_name;
    const parts: string[] = [];

    if (over.value > 0) {
        parts.push(
            `The text for ${name} is over the limit of ${props.channel.max_body_length} characters.`,
        );
    }

    if (needsLinkCard.value && titleOver.value > 0) {
        parts.push(
            `The link title for ${name} is over the limit of ${props.channel.link_title_length} characters.`,
        );
    }

    if (needsLinkCard.value && descriptionOver.value > 0) {
        parts.push(
            `The link description for ${name} is over the limit of ${props.channel.link_description_length} characters.`,
        );
    }

    return parts.join(' ');
});

function sameImages(a: MediaInput[], b: MediaInput[]): boolean {
    return (
        a.length === b.length &&
        a.every(
            (item, index) =>
                item.id === b[index].id && item.alt_text === b[index].alt_text,
        )
    );
}

type CopyChoice = {
    value: string;
    from: number;
    what: CopyKind;
    /** What is copied and from where: "text and images from Alpha". */
    label: string;
    /** Nothing would change. */
    same: boolean;
};

// What can be copied in from the other channels: a text, the images, or
// both. Each choice says which.
const copyChoices = computed(() =>
    props.others.flatMap((other) => {
        const text = other.body.trim() !== '';
        const pictures = other.media.length > 0 && rules.value.max_items > 0;
        const sameText = other.body === body.value;
        const samePictures = sameImages(other.media, media.value);
        const choices: CopyChoice[] = [];
        const add = (what: CopyKind, label: string, same: boolean) =>
            choices.push({
                value: `${other.channel_id}:${what}`,
                from: other.channel_id,
                what,
                label: `${label} from ${other.name}`,
                same,
            });

        if (text) {
            add('text', 'text', sameText);
        }

        if (pictures) {
            add('images', 'images', samePictures);
        }

        if (text && pictures) {
            add('both', 'text and images', sameText && samePictures);
        }

        return choices;
    }),
);

function capitalised(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

// With more than one thing to copy the choice is made from a list, which
// goes back to its prompt once it was made.
const picked = ref('');

watch(picked, (value) => {
    const choice = copyChoices.value.find(
        (candidate) => candidate.value === value,
    );

    if (choice) {
        emit('copy', choice.from, choice.what);
    }

    picked.value = '';
});

// Files dropped on the column or pasted into the text go to its images.
const images = ref<InstanceType<typeof VariantImages> | null>(null);
const dragDepth = ref(0);

function carriesFiles(event: DragEvent): boolean {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files');
}

function onDragEnter(event: DragEvent): void {
    if (carriesFiles(event)) {
        event.preventDefault();
        dragDepth.value++;
    }
}

function onDragOver(event: DragEvent): void {
    // Without this the browser would open a dropped file and leave the page.
    if (carriesFiles(event)) {
        event.preventDefault();

        if (event.dataTransfer) {
            event.dataTransfer.dropEffect =
                rules.value.max_items > 0 ? 'copy' : 'none';
        }
    }
}

function onDragLeave(event: DragEvent): void {
    if (carriesFiles(event)) {
        dragDepth.value = Math.max(0, dragDepth.value - 1);
    }
}

function onDrop(event: DragEvent): void {
    if (!carriesFiles(event)) {
        return;
    }

    event.preventDefault();
    dragDepth.value = 0;
    images.value?.addFiles(Array.from(event.dataTransfer?.files ?? []));
}

function onPaste(event: ClipboardEvent): void {
    const files = Array.from(event.clipboardData?.files ?? []).filter((file) =>
        file.type.startsWith('image/'),
    );

    // Text that comes with a picture of itself, as from a word processor,
    // is pasted as text.
    if (
        files.length === 0 ||
        rules.value.max_items === 0 ||
        (event.clipboardData?.getData('text/plain') ?? '') !== ''
    ) {
        return;
    }

    event.preventDefault();
    images.value?.addFiles(files);
}

function describedBy(...ids: (string | false)[]): string | undefined {
    const present = ids.filter((id): id is string => id !== false);

    return present.length ? present.join(' ') : undefined;
}
</script>

<template>
    <section
        class="grid content-start gap-4 rounded-2xl bg-card p-5.5 shadow-xs"
        :class="
            dragDepth > 0 && rules.max_items > 0 ? 'ring-[3px] ring-ring' : ''
        "
        :aria-labelledby="`column-${channel.id}`"
        @dragenter="onDragEnter"
        @dragover="onDragOver"
        @dragleave="onDragLeave"
        @drop="onDrop"
    >
        <header
            class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3"
        >
            <div class="grid min-w-0 gap-1.5">
                <h2
                    :id="`column-${channel.id}`"
                    class="text-heading break-words"
                >
                    {{ channel.display_name }}
                </h2>
                <span class="w-fit rounded-sm bg-secondary px-2.5 py-1 text-sm">
                    {{ channel.platform_label }}
                </span>
            </div>

            <Button
                v-if="copyChoices.length === 1"
                type="button"
                size="sm"
                variant="outline"
                :disabled="copyChoices[0].same"
                :aria-label="`Copy the ${copyChoices[0].label} into the column for ${channel.display_name}`"
                :data-test="`copy-into-${channel.id}`"
                @click="emit('copy', copyChoices[0].from, copyChoices[0].what)"
            >
                Copy {{ copyChoices[0].label }}
            </Button>
            <div v-else-if="copyChoices.length > 1" class="w-full sm:w-72">
                <NativeSelect
                    v-model="picked"
                    :aria-label="`Copy the text or the images of another channel into the column for ${channel.display_name}`"
                    :data-test="`copy-into-${channel.id}`"
                >
                    <option value="">Copy from another channel...</option>
                    <option
                        v-for="choice in copyChoices"
                        :key="choice.value"
                        :value="choice.value"
                        :disabled="choice.same"
                    >
                        {{ capitalised(choice.label) }}
                    </option>
                </NativeSelect>
            </div>
        </header>

        <p class="sr-only" role="status" aria-live="polite">
            {{ announcement }}
        </p>

        <div
            v-if="channel.problem"
            class="grid gap-2 rounded-lg border-2 border-destructive px-4 py-3 text-base"
            :data-test="`channel-problem-${channel.id}`"
        >
            <p class="text-destructive">{{ channel.problem }}</p>
            <template v-if="removable">
                <p>
                    The post cannot be approved while it has a text for this
                    channel. Connect the channel again, or take it off this
                    post. The other texts stay as they are.
                </p>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    class="w-fit"
                    :data-test="`remove-channel-${channel.id}`"
                    @click="emit('remove')"
                >
                    Take it off this post
                </Button>
            </template>
            <p v-else>
                The post cannot be approved until this channel is connected
                again. This text stays on the post, because the channel was
                called for it and may hold it.
            </p>
        </div>

        <div class="grid gap-2">
            <Label :for="`body-${channel.id}`">
                Text for {{ channel.display_name }}
            </Label>
            <textarea
                :id="`body-${channel.id}`"
                v-model="body"
                rows="9"
                class="min-h-[220px] w-full resize-y rounded-lg border border-input bg-card p-4 text-lg leading-normal outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                :aria-invalid="over > 0 ? 'true' : undefined"
                :aria-describedby="
                    describedBy(
                        channel.max_body_length !== null &&
                            `count-${channel.id}`,
                        over > 0 && `too-long-${channel.id}`,
                    )
                "
                @paste="onPaste"
            ></textarea>
            <div
                class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1"
            >
                <div class="grid gap-1">
                    <p
                        v-if="over > 0"
                        :id="`too-long-${channel.id}`"
                        class="text-sm text-destructive"
                        :data-test="`too-long-${channel.id}`"
                    >
                        {{
                            linkInText
                                ? `Too long for ${channel.platform_label} with the link as its last line.`
                                : `Too long for ${channel.platform_label}.`
                        }}
                        The post cannot be approved until this text fits.
                    </p>
                    <p
                        v-if="needsText"
                        class="text-sm text-destructive"
                        :data-test="`needs-text-${channel.id}`"
                    >
                        {{ channel.platform_label }} posts need a text for now,
                        even with images. The post cannot be approved until this
                        one has a text.
                    </p>
                    <InputError :message="errors.body" />
                    <InputError :message="errors.channel" />
                </div>
                <span
                    v-if="channel.max_body_length !== null"
                    :id="`count-${channel.id}`"
                    class="ml-auto font-mono text-sm"
                    :class="
                        over > 0 ? 'text-destructive' : 'text-muted-foreground'
                    "
                    :data-test="`remaining-${channel.id}`"
                >
                    <template v-if="over > 0">
                        {{ over }} over the limit of
                        {{ channel.max_body_length }}
                    </template>
                    <template v-else>
                        <span class="sr-only">
                            {{ channel.max_body_length - length }} characters
                            left:
                        </span>
                        <template v-if="sent !== body"
                            >with the link,
                        </template>
                        {{ length }} / {{ channel.max_body_length }}
                    </template>
                </span>
            </div>
        </div>

        <VariantImages
            ref="images"
            v-model:media="media"
            :channel="channel"
            :uploads="uploads"
            :library="library"
            :saved-ids="savedMediaIds"
            :others="others"
            :problems="imageProblems"
            :errors="errors.media ?? []"
            :gone-ids="goneMediaIds"
            :suggested-alt-text="suggestedAltText"
            @describe="
                (mediaId: number, text: string) =>
                    emit('describe', mediaId, text)
            "
            @describe-all="(mediaId: number) => emit('describeAll', mediaId)"
            @order-all="emit('orderAll')"
            @uploaded="(image: MediaItem) => emit('uploaded', image)"
            @share="
                (mediaId: number, channelIds: number[]) =>
                    emit('share', mediaId, channelIds)
            "
            @uploading="(count: number) => emit('uploading', count)"
        />

        <div class="grid gap-1.5">
            <Label :for="`link-${channel.id}`">
                Link for {{ channel.display_name }} (optional)
            </Label>
            <Input
                :id="`link-${channel.id}`"
                v-model="link"
                type="url"
                inputmode="url"
            />
            <p
                v-if="
                    channel.link_previews === false &&
                    channel.link_title_length === null
                "
                class="text-sm text-muted-foreground"
            >
                {{ channel.platform_label }} shows no preview card for a link.
            </p>
            <!-- Advice, not a problem: it carries the Check tag and says
                 what will happen. -->
            <p
                v-if="linkInText"
                class="rounded-md bg-warning-surface px-3 py-2 text-sm"
                role="status"
                :data-test="`link-in-text-${channel.id}`"
            >
                <span
                    class="mr-1.5 rounded-sm bg-warning-fill px-2 py-0.5 text-xs font-bold text-white"
                >
                    Check
                </span>
                <template v-if="linkGetsCampaign">
                    With images, {{ channel.platform_label }} shows no link
                    card. The text already ends with the link, so it is not
                    added again. It goes out with its campaign parameters.
                </template>
                <template v-else-if="endsWithLink">
                    With images, {{ channel.platform_label }} shows no link
                    card. The text already ends with the link, so it is not
                    added again.
                </template>
                <template v-else>
                    With images, {{ channel.platform_label }} shows no link
                    card. The link goes out as the last line of the text.
                </template>
            </p>
            <p
                v-else-if="linkRefused"
                class="text-sm text-destructive"
                role="status"
                :data-test="`link-refused-${channel.id}`"
            >
                {{ channel.platform_label }} takes a post with images or a post
                with a link, not both. Take the link or the images off before
                the post can be approved.
            </p>
            <InputError :message="errors.link" />
        </div>

        <div
            v-if="needsLinkCard"
            class="grid gap-4"
            :data-test="`link-card-${channel.id}`"
        >
            <p class="text-sm text-muted-foreground">
                {{ channel.platform_label }} does not read the linked page. It
                shows the link with the title and description you write here,
                without an image.
            </p>
            <div class="grid gap-1.5">
                <Label :for="`link-title-${channel.id}`">
                    Link title for {{ channel.display_name }} (required)
                </Label>
                <Input
                    :id="`link-title-${channel.id}`"
                    v-model="linkTitle"
                    type="text"
                    aria-required="true"
                    :aria-invalid="
                        titleOver > 0 || titleMissing ? 'true' : undefined
                    "
                    :aria-describedby="
                        describedBy(
                            `link-title-count-${channel.id}`,
                            (titleMissing || titleOver > 0) &&
                                `link-title-problem-${channel.id}`,
                        )
                    "
                />
                <div
                    class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1"
                >
                    <div class="grid gap-1">
                        <p
                            v-if="titleMissing"
                            :id="`link-title-problem-${channel.id}`"
                            class="text-sm text-destructive"
                            :data-test="`link-title-missing-${channel.id}`"
                        >
                            The link needs a title. The post cannot be approved
                            without one, or without the link.
                        </p>
                        <p
                            v-else-if="titleOver > 0"
                            :id="`link-title-problem-${channel.id}`"
                            class="text-sm text-destructive"
                            :data-test="`link-title-too-long-${channel.id}`"
                        >
                            Too long for {{ channel.platform_label }}. The post
                            cannot be approved until this title fits.
                        </p>
                        <InputError :message="errors.linkTitle" />
                    </div>
                    <span
                        :id="`link-title-count-${channel.id}`"
                        class="ml-auto font-mono text-sm"
                        :class="
                            titleOver > 0
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        "
                    >
                        <template v-if="titleOver > 0">
                            {{ titleOver }} over the limit of
                            {{ channel.link_title_length }}
                        </template>
                        <template v-else>
                            {{ titleLength }} / {{ channel.link_title_length }}
                        </template>
                    </span>
                </div>
            </div>
            <div class="grid gap-1.5">
                <Label :for="`link-description-${channel.id}`">
                    Link description for {{ channel.display_name }} (optional)
                </Label>
                <textarea
                    :id="`link-description-${channel.id}`"
                    v-model="linkDescription"
                    rows="2"
                    class="w-full resize-y rounded-lg border border-input bg-card px-3 py-2 text-base outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    :aria-invalid="descriptionOver > 0 ? 'true' : undefined"
                    :aria-describedby="
                        describedBy(
                            channel.link_description_length !== null &&
                                `link-description-count-${channel.id}`,
                        )
                    "
                ></textarea>
                <div
                    class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1"
                >
                    <InputError :message="errors.linkDescription" />
                    <span
                        v-if="channel.link_description_length !== null"
                        :id="`link-description-count-${channel.id}`"
                        class="ml-auto font-mono text-sm"
                        :class="
                            descriptionOver > 0
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        "
                    >
                        <template v-if="descriptionOver > 0">
                            {{ descriptionOver }} over the limit of
                            {{ channel.link_description_length }}
                        </template>
                        <template v-else>
                            {{ descriptionLength }} /
                            {{ channel.link_description_length }}
                        </template>
                    </span>
                </div>
            </div>
        </div>
    </section>
</template>
