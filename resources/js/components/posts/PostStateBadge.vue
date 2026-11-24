<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { stateLabels } from '@/lib/posts';
import type { PostState } from '@/types/posts';

defineProps<{
    state: PostState;
}>();

const variants: Record<
    PostState,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    draft: 'outline',
    approved: 'secondary',
    scheduled: 'secondary',
    publishing: 'secondary',
    published: 'outline',
    failed: 'destructive',
    rejected: 'outline',
};
</script>

<template>
    <Badge :variant="variants[state]" :data-test="`state-${state}`">
        {{ stateLabels[state] }}
    </Badge>
</template>
