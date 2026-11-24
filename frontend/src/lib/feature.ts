import type { ApiFeature } from './types';
import { MSG, DATE_LOCALE, t } from './i18n';

/**
 * Human-readable feature name from the attributes (the "nazev" field); when missing,
 * returns a placeholder "Object #id". Same logic as the detail card title.
 */
export function featureTitle(f: ApiFeature): string {
    const n = f.properties.attributes?.nazev;
    return typeof n === 'string' && n.trim() !== '' ? n.trim() : `${MSG.untitled} #${f.id}`;
}

/** Who made the last change (updated_by, otherwise the author). Empty when the name is missing. */
export function featureChangedBy(f: ApiFeature): string {
    const m = f.properties.meta;
    return m.updated_by_name || m.created_by_name || '';
}

/**
 * Timestamp of the last change in ms (updated_at, otherwise created_at).
 * A MySQL string ("YYYY-MM-DD HH:MM:SS") is interpreted as local time.
 * Returns 0 for an invalid value so sorting doesn't break.
 */
export function featureChangedMs(f: ApiFeature): number {
    const s = f.properties.meta.updated_at || f.properties.meta.created_at || '';
    const t = Date.parse(s.replace(' ', 'T'));
    return Number.isNaN(t) ? 0 : t;
}

/**
 * Short relative time in the UI language; older than a week shows the date.
 * `now` is passed in from outside (a ticking $state) so the labels keep refreshing
 * even without data changes – otherwise "just now" would freeze until the next features load.
 */
export function relativeTime(ms: number, now: number = Date.now()): string {
    if (!ms) return '';
    const sec = Math.floor((now - ms) / 1000);
    if (sec < 45) return t.rel.now;
    const min = Math.floor(sec / 60);
    if (min < 60) return t.rel.min(min);
    const hod = Math.floor(min / 60);
    if (hod < 24) return t.rel.hour(hod);
    const dny = Math.floor(hod / 24);
    if (dny < 7) return t.rel.day(dny);
    return new Date(ms).toLocaleDateString(DATE_LOCALE);
}

/** Full date and time for the tooltip (exact value). */
export function fullDateTime(ms: number): string {
    return ms ? new Date(ms).toLocaleString(DATE_LOCALE) : '';
}
