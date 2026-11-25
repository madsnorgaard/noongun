<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const name = usePage().props.name;
</script>

<template>
    <div
        class="flex size-[34px] shrink-0 items-center justify-center rounded-[9px] bg-sidebar-primary text-sidebar-primary-foreground"
    >
        <AppLogoIcon class="size-6" />
    </div>
    <span class="truncate text-xl leading-tight font-bold tracking-[-0.02em]">
        {{ name }}
    </span>
</template>
