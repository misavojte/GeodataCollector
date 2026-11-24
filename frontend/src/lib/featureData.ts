// Data layer for map geometries: loads the extent with a front-end cache + a cheap freshness
// check (version probe). Kept separate from the UI (+page.svelte) so future loading/cache changes
// live in ONE file.
//
// Layers:
//   • featureCache.ts – PURE cache (LRU + TTL + containment + version), framework-free, tested.
//   • featureData.ts  – HERE: orchestration on top of the cache (freshness probe, "last one wins",
//                       invalidation, extent filter). Depends on api + cache + toast.
//   • +page.svelte    – UI only: holds `features`/`selected`, calls loadFeatureSet() on moveend,
//                       calls invalidateFeatures() after its own data changes.
//
// Flow (on every moveend): loadFeatureSet(bbox, {mine, force})
//   – cache hit + matching version (probe) → returns the stored set WITHOUT a full query,
//   – otherwise a full fetch (enlarged extent) → stores in cache + returns,
//   – null = "do nothing" (overtaken by a newer move / invalidation during loading / error).

import { api, ApiError } from './api';
import { featureCache, expandBbox, FETCH_LIMIT, type Bbox } from './featureCache';
import { toast } from './toast.svelte';
import { MSG } from './i18n';
import type { ApiFeature, Geometry } from './types';

// "Last load wins" token: a late older fetch does not overwrite a newer (already displayed) extent.
let loadEpoch = 0;
// Cancelling an overtaken fetch: a new load aborts the previous one, so we don't download and
// parse data we would discard anyway (fast map panning on a weak connection).
let inFlightController: AbortController | null = null;

// Memo of the last data-state "fingerprint": during this time we don't re-probe the server (quick "hovering around")
const PROBE_MEMO_MS = 10_000; // 10 seconds: during this time a cached extent is considered fully fresh without network requests
let probeMemo: { version: number; at: number } | null = null;

// Cheap data-version query (with a short memo). null = probe failed → the caller uses the cache or a full fetch.
async function probeVersion(signal?: AbortSignal): Promise<number | null> {
    const now = Date.now();
    if (probeMemo && now - probeMemo.at < PROBE_MEMO_MS) return probeMemo.version;
    const genAtStart = featureCache.generation;
    try {
        const v = (await api.featuresVersion(signal)).version;
        // Update the memo only SAFELY: (1) not if a local invalidation happened in the meantime (generation
        // change) – otherwise a late probe would "revive" a stale version; (2) never lower the version
        // (the server version is monotonic) – so an older concurrent probe can't overwrite a newer one.
        if (featureCache.generation === genAtStart && (probeMemo === null || v >= probeMemo.version)) {
            probeMemo = { version: v, at: Date.now() };
        }
        return v;
    } catch (err) {
        if ((err as { name?: string })?.name === 'AbortError') {
            throw err;
        }
        return null;
    }
}

/** Invalidation after OUR OWN data change (create/update/delete/restore): drops the cache and the version memo. */
export function invalidateFeatures(): void {
    featureCache.clear();
    probeMemo = null;
}

/**
 * Loads the feature set for an extent. Returns the set to display, or null = "do nothing":
 * this load was overtaken by a newer move, an invalidation happened during loading, or the fetch failed
 * (the error is reported via toast here). Caller: `const set = await loadFeatureSet(...); if (set) features = set;`
 */
export async function loadFeatureSet(
    bbox: Bbox,
    opts: { mine: boolean; force?: boolean; retried?: boolean }
): Promise<ApiFeature[] | null> {
    // Every load (even a cache hit) advances the token → "last one wins".
    const epoch = ++loadEpoch;
    // A new load cancels the previous pending fetch/probe (we overtook it with another map move).
    inFlightController?.abort();
    const controller = new AbortController();
    inFlightController = controller;
    const gen = featureCache.generation; // data generation (changes on invalidation); checked after await
    const key = opts.mine ? 'mine' : 'all'; // different filters = different data → separate namespace

    // Cache hit: the stored set fully covers the extent. Before using it we cheaply check the data-state "fingerprint"
    // (probe) – so other users' changes show up without pulling the full geometry set on every move.
    if (!opts.force) {
        const hit = featureCache.get(bbox, key);
        if (hit) {
            try {
                const v = await probeVersion(controller.signal);
                if (epoch !== loadEpoch || featureCache.generation !== gen) return null; // overtaken by a newer move/change
                if (v !== null && v === hit.version) {
                    if (inFlightController === controller) inFlightController = null;
                    return hit.features.slice(); // fresh → copy of the reference (the map rebuilds)
                }
                if (v !== null) {
                    featureCache.clear(); // version changed (someone else's edit) → drop and refetch
                } else {
                    // Probe failed (503, network outage etc.). But we DO have cached data for this extent!
                    // Instead of hammering an overloaded server with a full query, return the cached data (fallback).
                    if (inFlightController === controller) inFlightController = null;
                    return hit.features.slice();
                }
            } catch (err) {
                if (inFlightController === controller) inFlightController = null;
                if ((err as { name?: string })?.name === 'AbortError') return null;
                // On any other probe error we likewise fall back safely to the cached data:
                return hit.features.slice();
            }
        }
    }

    // Full fetch (miss / forced / cache no longer fresh). Enlarged extent – a small zoom-out/pan
    // then falls inside it and hits the cache.
    const fetchBbox = expandBbox(bbox);
    const gen2 = featureCache.generation; // a possible clear above advanced the generation
    try {
        const resp = await api.features({
            bbox: fetchBbox.join(','), mine: opts.mine ? '1' : '', limit: String(FETCH_LIMIT)
        }, controller.signal);
        if (inFlightController === controller) inFlightController = null;
        if (epoch !== loadEpoch) return null; // a newer request went out meanwhile → discard
        // Data change (clear) during the fetch → the response is stale: neither display nor store it.
        if (featureCache.generation !== gen2) return null;
        // Fresh from the fetch → skip probing for a short while. Never lower the version (monotonic; a concurrent probe
        // may already have stored a newer one). NOTE: should the server version occasionally miss a real change
        // (failure of the best-effort audit write), staleness is bounded by the cache TTL.
        if (probeMemo === null || resp.version >= probeMemo.version) {
            probeMemo = { version: resp.version, at: Date.now() };
        }
        // complete=false → the response may have been truncated at the limit; the cache must not use such a set for
        // smaller sub-extents (older features cut off by the LIMIT would be missing).
        featureCache.put(fetchBbox, key, resp.features, resp.features.length < FETCH_LIMIT, resp.version);
        return resp.features;
    } catch (e) {
        if (inFlightController === controller) inFlightController = null;
        // An aborted (superseded) request is not an error – ignore silently.
        if ((e as { name?: string })?.name === 'AbortError') return null;
        if (epoch !== loadEpoch) return null; // a newer one went out meanwhile → don't report the error

        // Rescue fallback: if the full query failed (e.g. 503) but we have valid cached data
        // for this extent (e.g. with force or just before the error), return it silently without alarming the user.
        const fallbackHit = featureCache.get(bbox, key);
        if (fallbackHit) {
            return fallbackHit.features.slice();
        }

        // Transient server error (5xx, typically a dropped DB connection on shared hosting) or a network
        // outage: ONE silent retry after a short delay. Only the second failure is reported via toast.
        const transient = !(e instanceof ApiError) || e.status >= 500;
        if (transient && !opts.retried) {
            await new Promise((r) => setTimeout(r, 900));
            if (epoch !== loadEpoch) return null; // the map moved meanwhile → a newer load took over
            return loadFeatureSet(bbox, { ...opts, retried: true });
        }
        console.error(e);
        // Error code in parentheses (e.g. db_2006 = MySQL error number): a user's report then
        // shows right away WHICH error occurred on the server, without digging through server logs.
        const code = e instanceof ApiError && e.code ? ` (${e.code})` : '';
        toast(MSG.loadError + code, 'error');
        return null;
    }
}

/**
 * Does the geometry's bounding box intersect the given extent? The map also draws features from the buffer/cache around the extent, but
 * the Records list must match EXACTLY the visible part of the map – hence this filter.
 */
export function geomInView(g: Geometry, v: Bbox): boolean {
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    const walk = (c: any): void => {
        if (typeof c[0] === 'number') {
            if (c[0] < minX) minX = c[0];
            if (c[0] > maxX) maxX = c[0];
            if (c[1] < minY) minY = c[1];
            if (c[1] > maxY) maxY = c[1];
        } else {
            for (const cc of c) walk(cc);
        }
    };
    walk(g.coordinates);
    return minX <= v[2] && maxX >= v[0] && minY <= v[3] && maxY >= v[1];
}
