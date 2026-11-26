<script setup lang="ts">
import { computed } from 'vue';
import MetricValue from '@/components/insights/MetricValue.vue';
import { metricLabels, metricNames } from '@/lib/insights';
import type { MetricNumbers } from '@/types/insights';

/**
 * The numbers of a post or a period on one line, each with its name.
 * Reactions, comments and shares are always there, with a dash where one
 * was not given. Clicks and reach are only listed when they were given,
 * because most channels do not have them.
 */
const props = defineProps<{
    numbers: MetricNumbers;
    /** Left out when undefined, shown as a dash when null. */
    score?: number | null;
}>();

const listed = computed(() =>
    metricNames.filter(
        (metric) =>
            !['clicks', 'reach'].includes(metric) ||
            props.numbers[metric] !== null,
    ),
);
</script>

<template>
    <dl class="flex flex-wrap gap-x-5 gap-y-1 text-sm">
        <div v-for="metric in listed" :key="metric" class="flex gap-1.5">
            <dt class="text-muted-foreground">{{ metricLabels[metric] }}</dt>
            <dd><MetricValue :value="numbers[metric]" /></dd>
        </div>
        <div v-if="score !== undefined" class="flex gap-1.5">
            <dt class="text-muted-foreground">Score</dt>
            <dd>
                <MetricValue
                    :value="score"
                    :digits="2"
                    reason="No number that the score counts was given."
                />
            </dd>
        </div>
    </dl>
</template>
