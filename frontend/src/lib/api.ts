import type {
    MeResponse, LayerDef, FieldDefinition, ApiFeature,
    FeatureCollection, Geometry, Attributes, GeometryType, FieldError,
    MapLayerAdmin, MapLayerInput, FieldInput,
    AdminUser, UserCreateInput, UserUpdateInput,
    FeatureRevision, ChangeEntry, Capabilities, AttachmentRef,
    GcpPair, GcpInput, GcpUpdateInput
} from './types';
import { t, translateApiError } from './i18n';

const BASE = 'api/';
let csrf = '';

export function setCsrf(token: string): void {
    csrf = token;
}

// ------------------------------------------------------------------------------------
// Redirect to login (401/419) with self-healing and a loop guard.
//
// The "after a week" scenario: the browser holds stale state (typically a heuristically cached
// index.html, occasionally a cookie), login on the server succeeds, but the API keeps returning 401
// and the page would bounce back to login forever. The manual fix used to be a hard reload –
// here we do it automatically:
//  1) If we arrive DIRECTLY after a successful login (login.php adds ?li=1) and the API still
//     returns 401, we reload the app ONCE with a unique URL (?v=…), which bypasses
//     the cache exactly like a manual hard reload.
//  2) Bounce counter: a second bounce to login shortly after does NOT redirect – the app
//     instead shows a calm screen with an explanation and a link (no endless looping).
// After a successful app start everything is reset (authBootOk).
// ------------------------------------------------------------------------------------
export type AuthBounce = 'redirecting' | 'blocked';
let bounceState: AuthBounce | null = null; // what gotoLogin decided (read by +page.svelte)
let actedThisLoad = false;                 // concurrent 401s within one page load = one action

const BOUNCE_KEY = 'zvk_bounce'; // {n, t}: bounce count and time of the last one
const HEAL_KEY = 'zvk_heal';     // the one-time self-heal after ?li=1 has already run
const BOUNCE_WINDOW_MS = 90_000; // bounces further apart = independent events

// sessionStorage may be blocked (SecurityError) – then we fall back to in-memory
// variables (the guard survives at least within a single page load).
let memBounce: { n: number; t: number } | null = null;
let memHeal = false;
function readBounce(): { n: number; t: number } | null {
    try {
        const v = sessionStorage.getItem(BOUNCE_KEY);
        return v ? JSON.parse(v) : null;
    } catch { return memBounce; }
}
function writeBounce(v: { n: number; t: number } | null): void {
    memBounce = v;
    try {
        if (v) sessionStorage.setItem(BOUNCE_KEY, JSON.stringify(v));
        else sessionStorage.removeItem(BOUNCE_KEY);
    } catch { /* storage blocked */ }
}
function healDone(): boolean {
    try { if (sessionStorage.getItem(HEAL_KEY) === '1') return true; } catch { /* ignore */ }
    return memHeal;
}
function markHealDone(): void {
    memHeal = true;
    try { sessionStorage.setItem(HEAL_KEY, '1'); } catch { /* ignore */ }
}

/** Outcome of the last 401/419 handling (for the startup screen). */
export function authBounce(): AuthBounce | null {
    return bounceState;
}

/** Successful app start: bounces and self-heal are reset (the next occurrence is a new event). */
export function authBootOk(): void {
    writeBounce(null);
    memHeal = false;
    try { sessionStorage.removeItem(HEAL_KEY); } catch { /* ignore */ }
    bounceState = null;
    actedThisLoad = false;
}

function gotoLogin(): void {
    if (actedThisLoad) return; // startup calls /me, /layers, /fields concurrently – only the first one reacts
    actedThisLoad = true;

    const now = Date.now();
    const prev = readBounce();
    const n = prev && now - prev.t < BOUNCE_WINDOW_MS ? prev.n : 0;

    // One-time self-heal: we are here DIRECTLY after a successful login (?li=1), yet got a 401.
    const justLoggedIn = new URLSearchParams(window.location.search).get('li') === '1';
    if (justLoggedIn && !healDone()) {
        markHealDone();
        bounceState = 'redirecting';
        window.location.replace('./?v=' + now.toString(36));
        return;
    }

    if (n >= 2) {
        // Third bounce in a row: stop looping, the app shows a calm explanation.
        writeBounce({ n: n + 1, t: now });
        bounceState = 'blocked';
        return;
    }
    writeBounce({ n: n + 1, t: now });
    bounceState = 'redirecting';
    window.location.replace('login.php');
}

export class ApiError extends Error {
    status: number;
    code: string | null;
    fields: FieldError[] | null;
    constructor(message: string, status: number, code: string | null, fields: FieldError[] | null) {
        super(message);
        this.status = status;
        this.code = code;
        this.fields = fields;
    }
}

async function req<T>(method: string, path: string, body?: unknown, signal?: AbortSignal): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json' };
    const opts: RequestInit = { method, headers, credentials: 'same-origin' };
    if (signal) opts.signal = signal;
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }
    if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
        headers['X-CSRF-Token'] = csrf;
    }

    const res = await fetch(BASE + path, opts);
    const text = await res.text();
    let data: unknown = null;
    if (text) {
        try { data = JSON.parse(text); } catch { data = null; }
    }

    if (!res.ok) {
        const info = (data as { error?: {
            message?: string; code?: string;
            params?: Record<string, string | number>; fields?: FieldError[];
        } })?.error ?? {};
        // Expired login session (401) OR an invalid/expired CSRF token (419, code 'csrf')
        // → back to login. Without this, during a long collecting session every further save would silently fail
        // with a generic error (and auto-save would retry forever) while the collector keeps typing.
        if (res.status === 401 || res.status === 419 || info.code === 'csrf' || info.code === 'unauthorized') {
            gotoLogin();
        }
        // The server sends a machine code + a short English message; translation to localized text
        // happens HERE (single choke point) – components keep reading only e.message.
        const fields = (info.fields ?? null) && info.fields!.map((f) => ({
            ...f,
            message: translateApiError(f.code, f.params, f.message)
        }));
        throw new ApiError(
            translateApiError(info.code, info.params, info.message ?? t.serverError(res.status)),
            res.status,
            info.code ?? null,
            fields
        );
    }
    return data as T;
}

function qs(params: Record<string, string | undefined>): string {
    const u = new URLSearchParams();
    for (const [k, v] of Object.entries(params)) {
        if (v !== undefined && v !== '') u.append(k, v);
    }
    const s = u.toString();
    return s ? '?' + s : '';
}

/** Attachment URL: v='t' thumbnail, 'd' display size, '' original. */
export function attachmentUrl(id: number, v: 't' | 'd' | '' = ''): string {
    return BASE + 'attachments/' + id + (v ? '?v=' + v : '');
}

/** Uploads one file to an attachment field (multipart; XHR for upload progress). */
export function uploadAttachment(fieldKey: string, file: File, onProgress?: (frac: number) => void): Promise<AttachmentRef> {
    return new Promise((resolve, reject) => {
        const fd = new FormData();
        fd.append('field_key', fieldKey);
        fd.append('file', file, file.name);
        const xhr = new XMLHttpRequest();
        xhr.open('POST', BASE + 'uploads');
        xhr.withCredentials = true;
        xhr.setRequestHeader('X-CSRF-Token', csrf);
        xhr.setRequestHeader('Accept', 'application/json');
        if (onProgress) xhr.upload.onprogress = (e) => { if (e.lengthComputable) onProgress(e.loaded / e.total); };
        xhr.onerror = () => reject(new ApiError(t.serverError(0), 0, null, null));
        xhr.onload = () => {
            let data: any = null;
            try { data = JSON.parse(xhr.responseText); } catch { /* non-JSON */ }
            if (xhr.status >= 200 && xhr.status < 300 && data) { resolve(data as AttachmentRef); return; }
            const info = data?.error ?? {};
            if (xhr.status === 401 || xhr.status === 419 || info.code === 'csrf' || info.code === 'unauthorized') gotoLogin();
            // 413 from the web server (no JSON) = file exceeds the server limit.
            const code = info.code ?? (xhr.status === 413 ? 'upload_too_large' : null);
            reject(new ApiError(translateApiError(code, info.params, info.message ?? t.serverError(xhr.status)), xhr.status, code, null));
        };
        xhr.send(fd);
    });
}

export const api = {
    me: () => req<MeResponse>('GET', 'me'),
    users: () => req<{ users: AdminUser[] }>('GET', 'users'),
    createUser: (body: UserCreateInput) => req<{ id: number }>('POST', 'users', body),
    updateUser: (id: number, body: UserUpdateInput) =>
        req<{ updated: boolean; id: number }>('PUT', 'users/' + id, body),
    layers: () => req<{ layers: LayerDef[] }>('GET', 'layers'),
    fields: () => req<{ fields: FieldDefinition[] }>('GET', 'fields'),
    allFields: () => req<{ fields: FieldDefinition[] }>('GET', 'fields?all=1'), // incl. removed ones (admin)
    createField: (body: FieldInput) => req<{ id: number }>('POST', 'fields', body),
    updateField: (id: number, body: FieldInput) =>
        req<{ updated: boolean; id: number }>('PUT', 'fields/' + id, body),
    deleteField: (id: number) =>
        req<{ deactivated: boolean; id: number }>('DELETE', 'fields/' + id),
    reorderFields: (order: number[]) =>
        req<{ reordered: boolean }>('POST', 'fields/reorder', { order }),
    features: (params: { bbox?: string; mine?: string; limit?: string }, signal?: AbortSignal) =>
        req<FeatureCollection>('GET', 'features' + qs(params), undefined, signal),
    // Cheap data-state "fingerprint" – to verify that a cached extent is still fresh.
    featuresVersion: (signal?: AbortSignal) => req<{ version: number }>('GET', 'features/version', undefined, signal),
    createFeature: (body: { geometry: Geometry; geometry_type: GeometryType; attributes: Attributes }) =>
        req<ApiFeature>('POST', 'features', body),
    updateFeature: (id: number, body: { geometry?: Geometry; attributes?: Attributes }) =>
        req<ApiFeature>('PUT', 'features/' + id, body),
    deleteFeature: (id: number) =>
        req<{ deleted: boolean; id: number; revision_id?: number }>('DELETE', 'features/' + id),
    // Export selected records to GeoJSON (returns a file to download).
    exportGeoJson: async (ids: number[]): Promise<Blob> => {
        const res = await fetch(BASE + 'features/export', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }
        , body: JSON.stringify({ ids }) });
        if (!res.ok) {
            if (res.status === 401 || res.status === 419) gotoLogin();
            let msg = t.serverError(res.status);
            try {
                const j = JSON.parse(await res.text());
                if (j?.error) msg = translateApiError(j.error.code, j.error.params, j.error.message ?? msg);
            } catch { /* binary/empty */ }
            throw new ApiError(msg, res.status, null, null);
        }
        return res.blob();
    },

    // Map layer management (admin only)
    mapLayers: () => req<{ layers: MapLayerAdmin[] }>('GET', 'map-layers'),
    createLayer: (body: MapLayerInput) => req<{ id: number }>('POST', 'map-layers', body),
    updateLayer: (id: number, body: MapLayerInput) =>
        req<{ updated: boolean; id: number }>('PUT', 'map-layers/' + id, body),
    deleteLayer: (id: number) =>
        req<{ deleted: boolean; id: number }>('DELETE', 'map-layers/' + id),
    reorderLayers: (order: number[]) =>
        req<{ reordered: boolean }>('POST', 'map-layers/reorder', { order }),
    layerCapabilities: (url: string, service: 'WMS' | 'WMTS') =>
        req<{ capabilities: Capabilities }>('POST', 'map-layers/capabilities', { url, service }),
    layerCrs: (code: string) =>
        req<{ code: string; proj4def: string }>('GET', 'map-layers/crs?code=' + encodeURIComponent(code)),

    // Change history / restore (admin only)
    changes: (limit = 50, offset = 0) =>
        req<{ changes: ChangeEntry[] }>('GET', 'changes?limit=' + limit + '&offset=' + offset),
    featureRevisions: (id: number) =>
        req<{ revisions: FeatureRevision[] }>('GET', 'features/' + id + '/revisions'),
    restoreFeature: (id: number, revisionId: number) =>
        req<ApiFeature>('POST', 'features/' + id + '/restore', { revision_id: revisionId }),

    // Reference points (GCP pairs)
    gcpList: (params?: { mine?: boolean }) =>
        req<{ pairs: GcpPair[] }>('GET', 'gcp' + (params?.mine ? '?mine=1' : '')),
    gcpNextLabel: () =>
        req<{ label: string }>('GET', 'gcp/next-label'),
    gcpGet: (id: number) =>
        req<GcpPair>('GET', 'gcp/' + id),
    createGcp: (body: GcpInput) =>
        req<GcpPair>('POST', 'gcp', body),
    updateGcp: (id: number, body: GcpUpdateInput) =>
        req<GcpPair>('PUT', 'gcp/' + id, body),
    deleteGcp: (id: number) =>
        req<{ deleted: boolean; id: number }>('DELETE', 'gcp/' + id)
};

