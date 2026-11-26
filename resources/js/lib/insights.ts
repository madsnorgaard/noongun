import { formatDateTime } from '@/lib/posts';
import type {
    MetricName,
    MissingReason,
    Reading,
    ScoreWeights,
    VariantInsights,
} from '@/types/insights';

/**
 * The words for the numbers of a post. Every page that shows numbers takes
 * its sentences from here, so that a state reads the same everywhere.
 */

export const metricNames: MetricName[] = [
    'reactions',
    'comments',
    'shares',
    'clicks',
    'reach',
];

export const metricLabels: Record<MetricName, string> = {
    reactions: 'Reactions',
    comments: 'Comments',
    shares: 'Shares',
    clicks: 'Clicks',
    reach: 'Reach',
};

/** Said when a number is missing and nothing more is known about why. */
export const notGiven = 'The platform did not give this number.';

/**
 * A count the way the reader writes numbers, with thousands separators.
 */
export function formatNumber(value: number, digits = 0): string {
    return new Intl.NumberFormat(undefined, {
        maximumFractionDigits: digits,
    }).format(value);
}

export function counted(count: number, one: string, many: string): string {
    return `${formatNumber(count)} ${count === 1 ? one : many}`;
}

function sentenceList(words: string[]): string {
    if (words.length < 3) {
        return words.join(' and ');
    }

    return `${words.slice(0, -1).join(', ')} and ${words[words.length - 1]}`;
}

/**
 * Why a cell holds a dash, for the reader of a screen and for the one who
 * rests the pointer on it.
 */
export function missingReason(
    reason: MissingReason | null,
    platform: string,
): string {
    switch (reason) {
        case 'platform':
            return `${platform} does not give this number.`;
        case 'permission':
            return `Needs one more permission from ${platform}.`;
        case 'not_given':
            return `${platform} did not give this number for this post.`;
        default:
            return notGiven;
    }
}

/**
 * "Reach and clicks need one more permission from Facebook."
 */
export function permissionNote(
    metrics: MetricName[],
    platform: string,
): string | null {
    if (metrics.length === 0) {
        return null;
    }

    const order: MetricName[] = [
        'reach',
        'clicks',
        'reactions',
        'comments',
        'shares',
    ];
    const names = order
        .filter((metric) => metrics.includes(metric))
        .map((metric) => metricLabels[metric].toLowerCase());
    const list = sentenceList(names);

    return `${list.charAt(0).toUpperCase()}${list.slice(1)} ${
        names.length === 1 ? 'needs' : 'need'
    } one more permission from ${platform}.`;
}

/**
 * Whether the reader can put the state right by connecting the channel
 * again, so that the page offers the way there.
 */
export function needsReconnect(variant: VariantInsights): boolean {
    return (
        variant.channel_status === 'needs_reconnect' &&
        (variant.stale || variant.state === 'blocked')
    );
}

/**
 * One or two sentences on where the numbers of one text of a post stand:
 * when they were last checked, when the first reading is expected, or why
 * there are none. Every state reads differently.
 */
export function variantNote(
    variant: VariantInsights,
    reading: Reading,
    timezone: string,
    now: Date = new Date(),
): string {
    const channel = variant.channel_name;
    const platform = variant.platform_label;

    switch (variant.state) {
        case 'measured': {
            const checked = variant.checked_at
                ? formatDateTime(variant.checked_at, timezone)
                : 'an earlier reading';

            if (variant.stopped) {
                return `Last measured on ${checked}. A post is read for ${reading.stop_after} after it goes out.`;
            }

            if (variant.stale) {
                return variant.channel_status === 'needs_reconnect'
                    ? `These numbers are from ${checked}. Connect ${channel} again to have them updated.`
                    : `These numbers are from ${checked}. ${channel} is disconnected, so they are not updated.`;
            }

            if (!reading.enabled) {
                return `These numbers are from ${checked}. Insights are turned off on this instance, so they are not updated.`;
            }

            return `Last checked ${checked}.`;
        }

        case 'pending': {
            const next = variant.next_reading_at
                ? new Date(variant.next_reading_at)
                : null;

            if (next === null || next.getTime() <= now.getTime()) {
                return 'Not measured yet. A reading is due now. If this does not change within the hour, check that the scheduler and the queue worker are running.';
            }

            const expected = formatDateTime(next.toISOString(), timezone);

            return variant.asked
                ? `Not measured yet. ${platform} gave no numbers at the last reading. The next one is expected around ${expected}.`
                : `Not measured yet. The first reading is expected around ${expected}.`;
        }

        case 'never':
            return `Not measured. No reading came through in the ${reading.stop_after} after it went out, and older posts are not read. Open the post on ${platform} to see its numbers.`;

        case 'unavailable':
            return `${platform} does not give Noon Gun numbers for posts yet.`;

        case 'off':
            return 'Not measured. Insights are turned off on this instance. To turn them on, set NOONGUN_INSIGHTS_ENABLED=true and restart the containers.';

        case 'blocked':
            return variant.channel_status === 'needs_reconnect'
                ? `Not measured. Connect ${channel} again to have its numbers read.`
                : `Not measured. ${channel} is disconnected, so its numbers are not read.`;
    }
}

/**
 * "reactions x 1 + comments x 2 + shares x 3 + clicks x 1", with the
 * weights this instance is set to.
 */
export function scoreFormula(weights: ScoreWeights): string {
    return (['reactions', 'comments', 'shares', 'clicks'] as const)
        .map(
            (metric) =>
                `${metricLabels[metric].toLowerCase()} x ${formatNumber(weights[metric], 4)}`,
        )
        .join(' + ');
}
