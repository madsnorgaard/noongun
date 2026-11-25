<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { usePostActions } from '@/composables/usePostActions';
import { formatDate, formatDateTime, useWorkspaceTimezone } from '@/lib/posts';
import { create, store } from '@/routes/proposals';
import { index as queue } from '@/routes/queue';
import type {
    ProposalRefusal,
    ProposalWeek,
    WeekPlan,
} from '@/types/proposals';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Queue',
                href: queue(),
            },
            {
                title: 'Propose a week',
                href: '#',
            },
        ],
    },
});

const props = defineProps<{
    week: ProposalWeek;
    plan: WeekPlan;
    /** Everything that stands in the way of starting. Empty when it can start. */
    refusals: ProposalRefusal[];
    generator: { provider: string; model: string } | null;
    /** Expected and worst case cost by the price table, or null without a price. */
    cost: { currency: string; expected: string; worst: string } | null;
    limits: {
        posts_per_proposal: number;
        runs_per_day: number;
        runs_used: number;
        max_attempts: number;
    };
    links: Record<'brief' | 'pillars' | 'slots' | 'ideas', string | null>;
}>();

const timezone = useWorkspaceTimezone();
const { busy, errors, run } = usePostActions();

const count = computed(() => props.plan.posts.length);
const canStart = computed(() => props.refusals.length === 0 && count.value > 0);

const weekLabel = computed(
    () =>
        `${formatDate(props.week.start, { weekday: 'short', day: 'numeric', month: 'short' })} to ${formatDate(props.week.end, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })}`,
);

const withoutPillar = computed(
    () => props.plan.posts.filter((post) => post.pillar === null).length,
);

const refused = computed(() => Object.values(errors.value));

function posts(number: number): string {
    return number === 1 ? '1 post' : `${number} posts`;
}

function start(): void {
    if (!canStart.value) {
        return;
    }

    run('propose', 'post', store.url(), {
        week: props.week.start,
        plan: props.plan.fingerprint,
    });
}
</script>

<template>
    <Head title="Propose a week" />

    <div class="flex flex-1 flex-col gap-5.5 px-4 pt-2 pb-12 md:px-8 lg:px-11">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="grid gap-3">
                <Button size="sm" variant="outline" class="w-fit" as-child>
                    <Link :href="queue()" aria-label="Back to the queue">
                        <ChevronLeft />
                        Queue
                    </Link>
                </Button>
                <div class="grid gap-1">
                    <h1 class="text-title md:text-display">Propose a week</h1>
                    <p class="text-muted-foreground" data-test="proposal-week">
                        Posts for {{ weekLabel }}, drafted from your brief.
                        Times in {{ timezone }}.
                    </p>
                </div>
            </div>

            <nav class="flex gap-2" aria-label="Weeks">
                <Button
                    v-if="week.previous"
                    size="sm"
                    variant="outline"
                    as-child
                >
                    <Link
                        :href="create({ query: { week: week.previous } })"
                        data-test="week-previous"
                    >
                        Week before
                    </Link>
                </Button>
                <Button v-if="week.next" size="sm" variant="outline" as-child>
                    <Link
                        :href="create({ query: { week: week.next } })"
                        data-test="week-next"
                    >
                        Week after
                    </Link>
                </Button>
            </nav>
        </header>

        <div
            class="grid items-start gap-5.5 lg:grid-cols-[minmax(0,1fr)_22rem]"
        >
            <div class="grid content-start gap-4">
                <section
                    v-if="refusals.length"
                    class="grid gap-3 rounded-2xl bg-card p-5.5 shadow-xs"
                    data-test="proposal-refusals"
                >
                    <h2 class="text-heading">Before this can start</h2>
                    <ul class="grid divide-y divide-border">
                        <li
                            v-for="refusal in refusals"
                            :key="refusal.reason"
                            class="flex flex-wrap items-center justify-between gap-x-5 gap-y-3 py-3 first:pt-0 last:pb-0"
                            :data-test="`refusal-${refusal.reason}`"
                        >
                            <p class="max-w-[72ch] flex-[1_1_20rem] text-base">
                                {{ refusal.message }}
                            </p>
                            <Button
                                v-if="refusal.href"
                                size="sm"
                                variant="outline"
                                as-child
                            >
                                <Link :href="refusal.href">
                                    {{ refusal.label }}
                                </Link>
                            </Button>
                        </li>
                    </ul>
                </section>

                <section
                    v-if="count > 0"
                    class="grid gap-3 rounded-2xl bg-card p-5.5 shadow-xs"
                    data-test="proposal-plan"
                >
                    <div>
                        <h2 class="text-heading">
                            {{ posts(count) }} would be drafted
                        </h2>
                        <p class="mt-1 text-muted-foreground">
                            One post for each open slot time. Channels that
                            share a time share a post, with a text for each of
                            them.
                        </p>
                    </div>

                    <ul class="grid divide-y divide-border">
                        <li
                            v-for="post in plan.posts"
                            :key="post.at"
                            class="grid gap-x-5 gap-y-2 py-3.5 first:pt-0 last:pb-0 sm:grid-cols-[13rem_minmax(0,1fr)]"
                        >
                            <span class="font-mono text-base">
                                {{ formatDateTime(post.at, timezone) }}
                            </span>
                            <div class="grid gap-2">
                                <div class="flex flex-wrap gap-1.5">
                                    <span
                                        v-for="channel in post.channels"
                                        :key="channel.name"
                                        class="rounded-sm bg-secondary px-2.5 py-1 text-sm"
                                    >
                                        {{ channel.name }}
                                        <span class="text-muted-foreground">
                                            - {{ channel.platform_label }}
                                        </span>
                                    </span>
                                </div>
                                <p class="text-base">
                                    <template v-if="post.pillar">
                                        {{ post.pillar }}
                                    </template>
                                    <span v-else class="text-muted-foreground">
                                        From the brief alone, without a pillar
                                    </span>
                                </p>
                                <p
                                    v-if="post.idea"
                                    class="text-sm text-muted-foreground"
                                >
                                    Idea: {{ post.idea }}
                                </p>
                            </div>
                        </li>
                    </ul>
                </section>

                <section
                    class="grid gap-2 rounded-xl bg-card p-5.5 shadow-xs"
                    data-test="proposal-notes"
                >
                    <h2 class="text-sm text-muted-foreground">
                        How the week was planned
                    </h2>
                    <ul class="grid gap-1.5 text-base">
                        <li
                            v-if="plan.pillars_with_target === 0"
                            data-test="proposal-no-pillars"
                        >
                            No pillar has a weekly target, so every post is
                            drafted from the brief alone.
                            <Link
                                v-if="links.pillars"
                                :href="links.pillars"
                                class="font-semibold underline underline-offset-4"
                            >
                                Add pillars and targets
                            </Link>
                            <template v-if="links.pillars">
                                to spread the week over your themes.
                            </template>
                        </li>
                        <template v-else>
                            <li>
                                Pillars take turns, the largest weekly target
                                first, until each has its target. Posts the week
                                already holds for a pillar count towards it.
                            </li>
                            <li v-if="count > 0 && withoutPillar > 0">
                                {{ posts(withoutPillar) }}
                                {{ withoutPillar === 1 ? 'has' : 'have' }} no
                                pillar, because the weekly targets ask for fewer
                                posts than the week has open times.
                            </li>
                        </template>
                        <li>
                            Each post takes the oldest unused idea of its
                            pillar, then the oldest idea without a pillar.
                        </li>
                        <li v-for="short in plan.short" :key="short.pillar">
                            {{ short.pillar }} gets {{ short.missing }} fewer
                            than its weekly target: the week has no more open
                            times.
                        </li>
                        <li v-if="plan.taken > 0">
                            {{ plan.taken }} slot
                            {{ plan.taken === 1 ? 'time' : 'times' }} already
                            {{ plan.taken === 1 ? 'has' : 'have' }} a post and
                            {{ plan.taken === 1 ? 'is' : 'are' }} left alone.
                        </li>
                        <li v-if="plan.past > 0">
                            {{ plan.past }} slot
                            {{ plan.past === 1 ? 'time has' : 'times have' }}
                            passed.
                        </li>
                        <li v-if="plan.soon > 0">
                            {{ plan.soon }} slot
                            {{ plan.soon === 1 ? 'time is' : 'times are' }}
                            less than {{ plan.lead_minutes }} minutes away and
                            {{ plan.soon === 1 ? 'is' : 'are' }} left out: a
                            draft could not be read and approved before then.
                        </li>
                        <li v-if="plan.unavailable > 0">
                            {{ plan.unavailable }}
                            {{
                                plan.unavailable === 1
                                    ? 'slot belongs'
                                    : 'slots belong'
                            }}
                            to a channel that cannot publish now.
                        </li>
                        <li v-if="plan.left_out > 0">
                            One proposal drafts at most
                            {{ limits.posts_per_proposal }} posts. The
                            {{ plan.left_out }} latest
                            {{ plan.left_out === 1 ? 'time is' : 'times are' }}
                            left for a second proposal.
                        </li>
                    </ul>
                    <p
                        v-if="
                            links.brief ||
                            links.pillars ||
                            links.slots ||
                            links.ideas
                        "
                        class="flex flex-wrap gap-x-5 gap-y-1 pt-1 text-base"
                    >
                        <Link
                            v-if="links.brief"
                            :href="links.brief"
                            class="inline-flex min-h-11 items-center font-semibold underline underline-offset-4"
                            data-test="proposal-link-brief"
                        >
                            Brief
                        </Link>
                        <Link
                            v-if="links.pillars"
                            :href="links.pillars"
                            class="inline-flex min-h-11 items-center font-semibold underline underline-offset-4"
                        >
                            Pillars and targets
                        </Link>
                        <Link
                            v-if="links.slots"
                            :href="links.slots"
                            class="inline-flex min-h-11 items-center font-semibold underline underline-offset-4"
                        >
                            Slots
                        </Link>
                        <Link
                            v-if="links.ideas"
                            :href="links.ideas"
                            class="inline-flex min-h-11 items-center font-semibold underline underline-offset-4"
                        >
                            Ideas
                        </Link>
                    </p>
                </section>
            </div>

            <aside class="grid content-start gap-4">
                <section class="grid gap-4 rounded-xl bg-card p-5.5 shadow-xs">
                    <dl class="grid gap-3.5">
                        <div class="grid gap-0.5">
                            <dt class="text-sm text-muted-foreground">Model</dt>
                            <dd v-if="generator" data-test="proposal-model">
                                <span class="font-mono text-base">
                                    {{ generator.model }}
                                </span>
                                <span class="text-muted-foreground">
                                    - {{ generator.provider }}
                                </span>
                            </dd>
                            <dd v-else class="text-muted-foreground">
                                None yet. See what is missing on the left.
                            </dd>
                        </div>

                        <div v-if="count > 0 && generator" class="grid gap-0.5">
                            <dt class="text-sm text-muted-foreground">
                                Expected cost, about
                            </dt>
                            <dd v-if="cost" data-test="proposal-cost">
                                <span class="font-mono text-base">
                                    {{ cost.currency }} {{ cost.expected }}
                                </span>
                                <span
                                    class="mt-1 block text-sm text-muted-foreground"
                                >
                                    One answer of the usual length for each
                                    post, by the price table of this instance.
                                </span>
                                <span
                                    class="mt-2 block text-sm text-muted-foreground"
                                    data-test="proposal-cost-worst"
                                >
                                    Worst case:
                                    <span class="font-mono">
                                        {{ cost.currency }} {{ cost.worst }}
                                    </span>
                                    if every post needed all
                                    {{ limits.max_attempts }} attempts and every
                                    answer ran to the token limit. It cannot
                                    cost more than that.
                                </span>
                            </dd>
                            <dd v-else class="text-sm text-muted-foreground">
                                No price is set for this model, so there is no
                                estimate.
                            </dd>
                        </div>

                        <div class="grid gap-0.5">
                            <dt class="text-sm text-muted-foreground">
                                Drafts in the last 24 hours
                            </dt>
                            <dd data-test="proposal-usage">
                                <span class="font-mono text-base">
                                    {{ limits.runs_used }} /
                                    {{ limits.runs_per_day }}
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <ul
                        v-if="refused.length"
                        class="grid gap-1 rounded-lg border-2 border-destructive bg-card px-4 py-3 text-base text-destructive"
                        role="alert"
                        data-test="proposal-errors"
                    >
                        <li v-for="message in refused" :key="message">
                            {{ message }}
                        </li>
                    </ul>

                    <!-- Starting the drafts is what moves the week forward,
                         so it is the one red action of this page. -->
                    <Button
                        variant="signal"
                        size="lg"
                        :disabled="!canStart || busy !== null"
                        data-test="start-proposal"
                        @click="start"
                    >
                        <Spinner v-if="busy === 'propose'" />
                        <template v-if="count > 0">
                            Draft {{ posts(count) }}
                        </template>
                        <template v-else>Nothing to draft</template>
                    </Button>

                    <p class="text-sm text-muted-foreground">
                        Each one arrives in the queue as a draft. Nothing is
                        published until you approve it.
                    </p>
                </section>
            </aside>
        </div>
    </div>
</template>
