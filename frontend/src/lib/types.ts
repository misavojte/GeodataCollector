import type { Locale } from './i18n';

// Shared types matching the PHP API.

export type GeometryType = 'point' | 'line' | 'polygon';

export type DataType =
    | 'text' | 'textarea' | 'number' | 'integer'
    | 'boolean' | 'date' | 'select' | 'multiselect' | 'hierarchy'
    | 'photos' | 'files';

/** Item of an attachment field (photos / documents) – stored in the record's attributes. */
export interface AttachmentRef {
    id: number;
    name: string;
    size: number;
}

export interface FieldOption {
    value: string;
    label: string;
}

export interface HierarchyLevel {
    key: string;
    label: string;
}

export interface HierarchyNode {
    value: string;
    label: string;
    children?: HierarchyNode[];
}

export interface FieldValidation {
    min?: number;
    max?: number;
    maxLength?: number;
    pattern?: string;
    levels?: HierarchyLevel[];
    maxFiles?: number;
}

export interface FieldDefinition {
    id: number;
    field_key: string;
    label: string;
    help_text: string | null;
    data_type: DataType;
    options: (FieldOption | HierarchyNode)[] | null;
    is_required: boolean;
    validation: FieldValidation | null;
    applies_to: GeometryType[] | null;
    default_value: unknown;
    sort_order: number;
    is_active: boolean;
}

/** A single record revision (change history). */
export interface FeatureRevision {
    id: number;
    action: 'create' | 'update' | 'delete' | 'restore';
    geometry_type: GeometryType;
    geometry: Geometry;
    attributes: Attributes;
    changed_at: string;
    changed_by_name: string | null;
}

/** Entry in the recent-changes overview (across records). */
export interface ChangeEntry {
    id: number;
    feature_id: number;
    action: 'create' | 'update' | 'delete' | 'restore';
    geometry_type: GeometryType;
    nazev: string | null;
    changed_at: string;
    changed_by_name: string | null;
    feature_deleted: boolean;
}

/** Payload for creating/updating a field definition (admin metadata management). */
export interface FieldInput {
    field_key: string;
    label: string;
    data_type: DataType;
    is_required: boolean;
    help_text?: string | null;
    options?: (FieldOption | HierarchyNode)[] | null;
    applies_to?: GeometryType[] | null;
    validation?: FieldValidation | null;
    default_value?: unknown;
    sort_order?: number;
    is_active?: boolean;
}

/** proj4 CRS definition to register in OpenLayers (omitted for the built-in 3857/4326). */
export interface CrsCfg {
    code: string;
    proj4def: string;
    units?: string;
    axisOrientation?: string;
    extent?: [number, number, number, number];
    worldExtent?: [number, number, number, number];
    metersPerUnit?: number;
}

/** Stored WMTS source configuration (computed during setup from GetCapabilities). */
export interface WmtsLayerCfg {
    url: string;
    requestEncoding: 'KVP' | 'REST';
    layer: string;
    matrixSet: string;
    format?: string;
    style?: string;
    projection: string;
    tileGrid: {
        origin?: [number, number];
        origins?: [number, number][];
        tileSize: number | [number, number];
        matrixIds: string[];
        resolutions: number[];
    };
}

/** An enabled map layer (array order = drawing order from the bottom; first = base map). */
export interface ActiveOverlay {
    id: string;       // LayerDef.id
    opacity: number;  // 0..1
}

/**
 * A layer in the Layers panel: ONE list of all layers in the user's local order
 * (drag-and-drop in the browser, saved to localStorage). No special
 * "base map" – the first enabled layer in the list is simply at the bottom.
 */
export interface UserLayer {
    id: string;       // LayerDef.id
    on: boolean;      // checked = drawn
    opacity: number;  // 0..1
}

/**
 * Layer tile state for the indicator in the Layers panel. Intentionally ONLY loading:
 * it is the only state that can be detected truthfully for all service types (WMS/XYZ/WMTS).
 * 'ok' (idle) is not shown in the UI at all. States like "missing tiles" or "outside
 * zoom range" were removed – the tile pyramid covers holes with tiles
 * from other levels, servers return blank images instead of errors, and XYZ/WMS never
 * announce their native range, so such an indicator either lies or stays silent.
 */
export type LayerStatus = 'loading' | 'ok';

export interface LayerDef {
    id: string;
    title: string;
    type: 'wms' | 'xyz' | 'wmts';
    url?: string;
    url_template?: string;
    subdomains?: string;
    layers?: string;
    format?: string;
    transparent?: boolean;
    version?: string;
    styles?: string;
    attribution?: string;
    default_visible?: boolean;
    default_opacity?: number;
    min_zoom?: number;
    max_zoom?: number;
    parent_id?: number | null;
    parent_key?: string | null;
    proxy?: boolean;
    projection?: string;   // WMS/WMTS reprojection (EPSG code); missing = EPSG:3857
    crs?: CrsCfg;          // proj4 def of a non-built-in CRS (missing for 3857/4326)
    wmts?: WmtsLayerCfg;   // present when type === 'wmts'
}

// --- GetCapabilities (parsed on the server, for the setup wizard) ---
export interface WmsCapsLayer { name: string; title: string; crs: string[]; }
export interface WmsCaps {
    service: 'wms'; version: string; getMapUrl: string | null;
    formats: string[]; layers: WmsCapsLayer[];
}
export interface WmtsCapsMatrix {
    id: string; scaleDenominator: number; topLeftCorner: [number, number] | null;
    tileWidth: number; tileHeight: number; matrixWidth: number; matrixHeight: number;
}
export interface WmtsCapsSet { identifier: string; supportedCRS: string; matrices: WmtsCapsMatrix[]; }
export interface WmtsCapsLayer {
    identifier: string; title: string; formats: string[]; styles: string[];
    defaultStyle: string; matrixSets: string[];
    resourceTemplates: { format: string; template: string }[];
}
export interface WmtsCaps {
    service: 'wmts'; version: string; kvpGetTile: string | null;
    tileMatrixSets: WmtsCapsSet[]; layers: WmtsCapsLayer[];
}
export type Capabilities = WmsCaps | WmtsCaps;

/** A layer as managed by the admin (map_layers table). List order = sort_order. */
export interface MapLayerAdmin {
    id: number;
    layer_key: string;
    title: string;
    type: 'wms' | 'xyz' | 'wmts';
    url: string;
    capabilities_url: string | null;
    wms_layers: string | null;
    subdomains: string | null;
    format: string | null;
    projection: string | null;
    crs_config: CrsCfg | null;
    wmts_config: {
        requestEncoding: 'KVP' | 'REST';
        layer: string;
        matrixSet: string;
        style?: string;
        format?: string;
        tileGrid: WmtsLayerCfg['tileGrid'];
    } | null;
    attribution: string | null;
    min_zoom: number | null;
    max_zoom: number | null;
    parent_id: number | null;
}

/** Input for creating/updating a layer. */
export interface MapLayerInput {
    title: string;
    type: 'wms' | 'xyz' | 'wmts';
    url: string;
    capabilities_url?: string;
    wms_layers?: string;
    subdomains?: string;
    format?: string;
    attribution?: string;
    min_zoom?: number | null;
    max_zoom?: number | null;
    parent_id?: number | null;
    // WMTS (+ WMS with a custom CRS)
    projection?: string;
    crs?: CrsCfg | null;
    layer?: string;
    matrix_set?: string;
    style?: string;
    request_encoding?: 'KVP' | 'REST';
    tile_grid?: WmtsLayerCfg['tileGrid'];
}

export interface MapConfig {
    center: [number, number]; // [lat, lon]
    zoom: number;
    minZoom: number;
    maxZoom: number;
    maxBounds: [[number, number], [number, number]] | null;
}

export interface User {
    id: number;
    username: string;
    display_name: string;
    role: string;
}

/** User account for admin management (without password). */
export interface AdminUser {
    id: number;
    username: string;
    display_name: string;
    email: string | null;
    role: 'admin' | 'collector';
    is_active: boolean;
    created_at: string;
    last_login_at: string | null;
}

export interface UserCreateInput {
    username: string;
    display_name: string;
    password: string;
    email?: string;
    role: 'admin' | 'collector';
}

export interface UserUpdateInput {
    display_name?: string;
    email?: string;
    role?: 'admin' | 'collector';
    is_active?: boolean;
    password?: string;
}

/** Branding (name + subtitle) from config.php – the single source for both the header and the login page. */
export interface AppConfig {
    name: string;
    subtitle: string;
    /** Default UI language from config.php (used when the user has not chosen one). */
    default_language?: Locale;
}

export interface MeResponse {
    user: User;
    csrf_token: string;
    app: AppConfig;
    map: MapConfig;
}

export type Attributes = Record<string, unknown>;

export interface Geometry {
    type: 'Point' | 'LineString' | 'Polygon';
    coordinates: number[] | number[][] | number[][][];
}

export interface FeatureMeta {
    id: number;
    geometry_type: GeometryType;
    status: string;
    created_by: number;
    created_by_name: string | null;
    updated_by: number | null;
    updated_by_name: string | null;
    created_at: string;
    updated_at: string;
    can_edit: boolean;
}

export interface ApiFeature {
    type: 'Feature';
    id: number;
    geometry: Geometry;
    properties: { attributes: Attributes; meta: FeatureMeta };
}

export interface FeatureCollection {
    type: 'FeatureCollection';
    features: ApiFeature[];
    version: number; // data-state fingerprint at query time (for cache freshness checks)
}

export interface FieldError {
    field: string;
    message: string;
    // Machine error code + parameters for client-side localization (the server sends
    // only a short English message as a fallback).
    code?: string;
    params?: Record<string, string | number>;
}

/** Reference point (GCP pair) linking the historical map and the reference base map. */
export interface GcpPair {
    id: number;
    label: string;
    description: string | null;
    map_point: { lon: number; lat: number };
    ref_point: { lon: number; lat: number };
    created_by: number;
    created_by_name: string | null;
    updated_by: number | null;
    updated_by_name: string | null;
    created_at: string;
    updated_at: string;
    can_edit: boolean;
}

export interface GcpInput {
    label: string;
    description?: string | null;
    map_geometry: Geometry;
    ref_geometry: Geometry;
}

export interface GcpUpdateInput {
    label?: string;
    description?: string | null;
    map_geometry?: Geometry;
    ref_geometry?: Geometry;
}

