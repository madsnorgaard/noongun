<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import LlmKeyController from '@/actions/App/Http/Controllers/Settings/LlmKeyController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/posts';
import { create as proposeWeek } from '@/routes/proposals';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Drafting settings',
                href: LlmKeyController.edit(),
            },
        ],
    },
});

type Credential = {
    unreadable: boolean;
    last_four: string;
    set_at: string | null;
};

type ProviderRow = {
    name: string;
    label: string;
    offered: boolean;
    in_use: boolean;
    key_source: 'workspace' | 'instance' | null;
    credential: Credential | null;
};

const props = defineProps<{
    provider: { name: string; label: string } | null;
    available: boolean;
    key_source: 'workspace' | 'instance' | null;
    credential: Credential | null;
    chosen_not_offered: string | null;
    providers: ProviderRow[];
    can: { manage: boolean };
}>();

const page = usePage();
const timezone = computed(
    () =>
        (page.props.workspace as { timezone: string } | null)?.timezone ??
        'UTC',
);

const keyFor = ref<ProviderRow | null>(null);
const removalOf = ref<ProviderRow | null>(null);
const removing = ref(false);
const choosing = ref<string | null>(null);
const chooseError = ref<string | null>(null);

const explanation = computed(() => {
    if (props.chosen_not_offered !== null) {
        return props.can.manage
            ? `This workspace was set to draft with ${props.chosen_not_offered}, which this instance does not offer any more. Drafts cannot be requested until you choose another provider below.`
            : `This workspace was set to draft with ${props.chosen_not_offered}, which this instance does not offer any more. An owner of this workspace can choose another provider.`;
    }

    if (props.provider === null) {
        return 'No drafting provider is set up on this instance. The operator sets one in the configuration.';
    }

    if (!props.can.manage) {
        return props.available
            ? 'Drafts can be requested in this workspace.'
            : 'Drafts cannot be requested yet. An owner of this workspace can add an API key.';
    }

    if (props.credential?.unreadable) {
        return `The stored ${props.provider.label} key can no longer be read, so drafts cannot be requested. Enter the key again below, or remove it.`;
    }

    if (props.key_source === 'workspace') {
        return `Drafts are written by ${props.provider.label} with the key of this workspace. The ${props.provider.label} account the key belongs to is billed for them.`;
    }

    if (props.key_source === 'instance') {
        return `Drafts are written by ${props.provider.label} with the key of this instance, set by its operator. Store a key below to use your own ${props.provider.label} account.`;
    }

    return `There is no key for ${props.provider.label}, so drafts cannot be requested. Store an API key for ${props.provider.label} below.`;
});

function keyLine(row: ProviderRow): string {
    if (row.credential?.unreadable) {
        return 'The key of this workspace cannot be read';
    }

    if (!row.offered) {
        return 'Not offered on this instance any more. The key stored for it is not used.';
    }

    if (row.key_source === 'workspace') {
        return 'Key of this workspace';
    }

    if (row.key_source === 'instance') {
        return 'Key of this instance';
    }

    return 'No key';
}

function choose(row: ProviderRow): void {
    router.put(
        LlmKeyController.choose.url(),
        { provider: row.name },
        {
            preserveScroll: true,
            onStart: () => {
                choosing.value = row.name;
                chooseError.value = null;
            },
            onError: (errors) => (chooseError.value = errors.provider ?? null),
            onFinish: () => (choosing.value = null),
        },
    );
}

async function enterKeyFor(row: ProviderRow): Promise<void> {
    keyFor.value = row;

    await nextTick();

    document.getElementById('api_key')?.focus();
}

function remove(): void {
    if (removalOf.value === null) {
        return;
    }

    router.delete(LlmKeyController.destroy.url(), {
        data: { provider: removalOf.value.name },
        preserveScroll: true,
        onStart: () => (removing.value = true),
        onFinish: () => {
            removing.value = false;
            removalOf.value = null;
        },
    });
}
</script>

<template>
    <Head title="Drafting settings" />

    <h1 class="sr-only">Drafting settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Drafting"
            description="Drafts are written by a language model from your brief. You approve every one before it goes anywhere."
        />

        <div class="grid gap-2" data-test="drafting-status">
            <span
                class="inline-flex w-fit items-center gap-2 rounded-full px-[11px] py-[3px] text-[0.875rem] leading-snug font-semibold whitespace-nowrap"
                :class="
                    available
                        ? 'bg-secondary text-secondary-foreground'
                        : 'bg-secondary text-muted-foreground'
                "
            >
                <span
                    class="size-[9px] shrink-0"
                    :class="
                        available
                            ? 'rounded-full bg-success'
                            : 'rounded-[2px] ring-2 ring-idle ring-inset'
                    "
                    aria-hidden="true"
                ></span>
                {{ available ? 'Available' : 'Not available' }}
            </span>
            <p class="max-w-[72ch] text-base">{{ explanation }}</p>
            <p v-if="available" class="max-w-[72ch] text-base">
                With a brief and slots in place, owners and editors can
                <TextLink :href="proposeWeek()" data-test="propose-week"
                    >propose a week</TextLink
                >.
            </p>
        </div>
    </div>

    <div v-if="can.manage && providers.length > 0" class="space-y-4">
        <Heading
            variant="small"
            title="Provider and keys"
            description="The provider in use is sent your brief, your voice rules and the idea for each post, and nothing else. A key is used with its own provider only."
        />

        <ul class="grid gap-2" data-test="drafting-providers">
            <li
                v-for="row in providers"
                :key="row.name"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg bg-card px-4 py-3 shadow-xs"
                :data-test="`drafting-provider-${row.name}`"
            >
                <div class="grid min-w-[14rem] flex-1 gap-0.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-base font-semibold">{{
                            row.label
                        }}</span>
                        <span
                            v-if="row.in_use"
                            class="inline-flex items-center gap-2 rounded-full bg-secondary px-[11px] py-[3px] text-[0.875rem] leading-snug font-semibold whitespace-nowrap text-secondary-foreground"
                            data-test="drafting-provider-in-use"
                        >
                            <span
                                class="size-[9px] shrink-0 rounded-full bg-success"
                                aria-hidden="true"
                            ></span>
                            In use
                        </span>
                    </div>
                    <p
                        class="text-sm"
                        :class="
                            row.credential?.unreadable
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        "
                        :data-test="
                            row.credential?.unreadable
                                ? 'llm-key-unreadable'
                                : undefined
                        "
                    >
                        {{ keyLine(row)
                        }}<template
                            v-if="
                                row.offered &&
                                row.credential &&
                                !row.credential.unreadable
                            "
                            >, ends in
                            <span class="font-mono text-xs">{{
                                row.credential.last_four
                            }}</span
                            ><template v-if="row.credential.set_at"
                                >, stored
                                <span class="font-mono text-xs">{{
                                    formatDateTime(
                                        row.credential.set_at,
                                        timezone,
                                    )
                                }}</span>
                            </template>
                        </template>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-if="row.offered && !row.in_use"
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="choosing !== null"
                        :data-test="`use-provider-${row.name}`"
                        @click="choose(row)"
                    >
                        Draft with {{ row.label }}
                    </Button>
                    <Button
                        v-if="row.offered"
                        type="button"
                        variant="outline"
                        size="sm"
                        :data-test="`enter-llm-key-${row.name}`"
                        @click="enterKeyFor(row)"
                    >
                        {{ row.credential ? 'Replace key' : 'Store a key' }}
                    </Button>
                    <Button
                        v-if="row.credential"
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="text-muted-foreground"
                        :data-test="`remove-llm-key-${row.name}`"
                        @click="removalOf = row"
                    >
                        Remove key
                    </Button>
                </div>
            </li>
        </ul>

        <InputError :message="chooseError ?? undefined" />

        <p class="max-w-[72ch] text-sm text-muted-foreground">
            Choosing a provider and changing or removing a key need your
            password. If you have not given it for a while, you are asked for
            it first and then brought back here to do it again.
        </p>
    </div>

    <div v-if="can.manage && keyFor" class="space-y-6" data-test="llm-key-form">
        <Heading
            variant="small"
            :title="
                keyFor.credential
                    ? `Replace the ${keyFor.label} key`
                    : `Store a ${keyFor.label} key`
            "
            :description="`The key is stored encrypted and is never shown again. It is sent to ${keyFor.label} with each request for a draft, and nowhere else.`"
        />

        <Form
            :key="keyFor.name"
            v-bind="LlmKeyController.update.form()"
            :options="{ preserveScroll: true }"
            reset-on-success
            reset-on-error
            class="space-y-6"
            @success="keyFor = null"
            v-slot="{ errors, processing }"
        >
            <input type="hidden" name="provider" :value="keyFor.name" />

            <div class="grid gap-2">
                <Label for="api_key">{{ keyFor.label }} API key</Label>
                <PasswordInput
                    id="api_key"
                    name="api_key"
                    class="mt-1 block w-full font-mono"
                    required
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    data-1p-ignore
                    data-lpignore="true"
                />
                <InputError class="mt-2" :message="errors.api_key" />
                <InputError class="mt-2" :message="errors.provider" />
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <Button :disabled="processing" data-test="store-llm-key">
                    {{ keyFor.credential ? 'Replace key' : 'Store key' }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    class="text-muted-foreground"
                    @click="keyFor = null"
                >
                    Cancel
                </Button>
            </div>
        </Form>
    </div>

    <Dialog
        :open="removalOf !== null"
        @update:open="(open: boolean) => !open && (removalOf = null)"
    >
        <DialogContent>
            <DialogHeader class="space-y-3">
                <DialogTitle
                    >Remove the {{ removalOf?.label }} key of this
                    workspace?</DialogTitle
                >
                <DialogDescription class="text-base">
                    Noon Gun erases the key. Drafts with
                    {{ removalOf?.label }} are then written with the key of
                    this instance, if its operator has set one for
                    {{ removalOf?.label }}, and cannot be requested otherwise.
                    Posts and drafts that exist stay as they are.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="removalOf = null">
                    Cancel
                </Button>
                <Button
                    variant="destructive"
                    :disabled="removing"
                    data-test="confirm-remove-llm-key"
                    @click="remove"
                >
                    Remove key
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
