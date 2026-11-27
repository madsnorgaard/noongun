import type { MediaRules } from '@/types/posts';

const typeNames: Record<string, string> = {
    'image/jpeg': 'JPEG',
    'image/png': 'PNG',
    'image/webp': 'WebP',
};

/**
 * A size in megabytes, to one place and without a trailing zero.
 */
export function megabytes(bytes: number): string {
    return String(Math.round((bytes / 1024 / 1024) * 10) / 10);
}

/**
 * The accepted types in words: "JPEG, PNG or WebP".
 */
export function typeList(types: string[]): string {
    const names = types.map(
        (type) => typeNames[type] ?? (type.split('/')[1] ?? type).toUpperCase(),
    );

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
