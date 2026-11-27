<script setup lang="ts">
import { computed, ref } from 'vue';
import ImageViewer from '@/components/posts/ImageViewer.vue';
import { altFor, imageCount } from '@/lib/media';
import type { AttachedMedia } from '@/types/posts';

/**
 * The images of one text, read-only. Lists show the small copies; the full
 * file is loaded when an image is opened.
 */
const props = defineProps<{
    images: AttachedMedia[];
    channelName: string;
    /** Small squares and a count, for a row in a list. */
    compact?: boolean;
}>();

const viewing = ref<number | null>(null);
const viewerOpen = ref(false);

const current = computed(() =>
    viewing.value !== null ? (props.images[viewing.value] ?? null) : null,
);

const undescribed = computed(
    () =>
        props.images.filter((image) => (image.alt_text ?? '').trim() === '')
            .length,
);

function view(index: number): void {
    viewing.value = index;
    viewerOpen.value = true;
}

function label(index: number): string {
    return `Image ${index + 1} of ${props.images.length} for ${props.channelName}`;
}
</script>

<template>
    <div v-if="images.length" class="grid gap-2" data-test="post-images">
        <template v-if="compact">
            <ul class="flex flex-wrap items-center gap-1.5">
                <li v-for="(image, index) in images" :key="image.id">
                    <button
                        type="button"
                        class="block rounded-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring"
                        :aria-label="`Open ${label(index).toLowerCase()}: ${altFor(image.alt_text)}`"
                        @click="view(index)"
                    >
                        <img
                            :src="image.thumb_url"
                            :alt="altFor(image.alt_text)"
                            width="44"
                            height="44"
                            loading="lazy"
                            decoding="async"
                            class="size-11 rounded-sm bg-secondary object-cover"
                        />
                    </button>
                </li>
            </ul>
            <p class="text-sm text-muted-foreground">
                <span class="font-mono">{{ imageCount(images.length) }}</span>
                <template v-if="undescribed > 0">
                    - {{ undescribed }} without a description
                </template>
            </p>
        </template>

        <template v-else>
            <p class="text-sm text-muted-foreground">
                <span class="font-mono">{{ imageCount(images.length) }}</span>
                for {{ channelName }}, in this order
            </p>
            <ol class="grid gap-3">
                <li
                    v-for="(image, index) in images"
                    :key="image.id"
                    class="grid grid-cols-[6rem_minmax(0,1fr)] items-start gap-3"
                >
                    <button
                        type="button"
                        class="block rounded-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring"
                        :aria-label="`Open ${label(index).toLowerCase()} at full size`"
                        @click="view(index)"
                    >
                        <img
                            :src="image.thumb_url"
                            :alt="altFor(image.alt_text)"
                            width="96"
                            height="96"
                            loading="lazy"
                            decoding="async"
                            class="size-24 rounded-sm bg-secondary object-cover"
                        />
                    </button>
                    <div class="grid min-w-0 gap-0.5">
                        <p class="text-sm text-muted-foreground">
                            Description of image {{ index + 1 }}
                        </p>
                        <p
                            v-if="(image.alt_text ?? '').trim() !== ''"
                            class="max-w-[72ch] text-base break-words whitespace-pre-line"
                        >
                            {{ image.alt_text }}
                        </p>
                        <p v-else class="text-base text-muted-foreground">
                            No description.
                        </p>
                    </div>
                </li>
            </ol>
        </template>

        <ImageViewer
            v-model:open="viewerOpen"
            :image="current"
            :alt-text="current?.alt_text ?? null"
            :title="viewing !== null ? label(viewing) : ''"
        />
    </div>
</template>
