import type { MediaRules } from '@/types/posts';

const typeNames: Record<string, string> = {
    'image/jpeg': 'JPEG',
    'image/png': 'PNG',
    'image/webp': 'WebP',
};

/**
 * A size in megabytes, written the same way everywhere, also by the server
 * (PostPreconditions::megabytes). A limit that was set in whole binary or
 * whole decimal megabytes is that number, so 20 x 1024 x 1024 bytes is "20"
 * and 10,000,000 bytes is "10". Anything else, such as the size of a file,
 * is decimal megabytes to one place.
 */
export function megabytes(bytes: number): string {
    for (const unit of [1024 * 1024, 1_000_000]) {
        if (bytes >= unit && bytes % unit === 0) {
            return String(bytes / unit);
        }
    }

    return String(Math.round(bytes / 100_000) / 10);
}

export function typeName(type: string): string {
    return typeNames[type] ?? (type.split('/')[1] ?? type).toUpperCase();
}

/**
 * The accepted types in words: "JPEG, PNG or WebP".
 */
export function typeList(types: string[]): string {
    const names = types.map(typeName);

    return names.length > 1
        ? `${names.slice(0, -1).join(', ')} or ${names[names.length - 1]}`
        : (names[0] ?? '');
}

export function imageCount(count: number): string {
    return count === 1 ? '1 image' : `${count} images`;
}

/**
 * What a picture says to somebody who cannot see it.
 */
export function altFor(text: string | null | undefined): string {
    const typed = (text ?? '').trim();

    return typed !== '' ? typed : 'Image without a description';
}

// The characters PHP takes off with trim() and rtrim().
const trailing = /[ \n\r\t\v\0]+$/;
const leading = /^[ \n\r\t\v\0]+/;

/**
 * True when the link of a text with this many images is sent as the last
 * line of the text, because the images take the place of the link card.
 */
export function linkGoesInText(
    link: string,
    images: number,
    rule: MediaRules['link_rule'],
): boolean {
    return images > 0 && rule === 'in_text' && link.trim() !== '';
}

/**
 * The text as the platform gets it, built the way the server builds it
 * (ChannelCapabilities::textFor), so that the count on the page is the
 * count the readiness check makes.
 */
export function textAsSent(
    body: string,
    link: string,
    images: number,
    rule: MediaRules['link_rule'],
): string {
    const address = link.replace(trailing, '').replace(leading, '');
    const text = body.replace(trailing, '');

    if (!linkGoesInText(address, images, rule) || text.endsWith(address)) {
        return body;
    }

    return text === '' ? address : `${text}\n\n${address}`;
}

/**
 * A link with the campaign parameters of its text, for the count while the
 * link is being typed. The server builds the link that is sent
 * (LinkWithUtm) and gives it for the saved link; this follows it for a link
 * that is not saved yet: the parameters come first, what the link already
 * carries stays, and a fragment stays last.
 */
export function withCampaign(link: string, campaign: string | null): string {
    const address = link.trim();

    if (campaign === null || campaign === '' || address === '') {
        return link;
    }

    const [base, fragment] = splitOnce(address, '#');
    const [path, query] = splitOnce(base, '?');
    const own = new Set(
        (query ?? '')
            .split('&')
            .map((pair) => pair.split('=')[0])
            .filter((key) => key !== ''),
    );
    // A value already on the link is kept, so that parameter is not added.
    const added = campaign
        .split('&')
        .filter((pair) => !own.has(pair.split('=')[0]));
    const all = [...added, ...(query ? [query] : [])].join('&');

    return `${path}${all === '' ? '' : `?${all}`}${fragment === null ? '' : `#${fragment}`}`;
}

function splitOnce(text: string, at: string): [string, string | null] {
    const index = text.indexOf(at);

    return index === -1
        ? [text, null]
        : [text.slice(0, index), text.slice(index + 1)];
}
