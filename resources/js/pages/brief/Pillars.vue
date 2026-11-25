<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BriefTabs from '@/components/brief/BriefTabs.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit as brief } from '@/routes/brief';
import { destroy, index, store, update } from '@/routes/pillars';
import type { Pillar } from '@/types/brief';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Brief',
                href: brief(),
            },
            {
                title: 'Pillars',
                href: index(),
            },
        ],
    },
});

const props = defineProps<{
    pillars: Pillar[];
    limits: { name: number; description: number; weekly_target: number };
    can: { manage: boolean };
}>();

type PillarFields = {
    name: string;
    description: string;
    weekly_target: number;
};

const adding = useForm<PillarFields>({
    name: '',
    description: '',
    weekly_target: 1,
});

const editing = ref<number | null>(null);
const changing = useForm<PillarFields>({
    name: '',
    description: '',
    weekly_target: 0,
});

const toDelete = ref<Pillar | null>(null);
// What the dialog shows. Kept while it closes, so its text does not change
// on the way out.
const shown = ref<Pillar | null>(null);
const deleting = ref(false);

const weeklyTotal = computed(() =>
    props.pillars.reduce((sum, pillar) => sum + pillar.weekly_target, 0),
);

function add(): void {
    adding.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => adding.reset(),
    });
}

function startEdit(pillar: Pillar): void {
    editing.value = pillar.id;
    changing.clearErrors();
    changing.name = pillar.name;
    changing.description = pillar.description ?? '';
    changing.weekly_target = pillar.weekly_target;
}

function saveEdit(): void {
    if (editing.value === null) {
        return;
    }

    changing.put(update.url({ pillar: editing.value }), {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

function askDelete(pillar: Pillar): void {
    shown.value = pillar;
    toDelete.value = pillar;
}

function confirmDelete(): void {
    if (!toDelete.value) {
        return;
    }

    router.delete(destroy.url({ pillar: toDelete.value.id }), {
        preserveScroll: true,
        onStart: () => (deleting.value = true),
        onFinish: () => {
            deleting.value = false;
            toDelete.value = null;
        },
    });
}

function count(number: number, one: string, many: string): string {
    return `${number} ${number === 1 ? one : many}`;
}

// What happens to the things that point at the pillar, said before it goes.
const consequence = computed(() => {
    const pillar = shown.value;

    if (!pillar) {
        return '';
    }

    const kept = [
        pillar.ideas_count > 0
            ? count(pillar.ideas_count, 'idea', 'ideas')
            : null,
        pillar.posts_count > 0
            ? count(pillar.posts_count, 'post', 'posts')
            : null,
    ].filter((part): part is string => part !== null);

    if (kept.length === 0) {
        return 'No idea or post belongs to it, so nothing else changes.';
    }

    const single = pillar.ideas_count + pillar.posts_count === 1;

    return `${kept.join(' and ')} ${single ? 'belongs' : 'belong'} to it. ${single ? 'It is' : 'They are'} kept, and ${single ? 'is' : 'are'} left without a pillar.`;
});
</script>

<template>
    <Head title="Pillars" />

    <div class="flex flex-1 flex-col gap-5.5 px-4 pt-2 pb-12 md:px-8 lg:px-11">
        <header class="grid gap-4">
            <div class="grid gap-1">
                <h1 class="text-title md:text-display">Pillars</h1>
                <p class="text-muted-foreground">
                    The themes you write about, and how many posts a week each
                    one gets when a week is proposed.
                </p>
            </div>
            <BriefTabs current="pillars" :can-propose="can.manage" />
        </header>

        <section
            v-if="pillars.length === 0"
            class="rounded-xl bg-card p-5.5 shadow-xs"
            data-test="pillars-empty"
        >
            <h2 class="text-heading">There are no pillars yet</h2>
            <p class="mt-1 max-w-[72ch] text-muted-foreground">
                A pillar is a theme you come back to, for example "Behind the
                scenes" or "What customers ask". Give each one a number of posts
                per week, and a proposed week follows that mix.
                <template v-if="!can.manage">
                    An owner or editor of this workspace can add them.
                </template>
            </p>
        </section>

        <template v-else>
            <p class="text-base" data-test="pillars-total">
                <span class="font-mono font-medium">{{ weeklyTotal }}</span>
                {{ weeklyTotal === 1 ? 'post' : 'posts' }} a week across
                <span class="font-mono font-medium">{{ pillars.length }}</span>
                {{ pillars.length === 1 ? 'pillar' : 'pillars' }}.
            </p>

            <ul class="grid gap-3">
                <li
                    v-for="pillar in pillars"
                    :key="pillar.id"
                    class="rounded-lg bg-card px-4 py-4 shadow-xs sm:px-6"
                    :data-test="`pillar-${pillar.id}`"
                >
                    <form
                        v-if="editing === pillar.id"
                        class="grid gap-4"
                        @submit.prevent="saveEdit"
                    >
                        <div
                            class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_10rem]"
                        >
                            <div class="grid gap-1.5">
                                <Label :for="`pillar-name-${pillar.id}`">
                                    Name
                                </Label>
                                <Input
                                    :id="`pillar-name-${pillar.id}`"
                                    v-model="changing.name"
                                    :maxlength="limits.name"
                                />
                                <InputError :message="changing.errors.name" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label :for="`pillar-target-${pillar.id}`">
                                    Posts per week
                                </Label>
                                <Input
                                    :id="`pillar-target-${pillar.id}`"
                                    v-model.number="changing.weekly_target"
                                    type="number"
                                    inputmode="numeric"
                                    min="0"
                                    :max="limits.weekly_target"
                                    step="1"
                                    class="font-mono"
                                />
                                <InputError
                                    :message="changing.errors.weekly_target"
                                />
                            </div>
                        </div>
                        <div class="grid gap-1.5">
                            <Label :for="`pillar-description-${pillar.id}`">
                                Description (optional)
                            </Label>
                            <textarea
                                :id="`pillar-description-${pillar.id}`"
                                v-model="changing.description"
                                rows="3"
                                :maxlength="limits.description"
                                class="w-full resize-y rounded-lg border border-input bg-card p-3.5 text-base leading-normal outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            ></textarea>
                            <InputError
                                :message="changing.errors.description"
                            />
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="changing.processing"
                                data-test="save-pillar"
                            >
                                <Spinner v-if="changing.processing" />
                                Save
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                class="text-muted-foreground"
                                @click="editing = null"
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>

                    <div
                        v-else
                        class="flex flex-wrap items-start gap-x-6 gap-y-3"
                    >
                        <div class="grid min-w-0 flex-[1_1_18rem] gap-1">
                            <h2 class="text-lg font-semibold break-words">
                                {{ pillar.name }}
                            </h2>
                            <p
                                v-if="pillar.description"
                                class="max-w-[72ch] text-base break-words whitespace-pre-line"
                            >
                                {{ pillar.description }}
                            </p>
                        </div>

                        <dl class="grid shrink-0 gap-0.5 text-sm sm:w-36">
                            <dt class="text-muted-foreground">Per week</dt>
                            <dd class="text-base">
                                <template v-if="pillar.weekly_target === 0">
                                    None planned
                                </template>
                                <template v-else>
                                    <span class="font-mono font-medium">{{
                                        pillar.weekly_target
                                    }}</span>
                                    {{
                                        pillar.weekly_target === 1
                                            ? 'post'
                                            : 'posts'
                                    }}
                                </template>
                            </dd>
                        </dl>

                        <div
                            v-if="can.manage"
                            class="flex shrink-0 flex-wrap justify-end gap-2 max-sm:w-full"
                        >
                            <Button
                                size="sm"
                                variant="outline"
                                :aria-label="`Edit ${pillar.name}`"
                                @click="startEdit(pillar)"
                            >
                                Edit
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                class="text-destructive hover:text-destructive"
                                :aria-label="`Delete ${pillar.name}`"
                                :data-test="`delete-pillar-${pillar.id}`"
                                @click="askDelete(pillar)"
                            >
                                Delete
                            </Button>
                        </div>
                    </div>
                </li>
            </ul>
        </template>

        <form
            v-if="can.manage"
            class="grid gap-4 rounded-xl bg-card p-5.5 shadow-xs"
            data-test="add-pillar"
            @submit.prevent="add"
        >
            <h2 class="text-heading">Add a pillar</h2>
            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_10rem]">
                <div class="grid gap-1.5">
                    <Label for="new-pillar-name">Name</Label>
                    <Input
                        id="new-pillar-name"
                        v-model="adding.name"
                        :maxlength="limits.name"
                        autocomplete="off"
                    />
                    <InputError :message="adding.errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="new-pillar-target">Posts per week</Label>
                    <Input
                        id="new-pillar-target"
                        v-model.number="adding.weekly_target"
                        type="number"
                        inputmode="numeric"
                        min="0"
                        :max="limits.weekly_target"
                        step="1"
                        class="font-mono"
                    />
                    <InputError :message="adding.errors.weekly_target" />
                </div>
            </div>
            <div class="grid gap-1.5">
                <Label for="new-pillar-description">
                    Description (optional)
                </Label>
                <textarea
                    id="new-pillar-description"
                    v-model="adding.description"
                    rows="3"
                    :maxlength="limits.description"
                    aria-describedby="new-pillar-description-hint"
                    class="w-full resize-y rounded-lg border border-input bg-card p-3.5 text-base leading-normal outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                ></textarea>
                <p
                    id="new-pillar-description-hint"
                    class="text-sm text-muted-foreground"
                >
                    What belongs under this theme. A draft for the pillar is
                    given this text.
                </p>
                <InputError :message="adding.errors.description" />
            </div>
            <Button
                type="submit"
                class="w-fit"
                :disabled="adding.processing || adding.name.trim() === ''"
            >
                <Spinner v-if="adding.processing" />
                Add pillar
            </Button>
        </form>

        <p v-else-if="pillars.length" class="text-sm text-muted-foreground">
            Owners and editors of this workspace can add, change and delete
            pillars.
        </p>
    </div>

    <Dialog
        :open="toDelete !== null"
        @update:open="(open: boolean) => !open && (toDelete = null)"
    >
        <DialogContent>
            <DialogHeader class="space-y-3">
                <DialogTitle>Delete {{ shown?.name }}?</DialogTitle>
                <DialogDescription class="text-base" data-test="consequence">
                    The pillar is removed for good, and a proposed week no
                    longer plans posts for it. {{ consequence }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="toDelete = null">
                    Cancel
                </Button>
                <Button
                    variant="destructive"
                    :disabled="deleting"
                    data-test="confirm-delete"
                    @click="confirmDelete"
                >
                    <Spinner v-if="deleting" />
                    Delete pillar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
