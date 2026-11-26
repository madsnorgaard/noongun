<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import ConnectReportNote from '@/components/channels/ConnectReport.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/channels';
import { destroy, edit, update } from '@/routes/channels/selection';
import type { Channel, ConnectReport } from '@/types/channels';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Channels',
                href: index(),
            },
            {
                title: 'Choose what to connect',
                href: edit(),
            },
        ],
    },
});

defineProps<{
    channels: Channel[];
    renewed: Channel[];
    connect_report: ConnectReport | null;
    minutes: number;
}>();

// Nothing is ticked for you: a channel is connected only because you chose it.
const form = useForm<{ keep: number[] }>({
    keep: [],
});

const cancelling = ref(false);

function submit(): void {
    form.put(update.url());
}

function keepNone(): void {
    router.delete(destroy.url(), {
        onStart: () => (cancelling.value = true),
        onFinish: () => (cancelling.value = false),
    });
}
</script>

<template>
    <Head title="Choose channels" />

    <form
        class="flex max-w-3xl flex-1 flex-col gap-5.5 px-4 pt-2 pb-12 md:px-8 lg:px-11"
        @submit.prevent="submit"
    >
        <header class="grid gap-1">
            <h1 class="text-title">Choose what to connect</h1>
            <p class="max-w-[72ch] text-muted-foreground">
                Access was granted to the channels below, and none of them is
                connected yet. Tick the ones this workspace publishes to. For
                the others, Noon Gun erases the access it received.
            </p>
        </header>

        <fieldset
            class="grid min-w-0 divide-y divide-border rounded-xl bg-card px-2 py-1 shadow-xs"
        >
            <legend class="sr-only">Channels to connect</legend>
            <label
                v-for="channel in channels"
                :key="channel.id"
                class="flex min-h-14 cursor-pointer items-center gap-3.5 rounded-md px-3 py-3 hover:bg-accent/50"
            >
                <input
                    v-model="form.keep"
                    type="checkbox"
                    name="keep[]"
                    :value="channel.id"
                    class="size-5 shrink-0 accent-primary"
                    :data-test="`keep-${channel.id}`"
                />
                <span class="grid min-w-0 gap-0.5">
                    <span class="font-semibold">{{
                        channel.display_name
                    }}</span>
                    <span class="text-sm text-muted-foreground">
                        {{ channel.platform_label }} -
                        <span class="font-mono text-xs break-all">{{
                            channel.external_id
                        }}</span>
                    </span>
                </span>
            </label>
        </fieldset>

        <InputError :message="form.errors.keep" />

        <div class="flex flex-wrap items-center gap-2.5">
            <Button
                type="submit"
                class="max-sm:w-full"
                :disabled="
                    form.processing || cancelling || form.keep.length === 0
                "
                data-test="keep-selected"
            >
                <Spinner v-if="form.processing" />
                Connect {{ form.keep.length }} selected
            </Button>
            <Button
                type="button"
                variant="ghost"
                class="text-muted-foreground max-sm:w-full"
                :disabled="form.processing || cancelling"
                data-test="keep-none"
                @click="keepNone"
            >
                <Spinner v-if="cancelling" />
                Connect none of them
            </Button>
        </div>

        <p class="max-w-[72ch] text-sm text-muted-foreground">
            If you leave this page without choosing, none of them is connected
            and the access is erased after
            <span class="font-mono">{{ minutes }}</span> minutes.
        </p>

        <ConnectReportNote v-if="connect_report" :report="connect_report" />

        <section
            v-if="renewed.length"
            class="grid gap-1 rounded-xl bg-card p-5.5 shadow-xs"
        >
            <h2 class="text-heading">Already connected</h2>
            <p class="max-w-[72ch] text-muted-foreground">
                These were connected before. Their access was renewed and they
                are not affected by your choice.
            </p>
            <ul class="mt-2 grid gap-1">
                <li v-for="channel in renewed" :key="channel.id">
                    {{ channel.display_name }}
                    <span class="text-sm text-muted-foreground">
                        - {{ channel.platform_label }}
                    </span>
                </li>
            </ul>
        </section>
    </form>
</template>
