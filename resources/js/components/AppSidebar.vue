<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as calendar } from '@/routes/calendar';
import { index as channels } from '@/routes/channels';
import { index as queue } from '@/routes/queue';
import type { NavItem } from '@/types';

// Words only, as in the guide. The sidebar slides away as a whole instead
// of shrinking to a rail of icons.
const mainNavItems: NavItem[] = [
    {
        title: 'Queue',
        href: queue(),
    },
    {
        title: 'Calendar',
        href: calendar(),
    },
    {
        title: 'Channels',
        href: channels(),
    },
    {
        title: 'Dashboard',
        href: dashboard(),
    },
];
</script>

<template>
    <Sidebar collapsible="offcanvas" variant="sidebar">
        <SidebarHeader class="px-4 pt-6 pb-3">
            <Link
                :href="dashboard()"
                class="flex min-h-11 items-center gap-2.5 rounded-[10px] px-2.5"
            >
                <AppLogo />
            </Link>
        </SidebarHeader>

        <SidebarContent class="px-2">
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter class="p-4">
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
