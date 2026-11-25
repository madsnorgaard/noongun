<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
import { connect, destroy, index } from '@/routes/channels';
import { edit as chooseChannels } from '@/routes/channels/selection';
import type { Channel, ChannelPlatform } from '@/types/channels';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Channels',
                href: index(),
            },
        ],
    },
});

const props = defineProps<{
    channels: Channel[];
    platforms: ChannelPlatform[];
    selection_open: boolean;
    can: { manage: boolean };
}>();

const page = usePage();
const timezone = computed(
    () =>
        (page.props.workspace as { timezone: string } | null)?.timezone ??
        'UTC',
);

const ready = computed(() =>
    props.platforms.filter(
        (platform) => platform.missing_settings.length === 0,
    ),
);
const notReady = computed(() =>
    props.platforms.filter((platform) => platform.missing_settings.length > 0),
);

const connecting = ref<string | null>(null);
const toDisconnect = ref<Channel | null>(null);
const disconnecting = ref(false);

const statusLabels: Record<Channel['status'], string> = {
    active: 'Connected',
    pending: 'Not confirmed',
    needs_reconnect: 'Needs reconnecting',
    disconnected: 'Disconnected',
};

// The chip of a channel: a dot and a word, as for a post. A connection that
// needs a person has a square dot, so it does not rest on colour alone.
const statusChips: Record<Channel['status'], string> = {
    active: 'bg-secondary text-secondary-foreground',
    pending: 'bg-secondary text-secondary-foreground',
    needs_reconnect: 'bg-destructive-fill text-white',
    disconnected: 'bg-secondary text-muted-foreground',
};

const statusDots: Record<Channel['status'], string> = {
    active: 'rounded-full bg-success',
    pending: 'rounded-full ring-2 ring-foreground ring-inset',
    needs_reconnect: 'rounded-[2px] bg-white',
    disconnected: 'rounded-full ring-2 ring-idle ring-inset',
};

function canConnect(platform: string): boolean {
    return ready.value.some((candidate) => candidate.name === platform);
}

function startConnect(platform: string): void {
    connecting.value = platform;

    // The server answers with the address of the platform's sign-in page and
    // the browser leaves the app, so the spinner stays until then.
    router.post(
        connect.url({ platform }),
        {},
        {
            onError: () => (connecting.value = null),
            onCancel: () => (connecting.value = null),
            onSuccess: () => (connecting.value = null),
        },
    );
}

function disconnect(): void {
    if (!toDisconnect.value) {
        return;
    }

    router.delete(destroy.url({ channel: toDisconnect.value.id }), {
        preserveScroll: true,
        onStart: () => (disconnecting.value = true),
        onFinish: () => {
            disconnecting.value = false;
            toDisconnect.value = null;
        },
    });
}

function access(channel: Channel): string {
    if (channel.status === 'disconnected') {
        return 'Access removed';
    }

    if (channel.status === 'pending') {
        return 'Not in use';
    }

    if (!channel.token_expires_at) {
        return 'Does not expire';
    }

    const date = new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeZone: timezone.value,
    }).format(new Date(channel.token_expires_at));

    return channel.token_expired ? `Expired ${date}` : `Expires ${date}`;
}
</script>

<template>
    <Head title="Channels" />

    <div class="flex flex-1 flex-col gap-5.5 px-4 pt-2 pb-12 md:px-8 lg:px-11">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="grid gap-1">
                <h1 class="text-title md:text-display">Channels</h1>
                <p class="text-muted-foreground">
                    The Pages and profiles this workspace publishes to.
                </p>
            </div>

            <div
                v-if="can.manage && ready.length"
                class="flex flex-wrap gap-2.5"
            >
                <Button
                    v-for="platform in ready"
                    :key="platform.name"
                    :disabled="connecting !== null"
                    :data-test="`connect-${platform.name}`"
                    @click="startConnect(platform.name)"
                >
                    <Spinner v-if="connecting === platform.name" />
                    Connect {{ platform.label }}
                </Button>
            </div>
        </header>

        <section
            v-for="platform in notReady"
            :key="platform.name"
            class="rounded-xl bg-card p-5.5 shadow-xs"
            :data-test="`setup-${platform.name}`"
        >
            <h2 class="text-heading">
                {{ platform.label }} is not set up on this instance
            </h2>
            <p class="mt-1 max-w-[72ch] text-muted-foreground">
                Noon Gun connects through an app that you create with
                {{ platform.label }} yourself. Until its credentials are in
                place, nothing can be connected.
            </p>
            <ul class="mt-3 grid max-w-[72ch] list-disc gap-1.5 pl-5 text-base">
                <li>
                    Create the app, and register this address in it as the
                    redirect address:
                    <code
                        v-if="platform.redirect_uri"
                        class="rounded-sm bg-secondary px-1.5 py-0.5 font-mono text-xs break-all"
                        >{{ platform.redirect_uri }}</code
                    >
                </li>
                <li>
                    Set
                    <template
                        v-for="(name, position) in platform.missing_settings"
                        :key="name"
                    >
                        <span v-if="position > 0"> and </span>
                        <code
                            class="rounded-sm bg-secondary px-1.5 py-0.5 font-mono text-xs"
                            >{{ name }}</code
                        >
                    </template>
                    in the <code class="font-mono text-xs">.env</code> file of
                    this instance.
                </li>
                <li>Restart the containers, then come back to this page.</li>
            </ul>
            <p class="mt-3 text-sm text-muted-foreground">
                The README describes each step.
            </p>
        </section>

        <section
            v-if="channels.length === 0 && ready.length"
            class="rounded-xl bg-card p-5.5 shadow-xs"
            data-test="channels-empty"
        >
            <h2 class="text-heading">No channels are connected yet</h2>
            <p class="mt-1 max-w-[72ch] text-muted-foreground">
                <template v-if="can.manage">
                    Connect a Page or a profile to start publishing. You are
                    sent to the platform to sign in and choose what Noon Gun may
                    post to, and then brought back here.
                </template>
                <template v-else>
                    Only an owner of this workspace can connect one.
                </template>
            </p>
        </section>

        <ul v-if="channels.length" class="grid gap-3">
            <li
                v-for="channel in channels"
                :key="channel.id"
                class="flex flex-wrap items-start gap-x-6 gap-y-3 rounded-xl bg-card px-4 py-4 shadow-xs sm:px-6"
                :class="
                    channel.status === 'needs_reconnect' ||
                    (channel.status === 'active' && channel.token_expired)
                        ? 'border-2 border-destructive'
                        : ''
                "
                :data-test="`channel-${channel.id}`"
            >
                <div class="grid min-w-0 flex-[1_1_18rem] gap-1.5">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                        <h2
                            class="text-lg"
                            :class="
                                channel.status === 'disconnected'
                                    ? 'text-muted-foreground'
                                    : 'font-semibold'
                            "
                        >
                            {{ channel.display_name }}
                        </h2>
                        <span
                            class="inline-flex w-fit items-center gap-2 rounded-full px-[11px] py-[3px] text-[0.875rem] leading-snug font-semibold whitespace-nowrap"
                            :class="statusChips[channel.status]"
                        >
                            <span
                                class="size-[9px] shrink-0"
                                :class="statusDots[channel.status]"
                                aria-hidden="true"
                            ></span>
                            {{ statusLabels[channel.status] }}
                        </span>
                    </div>
                    <p
                        class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
                    >
                        <span
                            class="rounded-sm bg-secondary px-2.5 py-1 text-foreground"
                        >
                            {{ channel.platform_label }}
                        </span>
                        <span class="font-mono text-xs break-all">
                            {{ channel.external_id }}
                        </span>
                    </p>
                    <p
                        v-if="channel.status === 'pending'"
                        class="max-w-[72ch] text-base"
                    >
                        Access was granted but you have not confirmed it.
                        Nothing is published here, and the access is erased
                        shortly unless you keep it.
                    </p>
                    <p
                        v-else-if="channel.status === 'needs_reconnect'"
                        class="max-w-[72ch] text-base text-destructive"
                    >
                        {{ channel.platform_label }} no longer accepts this
                        connection. Nothing is published here until it is
                        connected again.
                    </p>
                    <p
                        v-else-if="
                            channel.status === 'active' && channel.token_expired
                        "
                        class="max-w-[72ch] text-base text-destructive"
                    >
                        The access has expired. Nothing is published here until
                        it is connected again.
                    </p>
                    <p
                        v-else-if="
                            channel.status === 'active' &&
                            channel.token_expires_soon
                        "
                        class="max-w-[72ch] text-base text-warning"
                    >
                        The access ends soon. Connect again to renew it.
                    </p>
                </div>

                <dl class="grid shrink-0 gap-0.5 text-sm sm:w-44">
                    <dt class="text-muted-foreground">Access</dt>
                    <dd class="text-base">{{ access(channel) }}</dd>
                </dl>

                <div
                    v-if="can.manage"
                    class="flex shrink-0 flex-wrap justify-end gap-2 max-sm:w-full"
                >
                    <Button
                        v-if="channel.status === 'pending' && selection_open"
                        size="sm"
                        as-child
                    >
                        <Link :href="chooseChannels()">Choose</Link>
                    </Button>
                    <Button
                        v-if="
                            channel.status !== 'pending' &&
                            canConnect(channel.platform) &&
                            (channel.status !== 'active' ||
                                channel.token_expired ||
                                channel.token_expires_soon)
                        "
                        size="sm"
                        :variant="
                            channel.status === 'disconnected'
                                ? 'outline'
                                : 'default'
                        "
                        :disabled="connecting !== null"
                        @click="startConnect(channel.platform)"
                    >
                        Connect again
                    </Button>
                    <Button
                        v-if="
                            channel.status !== 'disconnected' &&
                            channel.status !== 'pending'
                        "
                        size="sm"
                        variant="outline"
                        @click="toDisconnect = channel"
                    >
                        Disconnect
                    </Button>
                </div>
            </li>
        </ul>

        <p
            v-if="!can.manage && channels.length"
            class="text-sm text-muted-foreground"
            data-test="channels-owner-only"
        >
            Only owners of this workspace can connect and disconnect channels,
            because a channel holds the access to an account. Ask an owner if
            one needs to be connected again.
        </p>

        <section
            v-if="can.manage && ready.some((platform) => platform.redirect_uri)"
            class="grid gap-1 text-sm text-muted-foreground"
        >
            <h2 class="text-base font-semibold text-foreground">
                Redirect addresses
            </h2>
            <p>
                Each platform app must list the address below exactly as it is
                written. It follows APP_URL.
            </p>
            <dl class="mt-1 grid gap-1">
                <div
                    v-for="platform in ready"
                    :key="platform.name"
                    class="flex flex-wrap items-baseline gap-x-2"
                >
                    <dt>{{ platform.label }}</dt>
                    <dd class="font-mono text-xs leading-5 break-all">
                        {{ platform.redirect_uri }}
                    </dd>
                </div>
            </dl>
        </section>
    </div>

    <Dialog
        :open="toDisconnect !== null"
        @update:open="(open: boolean) => !open && (toDisconnect = null)"
    >
        <DialogContent>
            <DialogHeader class="space-y-3">
                <DialogTitle>
                    Disconnect {{ toDisconnect?.display_name }}?
                </DialogTitle>
                <DialogDescription class="text-base">
                    Noon Gun erases the access it holds for this channel. Posts
                    that are already published stay where they are and keep
                    their links. Posts that are scheduled for this channel fail
                    until it is connected again.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="toDisconnect = null">
                    Cancel
                </Button>
                <Button
                    variant="destructive"
                    :disabled="disconnecting"
                    data-test="confirm-disconnect"
                    @click="disconnect"
                >
                    <Spinner v-if="disconnecting" />
                    Disconnect
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
