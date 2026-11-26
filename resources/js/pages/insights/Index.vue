<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import MetricList from '@/components/insights/MetricList.vue';
import MetricValue from '@/components/insights/MetricValue.vue';
import TextLink from '@/components/TextLink.vue';
import {
    counted,
    formatNumber,
    metricLabels,
    metricNames,
    permissionNote,
    scoreFormula,
} from '@/lib/insights';
import { formatDate, formatDateTime, useWorkspaceTimezone } from '@/lib/posts';
import { index as channelsPage } from '@/routes/channels';
import { index } from '@/routes/insights';
import { show } from '@/routes/posts';
import { index as queue } from '@/routes/queue';
import type {
    ChannelSummary,
    InsightsStatus,
    MetricName,
    PeriodTotals,
    PillarSummary,
    Reading,
    ScoreWeights,
    Summary,
    TopPost,
} from '@/types/insights';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Insights',
                href: index(),
            },
        ],
    },
});

const props = defineProps<{
    /** The days of the period are days on the clock of the workspace. */
    period: { days: number; options: number[]; from: string; until: string };
    reading: Reading;
    weights: ScoreWeights;
    status: InsightsStatus;
    totals: PeriodTotals;
    /** Best average score first, posts without a pillar last. */
    pillars: PillarSummary[];
    top: TopPost[];
    channels: ChannelSummary[];
}>();

const timezone = useWorkspaceTimezone();

const dates = computed(() => {
    const options: Intl.DateTimeFormatOptions = {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    };

    return `${formatDate(props.period.from, options)} - ${formatDate(props.period.until, options)}`;
});

const nothing = computed(() => {
    switch (props.status) {
        case 'nothing_published':
            return {
                title: 'Nothing is published yet',
                text: 'Numbers appear here after the first post has gone out. A post goes out when it is approved and its firing time comes.',
                queue: true,
            };
        case 'nothing_in_period':
            return {
                title: `Nothing was published in the last ${props.period.days} days`,
                text: 'Choose a longer period to see how older posts did.',
                queue: false,
            };
        case 'nothing_measured':
            return {
                title: 'Nothing is measured yet',
                text: `${counted(props.totals.posts, 'post', 'posts')} went out in this period and none has numbers yet. ${props.reading.cadence} The table of channels says which channels give numbers.`,
                queue: false,
            };
        default:
            return null;
    }
});

/**
 * Why the average under a total may be made of fewer posts than were
 * measured: said next to the number, so that it cannot mislead.
 */
function partly(summary: Summary, metric: MetricName): string | null {
    const posts = summary.numbers[metric].posts;

    return posts > 0 && posts < summary.measured_posts
        ? `from ${posts} of ${summary.measured_posts}`
        : null;
}

/**
 * What stands in the way of the numbers of a channel, and what to do.
 */
function channelNotes(channel: ChannelSummary): string[] {
    const notes: string[] = [];

    if (!channel.gives_numbers) {
        notes.push(
            `${channel.platform_label} does not give Noon Gun numbers for posts yet.`,
        );

        return notes;
    }

    if (channel.status === 'needs_reconnect') {
        notes.push(
            'Needs to be connected again. Its numbers are not updated until then.',
        );
    } else if (channel.status === 'disconnected') {
        notes.push('Disconnected. Its numbers are no longer updated.');
    } else if (channel.paused) {
        notes.push(
            `${channel.platform_label} asked Noon Gun to wait. Reading starts again by itself.`,
        );
    }

    const permission = permissionNote(
        channel.needs_permission,
        channel.platform_label,
    );

    if (permission !== null) {
        notes.push(permission);
    }

    return notes;
}
</script>

<template>
    <Head title="Insights" />

    <div class="flex flex-1 flex-col gap-5.5 px-4 pt-2 pb-12 md:px-8 lg:px-11">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="grid gap-1">
                <h1 class="text-title md:text-display">Insights</h1>
                <p class="text-muted-foreground">
                    How published posts did, from
                    <span class="font-mono" data-test="period-dates">{{
                        dates
                    }}</span
                    >. Days and times in {{ timezone }}.
                </p>
            </div>

            <nav
                class="flex w-fit gap-1.5 rounded-lg bg-accent p-1"
                aria-label="Period"
            >
                <Link
                    v-for="days in period.options"
                    :key="days"
                    :href="index({ query: { period: days } })"
                    class="flex min-h-11 items-center rounded-[10px] px-4 text-base font-semibold"
                    :class="
                        days === period.days
                            ? 'bg-card shadow-xs'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-current="days === period.days ? 'page' : undefined"
                    :data-test="`period-${days}`"
                >
                    Last {{ days }} days
                </Link>
            </nav>
        </header>

        <section
            v-if="!reading.enabled"
            class="rounded-xl bg-card p-5.5 shadow-xs"
            data-test="insights-off"
        >
            <h2 class="text-heading">Insights are turned off</h2>
            <p class="mt-1 max-w-[72ch] text-muted-foreground">
                This instance does not read numbers from the platforms, so
                nothing new arrives here. What was read before is still shown.
                To turn insights on, set
                <span class="font-mono text-sm"
                    >NOONGUN_INSIGHTS_ENABLED=true</span
                >
                and restart the containers.
            </p>
        </section>

        <section
            v-if="nothing"
            class="rounded-xl bg-card p-5.5 shadow-xs"
            :data-test="`insights-${status}`"
        >
            <h2 class="text-heading">{{ nothing.title }}</h2>
            <p class="mt-1 max-w-[72ch] text-muted-foreground">
                {{ nothing.text }}
                <TextLink v-if="nothing.queue" :href="queue()">
                    Open the queue
                </TextLink>
            </p>
        </section>

        <section
            v-if="status === 'ok'"
            class="grid gap-2 rounded-xl bg-card p-5.5 shadow-xs"
            data-test="insights-totals"
        >
            <p>
                <span class="font-mono">{{ formatNumber(totals.posts) }}</span>
                {{ totals.posts === 1 ? 'post' : 'posts' }} published,
                <span class="font-mono">{{
                    formatNumber(totals.measured_posts)
                }}</span>
                measured.
            </p>
            <MetricList :numbers="totals.numbers" :score="totals.score" />
            <p class="max-w-[72ch] text-sm text-muted-foreground">
                Score =
                <span class="font-mono" data-test="score-formula">{{
                    scoreFormula(weights)
                }}</span
                >. Reach is not part of it, so that posts can be compared where
                reach is not given.
            </p>
        </section>

        <section
            v-if="pillars.length"
            class="grid gap-3 rounded-xl bg-card p-5.5 shadow-xs"
            aria-labelledby="insights-pillars"
        >
            <div>
                <h2 id="insights-pillars" class="text-heading">Pillars</h2>
                <p class="mt-1 max-w-[72ch] text-muted-foreground">
                    Which themes work, best average score first. Under each
                    total is the average for a post that has that number.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[56rem] border-collapse text-base">
                    <caption class="sr-only">
                        Totals and averages for each pillar
                    </caption>
                    <thead>
                        <tr class="text-sm text-muted-foreground">
                            <th
                                scope="col"
                                class="py-2 pr-3 text-left font-normal"
                            >
                                Pillar
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-normal"
                            >
                                Posts
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-normal"
                            >
                                Measured
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
                                class="px-3 py-2 text-right font-normal"
                            >
                                Score
                            </th>
                            <th
                                scope="col"
                                class="py-2 pl-3 text-right font-normal"
                            >
                                Average score
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="pillar in pillars"
                            :key="pillar.id ?? 'none'"
                            class="border-t border-border align-top"
                            :data-test="`pillar-${pillar.id ?? 'none'}`"
                        >
                            <th
                                scope="row"
                                class="py-2.5 pr-3 text-left"
                                :class="
                                    pillar.id === null
                                        ? 'font-normal text-muted-foreground'
                                        : 'font-semibold'
                                "
                            >
                                {{ pillar.name ?? 'No pillar' }}
                            </th>
                            <td class="px-3 py-2.5 text-right font-mono">
                                {{ formatNumber(pillar.posts) }}
                            </td>
                            <td class="px-3 py-2.5 text-right font-mono">
                                {{ formatNumber(pillar.measured_posts) }}
                            </td>
                            <td
                                v-for="metric in metricNames"
                                :key="metric"
                                class="px-3 py-2.5 text-right"
                            >
                                <MetricValue
                                    :value="pillar.numbers[metric].total"
                                    reason="No post of this pillar has this number."
                                />
                                <span
                                    v-if="
                                        pillar.numbers[metric].average !== null
                                    "
                                    class="block text-xs text-muted-foreground"
                                >
                                    avg
                                    <span class="font-mono">{{
                                        formatNumber(
                                            pillar.numbers[metric].average ?? 0,
                                            1,
                                        )
                                    }}</span>
                                    <span
                                        v-if="partly(pillar, metric)"
                                        class="block"
                                    >
                                        {{ partly(pillar, metric) }}
                                    </span>
                                </span>
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <MetricValue
                                    :value="pillar.score"
                                    :digits="2"
                                    reason="No post of this pillar has a score yet."
                                />
                            </td>
                            <td class="py-2.5 pl-3 text-right font-semibold">
                                <MetricValue
                                    :value="pillar.average_score"
                                    :digits="1"
                                    reason="No post of this pillar has a score yet."
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            v-if="top.length"
            class="grid gap-3 rounded-xl bg-card p-5.5 shadow-xs"
            aria-labelledby="insights-top"
        >
            <div>
                <h2 id="insights-top" class="text-heading">Top posts</h2>
                <p class="mt-1 max-w-[72ch] text-muted-foreground">
                    The posts of this period with the highest score, all their
                    channels added up. A post on a Page with many followers
                    scores higher than the same post on a small one.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[52rem] border-collapse text-base">
                    <caption class="sr-only">
                        The posts with the highest score in this period
                    </caption>
                    <thead>
                        <tr class="text-sm text-muted-foreground">
                            <th
                                scope="col"
                                class="py-2 pr-3 text-left font-normal"
                            >
                                Post
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
                            v-for="post in top"
                            :key="post.post_id"
                            class="border-t border-border align-top"
                            :data-test="`top-${post.post_id}`"
                        >
                            <th
                                scope="row"
                                class="py-1 pr-3 text-left font-normal"
                            >
                                <Link
                                    :href="show({ post: post.post_id })"
                                    class="flex min-h-11 max-w-[60ch] items-center font-semibold underline decoration-input underline-offset-4 hover:decoration-current"
                                >
                                    {{ post.excerpt || 'A post without text' }}
                                </Link>
                                <span
                                    class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground"
                                >
                                    <span
                                        v-for="channel in post.channels"
                                        :key="channel.name"
                                        class="rounded-sm bg-secondary px-2.5 py-1 text-sm text-foreground"
                                    >
                                        {{ channel.name }}
                                    </span>
                                    <span v-if="post.pillar">
                                        {{ post.pillar }}
                                    </span>
                                    <span v-else>No pillar</span>
                                    <span
                                        v-if="post.published_at"
                                        class="font-mono"
                                    >
                                        {{
                                            formatDateTime(
                                                post.published_at,
                                                timezone,
                                            )
                                        }}
                                    </span>
                                    <span v-if="post.measured < post.published">
                                        {{ post.measured }} of
                                        {{ post.published }} channels measured
                                    </span>
                                </span>
                            </th>
                            <td
                                v-for="metric in metricNames"
                                :key="metric"
                                class="px-3 py-2.5 text-right"
                            >
                                <MetricValue :value="post.numbers[metric]" />
                            </td>
                            <td class="py-2.5 pl-3 text-right font-semibold">
                                <MetricValue :value="post.score" :digits="2" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            v-if="channels.length"
            class="grid gap-3 rounded-xl bg-card p-5.5 shadow-xs"
            aria-labelledby="insights-channels"
        >
            <div>
                <h2 id="insights-channels" class="text-heading">Channels</h2>
                <p class="mt-1 max-w-[72ch] text-muted-foreground">
                    What each channel added up to in this period, and whether
                    its numbers are being read.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[52rem] border-collapse text-base">
                    <caption class="sr-only">
                        Totals for each channel
                    </caption>
                    <thead>
                        <tr class="text-sm text-muted-foreground">
                            <th
                                scope="col"
                                class="py-2 pr-3 text-left font-normal"
                            >
                                Channel
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-normal"
                            >
                                Posts
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-normal"
                            >
                                Measured
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
                            v-for="channel in channels"
                            :key="channel.id"
                            class="border-t border-border align-top"
                            :data-test="`channel-${channel.id}`"
                        >
                            <th
                                scope="row"
                                class="py-2.5 pr-3 text-left font-normal"
                            >
                                <span class="font-semibold">
                                    {{ channel.name }}
                                </span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{ channel.platform_label }}
                                </span>
                                <span
                                    v-for="note in channelNotes(channel)"
                                    :key="note"
                                    class="mt-1 block max-w-[44ch] text-sm text-muted-foreground"
                                >
                                    {{ note }}
                                </span>
                            </th>
                            <td class="px-3 py-2.5 text-right font-mono">
                                {{ formatNumber(channel.posts) }}
                            </td>
                            <td class="px-3 py-2.5 text-right font-mono">
                                {{ formatNumber(channel.measured_posts) }}
                            </td>
                            <td
                                v-for="metric in metricNames"
                                :key="metric"
                                class="px-3 py-2.5 text-right"
                            >
                                <MetricValue
                                    :value="channel.numbers[metric].total"
                                    :reason="
                                        channel.gives_numbers
                                            ? 'No post of this channel has this number.'
                                            : `${channel.platform_label} does not give this number.`
                                    "
                                />
                            </td>
                            <td class="py-2.5 pl-3 text-right">
                                <MetricValue
                                    :value="channel.score"
                                    :digits="2"
                                    reason="No post of this channel has a score."
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            class="grid gap-3 rounded-xl bg-card p-5.5 shadow-xs"
            aria-labelledby="insights-reading"
        >
            <h2 id="insights-reading" class="text-heading">
                How to read these numbers
            </h2>
            <ul class="grid max-w-[72ch] gap-2 text-base">
                <li>
                    A dash is a number the platform did not give. It is not
                    zero, and it is left out of every total and average.
                </li>
                <li>{{ reading.cadence }}</li>
                <li>
                    The numbers are the latest reading of each post, whenever it
                    was published within the period.
                </li>
                <li id="more-numbers" class="scroll-mt-24">
                    Some numbers need one more permission. On Facebook, reach
                    and clicks are only given to an app that has
                    <span class="font-mono text-sm">read_insights</span>: add it
                    to the Meta app and to
                    <span class="font-mono text-sm">FACEBOOK_SCOPES</span>, then
                    connect the Page again on
                    <TextLink :href="channelsPage()">Channels</TextLink>. The
                    README describes each step under Insights.
                </li>
            </ul>
        </section>
    </div>
</template>
