<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { altFor } from '@/lib/media';
import type { MediaItem } from '@/types/posts';

/**
 * One image at its full size, with its description as text. The full file
 * is only asked for when this opens.
 */
defineProps<{
    image: MediaItem | null;
    altText: string | null;
    /** Which image this is, for example "Image 2 of 3 for Alpha". */
    title: string;
}>();

const open = defineModel<boolean>('open', { required: true });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-3xl" data-test="image-viewer">
            <DialogHeader class="space-y-2 pr-10">
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription class="text-base break-words">
                    <template v-if="(altText ?? '').trim() !== ''">
                        Description: {{ altText }}
                    </template>
                    <template v-else>This image has no description.</template>
                </DialogDescription>
            </DialogHeader>

            <template v-if="image">
                <img
                    :src="image.url"
                    :alt="altFor(altText)"
                    :width="image.width"
                    :height="image.height"
                    decoding="async"
                    class="mx-auto h-auto max-h-[65vh] w-auto max-w-full rounded-sm bg-secondary object-contain"
                />
                <p class="text-sm text-muted-foreground">
                    <span class="font-mono">
                        {{ image.width }} x {{ image.height }}
                    </span>
                    pixels -
                    <a
                        :href="image.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex min-h-11 items-center font-semibold text-foreground underline underline-offset-4"
                    >
                        Open in a new tab
                    </a>
                </p>
            </template>
        </DialogContent>
    </Dialog>
</template>
