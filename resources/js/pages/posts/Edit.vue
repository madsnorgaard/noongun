<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ChevronLeft } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PostStateBadge from '@/components/posts/PostStateBadge.vue';
import PostTexts from '@/components/posts/PostTexts.vue';
import VariantColumn from '@/components/posts/VariantColumn.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { usePostActions } from '@/composables/usePostActions';
import { formatDateTime, problemList, useWorkspaceTimezone } from '@/lib/posts';
import { index as channelsIndex } from '@/routes/channels';
import {
    approve,
    create,
    destroy,
    reject,
    reopen,
    retry,
    schedule,
    store,
    unschedule,
    update,
} from '@/routes/posts';
import { index as queue } from '@/routes/queue';
import type { ChannelOption, Post } from '@/types/posts';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Queue',
                href: queue(),
            },
            {
                title: 'Post',
                href: '#',
            },
        ],
    },
});

const props = defineProps<{
    post: Post | null;
    channels: ChannelOption[];
    /** A planned time to start a new post with, on the workspace clock. */
    planned: string | null;
}>();

type VariantFields = { channel_id: number; body: string; link: string };

const timezone = useWorkspaceTimezone();
const { busy, errors: refused, run } = usePostActions();

const post = computed(() => props.post);
const isNew = computed(() => props.post === null);
const editable = computed(() => props.post === null || props.post.can.update);

// Variants that are already on their platform stay as they are, also when
// the rest of the post is a draft again.
const lockedVariants = computed(() =>
    (props.post?.variants ?? []).filter((variant) => variant.published),
);
const lockedChannelIds = computed(() =>
    lockedVariants.value.map((variant) => variant.channel_id),
);
const usedChannelIds = computed(() =>
    (props.post?.variants ?? []).map((variant) => variant.channel_id),
);

const offered = computed(() =>
    props.channels.filter(
        (channel) => !lockedChannelIds.value.includes(channel.id),
    ),
);

function selectable(channel: ChannelOption): boolean {
    return channel.can_publish || usedChannelIds.value.includes(channel.id);
}

function fieldsFromServer(): {
    scheduled_at: string;
    variants: VariantFields[];
} {
    if (props.post === null) {
        const usable = props.channels.filter((channel) => channel.can_publish);

        return {
            scheduled_at: props.planned ?? '',
            // With a single channel there is nothing to choose.
            variants:
                usable.length === 1
                    ? [{ channel_id: usable[0].id, body: '', link: '' }]
                    : [],
        };
    }

    return {
        scheduled_at: props.post.scheduled_local ?? '',
        variants: props.post.variants
            .filter((variant) => !variant.published)
            .map((variant) => ({
                channel_id: variant.channel_id,
                body: variant.body,
                link: variant.link ?? '',
            })),
    };
}

const form = useForm(fieldsFromServer());

// What the server holds changed (saved, approved, edited by someone else):
// show that. A refused save changes nothing there, so typing is kept.
const serverKey = computed(() =>
    props.post
        ? `${props.post.id}:${props.post.state}:${props.post.version}`
        : 'new',
);

const scheduleAt = ref(props.post?.scheduled_local ?? '');

watch(serverKey, () => {
    form.defaults(fieldsFromServer());
    form.reset();
    form.clearErrors();
    scheduleAt.value = props.post?.scheduled_local ?? '';
});

function channel(id: number): ChannelOption | undefined {
    return props.channels.find((candidate) => candidate.id === id);
}

function isChosen(id: number): boolean {
    return form.variants.some((variant) => variant.channel_id === id);
}

function toggleChannel(id: number, chosen: boolean): void {
    if (!chosen) {
        form.variants = form.variants.filter(
            (variant) => variant.channel_id !== id,
        );

        return;
    }

    if (!isChosen(id)) {
        // A further channel starts from the first text, to be adjusted.
        const first = form.variants[0];

        form.variants.push({
            channel_id: id,
            body: first?.body ?? '',
            link: first?.link ?? '',
        });
    }
}

function fieldError(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}

// One column per chosen channel, in the order the channels were chosen.
const columns = computed(() =>
    form.variants.flatMap((variant, position) => {
        const option = channel(variant.channel_id);

        return option ? [{ variant, position, option }] : [];
    }),
);

function othersFor(
    channelId: number,
): { channel_id: number; name: string; body: string }[] {
    return columns.value
        .filter((column) => column.variant.channel_id !== channelId)
        .map((column) => ({
            channel_id: column.variant.channel_id,
            name: column.option.display_name,
            body: column.variant.body,
        }));
}

// With more than one text the columns take the width of the page, and the
// status and the actions follow below them until the screen is very wide.
const sideBySide = computed(
    () =>
        (editable.value
            ? form.variants.length
            : (props.post?.variants.length ?? 0)) > 1,
);

function save(): void {
    if (!editable.value) {
        return;
    }

    if (props.post) {
        form.put(update.url({ post: props.post.id }), { preserveScroll: true });
    } else {
        form.post(store.url());
    }
}

const problems = computed(() => (props.post ? problemList(props.post) : []));

// A refusal about the time is shown at the time field. Every other refusal,
// and the one about the time when the page has no time field, goes in the
// list under the status, so that no refusal is ever silent.
const hasTimeField = computed(
    () => editable.value || (props.post?.can.schedule ?? false),
);
const refusedMessages = computed(() =>
    Object.entries(refused.value)
        .filter(([key]) => !(key === 'scheduled_at' && hasTimeField.value))
        .map(([, message]) => message),
);

type Confirmation = 'delete' | 'reopen' | 'publish-now' | 'retry';

const confirming = ref<Confirmation | null>(null);
// What the dialog shows. Kept while it closes, so its text does not change
// on the way out.
const dialog = ref<Confirmation>('reopen');
// Sending again needs this ticked: the user has looked at the channel.
const checkedChannel = ref(false);

watch(confirming, (kind) => {
    if (kind !== null) {
        dialog.value = kind;
        checkedChannel.value = false;
    }
});

// A refusal is shown on the page, so the dialog must not stay in front of it.
watch(refused, (messages) => {
    if (Object.keys(messages).length > 0) {
        confirming.value = null;
    }
});

// The channels a publish or a retry would send to: every one that does not
// have the post yet.
const pending = computed(() =>
    (props.post?.variants ?? []).filter((variant) => !variant.published),
);
const pendingNames = computed(() =>
    pending.value.map((variant) => variant.channel_name).join(', '),
);

function act(
    key: string,
    url: string,
    data: Record<string, string | boolean> = {},
): void {
    run(key, 'post', url, data, () => (confirming.value = null));
}

function approveNow(): void {
    if (props.post) {
        act('approve', approve.url({ post: props.post.id }), {
            version: props.post.version,
        });
    }
}

function requestReopen(): void {
    if (!props.post) {
        return;
    }

    if (props.post.state === 'rejected') {
        act('reopen', reopen.url({ post: props.post.id }));
    } else {
        confirming.value = 'reopen';
    }
}

function confirm(): void {
    if (!props.post) {
        return;
    }

    if (confirming.value === 'delete') {
        run('delete', 'delete', destroy.url({ post: props.post.id }));
    } else if (confirming.value === 'reopen') {
        act('reopen', reopen.url({ post: props.post.id }));
    } else if (confirming.value === 'publish-now') {
        act('publish-now', schedule.url({ post: props.post.id }), {
            now: true,
        });
    } else if (confirming.value === 'retry' && checkedChannel.value) {
        act('retry', retry.url({ post: props.post.id }));
    }
}

// Leaving with text that is not saved asks first. Saving and the state
// changes are not GET requests and pass.
const unsaved = computed(() => editable.value && form.isDirty);

const stopGuard = router.on('before', (event) => {
    if (!unsaved.value || event.detail.visit.method !== 'get') {
        return;
    }

    return window.confirm(
        'This post has changes that are not saved. Leave without saving them?',
    );
});

function warnOnUnload(event: BeforeUnloadEvent): void {
    if (unsaved.value) {
        event.preventDefault();
    }
}

onMounted(() => window.addEventListener('beforeunload', warnOnUnload));

onBeforeUnmount(() => {
    stopGuard();
    window.removeEventListener('beforeunload', warnOnUnload);
});

const status = computed(() => {
    const current = props.post;

    if (!current) {
        return 'A new draft. Nothing is published until it is approved.';
    }

    const when = current.scheduled_at
        ? formatDateTime(current.scheduled_at, timezone.value)
        : null;

    switch (current.state) {
        case 'draft':
            return 'A draft. Nothing is published until it is approved.';
        case 'approved':
            return 'Approved, but not on the schedule. It is not sent until it has a firing time.';
        case 'scheduled':
            return `Approved and scheduled. It fires ${when}.`;
        case 'publishing':
            return 'It is being published now.';
        case 'published':
            return 'Published on every channel.';
        case 'failed':
            return 'It was not published on every channel. Each channel says why.';
        case 'rejected':
            return 'Rejected. It is kept for reference and can be returned to draft.';
        default:
            return '';
    }
});

const locked = computed(() => {
    switch (props.post?.state) {
        case 'approved':
        case 'scheduled':
            return 'The text is locked, because what was approved is exactly what is published. To change it, return the post to draft. That removes the approval, and the post must be approved again.';
        case 'failed':
            return 'The text is locked. You can send it again as it is, or return it to draft to change it. A draft must be approved again.';
        case 'rejected':
            return 'Return the post to draft to work on it again.';
        default:
            return null;
    }
});
</script>

<template>
    <Head :title="isNew ? 'New post' : 'Post'" />

    <form
        class="flex flex-1 flex-col gap-5.5 px-4 pt-2 pb-12 md:px-8 lg:px-11"
        @submit.prevent="save"
    >
        <header class="flex flex-wrap items-center justify-between gap-3.5">
            <div class="flex flex-wrap items-center gap-3.5">
                <Button size="sm" variant="outline" as-child>
                    <Link :href="queue()" aria-label="Back to the queue">
                        <ChevronLeft />
                        Queue
                    </Link>
                </Button>
                <h1 class="text-title">
                    {{ isNew ? 'New post' : 'Post' }}
                </h1>
            </div>
            <PostStateBadge v-if="post" :state="post.state" size="large" />
        </header>

        <div
            class="grid items-start gap-5.5"
            :class="
                sideBySide
                    ? '2xl:grid-cols-[minmax(0,1fr)_22rem]'
                    : 'lg:grid-cols-[minmax(0,1fr)_22rem]'
            "
        >
            <div class="grid content-start gap-4">
                <template v-if="editable">
                    <section
                        v-if="channels.length === 0"
                        class="rounded-2xl bg-card p-5.5 shadow-xs"
                        data-test="no-channels"
                    >
                        <h2 class="text-heading">No channel is connected</h2>
                        <p class="mt-1 text-muted-foreground">
                            A post is written for a channel. Connect one first.
                        </p>
                        <Button class="mt-4" variant="outline" as-child>
                            <Link :href="channelsIndex()">Go to Channels</Link>
                        </Button>
                    </section>

                    <fieldset
                        v-else
                        class="grid min-w-0 gap-2 rounded-2xl bg-card p-5.5 shadow-xs"
                    >
                        <legend class="sr-only">Channels</legend>
                        <p
                            class="text-sm text-muted-foreground"
                            aria-hidden="true"
                        >
                            Channels
                        </p>
                        <div class="grid divide-y divide-border">
                            <label
                                v-for="option in offered"
                                :key="option.id"
                                class="-mx-2 flex min-h-11 items-start gap-3 rounded-md px-2 py-2.5"
                                :class="
                                    selectable(option)
                                        ? 'cursor-pointer hover:bg-accent/50'
                                        : 'text-muted-foreground'
                                "
                            >
                                <input
                                    type="checkbox"
                                    class="mt-0.5 size-5 shrink-0 accent-primary"
                                    :checked="isChosen(option.id)"
                                    :disabled="!selectable(option)"
                                    :data-test="`channel-${option.id}`"
                                    @change="
                                        toggleChannel(
                                            option.id,
                                            ($event.target as HTMLInputElement)
                                                .checked,
                                        )
                                    "
                                />
                                <span class="grid gap-0.5">
                                    <span>
                                        {{ option.display_name }}
                                        <span class="text-muted-foreground">
                                            - {{ option.platform_label }}
                                        </span>
                                    </span>
                                    <span
                                        v-if="option.problem"
                                        class="text-sm"
                                        :class="
                                            isChosen(option.id)
                                                ? 'text-destructive'
                                                : ''
                                        "
                                    >
                                        {{ option.problem }}
                                    </span>
                                </span>
                            </label>
                        </div>
                        <p
                            v-if="offered.length > 1"
                            class="text-sm text-muted-foreground"
                            data-test="channels-hint"
                        >
                            One post, a text for each channel you tick. A
                            channel you add starts from the first text, and each
                            text is then changed by itself.
                        </p>
                        <InputError :message="fieldError('variants')" />
                    </fieldset>

                    <div
                        v-if="columns.length"
                        class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,20rem),1fr))] items-start gap-4"
                        data-test="variant-columns"
                    >
                        <VariantColumn
                            v-for="column in columns"
                            :key="column.variant.channel_id"
                            v-model:body="column.variant.body"
                            v-model:link="column.variant.link"
                            :channel="column.option"
                            :others="othersFor(column.variant.channel_id)"
                            :errors="{
                                body: fieldError(
                                    `variants.${column.position}.body`,
                                ),
                                channel: fieldError(
                                    `variants.${column.position}.channel_id`,
                                ),
                                link: fieldError(
                                    `variants.${column.position}.link`,
                                ),
                            }"
                            :data-test="`text-${column.variant.channel_id}`"
                            @remove="
                                toggleChannel(column.variant.channel_id, false)
                            "
                        />
                    </div>

                    <section
                        v-if="post && lockedVariants.length"
                        class="grid gap-3 rounded-2xl bg-card p-5.5 shadow-xs"
                    >
                        <div>
                            <h2 class="text-heading">Already published</h2>
                            <p class="mt-1 text-muted-foreground">
                                These stay as they are and are not sent again.
                            </p>
                        </div>
                        <PostTexts
                            :post="{ ...post, variants: lockedVariants }"
                            side-by-side
                        />
                    </section>
                </template>

                <template v-else-if="post">
                    <section
                        v-if="locked && post.can.return_to_draft"
                        class="flex flex-wrap items-center justify-between gap-x-5 gap-y-3 rounded-xl bg-card px-5.5 py-4 shadow-xs"
                        data-test="locked-notice"
                    >
                        <p class="max-w-[72ch] flex-[1_1_20rem] text-base">
                            {{ locked }}
                        </p>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="busy !== null"
                            data-test="return-to-draft"
                            @click="requestReopen"
                        >
                            Return to draft
                        </Button>
                    </section>

                    <section class="rounded-2xl bg-card p-5.5 shadow-xs">
                        <PostTexts :post="post" side-by-side />
                    </section>
                </template>
            </div>

            <aside
                class="grid content-start items-start gap-4"
                :class="sideBySide ? 'md:grid-cols-2 2xl:grid-cols-1' : ''"
            >
                <section
                    class="grid gap-2 rounded-xl p-5.5"
                    :class="
                        post?.state === 'scheduled'
                            ? 'bg-panel text-panel-foreground'
                            : 'bg-card shadow-xs'
                    "
                >
                    <h2
                        class="text-sm"
                        :class="
                            post?.state === 'scheduled'
                                ? 'text-panel-muted'
                                : 'text-muted-foreground'
                        "
                    >
                        Status
                    </h2>
                    <p
                        :class="
                            post?.state === 'scheduled' ? 'font-semibold' : ''
                        "
                        data-test="post-status"
                    >
                        {{ status }}
                    </p>
                    <p
                        v-if="post?.approval"
                        class="text-sm"
                        :class="
                            post.state === 'scheduled'
                                ? 'text-panel-muted'
                                : 'text-muted-foreground'
                        "
                        data-test="post-approval"
                    >
                        Approved
                        <template v-if="post.approval.by">
                            by {{ post.approval.by }}
                        </template>
                        on
                        <span class="font-mono">{{
                            formatDateTime(post.approval.at, timezone)
                        }}</span
                        >.
                    </p>
                </section>

                <ul
                    v-if="refusedMessages.length"
                    class="grid gap-1 rounded-lg border-2 border-destructive bg-card px-4 py-3 text-base text-destructive"
                    role="alert"
                    data-test="post-errors"
                >
                    <li v-for="message in refusedMessages" :key="message">
                        {{ message }}
                    </li>
                </ul>

                <section
                    v-if="editable"
                    class="grid gap-2 rounded-xl bg-card p-5.5 shadow-xs"
                >
                    <Label for="scheduled_at">
                        Planned time, {{ timezone }}
                    </Label>
                    <Input
                        id="scheduled_at"
                        v-model="form.scheduled_at"
                        type="datetime-local"
                        class="font-mono"
                    />
                    <p class="text-sm text-muted-foreground">
                        Leave it empty to decide after approval. A post with a
                        planned time is scheduled the moment it is approved.
                    </p>
                    <InputError
                        :message="
                            fieldError('scheduled_at') ?? refused.scheduled_at
                        "
                        data-test="planned-time-error"
                    />
                </section>

                <section
                    v-else-if="post?.can.schedule"
                    class="grid gap-2 rounded-xl bg-card p-5.5 shadow-xs"
                >
                    <Label for="schedule_at">
                        {{
                            post.state === 'scheduled'
                                ? 'Move to another time'
                                : 'Firing time'
                        }}, {{ timezone }}
                    </Label>
                    <Input
                        id="schedule_at"
                        v-model="scheduleAt"
                        type="datetime-local"
                        class="font-mono"
                    />
                    <InputError :message="refused.scheduled_at" />
                    <div class="mt-1 grid gap-2">
                        <!-- Giving an approved post its time is what moves
                             the week forward, so it is the red action here.
                             Moving a post that already has a time is not. -->
                        <Button
                            type="button"
                            :variant="
                                post.state === 'scheduled'
                                    ? 'default'
                                    : 'signal'
                            "
                            :size="
                                post.state === 'scheduled' ? 'default' : 'lg'
                            "
                            :disabled="busy !== null || scheduleAt === ''"
                            data-test="schedule"
                            @click="
                                act(
                                    'schedule',
                                    schedule.url({ post: post.id }),
                                    { scheduled_at: scheduleAt },
                                )
                            "
                        >
                            <Spinner v-if="busy === 'schedule'" />
                            {{
                                post.state === 'scheduled' ? 'Move' : 'Schedule'
                            }}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="busy !== null"
                            data-test="publish-now"
                            @click="confirming = 'publish-now'"
                        >
                            Publish at the next minute
                        </Button>
                        <Button
                            v-if="post.can.unschedule"
                            type="button"
                            variant="ghost"
                            class="text-muted-foreground"
                            :disabled="busy !== null"
                            data-test="unschedule"
                            @click="
                                act(
                                    'unschedule',
                                    unschedule.url({ post: post.id }),
                                )
                            "
                        >
                            Take off schedule
                        </Button>
                    </div>
                </section>

                <section
                    v-if="post && post.state === 'draft'"
                    class="grid gap-2 rounded-xl bg-card p-5.5 shadow-xs"
                    data-test="readiness"
                >
                    <h2 class="text-sm text-muted-foreground">
                        Before it can be approved
                    </h2>
                    <p v-if="form.isDirty" class="text-base">
                        Save the draft to check it again.
                    </p>
                    <ul v-else-if="problems.length" class="grid gap-1.5">
                        <li
                            v-for="problem in problems"
                            :key="problem"
                            class="text-base text-destructive"
                        >
                            {{ problem }}
                        </li>
                    </ul>
                    <p
                        v-else
                        class="flex items-baseline gap-2.5 rounded-md bg-secondary px-3.5 py-2.5 text-sm"
                    >
                        <span
                            class="rounded-sm bg-success-fill px-2 py-0.5 text-xs font-bold text-white"
                        >
                            OK
                        </span>
                        <span>
                            Nothing is missing. It is ready for approval.
                        </span>
                    </p>
                </section>

                <section
                    v-else-if="post && problems.length"
                    class="grid gap-2 rounded-xl bg-card p-5.5 shadow-xs"
                >
                    <h2 class="text-sm text-muted-foreground">
                        Cannot go out as it stands
                    </h2>
                    <ul class="grid gap-1.5">
                        <li
                            v-for="problem in problems"
                            :key="problem"
                            class="text-base text-destructive"
                        >
                            {{ problem }}
                        </li>
                    </ul>
                </section>

                <section
                    v-if="
                        isNew || (post && Object.values(post.can).some(Boolean))
                    "
                    class="grid gap-2 rounded-xl bg-card p-5.5 shadow-xs"
                >
                    <!-- One approval covers every text that is still to
                         be sent. This says which, and when they go. -->
                    <div
                        v-if="post?.can.approve && !form.isDirty"
                        class="grid gap-1.5 pb-1 text-base"
                        data-test="approval-covers"
                    >
                        <p>
                            <template v-if="pending.length === 1">
                                Approving covers the text for:
                            </template>
                            <template v-else>
                                One approval covers all
                                {{ pending.length }} texts of this post:
                            </template>
                        </p>
                        <ul class="grid gap-1">
                            <li v-for="variant in pending" :key="variant.id">
                                {{ variant.channel_name }}
                                <span class="text-muted-foreground">
                                    - {{ variant.platform_label }}
                                </span>
                            </li>
                        </ul>
                        <p v-if="post.scheduled_at">
                            {{
                                pending.length === 1
                                    ? 'Once approved it fires'
                                    : 'Once approved they fire together,'
                            }}
                            <span class="font-mono">{{
                                formatDateTime(post.scheduled_at, timezone)
                            }}</span
                            >.
                        </p>
                        <p v-else>
                            Nothing is sent until the post has a firing time.
                        </p>
                    </div>

                    <!-- Approving is the one red action of this page. -->
                    <Button
                        v-if="post?.can.approve"
                        type="button"
                        variant="signal"
                        size="lg"
                        :disabled="
                            form.isDirty || problems.length > 0 || busy !== null
                        "
                        data-test="approve"
                        @click="approveNow"
                    >
                        <Spinner v-if="busy === 'approve'" />
                        {{
                            post.scheduled_at
                                ? 'Approve and schedule'
                                : 'Approve'
                        }}
                    </Button>

                    <Button
                        v-if="editable"
                        type="submit"
                        :variant="post && !form.isDirty ? 'outline' : 'default'"
                        :disabled="
                            form.processing || form.variants.length === 0
                        "
                        data-test="save-draft"
                    >
                        <Spinner v-if="form.processing" />
                        Save draft
                    </Button>

                    <template v-if="post">
                        <Button
                            v-if="post.can.retry"
                            type="button"
                            :disabled="problems.length > 0 || busy !== null"
                            data-test="retry"
                            @click="confirming = 'retry'"
                        >
                            <Spinner v-if="busy === 'retry'" />
                            Send again
                        </Button>
                        <div
                            v-if="post.can.reject || post.can.delete"
                            class="flex flex-wrap gap-2"
                        >
                            <Button
                                v-if="post.can.reject"
                                type="button"
                                size="sm"
                                variant="ghost"
                                class="text-muted-foreground"
                                :disabled="busy !== null"
                                data-test="reject"
                                @click="
                                    act('reject', reject.url({ post: post.id }))
                                "
                            >
                                Reject
                            </Button>
                            <Button
                                v-if="post.can.delete"
                                type="button"
                                size="sm"
                                variant="ghost"
                                class="ml-auto text-destructive hover:text-destructive"
                                :disabled="busy !== null"
                                data-test="delete"
                                @click="confirming = 'delete'"
                            >
                                Delete
                            </Button>
                        </div>
                    </template>

                    <p
                        v-if="post?.can.approve && form.isDirty"
                        class="text-sm text-muted-foreground"
                    >
                        Save the draft before you approve it, so that you
                        approve what is saved.
                    </p>

                    <p
                        v-if="post && post.state === 'failed' && post.can.retry"
                        class="text-sm text-muted-foreground"
                    >
                        Before you send it again, check the channel itself. If
                        the post is there after all, sending it again makes a
                        duplicate.
                    </p>
                </section>

                <p
                    v-if="
                        post &&
                        !Object.values(post.can).some(Boolean) &&
                        post.state !== 'published' &&
                        post.state !== 'publishing'
                    "
                    class="px-1 text-sm text-muted-foreground"
                >
                    Owners and editors of this workspace can change posts.
                </p>

                <!-- What a model wrote about its own draft. Shown as text
                     only, never as markup or a link. -->
                <section
                    v-if="post?.origin"
                    class="grid gap-3 rounded-xl bg-card p-5.5 shadow-xs"
                    data-test="post-origin"
                >
                    <div class="grid gap-1">
                        <h2 class="text-sm text-muted-foreground">
                            Where this draft came from
                        </h2>
                        <p class="text-base">
                            Drafted from your brief
                            <template v-if="post.origin.drafted_at">
                                on
                                <span class="font-mono">{{
                                    formatDateTime(
                                        post.origin.drafted_at,
                                        timezone,
                                    )
                                }}</span>
                            </template>
                            by
                            <span class="font-mono">{{
                                post.origin.model
                            }}</span>
                            ({{ post.origin.provider }}). Nobody has checked it
                            yet: read it before you approve it.
                        </p>
                    </div>
                    <dl class="grid gap-3 text-base">
                        <div v-if="post.origin.pillar" class="grid gap-0.5">
                            <dt class="text-sm text-muted-foreground">
                                Pillar
                            </dt>
                            <dd>{{ post.origin.pillar }}</dd>
                        </div>
                        <div v-if="post.origin.idea" class="grid gap-0.5">
                            <dt class="text-sm text-muted-foreground">Idea</dt>
                            <dd class="break-words">{{ post.origin.idea }}</dd>
                        </div>
                        <div v-if="post.origin.rationale" class="grid gap-0.5">
                            <dt class="text-sm text-muted-foreground">
                                Why it was drafted this way, in the words of the
                                model
                            </dt>
                            <dd class="break-words whitespace-pre-line">
                                {{ post.origin.rationale }}
                            </dd>
                        </div>
                        <div v-if="post.origin.alt_text" class="grid gap-0.5">
                            <dt class="text-sm text-muted-foreground">
                                Suggested alternative text for an image
                            </dt>
                            <dd class="break-words whitespace-pre-line">
                                {{ post.origin.alt_text }}
                            </dd>
                        </div>
                        <div
                            v-if="post.origin.link_dropped"
                            class="grid gap-0.5"
                            data-test="post-origin-link-dropped"
                        >
                            <dt class="text-sm text-muted-foreground">Link</dt>
                            <dd>
                                The draft suggested a link that was not in the
                                idea or the brief. It was left out.
                            </dd>
                        </div>
                        <div
                            v-if="post.origin.input_tokens !== null"
                            class="grid gap-0.5"
                            data-test="post-origin-cost"
                        >
                            <dt class="text-sm text-muted-foreground">
                                What this draft used
                            </dt>
                            <dd>
                                <template v-if="post.origin.cost">
                                    About
                                    <span class="font-mono">
                                        {{ post.origin.currency }}
                                        {{ post.origin.cost }}
                                    </span>
                                    by the price table,
                                </template>
                                <span class="font-mono">{{
                                    post.origin.input_tokens
                                }}</span>
                                tokens in and
                                <span class="font-mono">{{
                                    post.origin.output_tokens
                                }}</span>
                                out.
                            </dd>
                        </div>
                    </dl>
                </section>

                <p v-if="post" class="px-1 text-base">
                    <Link
                        :href="create()"
                        class="inline-flex min-h-11 items-center font-semibold underline underline-offset-4"
                    >
                        Write another post
                    </Link>
                </p>
            </aside>
        </div>
    </form>

    <Dialog
        :open="confirming !== null"
        @update:open="(open: boolean) => !open && (confirming = null)"
    >
        <DialogContent>
            <DialogHeader class="space-y-3">
                <DialogTitle>
                    <template v-if="dialog === 'delete'">
                        Delete this post?
                    </template>
                    <template v-else-if="dialog === 'publish-now'">
                        Publish this post within a minute?
                    </template>
                    <template v-else-if="dialog === 'retry'">
                        Send this post again?
                    </template>
                    <template v-else>Return this post to draft?</template>
                </DialogTitle>
                <DialogDescription class="text-base">
                    <template v-if="dialog === 'delete'">
                        The post and its texts are removed for good. It was
                        never published, so nothing changes on any channel.
                    </template>
                    <template v-else-if="dialog === 'publish-now'">
                        It becomes public on {{ pendingNames }} at the next run
                        of the scheduler, which is within a minute. Once it is
                        out, it cannot be taken back from here.
                    </template>
                    <template v-else-if="dialog === 'retry'">
                        Look at {{ pendingNames }} first. If the post is there
                        after all, sending it again puts a second copy in
                        public, and Noon Gun cannot see or undo that.
                    </template>
                    <template v-else-if="post?.state === 'failed'">
                        The approval is removed and the post must be approved
                        again. Channels where it is already published keep their
                        post and are not sent again.
                    </template>
                    <template v-else>
                        The approval is removed and the post leaves the
                        schedule. It is not published until it is approved
                        again.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div v-if="dialog === 'retry'" class="grid gap-3 text-base">
                <ul class="grid gap-1" data-test="retry-errors">
                    <li v-for="variant in pending" :key="variant.id">
                        {{ variant.channel_name }}:
                        <span class="text-destructive">
                            {{ variant.error ?? 'No result was recorded.' }}
                        </span>
                    </li>
                </ul>
                <label class="flex min-h-11 cursor-pointer items-start gap-3">
                    <input
                        v-model="checkedChannel"
                        type="checkbox"
                        class="mt-0.5 size-5 shrink-0 accent-primary"
                        data-test="checked-channel"
                    />
                    <span>
                        I have looked at {{ pendingNames }} and the post is not
                        there.
                    </span>
                </label>
            </div>

            <DialogFooter class="gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    @click="confirming = null"
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    :variant="dialog === 'delete' ? 'destructive' : 'default'"
                    :disabled="
                        busy !== null || (dialog === 'retry' && !checkedChannel)
                    "
                    data-test="confirm"
                    @click="confirm"
                >
                    <Spinner v-if="busy !== null" />
                    <template v-if="dialog === 'delete'">Delete</template>
                    <template v-else-if="dialog === 'publish-now'">
                        Publish now
                    </template>
                    <template v-else-if="dialog === 'retry'">
                        Send again
                    </template>
                    <template v-else>Return to draft</template>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
