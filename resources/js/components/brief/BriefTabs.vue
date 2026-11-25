<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { edit as brief } from '@/routes/brief';
import { index as pillars } from '@/routes/pillars';
import { index as slots } from '@/routes/slots';

defineProps<{
    current: 'brief' | 'pillars' | 'slots';
}>();

const tabs = [
    { key: 'brief', title: 'Brief', href: brief() },
    { key: 'pillars', title: 'Pillars', href: pillars() },
    { key: 'slots', title: 'Slots', href: slots() },
] as const;
</script>

<template>
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
</template>
