<script setup lang="ts">
import { computed } from 'vue';
import { formatDateTime, useWorkspaceTimezone } from '@/lib/posts';

const props = defineProps<{
    at: string | null;
    by: string | null;
}>();

const timezone = useWorkspaceTimezone();

const lead = computed(() => {
    const who = props.by ? `Saved by ${props.by}` : 'Saved';

    return props.at ? `${who} on` : who;
});
</script>

<template>
    <p class="text-sm text-muted-foreground" data-test="brief-saved">
        {{ lead }}
        <span v-if="at" class="font-mono">{{
            formatDateTime(at, timezone)
        }}</span>
    </p>
</template>
