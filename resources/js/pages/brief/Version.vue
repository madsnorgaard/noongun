<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft } from '@lucide/vue';
import BriefText from '@/components/brief/BriefText.vue';
import SavedLine from '@/components/brief/SavedLine.vue';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/brief';
import type { Brief, BriefPlatform } from '@/types/brief';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Brief',
                href: edit(),
            },
            {
                title: 'Version',
                href: '#',
            },
        ],
    },
});

defineProps<{
    brief: Brief;
    platforms: BriefPlatform[];
}>();
</script>

<template>
    <Head :title="`Brief, version ${brief.version}`" />

    <div class="flex flex-1 flex-col gap-5.5 px-4 pt-2 pb-12 md:px-8 lg:px-11">
        <header class="flex flex-wrap items-center gap-3.5">
            <Button size="sm" variant="outline" as-child>
                <Link :href="edit()" aria-label="Back to the brief">
                    <ChevronLeft />
                    Brief
                </Link>
            </Button>
            <h1 class="text-title">
                Version <span class="font-mono">{{ brief.version }}</span>
            </h1>
        </header>

        <div
            class="grid items-start gap-5.5 lg:grid-cols-[minmax(0,1fr)_22rem]"
        >
            <BriefText :brief="brief" :platforms="platforms" />

            <aside class="grid content-start gap-4">
                <section
                    class="grid gap-2 rounded-xl bg-card p-5.5 shadow-xs"
                    data-test="version-status"
                >
                    <h2 class="text-sm text-muted-foreground">Status</h2>
                    <p v-if="brief.is_current">
                        This is the current version. Drafts are written from it.
                    </p>
                    <p v-else>
                        An earlier version, kept as it was saved. Drafts that
                        were written from it still point to it.
                    </p>
                    <SavedLine :at="brief.saved_at" :by="brief.saved_by" />
                    <p
                        v-if="!brief.is_current"
                        class="text-sm text-muted-foreground"
                    >
                        A version cannot be changed or restored. To go back to
                        this one, copy its text into the brief and save it as a
                        new version.
                    </p>
                </section>
            </aside>
        </div>
    </div>
</template>
