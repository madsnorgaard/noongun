<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { edit as brief } from '@/routes/brief';
import { index as pillars } from '@/routes/pillars';
import { create as proposeWeek } from '@/routes/proposals';
import { index as slots } from '@/routes/slots';

defineProps<{
    current: 'brief' | 'pillars' | 'slots';
    /** Owners and editors get the way on from here: proposing a week. */
    canPropose?: boolean;
}>();

const tabs = [
    { key: 'brief', title: 'Brief', href: brief() },
    { key: 'pillars', title: 'Pillars', href: pillars() },
    { key: 'slots', title: 'Slots', href: slots() },
] as const;
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <nav
            aria-label="What drafts are based on"
            class="flex w-fit max-w-full gap-1 rounded-lg bg-accent p-1"
        >
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="tab.href"
                :aria-current="tab.key === current ? 'page' : undefined"
                class="inline-flex min-h-11 items-center rounded-[10px] px-4 text-base outline-none focus-visible:ring-[3px] focus-visible:ring-ring"
                :class="
                    tab.key === current
                        ? 'bg-card font-semibold shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
            >
                {{ tab.title }}
            </Link>
        </nav>
        <Button
            v-if="canPropose"
            size="sm"
            variant="outline"
            as-child
            data-test="propose-week"
        >
            <Link :href="proposeWeek()">Propose a week</Link>
        </Button>
    </div>
</template>
