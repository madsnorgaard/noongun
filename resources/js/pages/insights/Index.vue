<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import MetricList from '@/components/insights/MetricList.vue';
import MetricValue from '@/components/insights/MetricValue.vue';
import PeriodCounts from '@/components/insights/PeriodCounts.vue';
import ScrollTable from '@/components/insights/ScrollTable.vue';
import TextLink from '@/components/TextLink.vue';
import {
    counted,
    formatNumber,
    hiddenNote,
    metricLabels,
    metricsShown,
    pauseNote,
    permissionNote,
    reachNotAdded,
    scoreFormula,
    sentenceList,
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
    /** The channel the pillars and the top posts are narrowed to. */
    channel: number | null;
    reading: Reading;
    status: InsightsStatus;
    totals: PeriodTotals;
    /** Pillars with enough posts to compare first, best average score first. */
    pillars: PillarSummary[];
    /** False while too few posts compete to say which did best. */
    ranked: boolean;
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

function address(period: number, channel: number | null) {
    return index({
        query: { period, ...(channel === null ? {} : { channel }) },
    });
}

// Pillars can only be compared fairly inside one channel, so the choice is
// offered as soon as there are two channels with numbers.
const comparable = computed(() =>
    props.channels.filter((channel) => channel.gives_numbers),
);
const chosen = computed(
    () =>
        props.channels.find((channel) => channel.id === props.channel) ?? null,
);

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
 * Said next to a number that is made of fewer posts than were measured,
 * so that it cannot mislead.
 */
function partly(summary: Summary, metric: MetricName): string | null {
    const count = summary.numbers[metric].with;

    return count > 0 && count < summary.measured
        ? `from ${count} of ${summary.measured}`
        : null;
}

const totalsPartly = computed(() => {
    const count = props.totals.with.clicks;

    return {
        clicks:
            count > 0 && count < props.totals.measured
                ? `from ${count} of ${props.totals.measured}`
                : null,
    };
});

// Clicks and reach get a column only where a row has them.
const pillarMetrics = computed(() =>
    metricsShown((metric) =>
        props.pillars.some((pillar) => pillar.numbers[metric].average !== null),
    ),
);
const topMetrics = computed(() =>
    metricsShown((metric) =>
        props.top.some((post) => post.numbers[metric] !== null),
    ),
);
const channelMetrics = computed(() =>
    metricsShown((metric) =>
        props.channels.some(
            (channel) => channel.numbers[metric].average !== null,
        ),
    ),
);

const youngLeftOut = computed(
    () =>
        `Posts that went out less than ${props.reading.min_age} ago are left out: they are still collecting reactions.`,
);

/**
 * What stands in the way of the numbers of a channel, and what to do.
 */
function channelNotes(channel: ChannelSummary): string[] {
    if (!channel.gives_numbers) {
        return [
            `${channel.platform_label} does not give Noon Gun numbers for posts yet.`,
        ];
    }

    if (channel.status === 'needs_reconnect') {
        return [
            props.reading.can_set_up
                ? 'Needs to be connected again. Its numbers are not updated until then.'
                : 'Needs to be connected again. Its numbers are not updated until then. Ask an owner of this workspace.',
        ];
    }

    if (channel.status === 'disconnected') {
        return ['Disconnected. Its numbers are no longer updated.'];
    }

    return channel.pause
        ? [
              pauseNote(
                  channel.pause,
                  channel.platform_label,
                  channel.name,
                  timezone.value,
                  props.reading.can_set_up,
              ),
          ]
        : [];
}

// Said once under the table: which channels could give more numbers with
// one more permission.
const permission = computed(() => {
    const wanting = props.channels.filter(
        (channel) => channel.needs_permission.length > 0,
    );

    if (wanting.length === 0) {
        return null;
    }

    const metrics = [
        ...new Set(wanting.flatMap((channel) => channel.needs_permission)),
    ] as MetricName[];
    const platforms = [
        ...new Set(wanting.map((channel) => channel.platform_label)),
    ];
    const note = permissionNote(metrics, sentenceList(platforms)) ?? '';

    return `${note.slice(0, -1)} for ${sentenceList(wanting.map((channel) => channel.name))}. It is optional: everything else works without it.`;
});
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
                class="flex w-fit flex-wrap gap-1.5 rounded-lg bg-accent p-1"
                aria-label="Period"
            >
                <Link
                    v-for="days in period.options"
                    :key="days"
                    :href="address(days, channel)"
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
                <template v-if="reading.can_set_up">
                    To turn insights on, set
                    <span class="font-mono text-sm"
                        >NOONGUN_INSIGHTS_ENABLED=true</span
                    >
                    and restart the containers.
                </template>
                <template v-else>
                    Whoever runs this instance can turn them on.
                </template>
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
            <PeriodCounts :totals="totals" />
            <MetricList
                :numbers="totals.numbers"
                :score="totals.score"
                :partly="totalsPartly"
            />
            <p class="max-w-[72ch] text-sm text-muted-foreground">
                Score =
                <span class="font-mono" data-test="score-formula">{{
                    scoreFormula(reading.weights)
                }}</span
                >, the numbers every measured channel gives. Clicks and reach
                are shown where a channel gives them and are not counted, so
                that a channel with more numbers does not score higher for the
                same post. Reach is never added up: the same person would be
                counted once for each post.
            </p>
        </section>

        <nav
            v-if="status === 'ok' && comparable.length > 1"
            class="flex flex-wrap items-center gap-x-3 gap-y-2"
            aria-label="Channel for pillars and top posts"
            data-test="channel-filter"
        >
            <span class="text-sm text-muted-foreground">
                Pillars and top posts for
            </span>
            <span class="flex flex-wrap gap-1.5 rounded-lg bg-accent p-1">
                <Link
                    :href="address(period.days, null)"
                    class="flex min-h-11 items-center rounded-[10px] px-4 text-base font-semibold"
                    :class="
                        channel === null
                            ? 'bg-card shadow-xs'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-current="channel === null ? 'page' : undefined"
                >
                    All channels
                </Link>
                <Link
                    v-for="option in comparable"
                    :key="option.id"
                    :href="address(period.days, option.id)"
                    class="flex min-h-11 items-center rounded-[10px] px-4 text-base font-semibold"
                    :class="
                        channel === option.id
                            ? 'bg-card shadow-xs'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-current="channel === option.id ? 'page' : undefined"
                    :data-test="`channel-filter-${option.id}`"
                >
                    {{ option.name }}
                </Link>
            </span>
        </nav>

        <section
            v-if="pillars.length"
            class="grid gap-3 rounded-xl bg-card p-5.5 shadow-xs"
            aria-labelledby="insights-pillars"
        >
            <div>
                <h2 id="insights-pillars" class="text-heading">Pillars</h2>
                <p class="mt-1 max-w-[72ch] text-muted-foreground">
                    <template v-if="chosen">
                        What one post of each pillar did on {{ chosen.name }},
                        on average.
                    </template>
                    <template v-else>
                        What one post on one channel did, on average, for each
                        pillar. A post on three channels counts three times.
                        <template v-if="comparable.length > 1">
                            Channels of different sizes are mixed here, so
                            choose one channel to compare pillars like with
                            like.
                        </template>
                    </template>
                    {{ youngLeftOut }} A pillar with fewer than
                    {{ reading.min_measured }} measured posts is listed after
                    the others: that is too few to compare.
                </p>
            </div>

            <ScrollTable label="Averages for each pillar">
                <table class="w-full min-w-[44rem] border-collapse text-base">
                    <caption class="sr-only">
                        Averages for each pillar
                    </caption>
                    <thead>
                        <tr class="text-sm text-muted-foreground">
                            <th
                                scope="col"
                                class="sticky left-0 bg-card py-2 pr-3 text-left font-normal"
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
                                Compared
                            </th>
                            <th
                                v-for="metric in pillarMetrics"
                                :key="metric"
                                scope="col"
                                class="px-3 py-2 text-right font-normal"
                            >
                                {{ metricLabels[metric] }} per post
                            </th>
                            <th
                                scope="col"
                                class="py-2 pl-3 text-right font-normal"
                            >
                                Score per post
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
                                class="sticky left-0 bg-card py-2.5 pr-3 text-left"
                                :class="
                                    pillar.id === null
                                        ? 'font-normal text-muted-foreground'
                                        : 'font-semibold'
                                "
                            >
                                {{ pillar.name ?? 'No pillar' }}
                                <span
                                    v-if="!pillar.enough && pillar.id !== null"
                                    class="block text-xs font-normal text-muted-foreground"
                                >
                                    Too few to compare
                                </span>
                            </th>
                            <td class="px-3 py-2.5 text-right font-mono">
                                {{ formatNumber(pillar.posts) }}
                            </td>
                            <td class="px-3 py-2.5 text-right font-mono">
                                {{ formatNumber(pillar.measured) }}
                            </td>
                            <td
                                v-for="metric in pillarMetrics"
                                :key="metric"
                                class="px-3 py-2.5 text-right"
                            >
                                <MetricValue
                                    :value="pillar.numbers[metric].average"
                                    :digits="1"
                                    reason="No compared post of this pillar has this number."
                                />
                                <span
                                    v-if="
                                        pillar.numbers[metric].total !== null ||
                                        partly(pillar, metric)
                                    "
                                    class="block text-xs text-muted-foreground"
                                >
                                    <template
                                        v-if="
                                            pillar.numbers[metric].total !==
                                            null
                                        "
                                    >
                                        total
                                        <span class="font-mono">{{
                                            formatNumber(
                                                pillar.numbers[metric].total ??
                                                    0,
                                            )
                                        }}</span>
                                    </template>
                                    <span
                                        v-if="partly(pillar, metric)"
                                        class="block"
                                    >
                                        {{ partly(pillar, metric) }}
                                    </span>
                                </span>
                            </td>
                            <td class="py-2.5 pl-3 text-right font-semibold">
                                <MetricValue
                                    :value="pillar.average_score"
                                    :digits="1"
                                    reason="No compared post of this pillar has a score yet."
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </ScrollTable>
            <p
                v-if="hiddenNote(pillarMetrics)"
                class="text-sm text-muted-foreground"
            >
                {{ hiddenNote(pillarMetrics) }}
            </p>
        </section>

        <section
            v-if="status === 'ok'"
            class="grid gap-3 rounded-xl bg-card p-5.5 shadow-xs"
            aria-labelledby="insights-top"
            data-test="insights-top"
        >
            <div>
                <h2 id="insights-top" class="text-heading">
                    {{ ranked ? 'Top posts' : 'Measured posts' }}
                </h2>
                <p class="mt-1 max-w-[72ch] text-muted-foreground">
                    <template v-if="top.length === 0">
                        No post of this period is both measured and older than
                        {{ reading.min_age }} yet.
                    </template>
                    <template v-else-if="ranked">
                        The posts with the highest score<template v-if="chosen">
                            on {{ chosen.name }}</template
                        ><template v-else-if="comparable.length > 1"
                            >, all their channels added up. A post on a channel
                            with many followers scores higher than the same post
                            on a small one</template
                        >.
                    </template>
                    <template v-else>
                        Fewer than {{ reading.min_measured }} posts are measured
                        and old enough, which is too few to call one the best.
                        They are listed by score.
                    </template>
                    {{ youngLeftOut }}
                </p>
            </div>

            <ScrollTable
                v-if="top.length"
                label="Measured posts of this period by score"
            >
                <table class="w-full min-w-[40rem] border-collapse text-base">
                    <caption class="sr-only">
                        Measured posts of this period by score
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
                                v-for="metric in topMetrics"
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
                                        :key="channel.id"
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
                                v-for="metric in topMetrics"
                                :key="metric"
                                class="px-3 py-2.5 text-right"
                            >
                                <MetricValue
                                    :value="post.numbers[metric]"
                                    :reason="
                                        metric === 'reach' && post.measured > 1
                                            ? reachNotAdded
                                            : undefined
                                    "
                                />
                            </td>
                            <td class="py-2.5 pl-3 text-right font-semibold">
                                <MetricValue :value="post.score" :digits="2" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </ScrollTable>
            <p
                v-if="top.length && hiddenNote(topMetrics)"
                class="text-sm text-muted-foreground"
            >
                {{ hiddenNote(topMetrics) }}
            </p>
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
                    its numbers are being read. Reach is the average for one
                    post, because it cannot be added up.
                </p>
            </div>

            <ScrollTable label="Totals for each channel">
                <table class="w-full min-w-[44rem] border-collapse text-base">
                    <caption class="sr-only">
                        Totals for each channel
                    </caption>
                    <thead>
                        <tr class="text-sm text-muted-foreground">
                            <th
                                scope="col"
                                class="sticky left-0 bg-card py-2 pr-3 text-left font-normal"
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
                                v-for="metric in channelMetrics"
                                :key="metric"
                                scope="col"
                                class="px-3 py-2 text-right font-normal"
                            >
                                {{
                                    metric === 'reach'
                                        ? 'Reach per post'
                                        : metricLabels[metric]
                                }}
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
                            v-for="row in channels"
                            :key="row.id"
                            class="border-t border-border align-top"
                            :data-test="`channel-${row.id}`"
                        >
                            <th
                                scope="row"
                                class="sticky left-0 bg-card py-2.5 pr-3 text-left font-normal"
                            >
                                <span class="font-semibold">
                                    {{ row.name }}
                                </span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{ row.platform_label }}
                                </span>
                                <span
                                    v-for="note in channelNotes(row)"
                                    :key="note"
                                    class="mt-1 block max-w-[44ch] text-sm text-muted-foreground"
                                >
                                    {{ note }}
                                </span>
                            </th>
                            <td class="px-3 py-2.5 text-right font-mono">
                                {{ formatNumber(row.posts) }}
                            </td>
                            <td class="px-3 py-2.5 text-right font-mono">
                                {{ formatNumber(row.measured) }}
                            </td>
                            <td
                                v-for="metric in channelMetrics"
                                :key="metric"
                                class="px-3 py-2.5 text-right"
                            >
                                <MetricValue
                                    :value="
                                        metric === 'reach'
                                            ? row.numbers.reach.average
                                            : row.numbers[metric].total
                                    "
                                    :digits="metric === 'reach' ? 1 : 0"
                                    :reason="
                                        row.gives_numbers
                                            ? 'No post of this channel has this number.'
                                            : `${row.platform_label} does not give this number.`
                                    "
                                />
                                <span
                                    v-if="partly(row, metric)"
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{ partly(row, metric) }}
                                </span>
                            </td>
                            <td class="py-2.5 pl-3 text-right">
                                <MetricValue
                                    :value="row.score"
                                    :digits="2"
                                    reason="No post of this channel has a score."
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </ScrollTable>
            <p
                v-if="permission"
                class="max-w-[72ch] text-sm text-muted-foreground"
                data-test="channels-permission"
            >
                {{ permission }}
                <TextLink v-if="reading.can_set_up" href="#more-numbers">
                    How to add it
                </TextLink>
                <template v-else>
                    An owner of this workspace can add it.
                </template>
            </p>
            <p
                v-else-if="hiddenNote(channelMetrics)"
                class="text-sm text-muted-foreground"
            >
                {{ hiddenNote(channelMetrics) }}
            </p>
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
                <li>
                    These numbers say what happened, not why. A pillar with a
                    higher average did better in this period on these channels;
                    the time of day, the channel and chance play their part.
                </li>
                <li id="more-numbers" class="scroll-mt-24">
                    Some numbers need one more permission, and it is optional.
                    <template v-if="reading.can_set_up">
                        A Facebook Page connects and publishes without
                        <span class="font-mono text-sm">read_insights</span>;
                        clicks and reach then stay a dash. To get them, add the
                        permission to the Meta app and to
                        <span class="font-mono text-sm">FACEBOOK_SCOPES</span>,
                        restart, and connect the Page again on
                        <TextLink :href="channelsPage()">Channels</TextLink>.
                        The Page keeps publishing while you do this. The README
                        has each step under "Getting reach and clicks".
                    </template>
                    <template v-else>
                        Without it a channel still publishes, and clicks and
                        reach stay a dash. An owner of this workspace can add
                        it.
                    </template>
                </li>
            </ul>
        </section>
    </div>
</template>
