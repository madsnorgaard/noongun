<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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

const props = defineProps<{
    provider: { name: string; label: string } | null;
    available: boolean;
    key_source: 'workspace' | 'instance' | null;
    credential: {
        unreadable: boolean;
        last_four: string;
        set_at: string | null;
    } | null;
    can: { manage: boolean };
}>();

const page = usePage();
const timezone = computed(
    () =>
        (page.props.workspace as { timezone: string } | null)?.timezone ??
        'UTC',
);

const confirmingRemoval = ref(false);
const removing = ref(false);

const explanation = computed(() => {
    if (props.provider === null) {
        return 'No drafting provider is set up on this instance. The operator sets one in the configuration.';
    }

    if (!props.can.manage) {
        return props.available
            ? 'Drafts can be requested in this workspace.'
            : 'Drafts cannot be requested yet. An owner of this workspace can add an API key.';
    }

    if (props.credential?.unreadable) {
        return 'The stored key can no longer be read, so drafts cannot be requested. Enter the key again below, or remove it.';
    }

    if (props.key_source === 'workspace') {
        return `Drafts are written with the key of this workspace. Its ${props.provider.label} account is billed for them.`;
    }

    if (props.key_source === 'instance') {
        return `Drafts are written with the key of this instance, set by its operator. Store a key below to use your own ${props.provider.label} account.`;
    }

    return `There is no key, so drafts cannot be requested. Store an API key for ${props.provider.label} below.`;
});

function remove(): void {
    router.delete(LlmKeyController.destroy.url(), {
        preserveScroll: true,
        onStart: () => (removing.value = true),
        onFinish: () => {
            removing.value = false;
            confirmingRemoval.value = false;
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

        <dl
            v-if="can.manage && provider"
            class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3"
            data-test="drafting-key-facts"
        >
            <div class="grid gap-0.5">
                <dt class="text-muted-foreground">Provider</dt>
                <dd class="text-base">{{ provider.label }}</dd>
            </div>
            <div class="grid gap-0.5">
                <dt class="text-muted-foreground">Key of this workspace</dt>
                <dd
                    v-if="credential?.unreadable"
                    class="text-base text-destructive"
                    data-test="llm-key-unreadable"
                >
                    Cannot be read
                </dd>
                <dd v-else-if="credential" class="text-base">
                    Ends in
                    <span class="font-mono text-xs">{{
                        credential.last_four
                    }}</span>
                </dd>
                <dd v-else class="text-base">None</dd>
            </div>
            <div v-if="credential?.set_at" class="grid gap-0.5">
                <dt class="text-muted-foreground">Stored</dt>
                <dd class="font-mono text-xs leading-6">
                    {{ formatDateTime(credential.set_at, timezone) }}
                </dd>
            </div>
        </dl>
    </div>

    <div v-if="can.manage && provider" class="space-y-6">
        <Heading
            variant="small"
            :title="credential ? 'Replace the key' : 'Store a key'"
            :description="`The key is stored encrypted and is never shown again. It is sent to ${provider.label} with each request for a draft, and nowhere else.`"
        />

        <Form
            v-bind="LlmKeyController.update.form()"
            :options="{ preserveScroll: true }"
            reset-on-success
            reset-on-error
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="api_key">{{ provider.label }} API key</Label>
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
            </div>

            <p class="text-sm text-muted-foreground">
                Changing or removing the key needs your password. If you have
                not given it for a while, you are asked for it first and then
                brought back here to enter the key again.
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <Button :disabled="processing" data-test="store-llm-key">
                    {{ credential ? 'Replace key' : 'Store key' }}
                </Button>
                <Button
                    v-if="credential"
                    type="button"
                    variant="ghost"
                    class="text-muted-foreground"
                    data-test="remove-llm-key"
                    @click="confirmingRemoval = true"
                >
                    Remove key
                </Button>
            </div>
        </Form>
    </div>

    <Dialog
        :open="confirmingRemoval"
        @update:open="(open: boolean) => !open && (confirmingRemoval = false)"
    >
        <DialogContent>
            <DialogHeader class="space-y-3">
                <DialogTitle>Remove the key of this workspace?</DialogTitle>
                <DialogDescription class="text-base">
                    Noon Gun erases the key. Drafts are then written with the
                    key of this instance, if its operator has set one, and
                    cannot be requested otherwise. Posts and drafts that exist
                    stay as they are.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="confirmingRemoval = false">
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
