<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const name = usePage().props.name;
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center bg-background px-4 py-8 md:p-10"
    >
        <div class="flex w-full max-w-md flex-col gap-6">
            <Link
                :href="home()"
                class="flex min-h-11 items-center gap-2.5 self-center"
            >
                <span
                    class="flex size-9 items-center justify-center rounded-[10px] bg-signal text-signal-foreground"
                >
                    <AppLogoIcon class="size-[25px]" />
                </span>
                <span class="text-xl font-bold tracking-[-0.02em]">
                    {{ name }}
                </span>
            </Link>

            <div class="rounded-2xl bg-card p-6 shadow-xs sm:p-8">
                <div class="mb-6 grid gap-1.5">
                    <h1 class="text-title">{{ title }}</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>

            <p class="text-center text-sm text-muted-foreground">
                On time, every time.
            </p>
        </div>
    </div>
</template>
