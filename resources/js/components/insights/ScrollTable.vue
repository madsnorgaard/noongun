<script setup lang="ts">
/**
 * The frame of a table of numbers that may be wider than the screen. It
 * scrolls sideways by itself, so the page does not, and it can be reached
 * and scrolled with the keyboard.
 */
defineProps<{
    /** Names the table for a screen reader, as its caption does. */
    label: string;
}>();
</script>

<template>
    <div
        class="overflow-x-auto rounded-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        tabindex="0"
        role="region"
        :aria-label="label"
    >
        <slot />
    </div>
</template>
