<script setup lang="ts">
import { formatNumber, keptLabel, notGiven } from '@/lib/insights';

/**
 * One number, or a dash where the platform did not give it. The dash is
 * never a zero: it says why in words, for a screen reader and on hover.
 */
withDefaults(
    defineProps<{
        value: number | null;
        /** Why the number is missing, as a sentence. */
        reason?: string;
        /** Decimal places at most, for an average. */
        digits?: number;
        /**
         * The platform stopped giving this number and it is kept from an
         * earlier reading: said next to it, so that it is not read as fresh.
         */
        kept?: boolean;
    }>(),
    { reason: notGiven, digits: 0, kept: false },
);
</script>

<template>
    <span v-if="value !== null">
        <span class="font-mono tabular-nums">{{
            formatNumber(value, digits)
        }}</span>
        <span
            v-if="kept"
            class="block text-xs text-muted-foreground"
            title="The platform stopped giving this number. This is the last one it gave."
        >
            {{ keptLabel }}
        </span>
    </span>
    <span v-else class="font-mono text-muted-foreground" :title="reason">
        <span aria-hidden="true">-</span>
        <span class="sr-only">{{ reason }}</span>
    </span>
</template>
