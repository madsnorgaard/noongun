<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { formatDate, stateLabels, useWorkspaceTimezone } from '@/lib/posts';
import { stateDots } from '@/lib/postStates';
import { index } from '@/routes/calendar';
import { create, show } from '@/routes/posts';
import type { CalendarDay, CalendarEntry } from '@/types/posts';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Calendar',
                href: index(),
            },
        ],
    },
});

const props = defineProps<{
    view: 'week' | 'month';
    period: string;
    previous: string;
    next: string;
    today: string;
    days: CalendarDay[];
    can: { manage: boolean };
}>();

const timezone = useWorkspaceTimezone();

const title = computed(() => {
    if (props.view === 'month') {
        return formatDate(props.period, { month: 'long', year: 'numeric' });
    }

    const last = props.days[props.days.length - 1]?.date ?? props.period;

    return `${formatDate(props.period, { day: 'numeric', month: 'long' })} - ${formatDate(last, { day: 'numeric', month: 'long', year: 'numeric' })}`;
});

// Below the md width a month lists only the days that have posts, so a
// month without any would be an empty box.
const emptyMonth = computed(
    () =>
        props.view === 'month' &&
        props.days.every((day) => day.posts.length === 0),
);

const weekdays = computed(() =>
    props.days
        .slice(0, 7)
        .map((day) => formatDate(day.date, { weekday: 'short' })),
);

function at(date: string, view: 'week' | 'month' = props.view) {
    return index({ query: { view, date } });
}

function dayLabel(day: CalendarDay): string {
    return props.view === 'week'
        ? formatDate(day.date, { weekday: 'short', day: 'numeric' })
        : formatDate(day.date, { day: 'numeric' });
}

function fullDate(day: CalendarDay): string {
    return formatDate(day.date, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });
}

// A draft or an approved post has a planned time only. It is drawn with a
// dashed outline, so that a plan is not read as something that will go out.
function isPlan(entry: CalendarEntry): boolean {
    return entry.state === 'draft' || entry.state === 'approved';
}
</script>

<template>
    <Head title="Calendar" />

    <div class="flex flex-1 flex-col gap-5.5 px-4 pt-2 pb-12 md:px-8 lg:px-11">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="grid gap-1">
                <h1
                    class="text-title md:text-display"
                    data-test="calendar-title"
                >
                    {{ title }}
                </h1>
                <p class="text-muted-foreground">Times in {{ timezone }}.</p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <div class="flex gap-1.5 rounded-lg bg-accent p-1">
                    <Link
                        :href="at(period, 'week')"
                        class="flex min-h-11 items-center rounded-[10px] px-4 text-base font-semibold"
                        :class="
                            view === 'week'
                                ? 'bg-card shadow-xs'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        :aria-current="view === 'week' ? 'page' : undefined"
                    >
                        Week
                    </Link>
                    <Link
                        :href="at(period, 'month')"
                        class="flex min-h-11 items-center rounded-[10px] px-4 text-base font-semibold"
                        :class="
                            view === 'month'
                                ? 'bg-card shadow-xs'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        :aria-current="view === 'month' ? 'page' : undefined"
                        data-test="calendar-month"
                    >
                        Month
                    </Link>
                </div>

                <div class="flex items-center gap-1.5">
                    <Button size="icon-sm" variant="outline" as-child>
                        <Link
                            :href="at(previous)"
                            :aria-label="`Previous ${view}`"
                            data-test="calendar-previous"
                        >
                            <ChevronLeft />
                        </Link>
                    </Button>
                    <Button size="sm" variant="outline" as-child>
                        <Link :href="at(today)">Today</Link>
                    </Button>
                    <Button size="icon-sm" variant="outline" as-child>
                        <Link
                            :href="at(next)"
                            :aria-label="`Next ${view}`"
                            data-test="calendar-next"
                        >
                            <ChevronRight />
                        </Link>
                    </Button>
                </div>

                <Button v-if="can.manage" size="sm" as-child>
                    <Link :href="create()">New post</Link>
                </Button>
            </div>
        </header>

        <p
            v-if="emptyMonth"
            class="text-muted-foreground md:hidden"
            data-test="calendar-empty"
        >
            No posts this month.
        </p>

        <div
            class="overflow-hidden rounded-xl bg-card text-sm shadow-xs"
            :class="emptyMonth ? 'hidden md:block' : ''"
        >
            <div
                v-if="view === 'month'"
                class="hidden grid-cols-7 border-b text-muted-foreground md:grid"
            >
                <div
                    v-for="weekday in weekdays"
                    :key="weekday"
                    class="border-l px-3 py-2 first:border-l-0"
                >
                    {{ weekday }}
                </div>
            </div>

            <div class="-mb-px grid grid-cols-1 md:grid-cols-7">
                <section
                    v-for="day in days"
                    :key="day.date"
                    class="border-b md:border-l md:[&:nth-child(7n+1)]:border-l-0"
                    :class="[
                        view === 'week' ? 'md:min-h-96' : 'md:min-h-32',
                        view === 'month' && day.posts.length === 0
                            ? 'hidden md:block'
                            : '',
                        day.in_period ? '' : 'bg-muted/60',
                    ]"
                    :aria-label="fullDate(day)"
                    :data-test="`day-${day.date}`"
                >
                    <div class="flex items-center justify-between gap-1 pl-3">
                        <!-- Today carries the gunmetal pill. Brass alone
                             would not be seen on Paper. -->
                        <h2
                            :class="
                                day.today
                                    ? 'rounded-sm bg-primary px-2 py-0.5 font-semibold text-primary-foreground'
                                    : day.in_period
                                      ? 'text-foreground'
                                      : 'text-muted-foreground'
                            "
                        >
                            <span class="md:hidden">{{ fullDate(day) }}</span>
                            <span class="hidden md:inline">
                                {{ dayLabel(day) }}
                            </span>
                        </h2>
                        <Link
                            v-if="can.manage"
                            :href="create({ query: { date: day.date } })"
                            class="flex size-11 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                            :aria-label="`New post on ${fullDate(day)}`"
                        >
                            <Plus class="size-4" />
                        </Link>
                        <span v-else class="h-11"></span>
                    </div>

                    <ul class="grid gap-1.5 px-2 pb-2">
                        <li v-for="entry in day.posts" :key="entry.id">
                            <Link
                                :href="show({ post: entry.id })"
                                class="block min-h-11 rounded-sm border px-2 py-1.5 hover:bg-accent/60"
                                :class="
                                    isPlan(entry)
                                        ? 'border-dashed border-input'
                                        : entry.state === 'failed'
                                          ? 'border-destructive'
                                          : 'border-transparent bg-secondary'
                                "
                                :data-test="`entry-${entry.id}`"
                            >
                                <span
                                    class="flex flex-wrap items-center gap-x-2"
                                >
                                    <span class="font-mono font-medium">
                                        {{ entry.time }}
                                    </span>
                                    <span
                                        class="flex items-center gap-1.5 text-xs"
                                        :class="
                                            entry.state === 'failed'
                                                ? 'font-semibold text-destructive'
                                                : 'text-muted-foreground'
                                        "
                                    >
                                        <span
                                            class="size-2 shrink-0"
                                            :class="stateDots[entry.state]"
                                            aria-hidden="true"
                                        ></span>
                                        {{
                                            isPlan(entry)
                                                ? `${stateLabels[entry.state]}, planned`
                                                : stateLabels[entry.state]
                                        }}
                                    </span>
                                </span>
                                <span
                                    class="block break-words"
                                    :class="
                                        view === 'week'
                                            ? 'line-clamp-3'
                                            : 'line-clamp-1'
                                    "
                                >
                                    {{ entry.excerpt || 'No text yet' }}
                                </span>
                                <span
                                    v-if="
                                        view === 'week' && entry.channels.length
                                    "
                                    class="block truncate text-xs text-muted-foreground"
                                >
                                    {{ entry.channels.join(', ') }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </div>
</template>
