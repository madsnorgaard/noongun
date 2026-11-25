<script setup lang="ts">
import { formatDateTime, useWorkspaceTimezone, webAddress } from '@/lib/posts';
import type { Post } from '@/types/posts';

/**
 * The texts of a post as they will read on each channel, with what became
 * of each one. Read-only.
 */
defineProps<{
    post: Post;
    /** Shorten each text to two lines, for rows that are not being reviewed. */
    clamp?: boolean;
}>();

const timezone = useWorkspaceTimezone();
</script>

<template>
    <div class="grid divide-y divide-border">
        <p v-if="post.variants.length === 0" class="text-muted-foreground">
            No channel and no text yet.
        </p>

        <div
            v-for="variant in post.variants"
            :key="variant.id"
            class="grid gap-x-6 gap-y-2 py-4 first:pt-0 last:pb-0 sm:grid-cols-[11rem_minmax(0,1fr)]"
            :data-test="`variant-${variant.id}`"
        >
            <div
                class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 sm:flex-col sm:items-start"
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
    </div>
</template>
