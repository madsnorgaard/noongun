<script setup lang="ts">
import { computed } from 'vue';
import { webAddress } from '@/lib/posts';
import type { Brief, BriefPlatform } from '@/types/brief';

const props = defineProps<{
    brief: Brief;
    platforms: BriefPlatform[];
}>();

const platformRules = computed(() =>
    props.platforms
        .map((platform) => ({
            ...platform,
            rules: props.brief.voice_rules.platforms[platform.name] ?? [],
        }))
        .filter((platform) => platform.rules.length > 0),
);

const hasRules = computed(
    () =>
        props.brief.voice_rules.general.length > 0 ||
        platformRules.value.length > 0,
);
</script>

<template>
    <div class="grid gap-4">
        <section class="grid gap-3 rounded-2xl bg-card p-5.5 shadow-xs">
            <h2 class="text-heading" data-test="brief-title">
                {{ brief.title }}
            </h2>
            <!-- The brief is markdown and is shown as it was typed. It is
                 never turned into markup. -->
            <pre
                class="max-w-[72ch] font-sans text-lg leading-normal break-words whitespace-pre-wrap"
                data-test="brief-body"
                >{{ brief.body }}</pre>
        </section>

        <section class="grid gap-3 rounded-xl bg-card p-5.5 shadow-xs">
            <h2 class="text-heading">Voice rules</h2>
            <p v-if="!hasRules" class="text-muted-foreground">
                This version has no voice rules.
            </p>
            <template v-else>
                <div v-if="brief.voice_rules.general.length" class="grid gap-1">
                    <h3 class="text-sm text-muted-foreground">
                        On every channel
                    </h3>
                    <ul class="grid list-disc gap-1 pl-5 text-base">
                        <li
                            v-for="rule in brief.voice_rules.general"
                            :key="rule"
                        >
                            {{ rule }}
                        </li>
                    </ul>
                </div>
                <div
                    v-for="platform in platformRules"
                    :key="platform.name"
                    class="grid gap-1"
                >
                    <h3 class="text-sm text-muted-foreground">
                        On {{ platform.label }}
                    </h3>
                    <ul class="grid list-disc gap-1 pl-5 text-base">
                        <li v-for="rule in platform.rules" :key="rule">
                            {{ rule }}
                        </li>
                    </ul>
                </div>
            </template>
        </section>

        <section class="grid gap-3 rounded-xl bg-card p-5.5 shadow-xs">
            <h2 class="text-heading">Calls to action</h2>
            <p
                v-if="brief.calls_to_action.length === 0"
                class="text-muted-foreground"
            >
                This version has no calls to action.
            </p>
            <ul v-else class="grid gap-2 text-base">
                <li
                    v-for="(call, position) in brief.calls_to_action"
                    :key="position"
                    class="grid gap-0.5"
                >
                    <span>{{ call.text }}</span>
                    <a
                        v-if="webAddress(call.url)"
                        :href="webAddress(call.url) ?? undefined"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-mono text-xs break-all underline underline-offset-4"
                    >
                        {{ call.url }}
                    </a>
                    <span
                        v-else-if="call.url"
                        class="font-mono text-xs break-all"
                    >
                        {{ call.url }}
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
