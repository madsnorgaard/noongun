<script setup lang="ts">
import { formatNumber } from '@/lib/insights';
import type { PeriodTotals } from '@/types/insights';

/**
 * How many posts a period has and how many of them are measured, in one
 * sentence. A post on several channels is measured on each of them, so
 * when that happens the sentence counts the times a post went out.
 */
defineProps<{ totals: PeriodTotals }>();
</script>

<template>
    <p>
        <span class="font-mono">{{ formatNumber(totals.posts) }}</span>
        {{ totals.posts === 1 ? 'post' : 'posts' }} published<template
            v-if="totals.channel_posts > totals.posts"
            >. They went out
            <span class="font-mono">{{
                formatNumber(totals.channel_posts)
            }}</span>
            times across channels, and
            <span class="font-mono">{{ formatNumber(totals.measured) }}</span>
            of those are measured.</template
        ><template v-else
            >,
            <span class="font-mono">{{ formatNumber(totals.measured) }}</span>
            measured.</template
        >
    </p>
</template>
