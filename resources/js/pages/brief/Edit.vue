<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import BriefTabs from '@/components/brief/BriefTabs.vue';
import BriefText from '@/components/brief/BriefText.vue';
import RuleList from '@/components/brief/RuleList.vue';
import SavedLine from '@/components/brief/SavedLine.vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, textLength, useWorkspaceTimezone } from '@/lib/posts';
import { edit, update } from '@/routes/brief';
import { show as showVersion } from '@/routes/brief/versions';
import { create as proposeWeek } from '@/routes/proposals';
import type {
    Brief,
    BriefLimits,
    BriefPlatform,
    BriefVersionSummary,
    VoiceRules,
} from '@/types/brief';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Brief',
                href: edit(),
            },
        ],
    },
});

const props = defineProps<{
    brief: Brief | null;
    versions: BriefVersionSummary[];
    version_count: number;
    platforms: BriefPlatform[];
    /** Rules a workspace can start from. Offered, never applied for it. */
    starter_voice: VoiceRules;
    limits: BriefLimits;
    can: { update: boolean };
}>();

type CallFields = { text: string; url: string };

type BriefFields = {
    title: string;
    body: string;
    voice_rules: VoiceRules;
    calls_to_action: CallFields[];
};

const OUTLINE = [
    '## Who we are',
    '',
    '## What we offer',
    '',
    '## Who we write for',
    '',
    '## What we want readers to do',
    '',
    '## What we never say',
    '',
].join('\n');

const timezone = useWorkspaceTimezone();

// Every platform gets a list in the form, also when it has no rules yet.
function rulesPerPlatform(
    rules: Record<string, string[]> | undefined,
): Record<string, string[]> {
    return Object.fromEntries(
        props.platforms.map((platform) => [
            platform.name,
            [...(rules?.[platform.name] ?? [])],
        ]),
    );
}

function fieldsFromServer(): BriefFields {
    return {
        title: props.brief?.title ?? '',
        body: props.brief?.body ?? '',
        voice_rules: {
            general: [...(props.brief?.voice_rules.general ?? [])],
            platforms: rulesPerPlatform(props.brief?.voice_rules.platforms),
        },
        calls_to_action: (props.brief?.calls_to_action ?? []).map((call) => ({
            text: call.text,
            url: call.url ?? '',
        })),
    };
}

const form = useForm<BriefFields>(fieldsFromServer());

// A new version was saved, here or by someone else: show what is stored.
watch(
    () => props.brief?.version ?? 0,
    () => {
        form.defaults(fieldsFromServer());
        form.reset();
        form.clearErrors();
    },
);

function filled(rules: string[]): string[] {
    return rules.map((rule) => rule.trim()).filter((rule) => rule !== '');
}

function save(): void {
    if (!props.can.update) {
        return;
    }

    form.transform((fields) => ({
        title: fields.title,
        body: fields.body,
        voice_rules: {
            general: filled(fields.voice_rules.general),
            platforms: Object.fromEntries(
                Object.entries(fields.voice_rules.platforms)
                    .map(([platform, rules]) => [platform, filled(rules)])
                    .filter(([, rules]) => rules.length > 0),
            ),
        },
        // A row that was added and left empty is not sent.
        calls_to_action: fields.calls_to_action
            .filter((call) => call.text.trim() !== '' || call.url.trim() !== '')
            .map((call) => ({
                text: call.text.trim(),
                url: call.url.trim() === '' ? null : call.url.trim(),
            })),
    })).put(update.url(), { preserveScroll: true });
}

function fieldError(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}

function ruleErrors(prefix: string): Record<number, string | undefined> {
    const errors: Record<number, string | undefined> = {};

    for (const [key, message] of Object.entries(
        form.errors as Record<string, string | undefined>,
    )) {
        if (key.startsWith(`${prefix}.`)) {
            errors[Number(key.slice(prefix.length + 1))] = message;
        }
    }

    return errors;
}

const hasNoRules = computed(
    () =>
        filled(form.voice_rules.general).length === 0 &&
        Object.values(form.voice_rules.platforms).every(
            (rules) => filled(rules).length === 0,
        ),
);

const starterPlatforms = computed(() =>
    props.platforms
        .map((platform) => ({
            ...platform,
            rules: props.starter_voice.platforms[platform.name] ?? [],
        }))
        .filter((platform) => platform.rules.length > 0),
);

// Fills the form only. Nothing is stored until the brief is saved.
function useStarterVoice(): void {
    form.voice_rules = {
        general: [...props.starter_voice.general],
        platforms: rulesPerPlatform(props.starter_voice.platforms),
    };
}

function useOutline(): void {
    if (form.body.trim() === '') {
        form.body = OUTLINE;
    }
}

function addCall(): void {
    if (form.calls_to_action.length < props.limits.calls_to_action) {
        form.calls_to_action.push({ text: '', url: '' });
    }
}

function removeCall(position: number): void {
    form.calls_to_action.splice(position, 1);
}

const bodyLeft = computed(() => props.limits.body - textLength(form.body));

const earlier = computed(() =>
    props.versions.filter((version) => !version.is_current),
);

// Leaving with text that is not saved asks first. Saving is not a GET
// request and passes.
const unsaved = computed(() => props.can.update && form.isDirty);

const stopGuard = router.on('before', (event) => {
    if (!unsaved.value || event.detail.visit.method !== 'get') {
        return;
    }

    return window.confirm(
        'The brief has changes that are not saved. Leave without saving them?',
    );
});

function warnOnUnload(event: BeforeUnloadEvent): void {
    if (unsaved.value) {
        event.preventDefault();
    }
}

onMounted(() => window.addEventListener('beforeunload', warnOnUnload));

onBeforeUnmount(() => {
    stopGuard();
    window.removeEventListener('beforeunload', warnOnUnload);
});
</script>

<template>
    <Head title="Brief" />

    <form
        class="flex flex-1 flex-col gap-5.5 px-4 pt-2 pb-12 md:px-8 lg:px-11"
        @submit.prevent="save"
    >
        <header class="grid gap-4">
            <div class="grid gap-1">
                <h1 class="text-title md:text-display">Brief</h1>
                <p class="text-muted-foreground">
                    Every draft starts here. Change the brief and next week
                    changes with it. Times in {{ timezone }}.
                </p>
            </div>
            <BriefTabs current="brief" :can-propose="can.update" />
        </header>

        <section
            v-if="brief === null"
            class="rounded-2xl bg-card p-5.5 shadow-xs"
            data-test="brief-empty"
        >
            <h2 class="text-heading">There is no brief yet</h2>
            <template v-if="can.update">
                <p class="mt-1 max-w-[72ch] text-muted-foreground">
                    The brief is what every post is drafted from. Write it the
                    way you would explain the business to someone who is going
                    to post for you. A useful brief answers these questions.
                </p>
                <ul class="mt-3 grid max-w-[72ch] list-disc gap-1.5 pl-5">
                    <li>Who are you, and what do you offer?</li>
                    <li>Who reads your posts, and what do they care about?</li>
                    <li>What should a reader do after reading a post?</li>
                    <li>
                        What do you never say, promise or joke about in public?
                    </li>
                </ul>
                <p class="mt-3 max-w-[72ch] text-sm text-muted-foreground">
                    Do not put passwords, customer names or anything private in
                    the brief. It is sent to the drafting service as it stands.
                </p>
            </template>
            <p v-else class="mt-1 max-w-[72ch] text-muted-foreground">
                An owner or editor of this workspace can write it.
            </p>
        </section>

        <div
            v-if="can.update"
            class="grid items-start gap-5.5 lg:grid-cols-[minmax(0,1fr)_22rem]"
        >
            <div class="grid content-start gap-4">
                <section class="grid gap-4 rounded-2xl bg-card p-5.5 shadow-xs">
                    <div class="grid gap-1.5">
                        <Label for="brief-title">Title</Label>
                        <Input
                            id="brief-title"
                            v-model="form.title"
                            :maxlength="limits.title"
                            autocomplete="off"
                        />
                        <p class="text-sm text-muted-foreground">
                            The name of the business or the project.
                        </p>
                        <InputError :message="fieldError('title')" />
                    </div>

                    <div class="grid gap-2">
                        <div
                            class="flex flex-wrap items-end justify-between gap-2"
                        >
                            <Label for="brief-body">Brief</Label>
                            <Button
                                v-if="form.body.trim() === ''"
                                type="button"
                                size="sm"
                                variant="outline"
                                data-test="use-outline"
                                @click="useOutline"
                            >
                                Start from an outline
                            </Button>
                        </div>
                        <textarea
                            id="brief-body"
                            v-model="form.body"
                            rows="16"
                            spellcheck="true"
                            aria-describedby="brief-body-hint"
                            class="min-h-[360px] w-full resize-y rounded-lg border border-input bg-card p-4 text-lg leading-normal outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        ></textarea>
                        <div
                            class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1"
                        >
                            <p
                                id="brief-body-hint"
                                class="max-w-[72ch] text-sm text-muted-foreground"
                            >
                                Plain text or markdown. It is kept exactly as
                                you type it.
                            </p>
                            <span
                                class="ml-auto font-mono text-sm"
                                :class="
                                    bodyLeft < 0
                                        ? 'text-destructive'
                                        : 'text-muted-foreground'
                                "
                            >
                                <template v-if="bodyLeft < 0">
                                    {{ -bodyLeft }} over the limit of
                                    {{ limits.body }}
                                </template>
                                <template v-else>
                                    {{ textLength(form.body) }} /
                                    {{ limits.body }}
                                </template>
                            </span>
                        </div>
                        <InputError :message="fieldError('body')" />
                    </div>
                </section>

                <section class="grid gap-4 rounded-xl bg-card p-5.5 shadow-xs">
                    <div>
                        <h2 class="text-heading">Voice rules</h2>
                        <p class="mt-1 max-w-[72ch] text-muted-foreground">
                            How every draft should sound, one rule per line.
                            Voice rules advise. Only platform limits block.
                        </p>
                    </div>

                    <div
                        v-if="hasNoRules"
                        class="grid gap-3 rounded-lg bg-secondary p-4"
                        data-test="starter-voice"
                    >
                        <div>
                            <h3 class="font-semibold">
                                Start from the house rules
                            </h3>
                            <p class="text-sm text-muted-foreground">
                                These are put in the lists below for you to
                                change. Nothing is saved until you save the
                                brief.
                            </p>
                        </div>
                        <ul class="grid list-disc gap-1 pl-5 text-base">
                            <li
                                v-for="rule in starter_voice.general"
                                :key="rule"
                            >
                                {{ rule }}
                            </li>
                            <template
                                v-for="platform in starterPlatforms"
                                :key="platform.name"
                            >
                                <li
                                    v-for="rule in platform.rules"
                                    :key="`${platform.name}-${rule}`"
                                >
                                    On {{ platform.label }}: {{ rule }}
                                </li>
                            </template>
                        </ul>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            class="w-fit"
                            data-test="use-starter-voice"
                            @click="useStarterVoice"
                        >
                            Use these rules
                        </Button>
                    </div>

                    <RuleList
                        v-model="form.voice_rules.general"
                        name="rules-general"
                        label="On every channel"
                        :max="limits.rules"
                        :max-length="limits.rule"
                        :errors="ruleErrors('voice_rules.general')"
                        :list-error="fieldError('voice_rules.general')"
                    />

                    <RuleList
                        v-for="platform in platforms"
                        :key="platform.name"
                        v-model="form.voice_rules.platforms[platform.name]"
                        :name="`rules-${platform.name}`"
                        :label="`On ${platform.label} only`"
                        :max="limits.rules"
                        :max-length="limits.rule"
                        :errors="
                            ruleErrors(`voice_rules.platforms.${platform.name}`)
                        "
                        :list-error="
                            fieldError(`voice_rules.platforms.${platform.name}`)
                        "
                    />

                    <InputError
                        :message="
                            fieldError('voice_rules') ??
                            fieldError('voice_rules.platforms')
                        "
                    />
                </section>

                <section class="grid gap-4 rounded-xl bg-card p-5.5 shadow-xs">
                    <div>
                        <h2 class="text-heading">Calls to action</h2>
                        <p class="mt-1 max-w-[72ch] text-muted-foreground">
                            What a post may ask the reader to do, with the
                            address it leads to when there is one.
                        </p>
                    </div>

                    <p
                        v-if="form.calls_to_action.length === 0"
                        class="text-base text-muted-foreground"
                    >
                        No calls to action yet.
                    </p>

                    <ul v-else class="grid gap-4">
                        <li
                            v-for="(call, position) in form.calls_to_action"
                            :key="position"
                            class="grid gap-3 border-b border-border pb-4 last:border-b-0 last:pb-0 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-start"
                            :data-test="`call-${position}`"
                        >
                            <div class="grid gap-1.5">
                                <Label :for="`call-text-${position}`">
                                    Text
                                </Label>
                                <Input
                                    :id="`call-text-${position}`"
                                    v-model="call.text"
                                    :maxlength="limits.call"
                                />
                                <InputError
                                    :message="
                                        fieldError(
                                            `calls_to_action.${position}.text`,
                                        )
                                    "
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label :for="`call-url-${position}`">
                                    Link (optional)
                                </Label>
                                <Input
                                    :id="`call-url-${position}`"
                                    v-model="call.url"
                                    type="url"
                                    inputmode="url"
                                    :maxlength="2048"
                                />
                                <InputError
                                    :message="
                                        fieldError(
                                            `calls_to_action.${position}.url`,
                                        )
                                    "
                                />
                            </div>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                class="w-fit text-muted-foreground sm:mt-[1.875rem]"
                                :aria-label="`Remove call to action ${position + 1}`"
                                @click="removeCall(position)"
                            >
                                Remove
                            </Button>
                        </li>
                    </ul>

                    <InputError :message="fieldError('calls_to_action')" />

                    <Button
                        v-if="
                            form.calls_to_action.length < limits.calls_to_action
                        "
                        type="button"
                        size="sm"
                        variant="outline"
                        class="w-fit"
                        data-test="add-call"
                        @click="addCall"
                    >
                        Add a call to action
                    </Button>
                </section>
            </div>

            <aside class="grid content-start gap-4">
                <section class="grid gap-2 rounded-xl bg-card p-5.5 shadow-xs">
                    <h2 class="text-sm text-muted-foreground">Version</h2>
                    <template v-if="brief">
                        <p data-test="brief-version">
                            <span class="font-mono font-medium">
                                {{ brief.version }}
                            </span>
                            is the current version. Posts are drafted from it.
                        </p>
                        <SavedLine :at="brief.saved_at" :by="brief.saved_by" />
                    </template>
                    <p v-else>Nothing is saved yet.</p>

                    <Button
                        type="submit"
                        class="mt-2"
                        :variant="
                            brief && !form.isDirty ? 'outline' : 'default'
                        "
                        :disabled="
                            form.processing || (brief !== null && !form.isDirty)
                        "
                        data-test="save-brief"
                    >
                        <Spinner v-if="form.processing" />
                        {{ brief ? 'Save as a new version' : 'Save the brief' }}
                    </Button>
                    <p class="text-sm text-muted-foreground">
                        <template v-if="brief && !form.isDirty">
                            Nothing has changed since version
                            {{ brief.version }}. With slots in place, the next
                            step is to
                            <TextLink
                                :href="proposeWeek()"
                                data-test="brief-propose"
                                >propose a week</TextLink
                            >.
                        </template>
                        <template v-else>
                            Saving adds a version. Earlier versions are kept as
                            they were, and drafts that already exist do not
                            change.
                        </template>
                    </p>
                </section>

                <section
                    v-if="earlier.length"
                    class="grid gap-2 rounded-xl bg-card p-5.5 shadow-xs"
                    data-test="brief-versions"
                >
                    <h2 class="text-sm text-muted-foreground">
                        Earlier versions
                    </h2>
                    <ul class="grid divide-y divide-border">
                        <li
                            v-for="version in earlier"
                            :key="version.version"
                            class="flex items-center justify-between gap-3 py-1.5"
                        >
                            <span class="grid gap-0.5">
                                <span>
                                    Version
                                    <span class="font-mono">{{
                                        version.version
                                    }}</span>
                                </span>
                                <span class="text-sm text-muted-foreground">
                                    <span
                                        v-if="version.saved_at"
                                        class="font-mono text-xs"
                                        >{{
                                            formatDateTime(
                                                version.saved_at,
                                                timezone,
                                            )
                                        }}</span
                                    >
                                    <template v-if="version.saved_by">
                                        - {{ version.saved_by }}
                                    </template>
                                </span>
                            </span>
                            <Button size="sm" variant="outline" as-child>
                                <Link
                                    :href="
                                        showVersion({
                                            version: version.version,
                                        })
                                    "
                                    :aria-label="`View version ${version.version}`"
                                >
                                    View
                                </Link>
                            </Button>
                        </li>
                    </ul>
                    <p
                        v-if="version_count > versions.length"
                        class="text-sm text-muted-foreground"
                    >
                        The latest {{ versions.length }} of
                        {{ version_count }} versions are listed.
                    </p>
                    <p class="text-sm text-muted-foreground">
                        An earlier version cannot be restored. To go back to
                        one, copy its text here and save it as a new version.
                    </p>
                </section>
            </aside>
        </div>

        <div
            v-else-if="brief"
            class="grid items-start gap-5.5 lg:grid-cols-[minmax(0,1fr)_22rem]"
        >
            <BriefText :brief="brief" :platforms="platforms" />

            <aside class="grid content-start gap-4">
                <section class="grid gap-2 rounded-xl bg-card p-5.5 shadow-xs">
                    <h2 class="text-sm text-muted-foreground">Version</h2>
                    <p data-test="brief-version">
                        <span class="font-mono font-medium">
                            {{ brief.version }}
                        </span>
                        is the current version. Posts are drafted from it.
                    </p>
                    <SavedLine :at="brief.saved_at" :by="brief.saved_by" />
                </section>

                <section
                    v-if="earlier.length"
                    class="grid gap-2 rounded-xl bg-card p-5.5 shadow-xs"
                >
                    <h2 class="text-sm text-muted-foreground">
                        Earlier versions
                    </h2>
                    <ul class="grid divide-y divide-border">
                        <li
                            v-for="version in earlier"
                            :key="version.version"
                            class="flex items-center justify-between gap-3 py-1.5"
                        >
                            <span>
                                Version
                                <span class="font-mono">{{
                                    version.version
                                }}</span>
                            </span>
                            <Button size="sm" variant="outline" as-child>
                                <Link
                                    :href="
                                        showVersion({
                                            version: version.version,
                                        })
                                    "
                                    :aria-label="`View version ${version.version}`"
                                >
                                    View
                                </Link>
                            </Button>
                        </li>
                    </ul>
                </section>

                <p class="px-1 text-sm text-muted-foreground">
                    Owners and editors of this workspace can change the brief.
                </p>
            </aside>
        </div>
    </form>
</template>
