<script setup lang="ts">
import { Link, router, usePoll } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatDateTime, useWorkspaceTimezone } from '@/lib/posts';
import { show } from '@/routes/posts';
import { create, stop as stopWaiting } from '@/routes/proposals';
import type { ProposalProgress, ProposalRun } from '@/types/proposals';

/**
 * The drafts of the latest "Propose a week" while they are drafted and for
 * a while after. It asks the server for the progress alone every few
 * seconds, only while a draft is waiting or being drafted, and loads the
 * lists of the queue again each time one is settled.
 */
const props = defineProps<{
    proposal: ProposalProgress;
    /** Whether the viewer may propose a week, and stop one that hangs. */
    canPropose: boolean;
}>();

const POLL_MILLISECONDS = 5000;

const timezone = useWorkspaceTimezone();

const { start, stop } = usePoll(
    POLL_MILLISECONDS,
    { only: ['drafting'] },
    { autoStart: false },
);

const settled = computed(
    () => props.proposal.counts.succeeded + props.proposal.counts.failed,
);

onMounted(() => {
    if (props.proposal.active) {
        start();
    }
});

watch(
    () => props.proposal.active,
    (active) => (active ? start() : stop()),
);

// A draft arrived or gave up: show the new draft in the lists.
watch(settled, () => router.reload({ only: ['sections', 'totals'] }));

const total = computed(() => props.proposal.runs.length);

const summary = computed(() => {
    const { succeeded, failed } = props.proposal.counts;
    const parts = [`${succeeded} of ${total.value} drafted`];

    if (failed > 0) {
        parts.push(`${failed} failed`);
    }

    return parts.join(', ');
});

const weekLabel = computed(() =>
    props.proposal.week
        ? formatDate(props.proposal.week, { day: 'numeric', month: 'long' })
        : '',
);

const open = computed(
    () => props.proposal.counts.pending + props.proposal.counts.running,
);

// Stopping asks first: a draft that is with the provider right now is lost.
const confirming = ref(false);
const stopping = ref(false);

function stopNow(): void {
    if (stopping.value) {
        return;
    }

    router.post(
        stopWaiting.url(),
        {},
        {
            preserveScroll: true,
            onStart: () => (stopping.value = true),
            onFinish: () => {
                stopping.value = false;
                confirming.value = false;
            },
        },
    );
}

function word(run: ProposalRun): string {
    switch (run.status) {
        case 'pending':
            return run.attempt > 1 ? 'Waiting to try again' : 'Waiting';
        case 'running':
            return 'Being drafted';
        case 'succeeded':
            return 'Drafted';
        default:
            return 'Failed';
    }
}

function dot(run: ProposalRun): string {
    switch (run.status) {
        case 'running':
            return 'rounded-full bg-brass motion-safe:animate-pulse';
        case 'succeeded':
            return 'rounded-full bg-success-fill';
        case 'failed':
            return 'bg-destructive-fill';
        default:
            return 'rounded-full bg-idle';
    }
}
</script>

<template>
    <section
        class="grid gap-3 rounded-xl bg-card p-5.5 shadow-xs"
        aria-labelledby="queue-proposal"
        data-test="proposal-progress"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-x-5">
            <h2 id="queue-proposal" class="text-heading">
                <template v-if="proposal.active">Drafting</template>
                <template v-else>Drafted</template>
                the week of {{ weekLabel }}
            </h2>
            <p
                class="font-mono text-sm text-muted-foreground"
                role="status"
                data-test="proposal-summary"
            >
                {{ summary }}
            </p>
        </div>

        <!-- Advice, not an error: nothing has failed yet. -->
        <div
            v-if="proposal.active && proposal.waiting_long"
            class="flex flex-wrap items-center justify-between gap-x-5 gap-y-3 rounded-lg bg-warning-surface px-4 py-3"
            role="status"
            data-test="proposal-waiting-long"
        >
            <p class="flex-[1_1_18rem] text-base">
                <span
                    class="mr-2 rounded-sm bg-warning-fill px-2 py-0.5 text-xs font-bold text-white"
                >
                    Check
                </span>
                Waiting longer than expected. Check that the queue is running.
            </p>
            <Button
                v-if="canPropose"
                size="sm"
                variant="outline"
                data-test="stop-waiting"
                @click="confirming = true"
            >
                Stop waiting
            </Button>
        </div>

        <ul class="grid divide-y divide-border">
            <li
                v-for="run in proposal.runs"
                :key="run.id"
                class="grid gap-x-5 gap-y-1.5 py-3 first:pt-0 last:pb-0 sm:grid-cols-[13rem_minmax(0,1fr)_auto]"
                :data-test="`run-${run.id}`"
            >
                <span class="font-mono text-base">
                    {{
                        run.planned_at
                            ? formatDateTime(run.planned_at, timezone)
                            : 'No planned time'
                    }}
                </span>

                <div class="grid min-w-0 gap-1">
                    <p class="text-base">
                        {{ run.channels.join(', ') }}
                        <span v-if="run.pillar" class="text-muted-foreground">
                            - {{ run.pillar }}
                        </span>
                    </p>
                    <p
                        v-if="run.status === 'failed' && run.failure_reason"
                        class="max-w-[72ch] text-base break-words text-destructive"
                        :data-test="`run-reason-${run.id}`"
                    >
                        {{ run.failure_reason }}
                    </p>
                    <p
                        v-else-if="run.earlier_failure"
                        class="max-w-[72ch] text-sm break-words text-muted-foreground"
                    >
                        Attempt {{ run.attempt }} of
                        {{ proposal.max_attempts }}. The one before failed:
                        {{ run.earlier_failure }}
                    </p>
                </div>

                <div class="flex items-center gap-3 sm:justify-end">
                    <span
                        class="inline-flex items-center gap-2 text-[0.875rem] font-semibold"
                        :class="
                            run.status === 'failed' ? 'text-destructive' : ''
                        "
                    >
                        <span
                            class="size-2.5 shrink-0"
                            :class="dot(run)"
                            aria-hidden="true"
                        ></span>
                        {{ word(run) }}
                    </span>
                    <Link
                        v-if="run.post_id"
                        :href="show({ post: run.post_id })"
                        class="inline-flex min-h-11 items-center text-base font-semibold underline underline-offset-4"
                    >
                        Open
                    </Link>
                </div>
            </li>
        </ul>

        <p class="text-sm text-muted-foreground">
            <template v-if="proposal.active">
                Each draft appears under "To approve" when it is drafted. You
                can leave this page: the work goes on.
            </template>
            <template v-else-if="proposal.counts.failed > 0">
                The posts that were drafted wait under "To approve". The times
                that failed are still open.
                <Link
                    v-if="canPropose"
                    :href="create({ query: { week: proposal.week } })"
                    class="font-semibold text-foreground underline underline-offset-4"
                >
                    Propose the week again
                </Link>
                <template v-if="canPropose"> to fill them.</template>
            </template>
            <template v-else>
                Every draft waits under "To approve". Nothing is published until
                you approve it.
            </template>
        </p>
    </section>

    <Dialog
        :open="confirming"
        @update:open="(isOpen: boolean) => !isOpen && (confirming = false)"
    >
        <DialogContent>
            <DialogHeader class="space-y-3">
                <DialogTitle>Stop waiting for these drafts?</DialogTitle>
                <DialogDescription class="text-base">
                    {{ open === 1 ? '1 draft is' : `${open} drafts are` }}
                    marked as failed, and
                    {{ open === 1 ? 'its time is' : 'their times are' }} open
                    again, so you can propose the week once more. Nothing new is
                    asked of the provider. A draft that is with the provider at
                    this moment is discarded, and may still be charged for.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    @click="confirming = false"
                >
                    Keep waiting
                </Button>
                <Button
                    type="button"
                    :disabled="stopping"
                    data-test="confirm-stop-waiting"
                    @click="stopNow"
                >
                    <Spinner v-if="stopping" />
                    Stop waiting
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
