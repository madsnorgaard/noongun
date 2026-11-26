<script setup lang="ts">
import type { ConnectReport } from '@/types/channels';

defineProps<{
    report: ConnectReport;
}>();
</script>

<template>
    <div class="grid gap-3" data-test="connect-report">
        <section
            v-if="report.skipped.length"
            class="grid gap-1 rounded-xl bg-card p-5.5 shadow-xs"
            data-test="connect-skipped"
        >
            <p class="max-w-[72ch]">
                {{ report.platform_label }} also listed these Pages, but they
                cannot be connected:
            </p>
            <ul class="mt-2 grid max-w-[72ch] gap-2">
                <li
                    v-for="account in report.skipped"
                    :key="account.id"
                    class="grid gap-0.5"
                >
                    <span class="font-semibold">{{ account.name }}</span>
                    <span class="text-muted-foreground">{{
                        account.reason
                    }}</span>
                </li>
            </ul>
        </section>

        <p
            v-if="report.hint"
            class="max-w-[72ch] text-sm text-muted-foreground"
            data-test="connect-hint"
        >
            {{ report.hint }}
        </p>
    </div>
</template>
