import type { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

// The type sizes that resources/css/app.css adds to the theme. Without this
// they would be taken for text colours and dropped next to one.
const twMerge = extendTailwindMerge({
    extend: {
        classGroups: {
            'font-size': [{ text: ['body', 'heading', 'title', 'display'] }],
        },
    },
});

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}
