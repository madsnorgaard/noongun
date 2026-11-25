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
 * card for it where the platform reads nothing from the linked page. The text
 * of another channel can be copied in and then changed here by itself.
 */
const props = defineProps<{
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
}>();

const emit = defineEmits<{ remove: [] }>();

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

const length = computed(() => textLength(body.value));
const over = computed(() =>
    props.channel.max_body_length === null
        ? 0
        : Math.max(0, length.value - props.channel.max_body_length),
);

const sources = computed(() =>
    props.others.filter((other) => other.body.trim() !== ''),
);

function copyFrom(channelId: number): void {
    const source = sources.value.find(
        (other) => other.channel_id === channelId,
    );

    if (!source || source.body === body.value) {
        return;
    }

    if (
        body.value.trim() !== '' &&
        !window.confirm(
            `Replace the text for ${props.channel.display_name} with the text for ${source.name}?`,
        )
    ) {
        return;
    }

    body.value = source.body;
}

// With several other channels the source is picked from a list, which
// goes back to its prompt once the text is copied.
const picked = ref('');

watch(picked, (channelId) => {
    if (channelId !== '') {
        copyFrom(Number(channelId));
        picked.value = '';
    }
});
</script>

<template>
    <section
        class="grid content-start gap-4 rounded-2xl bg-card p-5.5 shadow-xs"
    >
        <header
            class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3"
        >
            <div class="grid min-w-0 gap-1.5">
                <h2 class="text-heading break-words">
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
                :disabled="sources[0].body === body"
                :aria-label="`Copy the text for ${sources[0].name} into the text for ${channel.display_name}`"
                :data-test="`copy-into-${channel.id}`"
                @click="copyFrom(sources[0].channel_id)"
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

        <div
            v-if="channel.problem"
            class="grid gap-2 rounded-lg border-2 border-destructive px-4 py-3 text-base"
            :data-test="`channel-problem-${channel.id}`"
        >
            <p class="text-destructive">{{ channel.problem }}</p>
            <p>
                The post cannot be approved while it has a text for this
                channel. Connect the channel again, or take it off this post.
                The other texts stay as they are.
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
        </div>

        <div class="grid gap-2">
            <Label :for="`body-${channel.id}`">
                Text for {{ channel.platform_label }}
            </Label>
            <textarea
                :id="`body-${channel.id}`"
                v-model="body"
                rows="9"
                class="min-h-[220px] w-full resize-y rounded-lg border border-input bg-card p-4 text-lg leading-normal outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                :aria-invalid="over > 0"
                :aria-describedby="`count-${channel.id}`"
            ></textarea>
            <div
                class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1"
            >
                <div class="grid gap-1">
                    <p
                        v-if="over > 0"
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
            <Label :for="`link-${channel.id}`">Link (optional)</Label>
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
                <Label :for="`link-title-${channel.id}`">Link title</Label>
                <Input
                    :id="`link-title-${channel.id}`"
                    v-model="linkTitle"
                    type="text"
                    :maxlength="channel.link_title_length ?? undefined"
                />
                <InputError :message="errors.linkTitle" />
            </div>
            <div class="grid gap-1.5">
                <Label :for="`link-description-${channel.id}`">
                    Link description (optional)
                </Label>
                <textarea
                    :id="`link-description-${channel.id}`"
                    v-model="linkDescription"
                    rows="2"
                    class="w-full resize-y rounded-lg border border-input bg-card px-3 py-2 text-base outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                ></textarea>
                <InputError :message="errors.linkDescription" />
            </div>
        </div>
    </section>
</template>
