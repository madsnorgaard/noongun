<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/channels';
import { destroy, edit, update } from '@/routes/channels/selection';
import type { Channel } from '@/types/channels';

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
        class="flex max-w-2xl flex-1 flex-col gap-6 p-4"
        @submit.prevent="submit"
    >
        <header class="grid gap-1">
            <h1 class="text-lg font-medium">Choose what to connect</h1>
            <p class="text-sm text-muted-foreground">
                Access was granted to the channels below, and none of them is
                connected yet. Tick the ones this workspace publishes to. For
                the others, Noon Gun erases the access it received.
            </p>
        </header>

        <fieldset class="rounded-md border text-sm">
            <legend class="sr-only">Channels to connect</legend>
            <label
                v-for="channel in channels"
                :key="channel.id"
                class="flex cursor-pointer items-center gap-3 border-b px-3 py-2 last:border-b-0 hover:bg-accent/50"
            >
                <input
                    v-model="form.keep"
                    type="checkbox"
                    name="keep[]"
                    :value="channel.id"
                    class="size-4 accent-primary"
                    :data-test="`keep-${channel.id}`"
                />
                <span class="grid">
                    <span class="font-medium">{{ channel.display_name }}</span>
                    <span class="text-xs text-muted-foreground">
                        {{ channel.platform_label }} -
                        <span class="font-mono">{{ channel.external_id }}</span>
                    </span>
                </span>
            </label>
        </fieldset>

        <InputError :message="form.errors.keep" />

        <div class="flex items-center gap-3">
            <Button
                type="submit"
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
                :disabled="form.processing || cancelling"
                data-test="keep-none"
                @click="keepNone"
            >
                <Spinner v-if="cancelling" />
                Connect none of them
            </Button>
        </div>

        <p class="text-sm text-muted-foreground">
            If you leave this page without choosing, none of them is connected
            and the access is erased after {{ minutes }} minutes.
        </p>

        <section v-if="renewed.length" class="grid gap-1 text-sm">
            <h2 class="font-medium">Already connected</h2>
            <p class="text-muted-foreground">
                These were connected before. Their access was renewed and they
                are not affected by your choice.
            </p>
            <ul class="mt-1 grid gap-0.5">
                <li v-for="channel in renewed" :key="channel.id">
                    {{ channel.display_name }}
                    <span class="text-xs text-muted-foreground">
                        - {{ channel.platform_label }}
                    </span>
                </li>
            </ul>
        </section>
    </form>
</template>
