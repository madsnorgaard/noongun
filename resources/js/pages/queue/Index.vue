<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import PostNumbersLine from '@/components/insights/PostNumbersLine.vue';
import NextFiring from '@/components/posts/NextFiring.vue';
import PostStateBadge from '@/components/posts/PostStateBadge.vue';
import PostTexts from '@/components/posts/PostTexts.vue';
import ProposalProgress from '@/components/posts/ProposalProgress.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { usePostActions } from '@/composables/usePostActions';
import {
    formatDateTime,
    problemList,
    shortText,
    useWorkspaceTimezone,
} from '@/lib/posts';
import { approve, create, reject, show, unschedule } from '@/routes/posts';
import { create as propose } from '@/routes/proposals';
import { approve as approveSelection, index } from '@/routes/queue';
import type { PostInsights, Reading } from '@/types/insights';
import type { Post } from '@/types/posts';
import type { QueueDrafting } from '@/types/proposals';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Queue',
                href: index(),
            },
        ],
    },
});

const props = defineProps<{
    view: 'working' | 'history';
    sections: { drafts: Post[]; upcoming: Post[]; failed: Post[] } | null;
    /** How many posts each section has in all. A section may show fewer. */
    totals: { drafts: number; upcoming: number; failed: number } | null;
    history: {
        posts: Post[];
        /** How the published posts on this page did, keyed by post id. */
        insights: Record<number, PostInsights>;
        reading: Reading;
        previous: string | null;
        next: string | null;
    } | null;
    can: { manage: boolean };
    /** The latest week proposal and whether the viewer may start one. */
    drafting: QueueDrafting | null;
}>();

const timezone = useWorkspaceTimezone();
const { busy, errors, run } = usePostActions();

// Proposing a week is the red action only while there is no draft to
// approve and none on its way: then it is what moves the week forward.
const proposeLeads = computed(
    () =>
        props.sections !== null &&
        props.sections.drafts.length === 0 &&
        !(props.drafting?.proposal?.active ?? false),
);

// Where a refusal is shown. The server keys what it refused for one post of
// a selection as "posts.{id}"; everything else belongs to the request. The
// reasons go to the row of the post they are about when that row is on the
// page, and to the list above the sections otherwise, so none is lost.
const actedOn = ref<number | null>(null);

const visibleIds = computed(() =>
    props.sections
        ? [
              ...props.sections.drafts,
              ...props.sections.upcoming,
              ...props.sections.failed,
          ].map((post) => post.id)
        : [],
);

function postOf(key: string): number | null {
    const match = /^posts\.(\d+)$/.exec(key);

    return match ? Number(match[1]) : actedOn.value;
}

function refusedAt(post: Post): string[] {
    return Object.entries(errors.value)
        .filter(([key]) => postOf(key) === post.id)
        .map(([, message]) => message);
}

const refused = computed(() =>
    Object.entries(errors.value)
        .filter(([key]) => {
            const id = postOf(key);

            return id === null || !visibleIds.value.includes(id);
        })
        .map(([, message]) => message),
);

function act(post: Post, name: string, url: string): void {
    actedOn.value = post.id;
    run(`${name}-${post.id}`, 'post', url);
}

function sectionCount(name: 'drafts' | 'upcoming' | 'failed'): string {
    const shown = props.sections?.[name].length ?? 0;
    const total = props.totals?.[name] ?? shown;

    return total > shown ? `first ${shown} of ${total}` : `${shown}`;
}

const isEmpty = computed(
    () =>
        props.sections !== null &&
        props.sections.drafts.length === 0 &&
        props.sections.upcoming.length === 0 &&
        props.sections.failed.length === 0,
);

// The drafts the user has ticked, each with the fingerprint of the content
// that was on screen when it was ticked. Nothing is ever ticked for them.
const selected = ref<Record<number, string>>({});
const selectedCount = computed(() => Object.keys(selected.value).length);

watch(
    () => props.sections?.drafts,
    (drafts) => {
        const kept: Record<number, string> = {};

        for (const draft of drafts ?? []) {
            if (selected.value[draft.id] === draft.version && isReady(draft)) {
                kept[draft.id] = draft.version;
            }
        }

        selected.value = kept;
    },
);

function isReady(post: Post): boolean {
    return problemList(post).length === 0;
}

function toggle(post: Post, ticked: boolean): void {
    const next = { ...selected.value };

    if (ticked) {
        next[post.id] = post.version;
    } else {
        delete next[post.id];
    }

    selected.value = next;
}

function approveOne(post: Post): void {
    actedOn.value = post.id;
    run(`approve-${post.id}`, 'post', approve.url({ post: post.id }), {
        version: post.version,
    });
}

function approveTicked(): void {
    actedOn.value = null;
    run('approve-selected', 'post', approveSelection.url(), {
        posts: Object.entries(selected.value).map(([id, version]) => ({
            id: Number(id),
            version,
        })),
    });
}

function time(post: Post): string | null {
    return post.scheduled_at
        ? formatDateTime(post.scheduled_at, timezone.value)
        : null;
}

// What a failed post says first, in the words of the guide: when it was
// meant to fire and where it did not arrive.
function failedHeadline(post: Post): string {
    const channels = post.variants
        .filter((variant) => !variant.published)
        .map((variant) => variant.channel_name)
        .join(', ');
    const when = time(post);

    if (channels === '') {
        return when ? `${when} was not published` : 'Not published';
    }

    return when
        ? `${when} on ${channels} was not published`
        : `Not published on ${channels}`;
}
</script>

<template>
    <Head title="Queue" />

    <div
        class="flex flex-1 flex-col gap-5.5 px-4 pt-2 pb-12 md:px-8 lg:px-11"
        :class="can.manage && selectedCount > 0 ? 'max-sm:pb-28' : ''"
    >
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="grid gap-1">
                <h1 class="text-title md:text-display">Queue</h1>
                <p class="text-muted-foreground">
                    Nothing is published until someone here approves it. Times
                    in {{ timezone }}.
                </p>
            </div>

            <div v-if="can.manage" class="flex flex-wrap gap-2">
                <Button
                    v-if="drafting?.can_propose"
                    :variant="proposeLeads ? 'signal' : 'outline'"
                    as-child
                >
                    <Link :href="propose()" data-test="propose-week">
                        Propose a week
                    </Link>
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="create()" data-test="new-post">New post</Link>
                </Button>
            </div>
        </header>

        <nav
            class="flex w-fit gap-1.5 rounded-lg bg-accent p-1"
            aria-label="Queue views"
        >
            <Link
                :href="index()"
                class="flex min-h-11 items-center rounded-[10px] px-4 text-base font-semibold"
                :class="
                    view === 'working'
                        ? 'bg-card shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
                :aria-current="view === 'working' ? 'page' : undefined"
            >
                Working list
            </Link>
            <Link
                :href="index({ query: { view: 'history' } })"
                class="flex min-h-11 items-center rounded-[10px] px-4 text-base font-semibold"
                :class="
                    view === 'history'
                        ? 'bg-card shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
                :aria-current="view === 'history' ? 'page' : undefined"
                data-test="queue-history"
            >
                History
            </Link>
        </nav>

        <ul
            v-if="refused.length"
            class="grid gap-1 rounded-lg border-2 border-destructive bg-card px-4 py-3 text-base text-destructive"
            role="alert"
            data-test="queue-errors"
        >
            <li v-for="message in refused" :key="message">{{ message }}</li>
        </ul>

        <template v-if="sections">
            <ProposalProgress
                v-if="drafting?.proposal"
                :proposal="drafting.proposal"
                :can-propose="drafting.can_propose"
            />

            <section
                v-if="isEmpty"
                class="rounded-xl bg-card p-6 shadow-xs"
                data-test="queue-empty"
            >
                <h2 class="text-heading">The queue is empty</h2>
                <p class="mt-1 text-muted-foreground">
                    <template v-if="can.manage">
                        Write a post, approve it and give it a firing time. It
                        goes out at that minute.
                    </template>
                    <template v-else>
                        Nothing is waiting, scheduled or failed.
                    </template>
                </p>
            </section>

            <!-- What failed comes first: it is the only thing on the page
                 that will not sort itself out. -->
            <section
                v-if="sections.failed.length"
                class="grid gap-3"
                aria-labelledby="queue-failed"
            >
                <h2 id="queue-failed" class="text-heading">
                    Needs attention
                    <span
                        class="ml-1 font-mono text-sm font-normal tracking-normal text-muted-foreground"
                    >
                        {{ sectionCount('failed') }}
                    </span>
                </h2>

                <ul class="grid gap-3">
                    <li
                        v-for="post in sections.failed"
                        :key="post.id"
                        class="grid gap-4 rounded-xl border-2 border-destructive bg-card px-4 py-4 sm:px-[22px]"
                        :data-test="`failed-${post.id}`"
                    >
                        <div
                            class="flex flex-wrap items-center gap-x-5 gap-y-3"
                        >
                            <PostStateBadge :state="post.state" />
                            <p class="min-w-0 flex-[1_1_18rem] font-semibold">
                                {{ failedHeadline(post) }}
                            </p>

                            <!-- Sending again can put a second copy
                                 on a channel, so it is not done from a
                                 list: the post page shows the error and
                                 the warning first. -->
                            <Button
                                size="sm"
                                :variant="post.can.retry ? 'default' : 'ghost'"
                                class="max-sm:w-full"
                                as-child
                            >
                                <Link
                                    :href="show({ post: post.id })"
                                    :data-test="`open-failed-${post.id}`"
                                >
                                    {{
                                        post.can.retry
                                            ? 'Open to send again'
                                            : 'Open'
                                    }}
                                </Link>
                            </Button>
                        </div>

                        <PostTexts :post="post" clamp />

                        <ul
                            v-if="!isReady(post)"
                            class="grid gap-0.5 text-base text-destructive"
                        >
                            <li
                                v-for="problem in problemList(post)"
                                :key="problem"
                            >
                                {{ problem }}
                            </li>
                        </ul>
                    </li>
                </ul>
            </section>

            <NextFiring :posts="sections.upcoming" />

            <section
                v-if="sections.drafts.length"
                class="grid gap-3"
                aria-labelledby="queue-drafts"
            >
                <div class="flex min-h-11 items-center justify-between gap-3">
                    <h2 id="queue-drafts" class="text-heading">
                        To approve
                        <span
                            class="ml-1 font-mono text-sm font-normal tracking-normal text-muted-foreground"
                        >
                            {{ sectionCount('drafts') }}
                        </span>
                    </h2>

                    <!-- The one red action of the page. On a phone it stays
                         in reach at the bottom of the screen. -->
                    <div
                        v-if="can.manage && selectedCount > 0"
                        class="max-sm:fixed max-sm:inset-x-0 max-sm:bottom-0 max-sm:z-20 max-sm:border-t max-sm:bg-card max-sm:p-3"
                    >
                        <Button
                            variant="signal"
                            class="max-sm:min-h-[58px] max-sm:w-full max-sm:rounded-2xl max-sm:text-lg"
                            :disabled="busy !== null"
                            data-test="approve-selected"
                            @click="approveTicked"
                        >
                            <Spinner v-if="busy === 'approve-selected'" />
                            Approve {{ selectedCount }} selected
                        </Button>
                    </div>
                </div>

                <ul class="grid gap-3">
                    <li
                        v-for="post in sections.drafts"
                        :key="post.id"
                        class="overflow-hidden rounded-xl bg-card shadow-xs"
                        :data-test="`draft-${post.id}`"
                    >
                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b px-4 py-2.5 sm:px-6"
                        >
                            <label
                                v-if="can.manage"
                                class="-ml-3 flex size-11 shrink-0 items-center justify-center"
                                :class="
                                    !isReady(post) || busy !== null
                                        ? ''
                                        : 'cursor-pointer'
                                "
                            >
                                <input
                                    type="checkbox"
                                    class="size-5 accent-primary disabled:opacity-40"
                                    :checked="post.id in selected"
                                    :disabled="!isReady(post) || busy !== null"
                                    :aria-label="`Select for approval: ${shortText(post)}`"
                                    :data-test="`select-${post.id}`"
                                    @change="
                                        toggle(
                                            post,
                                            ($event.target as HTMLInputElement)
                                                .checked,
                                        )
                                    "
                                />
                            </label>

                            <span
                                v-if="time(post)"
                                class="font-mono text-body font-medium"
                            >
                                {{ time(post) }}
                            </span>
                            <span v-else class="text-muted-foreground">
                                No firing time
                            </span>

                            <span
                                v-if="post.origin"
                                class="rounded-sm bg-secondary px-2.5 py-1 text-sm"
                                :data-test="`generated-${post.id}`"
                            >
                                Drafted from the brief
                                <template v-if="post.origin.pillar">
                                    - {{ post.origin.pillar }}
                                </template>
                            </span>

                            <PostStateBadge
                                :state="post.state"
                                class="ml-auto"
                            />
                        </div>

                        <div class="grid gap-3 px-4 py-4 sm:px-6">
                            <PostTexts :post="post" />

                            <ul
                                v-if="!isReady(post)"
                                class="grid gap-0.5 text-base text-destructive"
                                :data-test="`problems-${post.id}`"
                            >
                                <li
                                    v-for="problem in problemList(post)"
                                    :key="problem"
                                >
                                    {{ problem }}
                                </li>
                            </ul>

                            <ul
                                v-if="refusedAt(post).length"
                                class="grid gap-0.5 text-base text-destructive"
                                role="alert"
                                :data-test="`refused-${post.id}`"
                            >
                                <li
                                    v-for="message in refusedAt(post)"
                                    :key="message"
                                >
                                    {{ message }}
                                </li>
                            </ul>

                            <!-- Approve leads and is the only filled button.
                                 Reject is plain text at the far end, with the
                                 link to the post between the two, so that one
                                 is not pressed for the other. On a phone the
                                 two main buttons share a row and Reject sits
                                 under them. -->
                            <div
                                class="grid grid-cols-2 gap-2.5 sm:flex sm:flex-wrap sm:items-center sm:gap-2"
                            >
                                <Button
                                    v-if="can.manage"
                                    size="sm"
                                    class="max-sm:min-h-[52px] max-sm:whitespace-normal"
                                    :disabled="!isReady(post) || busy !== null"
                                    :data-test="`approve-${post.id}`"
                                    @click="approveOne(post)"
                                >
                                    <Spinner
                                        v-if="busy === `approve-${post.id}`"
                                    />
                                    {{
                                        post.scheduled_at
                                            ? 'Approve and schedule'
                                            : 'Approve'
                                    }}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    class="max-sm:min-h-[52px]"
                                    :class="can.manage ? '' : 'col-span-2'"
                                    as-child
                                >
                                    <Link :href="show({ post: post.id })">
                                        {{ can.manage ? 'Edit' : 'Open' }}
                                    </Link>
                                </Button>
                                <Button
                                    v-if="can.manage"
                                    size="sm"
                                    variant="ghost"
                                    class="col-span-2 text-muted-foreground sm:ml-auto"
                                    :disabled="busy !== null"
                                    :data-test="`reject-${post.id}`"
                                    @click="
                                        act(
                                            post,
                                            'reject',
                                            reject.url({ post: post.id }),
                                        )
                                    "
                                >
                                    Reject
                                </Button>
                            </div>

                            <p class="text-sm text-muted-foreground">
                                <template v-if="time(post)">
                                    Approving it schedules it for that time.
                                </template>
                                <template v-else>
                                    It can be approved now and given a time
                                    later.
                                </template>
                            </p>
                        </div>
                    </li>
                </ul>
            </section>

            <section
                v-if="sections.upcoming.length"
                class="grid gap-3"
                aria-labelledby="queue-upcoming"
            >
                <h2 id="queue-upcoming" class="text-heading">
                    Approved and scheduled
                    <span
                        class="ml-1 font-mono text-sm font-normal tracking-normal text-muted-foreground"
                    >
                        {{ sectionCount('upcoming') }}
                    </span>
                </h2>

                <ul class="grid gap-2.5">
                    <li
                        v-for="post in sections.upcoming"
                        :key="post.id"
                        class="flex flex-wrap items-start gap-x-5 gap-y-3 rounded-lg bg-card px-4 py-3.5 shadow-xs sm:px-[18px]"
                        :data-test="`upcoming-${post.id}`"
                    >
                        <div
                            class="flex w-full shrink-0 flex-wrap items-center gap-x-3 gap-y-1.5 lg:grid lg:w-52 lg:content-start"
                        >
                            <span v-if="time(post)" class="font-mono text-base">
                                {{ time(post) }}
                            </span>
                            <span
                                v-else
                                class="text-base text-muted-foreground"
                            >
                                No firing time yet
                            </span>
                            <PostStateBadge :state="post.state" />
                        </div>

                        <div class="grid min-w-0 flex-[1_1_20rem] gap-2">
                            <PostTexts :post="post" clamp />

                            <ul
                                v-if="!isReady(post)"
                                class="grid gap-0.5 text-base text-destructive"
                            >
                                <li
                                    v-for="problem in problemList(post)"
                                    :key="problem"
                                >
                                    {{ problem }}
                                </li>
                            </ul>

                            <ul
                                v-if="refusedAt(post).length"
                                class="grid gap-0.5 text-base text-destructive"
                                role="alert"
                                :data-test="`refused-${post.id}`"
                            >
                                <li
                                    v-for="message in refusedAt(post)"
                                    :key="message"
                                >
                                    {{ message }}
                                </li>
                            </ul>

                            <p
                                v-if="post.approval"
                                class="text-sm text-muted-foreground"
                            >
                                Approved
                                <template v-if="post.approval.by">
                                    by {{ post.approval.by }}
                                </template>
                                on
                                <span class="font-mono">
                                    {{
                                        formatDateTime(
                                            post.approval.at,
                                            timezone,
                                        )
                                    }}
                                </span>
                            </p>
                        </div>

                        <div
                            class="flex shrink-0 flex-wrap items-start gap-2 max-sm:w-full"
                        >
                            <Button
                                v-if="post.can.unschedule"
                                size="sm"
                                variant="outline"
                                :disabled="busy !== null"
                                :data-test="`unschedule-${post.id}`"
                                @click="
                                    act(
                                        post,
                                        'unschedule',
                                        unschedule.url({ post: post.id }),
                                    )
                                "
                            >
                                Take off schedule
                            </Button>
                            <Button
                                size="sm"
                                :variant="
                                    post.state === 'approved' && can.manage
                                        ? 'default'
                                        : 'ghost'
                                "
                                as-child
                            >
                                <Link :href="show({ post: post.id })">
                                    {{
                                        post.state === 'approved' && can.manage
                                            ? 'Set a firing time'
                                            : 'Open'
                                    }}
                                </Link>
                            </Button>
                        </div>
                    </li>
                </ul>
            </section>
        </template>

        <template v-if="history">
            <section
                v-if="history.posts.length === 0"
                class="rounded-xl bg-card p-6 shadow-xs"
                data-test="history-empty"
            >
                <h2 class="text-heading">Nothing here yet</h2>
                <p class="mt-1 text-muted-foreground">
                    Published and rejected posts are kept here.
                </p>
            </section>

            <ul v-else class="grid gap-2.5">
                <li
                    v-for="post in history.posts"
                    :key="post.id"
                    class="flex flex-wrap items-start gap-x-5 gap-y-3 rounded-lg bg-card px-4 py-3.5 shadow-xs sm:px-[18px]"
                    :data-test="`history-${post.id}`"
                >
                    <div
                        class="flex w-full shrink-0 flex-wrap items-center gap-x-3 gap-y-1.5 lg:grid lg:w-52 lg:content-start"
                    >
                        <span v-if="time(post)" class="font-mono text-base">
                            {{ time(post) }}
                        </span>
                        <PostStateBadge :state="post.state" />
                    </div>

                    <div class="grid min-w-0 flex-[1_1_20rem] gap-2.5">
                        <PostTexts :post="post" clamp />
                        <PostNumbersLine
                            v-if="history.insights[post.id]"
                            :insights="history.insights[post.id]"
                            :reading="history.reading"
                            :data-test="`history-numbers-${post.id}`"
                        />
                    </div>

                    <Button size="sm" variant="ghost" as-child>
                        <Link :href="show({ post: post.id })">Open</Link>
                    </Button>
                </li>
            </ul>

            <nav
                v-if="history.previous || history.next"
                class="flex gap-2"
                aria-label="History pages"
            >
                <Button
                    v-if="history.previous"
                    size="sm"
                    variant="outline"
                    as-child
                >
                    <Link :href="history.previous">Newer</Link>
                </Button>
                <Button
                    v-if="history.next"
                    size="sm"
                    variant="outline"
                    as-child
                >
                    <Link :href="history.next">Older</Link>
                </Button>
            </nav>
        </template>
    </div>
</template>
