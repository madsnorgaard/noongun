<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
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

    <div class="flex flex-1 flex-col gap-6 p-4">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="grid gap-1">
                <h1 class="text-lg font-medium">Channels</h1>
                <p class="text-sm text-muted-foreground">
                    The Pages and profiles this workspace publishes to.
                </p>
            </div>

            <div v-if="can.manage && ready.length" class="flex gap-2">
                <Button
                    v-for="platform in ready"
                    :key="platform.name"
                    size="sm"
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
            class="rounded-md border p-4 text-sm"
            :data-test="`setup-${platform.name}`"
        >
            <h2 class="font-medium">
                {{ platform.label }} is not set up on this instance
            </h2>
            <p class="mt-1 text-muted-foreground">
                Noon Gun connects through an app that you create with
                {{ platform.label }} yourself. Until its credentials are in
                place, nothing can be connected.
            </p>
            <ul class="mt-3 grid list-disc gap-1.5 pl-5">
                <li>
                    Create the app, and register this address in it as the
                    redirect address:
                    <code
                        v-if="platform.redirect_uri"
                        class="rounded bg-muted px-1 py-0.5 font-mono text-xs break-all"
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
                            class="rounded bg-muted px-1 py-0.5 font-mono text-xs"
                            >{{ name }}</code
                        >
                    </template>
                    in the <code class="font-mono text-xs">.env</code> file of
                    this instance.
                </li>
                <li>Restart the containers, then come back to this page.</li>
            </ul>
            <p class="mt-3 text-muted-foreground">
                The README describes each step.
            </p>
        </section>

        <section
            v-if="channels.length === 0 && ready.length"
            class="rounded-md border p-4 text-sm"
            data-test="channels-empty"
        >
            <h2 class="font-medium">No channels are connected yet</h2>
            <p class="mt-1 text-muted-foreground">
                <template v-if="can.manage">
                    Connect a Page to start publishing. You are sent to the
                    platform to sign in and choose what Noon Gun may post to,
                    and then brought back here.
                </template>
                <template v-else>
                    An owner or editor of this workspace can connect one.
                </template>
            </p>
        </section>

        <div v-if="channels.length" class="overflow-x-auto rounded-md border">
            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-normal">Name</th>
                        <th class="px-3 py-2 font-normal">Platform</th>
                        <th class="px-3 py-2 font-normal">Status</th>
                        <th class="px-3 py-2 font-normal">Access</th>
                        <th class="px-3 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="channel in channels"
                        :key="channel.id"
                        class="border-b align-top last:border-b-0"
                        :data-test="`channel-${channel.id}`"
                    >
                        <td class="px-3 py-2">
                            <div
                                :class="
                                    channel.status === 'disconnected'
                                        ? 'text-muted-foreground'
                                        : 'font-medium'
                                "
                            >
                                {{ channel.display_name }}
                            </div>
                            <div
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ channel.external_id }}
                            </div>
                            <p
                                v-if="channel.status === 'pending'"
                                class="mt-1 max-w-prose text-muted-foreground"
                            >
                                Access was granted but you have not confirmed
                                it. Nothing is published here, and the access is
                                erased shortly unless you keep it.
                            </p>
                            <p
                                v-else-if="channel.status === 'needs_reconnect'"
                                class="mt-1 max-w-prose text-destructive"
                            >
                                {{ channel.platform_label }} no longer accepts
                                this connection. Nothing is published here until
                                it is connected again.
                            </p>
                            <p
                                v-else-if="
                                    channel.status === 'active' &&
                                    channel.token_expired
                                "
                                class="mt-1 max-w-prose text-destructive"
                            >
                                The access has expired. Nothing is published
                                here until it is connected again.
                            </p>
                            <p
                                v-else-if="
                                    channel.status === 'active' &&
                                    channel.token_expires_soon
                                "
                                class="mt-1 max-w-prose text-muted-foreground"
                            >
                                The access ends soon. Connect again to renew it.
                            </p>
                        </td>
                        <td class="px-3 py-2">{{ channel.platform_label }}</td>
                        <td class="px-3 py-2">
                            <Badge
                                :variant="
                                    channel.status === 'needs_reconnect'
                                        ? 'destructive'
                                        : channel.status === 'active'
                                          ? 'secondary'
                                          : 'outline'
                                "
                            >
                                {{ statusLabels[channel.status] }}
                            </Badge>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ access(channel) }}
                        </td>
                        <td class="px-3 py-2">
                            <div
                                v-if="can.manage"
                                class="flex justify-end gap-2"
                            >
                                <Button
                                    v-if="
                                        channel.status === 'pending' &&
                                        selection_open
                                    "
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
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p
            v-if="!can.manage && channels.length"
            class="text-sm text-muted-foreground"
        >
            Owners and editors of this workspace can connect and disconnect
            channels.
        </p>

        <section
            v-if="can.manage && ready.some((platform) => platform.redirect_uri)"
            class="grid gap-1 text-sm text-muted-foreground"
        >
            <h2 class="text-foreground">Redirect addresses</h2>
            <p>
                Each platform app must list the address below exactly as it is
                written. It follows APP_URL.
            </p>
            <dl class="mt-1 grid gap-1">
                <div
                    v-for="platform in ready"
                    :key="platform.name"
                    class="flex flex-wrap gap-x-2"
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
                <DialogDescription>
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
