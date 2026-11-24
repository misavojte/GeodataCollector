import { cs, type Dict } from './i18n.cs';
import { en } from './i18n.en';

// The language is resolved ONCE when the module loads; switching writes a cookie and reloads
// the page (location.reload). No reactivity is needed – all constants
// below are immutable for the whole lifetime of the page.
//
// The source of truth is the `zvk_lang` cookie (not localStorage): login.php reads it too,
// so the login page and the app never diverge.

export type Locale = 'cs' | 'en';
const LANG_COOKIE = 'zvk_lang';

function readCookieLocale(): Locale | null {
    try {
        const m = /(?:^|;\s*)zvk_lang=(cs|en)(?:;|$)/.exec(document.cookie);
        return m ? (m[1] as Locale) : null;
    } catch {
        return null; // cookies blocked / not running in a browser
    }
}

// No cookie yet → English until /api/me tells us the configured default (see applyDefaultLocale).
// login.php normally sets the cookie already, so this fallback is rare.
function readLocale(): Locale {
    return readCookieLocale() ?? 'en';
}

export const locale: Locale = readLocale();
export const t: Dict = locale === 'en' ? en : cs;

/** Locale for Intl date and time formatting (toLocaleDateString etc.). */
export const DATE_LOCALE = locale === 'en' ? 'en-GB' : 'cs-CZ';

// Document language (app.html has a static lang="cs" only as the default before startup).
try { document.documentElement.lang = locale; } catch { /* outside the browser (build tooling) */ }

function writeLocaleCookie(value: Locale): void {
    document.cookie = `${LANG_COOKIE}=${value}; path=/; max-age=31536000; SameSite=Lax`
        + (location.protocol === 'https:' ? '; Secure' : '');
}

/** Switches the language and reloads the page. Unsaved work is protected by the existing beforeunload guard. */
export function switchLocale(): void {
    const next: Locale = locale === 'cs' ? 'en' : 'cs';
    try { writeLocaleCookie(next); } catch { /* cookies blocked → the current language stays */ }
    location.reload();
}

/**
 * Applies the server's configured default language when the user has never chosen one
 * (no cookie). Stores it and reloads once if it differs from the language already in use.
 * Returns true when a reload was triggered.
 */
export function applyDefaultLocale(def: Locale | undefined): boolean {
    if (def !== 'cs' && def !== 'en') return false;
    if (readCookieLocale() !== null) return false;
    try { writeLocaleCookie(def); } catch { return false; }
    if (readCookieLocale() !== def) return false; // cookies blocked → avoid a reload loop
    if (def === locale) return false;
    location.reload();
    return true;
}

/**
 * Translates an API error by its machine code (the server sends the code + a short English
 * message as a fallback). Unknown code → the server's fallback.
 */
export function translateApiError(
    code: string | null | undefined,
    params: Record<string, string | number> | undefined,
    fallback: string
): string {
    if (!code) return fallback;
    // Database errors have the code db_<errno> – one shared message.
    const key = code.startsWith('db_') ? 'db_error' : code;
    const entry = (t.apiErr as Record<string, string | ((p: Record<string, string | number>) => string)>)[key];
    if (entry === undefined) return fallback;
    return typeof entry === 'function' ? entry(params ?? {}) : entry;
}

// Backward-compatible aliases – existing imports keep working unchanged.
export const GUIDE = t.guide;
export const EDIT_HINT = t.editHint;
export const DELETE_HINT = t.deleteHint;
export const MSG = t.msg;
export const TYPE_LABEL = t.typeLabel;
