<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import PostStateBadge from '@/components/posts/PostStateBadge.vue';
import PostTexts from '@/components/posts/PostTexts.vue';
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
import { approve as approveSelection, index } from '@/routes/queue';
import type { Post } from '@/types/posts';

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
        previous: string | null;
        next: string | null;
    } | null;
    can: { manage: boolean };
}>();

const timezone = useWorkspaceTimezone();
const { busy, errors, run } = usePostActions();

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
</script>

<template>
    <Head title="Queue" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="grid gap-1">
                <h1 class="text-lg font-medium">Queue</h1>
                <p class="text-sm text-muted-foreground">
                    Nothing is published until someone here approves it. Times
                    are in {{ timezone }}.
                </p>
            </div>

            <Button v-if="can.manage" size="sm" as-child>
                <Link :href="create()" data-test="new-post">New post</Link>
            </Button>
        </header>

        <nav class="flex gap-4 border-b text-sm" aria-label="Queue views">
            <Link
                :href="index()"
                class="-mb-px border-b-2 pb-2"
                :class="
                    view === 'working'
                        ? 'border-foreground font-medium'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                :aria-current="view === 'working' ? 'page' : undefined"
            >
                Working list
            </Link>
            <Link
                :href="index({ query: { view: 'history' } })"
                class="-mb-px border-b-2 pb-2"
                :class="
                    view === 'history'
                        ? 'border-foreground font-medium'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                :aria-current="view === 'history' ? 'page' : undefined"
                data-test="queue-history"
            >
                History
            </Link>
        </nav>

        <ul
            v-if="refused.length"
            class="grid gap-1 text-sm text-destructive"
            role="alert"
            data-test="queue-errors"
        >
            <li v-for="message in refused" :key="message">{{ message }}</li>
        </ul>

        <template v-if="sections">
            <section
                v-if="isEmpty"
                class="rounded-md border p-4 text-sm"
                data-test="queue-empty"
            >
                <h2 class="font-medium">The queue is empty</h2>
                <p class="mt-1 text-muted-foreground">
                    <template v-if="can.manage">
                        Write a post, approve it and give it a time. It goes out
                        at that minute.
                    </template>
                    <template v-else>
                        Nothing is waiting, scheduled or failed.
                    </template>
                </p>
            </section>

            <section
                v-if="sections.drafts.length"
                aria-labelledby="queue-drafts"
            >
                <div class="flex min-h-8 items-center justify-between gap-3">
                    <h2 id="queue-drafts" class="text-sm font-medium">
                        Waiting for approval
                        <span class="font-normal text-muted-foreground">
                            {{ sectionCount('drafts') }}
                        </span>
                    </h2>
                    <Button
                        v-if="can.manage && selectedCount > 0"
                        size="sm"
                        :disabled="busy !== null"
                        data-test="approve-selected"
                        @click="approveTicked"
                    >
                        <Spinner v-if="busy === 'approve-selected'" />
                        Approve {{ selectedCount }} selected
                    </Button>
                </div>

                <ul class="mt-2 rounded-md border text-sm">
                    <li
                        v-for="post in sections.drafts"
                        :key="post.id"
                        class="flex flex-wrap gap-3 border-b px-3 py-3 last:border-b-0 sm:flex-nowrap"
                        :data-test="`draft-${post.id}`"
                    >
                        <input
                            v-if="can.manage"
                            type="checkbox"
                            class="mt-1 size-4 shrink-0 accent-primary disabled:opacity-40"
                            :checked="post.id in selected"
                            :disabled="!isReady(post) || busy !== null"
                            :aria-label="`Select for approval: ${shortText(post)}`"
                            :data-test="`select-${post.id}`"
                            @change="
                                toggle(
                                    post,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />

                        <div class="grid min-w-0 flex-1 gap-2">
                            <PostTexts :post="post" />

                            <ul
                                v-if="!isReady(post)"
                                class="grid gap-0.5 text-destructive"
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
                                class="grid gap-0.5 text-destructive"
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

                            <p class="text-xs text-muted-foreground">
                                <template v-if="time(post)">
                                    Planned for {{ time(post) }}. Approving it
                                    schedules it for that time.
                                </template>
                                <template v-else>
                                    No time planned. It can be approved now and
                                    given a time later.
                                </template>
                            </p>
                        </div>

                        <!-- Approve leads and is the only filled button.
                             Reject is plain text at the far end, with the
                             link to the post between the two, so that one
                             is not pressed for the other. -->
                        <div class="flex shrink-0 items-start gap-2">
                            <Button
                                v-if="can.manage"
                                size="sm"
                                :disabled="!isReady(post) || busy !== null"
                                :data-test="`approve-${post.id}`"
                                @click="approveOne(post)"
                            >
                                <Spinner v-if="busy === `approve-${post.id}`" />
                                {{
                                    post.scheduled_at
                                        ? 'Approve and schedule'
                                        : 'Approve'
                                }}
                            </Button>
                            <Button size="sm" variant="outline" as-child>
                                <Link :href="show({ post: post.id })">
                                    {{ can.manage ? 'Edit' : 'Open' }}
                                </Link>
                            </Button>
                            <Button
                                v-if="can.manage"
                                size="sm"
                                variant="ghost"
                                class="ml-4 text-muted-foreground"
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
                    </li>
                </ul>
            </section>

            <section
                v-if="sections.upcoming.length"
                aria-labelledby="queue-upcoming"
            >
                <h2 id="queue-upcoming" class="text-sm font-medium">
                    Approved and scheduled
                    <span class="font-normal text-muted-foreground">
                        {{ sectionCount('upcoming') }}
                    </span>
                </h2>

                <ul class="mt-2 rounded-md border text-sm">
                    <li
                        v-for="post in sections.upcoming"
                        :key="post.id"
                        class="flex flex-wrap gap-x-4 gap-y-2 border-b px-3 py-3 last:border-b-0 sm:flex-nowrap"
                        :data-test="`upcoming-${post.id}`"
                    >
                        <div class="grid w-44 shrink-0 content-start gap-1">
                            <span class="tabular-nums">
                                {{ time(post) ?? 'No time yet' }}
                            </span>
                            <PostStateBadge :state="post.state" />
                        </div>

                        <div class="grid min-w-0 flex-1 gap-2">
                            <PostTexts :post="post" clamp />

                            <ul
                                v-if="!isReady(post)"
                                class="grid gap-0.5 text-destructive"
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
                                class="grid gap-0.5 text-destructive"
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
                                class="text-xs text-muted-foreground"
                            >
                                Approved
                                <template v-if="post.approval.by">
                                    by {{ post.approval.by }}
                                </template>
                                on
                                {{ formatDateTime(post.approval.at, timezone) }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-start gap-2">
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
                                            ? 'Set a time'
                                            : 'Open'
                                    }}
                                </Link>
                            </Button>
                        </div>
                    </li>
                </ul>
            </section>

            <section
                v-if="sections.failed.length"
                aria-labelledby="queue-failed"
            >
                <h2 id="queue-failed" class="text-sm font-medium">
                    Needs attention
                    <span class="font-normal text-muted-foreground">
                        {{ sectionCount('failed') }}
                    </span>
                </h2>

                <ul class="mt-2 rounded-md border text-sm">
                    <li
                        v-for="post in sections.failed"
                        :key="post.id"
                        class="flex flex-wrap gap-x-4 gap-y-2 border-b px-3 py-3 last:border-b-0 sm:flex-nowrap"
                        :data-test="`failed-${post.id}`"
                    >
                        <div class="grid w-44 shrink-0 content-start gap-1">
                            <span class="tabular-nums">{{ time(post) }}</span>
                            <PostStateBadge :state="post.state" />
                        </div>

                        <div class="grid min-w-0 flex-1 gap-2">
                            <PostTexts :post="post" clamp />

                            <ul
                                v-if="!isReady(post)"
                                class="grid gap-0.5 text-destructive"
                            >
                                <li
                                    v-for="problem in problemList(post)"
                                    :key="problem"
                                >
                                    {{ problem }}
                                </li>
                            </ul>
                        </div>

                        <div class="flex shrink-0 items-start gap-2">
                            <!-- Sending again can put a second copy
                                 on a channel, so it is not done from a
                                 list: the post page shows the error and
                                 the warning first. -->
                            <Button
                                size="sm"
                                :variant="post.can.retry ? 'outline' : 'ghost'"
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
                    </li>
                </ul>
            </section>
        </template>

        <template v-if="history">
            <section
                v-if="history.posts.length === 0"
                class="rounded-md border p-4 text-sm"
                data-test="history-empty"
            >
                <h2 class="font-medium">Nothing here yet</h2>
                <p class="mt-1 text-muted-foreground">
                    Published and rejected posts are kept here.
                </p>
            </section>

            <ul v-else class="rounded-md border text-sm">
                <li
                    v-for="post in history.posts"
                    :key="post.id"
                    class="flex flex-wrap gap-x-4 gap-y-2 border-b px-3 py-3 last:border-b-0 sm:flex-nowrap"
                    :data-test="`history-${post.id}`"
                >
                    <div class="grid w-44 shrink-0 content-start gap-1">
                        <span v-if="time(post)" class="tabular-nums">
                            {{ time(post) }}
                        </span>
                        <PostStateBadge :state="post.state" />
                    </div>

                    <div class="min-w-0 flex-1">
                        <PostTexts :post="post" clamp />
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
