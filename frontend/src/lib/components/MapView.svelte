<script lang="ts">
    import { onMount, tick, untrack } from 'svelte';
    import Map from 'ol/Map';
    import View from 'ol/View';
    import TileLayer from 'ol/layer/Tile';
    import VectorLayer from 'ol/layer/Vector';
    import VectorSource from 'ol/source/Vector';
    import TileWMS from 'ol/source/TileWMS';
    import XYZ from 'ol/source/XYZ';
    import OSM from 'ol/source/OSM';
    import WMTS from 'ol/source/WMTS';
    import WMTSTileGrid from 'ol/tilegrid/WMTS';
    import TileState from 'ol/TileState';
    import GeoJSON from 'ol/format/GeoJSON';
    import { fromLonLat, toLonLat, transformExtent, get as getProjection } from 'ol/proj';
    import Point from 'ol/geom/Point';
    import LineString from 'ol/geom/LineString';
    import { unByKey } from 'ol/Observable';
    import type { EventsKey } from 'ol/events';
    import { registerCrs } from '$lib/crs';
    import Draw from 'ol/interaction/Draw';
    import Modify from 'ol/interaction/Modify';
    import { altKeyOnly, singleClick } from 'ol/events/condition';
    import Style from 'ol/style/Style';
    import Stroke from 'ol/style/Stroke';
    import Fill from 'ol/style/Fill';
    import CircleStyle from 'ol/style/Circle';
    import RegularShape from 'ol/style/RegularShape';
    import Text from 'ol/style/Text';
    import MultiPoint from 'ol/geom/MultiPoint';
    import Collection from 'ol/Collection';
    import { defaults as defaultControls, ScaleLine } from 'ol/control';
    import { easeOut } from 'ol/easing';
    import OlFeature from 'ol/Feature';
    import type { FeatureLike } from 'ol/Feature';
    import { getLength, getArea } from 'ol/sphere';
    import Polygon from 'ol/geom/Polygon';
    import { t, DATE_LOCALE } from '$lib/i18n';
    import type { MapConfig, LayerDef, ActiveOverlay, LayerStatus, ApiFeature, Geometry, GeometryType, GcpPair } from '$lib/types';
    import { featureTitle } from '$lib/feature';

    type Mode = 'idle' | 'drawing' | 'form';

    let {
        mapConfig, layers, activeLayers,
        features, mode, drawType, selectedId,
        featuresVisible = true,
        gcpPairs = [],
        gcpVisible = false,
        gcpMode = 'idle',
        selectedGcpId = null,
        gcpDraftMap = null,
        gcpDraftRef = null,
        onSelect, onDeselect, onDrawn, onDraftChange, onModify, onMoveEnd, onLayerStatus,
        onSelectGcp, onGcpMapPlaced, onGcpRefPlaced, onGcpModify
    }: {
        mapConfig: MapConfig;
        layers: LayerDef[];
        activeLayers: ActiveOverlay[];
        features: ApiFeature[];
        mode: Mode;
        drawType: GeometryType | null;
        selectedId: number | null;
        featuresVisible?: boolean;
        gcpPairs?: GcpPair[];
        gcpVisible?: boolean;
        gcpMode?: 'idle' | 'map_point' | 'ref_point' | 'form';
        selectedGcpId?: number | null;
        gcpDraftMap?: Geometry | null;
        gcpDraftRef?: Geometry | null;
        onSelect: (f: ApiFeature) => void;
        onDeselect: () => void;
        onDrawn: (geom: Geometry, type: GeometryType) => void;
        onDraftChange: (geom: Geometry) => void;
        onModify: (id: number, geom: Geometry) => void;
        onMoveEnd: (bbox: [number, number, number, number]) => void;
        onLayerStatus: (status: Record<string, LayerStatus>) => void;
        onSelectGcp?: (g: GcpPair) => void;
        onGcpMapPlaced?: (geom: Geometry) => void;
        onGcpRefPlaced?: (geom: Geometry) => void;
        onGcpModify?: (id: number, type: 'map' | 'ref', coord: [number, number]) => void;
    } = $props();

    const DRAW_TYPE: Record<GeometryType, 'Point' | 'LineString' | 'Polygon'> = {
        point: 'Point', line: 'LineString', polygon: 'Polygon'
    };
    // Size of the in-memory tile cache (number of tiles). Standard for modern desktop
    // browsers (2048 tiles ≈ 100-150 MB RAM): when zooming and panning,
    // already loaded tiles are not evicted from memory and not re-fetched from the network.
    const TILE_CACHE_SIZE = 2048;
    const gj = new GeoJSON();
    // Respect "reduce motion" – smooth pans/zooms then run without animation.
    const reduceMotion = typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Label (name) of the object under the cursor + "go to coordinates" state.
    let hoverName = $state('');
    let hoverX = $state(0);
    let hoverY = $state(0);
    let jumpVal = $state('');
    let jumpOpen = $state(false);
    let jumpInputEl = $state<HTMLInputElement | null>(null);

    // Right-click context menu (coordinate readout).
    let contextMenu = $state<{ x: number; y: number; text: string; coord: number[] } | null>(null);
    let copiedFeedback = $state(false);
    let copyTimer: ReturnType<typeof setTimeout> | null = null;

    let mapEl: HTMLDivElement;
    let map: Map | null = null;
    let ready = $state(false);

    // globalThis.Map: the built-in Map (the name `Map` is shadowed here by the ol/Map import)
    const tileLayers = new globalThis.Map<string, { layer: TileLayer<any>; defJson: string }>();
    const viewSource = new VectorSource();   // others' (read-only)
    const editSource = new VectorSource();   // own (editable)
    const draftSource = new VectorSource();  // new object being drawn
    const editColl = new Collection<OlFeature>(); // only the currently selected object = editable
    const gcpSource = new VectorSource();
    const gcpClusterSource = new VectorSource();
    const gcpDraftSource = new VectorSource();
    const gcpEditColl = new Collection<OlFeature>();
    let gcpDrawInteraction: Draw | null = null;
    let hoverGcpId: number | null = null;
    let hoverClusterId: string | null = null;
    let clusteredGcpIds = new Set<number>();
    let activeSpiderClusterId: string | null = null;
    let viewLayer: VectorLayer<any> | null = null;
    let editLayer: VectorLayer<any> | null = null;
    let draftLayer: VectorLayer<any> | null = null;
    let gcpLayer: VectorLayer<any> | null = null;
    let gcpClusterLayer: VectorLayer<any> | null = null;
    let gcpDraftLayer: VectorLayer<any> | null = null;
    let drawInteraction: Draw | null = null;
    let sketching = false;        // drawing in progress (at least the first vertex has been placed)?

    // --- Length / area measurement (started from the right-click menu) ---
    // Purely local tool: saves nothing, the result is shown in the bar at the top of the map.
    // 'point' = point picking: coordinates in the top bar with a Copy button.
    let measuring = $state<'line' | 'area' | 'point' | null>(null);
    let measureText = $state('');
    let pointText = $state(''); // "lat, lon" of the picked point
    const measureSource = new VectorSource();
    let measureDraw: Draw | null = null;
    const measureStyle = new Style({
        stroke: new Stroke({ color: '#0284c7', width: 2.5, lineDash: [8, 6] }),
        fill: new Fill({ color: 'rgba(2, 132, 199, 0.12)' }),
        image: new CircleStyle({ radius: 4, fill: new Fill({ color: '#0284c7' }) })
    });

    function fmtNum(n: number, digits = 0): string {
        return n.toLocaleString(DATE_LOCALE, { minimumFractionDigits: digits, maximumFractionDigits: digits });
    }
    // Geodesic values (ol/sphere computes on a sphere, not in the distorted Mercator plane).
    function measureLabel(geom: LineString | Polygon): string {
        if (geom instanceof Polygon) {
            const a = getArea(geom);
            const val = a < 10_000 ? fmtNum(a) + ' m²'
                : a < 1_000_000 ? fmtNum(a / 10_000, 2) + ' ha (' + fmtNum(a) + ' m²)'
                : fmtNum(a / 1_000_000, 2) + ' km²';
            return t.map.measureAreaLabel + ': ' + val;
        }
        const l = getLength(geom);
        return t.map.measureLengthLabel + ': ' + (l < 1000 ? fmtNum(l) + ' m' : fmtNum(l / 1000, 2) + ' km');
    }

    function coordText(coord: number[]): string {
        const [lon, lat] = toLonLat(coord, 'EPSG:3857');
        return `${lat.toFixed(5)}, ${lon.toFixed(5)}`;
    }

    function startMeasure(kind: 'line' | 'area' | 'point', initial?: number[]) {
        closeContextMenu();
        stopMeasure();
        measuring = kind;
        if (kind === 'point') {
            // The point from the right-click is selected immediately; another click on the map picks a different one.
            if (initial) {
                measureSource.addFeature(new OlFeature(new Point(initial)));
                pointText = coordText(initial);
            }
            const pd = new Draw({ source: measureSource, type: 'Point', style: measureStyle });
            pd.on('drawstart', () => { measureSource.clear(); });
            pd.on('drawend', (e) => {
                pointText = coordText((e.feature.getGeometry() as Point).getCoordinates());
                copiedFeedback = false;
            });
            map?.addInteraction(pd);
            measureDraw = pd;
            return;
        }
        measureText = t.map.measureHint;
        const d = new Draw({ source: measureSource, type: kind === 'line' ? 'LineString' : 'Polygon', style: measureStyle });
        let listener: EventsKey | null = null;
        d.on('drawstart', (e) => {
            measureSource.clear(); // a new measurement replaces the previous one
            const geom = e.feature.getGeometry() as LineString | Polygon;
            listener = geom.on('change', () => { measureText = measureLabel(geom); });
        });
        d.on('drawend', (e) => {
            if (listener) unByKey(listener);
            measureText = measureLabel(e.feature.getGeometry() as LineString | Polygon);
        });
        map?.addInteraction(d);
        measureDraw = d;
    }

    // When drawing a new record / GCP starts, measuring ends (two click tools would conflict).
    $effect(() => {
        if (mode !== 'idle' || gcpMode !== 'idle') untrack(() => { if (measuring) stopMeasure(); });
    });

    function stopMeasure() {
        if (measureDraw) { map?.removeInteraction(measureDraw); measureDraw = null; }
        measureSource.clear();
        measuring = null;
        measureText = '';
        pointText = '';
        copiedFeedback = false;
    }

    async function copyPoint() {
        try {
            await navigator.clipboard.writeText(pointText);
            copiedFeedback = true;
            if (copyTimer) clearTimeout(copyTimer);
            copyTimer = setTimeout(() => { copiedFeedback = false; }, 1500);
        } catch {
            // clipboard unavailable
        }
    }
    let modifyJustEnded = false;  // a vertex drag just ended → suppress the following click-deselect
    let selId: number | null = null;
    let hoverId: number | null = null;   // object currently under the cursor (hover highlight)

    // Vertex editing is only possible on the selected object (not on all at once).
    function syncEditable() {
        editColl.clear();
        if (selectedId == null) return;
        const f = editSource.getFeatures().find((x) => x.get('_id') === selectedId);
        if (f) editColl.push(f);
    }

    function toApiGeom(f: OlFeature): Geometry {
        return gj.writeGeometryObject(f.getGeometry()!, {
            dataProjection: 'EPSG:4326', featureProjection: 'EPSG:3857'
        }) as unknown as Geometry;
    }

    // Deletes the nearest vertex (for deleting with the right mouse button). Respects the minimum
    // (line ≥ 2 points, polygon ring ≥ 3 vertices) and keeps the ring closed.
    function removeNearestVertex(feature: OlFeature, pixel: number[]): boolean {
        const geom = feature.getGeometry() as any;
        if (!geom) return false;
        const TOL = 12;
        const near = (coord: number[]): boolean => {
            const p = map!.getPixelFromCoordinate(coord);
            return !!p && Math.hypot(p[0] - pixel[0], p[1] - pixel[1]) <= TOL;
        };
        const type = geom.getType();
        if (type === 'LineString') {
            const coords: number[][] = geom.getCoordinates();
            if (coords.length <= 2) return false;
            for (let i = 0; i < coords.length; i++) {
                if (near(coords[i])) { coords.splice(i, 1); geom.setCoordinates(coords); return true; }
            }
        } else if (type === 'Polygon') {
            const rings: number[][][] = geom.getCoordinates();
            for (let r = 0; r < rings.length; r++) {
                const distinct = rings[r].slice(0, -1); // without the closing point
                if (distinct.length <= 3) continue;
                for (let i = 0; i < distinct.length; i++) {
                    if (near(distinct[i])) {
                        distinct.splice(i, 1);
                        distinct.push(distinct[0]); // close the ring again
                        rings[r] = distinct;
                        geom.setCoordinates(rings);
                        return true;
                    }
                }
            }
        }
        return false;
    }

    function activeEditable(): OlFeature | null {
        if (editColl.getLength() > 0) return editColl.item(0) as OlFeature;
        const d = draftSource.getFeatures();
        return d.length > 0 ? (d[0] as OlFeature) : null;
    }

    function olFromApi(f: ApiFeature): OlFeature {
        const feat = gj.readFeature(f, {
            dataProjection: 'EPSG:4326', featureProjection: 'EPSG:3857'
        }) as OlFeature;
        feat.set('_zvk', f);
        feat.set('_id', f.id);
        feat.set('_canEdit', f.properties.meta.can_edit);
        return feat;
    }

    // Styles are STATIC for a given state → precompute and share them (no Style/Stroke/Fill allocation
    // on every redraw; highlighting would otherwise restyle the whole set). Key: type|editability|state.
    const styleCache: Record<string, Style[]> = {}; // note: `Map` is shadowed here by the ol/Map import
    // Shared geometry fn for vertex highlighting – OL calls it per feature (returns its vertices).
    const vertexGeom = (feat: FeatureLike) => {
        const g = (feat as OlFeature).getGeometry() as any;
        if (!g) return g;
        if (g.getType() === 'Polygon') return new MultiPoint(g.getCoordinates().flatMap((r: number[][]) => r.slice(0, -1)));
        if (g.getType() === 'LineString') return new MultiPoint(g.getCoordinates());
        return g;
    };
    function buildStyles(geomType: string, canEdit: boolean, st: 'idle' | 'hover' | 'selected'): Style[] {
        const color = canEdit ? '#d93925' : '#6b7280';
        const styles: Style[] = [];
        if (geomType === 'Point') {
            const radius = st === 'selected' ? 9 : st === 'hover' ? 8 : 6;
            // Glow ring (halo) under the point: strong when selected, subtle on hover.
            if (st !== 'idle') {
                styles.push(new Style({ image: new CircleStyle({ radius: radius + 5, fill: new Fill({ color: color + (st === 'selected' ? '4d' : '30') }) }) }));
            }
            styles.push(new Style({ image: new CircleStyle({ radius, fill: new Fill({ color }), stroke: new Stroke({ color: '#fff', width: st === 'selected' ? 2.5 : 2 }) }) }));
            return styles;
        }
        const width = st === 'selected' ? 5 : st === 'hover' ? 4 : 3;
        // White casing under the stroke: strong when selected, subtle on hover – clearly distinguishes the state.
        if (st !== 'idle') {
            styles.push(new Style({ stroke: new Stroke({ color: st === 'selected' ? 'rgba(255,255,255,.85)' : 'rgba(255,255,255,.6)', width: width + 4 }) }));
        }
        // Fill as a readable three-step ladder (idle 12 % → hover 20 % → selected 30 %).
        styles.push(new Style({ stroke: new Stroke({ color, width }), fill: new Fill({ color: color + (st === 'selected' ? '4d' : st === 'hover' ? '33' : '20') }) }));
        // For a selected editable shape, highlight the vertices (shared vertexGeom called by OL per feature).
        if (st === 'selected' && canEdit) {
            styles.push(new Style({ image: new CircleStyle({ radius: 6, fill: new Fill({ color: '#fff' }), stroke: new Stroke({ color, width: 2.5 }) }), geometry: vertexGeom }));
        }
        return styles;
    }
    function styleFor(feature: FeatureLike): Style | Style[] {
        const f = feature as OlFeature;
        const isDraft = f.get('_draft') === true;            // new object being drawn
        const canEdit = isDraft || f.get('_canEdit') !== false;
        const id = f.get('_id');
        const selected = isDraft || (id != null && id === selId);
        const st: 'idle' | 'hover' | 'selected' = selected ? 'selected' : (id != null && id === hoverId ? 'hover' : 'idle');
        const geomType = f.getGeometry()?.getType() ?? 'Point';
        const key = geomType + '|' + (canEdit ? '1' : '0') + '|' + st;
        let s = styleCache[key];
        if (!s) { s = buildStyles(geomType, canEdit, st); styleCache[key] = s; }
        return s;
    }

    function buildGcpCollapsedStyle(selected: boolean, isHover: boolean): Style[] {
        const styles: Style[] = [];
        const radius = selected ? 9 : isHover ? 8 : 6.5;

        // Glow halo on selection or mouse hover
        if (selected || isHover) {
            styles.push(new Style({
                image: new RegularShape({
                    points: 4,
                    radius: (radius + 5) * 1.15,
                    angle: Math.PI / 4,
                    fill: new Fill({ color: '#0284c7' + (selected ? '44' : '26') })
                })
            }));
        }

        // Plain blue square with a white border
        styles.push(new Style({
            image: new RegularShape({
                points: 4,
                radius: radius * 1.15,
                angle: Math.PI / 4,
                fill: new Fill({ color: '#0284c7' }),
                stroke: new Stroke({ color: '#fff', width: selected ? 2.5 : 2 })
            })
        }));

        return styles;
    }

    function isAtHighestZoom(): boolean {
        if (!map) return false;
        const view = map.getView();
        const curZoom = view.getZoom() ?? 0;
        const maxZoom = view.getMaxZoom() ?? mapConfig.maxZoom ?? 19;
        // The highest zoom means the maximum zoom and the one right below it (e.g. zoom 18 and 19 with maxZoom 19)
        return curZoom >= maxZoom - 1.05;
    }

    function gcpStyleFor(feature: FeatureLike, resolution?: number): Style | Style[] {
        const f = feature as OlFeature;
        const id = f.get('_gcpId') as number | undefined;
        if (id == null) return [];

        // If the pair is hidden in a larger cluster and is not currently selected, it is not rendered
        if (id !== selectedGcpId && clusteredGcpIds.has(id)) {
            return [];
        }

        const type = f.get('_gcpType'); // 'map' | 'ref' | 'line'
        const pair = f.get('_gcp') as GcpPair | undefined;
        const selected = selectedGcpId != null && id === selectedGcpId;
        const isHover = hoverGcpId != null && id === hoverGcpId;

        // Compute the on-screen distance between the pair's points in pixels
        let pixelDist = 999;
        if (pair && resolution) {
            const cMap = fromLonLat([pair.map_point.lon, pair.map_point.lat]);
            const cRef = fromLonLat([pair.ref_point.lon, pair.ref_point.lat]);
            pixelDist = Math.hypot(cMap[0] - cRef[0], cMap[1] - cRef[1]) / resolution;
        }

        // At the highest zoom (e.g. zoom 18 and 19) both points and the line MUST ALWAYS be visible,
        // so the points can be precisely calibrated and edited by dragging with the mouse.
        // Simplification to a blue square happens ONLY at lower zoom levels with a spacing < 24 px.
        const highestZoom = isAtHighestZoom();
        const isCollapsed = !highestZoom && pixelDist < 24;

        if (isCollapsed) {
            // When the two points can't be distinguished, simplify to a blue square at the reference position:
            // the line and the orange map point are hidden.
            if (type === 'line' || type === 'map') return [];
            return buildGcpCollapsedStyle(selected, isHover);
        }

        // Standard rendering at detailed zoom (spacing >= 24 px)
        if (type === 'line') {
            return new Style({
                stroke: new Stroke({
                    color: selected ? '#0284c7' : isHover ? 'rgba(2, 132, 199, 0.85)' : 'rgba(2, 132, 199, 0.5)',
                    width: selected ? 2.5 : 1.8,
                    lineDash: [5, 4]
                })
            });
        }

        const isMap = type === 'map';
        const color = isMap ? '#e06c1b' : '#0284c7';
        const radius = selected ? 9 : isHover ? 8 : 6.5;
        const styles: Style[] = [];

        if (selected || isHover) {
            const haloFill = new Fill({ color: color + (selected ? '44' : '26') });
            styles.push(new Style({
                image: isMap
                    ? new CircleStyle({ radius: radius + 5, fill: haloFill })
                    : new RegularShape({ points: 4, radius: (radius + 5) * 1.15, angle: Math.PI / 4, fill: haloFill })
            }));
        }

        const stroke = new Stroke({ color: '#fff', width: selected ? 2.5 : 2 });
        const fill = new Fill({ color });
        styles.push(new Style({
            image: isMap
                ? new CircleStyle({ radius, fill, stroke })
                : new RegularShape({ points: 4, radius: radius * 1.15, angle: Math.PI / 4, fill, stroke })
        }));

        return styles;
    }

    function gcpClusterStyleFor(feature: FeatureLike): Style | Style[] {
        const f = feature as OlFeature;

        // Fan-out (spiderfy) – connecting line
        if (f.get('_spiderLine')) {
            return new Style({
                stroke: new Stroke({
                    color: 'rgba(2, 132, 199, 0.65)',
                    width: 1.5,
                    lineDash: [4, 4]
                })
            });
        }

        // Fan-out – center marker
        if (f.get('_spiderHub')) {
            return new Style({
                image: new CircleStyle({
                    radius: 4,
                    fill: new Fill({ color: '#0284c7' }),
                    stroke: new Stroke({ color: '#fff', width: 2 })
                })
            });
        }

        // Fan-out – individual point
        if (f.get('_spiderGcp')) {
            const pair = f.get('_spiderGcp') as GcpPair;
            const selected = selectedGcpId != null && pair.id === selectedGcpId;
            const isHover = hoverGcpId != null && pair.id === hoverGcpId;
            return buildGcpCollapsedStyle(selected, isHover);
        }

        // Cluster badge
        const pairs = f.get('_clusterPairs') as GcpPair[] | undefined;
        if (!pairs || pairs.length <= 1) return [];

        const count = pairs.length;
        const clId = f.get('_clusterId');
        const isHover = hoverClusterId != null && hoverClusterId === clId;
        const radius = count < 10 ? 14 : count < 100 ? 16 : 18;
        const styles: Style[] = [];

        // Glow ring on hover
        if (isHover) {
            styles.push(new Style({
                image: new CircleStyle({
                    radius: radius + 5,
                    fill: new Fill({ color: 'rgba(2, 132, 199, 0.25)' })
                })
            }));
        }

        // Main blue circle with the point count inside
        styles.push(new Style({
            image: new CircleStyle({
                radius,
                fill: new Fill({ color: '#0284c7' }),
                stroke: new Stroke({ color: '#ffffff', width: 2.5 })
            }),
            text: new Text({
                text: String(count),
                font: 'bold 12px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                fill: new Fill({ color: '#ffffff' }),
                offsetY: 1
            })
        }));

        // Distinctive orange GCP marker in the badge corner (indicates an orange/blue pair)
        const pipDist = radius * 0.72;
        styles.push(new Style({
            image: new CircleStyle({
                radius: 4,
                fill: new Fill({ color: '#e06c1b' }),
                stroke: new Stroke({ color: '#ffffff', width: 1.5 }),
                displacement: [pipDist, pipDist]
            })
        }));

        return styles;
    }

    function gcpDraftStyleFor(feature: FeatureLike): Style | Style[] {
        const f = feature as OlFeature;
        const type = f.get('_gcpDraft');
        if (type === 'line') {
            return new Style({
                stroke: new Stroke({ color: 'rgba(2, 132, 199, 0.75)', width: 2, lineDash: [4, 4] })
            });
        }
        const isMap = type === 'map';
        const color = isMap ? '#e06c1b' : '#0284c7';

        if (isMap) {
            return [
                new Style({
                    image: new CircleStyle({
                        radius: 13,
                        fill: new Fill({ color: color + '33' })
                    })
                }),
                new Style({
                    image: new CircleStyle({
                        radius: 7.5,
                        fill: new Fill({ color }),
                        stroke: new Stroke({ color: '#fff', width: 2 })
                    })
                })
            ];
        }

        // Reference point in the basemap = small square
        return [
            new Style({
                image: new RegularShape({
                    points: 4,
                    radius: 13 * 1.15,
                    angle: Math.PI / 4,
                    fill: new Fill({ color: color + '33' })
                })
            }),
            new Style({
                image: new RegularShape({
                    points: 4,
                    radius: 7.5 * 1.15,
                    angle: Math.PI / 4,
                    fill: new Fill({ color }),
                    stroke: new Stroke({ color: '#fff', width: 2 })
                })
            })
        ];
    }


    // --- Layer tile status (indicator in the Layers panel) -----------------------
    // Each active layer has a tracker with the number of tiles currently loading; key = layer id.
    type Tracker = {
        keys: EventsKey[];
        pending: number; // tiles currently loading
    };
    const trackers = new globalThis.Map<string, Tracker>();
    let statusTimer: ReturnType<typeof setTimeout> | null = null;
    let statusDisposed = false;

    // The indicator intentionally reports ONLY loading (spinner/done) – the only state
    // that can be determined truthfully for all service types. Reporting "missing tiles" or
    // "outside zoom range" truthfully on the client is impossible (the pyramid covers holes
    // with tiles from other levels, servers return empty images instead of errors,
    // XYZ/WMS don't announce their native range) and would contradict the map.
    function trackSource(key: string, source: any) {
        untrackSource(key);
        const t: Tracker = { keys: [], pending: 0 };
        // The end of loading is tracked on the TILE (transition out of the LOADING state), not via
        // the source's tileloadend/tileloaderror: those don't cover silent ends (a tile
        // evicted from the cache while still loading, an empty response → EMPTY state)
        // and the counter would then report "loading" forever.
        const settle = (tile: any) => {
            const onChange = () => {
                if (tile.getState() === TileState.LOADING) return;
                tile.removeEventListener('change', onChange);
                t.pending = Math.max(0, t.pending - 1);
                emitStatus();
            };
            tile.addEventListener('change', onChange);
        };
        t.keys.push(source.on('tileloadstart', (e: any) => {
            t.pending++;
            settle(e.tile);
            emitStatus();
        }));
        trackers.set(key, t);
        emitStatus();
    }
    function untrackSource(key: string) {
        const t = trackers.get(key);
        if (!t) return;
        unByKey(t.keys);
        trackers.delete(key);
        emitStatus();
    }

    function computeStatus(): Record<string, LayerStatus> {
        const out: Record<string, LayerStatus> = {};
        for (const [id, t] of trackers) out[id] = t.pending > 0 ? 'loading' : 'ok';
        return out;
    }
    // Report status upward with slight batching (during loading an event arrives for every tile).
    function emitStatus() {
        if (statusDisposed || statusTimer !== null) return;
        statusTimer = setTimeout(() => {
            statusTimer = null;
            onLayerStatus(computeStatus());
        }, 120);
    }

    // Registering an unknown CRS (proj4) must happen before we build a source that uses it.
    function ensureCrs(def: LayerDef) {
        if (def.crs) registerCrs(def.crs);
    }

    // A layer has no fixed "kind" – all are composed into a single order, so WMS
    // always runs with transparency (harmless for the bottom layer, the upper ones must let the lower ones through).
    function makeSource(def: LayerDef) {
        if (def.type === 'xyz') {
            const tpl = def.url_template ?? '';
            if (/openstreetmap/i.test(tpl)) return new OSM({ cacheSize: TILE_CACHE_SIZE });
            const subs = (def.subdomains ?? '').trim();
            // The declared zoom range limits the grid: above max_zoom OL upscales tiles
            // instead of requesting non-existent levels; below min_zoom the layer isn't drawn at all
            // (see layerZoomBounds), so the grid never triggers an avalanche of requests.
            const zooms = { minZoom: def.min_zoom, maxZoom: def.max_zoom };
            if (subs) {
                // Each subdomain = its own URL (replaces ALL {s}); also handles non-alphabetic sets.
                return new XYZ({
                    urls: subs.split('').map((c) => tpl.split('{s}').join(c)),
                    attributions: def.attribution,
                    cacheSize: TILE_CACHE_SIZE,
                    ...zooms
                });
            }
            return new XYZ({ url: tpl, attributions: def.attribution, cacheSize: TILE_CACHE_SIZE, ...zooms });
        }
        if (def.type === 'wmts' && def.wmts) {
            ensureCrs(def);
            return makeWmtsSource(def);
        }
        // wms (default)
        ensureCrs(def);
        const crs = def.projection ?? 'EPSG:3857';
        const params: Record<string, unknown> = {
            LAYERS: def.layers ?? '',
            FORMAT: def.format ?? 'image/png',
            TRANSPARENT: true,
            VERSION: def.version ?? '1.3.0'
        };
        let url = def.url ?? '';
        if (def.proxy) { url = 'api/wms-proxy'; params['layer'] = def.id; }
        // OL reprojects the raster from the service CRS to the view (EPSG:3857) when they differ.
        // Reprojection draws tiles into a canvas → cross-origin sources require CORS (crossOrigin).
        const needsCors = !def.proxy && crs !== 'EPSG:3857';
        return new TileWMS({
            url, params,
            projection: getProjection(crs) ?? 'EPSG:3857',
            crossOrigin: needsCors ? 'anonymous' : undefined,
            attributions: def.attribution,
            cacheSize: TILE_CACHE_SIZE
        });
    }

    // WMTS source built only from the stored configuration (no network access at map startup).
    function makeWmtsSource(def: LayerDef): WMTS {
        const cfg = def.wmts!;
        const projection = getProjection(cfg.projection) ?? getProjection('EPSG:3857')!;
        const g = cfg.tileGrid;
        const tileGrid = new WMTSTileGrid({
            origin: g.origin,
            origins: g.origins,
            resolutions: g.resolutions,
            matrixIds: g.matrixIds,
            tileSize: g.tileSize ?? 256
        });
        return new WMTS({
            url: cfg.url,
            layer: cfg.layer,
            matrixSet: cfg.matrixSet,
            format: cfg.format ?? 'image/png',
            style: cfg.style ?? 'default',
            requestEncoding: cfg.requestEncoding,
            projection,
            tileGrid,
            reprojectionErrorThreshold: 0.5,
            wrapX: cfg.projection === 'EPSG:3857',
            crossOrigin: 'anonymous',
            attributions: def.attribution,
            cacheSize: TILE_CACHE_SIZE
        });
    }

    function defById(id: string): LayerDef | undefined {
        return layers.find((l) => l.id === id);
    }

    // The declared lower zoom bound disables rendering of the layer below it entirely:
    // without it, below min_zoom OL would request an avalanche of tiles from the lowest available
    // level for the whole viewport. OL draws a layer for viewZoom > minZoom, hence -1.
    function layerZoomBounds(def: LayerDef): { minZoom?: number } {
        return def.min_zoom != null ? { minZoom: def.min_zoom - 1 } : {};
    }

    // Syncs map layers with the list of enabled layers (single bottom-up order,
    // first = basemap): adds new ones, removes dropped ones, and for kept ones only adjusts
    // the order (zIndex). This way a layer is NOT REBUILT when opacity or order
    // changes (no tile reload). Definition changes are detected by a
    // FINGERPRINT (JSON), not object identity: after an admin save `layers` arrives
    // with new objects for all layers and identity would needlessly rebuild
    // (and reload) layers that the change didn't touch.
    function syncLayers() {
        for (const [id, ent] of tileLayers) {
            const wanted = activeLayers.some((o) => o.id === id);
            const def = defById(id);
            if (!wanted || !def || JSON.stringify(def) !== ent.defJson) {
                map!.removeLayer(ent.layer);
                untrackSource(id);
                tileLayers.delete(id);
            }
        }
        activeLayers.forEach((o, i) => {
            const def = defById(o.id);
            if (!def) return;
            const ent = tileLayers.get(o.id);
            if (ent) {
                ent.layer.setZIndex(10 + i);
                return;
            }
            // untrack: the initial opacity is read without tracking, otherwise the effect (membership/layers)
            // would also depend on opacity and every slider movement would recreate the layer.
            const source = makeSource(def);
            const layer = new TileLayer({
                source,
                zIndex: 10 + i,
                opacity: untrack(() => o.opacity),
                cacheSize: TILE_CACHE_SIZE,
                preload: 2,
                ...layerZoomBounds(def)
            });
            map!.addLayer(layer);
            trackSource(o.id, source);
            tileLayers.set(o.id, { layer, defJson: JSON.stringify(def) });
        });
    }

    function syncOpacities() {
        for (const o of activeLayers) tileLayers.get(o.id)?.layer.setOpacity(o.opacity);
    }

    function syncFeatures() {
        viewSource.clear();
        editSource.clear();
        for (const f of features) {
            const feat = olFromApi(f);
            if (f.properties.meta.can_edit) editSource.addFeature(feat);
            else viewSource.addFeature(feat);
        }
    }

    function syncGcpFeatures() {
        gcpSource.clear();
        for (const p of gcpPairs) {
            const cMap = fromLonLat([p.map_point.lon, p.map_point.lat]);
            const cRef = fromLonLat([p.ref_point.lon, p.ref_point.lat]);
            const pMap = new Point(cMap);
            const pRef = new Point(cRef);

            const fMap = new OlFeature({ geometry: pMap });
            fMap.set('_gcp', p);
            fMap.set('_gcpId', p.id);
            fMap.set('_gcpType', 'map');

            const fRef = new OlFeature({ geometry: pRef });
            fRef.set('_gcp', p);
            fRef.set('_gcpId', p.id);
            fRef.set('_gcpType', 'ref');

            const fLine = new OlFeature({
                geometry: new LineString([cMap, cRef])
            });
            fLine.set('_gcp', p);
            fLine.set('_gcpId', p.id);
            fLine.set('_gcpType', 'line');

            gcpSource.addFeatures([fLine, fMap, fRef]);
        }
        syncGcpEditable();
        updateGcpClusters();
    }

    function syncGcpEditable() {
        gcpEditColl.clear();
        if (selectedGcpId == null) return;
        const pair = gcpPairs.find((p) => p.id === selectedGcpId);
        if (pair && pair.can_edit !== false) {
            const feats = gcpSource.getFeatures().filter((f) => f.get('_gcpId') === selectedGcpId && (f.get('_gcpType') === 'map' || f.get('_gcpType') === 'ref'));
            feats.forEach((f) => gcpEditColl.push(f));
        }
    }

    function updateGcpLine(id: number) {
        const feats = gcpSource.getFeatures().filter((f) => f.get('_gcpId') === id);
        const fMap = feats.find((f) => f.get('_gcpType') === 'map');
        const fRef = feats.find((f) => f.get('_gcpType') === 'ref');
        const fLine = feats.find((f) => f.get('_gcpType') === 'line');
        if (fMap && fRef && fLine) {
            const pMap = (fMap.getGeometry() as Point).getCoordinates();
            const pRef = (fRef.getGeometry() as Point).getCoordinates();
            (fLine.getGeometry() as LineString).setCoordinates([pMap, pRef]);
        }
    }

    function updateGcpClusters() {
        if (!map) return;
        gcpClusterSource.clear();
        clusteredGcpIds.clear();

        if (!gcpVisible || gcpPairs.length === 0) {
            gcpSource.changed();
            return;
        }

        // At the highest zoom clustering is disabled entirely – the user is at maximum detail,
        // where they need to see and edit individual points directly on the map without any clustering.
        if (isAtHighestZoom()) {
            activeSpiderClusterId = null;
            gcpSource.changed();
            return;
        }

        const res = map.getView().getResolution();
        if (!res) return;

        // Clustering threshold distance in screen pixels
        const clusterDistPx = 38;
        const clusterDistMeters = clusterDistPx * res;

        type PairPos = {
            pair: GcpPair;
            coord: [number, number]; // EPSG:3857 center of the pair
        };

        const items: PairPos[] = [];
        for (const p of gcpPairs) {
            // The currently selected point is never clustered – it always stays expanded and editable
            if (p.id === selectedGcpId) continue;
            const cMap = fromLonLat([p.map_point.lon, p.map_point.lat]);
            const cRef = fromLonLat([p.ref_point.lon, p.ref_point.lat]);
            items.push({
                pair: p,
                coord: [(cMap[0] + cRef[0]) / 2, (cMap[1] + cRef[1]) / 2]
            });
        }

        type ClusterGroup = {
            id: string;
            center: [number, number];
            pairs: GcpPair[];
        };

        const groups: ClusterGroup[] = [];
        const visited = new Set<number>();

        for (let i = 0; i < items.length; i++) {
            if (visited.has(items[i].pair.id)) continue;
            const seed = items[i];
            visited.add(seed.pair.id);

            const grpPairs: GcpPair[] = [seed.pair];
            let sumX = seed.coord[0];
            let sumY = seed.coord[1];

            for (let j = i + 1; j < items.length; j++) {
                if (visited.has(items[j].pair.id)) continue;
                const other = items[j];
                const d = Math.hypot(seed.coord[0] - other.coord[0], seed.coord[1] - other.coord[1]);
                if (d <= clusterDistMeters) {
                    visited.add(other.pair.id);
                    grpPairs.push(other.pair);
                    sumX += other.coord[0];
                    sumY += other.coord[1];
                }
            }

            if (grpPairs.length > 1) {
                const center: [number, number] = [sumX / grpPairs.length, sumY / grpPairs.length];
                const clusterId = `cluster-${grpPairs.map((p) => p.id).sort((a, b) => a - b).join('-')}`;
                groups.push({ id: clusterId, center, pairs: grpPairs });
                for (const p of grpPairs) {
                    clusteredGcpIds.add(p.id);
                }
            }
        }

        // If a fan-out (spiderfy) is active, verify that the group still exists
        if (activeSpiderClusterId && !groups.some((g) => g.id === activeSpiderClusterId)) {
            activeSpiderClusterId = null;
        }

        for (const grp of groups) {
            if (activeSpiderClusterId === grp.id) {
                // Fan out the cluster into a circle
                const N = grp.pairs.length;
                const spiderRadiusPx = Math.min(65, 30 + N * 5);
                const spiderRadiusMeters = spiderRadiusPx * res;
                for (let idx = 0; idx < N; idx++) {
                    const angle = (2 * Math.PI * idx) / N - Math.PI / 2;
                    const offsetCoord: [number, number] = [
                        grp.center[0] + spiderRadiusMeters * Math.cos(angle),
                        grp.center[1] + spiderRadiusMeters * Math.sin(angle)
                    ];
                    const fLine = new OlFeature({
                        geometry: new LineString([grp.center, offsetCoord])
                    });
                    fLine.set('_spiderLine', true);

                    const fItem = new OlFeature({
                        geometry: new Point(offsetCoord)
                    });
                    fItem.set('_spiderGcp', grp.pairs[idx]);
                    fItem.set('_gcp', grp.pairs[idx]);
                    fItem.set('_gcpId', grp.pairs[idx].id);
                    fItem.set('_clusterId', grp.id);

                    gcpClusterSource.addFeatures([fLine, fItem]);
                }

                const fHub = new OlFeature({
                    geometry: new Point(grp.center)
                });
                fHub.set('_spiderHub', true);
                fHub.set('_clusterId', grp.id);
                gcpClusterSource.addFeature(fHub);
            } else {
                // Regular cluster badge with the point count
                const fCluster = new OlFeature({
                    geometry: new Point(grp.center)
                });
                fCluster.set('_clusterId', grp.id);
                fCluster.set('_clusterPairs', grp.pairs);
                fCluster.set('_clusterCount', grp.pairs.length);
                gcpClusterSource.addFeature(fCluster);
            }
        }

        gcpSource.changed();
    }

    function syncGcpDraft() {
        gcpDraftSource.clear();
        let pMapCoord: number[] | null = null;
        let pRefCoord: number[] | null = null;

        if (gcpDraftMap) {
            const geom = gj.readGeometry(gcpDraftMap, { dataProjection: 'EPSG:4326', featureProjection: 'EPSG:3857' }) as Point;
            pMapCoord = geom.getCoordinates();
            const f = new OlFeature({ geometry: geom });
            f.set('_gcpDraft', 'map');
            gcpDraftSource.addFeature(f);
        }
        if (gcpDraftRef) {
            const geom = gj.readGeometry(gcpDraftRef, { dataProjection: 'EPSG:4326', featureProjection: 'EPSG:3857' }) as Point;
            pRefCoord = geom.getCoordinates();
            const f = new OlFeature({ geometry: geom });
            f.set('_gcpDraft', 'ref');
            gcpDraftSource.addFeature(f);
        }
        if (pMapCoord && pRefCoord) {
            const fLine = new OlFeature({ geometry: new LineString([pMapCoord, pRefCoord]) });
            fLine.set('_gcpDraft', 'line');
            gcpDraftSource.addFeature(fLine);
        }
    }

    function syncGcpMode() {
        if (gcpDrawInteraction) {
            map?.removeInteraction(gcpDrawInteraction);
            gcpDrawInteraction = null;
        }
        if (gcpMode === 'map_point' || gcpMode === 'ref_point') {
            const d = new Draw({ type: 'Point' });
            d.on('drawend', (e) => {
                const geom = toApiGeom(e.feature as OlFeature);
                if (gcpMode === 'map_point' && onGcpMapPlaced) {
                    onGcpMapPlaced(geom);
                } else if (gcpMode === 'ref_point' && onGcpRefPlaced) {
                    onGcpRefPlaced(geom);
                }
            });
            map?.addInteraction(d);
            gcpDrawInteraction = d;
        } else if (gcpMode === 'idle') {
            gcpDraftSource.clear();
        }
    }

    function syncMode() {
        if (drawInteraction) { map!.removeInteraction(drawInteraction); drawInteraction = null; }
        if (mode === 'drawing' && drawType) {
            draftSource.clear();
            const d = new Draw({ source: draftSource, type: DRAW_TYPE[drawType] });
            d.on('drawstart', () => { sketching = true; });
            d.on('drawabort', () => { sketching = false; });
            d.on('drawend', (e) => {
                sketching = false;
                (e.feature as OlFeature).set('_draft', true); // makes vertices visible while the form is being filled in
                onDrawn(toApiGeom(e.feature as OlFeature), drawType!);
            });
            map!.addInteraction(d);
            drawInteraction = d;
        } else if (mode === 'idle') {
            draftSource.clear();
        }
    }

    // Esc while drawing removes the LAST placed vertex (doesn't delete the whole shape in progress).
    // Returns true if drawing is in progress (there was something to remove); otherwise false → the caller cancels drawing.
    export function drawRemoveLastPoint(): boolean {
        if (drawInteraction && sketching) {
            drawInteraction.removeLastPoint();
            return true;
        }
        return false;
    }

    // Centers/zooms the map to the given object (called by the panel after a click in the Records list).
    // The object must be loaded in the viewport (the list shows exactly these objects).
    export function focusFeature(id: number): void {
        if (!map) return;
        const feat =
            editSource.getFeatures().find((f) => f.get('_id') === id) ??
            viewSource.getFeatures().find((f) => f.get('_id') === id);
        const geom = feat?.getGeometry();
        if (!geom) return;
        const view = map.getView();
        const dur = reduceMotion ? 0 : 350;
        if (geom.getType() === 'Point') {
            const c = (geom as any).getCoordinates() as number[];
            // For a point, just pan smoothly; zoom in only if we're very far away.
            view.animate({ center: c, zoom: Math.max(view.getZoom() ?? 0, 15), duration: dur });
        } else {
            view.fit(geom.getExtent(), { padding: [48, 48, 48, 48], maxZoom: 18, duration: dur });
        }
    }

    export function focusGcp(id: number): void {
        if (!map) return;
        const pair = gcpPairs.find((p) => p.id === id);
        if (!pair) return;
        const c1 = fromLonLat([pair.map_point.lon, pair.map_point.lat]);
        const c2 = fromLonLat([pair.ref_point.lon, pair.ref_point.lat]);
        const dur = reduceMotion ? 0 : 350;
        const view = map.getView();
        const maxZ = view.getMaxZoom() ?? mapConfig.maxZoom ?? 19;
        if (Math.hypot(c1[0] - c2[0], c1[1] - c2[1]) < 5) {
            view.animate({ center: c1, zoom: maxZ, duration: dur });
        } else {
            const ext: [number, number, number, number] = [
                Math.min(c1[0], c2[0]),
                Math.min(c1[1], c2[1]),
                Math.max(c1[0], c2[0]),
                Math.max(c1[1], c2[1])
            ];
            view.fit(ext, { padding: [60, 60, 60, 60], maxZoom: maxZ, duration: dur });
        }
    }

    function zoomByDelta(delta: number) {
        if (!map) return;
        const view = map.getView();
        if (!view) return;
        const cur = view.getZoom();
        if (cur !== undefined) {
            const next = view.getConstrainedZoom(cur + delta);
            if (typeof next === 'number') {
                const dur = reduceMotion ? 0 : 250;
                if (dur > 0) {
                    if (view.getAnimating()) {
                        view.cancelAnimations();
                    }
                    view.animate({
                        zoom: next,
                        duration: dur,
                        easing: easeOut
                    });
                } else {
                    view.setZoom(next);
                }
            }
        }
    }

    function toggleJump() {
        jumpOpen = !jumpOpen;
        if (jumpOpen) {
            tick().then(() => jumpInputEl?.focus());
        }
    }

    function closeJump() {
        jumpOpen = false;
    }

    function onJumpKeydown(e: KeyboardEvent) {
        if (e.key === 'Escape') {
            e.stopPropagation();
            closeJump();
        }
    }

    // "Go to coordinates": accepts "lat, lon" (also swapped order, judged by the Czech Republic extent) and flies there.
    function doJump(e: Event) {
        e.preventDefault();
        const m = jumpVal.trim().match(/(-?\d+(?:[.,]\d+)?)[\s,;]+(-?\d+(?:[.,]\d+)?)/);
        if (!m || !map) return;
        let la = parseFloat(m[1].replace(',', '.'));
        let lo = parseFloat(m[2].replace(',', '.'));
        if (Number.isNaN(la) || Number.isNaN(lo)) return;
        if (la < 30 && lo > 40) { const t = la; la = lo; lo = t; } // entered as "lon, lat" → swap
        map.getView().animate({ center: fromLonLat([lo, la]), zoom: Math.max(map.getView().getZoom() ?? 0, 16), duration: reduceMotion ? 0 : 350 });
        closeJump();
    }

    function closeContextMenu() {
        contextMenu = null;
        copiedFeedback = false;
        if (copyTimer) {
            clearTimeout(copyTimer);
            copyTimer = null;
        }
    }

    async function copyCoordinates() {
        if (!contextMenu) return;
        try {
            await navigator.clipboard.writeText(contextMenu.text);
            copiedFeedback = true;
            if (copyTimer) clearTimeout(copyTimer);
            copyTimer = setTimeout(() => {
                closeContextMenu();
            }, 800);
        } catch {
            // clipboard unavailable
        }
    }

    onMount(() => {
        const c = mapConfig;
        let extent: [number, number, number, number] | undefined;
        if (c.maxBounds) {
            const [sw, ne] = c.maxBounds;
            extent = [...fromLonLat([sw[1], sw[0]]), ...fromLonLat([ne[1], ne[0]])] as [number, number, number, number];
        }
        const view = new View({
            center: fromLonLat([c.center[1], c.center[0]]),
            zoom: c.zoom, minZoom: c.minZoom, maxZoom: c.maxZoom, extent
        });
        map = new Map({
            target: mapEl,
            view,
            controls: defaultControls({
                attribution: false,
                zoom: false,
                rotate: false
            }).extend([
                new ScaleLine({ units: 'metric', bar: true, text: true, minWidth: 90 })
            ])
        });

        // Far above the tiles: overlays have zIndex 10 + order, records and GCPs on top.
        // Collected objects (vector layers) are ALWAYS rendered ABOVE reference points (GCPs).
        gcpLayer = new VectorLayer({ source: gcpSource, style: gcpStyleFor, zIndex: 100, visible: gcpVisible });
        gcpClusterLayer = new VectorLayer({ source: gcpClusterSource, style: gcpClusterStyleFor, zIndex: 101, visible: gcpVisible });
        gcpDraftLayer = new VectorLayer({ source: gcpDraftSource, style: gcpDraftStyleFor, zIndex: 102, visible: gcpVisible });
        viewLayer = new VectorLayer({ source: viewSource, style: styleFor, zIndex: 103, visible: featuresVisible });
        editLayer = new VectorLayer({ source: editSource, style: styleFor, zIndex: 104, visible: featuresVisible });
        draftLayer = new VectorLayer({ source: draftSource, style: styleFor, zIndex: 105, visible: featuresVisible });
        map.addLayer(new VectorLayer({ source: measureSource, style: measureStyle, zIndex: 110 }));
        map.addLayer(gcpLayer);
        map.addLayer(gcpClusterLayer);
        map.addLayer(gcpDraftLayer);
        map.addLayer(viewLayer);
        map.addLayer(editLayer);
        map.addLayer(draftLayer);

        view.on('change:resolution', () => {
            updateGcpClusters();
        });

        // A vertex is deleted only by an actual Alt+click (not just by holding Alt).
        const modifyEdit = new Modify({ features: editColl, deleteCondition: (e) => singleClick(e) && altKeyOnly(e) });
        modifyEdit.on('modifyend', (e) => {
            e.features.forEach((f) => {
                const id = (f as OlFeature).get('_id');
                if (id != null) onModify(id, toApiGeom(f as OlFeature));
            });
            // the click that ends a vertex drag must not close the detail (common when releasing over an empty map)
            modifyJustEnded = true;
            setTimeout(() => { modifyJustEnded = false; }, 300);
        });
        const modifyDraft = new Modify({ source: draftSource, deleteCondition: (e) => singleClick(e) && altKeyOnly(e) });
        modifyDraft.on('modifyend', (e) => {
            e.features.forEach((f) => onDraftChange(toApiGeom(f as OlFeature)));
        });
        const modifyGcp = new Modify({ features: gcpEditColl });
        modifyGcp.on('modifyend', (e) => {
            e.features.forEach((f) => {
                const feat = f as OlFeature;
                const id = feat.get('_gcpId') as number | undefined;
                const type = feat.get('_gcpType') as 'map' | 'ref' | undefined;
                if (id != null && type && onGcpModify) {
                    const geom = feat.getGeometry() as Point;
                    const coords = toLonLat(geom.getCoordinates(), 'EPSG:3857') as [number, number];
                    onGcpModify(id, type, coords);
                    updateGcpLine(id);
                }
            });
            modifyJustEnded = true;
            setTimeout(() => { modifyJustEnded = false; }, 300);
        });
        map.addInteraction(modifyEdit);
        map.addInteraction(modifyDraft);
        map.addInteraction(modifyGcp);
        // No snapping (Snap) – the vertex goes exactly where you drag it.
        // Predictable and simple; snapping used to cause "sticky" dragging.

        map.on('singleclick', (evt) => {
            if ((evt.originalEvent as MouseEvent).altKey) return; // vertex deletion
            if (measuring) return; // while measuring, a click only adds a point
            let picked: ApiFeature | null = null;
            let pickedGcp: GcpPair | null = null;
            let pickedCluster: { id: string; pairs: GcpPair[] } | null = null;
            let pickedSpiderGcp: GcpPair | null = null;

            map!.forEachFeatureAtPixel(evt.pixel, (feat) => {
                const ff = feat as OlFeature;
                // Cluster badge
                const clPairs = ff.get('_clusterPairs') as GcpPair[] | undefined;
                if (clPairs && !pickedCluster) {
                    pickedCluster = { id: ff.get('_clusterId') as string, pairs: clPairs };
                    return true;
                }
                // Point in an expanded fan-out (spiderfy)
                const spGcp = ff.get('_spiderGcp') as GcpPair | undefined;
                if (spGcp && !pickedSpiderGcp) {
                    pickedSpiderGcp = spGcp;
                    return true;
                }
                // Collected object
                const z = ff.get('_zvk') as ApiFeature | undefined;
                if (z && !picked) { picked = z; return true; }
                // GCP point (if not hidden in a cluster, or if selected)
                const g = ff.get('_gcp') as GcpPair | undefined;
                if (g && !pickedGcp && (!clusteredGcpIds.has(g.id) || g.id === selectedGcpId)) {
                    pickedGcp = g;
                    return true;
                }
                return false;
            }, { hitTolerance: 6 });

            // Click on a cluster badge: zoom in (zoom fit) or fan out (spiderfy)
            const curCluster = pickedCluster as { id: string; pairs: GcpPair[] } | null;
            if (curCluster) {
                const { id: clId, pairs } = curCluster;
                const v = map!.getView();
                const curZoom = v.getZoom() ?? 0;
                const maxZ = v.getMaxZoom() ?? 20;

                let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
                for (const p of pairs) {
                    const cMap = fromLonLat([p.map_point.lon, p.map_point.lat]);
                    const cRef = fromLonLat([p.ref_point.lon, p.ref_point.lat]);
                    minX = Math.min(minX, cMap[0], cRef[0]);
                    minY = Math.min(minY, cMap[1], cRef[1]);
                    maxX = Math.max(maxX, cMap[0], cRef[0]);
                    maxY = Math.max(maxY, cRef[1], cRef[1]);
                }

                const spreadMeters = Math.hypot(maxX - minX, maxY - minY);
                const dur = reduceMotion ? 0 : 350;

                if (spreadMeters > 3 && curZoom < maxZ - 1) {
                    v.fit([minX, minY, maxX, maxY], {
                        padding: [80, 80, 80, 80],
                        maxZoom: 19,
                        duration: dur
                    });
                    activeSpiderClusterId = null;
                } else {
                    // Points are at nearly identical coordinates or we're at max zoom -> fan out (spiderfy)
                    activeSpiderClusterId = activeSpiderClusterId === clId ? null : clId;
                    updateGcpClusters();
                }
                return;
            }

            // Click on a point in the fan-out -> select the pair and close the fan-out
            const curSpider = pickedSpiderGcp as GcpPair | null;
            if (curSpider) {
                activeSpiderClusterId = null;
                updateGcpClusters();
                if (onSelectGcp) onSelectGcp(curSpider);
                return;
            }

            // If the fan-out was open and the user clicked elsewhere, close the fan-out
            if (activeSpiderClusterId) {
                activeSpiderClusterId = null;
                updateGcpClusters();
            }

            if (picked) {
                onSelect(picked);
            } else if (pickedGcp && onSelectGcp) {
                onSelectGcp(pickedGcp);
            } else if ((selectedId != null || selectedGcpId != null) && mode !== 'drawing' && gcpMode === 'idle' && !modifyJustEnded) {
                onDeselect();
            }
        });

        // Cursor: over a selected/being-drawn shape 'move' (points/vertices can be dragged),
        // over other objects 'pointer' (can be clicked to select).
        map.on('pointermove', (evt) => {
            if (evt.dragging) return;
            let editable = false;
            let any = false;
            let topId: number | null = null;
            let topZvk: ApiFeature | null = null;
            let topGcpId: number | null = null;
            let topGcp: GcpPair | null = null;
            let topGcpType: 'map' | 'ref' | 'line' | null = null;
            let topCluster: GcpPair[] | null = null;
            let topClusterId: string | null = null;
            let topSpiderGcp: GcpPair | null = null;

            map!.forEachFeatureAtPixel(evt.pixel, (feat) => {
                any = true;
                const ff = feat as OlFeature;
                const clPairs = ff.get('_clusterPairs') as GcpPair[] | undefined;
                if (clPairs && topCluster === null) {
                    topCluster = clPairs;
                    topClusterId = (ff.get('_clusterId') as string) ?? null;
                }
                const spGcp = ff.get('_spiderGcp') as GcpPair | undefined;
                if (spGcp && topSpiderGcp === null) {
                    topSpiderGcp = spGcp;
                }
                const fid = ff.get('_id');
                if (topId === null && fid != null && ff.get('_draft') !== true) {
                    topId = fid as number;
                    topZvk = (ff.get('_zvk') as ApiFeature) ?? null;
                }
                const gcp = ff.get('_gcp') as GcpPair | undefined;
                if (gcp && topGcpId === null && (!clusteredGcpIds.has(gcp.id) || gcp.id === selectedGcpId)) {
                    topGcpId = gcp.id;
                    topGcp = gcp;
                    topGcpType = ff.get('_gcpType') ?? null;
                }
                if (ff.get('_draft') === true || (ff.get('_canEdit') !== false && fid === selId)) {
                    editable = true;
                }
                if (gcp && selectedGcpId != null && gcp.id === selectedGcpId && (topGcpType === 'map' || topGcpType === 'ref')) {
                    editable = true;
                }
                return false;
            }, { hitTolerance: 6 });
            // Highlight the object under the cursor – redraw only on an ACTUAL change (not on every move).
            if (topId !== hoverId) {
                hoverId = topId;
                viewSource.changed();
                editSource.changed();
            }
            if (topGcpId !== hoverGcpId) {
                hoverGcpId = topGcpId;
                gcpSource.changed();
            }
            if (topClusterId !== hoverClusterId) {
                hoverClusterId = topClusterId;
                gcpClusterSource.changed();
            }
            // Label with the name of the object under the cursor, follows the cursor.
            let name = '';
            const curTopCluster = topCluster as GcpPair[] | null;
            const curTopSpider = topSpiderGcp as GcpPair | null;
            if (curTopCluster) {
                const count = curTopCluster.length;
                const word = t.ui.points(count);
                name = `${t.detail.gcpCluster}: ${count} ${word} (${t.detail.gcpClusterHint})`;
            } else if (curTopSpider) {
                name = curTopSpider.label;
            } else if (topZvk) {
                name = featureTitle(topZvk);
            } else if (topGcp) {
                const gcp = topGcp as GcpPair;
                const cMap = fromLonLat([gcp.map_point.lon, gcp.map_point.lat]);
                const cRef = fromLonLat([gcp.ref_point.lon, gcp.ref_point.lat]);
                const res = map!.getView().getResolution() ?? 1;
                const isCollapsed = !isAtHighestZoom() && Math.hypot(cMap[0] - cRef[0], cMap[1] - cRef[1]) / res < 24;
                if (isCollapsed) {
                    name = gcp.label;
                } else {
                    const sub = topGcpType === 'map' ? t.detail.gcpMapCoord : topGcpType === 'ref' ? t.detail.gcpRefCoord : '';
                    name = `${gcp.label}${sub ? ` (${sub})` : ''}`;
                }
            }
            hoverName = name;
            if (name) { hoverX = evt.pixel[0]; hoverY = evt.pixel[1]; }
            // While holding Alt over an editable shape, the cursor shows vertex-deletion mode.
            const alt = (evt.originalEvent as MouseEvent).altKey;
            const isClickable = any || topCluster !== null || topSpiderGcp !== null;
            map!.getViewport().style.cursor = editable ? (alt ? 'crosshair' : 'move') : (isClickable ? 'pointer' : '');
        });

        // Delete a vertex with the right mouse button (if the click hits a vertex of the shape being edited).
        // Otherwise opens the context menu with a coordinate readout for that point.
        const onContextMenu = (e: MouseEvent) => {
            e.preventDefault();
            const pixel = map!.getEventPixel(e);
            const feature = activeEditable();
            if (feature && removeNearestVertex(feature, pixel)) {
                const geom = toApiGeom(feature);
                if (feature.get('_draft') === true) onDraftChange(geom);
                else if (feature.get('_id') != null) onModify(feature.get('_id') as number, geom);
                editSource.changed();
                draftSource.changed();
                closeContextMenu();
                return;
            }

            const coord = map!.getCoordinateFromPixel(pixel);
            if (!coord) return;
            const lonLat = toLonLat(coord, 'EPSG:3857');
            const lat = lonLat[1].toFixed(5);
            const lon = lonLat[0].toFixed(5);

            // Position the menu within the map bounds so it doesn't overflow the edge
            const size = map!.getSize() ?? [window.innerWidth, window.innerHeight];
            const menuWidth = 210;
            const menuHeight = mode === 'idle' && gcpMode === 'idle' ? 125 : 45;
            const x = Math.max(8, Math.min(pixel[0], size[0] - menuWidth - 8));
            const y = Math.max(8, Math.min(pixel[1], size[1] - menuHeight - 8));

            copiedFeedback = false;
            contextMenu = { x, y, text: `${lat}, ${lon}`, coord };
        };
        mapEl.addEventListener('contextmenu', onContextMenu);

        // Close the context menu and the go-to form on an outside click or Esc
        const onGlobalPointerDown = (e: PointerEvent) => {
            const target = e.target as HTMLElement | null;
            if (contextMenu && !target?.closest('.map-context-menu')) {
                closeContextMenu();
            }
            if (jumpOpen && !target?.closest('.map-jump-control')) {
                closeJump();
            }
        };
        const onGlobalKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                if (contextMenu) closeContextMenu();
                if (jumpOpen) closeJump();
            }
        };
        window.addEventListener('pointerdown', onGlobalPointerDown);
        window.addEventListener('keydown', onGlobalKeyDown);
        // Esc while measuring only ends the measurement – capture phase, so the page doesn't close the open record.
        const onMeasureEsc = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && measuring && !contextMenu) {
                e.stopPropagation();
                stopMeasure();
            }
        };
        window.addEventListener('keydown', onMeasureEsc, true);
        map.on('movestart', closeContextMenu);

        // Moving the cursor out of the map clears the hover highlight.
        const onMouseLeave = () => {
            hoverName = '';
            if (hoverId !== null) { hoverId = null; viewSource.changed(); editSource.changed(); }
            if (hoverGcpId !== null) { hoverGcpId = null; gcpSource.changed(); }
            if (hoverClusterId !== null) { hoverClusterId = null; gcpClusterSource.changed(); }
        };
        mapEl.addEventListener('mouseleave', onMouseLeave);

        const emitBounds = () => {
            const size = map!.getSize();
            if (!size) return;
            const ext = transformExtent(map!.getView().calculateExtent(size), 'EPSG:3857', 'EPSG:4326');
            onMoveEnd([ext[0], ext[1], ext[2], ext[3]]);
        };
        // moveend is reported IMMEDIATELY: collapsing a series of moves into one request (debounce)
        // is done by onMoveEnd in +page – a second delay in a row would only slow loading down.
        map.on('moveend', () => {
            emitBounds();
            updateGcpClusters();
        });
        map.once('rendercomplete', emitBounds); // first viewport (+page adds the delay)

        syncLayers();
        syncFeatures();
        syncGcpFeatures();
        syncGcpDraft();
        syncGcpMode();
        ready = true;

        return () => {
            statusDisposed = true; // late tiles must no longer schedule status reports
            for (const key of [...trackers.keys()]) untrackSource(key);
            if (statusTimer) { clearTimeout(statusTimer); statusTimer = null; }
            if (copyTimer) { clearTimeout(copyTimer); copyTimer = null; }
            if (gcpDrawInteraction) { map?.removeInteraction(gcpDrawInteraction); gcpDrawInteraction = null; }
            mapEl.removeEventListener('contextmenu', onContextMenu);
            mapEl.removeEventListener('mouseleave', onMouseLeave);
            window.removeEventListener('pointerdown', onGlobalPointerDown);
            window.removeEventListener('keydown', onGlobalKeyDown);
            window.removeEventListener('keydown', onMeasureEsc, true);
            map?.setTarget(undefined);
            map?.dispose();
            map = null;
        };
    });

    // Layer membership and order (ids) + definitions; opacity has its own effect,
    // so moving the slider doesn't rebuild the layer (see untrack in syncLayers).
    $effect(() => { const _ = [activeLayers.map((o) => o.id).join(' '), layers]; if (ready) syncLayers(); });
    $effect(() => { for (const o of activeLayers) void o.opacity; if (ready) syncOpacities(); });
    $effect(() => { const _ = features; if (ready) syncFeatures(); });
    $effect(() => { const _ = [mode, drawType]; if (ready) syncMode(); });
    // Reacts BOTH to the selection (selectedId) and to data reloads (features): after rebuilding
    // editSource, editColl must be re-bound to the fresh objects. Both dependencies are required.
    $effect(() => { const _ = features; selId = selectedId; if (ready) { syncEditable(); viewSource.changed(); editSource.changed(); } });

    $effect(() => { const _ = gcpPairs; if (ready) syncGcpFeatures(); });
    $effect(() => { const _ = [gcpDraftMap, gcpDraftRef]; if (ready) syncGcpDraft(); });
    $effect(() => { const _ = gcpMode; if (ready) syncGcpMode(); });
    $effect(() => {
        const _ = [selectedGcpId, gcpPairs];
        if (ready) {
            syncGcpEditable();
            updateGcpClusters();
            gcpSource.changed();
        }
    });

    $effect(() => {
        if (ready) {
            viewLayer?.setVisible(featuresVisible);
            editLayer?.setVisible(featuresVisible);
            draftLayer?.setVisible(featuresVisible);
        }
    });
    $effect(() => {
        if (ready) {
            gcpLayer?.setVisible(gcpVisible);
            gcpClusterLayer?.setVisible(gcpVisible);
            gcpDraftLayer?.setVisible(gcpVisible);
            updateGcpClusters();
        }
    });
</script>

<div class="map" role="region" aria-label={t.map.region}>
    <div class="ol-target" bind:this={mapEl}></div>

    {#if hoverName}
        <div class="hovertip" style="left: {hoverX}px; top: {hoverY}px;">{hoverName}</div>
    {/if}

    <!-- Map controls in the top left (Zoom In, Zoom Out, Crosshair / Go to coordinates) -->
    <div class="map-controls-group">
        <div class="map-ctrl-item">
            <button
                type="button"
                class="map-ctrl-btn"
                onclick={() => zoomByDelta(1)}
                title={t.map.zoomIn}
                aria-label={t.map.zoomIn}
            >
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
            </button>
            <div class="map-ctrl-tooltip" role="tooltip">{t.map.zoomIn}</div>
        </div>

        <div class="map-ctrl-item">
            <button
                type="button"
                class="map-ctrl-btn"
                onclick={() => zoomByDelta(-1)}
                title={t.map.zoomOut}
                aria-label={t.map.zoomOut}
            >
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
            </button>
            <div class="map-ctrl-tooltip" role="tooltip">{t.map.zoomOut}</div>
        </div>

        <div class="map-ctrl-item map-jump-control" class:active={jumpOpen}>
            <button
                type="button"
                class="map-ctrl-btn jump-btn"
                onclick={toggleJump}
                aria-expanded={jumpOpen}
                aria-label={t.map.jumpAria}
                title={!jumpOpen ? t.map.jumpTooltip : undefined}
            >
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="7" />
                    <line x1="12" y1="2" x2="12" y2="5" />
                    <line x1="12" y1="19" x2="12" y2="22" />
                    <line x1="2" y1="12" x2="5" y2="12" />
                    <line x1="19" y1="12" x2="22" y2="12" />
                    <circle cx="12" cy="12" r="2" fill="currentColor" />
                </svg>
            </button>
            {#if !jumpOpen}
                <div class="map-ctrl-tooltip" role="tooltip">{t.map.jumpTooltip}</div>
            {/if}
            {#if jumpOpen}
                <form class="jump-form" onsubmit={doJump} role="search">
                    <input
                        bind:this={jumpInputEl}
                        type="text"
                        bind:value={jumpVal}
                        placeholder={t.map.jumpPlaceholder}
                        aria-label={t.map.jumpAria}
                        onkeydown={onJumpKeydown}
                    />
                    <button type="submit" aria-label={t.map.jumpBtnAria} title={t.map.jumpTitle}>→</button>
                </form>
            {/if}
        </div>
    </div>

    <!-- Right-click context menu with coordinate readout -->
    {#if contextMenu}
        <div
            class="map-context-menu"
            style="left: {contextMenu.x}px; top: {contextMenu.y}px;"
            role="menu"
            aria-label={t.map.contextMenuAria}
        >
            <!-- Outside drawing it opens point picking (top bar); while drawing it copies directly. -->
            <button
                type="button"
                class="context-menu-item"
                role="menuitem"
                onclick={() => (mode === 'idle' && gcpMode === 'idle' ? startMeasure('point', contextMenu!.coord) : copyCoordinates())}
            >
                <span class="menu-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-7.5-7-12a7 7 0 0 1 14 0c0 4.5-7 12-7 12z" /><circle cx="12" cy="9" r="2.5" /></svg>
                </span>
                <span class="menu-label">
                    {#if mode === 'idle' && gcpMode === 'idle'}{t.map.pickPoint}
                    {:else if copiedFeedback}✓ {t.map.coordsCopiedShort}
                    {:else}{t.map.copyCoords}{/if}
                </span>
            </button>
            {#if mode === 'idle' && gcpMode === 'idle'}
                <button type="button" class="context-menu-item" role="menuitem" onclick={() => startMeasure('line')}>
                    <span class="menu-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l14-14 4 4-14 14z" /><path d="M7 13l2 2M10 10l2 2M13 7l2 2" /></svg>
                    </span>
                    <span class="menu-label">{t.map.measureLine}</span>
                </button>
                <button type="button" class="context-menu-item" role="menuitem" onclick={() => startMeasure('area')}>
                    <span class="menu-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M12 3l8 6-3 10H7L4 9z" /></svg>
                    </span>
                    <span class="menu-label">{t.map.measureArea}</span>
                </button>
            {/if}
        </div>
    {/if}

    {#if measuring}
        <div class="measure-bar" role="status" aria-live="polite">
            {#if measuring === 'point'}
                <span class="measure-val">{pointText || t.map.pickPointHint}</span>
                {#if pointText}
                    <button type="button" class="measure-copy" onclick={copyPoint}>
                        {copiedFeedback ? '✓ ' + t.map.coordsCopiedShort : t.map.copyBtn}
                    </button>
                {/if}
            {:else}
                <span class="measure-val">{measureText}</span>
            {/if}
            <button type="button" class="measure-close" onclick={stopMeasure} aria-label={t.map.measureClose} title={t.map.measureClose}>×</button>
        </div>
    {/if}
</div>

<style>
    .map { position: relative; width: 100%; height: 100%; background: #d9e4ea; }
    .ol-target { position: absolute; inset: 0; }

    /* Label with the name of the object under the cursor – follows the mouse without blocking it (pointer-events:none). */
    .hovertip {
        position: absolute; z-index: 5; pointer-events: none;
        transform: translate(14px, 14px); max-width: 16rem;
        padding: 0.15rem 0.45rem; border-radius: var(--r-sm);
        background: rgba(32, 36, 42, 0.86); color: #fff;
        font-size: 0.8rem; font-weight: 600; white-space: nowrap;
        overflow: hidden; text-overflow: ellipsis; box-shadow: var(--shadow-sm);
    }

    /* Group of map controls in the top left (zoom + crosshair) */
    .map-controls-group {
        position: absolute;
        top: 0.7rem;
        left: 0.7rem;
        z-index: 5;
        display: flex;
        flex-direction: column;
        gap: 0.28rem;
        user-select: none;
    }
    .map-ctrl-item {
        position: relative;
        display: flex;
        align-items: center;
    }
    .map-ctrl-btn {
        width: 1.85rem;
        height: 1.85rem;
        padding: 0;
        border: none;
        border-radius: var(--r-sm);
        background: var(--header);
        color: #fff;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: var(--shadow-sm);
        transition: background 0.12s, color 0.12s;
    }
    .map-ctrl-btn:hover,
    .map-ctrl-btn:focus-visible,
    .map-jump-control.active .map-ctrl-btn {
        background: var(--accent);
        color: #fff;
    }
    .map-ctrl-btn:focus-visible {
        outline: 3px solid var(--ring);
        outline-offset: 2px;
    }

    /* Nice dark tooltip – matched with the rest of the map (.hovertip) */
    .map-ctrl-tooltip {
        position: absolute;
        left: calc(100% + 7px);
        top: 50%;
        transform: translateY(-50%);
        padding: 0.2rem 0.5rem;
        border-radius: var(--r-sm);
        background: rgba(32, 36, 42, 0.88);
        color: #fff;
        font-size: 0.8rem;
        font-weight: 600;
        white-space: nowrap;
        pointer-events: none;
        box-shadow: var(--shadow-sm);
        opacity: 0;
        transition: opacity 0.12s ease;
        z-index: 10;
    }
    .map-ctrl-btn:hover + .map-ctrl-tooltip,
    .map-ctrl-btn:focus-visible + .map-ctrl-tooltip {
        opacity: 1;
    }

    /* Expandable form for entering coordinates. */
    .jump-form {
        position: absolute;
        left: calc(100% + 6px);
        top: 0;
        height: 1.85rem;
        display: flex;
        gap: 0;
        box-shadow: var(--shadow);
        border-radius: var(--r-sm);
        z-index: 10;
        animation: jumpFlyout 0.15s ease-out;
    }
    @keyframes jumpFlyout {
        from { opacity: 0; transform: translateX(-6px); }
        to { opacity: 1; transform: translateX(0); }
    }
    .jump-form input {
        width: 14rem;
        max-width: 42vw;
        height: 100%;
        font: inherit;
        font-size: 0.82rem;
        color: var(--ink);
        background: rgba(255, 255, 255, 0.98);
        border: 1px solid var(--border-strong);
        border-right: none;
        border-radius: var(--r-sm) 0 0 var(--r-sm);
        padding: 0 0.55rem;
        box-sizing: border-box;
    }
    .jump-form input:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px var(--ring);
        position: relative;
        z-index: 1;
    }
    .jump-form button {
        appearance: none;
        border: none;
        cursor: pointer;
        color: #fff;
        background: var(--header);
        font: inherit;
        font-weight: 700;
        padding: 0 0.65rem;
        border-radius: 0 var(--r-sm) var(--r-sm) 0;
        transition: background 0.12s;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .jump-form button:hover {
        background: var(--accent);
    }
    .jump-form button:focus-visible {
        outline: 3px solid var(--ring);
        outline-offset: 1px;
    }

    /* Right-click context menu with coordinate readout. */
    .map-context-menu {
        position: absolute;
        z-index: 20;
        background: rgba(255, 255, 255, 0.97);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        border: 1px solid var(--border-strong);
        border-radius: var(--r-sm);
        box-shadow: var(--shadow);
        padding: 0.25rem;
        min-width: 12rem;
        animation: menuFadeIn 0.12s ease-out;
    }
    @keyframes menuFadeIn {
        from { opacity: 0; transform: scale(0.96); }
        to { opacity: 1; transform: scale(1); }
    }
    .context-menu-item {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.35rem 0.5rem;
        border: none;
        border-radius: var(--r-sm);
        background: transparent;
        cursor: pointer;
        text-align: left;
        font: inherit;
        color: var(--ink);
        transition: background 0.12s;
    }
    .context-menu-item:hover,
    .context-menu-item:focus-visible {
        background: var(--hover);
        outline: none;
    }
    /* Menu icons: equally wide box so the labels line up. */
    .menu-ico {
        flex-shrink: 0;
        width: 1.25rem;
        height: 1.25rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: var(--muted);
    }
    .menu-ico svg { width: 18px; height: 18px; }
    .menu-label { font-size: 0.85rem; }
    .measure-bar {
        position: absolute; top: 0.75rem; left: 50%; transform: translateX(-50%); z-index: 20;
        display: flex; align-items: center; gap: 0.6rem; max-width: calc(100% - 2rem);
        padding: 0.4rem 0.4rem 0.4rem 0.9rem; border-radius: 999px;
        background: rgba(255, 255, 255, 0.97); border: 1px solid var(--border-strong); box-shadow: var(--shadow);
        font-size: 0.86rem; color: var(--ink);
    }
    .measure-val { font-weight: 600; font-variant-numeric: tabular-nums; }
    .measure-copy {
        flex-shrink: 0; height: 28px; padding: 0 0.75rem; border-radius: 999px; border: none; cursor: pointer;
        background: var(--accent); color: #fff; font: inherit; font-size: 0.8rem; font-weight: 600;
    }
    .measure-copy:hover { background: var(--accent-600); }
    .measure-close {
        flex-shrink: 0; width: 28px; height: 28px; border-radius: 50%; border: none; cursor: pointer;
        background: var(--hover); color: var(--ink); font-size: 18px; line-height: 1;
    }
    .measure-close:hover { background: var(--accent); color: #fff; }
</style>
