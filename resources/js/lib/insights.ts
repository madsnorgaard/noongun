import { formatDateTime } from '@/lib/posts';
import type {
    MetricName,
    MissingReason,
    Pause,
    Reading,
    ScoreWeights,
    VariantInsights,
} from '@/types/insights';

/**
 * The words for the numbers of a post. Every page that shows numbers takes
 * its sentences from here, so that a state reads the same everywhere.
 *
 * Someone who can connect channels is told what to do. Everyone else is
 * told whom to ask: they cannot act on a setting or a permission.
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

/** Why a row that adds up several channels or posts has no reach. */
export const reachNotAdded =
    'Reach is not added up: the same person would be counted once for each channel.';

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

export function sentenceList(words: string[]): string {
    if (words.length < 3) {
        return words.join(' and ');
    }

    return `${words.slice(0, -1).join(', ')} and ${words[words.length - 1]}`;
}

function capitalised(text: string): string {
    return `${text.charAt(0).toUpperCase()}${text.slice(1)}`;
}

/**
 * The columns a table of numbers shows: reactions, comments and shares
 * always, clicks and reach only when a row has them. Most channels do not,
 * and a column of dashes says nothing.
 */
export function metricsShown(
    has: (metric: MetricName) => boolean,
): MetricName[] {
    return metricNames.filter(
        (metric) => !['clicks', 'reach'].includes(metric) || has(metric),
    );
}

/**
 * One line for the columns that were left out, or null when none was.
 */
export function hiddenNote(shown: MetricName[]): string | null {
    const hidden = metricNames
        .filter((metric) => !shown.includes(metric))
        .map((metric) => metricLabels[metric].toLowerCase());

    if (hidden.length === 0) {
        return null;
    }

    return `${capitalised(sentenceList(hidden))} ${
        hidden.length === 1 ? 'is' : 'are'
    } not shown: no post here has ${hidden.length === 1 ? 'it' : 'them'}.`;
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

    return `${capitalised(sentenceList(names))} ${
        names.length === 1 ? 'needs' : 'need'
    } one more permission from ${platform}.`;
}

/**
 * Why reading is paused for a channel and what happens next. Each kind of
 * pause has its own sentence, because the next step differs: wait, connect
 * again, or nothing.
 */
export function pauseNote(
    pause: Pause,
    platform: string,
    channel: string,
    timezone: string,
    canSetUp: boolean,
): string {
    const until = pause.until ? formatDateTime(pause.until, timezone) : null;

    switch (pause.kind) {
        case 'rate_limit':
            return `${platform} asked Noon Gun to wait${until ? ` until ${until}` : ''}. Reading starts again by itself.`;
        case 'token':
            return canSetUp
                ? `${platform} did not accept the access of ${channel} for reading numbers. Noon Gun tries again${until ? ` after ${until}` : ''}. Connecting ${channel} again starts reading at once. Publishing is not affected.`
                : `${platform} did not accept the access of ${channel} for reading numbers. Noon Gun tries again${until ? ` after ${until}` : ''}. An owner of this workspace can connect ${channel} again to start reading at once. Publishing is not affected.`;
        case 'other':
            return `The last reading of ${channel} did not get through. Noon Gun tries again${until ? ` after ${until}` : ''}, by itself.`;
        default:
            return `Reading is paused for ${channel} after a reading that did not get through. It starts again by itself, within a day at the latest.`;
    }
}

/**
 * Said of numbers the platform stopped giving: what is shown is the last
 * one it gave. "Facebook has not given clicks and reach since Thu 26 Nov
 * 2026, 12:00. The numbers shown are the last ones it gave."
 */
export function keptNote(
    kept: Partial<Record<MetricName, string | null>>,
    platform: string,
    timezone: string,
): string | null {
    const metrics = metricNames.filter((metric) => kept[metric]);

    if (metrics.length === 0) {
        return null;
    }

    const since = metrics.map((metric) => kept[metric] as string).sort()[0];
    const names = sentenceList(
        metrics.map((metric) => metricLabels[metric].toLowerCase()),
    );

    return `${platform} has not given ${names} since ${formatDateTime(since, timezone)}. ${
        metrics.length === 1
            ? 'The number shown is the last one it gave.'
            : 'The numbers shown are the last ones it gave.'
    }`;
}

/** Said next to a number that is kept from an earlier reading. */
export const keptLabel = 'kept';

/**
 * Whether connecting the channel again is the next step, so that the page
 * offers the way there to someone who can: the channel asks for it, the
 * platform did not accept its token for reading, or the platform said the
 * post is gone and a new connection makes Noon Gun ask once more.
 */
export function needsReconnect(variant: VariantInsights): boolean {
    return (
        variant.gone ||
        variant.state === 'gone' ||
        variant.pause?.kind === 'token' ||
        (variant.channel_status === 'needs_reconnect' &&
            (variant.stale || variant.state === 'blocked'))
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
    const owner = reading.can_set_up;
    const paused = variant.pause
        ? pauseNote(variant.pause, platform, channel, timezone, owner)
        : null;

    switch (variant.state) {
        case 'measured': {
            const checked = variant.checked_at
                ? formatDateTime(variant.checked_at, timezone)
                : 'an earlier reading';

            if (variant.stopped) {
                return `Last measured on ${checked}. A post is read for ${reading.stop_after} after it goes out.`;
            }

            if (variant.stale) {
                if (variant.channel_status !== 'needs_reconnect') {
                    return `These numbers are from ${checked}. ${channel} is disconnected, so they are not updated.`;
                }

                return owner
                    ? `These numbers are from ${checked}. Connect ${channel} again to have them updated.`
                    : `These numbers are from ${checked}. ${channel} needs to be connected again before they are updated. Ask an owner of this workspace.`;
            }

            if (variant.gone) {
                return owner
                    ? `These numbers are from ${checked}. ${platform} says the post is no longer there, so they are not updated. If it is still there, connect ${channel} again and Noon Gun asks once more.`
                    : `These numbers are from ${checked}. ${platform} says the post is no longer there, so they are not updated. If it is still there, ask an owner of this workspace to connect ${channel} again.`;
            }

            if (!reading.enabled) {
                return `These numbers are from ${checked}. Insights are turned off on this instance, so they are not updated.`;
            }

            return paused
                ? `Last checked ${checked}. ${paused}`
                : `Last checked ${checked}.`;
        }

        case 'pending': {
            if (paused) {
                return `Not measured yet. ${paused}`;
            }

            const next = variant.next_reading_at
                ? new Date(variant.next_reading_at)
                : null;

            if (next === null || next.getTime() <= now.getTime()) {
                return owner
                    ? 'Not measured yet. A reading is due now. If this does not change within the hour, check that the scheduler and the queue worker are running.'
                    : 'Not measured yet. A reading is due now. If this does not change within the hour, tell whoever runs this instance.';
            }

            const expected = formatDateTime(next.toISOString(), timezone);

            return variant.asked
                ? `Not measured yet. ${platform} gave no numbers at the last reading. The next one is expected around ${expected}.`
                : `Not measured yet. The first reading is expected around ${expected}.`;
        }

        case 'waiting_once':
            return paused
                ? `Not measured yet. This post went out more than ${reading.stop_after} ago and was never read. Older posts are read once. ${paused}`
                : `Not measured yet. This post went out more than ${reading.stop_after} ago and was never read. Older posts are read once, and this one is waiting for that reading.`;

        case 'no_numbers':
            return `Not measured. ${platform} gave no numbers for this post when it was asked. Open the post on ${platform} to see how it did.`;

        case 'too_old':
            return `Not measured. This post went out more than ${reading.once_within ?? reading.stop_after} ago and was never read, and posts that old are not read. Open the post on ${platform} to see how it did.`;

        case 'unavailable':
            return `${platform} does not give Noon Gun numbers for posts yet.`;

        case 'off':
            return owner
                ? 'Not measured. Insights are turned off on this instance. To turn them on, set NOONGUN_INSIGHTS_ENABLED=true and restart the containers.'
                : 'Not measured. Insights are turned off on this instance. Whoever runs this instance can turn them on.';

        case 'gone':
            return owner
                ? `Not measured. ${platform} says the post is no longer there, so Noon Gun stopped asking. If it is still there, connect ${channel} again and Noon Gun asks once more.`
                : `Not measured. ${platform} says the post is no longer there, so Noon Gun stopped asking. If it is still there, ask an owner of this workspace to connect ${channel} again.`;

        case 'blocked':
            if (variant.channel_status !== 'needs_reconnect') {
                return `Not measured. ${channel} is disconnected, so its numbers are not read.`;
            }

            return owner
                ? `Not measured. Connect ${channel} again to have its numbers read.`
                : `Not measured. ${channel} needs to be connected again before its numbers are read. Ask an owner of this workspace.`;
    }
}

/**
 * "reactions x 1 + comments x 2 + shares x 3", with the weights this
 * instance is set to.
 */
export function scoreFormula(weights: ScoreWeights): string {
    return (['reactions', 'comments', 'shares'] as const)
        .map(
            (metric) =>
                `${metricLabels[metric].toLowerCase()} x ${formatNumber(weights[metric], 4)}`,
        )
        .join(' + ');
}
