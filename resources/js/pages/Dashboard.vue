<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { computed } from 'vue';
import { dashboard } from '@/routes';
import { index as calendar } from '@/routes/calendar';
import { index as queue } from '@/routes/queue';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

type Workspace = { id: number; name: string; timezone: string; locale: string };

const page = usePage();
const workspace = computed(() => page.props.workspace as Workspace | null);

const localTime = computed(() => {
    if (!workspace.value) {
        return null;
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'full',
        timeStyle: 'short',
        timeZone: workspace.value.timezone,
    }).format(new Date());
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <section v-if="workspace" class="grid gap-1 text-sm">
            <h1 class="text-lg font-medium">{{ workspace.name }}</h1>
            <p class="text-muted-foreground">
                {{ workspace.timezone }} - {{ localTime }}
            </p>
        </section>

        <section class="rounded-md border p-4 text-sm">
            <h2 class="font-medium">Queue</h2>
            <p class="mt-1 text-muted-foreground">
                Drafts that wait for approval, scheduled posts and anything that
                failed are in the <TextLink :href="queue()">queue</TextLink>.
                The <TextLink :href="calendar()">calendar</TextLink> shows the
                same posts by day.
            </p>
        </section>
    </div>
</template>
