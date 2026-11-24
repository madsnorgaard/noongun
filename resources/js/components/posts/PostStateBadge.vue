<script setup lang="ts">
import { stateLabels } from '@/lib/posts';
import { stateChipDots, stateChips } from '@/lib/postStates';
import type { PostState } from '@/types/posts';

/**
 * The state of a post as a chip: a dot and one plain word.
 */
withDefaults(
    defineProps<{
        state: PostState;
        /** The larger chip in the header of a page. */
        size?: 'default' | 'large';
    }>(),
    { size: 'default' },
);
</script>

<template>
    <span
        class="inline-flex w-fit shrink-0 items-center gap-2 rounded-full font-semibold whitespace-nowrap"
        :class="[
            stateChips[state],
            size === 'large'
                ? 'px-3.5 py-1.5 text-base'
                : 'px-[11px] py-[3px] text-[0.875rem] leading-snug',
        ]"
        :data-test="`state-${state}`"
    >
        <span
            class="size-[9px] shrink-0"
            :class="stateChipDots[state]"
            aria-hidden="true"
        ></span>
        {{ stateLabels[state] }}
    </span>
</template>
