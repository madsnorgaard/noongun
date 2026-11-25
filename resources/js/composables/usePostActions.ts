import { router } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { ref } from 'vue';

type ActionData = Record<
    string,
    string | boolean | { id: number; version: string }[]
>;

export type UsePostActionsReturn = {
    /** The key of the request that is under way, or null. */
    busy: Ref<string | null>;
    /** What the server refused in the last request, in words for the user. */
    errors: Ref<Record<string, string>>;
    run: (
        key: string,
        method: 'post' | 'delete',
        url: string,
        data?: ActionData,
        onSuccess?: () => void,
    ) => void;
};

/**
 * Sends one state change of a post (approve, schedule, retry and so on) and
 * keeps track of what is running and what was refused. Each call is one
 * request for one deliberate click.
 */
export function usePostActions(): UsePostActionsReturn {
    const busy = ref<string | null>(null);
    const errors = ref<Record<string, string>>({});

    function run(
        key: string,
        method: 'post' | 'delete',
        url: string,
        data: ActionData = {},
        onSuccess?: () => void,
    ): void {
        if (busy.value !== null) {
            return;
        }

        errors.value = {};

        router.visit(url, {
            method,
            data,
            preserveScroll: true,
            onStart: () => (busy.value = key),
            onError: (refused) => (errors.value = refused),
            onSuccess: () => onSuccess?.(),
            onFinish: () => (busy.value = null),
        });
    }

    return { busy, errors, run };
}
