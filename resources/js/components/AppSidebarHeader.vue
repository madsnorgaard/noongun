<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const name = usePage().props.name;
</script>

<template>
    <header
        class="flex min-h-14 shrink-0 items-center gap-2 px-2 pt-2 md:px-6 lg:px-9"
    >
        <SidebarTrigger />

        <!-- On a phone the sidebar is closed, so the mark sits here. -->
        <Link
            :href="dashboard()"
            class="flex min-h-11 items-center gap-2.5 md:hidden"
        >
            <span
                class="flex size-9 items-center justify-center rounded-[10px] bg-signal text-signal-foreground"
            >
                <AppLogoIcon class="size-[25px]" />
            </span>
            <span class="text-xl font-bold tracking-[-0.02em]">{{ name }}</span>
        </Link>

        <div
            v-if="breadcrumbs && breadcrumbs.length > 0"
            class="hidden md:block"
        >
            <Breadcrumbs :breadcrumbs="breadcrumbs" />
        </div>
    </header>
</template>
