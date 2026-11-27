<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import MetricList from '@/components/insights/MetricList.vue';
import PeriodCounts from '@/components/insights/PeriodCounts.vue';
import TextLink from '@/components/TextLink.vue';
import { computed } from 'vue';
import { formatNumber } from '@/lib/insights';
import { dashboard } from '@/routes';
import { edit as brief } from '@/routes/brief';
import { index as calendar } from '@/routes/calendar';
import { index as channels } from '@/routes/channels';
import { index as insightsPage } from '@/routes/insights';
import { edit as draftingSettings } from '@/routes/llm-key';
import { show as showPost } from '@/routes/posts';
import { create as proposeWeek } from '@/routes/proposals';
import { index as queue } from '@/routes/queue';
import { index as slots } from '@/routes/slots';
import type { DashboardInsights } from '@/types/insights';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

type Workspace = { id: number; name: string; timezone: string; locale: string };

type SetupKey = 'channel' | 'brief' | 'slots' | 'key' | 'propose';

const props = defineProps<{
    /** What is left before a week can be proposed. Null when all is done. */
    setup: { key: SetupKey; done: boolean }[] | null;
    /** What the last week added up to. Null when nothing of it is measured. */
    insights: DashboardInsights | null;
    can: { propose: boolean };
}>();

const setupSteps = computed(() =>
    (props.setup ?? []).map((step) => ({
        ...step,
        ...{
            channel: { title: 'Connect a channel', href: channels() },
            brief: { title: 'Write the brief', href: brief() },
            slots: { title: 'Set the times you prefer to post', href: slots() },
            key: {
                title: 'Make drafting available with an API key',
                href: draftingSettings(),
            },
            // Only owners and editors can propose a week.
            propose: {
                title: 'Propose a week of drafts',
                href: props.can.propose ? proposeWeek() : null,
            },
        }[step.key],
    })),
);

const page = usePage();
const workspace = computed(() => page.props.workspace as Workspace | null);

const localTime = computed(() => {
    if (!workspace.value) {
        return null;
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'full',
        timeStyle: 'short',
        timeZone: workspace.value.timezone,
    }).format(new Date());
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-5.5 px-4 pt-2 pb-12 md:px-8 lg:px-11">
        <section v-if="workspace" class="grid gap-1">
            <h1 class="text-title md:text-display">{{ workspace.name }}</h1>
            <p class="text-muted-foreground">
                {{ workspace.timezone }} - {{ localTime }}
            </p>
        </section>

        <section
            v-if="setup"
            class="rounded-xl bg-card p-5.5 shadow-xs"
            data-test="setup"
        >
            <h2 class="text-heading">Before the first week</h2>
            <p class="mt-1 max-w-[72ch] text-muted-foreground">
                What a week of drafts needs, in order. This list goes away when
                all of it is done.
            </p>
            <ul class="mt-3 grid divide-y divide-border">
                <li
                    v-for="step in setupSteps"
                    :key="step.key"
                    class="flex min-h-11 items-center gap-3 py-1.5"
                    :data-test="`setup-${step.key}`"
                >
                    <span
                        class="w-[4.5rem] shrink-0 rounded-sm px-2 py-0.5 text-center text-xs font-bold"
                        :class="
                            step.done
                                ? 'bg-success-fill text-white'
                                : 'bg-secondary text-muted-foreground'
                        "
                    >
                        {{ step.done ? 'Done' : 'Not yet' }}
                    </span>
                    <TextLink v-if="step.href" :href="step.href">
                        {{ step.title }}
                    </TextLink>
                    <span v-else>{{ step.title }}</span>
                </li>
            </ul>
        </section>

        <section
            v-if="insights"
            class="grid gap-2 rounded-xl bg-card p-5.5 shadow-xs"
            data-test="dashboard-insights"
        >
            <h2 class="text-heading">Last {{ insights.days }} days</h2>
            <PeriodCounts :totals="insights" />
            <MetricList :numbers="insights.numbers" :score="insights.score" />
            <p v-if="insights.best" class="max-w-[72ch]">
                Best post:
                <TextLink
                    :href="showPost({ post: insights.best.post_id })"
                    data-test="dashboard-best-post"
                    >{{
                        insights.best.excerpt || 'a post without text'
                    }}</TextLink
                >
                <template v-if="insights.best.score !== null">
                    with a score of
                    <span class="font-mono">{{
                        formatNumber(insights.best.score, 2)
                    }}</span
                    >.
                </template>
            </p>
            <p>
                <TextLink
                    :href="insightsPage({ query: { period: insights.days } })"
                    data-test="dashboard-insights-link"
                >
                    See pillars and channels in Insights
                </TextLink>
            </p>
        </section>

        <section class="rounded-xl bg-card p-5.5 shadow-xs">
            <h2 class="text-heading">Queue</h2>
            <p class="mt-1 max-w-[72ch] text-muted-foreground">
                Drafts that wait for approval, scheduled posts and anything that
                failed are in the <TextLink :href="queue()">queue</TextLink>.
                The <TextLink :href="calendar()">calendar</TextLink> shows the
                same posts by day.
                <template v-if="can.propose">
                    To fill a week with drafts from your brief,
                    <TextLink :href="proposeWeek()" data-test="propose-week"
                        >propose a week</TextLink
                    >.
                </template>
            </p>
        </section>
    </div>
</template>
