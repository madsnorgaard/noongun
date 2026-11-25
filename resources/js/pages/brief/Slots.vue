<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BriefTabs from '@/components/brief/BriefTabs.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, useWorkspaceTimezone } from '@/lib/posts';
import { edit as brief } from '@/routes/brief';
import { index as channelsIndex } from '@/routes/channels';
import { destroy, index, store } from '@/routes/slots';
import type { Slot, SlotChannel } from '@/types/brief';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Brief',
                href: brief(),
            },
            {
                title: 'Slots',
                href: index(),
            },
        ],
    },
});

const props = defineProps<{
    channels: SlotChannel[];
    slots: Slot[];
    can: { manage: boolean };
}>();

const WEEKDAYS = [
    { number: 1, name: 'Monday', short: 'Mon' },
    { number: 2, name: 'Tuesday', short: 'Tue' },
    { number: 3, name: 'Wednesday', short: 'Wed' },
    { number: 4, name: 'Thursday', short: 'Thu' },
    { number: 5, name: 'Friday', short: 'Fri' },
    { number: 6, name: 'Saturday', short: 'Sat' },
    { number: 7, name: 'Sunday', short: 'Sun' },
] as const;

const timezone = useWorkspaceTimezone();

const offered = computed(() =>
    props.channels.filter((channel) => channel.offered),
);

const form = useForm<{ channel_id: string; weekdays: number[]; time: string }>({
    // With a single channel there is nothing to choose. No day is ticked
    // for the user.
    channel_id: offered.value.length === 1 ? String(offered.value[0].id) : '',
    weekdays: [],
    time: '',
});

const removing = ref<number | null>(null);

function slotsOf(channel: SlotChannel): Slot[] {
    return props.slots.filter((slot) => slot.channel_id === channel.id);
}

function weekday(number: number): string {
    return WEEKDAYS.find((day) => day.number === number)?.name ?? '';
}

function toggleDay(number: number, chosen: boolean): void {
    form.weekdays = chosen
        ? [...form.weekdays.filter((day) => day !== number), number]
        : form.weekdays.filter((day) => day !== number);
}

function fieldError(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}

const dayError = computed(
    () =>
        fieldError('weekdays') ??
        Object.entries(form.errors as Record<string, string | undefined>).find(
            ([key]) => key.startsWith('weekdays.'),
        )?.[1],
);

function add(): void {
    form.transform((fields) => ({
        channel_id: fields.channel_id === '' ? null : Number(fields.channel_id),
        weekdays: [...fields.weekdays].sort(),
        time: fields.time,
    })).post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.weekdays = [];
            form.time = '';
        },
    });
}

function remove(slot: Slot): void {
    if (removing.value !== null) {
        return;
    }

    router.delete(destroy.url({ slot: slot.id }), {
        preserveScroll: true,
        onStart: () => (removing.value = slot.id),
        onFinish: () => (removing.value = null),
    });
}
</script>

<template>
    <Head title="Slots" />

    <div class="flex flex-1 flex-col gap-5.5 px-4 pt-2 pb-12 md:px-8 lg:px-11">
        <header class="grid gap-4">
            <div class="grid gap-1">
                <h1 class="text-title md:text-display">Slots</h1>
                <p class="text-muted-foreground">
                    The times you prefer to post on each channel. Times in
                    {{ timezone }}.
                </p>
            </div>
            <BriefTabs current="slots" />
        </header>

        <p class="max-w-[72ch] text-base">
            A slot proposes a firing time for a draft. It schedules nothing: a
            post fires only after you approve it.
        </p>

        <section
            v-if="channels.length === 0"
            class="rounded-xl bg-card p-5.5 shadow-xs"
            data-test="slots-no-channels"
        >
            <h2 class="text-heading">No channel is connected</h2>
            <p class="mt-1 max-w-[72ch] text-muted-foreground">
                A slot belongs to a channel. Connect one first.
            </p>
            <Button class="mt-4" variant="outline" as-child>
                <Link :href="channelsIndex()">Go to Channels</Link>
            </Button>
        </section>

        <section
            v-for="channel in channels"
            :key="channel.id"
            class="grid gap-3 rounded-xl bg-card p-5.5 shadow-xs"
            :data-test="`slots-channel-${channel.id}`"
        >
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <h2 class="text-heading break-words">
                    {{ channel.display_name }}
                </h2>
                <span class="rounded-sm bg-secondary px-2.5 py-1 text-sm">
                    {{ channel.platform_label }}
                </span>
            </div>

            <p v-if="!channel.offered" class="max-w-[72ch] text-base">
                This channel is not connected. Its slots are kept, and no slot
                can be added until it is connected again.
            </p>
            <p
                v-else-if="!channel.can_publish"
                class="max-w-[72ch] text-base text-warning"
            >
                This channel must be connected again before anything can be
                published to it.
            </p>

            <p
                v-if="slotsOf(channel).length === 0"
                class="text-muted-foreground"
            >
                No slots yet.
            </p>

            <ul v-else class="grid divide-y divide-border">
                <li
                    v-for="slot in slotsOf(channel)"
                    :key="slot.id"
                    class="flex flex-wrap items-center gap-x-6 gap-y-1 py-2"
                    :data-test="`slot-${slot.id}`"
                >
                    <span class="flex-[1_1_12rem] text-base">
                        {{ weekday(slot.weekday) }}
                        <span class="font-mono font-medium">{{
                            slot.time
                        }}</span>
                    </span>
                    <span
                        class="flex-[2_1_16rem] text-sm text-muted-foreground"
                    >
                        Next:
                        <span class="font-mono text-xs">{{
                            formatDateTime(slot.next_at, timezone)
                        }}</span>
                    </span>
                    <Button
                        v-if="can.manage"
                        size="sm"
                        variant="ghost"
                        class="text-muted-foreground"
                        :disabled="removing !== null"
                        :aria-label="`Remove ${weekday(slot.weekday)} ${slot.time} from ${channel.display_name}`"
                        @click="remove(slot)"
                    >
                        <Spinner v-if="removing === slot.id" />
                        Remove
                    </Button>
                </li>
            </ul>
        </section>

        <form
            v-if="can.manage && offered.length"
            class="grid gap-4 rounded-xl bg-card p-5.5 shadow-xs"
            data-test="add-slot"
            @submit.prevent="add"
        >
            <h2 class="text-heading">Add slots</h2>

            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_12rem]">
                <div class="grid gap-1.5">
                    <Label for="slot-channel">Channel</Label>
                    <NativeSelect id="slot-channel" v-model="form.channel_id">
                        <option value="" disabled>Choose a channel</option>
                        <option
                            v-for="channel in offered"
                            :key="channel.id"
                            :value="String(channel.id)"
                        >
                            {{ channel.display_name }} -
                            {{ channel.platform_label }}
                        </option>
                    </NativeSelect>
                    <InputError :message="fieldError('channel_id')" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="slot-time">Time, {{ timezone }}</Label>
                    <Input
                        id="slot-time"
                        v-model="form.time"
                        type="time"
                        step="60"
                        class="font-mono"
                    />
                    <InputError :message="fieldError('time')" />
                </div>
            </div>

            <fieldset class="grid min-w-0 gap-1.5">
                <legend class="text-sm text-muted-foreground">Days</legend>
                <div class="flex flex-wrap gap-x-2 gap-y-1">
                    <label
                        v-for="day in WEEKDAYS"
                        :key="day.number"
                        class="flex min-h-11 min-w-11 cursor-pointer items-center gap-2.5 rounded-md pr-3 hover:bg-accent/50"
                    >
                        <input
                            type="checkbox"
                            class="size-5 shrink-0 accent-primary"
                            :checked="form.weekdays.includes(day.number)"
                            :data-test="`day-${day.number}`"
                            @change="
                                toggleDay(
                                    day.number,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                        <span>{{ day.name }}</span>
                    </label>
                </div>
                <InputError :message="dayError" />
            </fieldset>

            <Button
                type="submit"
                class="w-fit"
                :disabled="
                    form.processing ||
                    form.channel_id === '' ||
                    form.time === '' ||
                    form.weekdays.length === 0
                "
            >
                <Spinner v-if="form.processing" />
                {{ form.weekdays.length > 1 ? 'Add slots' : 'Add slot' }}
            </Button>
        </form>

        <p
            v-if="!can.manage && channels.length"
            class="text-sm text-muted-foreground"
        >
            Owners and editors of this workspace can add and remove slots.
        </p>
    </div>
</template>
