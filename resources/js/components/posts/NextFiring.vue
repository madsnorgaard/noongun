<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { formatDateTime, useWorkspaceTimezone } from '@/lib/posts';
import type { Post } from '@/types/posts';

/**
 * The gunmetal panel of the queue: when the next scheduled post fires, a
 * countdown to it, and the fuse. It only reads the posts the page already
 * has, in the order the server gave them (soonest first), and shows nothing
 * when none of them is scheduled.
 */
const props = defineProps<{
    posts: Post[];
}>();

const timezone = useWorkspaceTimezone();

const next = computed(
    () =>
        props.posts.find(
            (post) => post.state === 'scheduled' && post.scheduled_at !== null,
        ) ?? null,
);

// Posts that fire at the same minute as the next one.
const together = computed(
    () =>
        props.posts.filter(
            (post) =>
                post.state === 'scheduled' &&
                post.scheduled_at === next.value?.scheduled_at,
        ).length,
);

// The clock runs in the browser only, so the first paint has no countdown.
const now = ref<number | null>(null);
let timer: number | undefined;

onMounted(() => {
    now.value = Date.now();
    timer = window.setInterval(() => (now.value = Date.now()), 1000);
});

onBeforeUnmount(() => window.clearInterval(timer));

const secondsLeft = computed(() => {
    if (now.value === null || !next.value?.scheduled_at) {
        return null;
    }

    return Math.max(
        0,
        Math.floor(
            (new Date(next.value.scheduled_at).getTime() - now.value) / 1000,
        ),
    );
});

function two(value: number): string {
    return String(value).padStart(2, '0');
}

const countdown = computed(() => {
    const left = secondsLeft.value;

    if (left === null) {
        return '--:--:--';
    }

    const days = Math.floor(left / 86400);
    const clock = `${two(Math.floor((left % 86400) / 3600))}:${two(Math.floor((left % 3600) / 60))}:${two(left % 60)}`;

    return days > 0 ? `${days}d ${clock}` : clock;
});

// The fuse burns down over the last day before the firing.
const fuse = computed(() => {
    const left = secondsLeft.value;

    if (left === null) {
        return 4;
    }

    return Math.max(
        4,
        Math.round(((86400 - Math.min(left, 86400)) / 86400) * 100),
    );
});
</script>

<template>
    <section
        v-if="next && next.scheduled_at"
        aria-label="Next firing"
        class="flex flex-wrap items-center gap-x-10 gap-y-5 rounded-2xl bg-panel px-5 py-5 text-panel-foreground sm:px-7 sm:py-6"
        data-test="next-firing"
    >
        <div class="grid gap-1">
            <p class="text-sm text-panel-muted">
                Next firing, {{ formatDateTime(next.scheduled_at, timezone) }}
            </p>
            <p
                class="font-mono text-[2rem] leading-[1.05] font-medium tracking-[-0.02em] sm:text-[2.75rem]"
                role="timer"
            >
                {{ countdown }}
            </p>
        </div>

        <div class="grid flex-[1_1_18rem] gap-3">
            <p class="flex items-baseline gap-2.5 text-lg leading-snug">
                <span
                    class="size-2.5 shrink-0 rounded-full bg-brass"
                    aria-hidden="true"
                ></span>
                <span v-if="secondsLeft === 0">
                    Due now. It goes out at the next run of the scheduler.
                </span>
                <span v-else-if="together === 1">
                    1 post is approved and scheduled for this time.
                </span>
                <span v-else>
                    {{ together }} posts are approved and scheduled for this
                    time.
                </span>
            </p>
            <div
                class="h-2 overflow-hidden rounded-full bg-panel-track"
                aria-hidden="true"
            >
                <div
                    class="h-2 rounded-full bg-brass transition-[width] duration-1000 ease-linear motion-reduce:transition-none"
                    :style="{ width: `${fuse}%` }"
                ></div>
            </div>
        </div>
    </section>
</template>
