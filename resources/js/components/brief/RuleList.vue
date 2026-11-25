<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const rules = defineModel<string[]>({ required: true });

const props = defineProps<{
    /** Names the list for screen readers and makes the field ids unique. */
    name: string;
    label: string;
    max: number;
    maxLength: number;
    /** The message for the rule at each position, when the server refused it. */
    errors?: Record<number, string | undefined>;
    listError?: string;
}>();

function add(): void {
    if (rules.value.length < props.max) {
        rules.value = [...rules.value, ''];
    }
}

function remove(position: number): void {
    rules.value = rules.value.filter((_, index) => index !== position);
}

function change(position: number, text: string): void {
    rules.value = rules.value.map((rule, index) =>
        index === position ? text : rule,
    );
}
</script>

<template>
    <fieldset class="grid min-w-0 gap-2">
        <legend class="text-sm text-muted-foreground">{{ label }}</legend>

        <p v-if="rules.length === 0" class="text-base text-muted-foreground">
            No rules yet.
        </p>

        <ul v-else class="grid gap-2">
            <li
                v-for="(rule, position) in rules"
                :key="position"
                class="grid gap-1"
            >
                <div class="flex items-center gap-2">
                    <Input
                        :id="`${name}-${position}`"
                        :model-value="rule"
                        :maxlength="maxLength"
                        :aria-label="`${label}, rule ${position + 1}`"
                        :aria-invalid="errors?.[position] ? true : undefined"
                        @update:model-value="
                            (text: string | number) =>
                                change(position, String(text))
                        "
                    />
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        class="shrink-0 text-muted-foreground"
                        :aria-label="`Remove rule ${position + 1} from ${label}`"
                        @click="remove(position)"
                    >
                        Remove
                    </Button>
                </div>
                <InputError :message="errors?.[position]" />
            </li>
        </ul>

        <InputError :message="listError" />

        <Button
            v-if="rules.length < max"
            type="button"
            size="sm"
            variant="outline"
            class="w-fit"
            :data-test="`add-rule-${name}`"
            @click="add"
        >
            Add a rule
        </Button>
    </fieldset>
</template>
