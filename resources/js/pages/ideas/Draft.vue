<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useWorkspaceTimezone, webAddress } from '@/lib/posts';
import { index as ideas } from '@/routes/ideas';
import { create, store } from '@/routes/ideas/draft';
import type { IdeaDraftPlan, ProposalRefusal } from '@/types/proposals';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Ideas',
                href: ideas(),
            },
            {
                title: 'Draft a post',
                href: '#',
            },
        ],
    },
});

const props = defineProps<{
    plan: IdeaDraftPlan;
    /** Everything that stands in the way of starting. Empty when it can start. */
    refusals: ProposalRefusal[];
    generator: { provider: string; model: string } | null;
    /** Expected and worst case cost by the price table, or null without a price. */
    cost: { currency: string; expected: string; worst: string } | null;
    /** How many approved posts go along for each channel, or null when none do. */
    examples: { per_channel: number; top_days: number | null } | null;
    limits: {
        runs_per_day: number;
        runs_used: number;
        max_attempts: number;
    };
}>();

const timezone = useWorkspaceTimezone();

const ticked = (): number[] =>
    props.plan.channels
        .filter((channel) => channel.chosen)
        .map((channel) => channel.id);

// What is ticked and typed. The server works out the cost and the checks
// for exactly this choice each time it changes, and answers with the plan.
const chosen = ref<number[]>(ticked());
const plannedAt = ref(props.plan.scheduled_at);
const checking = ref(false);
const starting = ref(false);
const errors = ref<Record<string, string>>({});

watch(
    () => props.plan,
    () => {
        chosen.value = ticked();
        plannedAt.value = props.plan.scheduled_at;
    },
);

const inStep = computed(
    () =>
        plannedAt.value === props.plan.scheduled_at &&
        chosen.value.join(',') === ticked().join(','),
);

const channelProblem = computed(
    () => errors.value.channels ?? props.plan.problems.channels.join(' '),
);

const timeProblem = computed(
    () =>
        errors.value.scheduled_at ?? props.plan.problems.scheduled_at.join(' '),
);

const otherErrors = computed(() =>
    Object.entries(errors.value)
        .filter(([field]) => field !== 'channels' && field !== 'scheduled_at')
        .map(([, message]) => message),
);

const canStart = computed(
    () =>
        props.refusals.length === 0 &&
        chosen.value.length > 0 &&
        props.plan.problems.channels.length === 0 &&
        props.plan.problems.scheduled_at.length === 0,
);

/** Asks the server for the plan of what is chosen now. Spends nothing. */
function look(): void {
    errors.value = {};

    router.get(
        create.url({ idea: props.plan.idea.id }),
        {
            // No channel at all is sent as 0, so that it is not taken for
            // "nothing said", which chooses every channel.
            channels: chosen.value.length > 0 ? chosen.value : [0],
            ...(plannedAt.value !== '' ? { at: plannedAt.value } : {}),
        },
        {
            only: ['plan', 'cost', 'examples', 'refusals', 'limits'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (checking.value = true),
            onFinish: () => (checking.value = false),
        },
    );
}

function tick(id: number, event: Event): void {
    const on = (event.target as HTMLInputElement).checked;

    chosen.value = on
        ? [...chosen.value, id].sort((a, b) => a - b)
        : chosen.value.filter((other) => other !== id);

    look();
}

function start(): void {
    if (starting.value || checking.value || !canStart.value) {
        return;
    }

    // The time was typed but the page has not caught up: show the plan
    // for it first, so that what is started is what is shown.
    if (!inStep.value) {
        look();

        return;
    }

    errors.value = {};

    router.post(
        store.url({ idea: props.plan.idea.id }),
        {
            channels: chosen.value,
            scheduled_at: plannedAt.value === '' ? null : plannedAt.value,
            plan: props.plan.fingerprint,
        },
        {
            preserveScroll: true,
            onStart: () => (starting.value = true),
            onError: (refused) => (errors.value = refused),
            onFinish: () => (starting.value = false),
        },
    );
}
</script>

<template>
    <Head title="Draft a post" />

    <div class="flex flex-1 flex-col gap-5.5 px-4 pt-2 pb-12 md:px-8 lg:px-11">
        <header class="grid gap-3">
            <Button size="sm" variant="outline" class="w-fit" as-child>
                <Link :href="ideas()" aria-label="Back to the ideas">
                    <ChevronLeft />
                    Ideas
                </Link>
            </Button>
            <div class="grid gap-1">
                <h1 class="text-title md:text-display">Draft a post</h1>
                <p class="text-muted-foreground">
                    One post from this idea and your brief, with a text for each
                    channel you tick. Times in {{ timezone }}.
                </p>
            </div>
        </header>

        <div
            class="grid items-start gap-5.5 lg:grid-cols-[minmax(0,1fr)_22rem]"
        >
            <div class="grid content-start gap-4">
                <section
                    v-if="refusals.length"
                    class="grid gap-3 rounded-2xl bg-card p-5.5 shadow-xs"
                    data-test="draft-refusals"
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
                    class="grid gap-2 rounded-2xl bg-card p-5.5 shadow-xs"
                    data-test="draft-idea"
                >
                    <p class="text-sm text-muted-foreground">Idea</p>
                    <h2 class="text-heading break-words">
                        {{ plan.idea.title }}
                    </h2>
                    <p
                        v-if="plan.idea.notes"
                        class="max-w-[72ch] text-base break-words whitespace-pre-line"
                    >
                        {{ plan.idea.notes }}
                    </p>
                    <!-- The address is shown as text. It becomes a link only
                         when it is a web address. -->
                    <p v-if="plan.idea.source_url" class="text-sm">
                        <span class="text-muted-foreground">Source:</span>
                        <a
                            v-if="webAddress(plan.idea.source_url)"
                            :href="
                                webAddress(plan.idea.source_url) ?? undefined
                            "
                            target="_blank"
                            rel="noopener noreferrer"
                            class="font-mono text-xs break-all underline underline-offset-4"
                        >
                            {{ plan.idea.source_url }}
                        </a>
                        <span v-else class="font-mono text-xs break-all">
                            {{ plan.idea.source_url }}
                        </span>
                    </p>
                    <p class="text-base" data-test="draft-pillar">
                        <template v-if="plan.idea.pillar">
                            <span class="text-muted-foreground">Pillar:</span>
                            {{ plan.idea.pillar }}
                        </template>
                        <span v-else class="text-muted-foreground">
                            This idea has no pillar, so the post has none.
                        </span>
                    </p>
                    <p
                        v-if="plan.idea.used_in > 0"
                        class="text-base"
                        data-test="draft-idea-used"
                    >
                        This idea is already used in
                        <span class="font-mono">{{ plan.idea.used_in }}</span>
                        {{ plan.idea.used_in === 1 ? 'post' : 'posts' }}.
                    </p>
                </section>

                <fieldset
                    v-if="plan.channels.length"
                    class="grid min-w-0 gap-2 rounded-2xl bg-card p-5.5 shadow-xs"
                >
                    <legend class="sr-only">Channels</legend>
                    <p class="text-sm text-muted-foreground" aria-hidden="true">
                        Channels
                    </p>
                    <div class="grid divide-y divide-border">
                        <label
                            v-for="channel in plan.channels"
                            :key="channel.id"
                            class="-mx-2 flex min-h-11 cursor-pointer items-start gap-3 rounded-md px-2 py-2.5 hover:bg-accent/50"
                        >
                            <input
                                type="checkbox"
                                class="mt-0.5 size-5 shrink-0 accent-primary"
                                :checked="chosen.includes(channel.id)"
                                :disabled="starting"
                                :data-test="`channel-${channel.id}`"
                                @change="tick(channel.id, $event)"
                            />
                            <span>
                                {{ channel.name }}
                                <span class="text-muted-foreground">
                                    - {{ channel.platform_label }}
                                </span>
                            </span>
                        </label>
                    </div>
                    <p
                        v-if="chosen.length > 1"
                        class="text-sm text-muted-foreground"
                    >
                        One post, a text for each of these
                        {{ chosen.length }} channels. One draft, one approval,
                        one planned time.
                    </p>
                    <InputError
                        :message="channelProblem"
                        data-test="draft-channels-error"
                    />
                </fieldset>

                <section class="grid gap-2 rounded-xl bg-card p-5.5 shadow-xs">
                    <Label for="draft_planned_at">
                        Planned time, {{ timezone }}
                    </Label>
                    <Input
                        id="draft_planned_at"
                        v-model="plannedAt"
                        type="datetime-local"
                        class="font-mono sm:max-w-xs"
                        :disabled="starting"
                        data-test="draft-planned-time"
                        @change="look"
                    />
                    <p class="text-sm text-muted-foreground">
                        Leave it empty to decide after approval. A post with a
                        planned time is scheduled the moment it is approved.
                    </p>
                    <InputError
                        :message="timeProblem"
                        data-test="draft-planned-time-error"
                    />
                </section>
            </div>

            <aside class="grid content-start gap-4">
                <section class="grid gap-4 rounded-xl bg-card p-5.5 shadow-xs">
                    <dl class="grid gap-3.5">
                        <div class="grid gap-0.5">
                            <dt class="text-sm text-muted-foreground">Model</dt>
                            <dd v-if="generator" data-test="draft-model">
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

                        <div
                            v-if="generator && chosen.length > 0"
                            class="grid gap-0.5"
                        >
                            <dt class="text-sm text-muted-foreground">
                                Expected cost, about
                            </dt>
                            <dd v-if="cost" data-test="draft-cost">
                                <span class="font-mono text-base">
                                    {{ cost.currency }} {{ cost.expected }}
                                </span>
                                <span
                                    class="mt-1 block text-sm text-muted-foreground"
                                >
                                    One answer of the usual length, by the price
                                    table of this instance.
                                </span>
                                <span
                                    class="mt-2 block text-sm text-muted-foreground"
                                    data-test="draft-cost-worst"
                                >
                                    Worst case:
                                    <span class="font-mono">
                                        {{ cost.currency }} {{ cost.worst }}
                                    </span>
                                    if the post needed all
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

                        <div
                            v-if="examples"
                            class="grid gap-0.5"
                            data-test="draft-examples"
                        >
                            <dt class="text-sm text-muted-foreground">
                                Examples
                            </dt>
                            <dd class="text-sm">
                                {{
                                    examples.top_days !== null
                                        ? `Up to ${examples.per_channel} approved posts for each channel are sent as examples: the most recent, and those of the last ${examples.top_days} days that did best.`
                                        : examples.per_channel === 1
                                          ? 'The last approved post for each channel is sent as an example.'
                                          : `The last ${examples.per_channel} approved posts for each channel are sent as examples.`
                                }}
                            </dd>
                        </div>

                        <div class="grid gap-0.5">
                            <dt class="text-sm text-muted-foreground">
                                Drafts in the last 24 hours
                            </dt>
                            <dd data-test="draft-usage">
                                <span class="font-mono text-base">
                                    {{ limits.runs_used }} /
                                    {{ limits.runs_per_day }}
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <ul
                        v-if="otherErrors.length"
                        class="grid gap-1 rounded-lg border-2 border-destructive bg-card px-4 py-3 text-base text-destructive"
                        role="alert"
                        data-test="draft-errors"
                    >
                        <li v-for="message in otherErrors" :key="message">
                            {{ message }}
                        </li>
                    </ul>

                    <!-- Starting the draft is what this page is for, so it
                         is its one red action. -->
                    <Button
                        variant="signal"
                        size="lg"
                        :disabled="!canStart || checking || starting"
                        data-test="start-idea-draft"
                        @click="start"
                    >
                        <Spinner v-if="starting || checking" />
                        Draft the post
                    </Button>

                    <p class="text-sm text-muted-foreground">
                        It arrives in the queue as a draft. Nothing is spent
                        before you press the button, and nothing is published
                        until you approve the draft.
                    </p>
                </section>
            </aside>
        </div>
    </div>
</template>
