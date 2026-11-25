<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import IdeaFields from '@/components/brief/IdeaFields.vue';
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
import { webAddress } from '@/lib/posts';
import { destroy, index, store, update } from '@/routes/ideas';
import type { Idea, PillarOption } from '@/types/brief';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Ideas',
                href: index(),
            },
        ],
    },
});

defineProps<{
    ideas: Idea[];
    pillars: PillarOption[];
    limits: { title: number; source_url: number; notes: number };
    can: { manage: boolean };
}>();

type IdeaForm = {
    title: string;
    pillar_id: string;
    source_url: string;
    notes: string;
};

const empty = (): IdeaForm => ({
    title: '',
    pillar_id: '',
    source_url: '',
    notes: '',
});

const adding = useForm<IdeaForm>(empty());
const changing = useForm<IdeaForm>(empty());
const editing = ref<number | null>(null);

const toDelete = ref<Idea | null>(null);
// What the dialog shows. Kept while it closes, so its text does not change
// on the way out.
const shown = ref<Idea | null>(null);
const deleting = ref(false);

function payload(fields: IdeaForm) {
    return {
        title: fields.title,
        pillar_id: fields.pillar_id === '' ? null : Number(fields.pillar_id),
        source_url: fields.source_url.trim() === '' ? null : fields.source_url,
        notes: fields.notes.trim() === '' ? null : fields.notes,
    };
}

function add(): void {
    adding.transform(payload).post(store.url(), {
        preserveScroll: true,
        onSuccess: () => adding.reset(),
    });
}

function startEdit(idea: Idea): void {
    editing.value = idea.id;
    changing.clearErrors();
    changing.title = idea.title;
    changing.pillar_id = idea.pillar_id === null ? '' : String(idea.pillar_id);
    changing.source_url = idea.source_url ?? '';
    changing.notes = idea.notes ?? '';
}

function saveEdit(): void {
    if (editing.value === null) {
        return;
    }

    changing.transform(payload).put(update.url({ idea: editing.value }), {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

function askDelete(idea: Idea): void {
    shown.value = idea;
    toDelete.value = idea;
}

function confirmDelete(): void {
    if (!toDelete.value) {
        return;
    }

    router.delete(destroy.url({ idea: toDelete.value.id }), {
        preserveScroll: true,
        onStart: () => (deleting.value = true),
        onFinish: () => {
            deleting.value = false;
            toDelete.value = null;
        },
    });
}
</script>

<template>
    <Head title="Ideas" />

    <div class="flex flex-1 flex-col gap-5.5 px-4 pt-2 pb-12 md:px-8 lg:px-11">
        <header class="grid gap-1">
            <h1 class="text-title md:text-display">Ideas</h1>
            <p class="text-muted-foreground">
                Topics to write about. A draft can be written from an idea.
            </p>
        </header>

        <form
            v-if="can.manage"
            class="grid gap-4 rounded-xl bg-card p-5.5 shadow-xs"
            data-test="add-idea"
            @submit.prevent="add"
        >
            <h2 class="text-heading">Add an idea</h2>
            <IdeaFields
                v-model="adding"
                name="new-idea"
                :pillars="pillars"
                :limits="limits"
            />
            <Button
                type="submit"
                class="w-fit"
                :disabled="adding.processing || adding.title.trim() === ''"
            >
                <Spinner v-if="adding.processing" />
                Add idea
            </Button>
        </form>

        <section
            v-if="ideas.length === 0"
            class="rounded-xl bg-card p-5.5 shadow-xs"
            data-test="ideas-empty"
        >
            <h2 class="text-heading">There are no ideas yet</h2>
            <p class="mt-1 max-w-[72ch] text-muted-foreground">
                An idea is a topic before it becomes posts: a title, and if you
                have them a source to write from and a few notes.
                <template v-if="!can.manage">
                    An owner or editor of this workspace can add them.
                </template>
            </p>
        </section>

        <ul v-else class="grid gap-3">
            <li
                v-for="idea in ideas"
                :key="idea.id"
                class="rounded-lg bg-card px-4 py-4 shadow-xs sm:px-6"
                :data-test="`idea-${idea.id}`"
            >
                <form
                    v-if="editing === idea.id"
                    class="grid gap-4"
                    @submit.prevent="saveEdit"
                >
                    <IdeaFields
                        v-model="changing"
                        :name="`idea-${idea.id}`"
                        :pillars="pillars"
                        :limits="limits"
                    />
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="changing.processing"
                            data-test="save-idea"
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

                <div v-else class="flex flex-wrap items-start gap-x-6 gap-y-3">
                    <div class="grid min-w-0 flex-[1_1_18rem] gap-1.5">
                        <div
                            class="flex flex-wrap items-center gap-x-3 gap-y-1.5"
                        >
                            <h2 class="text-lg font-semibold break-words">
                                {{ idea.title }}
                            </h2>
                            <span
                                v-if="idea.pillar_name"
                                class="rounded-sm bg-secondary px-2.5 py-1 text-sm"
                            >
                                {{ idea.pillar_name }}
                            </span>
                        </div>
                        <!-- The address is shown as text. It becomes a link
                             only when it is a web address. -->
                        <p v-if="idea.source_url" class="text-sm">
                            <span class="text-muted-foreground">Source:</span>
                            <a
                                v-if="webAddress(idea.source_url)"
                                :href="webAddress(idea.source_url) ?? undefined"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="font-mono text-xs break-all underline underline-offset-4"
                            >
                                {{ idea.source_url }}
                            </a>
                            <span v-else class="font-mono text-xs break-all">
                                {{ idea.source_url }}
                            </span>
                        </p>
                        <p
                            v-if="idea.notes"
                            class="max-w-[72ch] text-base break-words whitespace-pre-line"
                        >
                            {{ idea.notes }}
                        </p>
                    </div>

                    <div
                        v-if="can.manage"
                        class="flex shrink-0 flex-wrap justify-end gap-2 max-sm:w-full"
                    >
                        <Button
                            size="sm"
                            variant="outline"
                            :aria-label="`Edit ${idea.title}`"
                            @click="startEdit(idea)"
                        >
                            Edit
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            class="text-destructive hover:text-destructive"
                            :aria-label="`Delete ${idea.title}`"
                            :data-test="`delete-idea-${idea.id}`"
                            @click="askDelete(idea)"
                        >
                            Delete
                        </Button>
                    </div>
                </div>
            </li>
        </ul>

        <p
            v-if="!can.manage && ideas.length"
            class="text-sm text-muted-foreground"
        >
            Owners and editors of this workspace can add, change and delete
            ideas.
        </p>
    </div>

    <Dialog
        :open="toDelete !== null"
        @update:open="(open: boolean) => !open && (toDelete = null)"
    >
        <DialogContent>
            <DialogHeader class="space-y-3">
                <DialogTitle>Delete this idea?</DialogTitle>
                <DialogDescription class="text-base" data-test="consequence">
                    "{{ shown?.title }}" is removed for good.
                    <template v-if="shown && shown.posts_count > 0">
                        {{ shown.posts_count }}
                        {{
                            shown.posts_count === 1
                                ? 'post was written from it. It is kept, without the link to this idea.'
                                : 'posts were written from it. They are kept, without the link to this idea.'
                        }}
                    </template>
                    <template v-else>
                        No post was written from it, so nothing else changes.
                    </template>
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
                    Delete idea
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
