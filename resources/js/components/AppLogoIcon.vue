<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
    /** Colour of the shot. It follows the dial unless one is given. */
    shot?: string;
};

withDefaults(defineProps<Props>(), {
    shot: 'currentColor',
});
</script>

<!-- The Noon Gun mark: a clock with both hands at twelve and one shot
     leaving the dial. It is drawn in the current text colour. -->
<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 64 64"
        fill="none"
        aria-hidden="true"
        :class="className"
        v-bind="$attrs"
    >
        <circle
            cx="32"
            cy="36"
            r="18"
            stroke="currentColor"
            stroke-width="5"
            stroke-linecap="round"
            stroke-dasharray="97.1 16"
            stroke-dashoffset="-8"
            transform="rotate(-90 32 36)"
        />
        <line
            x1="32"
            y1="36"
            x2="32"
            y2="22"
            stroke="currentColor"
            stroke-width="5"
            stroke-linecap="round"
        />
        <circle cx="32" cy="9" r="4.5" :fill="shot" />
    </svg>
</template>
