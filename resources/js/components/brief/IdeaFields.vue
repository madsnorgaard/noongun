<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { PillarOption } from '@/types/brief';

type IdeaFormFields = {
    title: string;
    pillar_id: string;
    source_url: string;
    notes: string;
    errors: Partial<
        Record<'title' | 'pillar_id' | 'source_url' | 'notes', string>
    >;
};

const form = defineModel<IdeaFormFields>({ required: true });

defineProps<{
    /** Makes the field ids unique on a page with several of these forms. */
    name: string;
    pillars: PillarOption[];
    limits: { title: number; source_url: number; notes: number };
}>();
</script>

<template>
    <div class="grid gap-4">
        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_16rem]">
            <div class="grid gap-1.5">
                <Label :for="`${name}-title`">Title</Label>
                <Input
                    :id="`${name}-title`"
                    v-model="form.title"
                    :maxlength="limits.title"
                    autocomplete="off"
                />
                <InputError :message="form.errors.title" />
            </div>
            <div class="grid gap-1.5">
                <Label :for="`${name}-pillar`">Pillar (optional)</Label>
                <NativeSelect :id="`${name}-pillar`" v-model="form.pillar_id">
                    <option value="">No pillar</option>
                    <option
                        v-for="pillar in pillars"
                        :key="pillar.id"
                        :value="String(pillar.id)"
                    >
                        {{ pillar.name }}
                    </option>
                </NativeSelect>
                <InputError :message="form.errors.pillar_id" />
            </div>
        </div>

        <div class="grid gap-1.5">
            <Label :for="`${name}-source`">Source (optional)</Label>
            <Input
                :id="`${name}-source`"
                v-model="form.source_url"
                type="url"
                inputmode="url"
                :maxlength="limits.source_url"
                :aria-describedby="`${name}-source-hint`"
            />
            <p
                :id="`${name}-source-hint`"
                class="text-sm text-muted-foreground"
            >
                Kept as an address and given to a draft as text. Noon Gun does
                not open the page.
            </p>
            <InputError :message="form.errors.source_url" />
        </div>

        <div class="grid gap-1.5">
            <Label :for="`${name}-notes`">Notes (optional)</Label>
            <textarea
                :id="`${name}-notes`"
                v-model="form.notes"
                rows="3"
                :maxlength="limits.notes"
                class="w-full resize-y rounded-lg border border-input bg-card p-3.5 text-base leading-normal outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
            ></textarea>
            <InputError :message="form.errors.notes" />
        </div>
    </div>
</template>
