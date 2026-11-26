<script setup lang="ts">
import { computed } from 'vue';
import MetricValue from '@/components/insights/MetricValue.vue';
import TextLink from '@/components/TextLink.vue';
import {
    metricLabels,
    metricNames,
    missingReason,
    needsReconnect,
    permissionNote,
    variantNote,
} from '@/lib/insights';
import { formatDateTime, useWorkspaceTimezone } from '@/lib/posts';
import { index as channels } from '@/routes/channels';
import { index as insightsPage } from '@/routes/insights';
import type { PostInsights, Reading } from '@/types/insights';

/**
 * How a published post did, on the post page: one row for each channel it
 * is on, a total when there are several, what each channel has to say
 * about its numbers, and the readings over time. Read-only.
 */
const props = defineProps<{
    insights: PostInsights;
    reading: Reading;
}>();

const timezone = useWorkspaceTimezone();

const several = computed(() => props.insights.variants.length > 1);

const measured = computed(() =>
    props.insights.variants.filter((variant) => variant.state === 'measured'),
);

// What is said under the table: where the numbers of each measured channel
// stand, and which numbers a channel could give with one more permission.
const notes = computed(() =>
    props.insights.variants
        .map((variant) => ({
            id: variant.variant_id,
            channel: variant.channel_name,
            text:
                variant.state === 'measured'
                    ? variantNote(variant, props.reading, timezone.value)
                    : null,
            reconnect: variant.state === 'measured' && needsReconnect(variant),
            permission: permissionNote(
                variant.needs_permission,
                variant.platform_label,
            ),
        }))
        .filter((note) => note.text !== null || note.permission !== null),
);

const withHistory = computed(() =>
    measured.value.filter((variant) => (variant.history?.length ?? 0) > 0),
);
</script>

<template>
    <section
        class="grid gap-4 rounded-2xl bg-card p-5.5 shadow-xs"
        aria-labelledby="post-numbers"
        data-test="post-numbers"
    >
        <div>
            <h2 id="post-numbers" class="text-heading">Numbers</h2>
            <p class="mt-1 max-w-[72ch] text-muted-foreground">
                How this post did on each channel. A dash is a number the
                platform did not give. It is not zero.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[38rem] border-collapse text-base">
                <caption class="sr-only">
                    The numbers of this post for each channel
                </caption>
                <thead>
                    <tr class="text-sm text-muted-foreground">
                        <th scope="col" class="py-2 pr-3 text-left font-normal">
                            Channel
                        </th>
                        <th
                            v-for="metric in metricNames"
                            :key="metric"
                            scope="col"
                            class="px-3 py-2 text-right font-normal"
                        >
                            {{ metricLabels[metric] }}
                        </th>
                        <th
                            scope="col"
                            class="py-2 pl-3 text-right font-normal"
                        >
                            Score
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="variant in insights.variants"
                        :key="variant.variant_id"
                        class="border-t border-border align-top"
                        :data-test="`numbers-${variant.variant_id}`"
                    >
                        <th
                            scope="row"
                            class="py-2.5 pr-3 text-left font-normal"
                        >
                            <span class="font-semibold">
                                {{ variant.channel_name }}
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                {{ variant.platform_label }}
                            </span>
                        </th>

                        <template v-if="variant.numbers">
                            <td
                                v-for="metric in metricNames"
                                :key="metric"
                                class="px-3 py-2.5 text-right"
                            >
                                <MetricValue
                                    :value="variant.numbers[metric].value"
                                    :reason="
                                        missingReason(
                                            variant.numbers[metric].missing,
                                            variant.platform_label,
                                        )
                                    "
                                />
                            </td>
                            <td class="py-2.5 pl-3 text-right">
                                <MetricValue
                                    :value="variant.score"
                                    :digits="2"
                                    reason="No number that the score counts was given."
                                />
                            </td>
                        </template>
                        <td
                            v-else
                            :colspan="metricNames.length + 1"
                            class="max-w-[60ch] py-2.5 pl-3 text-sm"
                            :data-test="`not-measured-${variant.variant_id}`"
                        >
                            {{ variantNote(variant, reading, timezone) }}
                            <TextLink
                                v-if="needsReconnect(variant)"
                                :href="channels()"
                            >
                                Open Channels
                            </TextLink>
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="several && insights.total">
                    <tr
                        class="border-t-2 border-border align-top font-semibold"
                        data-test="numbers-total"
                    >
                        <th scope="row" class="py-2.5 pr-3 text-left">
                            Total
                            <span
                                v-if="
                                    insights.total.measured <
                                    insights.total.published
                                "
                                class="block text-xs font-normal text-muted-foreground"
                            >
                                {{ insights.total.measured }} of
                                {{ insights.total.published }} channels measured
                            </span>
                        </th>
                        <td
                            v-for="metric in metricNames"
                            :key="metric"
                            class="px-3 py-2.5 text-right"
                        >
                            <MetricValue
                                :value="insights.total.numbers[metric]"
                                reason="No channel gave this number."
                            />
                        </td>
                        <td class="py-2.5 pl-3 text-right">
                            <MetricValue
                                :value="insights.total.score"
                                :digits="2"
                                reason="No number that the score counts was given."
                            />
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <ul
            v-if="notes.length"
            class="grid max-w-[72ch] gap-1.5 text-sm text-muted-foreground"
            data-test="numbers-notes"
        >
            <li v-for="note in notes" :key="note.id">
                <span v-if="several" class="font-semibold text-foreground">
                    {{ note.channel }}:
                </span>
                {{ note.text }}
                <TextLink v-if="note.reconnect" :href="channels()">
                    Open Channels
                </TextLink>
                <template v-if="note.permission">
                    {{ note.permission }}
                    <TextLink :href="`${insightsPage.url()}#more-numbers`">
                        How to get them
                    </TextLink>
                </template>
            </li>
        </ul>

        <details v-if="withHistory.length" data-test="numbers-history">
            <summary
                class="flex min-h-11 w-fit cursor-pointer items-center font-semibold underline underline-offset-4"
            >
                Readings over time
            </summary>
            <div class="mt-2 grid gap-5">
                <p class="max-w-[72ch] text-sm text-muted-foreground">
                    {{ reading.cadence }} A row is added only when a number
                    changed, so each row held from its first time until at least
                    its second.
                </p>
                <div
                    v-for="variant in withHistory"
                    :key="variant.variant_id"
                    class="overflow-x-auto"
                >
                    <table
                        class="w-full min-w-[40rem] border-collapse text-base"
                    >
                        <caption
                            class="pb-1 text-left text-sm font-semibold"
                            :class="several ? '' : 'sr-only'"
                        >
                            {{
                                variant.channel_name
                            }}
                        </caption>
                        <thead>
                            <tr class="text-sm text-muted-foreground">
                                <th
                                    scope="col"
                                    class="py-2 pr-3 text-left font-normal"
                                >
                                    First read
                                </th>
                                <th
                                    scope="col"
                                    class="px-3 py-2 text-left font-normal"
                                >
                                    Still so at
                                </th>
                                <th
                                    v-for="metric in metricNames"
                                    :key="metric"
                                    scope="col"
                                    class="px-3 py-2 text-right font-normal last:pr-0"
                                >
                                    {{ metricLabels[metric] }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in variant.history ?? []"
                                :key="row.captured_at"
                                class="border-t border-border"
                            >
                                <td class="py-2 pr-3 font-mono text-sm">
                                    {{
                                        formatDateTime(
                                            row.captured_at,
                                            timezone,
                                        )
                                    }}
                                </td>
                                <td class="px-3 py-2 font-mono text-sm">
                                    {{
                                        formatDateTime(row.checked_at, timezone)
                                    }}
                                </td>
                                <td
                                    v-for="metric in metricNames"
                                    :key="metric"
                                    class="px-3 py-2 text-right last:pr-0"
                                >
                                    <MetricValue :value="row[metric]" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </details>
    </section>
</template>
