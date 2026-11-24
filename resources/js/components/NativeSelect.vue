<script setup lang="ts">
const model = defineModel<string>();
</script>

<template>
    <select
        v-model="model"
        class="h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
    >
        <slot />
    </select>
</template>
