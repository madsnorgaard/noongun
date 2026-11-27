<script setup lang="ts">
import { computed } from 'vue';
import PostImages from '@/components/posts/PostImages.vue';
import { formatDateTime, useWorkspaceTimezone, webAddress } from '@/lib/posts';
import type { AttachedMedia, Post } from '@/types/posts';

/**
 * The texts of a post as they will read on each channel, with what became
 * of each one. Read-only.
 */
const props = defineProps<{
    post: Post;
    /** Shorten each text to two lines, for rows that are not being reviewed. */
    clamp?: boolean;
    /** One column per channel where there is room, to compare the texts. */
    sideBySide?: boolean;
    /** Show each image with its description as text, where the post is read. */
    describeImages?: boolean;
}>();

const timezone = useWorkspaceTimezone();

// In a list the same images on several channels are shown once, with the
// channels they go to, and not once for every channel.
const imageSets = computed(() => {
    const sets = new Map<
        string,
        { images: AttachedMedia[]; channels: string[]; missing: number }
    >();

    props.post.variants.forEach((variant) => {
        if (variant.media.length === 0) {
            return;
        }

        const key = variant.media.map((image) => image.id).join(',');
        const set = sets.get(key) ?? {
            images: variant.media,
            channels: [],
            missing: 0,
        };

        set.channels.push(variant.channel_name);
        set.missing += variant.media.filter(
            (image) => (image.alt_text ?? '').trim() === '',
        ).length;
        sets.set(key, set);
    });

    return Array.from(sets, ([key, set]) => ({ key, ...set }));
});
</script>

<template>
    <div
        :class="
            sideBySide
                ? 'grid grid-cols-[repeat(auto-fit,minmax(min(100%,20rem),1fr))] items-start gap-x-8 gap-y-6'
                : 'grid divide-y divide-border'
        "
    >
        <p v-if="post.variants.length === 0" class="text-muted-foreground">
            No channel and no text yet.
        </p>

        <div
            v-for="variant in post.variants"
            :key="variant.id"
            :class="
                sideBySide
                    ? 'grid min-w-0 content-start gap-3'
                    : 'grid gap-x-6 gap-y-2 py-4 first:pt-0 last:pb-0 sm:grid-cols-[11rem_minmax(0,1fr)]'
            "
            :data-test="`variant-${variant.id}`"
        >
            <div
                class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1"
                :class="sideBySide ? '' : 'sm:flex-col sm:items-start'"
            >
                <span
                    class="max-w-full truncate rounded-sm bg-secondary px-2.5 py-1 text-sm"
                >
                    {{ variant.channel_name }}
                </span>
                <span class="text-xs text-muted-foreground">
                    {{ variant.platform_label }}
                </span>
            </div>

            <div class="grid min-w-0 gap-2">
                <p
                    v-if="variant.body"
                    class="max-w-[72ch] break-words whitespace-pre-line"
                    :class="clamp ? 'line-clamp-2' : ''"
                >
                    {{ variant.body }}
                </p>
                <p v-else class="text-muted-foreground">No text yet.</p>

                <PostImages
                    v-if="describeImages"
                    :images="variant.media"
                    :channel-name="variant.channel_name"
                />

                <a
                    v-if="webAddress(variant.link)"
                    :href="webAddress(variant.link) ?? undefined"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="w-fit max-w-full truncate text-sm text-muted-foreground underline underline-offset-4"
                >
                    {{ variant.link }}
                </a>
                <p
                    v-if="webAddress(variant.link) && variant.link_title"
                    class="max-w-[72ch] text-sm break-words"
                    :data-test="`link-card-${variant.id}`"
                >
                    <span class="font-semibold">{{ variant.link_title }}</span>
                    <span
                        v-if="variant.link_description && !clamp"
                        class="block text-muted-foreground"
                    >
                        {{ variant.link_description }}
                    </span>
                </p>

                <p
                    v-if="variant.published"
                    class="text-sm text-muted-foreground"
                >
                    Published
                    <span v-if="variant.published_at" class="font-mono">
                        {{ formatDateTime(variant.published_at, timezone) }}
                    </span>
                    <template v-if="webAddress(variant.permalink)">
                        -
                        <a
                            :href="webAddress(variant.permalink) ?? undefined"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="font-semibold text-foreground underline underline-offset-4"
                            :data-test="`permalink-${variant.id}`"
                        >
                            View on {{ variant.platform_label }}
                        </a>
                    </template>
                </p>
                <p
                    v-else-if="variant.error && post.state === 'failed'"
                    class="max-w-[72ch] text-base"
                >
                    <span class="font-semibold text-destructive">
                        Not published.
                    </span>
                    {{ variant.error }}
                </p>
                <p
                    v-else-if="variant.error && post.state === 'publishing'"
                    class="max-w-[72ch] text-sm text-muted-foreground"
                >
                    The last attempt did not get through. It is tried again
                    automatically. {{ variant.error }}
                </p>
                <p
                    v-else-if="variant.error"
                    class="max-w-[72ch] text-sm text-muted-foreground"
                >
                    An earlier attempt did not get through. {{ variant.error }}
                </p>
            </div>
        </div>

        <div
            v-if="!describeImages && imageSets.length"
            class="grid gap-3"
            :class="sideBySide ? 'col-span-full' : 'py-4 last:pb-0'"
            data-test="post-image-sets"
        >
            <PostImages
                v-for="set in imageSets"
                :key="set.key"
                :images="set.images"
                :channel-name="set.channels[0]"
                :channel-names="set.channels"
                :missing-descriptions="set.missing"
                compact
            />
        </div>
    </div>
</template>
