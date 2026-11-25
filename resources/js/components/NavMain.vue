<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
}>();

// An entry is also the current one on the pages under it, for example
// Brief on /brief/pillars.
const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <nav aria-label="Main">
            <SidebarMenu class="gap-1.5">
                <SidebarMenuItem v-for="item in items" :key="item.title">
                    <SidebarMenuButton
                        as-child
                        :is-active="isCurrentOrParentUrl(item.href)"
                        class="h-11 rounded-[10px] px-3 text-base data-[active=true]:bg-card data-[active=true]:font-semibold data-[active=true]:shadow-xs"
                    >
                        <Link
                            :href="item.href"
                            :aria-current="
                                isCurrentOrParentUrl(item.href)
                                    ? 'page'
                                    : undefined
                            "
                        >
                            <span>{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </nav>
    </SidebarGroup>
</template>
