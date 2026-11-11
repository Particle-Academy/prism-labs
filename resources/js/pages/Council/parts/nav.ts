/**
 * Helpers for the Council nav (Changes, Members) and the members pages: the one
 * link guard, and table sorting. Plain .ts (no JSX), so a host's
 * `./Pages/**\/*.tsx` glob never mistakes it for a page.
 */

/** Nav buttons: the current section is solid, the other is ghost. */
export function navVariant(current: 'changes' | 'members', key: 'changes' | 'members'): 'default' | 'ghost' {
    return current === key ? 'default' : 'ghost';
}

/**
 * A project URL is agent-supplied. The server refuses anything but absolute
 * http or https, and this refuses it again before it becomes a link, so a
 * `javascript:` or `data:` URL that reached a row some other way is drawn as
 * text, never as something to click.
 */
export function safeUrl(url: string | null | undefined): string | null {
    if (typeof url !== 'string' || !/^https?:\/\//i.test(url) || /\s/.test(url)) return null;
    try {
        const parsed = new URL(url);

        return (parsed.protocol === 'http:' || parsed.protocol === 'https:') && parsed.hostname !== '' ? url : null;
    } catch {
        return null;
    }
}

/** Sort direction toggling that matches react-fancy's Table.Column: a new key starts ascending. */
export type Sort<K extends string> = { key: K | null; dir: 'asc' | 'desc' };

export function nextSort<K extends string>(current: Sort<K>, key: K): Sort<K> {
    return current.key === key ? { key, dir: current.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'asc' };
}

export function compare(a: string | number | null, b: string | number | null): number {
    if (a === b) return 0;
    if (a === null) return 1;
    if (b === null) return -1;

    return typeof a === 'number' && typeof b === 'number' ? a - b : String(a).localeCompare(String(b));
}
