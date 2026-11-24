// Registers arbitrary CRSs via proj4 + normalizes identifiers from GetCapabilities.
// OpenLayers then automatically reprojects the raster into the EPSG:3857 view.
import proj4 from 'proj4';
import { register } from 'ol/proj/proj4';
import { get as getProjection } from 'ol/proj';
import { t } from '$lib/i18n';
import type { CrsCfg } from '$lib/types';

const registered = new Set<string>();

/** Registers a CRS in OL (idempotent per code). The built-in 3857/4326 need no registration. */
export function registerCrs(cfg: CrsCfg): void {
    if (!cfg?.code || !cfg.proj4def || registered.has(cfg.code)) return;
    proj4.defs(cfg.code, cfg.proj4def);
    register(proj4); // pushes all proj4 defs into the OL registry (cheap, idempotent)
    const p = getProjection(cfg.code);
    if (p) {
        if (cfg.extent) p.setExtent(cfg.extent);
        if (cfg.worldExtent) p.setWorldExtent(cfg.worldExtent);
    }
    registered.add(cfg.code);
}

const WELL_KNOWN: Record<string, string> = {
    'urn:ogc:def:crs:ogc:1.3:crs84': 'EPSG:4326',
    'urn:ogc:def:wkss:ogc:1.0:googlemapscompatible': 'EPSG:3857',
    'googlemapscompatible': 'EPSG:3857',
    'webmercatorquad': 'EPSG:3857',
    'urn:ogc:def:wkss:ogc:1.0:webmercatorquad': 'EPSG:3857'
};

/** "urn:ogc:def:crs:EPSG::5514" | "EPSG:5514" | "GoogleMapsCompatible" → "EPSG:5514" */
export function crsToEpsg(raw: string): string {
    const s = (raw ?? '').trim();
    const low = s.toLowerCase();
    if (WELL_KNOWN[low]) return WELL_KNOWN[low];
    const urn = low.match(/urn:ogc:def:crs:epsg:[^:]*:(\d+)/);
    if (urn) return `EPSG:${urn[1]}`;
    const plain = low.match(/^epsg:(\d+)$/);
    if (plain) return `EPSG:${plain[1]}`;
    const uri = low.match(/\/crs\/epsg\/[^/]*\/(\d+)/);
    if (uri) return `EPSG:${uri[1]}`;
    if (low.includes('crs84')) return 'EPSG:4326';
    throw new Error(t.crsUnknown(raw));
}

export const BUILTIN_CRS = new Set(['EPSG:3857', 'EPSG:4326']);

/** Meters per CRS unit for computing resolution (resolution = scaleDenom·0.00028 / mpu). */
export function metersPerUnit(units: string | undefined): number {
    switch (units) {
        case 'degrees': return 111319.49079327358; // OL METERS_PER_UNIT['degrees']
        case 'ft':      return 0.3048;
        case 'us-ft':   return 1200 / 3937;
        default:        return 1; // 'm' and projected metric CRSs
    }
}
