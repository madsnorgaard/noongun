import type { MediaItem } from '@/types/posts';

/**
 * One queue of image uploads for the whole page. The server decodes every
 * image it is sent, which takes memory, so the columns of the editor do not
 * each send on their own: one file is on its way at a time, in the order
 * the files were given, whichever column they were given to.
 *
 * When the server says it is busy (429) the file is not failed. The queue
 * waits as long as the server asks and sends it again.
 *
 * A file that was already stored from this page is not sent a second time:
 * the same file given to another column, or given twice, is the same image.
 */

export type UploadState = 'waiting' | 'sending' | 'held';

/** Status 0 means that the request never got an answer. */
export type UploadFailure = { status: number; body: string };

export type UploadJob = {
    file: File;
    url: string;
    field: string;
    onState: (state: UploadState, progress: number) => void;
    onDone: (image: MediaItem) => void;
    onFail: (failure: UploadFailure) => void;
};

type Entry = {
    key: string;
    jobs: UploadJob[];
    request: XMLHttpRequest | null;
    timer: ReturnType<typeof setTimeout> | null;
    busyAnswers: number;
};

const DEFAULT_WAIT_SECONDS = 10;
const LONGEST_WAIT_SECONDS = 120;
// After this many "busy" answers in a row the file is handed back as failed,
// with a way to try again by hand.
const MOST_BUSY_ANSWERS = 8;

const entries: Entry[] = [];
const stored = new Map<string, MediaItem>();
let active: Entry | null = null;

/**
 * What tells two picks of the same file apart from two files. The content
 * is not read: name, size and the time the file was last changed.
 */
export function fileKey(file: File): string {
    return `${file.name}\u0000${file.size}\u0000${file.lastModified}`;
}

/**
 * The image a file was stored as earlier on this page, if it was.
 */
export function storedCopy(file: File): MediaItem | undefined {
    return stored.get(fileKey(file));
}

function csrfToken(): string | null {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : null;
}

function settle(entry: Entry): void {
    const index = entries.indexOf(entry);

    if (index !== -1) {
        entries.splice(index, 1);
    }

    if (active === entry) {
        active = null;
    }

    next();
}

function waitSeconds(request: XMLHttpRequest): number {
    const asked = Number.parseInt(
        request.getResponseHeader('Retry-After') ?? '',
        10,
    );

    return Math.min(
        LONGEST_WAIT_SECONDS,
        Math.max(1, Number.isFinite(asked) ? asked : DEFAULT_WAIT_SECONDS),
    );
}

function send(entry: Entry): void {
    const job = entry.jobs[0];
    const request = new XMLHttpRequest();
    const form = new FormData();
    const token = csrfToken();

    form.append(job.field, job.file, job.file.name);
    entry.request = request;
    entry.jobs.forEach((each) => each.onState('sending', 0));

    request.upload.onprogress = (event) => {
        if (event.lengthComputable && event.total > 0) {
            const progress = Math.round((event.loaded / event.total) * 100);

            entry.jobs.forEach((each) => each.onState('sending', progress));
        }
    };

    request.onload = () => {
        entry.request = null;

        if (request.status === 429) {
            entry.busyAnswers++;

            if (entry.busyAnswers < MOST_BUSY_ANSWERS) {
                entry.jobs.forEach((each) => each.onState('held', 0));
                entry.timer = setTimeout(
                    () => {
                        entry.timer = null;
                        send(entry);
                    },
                    waitSeconds(request) * 1000,
                );

                return;
            }
        }

        let image: MediaItem | null = null;

        if (request.status === 200 || request.status === 201) {
            try {
                image = JSON.parse(request.responseText) as MediaItem;
            } catch {
                image = null;
            }
        }

        const jobs = [...entry.jobs];

        if (image !== null && typeof image.id === 'number') {
            const kept = image;

            stored.set(entry.key, kept);
            jobs.forEach((each) => each.onDone(kept));
        } else {
            jobs.forEach((each) =>
                each.onFail({
                    // An answer that says "stored" and holds no image is
                    // treated as a failure of the server.
                    status: image === null ? request.status : 500,
                    body: request.responseText,
                }),
            );
        }

        settle(entry);
    };

    request.onerror = request.ontimeout = () => {
        const jobs = [...entry.jobs];

        entry.request = null;
        jobs.forEach((each) => each.onFail({ status: 0, body: '' }));
        settle(entry);
    };

    request.open('POST', job.url);
    request.setRequestHeader('Accept', 'application/json');
    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    if (token !== null) {
        request.setRequestHeader('X-XSRF-TOKEN', token);
    }

    request.send(form);
}

function next(): void {
    if (active !== null || entries.length === 0) {
        return;
    }

    active = entries[0];
    send(active);
}

/**
 * Puts a file in the queue and returns what cancels it. Cancelling a file
 * that is on its way stops the request; one that is waiting is taken out.
 */
export function enqueue(job: UploadJob): () => void {
    const key = fileKey(job.file);
    let entry = entries.find((candidate) => candidate.key === key);

    if (entry) {
        // The same file is already on its way for another column.
        entry.jobs.push(job);
    } else {
        entry = {
            key,
            jobs: [job],
            request: null,
            timer: null,
            busyAnswers: 0,
        };
        entries.push(entry);
    }

    const owner = entry;

    job.onState('waiting', 0);
    next();

    return () => {
        const index = owner.jobs.indexOf(job);

        if (index === -1) {
            return;
        }

        owner.jobs.splice(index, 1);

        if (owner.jobs.length > 0) {
            return;
        }

        if (owner.timer !== null) {
            clearTimeout(owner.timer);
            owner.timer = null;
        }

        if (owner.request !== null) {
            const request = owner.request;

            owner.request = null;
            request.onload = request.onerror = request.ontimeout = null;
            request.abort();
        }

        if (entries.includes(owner)) {
            settle(owner);
        }
    };
}
