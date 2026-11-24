<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/setup';

defineOptions({
    layout: {
        title: 'Set up Noon Gun',
        description:
            'Create the owner account and the workspace for this instance.',
    },
});

const props = defineProps<{
    timezones: string[];
    allTimezones: string[];
    defaultTimezone: string;
    locales: Record<string, string>;
    passwordRules: string;
}>();

function guessTimezone(): string {
    const browser = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const known = [...props.timezones, ...props.allTimezones];

    return browser && known.includes(browser) ? browser : props.defaultTimezone;
}

const timezone = ref(guessTimezone());
const locale = ref(Object.keys(props.locales)[0] ?? 'en');
</script>

<template>
    <Head title="Set up" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <fieldset class="grid gap-4">
            <legend class="mb-2 text-sm font-medium">Owner account</legend>

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    v-focus
                    autocomplete="name"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autocomplete="email"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="new-password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                />
                <InputError :message="errors.password_confirmation" />
            </div>
        </fieldset>

        <fieldset class="grid gap-4">
            <legend class="mb-2 text-sm font-medium">Workspace</legend>

            <div class="grid gap-2">
                <Label for="workspace_name">Workspace name</Label>
                <Input id="workspace_name" name="workspace_name" required />
                <InputError :message="errors.workspace_name" />
            </div>

            <div class="grid gap-2">
                <Label for="timezone">Timezone</Label>
                <NativeSelect id="timezone" name="timezone" v-model="timezone">
                    <optgroup label="Common">
                        <option v-for="tz in timezones" :key="tz" :value="tz">
                            {{ tz }}
                        </option>
                    </optgroup>
                    <optgroup label="All timezones">
                        <option
                            v-for="tz in allTimezones"
                            :key="tz"
                            :value="tz"
                        >
                            {{ tz }}
                        </option>
                    </optgroup>
                </NativeSelect>
                <p class="text-xs text-muted-foreground">
                    Posts are scheduled and shown in this timezone.
                </p>
                <InputError :message="errors.timezone" />
            </div>

            <div class="grid gap-2">
                <Label for="locale">Language</Label>
                <NativeSelect id="locale" name="locale" v-model="locale">
                    <option
                        v-for="(label, code) in locales"
                        :key="code"
                        :value="code"
                    >
                        {{ label }}
                    </option>
                </NativeSelect>
                <InputError :message="errors.locale" />
            </div>
        </fieldset>

        <Button
            type="submit"
            class="w-full"
            :disabled="processing"
            data-test="setup-button"
        >
            <Spinner v-if="processing" />
            Create account and workspace
        </Button>
    </Form>
</template>
