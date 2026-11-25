<script setup lang="ts">
const model = defineModel<string>();
</script>

<template>
    <select
        v-model="model"
        class="h-[46px] w-full min-w-0 rounded-md border border-input bg-card px-3.5 py-1 text-base outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive"
    >
        <slot />
    </select>
</template>
