<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/channels';
import { edit, update } from '@/routes/channels/selection';
import type { Channel } from '@/types/channels';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Channels',
                href: index(),
            },
            {
                title: 'Choose what to keep',
                href: edit(),
            },
        ],
    },
});

const props = defineProps<{
    channels: Channel[];
}>();

const form = useForm({
    keep: props.channels.map((channel) => channel.id),
});

function submit(): void {
    form.put(update.url());
}
</script>

<template>
    <Head title="Choose channels" />

    <form
        class="flex max-w-2xl flex-1 flex-col gap-6 p-4"
        @submit.prevent="submit"
    >
        <header class="grid gap-1">
            <h1 class="text-lg font-medium">Choose what to keep</h1>
            <p class="text-sm text-muted-foreground">
                Access was granted to {{ channels.length }} channels. Keep the
                ones this workspace publishes to. For the others, Noon Gun
                erases the access it received.
            </p>
        </header>

        <fieldset class="rounded-md border text-sm">
            <legend class="sr-only">Channels to keep</legend>
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
                :disabled="form.processing || form.keep.length === 0"
                data-test="keep-selected"
            >
                <Spinner v-if="form.processing" />
                Keep {{ form.keep.length }} selected
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="index()">Decide later</Link>
            </Button>
        </div>

        <p class="text-sm text-muted-foreground">
            Until you choose, all of them stay connected. You can disconnect a
            channel at any time from the Channels page.
        </p>
    </form>
</template>
