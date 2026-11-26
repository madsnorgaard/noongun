<script setup lang="ts">
import { formatNumber, notGiven } from '@/lib/insights';

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
    }>(),
    { reason: notGiven, digits: 0 },
);
</script>

<template>
    <span v-if="value !== null" class="font-mono tabular-nums">
        {{ formatNumber(value, digits) }}
    </span>
    <span v-else class="font-mono text-muted-foreground" :title="reason">
        <span aria-hidden="true">-</span>
        <span class="sr-only">{{ reason }}</span>
    </span>
</template>
