<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { computed } from 'vue';
import { dashboard } from '@/routes';
import { edit as brief } from '@/routes/brief';
import { index as calendar } from '@/routes/calendar';
import { index as channels } from '@/routes/channels';
import { edit as draftingSettings } from '@/routes/llm-key';
import { create as proposeWeek } from '@/routes/proposals';
import { index as queue } from '@/routes/queue';
import { index as slots } from '@/routes/slots';

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

type SetupKey = 'channel' | 'brief' | 'slots' | 'key' | 'propose';

const props = defineProps<{
    /** What is left before a week can be proposed. Null when all is done. */
    setup: { key: SetupKey; done: boolean }[] | null;
    can: { propose: boolean };
}>();

const setupSteps = computed(() =>
    (props.setup ?? []).map((step) => ({
        ...step,
        ...{
            channel: { title: 'Connect a channel', href: channels() },
            brief: { title: 'Write the brief', href: brief() },
            slots: { title: 'Set the times you prefer to post', href: slots() },
            key: {
                title: 'Make drafting available with an API key',
                href: draftingSettings(),
            },
            // Only owners and editors can propose a week.
            propose: {
                title: 'Propose a week of drafts',
                href: props.can.propose ? proposeWeek() : null,
            },
        }[step.key],
    })),
);

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

    <div class="flex flex-1 flex-col gap-5.5 px-4 pt-2 pb-12 md:px-8 lg:px-11">
        <section v-if="workspace" class="grid gap-1">
            <h1 class="text-title md:text-display">{{ workspace.name }}</h1>
            <p class="text-muted-foreground">
                {{ workspace.timezone }} - {{ localTime }}
            </p>
        </section>

        <section
            v-if="setup"
            class="rounded-xl bg-card p-5.5 shadow-xs"
            data-test="setup"
        >
            <h2 class="text-heading">Before the first week</h2>
            <p class="mt-1 max-w-[72ch] text-muted-foreground">
                What a week of drafts needs, in order. This list goes away when
                all of it is done.
            </p>
            <ul class="mt-3 grid divide-y divide-border">
                <li
                    v-for="step in setupSteps"
                    :key="step.key"
                    class="flex min-h-11 items-center gap-3 py-1.5"
                    :data-test="`setup-${step.key}`"
                >
                    <span
                        class="w-[4.5rem] shrink-0 rounded-sm px-2 py-0.5 text-center text-xs font-bold"
                        :class="
                            step.done
                                ? 'bg-success-fill text-white'
                                : 'bg-secondary text-muted-foreground'
                        "
                    >
                        {{ step.done ? 'Done' : 'Not yet' }}
                    </span>
                    <TextLink v-if="step.href" :href="step.href">
                        {{ step.title }}
                    </TextLink>
                    <span v-else>{{ step.title }}</span>
                </li>
            </ul>
        </section>

        <section class="rounded-xl bg-card p-5.5 shadow-xs">
            <h2 class="text-heading">Queue</h2>
            <p class="mt-1 max-w-[72ch] text-muted-foreground">
                Drafts that wait for approval, scheduled posts and anything that
                failed are in the <TextLink :href="queue()">queue</TextLink>.
                The <TextLink :href="calendar()">calendar</TextLink> shows the
                same posts by day.
            </p>
        </section>
    </div>
</template>
