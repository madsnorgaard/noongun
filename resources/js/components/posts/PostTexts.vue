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
    <div class="grid gap-3">
        <p v-if="post.variants.length === 0" class="text-muted-foreground">
            No channel and no text yet.
        </p>

        <div
            v-for="variant in post.variants"
            :key="variant.id"
            class="grid gap-1"
            :data-test="`variant-${variant.id}`"
        >
            <div class="text-xs text-muted-foreground">
                {{ variant.channel_name }} - {{ variant.platform_label }}
            </div>

            <p
                v-if="variant.body"
                class="max-w-prose break-words whitespace-pre-line"
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
                class="w-fit max-w-full truncate text-xs text-muted-foreground underline underline-offset-4"
            >
                {{ variant.link }}
            </a>

            <p v-if="variant.published" class="text-xs text-muted-foreground">
                Published
                <template v-if="variant.published_at">
                    {{ formatDateTime(variant.published_at, timezone) }}
                </template>
                <template v-if="webAddress(variant.permalink)">
                    -
                    <a
                        :href="webAddress(variant.permalink) ?? undefined"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-foreground underline underline-offset-4"
                        :data-test="`permalink-${variant.id}`"
                    >
                        View on {{ variant.platform_label }}
                    </a>
                </template>
            </p>
            <p
                v-else-if="variant.error && post.state === 'failed'"
                class="max-w-prose text-destructive"
            >
                Not published. {{ variant.error }}
            </p>
            <p
                v-else-if="variant.error && post.state === 'publishing'"
                class="max-w-prose text-xs text-muted-foreground"
            >
                The last attempt did not get through. It is tried again
                automatically. {{ variant.error }}
            </p>
            <p
                v-else-if="variant.error"
                class="max-w-prose text-xs text-muted-foreground"
            >
                An earlier attempt did not get through. {{ variant.error }}
            </p>
        </div>
    </div>
</template>
