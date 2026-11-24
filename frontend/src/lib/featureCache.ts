// Front-end cache of loaded geometries keyed by map extent (bounding box).
//
// WHY: while browsing the map, users often "hover around" one spot – zoom in,
// zoom back out, pan a bit and come back. Without a cache every `moveend` would send
// a new DB query, even when we already hold data that fully covers that extent. The cache
// intercepts these repeated queries and returns the stored set WITHOUT a request.
//
// HOW: every real fetch is stored under the (slightly enlarged) bbox we loaded.
// On the next view we look for a stored entry whose bbox FULLY contains the CURRENT
// extent – then the stored set is a superset of the visible features and can be returned as is.
//   • The enlargement (buffer) is key to the "zoom in, then right back out" scenario: we fetch
//     a larger area than is visible, so a small zoom-out/pan still falls inside
//     the stored bbox → cache hit, no request.
//   • Memory is bounded by a TTL (old entries are dropped) + a cap on entry count (LRU).
//   • After EVERY data change (create/update/delete/restore) clear() must be called –
//     otherwise the cache would return stale state. Invalidation is the caller's job (+page.svelte).

import type { ApiFeature } from './types';

export type Bbox = [number, number, number, number]; // [minLon, minLat, maxLon, maxLat] (EPSG:4326)

// Query size cap sent to the server. MUST match the server-side
// FeatureRepository::DEFAULT_LIMIT – it is how we detect a truncated (incomplete)
// response: if exactly that many rows come back, the server may have truncated the set
// (ORDER BY id DESC LIMIT), so it is NOT safe to serve smaller sub-extents from it.
export const FETCH_LIMIT = 3000;

// How long a loaded set stays usable (the user asked for "a few minutes back").
const MAX_AGE_MS = 3 * 60_000;

// Cap on the number of stored extents. When exceeded, the least recently used one is dropped (LRU).
// Keeps memory in check even when the user pans across a large area (a few thousand features total).
const MAX_ENTRIES = 16;

// How much to enlarge the fetched extent beyond the visible one (fraction of side length on each
// side). 0.25 → sides +50 % (1.5×), area ~2.25×. Larger = better hit chance on
// zoom-out/pan, but more data transferred and a higher risk of truncation at FETCH_LIMIT.
const BUFFER = 0.25;

// Tolerance for containment checks – absorbs tiny float drift (a re-derived
// identical extent may be "larger" by nano-degrees). The buffer gives far more headroom anyway.
const EPS = 1e-9;

interface Entry {
    bbox: Bbox;            // actually fetched (enlarged) area = the entry's coverage
    key: string;           // filter signature (e.g. "mine" vs "all") – different filters = different data
    features: ApiFeature[]; // stored set (same reference that `features` received in state)
    complete: boolean;     // false = response may have been truncated → must not serve sub-extents
    version: number;       // data-state "fingerprint" at load time – the caller checks freshness against it
    expires: number;       // epoch ms after which the entry is expired
}

/** What get() returns on a hit: the data + the version it was captured at. */
export interface CacheHit {
    features: ApiFeature[];
    version: number;
}

/** Expands the extent by the buffer and clamps it to the valid geographic coordinate range. */
export function expandBbox(view: Bbox, factor = BUFFER): Bbox {
    const w = view[2] - view[0];
    const h = view[3] - view[1];
    const px = w * factor;
    const py = h * factor;
    const clampLon = (v: number) => Math.min(180, Math.max(-180, v));
    const clampLat = (v: number) => Math.min(90, Math.max(-90, v));
    return [
        clampLon(view[0] - px),
        clampLat(view[1] - py),
        clampLon(view[2] + px),
        clampLat(view[3] + py),
    ];
}

/** Does `outer` fully contain `inner`? (with a small EPS tolerance) */
function contains(outer: Bbox, inner: Bbox): boolean {
    return outer[0] - EPS <= inner[0]
        && outer[1] - EPS <= inner[1]
        && outer[2] + EPS >= inner[2]
        && outer[3] + EPS >= inner[3];
}

function area(b: Bbox): number {
    return (b[2] - b[0]) * (b[3] - b[1]);
}

function bboxEqual(a: Bbox, b: Bbox): boolean {
    return Math.abs(a[0] - b[0]) <= EPS && Math.abs(a[1] - b[1]) <= EPS
        && Math.abs(a[2] - b[2]) <= EPS && Math.abs(a[3] - b[3]) <= EPS;
}

class FeatureCache {
    // Map preserves insertion order → we use it as an LRU (first key = oldest).
    // A hit/insert "moves the entry to the back" (delete + set) = marks it most recently used.
    private entries = new Map<number, Entry>();
    private seq = 0;
    private gen = 0; // data generation – incremented on every clear() (invalidation)

    /**
     * Current generation. The caller records it before a fetch and checks afterwards that it
     * has not changed: if a data change (clear) happened during loading, the response is
     * stale and MUST NOT be cached (otherwise just-invalidated data would "come back to life").
     */
    get generation(): number {
        return this.gen;
    }

    /** Drops expired entries (lazy – called on every get/put; no timer = no leak). */
    private purge(now: number): void {
        for (const [id, e] of this.entries) {
            if (e.expires <= now) this.entries.delete(id);
        }
    }

    /**
     * Returns the stored set (+ its version) that FULLY covers the given extent for the given filter, or
     * null. Among several matches it picks the tightest (smallest area) → fewest surplus
     * features. A hit refreshes the entry's LRU age. NOTE: freshness (version) is checked by the caller via a probe
     * – here we only return the version the set was captured at.
     */
    get(view: Bbox, key: string, now: number = Date.now()): CacheHit | null {
        this.purge(now);
        let bestId = -1;
        let bestEntry: Entry | null = null;
        for (const [id, e] of this.entries) {
            if (e.key !== key || !e.complete) continue;
            if (!contains(e.bbox, view)) continue;
            if (bestEntry === null || area(e.bbox) < area(bestEntry.bbox)) {
                bestId = id;
                bestEntry = e;
            }
        }
        if (bestEntry === null) return null;
        // LRU bump: move to the end of the order.
        this.entries.delete(bestId);
        this.entries.set(bestId, bestEntry);
        return { features: bestEntry.features, version: bestEntry.version };
    }

    /**
     * Stores the result of a real fetch.
     * @param fetched  the bbox that was actually fetched (enlarged via expandBbox)
     * @param complete false if the response may have been truncated (features.length >= FETCH_LIMIT)
     */
    put(fetched: Bbox, key: string, features: ApiFeature[], complete: boolean, version: number, now: number = Date.now()): void {
        // A truncated (incomplete) set is not stored at all: get() must never return it for
        // smaller sub-extents anyway, so it would just hold the most memory (up to FETCH_LIMIT features) and take
        // an LRU slot from useful entries. Dropping it right away = most memory-efficient.
        if (!complete) return;
        this.purge(now);
        // Replace a matching entry (same filter + nearly identical bbox) instead of adding a duplicate –
        // typically a forced refetch of the same extent. This also refreshes the LRU age.
        for (const [id, e] of this.entries) {
            if (e.key === key && bboxEqual(e.bbox, fetched)) {
                this.entries.delete(id);
                break;
            }
        }
        // LRU: when full, drop the oldest entry (first in order).
        while (this.entries.size >= MAX_ENTRIES) {
            const oldest = this.entries.keys().next().value;
            if (oldest === undefined) break;
            this.entries.delete(oldest);
        }
        this.entries.set(++this.seq, {
            bbox: fetched, key, features, complete, version,
            expires: now + MAX_AGE_MS,
        });
    }

    /** Drops the whole cache. Call after EVERY data change (create/update/delete/restore). */
    clear(): void {
        this.gen++;
        this.entries.clear();
    }

    /** Number of stored entries (diagnostics / tests). */
    get size(): number {
        return this.entries.size;
    }
}

// A single shared module singleton for the whole app.
export const featureCache = new FeatureCache();
