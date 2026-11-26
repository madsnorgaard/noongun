<script setup lang="ts">
import { computed } from 'vue';
import MetricList from '@/components/insights/MetricList.vue';
import { variantNote } from '@/lib/insights';
import { formatDateTime, useWorkspaceTimezone } from '@/lib/posts';
import type { PostInsights, Reading } from '@/types/insights';

/**
 * How a published post did, on one line of a list: the numbers of all its
 * channels added up, or why there are none yet. The post page has the
 * numbers of each channel.
 */
const props = defineProps<{
    insights: PostInsights;
    reading: Reading;
}>();

const timezone = useWorkspaceTimezone();

const unmeasured = computed(() => {
    const variants = props.insights.variants;

    if (variants.length === 1) {
        return variantNote(variants[0], props.reading, timezone.value);
    }

    return `Not measured on any of its ${variants.length} channels. Open the post to see why for each of them.`;
});
</script>

<template>
    <div
        v-if="insights.total"
        class="flex flex-wrap items-baseline gap-x-5 gap-y-1"
    >
        <MetricList
            :numbers="insights.total.numbers"
            :score="insights.total.score"
        />
        <p class="text-sm text-muted-foreground">
            <template v-if="insights.total.measured < insights.total.published">
                {{ insights.total.measured }} of
                {{ insights.total.published }} channels measured,
            </template>
            <template v-if="insights.total.checked_at">
                checked
                <span class="font-mono">
                    {{ formatDateTime(insights.total.checked_at, timezone) }}
                </span>
            </template>
        </p>
    </div>
    <p v-else class="max-w-[72ch] text-sm text-muted-foreground">
        {{ unmeasured }}
    </p>
</template>
