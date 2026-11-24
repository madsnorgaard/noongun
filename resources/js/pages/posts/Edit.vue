<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PostStateBadge from '@/components/posts/PostStateBadge.vue';
import PostTexts from '@/components/posts/PostTexts.vue';
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
import {
    formatDateTime,
    problemList,
    textLength,
    useWorkspaceTimezone,
} from '@/lib/posts';
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

function remaining(variant: VariantFields): number | null {
    const limit = channel(variant.channel_id)?.max_body_length ?? null;

    return limit === null ? null : limit - textLength(variant.body);
}

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
const refusedMessages = computed(() =>
    Object.entries(refused.value)
        .filter(([key]) => key !== 'scheduled_at')
        .map(([, message]) => message),
);

const confirming = ref<'delete' | 'reopen' | null>(null);

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
    }
}

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
            return 'Approved, but not on the schedule. It is not sent until it has a time.';
        case 'scheduled':
            return `Approved and scheduled. It goes out ${when}.`;
        case 'publishing':
            return 'It is being published now.';
        case 'published':
            return 'Published on every channel.';
        case 'failed':
            return 'Publishing did not succeed on every channel. See what each channel reports.';
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

    <form class="flex flex-1 flex-col gap-6 p-4" @submit.prevent="save">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <h1 class="text-lg font-medium">
                    {{ isNew ? 'New post' : 'Post' }}
                </h1>
                <PostStateBadge v-if="post" :state="post.state" />
            </div>
            <Button size="sm" variant="ghost" as-child>
                <Link :href="queue()">Back to the queue</Link>
            </Button>
        </header>

        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="grid content-start gap-6 text-sm">
                <template v-if="editable">
                    <section
                        v-if="channels.length === 0"
                        class="rounded-md border p-4"
                        data-test="no-channels"
                    >
                        <h2 class="font-medium">No channel is connected</h2>
                        <p class="mt-1 text-muted-foreground">
                            A post is written for a channel. Connect one first.
                        </p>
                        <Button
                            class="mt-3"
                            size="sm"
                            variant="outline"
                            as-child
                        >
                            <Link :href="channelsIndex()">Go to Channels</Link>
                        </Button>
                    </section>

                    <fieldset v-else class="grid gap-2">
                        <legend class="font-medium">Channels</legend>
                        <div class="mt-2 rounded-md border">
                            <label
                                v-for="option in offered"
                                :key="option.id"
                                class="flex items-start gap-3 border-b px-3 py-2 last:border-b-0"
                                :class="
                                    selectable(option)
                                        ? 'cursor-pointer hover:bg-accent/50'
                                        : 'text-muted-foreground'
                                "
                            >
                                <input
                                    type="checkbox"
                                    class="mt-0.5 size-4 accent-primary"
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
                                <span class="grid">
                                    <span>
                                        {{ option.display_name }}
                                        <span class="text-muted-foreground">
                                            - {{ option.platform_label }}
                                        </span>
                                    </span>
                                    <span
                                        v-if="option.problem"
                                        class="text-xs"
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
                        <InputError :message="fieldError('variants')" />
                    </fieldset>

                    <section
                        v-for="(variant, position) in form.variants"
                        :key="variant.channel_id"
                        class="grid gap-3"
                        :data-test="`text-${variant.channel_id}`"
                    >
                        <div class="grid gap-2">
                            <div
                                class="flex items-baseline justify-between gap-3"
                            >
                                <Label :for="`body-${variant.channel_id}`">
                                    Text for
                                    {{
                                        channel(variant.channel_id)
                                            ?.display_name
                                    }}
                                </Label>
                                <span
                                    v-if="remaining(variant) !== null"
                                    class="text-xs tabular-nums"
                                    :class="
                                        (remaining(variant) ?? 0) < 0
                                            ? 'text-destructive'
                                            : 'text-muted-foreground'
                                    "
                                    :data-test="`remaining-${variant.channel_id}`"
                                >
                                    <template
                                        v-if="(remaining(variant) ?? 0) < 0"
                                    >
                                        {{ -(remaining(variant) ?? 0) }} over
                                        the limit of
                                        {{
                                            channel(variant.channel_id)
                                                ?.max_body_length
                                        }}
                                    </template>
                                    <template v-else>
                                        {{ remaining(variant) }} characters left
                                    </template>
                                </span>
                            </div>
                            <textarea
                                :id="`body-${variant.channel_id}`"
                                v-model="variant.body"
                                rows="9"
                                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base leading-relaxed shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                            ></textarea>
                            <InputError
                                :message="
                                    fieldError(`variants.${position}.body`)
                                "
                            />
                            <InputError
                                :message="
                                    fieldError(
                                        `variants.${position}.channel_id`,
                                    )
                                "
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label :for="`link-${variant.channel_id}`">
                                Link
                                <span class="font-normal text-muted-foreground">
                                    - optional
                                </span>
                            </Label>
                            <Input
                                :id="`link-${variant.channel_id}`"
                                v-model="variant.link"
                                type="url"
                                inputmode="url"
                            />
                            <InputError
                                :message="
                                    fieldError(`variants.${position}.link`)
                                "
                            />
                        </div>
                    </section>

                    <section
                        v-if="post && lockedVariants.length"
                        class="grid gap-2 rounded-md border p-3"
                    >
                        <h2 class="font-medium">Already published</h2>
                        <p class="text-muted-foreground">
                            These stay as they are and are not sent again.
                        </p>
                        <PostTexts
                            :post="{ ...post, variants: lockedVariants }"
                        />
                    </section>
                </template>

                <template v-else-if="post">
                    <section
                        v-if="locked && post.can.return_to_draft"
                        class="flex flex-wrap items-start justify-between gap-3 rounded-md border p-3"
                        data-test="locked-notice"
                    >
                        <p class="max-w-prose text-muted-foreground">
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

                    <PostTexts :post="post" />
                </template>
            </div>

            <aside class="grid content-start gap-5 text-sm">
                <section class="grid gap-1.5">
                    <h2 class="font-medium">Status</h2>
                    <p class="text-muted-foreground" data-test="post-status">
                        {{ status }}
                    </p>
                    <p
                        v-if="post?.approval"
                        class="text-muted-foreground"
                        data-test="post-approval"
                    >
                        Approved
                        <template v-if="post.approval.by">
                            by {{ post.approval.by }}
                        </template>
                        on {{ formatDateTime(post.approval.at, timezone) }}.
                    </p>
                </section>

                <ul
                    v-if="refusedMessages.length"
                    class="grid gap-1 text-destructive"
                    role="alert"
                    data-test="post-errors"
                >
                    <li v-for="message in refusedMessages" :key="message">
                        {{ message }}
                    </li>
                </ul>

                <section v-if="editable" class="grid gap-2 border-t pt-4">
                    <Label for="scheduled_at">Planned time</Label>
                    <Input
                        id="scheduled_at"
                        v-model="form.scheduled_at"
                        type="datetime-local"
                    />
                    <p class="text-xs text-muted-foreground">
                        On the clock of {{ timezone }}. Leave it empty to decide
                        after approval. A post with a planned time is scheduled
                        the moment it is approved.
                    </p>
                    <InputError :message="fieldError('scheduled_at')" />
                </section>

                <section
                    v-else-if="post?.can.schedule"
                    class="grid gap-2 border-t pt-4"
                >
                    <Label for="schedule_at">
                        {{
                            post.state === 'scheduled'
                                ? 'Move to another time'
                                : 'Publish at'
                        }}
                    </Label>
                    <Input
                        id="schedule_at"
                        v-model="scheduleAt"
                        type="datetime-local"
                    />
                    <p class="text-xs text-muted-foreground">
                        On the clock of {{ timezone }}.
                    </p>
                    <InputError :message="refused.scheduled_at" />
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            size="sm"
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
                            size="sm"
                            variant="outline"
                            :disabled="busy !== null"
                            data-test="publish-now"
                            @click="
                                act(
                                    'publish-now',
                                    schedule.url({ post: post.id }),
                                    { now: true },
                                )
                            "
                        >
                            Publish at the next minute
                        </Button>
                        <Button
                            v-if="post.can.unschedule"
                            type="button"
                            size="sm"
                            variant="ghost"
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
                    class="grid gap-1.5 border-t pt-4"
                    data-test="readiness"
                >
                    <h2 class="font-medium">Before it can be approved</h2>
                    <p v-if="form.isDirty" class="text-muted-foreground">
                        Save the draft to check it again.
                    </p>
                    <ul v-else-if="problems.length" class="grid gap-1">
                        <li
                            v-for="problem in problems"
                            :key="problem"
                            class="text-destructive"
                        >
                            {{ problem }}
                        </li>
                    </ul>
                    <p v-else class="text-muted-foreground">
                        Nothing is missing. It is ready for approval.
                    </p>
                </section>

                <section
                    v-else-if="post && problems.length"
                    class="grid gap-1.5 border-t pt-4"
                >
                    <h2 class="font-medium">Cannot go out as it stands</h2>
                    <ul class="grid gap-1">
                        <li
                            v-for="problem in problems"
                            :key="problem"
                            class="text-destructive"
                        >
                            {{ problem }}
                        </li>
                    </ul>
                </section>

                <section
                    v-if="
                        isNew || (post && Object.values(post.can).some(Boolean))
                    "
                    class="flex flex-wrap gap-2 border-t pt-4"
                >
                    <Button
                        v-if="editable"
                        type="submit"
                        size="sm"
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
                            v-if="post.can.approve"
                            type="button"
                            size="sm"
                            :disabled="
                                form.isDirty ||
                                problems.length > 0 ||
                                busy !== null
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
                            v-if="post.can.retry"
                            type="button"
                            size="sm"
                            :disabled="problems.length > 0 || busy !== null"
                            data-test="retry"
                            @click="act('retry', retry.url({ post: post.id }))"
                        >
                            <Spinner v-if="busy === 'retry'" />
                            Send again
                        </Button>
                        <Button
                            v-if="post.can.reject"
                            type="button"
                            size="sm"
                            variant="outline"
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
                            class="text-destructive hover:text-destructive"
                            :disabled="busy !== null"
                            data-test="delete"
                            @click="confirming = 'delete'"
                        >
                            Delete
                        </Button>
                    </template>
                </section>

                <p
                    v-if="post?.can.approve && form.isDirty"
                    class="text-xs text-muted-foreground"
                >
                    Save the draft before you approve it, so that you approve
                    what is saved.
                </p>

                <p
                    v-if="post && post.state === 'failed' && post.can.retry"
                    class="text-xs text-muted-foreground"
                >
                    Before you send it again, check the channel itself. If the
                    post is there after all, sending it again makes a duplicate.
                </p>

                <p
                    v-if="
                        post &&
                        !Object.values(post.can).some(Boolean) &&
                        post.state !== 'published' &&
                        post.state !== 'publishing'
                    "
                    class="text-xs text-muted-foreground"
                >
                    Owners and editors of this workspace can change posts.
                </p>

                <p v-if="post" class="text-xs text-muted-foreground">
                    <Link :href="create()" class="underline underline-offset-4">
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
                    {{
                        confirming === 'delete'
                            ? 'Delete this post?'
                            : 'Return this post to draft?'
                    }}
                </DialogTitle>
                <DialogDescription>
                    <template v-if="confirming === 'delete'">
                        The post and its texts are removed for good. It was
                        never published, so nothing changes on any channel.
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
                    :variant="
                        confirming === 'delete' ? 'destructive' : 'default'
                    "
                    :disabled="busy !== null"
                    data-test="confirm"
                    @click="confirm"
                >
                    <Spinner v-if="busy !== null" />
                    {{ confirming === 'delete' ? 'Delete' : 'Return to draft' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
