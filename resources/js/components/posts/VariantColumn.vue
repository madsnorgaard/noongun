<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { textLength } from '@/lib/posts';
import type { ChannelOption } from '@/types/posts';

/**
 * What a draft says on one channel, as one column of the editor: the text
 * with its count against the limit of that channel, and the link, with the
 * card for it where the platform reads nothing from the linked page. The
 * page that holds the columns copies a text in and takes a column off, so
 * that it can ask first; this component only asks for it.
 */
const props = withDefaults(
    defineProps<{
        channel: ChannelOption;
        /** The texts the post has for its other channels. */
        others: { channel_id: number; name: string; body: string }[];
        errors: {
            body?: string;
            channel?: string;
            link?: string;
            linkTitle?: string;
            linkDescription?: string;
        };
        /** False for a text the server keeps on the post whatever is sent. */
        removable?: boolean;
    }>(),
    { removable: true },
);

const emit = defineEmits<{ remove: []; copy: [channelId: number] }>();

const body = defineModel<string>('body', { required: true });
const link = defineModel<string>('link', { required: true });
const linkTitle = defineModel<string>('linkTitle', { default: '' });
const linkDescription = defineModel<string>('linkDescription', {
    default: '',
});

// A platform that reads nothing from the linked page shows the link as the
// card typed here.
const needsLinkCard = computed(
    () => props.channel.link_title_length !== null && link.value.trim() !== '',
);

function overBy(text: string, limit: number | null): number {
    return limit === null ? 0 : Math.max(0, textLength(text) - limit);
}

const length = computed(() => textLength(body.value));
const over = computed(() => overBy(body.value, props.channel.max_body_length));

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

const sources = computed(() =>
    props.others.filter((other) => other.body.trim() !== ''),
);

// With several other channels the source is picked from a list, which
// goes back to its prompt once it was picked.
const picked = ref('');

watch(picked, (channelId) => {
    if (channelId !== '') {
        emit('copy', Number(channelId));
        picked.value = '';
    }
});

function describedBy(...ids: (string | false)[]): string | undefined {
    const present = ids.filter((id): id is string => id !== false);

    return present.length ? present.join(' ') : undefined;
}
</script>

<template>
    <section
        class="grid content-start gap-4 rounded-2xl bg-card p-5.5 shadow-xs"
        :aria-labelledby="`column-${channel.id}`"
    >
        <!-- The copy control always has its own row under the name, so that
             it is in the same place in every column, whatever the length
             of the names. -->
        <header class="grid gap-3">
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
                v-if="sources.length === 1"
                type="button"
                size="sm"
                variant="outline"
                class="w-fit max-w-full"
                :disabled="sources[0].body === body"
                :aria-label="`Copy the text for ${sources[0].name} into the text for ${channel.display_name}`"
                :data-test="`copy-into-${channel.id}`"
                @click="emit('copy', sources[0].channel_id)"
            >
                Copy text from {{ sources[0].name }}
            </Button>
            <div v-else-if="sources.length > 1" class="w-full sm:w-64">
                <NativeSelect
                    v-model="picked"
                    :aria-label="`Copy the text of another channel into the text for ${channel.display_name}`"
                    :data-test="`copy-into-${channel.id}`"
                >
                    <option value="">Copy text from...</option>
                    <option
                        v-for="source in sources"
                        :key="source.channel_id"
                        :value="String(source.channel_id)"
                    >
                        {{ source.name }}
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
                        Too long for {{ channel.platform_label }}. The post
                        cannot be approved until this text fits.
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
                        {{ length }} / {{ channel.max_body_length }}
                    </template>
                </span>
            </div>
        </div>

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
