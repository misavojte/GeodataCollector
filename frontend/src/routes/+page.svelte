<script lang="ts">
    import { onMount, tick } from 'svelte';
    import { base } from '$app/paths';
    import TopBar from '$lib/components/TopBar.svelte';
    import LayersCard from '$lib/components/LayersCard.svelte';
    import NewRecordCard from '$lib/components/NewRecordCard.svelte';
    import RecordsCard from '$lib/components/RecordsCard.svelte';
    import DetailCard from '$lib/components/DetailCard.svelte';
    import GcpDetailCard from '$lib/components/GcpDetailCard.svelte';
    import LayersManager from '$lib/components/LayersManager.svelte';
    import FieldsManager from '$lib/components/FieldsManager.svelte';
    import UsersManager from '$lib/components/UsersManager.svelte';
    import Toasts from '$lib/components/Toasts.svelte';
    import { replaceState } from '$app/navigation';
    import { updated } from '$app/state';
    import { api, ApiError, setCsrf, authBounce, authBootOk } from '$lib/api';
    import { loadFeatureSet, invalidateFeatures, geomInView } from '$lib/featureData';
    import { toast, saveToast } from '$lib/toast.svelte';
    import { MSG, t, applyDefaultLocale } from '$lib/i18n';
    import type {
        User, MapConfig, LayerDef, UserLayer, LayerStatus, FieldDefinition,
        ApiFeature, Geometry, GeometryType, FieldError, GcpPair, ActiveOverlay
    } from '$lib/types';

    let ready = $state(false);
    let blocked = $state(false);      // touch device (coarse && noHover) – hard block on collecting
    let tooNarrow = $state(false);    // narrow window on desktop – soft warning, user can continue
    let narrowOverride = $state(false);
    let loadFailed = $state(false);
    let chunkFail = $state(false);    // a lazily loaded app chunk failed (not network/API) – most likely a new version
    // 401/419 on startup: 'redirecting' = calm wait for login, 'blocked' = safeguard
    // against looping between the app and the login page (decided by gotoLogin in $lib/api).
    let authGate = $state<'redirecting' | 'blocked' | null>(null);
    let showWelcome = $state(false); // one-time welcome for a new collector (persisted in localStorage)
    const WELCOME_KEY = 'zvk_welcomed';

    let user = $state<User | null>(null);
    let csrf = $state('');
    let mapConfig = $state<MapConfig | null>(null);
    let appName = $state('Geodata Collector');   // branding from config.php (via /api/me)
    let appSubtitle = $state('');
    let layers = $state<LayerDef[]>([]);
    let fields = $state<FieldDefinition[]>([]);
    let features = $state<ApiFeature[]>([]);
    let featuresLoading = $state(false); // viewport load in progress (distinguishes "loading" from "empty")
    let featuresVisible = $state(true);
    let gcpVisible = $state(false);
    let recordsTypeFilter = $state<'features' | 'gcp' | 'all'>('features');
    let gcpPairs = $state<GcpPair[]>([]);
    let gcpMode = $state<'idle' | 'map_point' | 'ref_point' | 'form'>('idle');
    let gcpDraftMap = $state<Geometry | null>(null);
    let gcpDraftRef = $state<Geometry | null>(null);
    let gcpValues = $state<{ label: string; description: string | null }>({ label: '', description: '' });
    let gcpErrors = $state<Record<string, string>>({});
    let gcpSaving = $state(false);

    let selectedGcp = $state<GcpPair | null>(null);
    let gcpDetailValues = $state<{ label: string; description: string | null }>({ label: '', description: '' });
    let gcpDetailOriginal = $state('');
    let gcpDetailErrors = $state<Record<string, string>>({});

    // Single list of all layers in the user's local order (as on the map: first = on top,
    // last = at the bottom as the basemap); enabled flag and visibility per layer. The map only gets enabled ones.
    let userLayers = $state<UserLayer[]>([]);
    let layerStatus = $state<Record<string, LayerStatus>>({}); // tile status of active layers (reported by the map)
    let mineOnly = $state(false);
    const activeLayers = $derived.by(() => {
        const list: ActiveOverlay[] = []; // render order is bottom-up → walk the list in reverse
        for (const u of [...userLayers].reverse()) {
            if (u.on) {
                list.push({ id: u.id, opacity: u.opacity });
                // Sublayers are enabled automatically with their parent layer and share its opacity
                const children = layers.filter((l) => l.parent_key === u.id || (l.parent_id != null && String(l.parent_id) === u.id));
                for (const ch of children) {
                    list.push({ id: ch.id, opacity: u.opacity });
                }
            }
        }
        return list;
    });

    // Layer selection, order and visibility survive a page reload (only in this
    // browser, not on the account).
    const LAYERS_PREF_KEY = 'zvk_layers';
    function restoreLayerPrefs(): UserLayer[] | null {
        try {
            const raw = localStorage.getItem(LAYERS_PREF_KEY);
            if (!raw) return null;
            const saved = JSON.parse(raw) as { v?: unknown; layers?: unknown };
            if (!Array.isArray(saved.layers)) return null;
            // The old format (without v:2) stored bottom-up order → reverse once so the map doesn't change.
            const list = saved.v === 2 ? saved.layers : [...saved.layers].reverse();
            const out = list
                .filter((u): u is { id: string; on?: unknown; opacity?: unknown } =>
                    !!u && typeof u === 'object' && typeof (u as { id?: unknown }).id === 'string')
                .map((u) => {
                    const op = Number(u.opacity);
                    return { id: u.id, on: u.on === true, opacity: Number.isFinite(op) ? Math.min(1, Math.max(0, op)) : 1 };
                });
            return out.length > 0 ? out : null;
        } catch {
            return null; // corrupted or unavailable storage → default state
        }
    }
    // Duplicate ids (corrupted storage, hand-edited layer config) would break the
    // keyed {#each} in the panel – always keep only the first occurrence.
    function dedupeById(list: UserLayer[]): UserLayer[] {
        const seen = new Set<string>();
        return list.filter((u) => {
            if (seen.has(u.id)) return false;
            seen.add(u.id);
            return true;
        });
    }
    // Reconciles the saved state with the current layer offer: drops unknown ones, adds new ones
    // on top (disabled). Without saved state: admin order reversed (the admin's first
    // layer = default basemap sits at the bottom of the list and is enabled).
    // Sublayers (parent_id) are not shown separately in the panel – they are enabled with their parent.
    function reconcileUserLayers(stored: UserLayer[] | null): UserLayer[] {
        const roots = layers.filter((l) => !l.parent_id && !l.parent_key);
        if (!stored) return dedupeById(roots.map((l, i) => ({ id: l.id, on: i === 0, opacity: 1 })).reverse());
        const known = dedupeById(stored.filter((u) => roots.some((l) => l.id === u.id)));
        const have = new Set(known.map((u) => u.id));
        const added = roots.filter((l) => !have.has(l.id)).map((l) => ({ id: l.id, on: false, opacity: 1 }));
        const next = dedupeById([...added.reverse(), ...known]);
        // The saved state is useless (only unknown layers) → don't leave the map empty.
        if (known.length === 0 && next.length > 0 && !next.some((u) => u.on)) next[next.length - 1].on = true;
        return next;
    }

    // Right panel: tiles switch sections. The selected object's detail "takes over" the panel.
    let activeTab = $state<'layers' | 'add' | 'records'>('add');
    let mapView: { focusFeature: (id: number) => void; focusGcp?: (id: number) => void; drawRemoveLastPoint: () => boolean } | undefined = $state();
    // OpenLayers components are loaded dynamically (off the critical path of the first render).
    let MapViewComp = $state<any>(null);
    let HistoryManagerComp = $state<any>(null);

    // Admin modals (admin only)
    let showLayers = $state(false);
    let showFields = $state(false);
    let showUsers = $state(false);
    let showHistory = $state(false);
    let historyFocusId = $state<number | null>(null);
    async function openHistory(featureId: number | null = null) {
        historyFocusId = featureId;
        if (!HistoryManagerComp) {
            try { HistoryManagerComp = (await import('$lib/components/HistoryManager.svelte')).default; }
            catch (e) { console.error(e); toast(MSG.loadError, 'error'); return; }
        }
        showHistory = true;
    }
    function afterRestore(restoredId: number) {
        // If the restored record is currently open in the detail, discard its stale in-progress
        // state and close it – otherwise the unsaved edit (auto-save) would overwrite it again.
        if (selected && selected.id === restoredId) {
            if (pending && pending.id === restoredId) pending = null;
            if (lastFailed && lastFailed.id === restoredId) lastFailed = null;
            saveToast.clear();
            saveActive = false;
            sessionDidSave = false;
            selected = null;
        }
        invalidateFeatures(); // restore changed data → discard cached viewports and memo versions
        if (lastBbox) loadFeatures(lastBbox, true); // the restored/undeleted record shows up on the map
    }

    // New record
    let newMode = $state<'idle' | 'drawing' | 'form'>('idle');
    let drawType = $state<GeometryType | null>(null);
    let draftGeom = $state<Geometry | null>(null);
    let createValues = $state<Record<string, any>>({});
    let createErrors = $state<Record<string, string>>({});
    let createSaving = $state(false);

    // Detail of an existing record
    let selected = $state<ApiFeature | null>(null);
    let detailValues = $state<Record<string, any>>({});
    let detailOriginal = $state('');
    let detailErrors = $state<Record<string, string>>({}); // inline field errors; save status lives in the status toast
    // Save status goes to ONE recycled status toast (Saving… → Saved / error).
    // The same element switches in place → no new toast is created, so nothing jumps around.
    let saveActive = false;                                      // is an editing save "session" in progress?
    let sessionDidSave = false;                                  // did a real PUT happen during this session? (otherwise don't claim "Saved")
    // No time window: saves IMMEDIATELY when a change is completed (field blur / selection / toggle / vertex
    // drag). History cleanliness is handled by the SERVER – revisions are merged when edits come within 5 s
    // of each other (FeatureRevisionRepository). The listener is on `change` (not `input`), so no PUT per keystroke.
    // Serialized save queue (no Save button). Holds state independently of `selected`,
    // so closing/switching during a save cannot lose the last edit, and it prevents concurrent/
    // duplicate PUTs (reordering). lastFailed is bound to a specific record.
    let inFlightJson = '';                                       // payload currently being sent to the server
    let inFlightId: number | null = null;                        // … and its record (dedup must be per-record)
    let saveRunning = false;                                     // is the queue loop running?
    type SavePayload = { attributes?: Record<string, any>; geometry?: Geometry };
    let pending: { id: number; json: string; payload: SavePayload } | null = null;
    let lastFailed: { id: number; json: string } | null = null; // payload rejected by VALIDATION (transient errors do not block)
    let geomOriginal = '';                                       // JSON of the last saved geometry (dirty check)
    let saveRetryTimer: ReturnType<typeof setTimeout> | null = null; // one automatic retry after a transient error

    let lastBbox = $state<[number, number, number, number] | null>(null);

    // Records list = only objects in the current viewport (the map also draws a wider cache/buffer; see featureData).
    const viewFeatures = $derived(
        lastBbox ? features.filter((f) => geomInView(f.geometry, lastBbox!)) : features
    );

    function gcpInView(g: GcpPair, bbox: [number, number, number, number]): boolean {
        const inBox = (p: { lon: number; lat: number }) =>
            p && p.lon >= bbox[0] && p.lon <= bbox[2] && p.lat >= bbox[1] && p.lat <= bbox[3];
        return inBox(g.map_point) || inBox(g.ref_point);
    }

    const viewGcpPairs = $derived(
        lastBbox ? gcpPairs.filter((g) => gcpInView(g, lastBbox!)) : gcpPairs
    );

    function onRecordsTypeFilterChange(val: 'features' | 'gcp' | 'all') {
        recordsTypeFilter = val;
        if (val === 'features') {
            featuresVisible = true;
            gcpVisible = false;
        } else if (val === 'gcp') {
            featuresVisible = false;
            gcpVisible = true;
        } else if (val === 'all') {
            featuresVisible = true;
            gcpVisible = true;
        }
    }

    $effect(() => {
        if (featuresVisible && gcpVisible) {
            if (recordsTypeFilter !== 'all') recordsTypeFilter = 'all';
        } else if (featuresVisible && !gcpVisible) {
            if (recordsTypeFilter !== 'features') recordsTypeFilter = 'features';
        } else if (!featuresVisible && gcpVisible) {
            if (recordsTypeFilter !== 'gcp') recordsTypeFilter = 'gcp';
        }
    });

    const selectedId = $derived(selected ? selected.id : null);
    const detailFields = $derived(selected ? fieldsFor(selected.properties.meta.geometry_type) : []);
    const detailCanEdit = $derived(selected ? selected.properties.meta.can_edit : false);
    const detailTitle = $derived(
        (detailValues.nazev as string) || (selected ? `${MSG.untitled} #${selected.id}` : '')
    );
    const createFields = $derived(drawType ? fieldsFor(drawType) : []);

    function fieldsFor(type: GeometryType): FieldDefinition[] {
        return fields.filter((f) => !Array.isArray(f.applies_to) || f.applies_to.includes(type));
    }
    function withDefaults(type: GeometryType): Record<string, any> {
        const v: Record<string, any> = {};
        for (const f of fieldsFor(type)) if (f.default_value !== null && f.default_value !== undefined) v[f.field_key] = f.default_value;
        return v;
    }
    function mapErrors(errs: FieldError[] | null): Record<string, string> {
        const o: Record<string, string> = {};
        for (const e of errs ?? []) o[e.field] = e.message;
        return o;
    }
    function isBusy(): boolean {
        return newMode !== 'idle' || selected !== null || gcpMode !== 'idle' || selectedGcp !== null;
    }

    // Guard against an incomplete shape (OpenLayers won't let you create one, but we give
    // a clear message instead of a server error): line ≥ 2 points, polygon ≥ 3 vertices.
    const MIN_GEOM_MSG = t.page.minGeom;
    const BUSY_MSG = t.page.busy;
    function geomOk(g: Geometry): boolean {
        if (g.type === 'LineString') return Array.isArray(g.coordinates) && (g.coordinates as number[][]).length >= 2;
        if (g.type === 'Polygon') {
            const rings = g.coordinates as number[][][];
            return Array.isArray(rings) && rings.length > 0 && rings.every((r) => r.length >= 4);
        }
        return true;
    }

    function dismissWelcome() {
        showWelcome = false;
        try { localStorage.setItem(WELCOME_KEY, '1'); } catch { /* ignore */ }
    }

    // Hard-block only based on input CAPABILITY (purely touch device without mouse / touchpad)
    // – not window width, so we don't lock out users with a narrow window / zoomed browser (accessibility).
    // Checking any-pointer / any-hover ensures hybrid laptops with a touchscreen are not
    // wrongly blocked, even after waking from sleep or touching the screen.
    function isTouchDevice(): boolean {
        const hasFinePointer = window.matchMedia('(any-pointer: fine)').matches || window.matchMedia('(any-hover: hover)').matches;
        if (hasFinePointer) return false;
        return window.matchMedia('(pointer: coarse)').matches && window.matchMedia('(hover: none)').matches;
    }
    // Narrow window on desktop = only a soft warning (user can continue); re-evaluated on resize.
    function evalViewport() {
        tooNarrow = !blocked && !narrowOverride && window.innerWidth < 768;
    }

    onMount(async () => {
        // Signal for boot-guard.js (static/): the app code has started, the safeguard is cleared.
        document.documentElement.dataset.zvkBoot = '1';
        try { sessionStorage.removeItem('zvk_bootheal'); } catch { /* storage disabled */ }
        if (isTouchDevice()) { blocked = true; return; }
        evalViewport();
        try {
            const mapMod = import('$lib/components/MapView.svelte'); // OL chunk in parallel with the API calls
            const me = await api.me();
            if (applyDefaultLocale(me.app.default_language)) return; // reloading into the configured language
            setCsrf(me.csrf_token);
            user = me.user;
            csrf = me.csrf_token;
            appName = me.app.name;
            appSubtitle = me.app.subtitle;
            mapConfig = me.map;
            const [lr, fr, gcpr] = await Promise.all([api.layers(), api.fields(), api.gcpList()]);
            layers = lr.layers;
            fields = fr.fields;
            gcpPairs = gcpr.pairs;
            userLayers = reconcileUserLayers(restoreLayerPrefs()); // saved state, otherwise the default (first layer)
            MapViewComp = (await mapMod).default; // the map is needed only now (after passing the gate)
            ready = true;
            authBootOk();      // successful start → reset the login-bounce safeguards
            stripBootParams(); // strip ?li/?v from the URL (cosmetic only, so it doesn't spread further)
            try { showWelcome = localStorage.getItem(WELCOME_KEY) !== '1'; } catch { /* localStorage unavailable → no welcome */ }
        } catch (e) {
            if (e instanceof ApiError && (e.status === 401 || e.status === 419 || e.code === 'csrf' || e.code === 'unauthorized')) {
                // gotoLogin in $lib/api has already decided the next step: either a redirect
                // to login is in progress, or (on repeated bounces) a calm screen. No
                // red toast or load-error message – nothing crashed.
                authGate = authBounce() === 'blocked' ? 'blocked' : 'redirecting';
                return;
            }
            console.error(e);
            chunkFail = !(e instanceof ApiError); // failure downloading code (chunk), not an API response
            loadFailed = true;
            toast(MSG.loadError, 'error');
        }
    });

    // The URL may carry ?li=1&v=… from login (cache-bust). After a successful start we strip them.
    function stripBootParams() {
        if (!location.search) return;
        try { replaceState(location.pathname, {}); }
        catch { try { history.replaceState(history.state, '', location.pathname); } catch { /* ignore */ } }
    }

    // Fresh load with a unique URL = bypasses the stale cached copy (unlike reload()).
    function freshReload() {
        location.replace('./?v=' + Date.now().toString(36));
    }

    // A new version was released while the app is open (poll _app/version.json): offer a reload.
    // Unsaved work in progress is protected by the existing beforeunload guard.
    let updateOffered = false;
    $effect(() => {
        if (updated.current && ready && !updateOffered) {
            updateOffered = true;
            toast(MSG.newVersion, 'ok', { label: MSG.newVersionButton, fn: freshReload });
        }
    });

    function onKeydown(e: KeyboardEvent) {
        if (showLayers || showFields || showUsers || showHistory) return; // admin modals handle keys themselves
        // Ctrl/Cmd+Enter in the new-record form = Create and continue (bulk entry without the mouse).
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            if (newMode === 'form') {
                e.preventDefault();
                saveNewrecAndNext();
                return;
            }
            if (gcpMode === 'form') {
                e.preventDefault();
                saveGcp(true);
                return;
            }
        }
        if (e.key !== 'Escape') return;
        // Esc while drawing removes the LAST vertex (doesn't delete the whole shape in progress); when there's
        // nothing to remove, drawing is cancelled. In the form Esc intentionally discards NOTHING (use the Discard button).
        if (newMode === 'drawing') { if (!mapView?.drawRemoveLastPoint()) cancelNewrec(); }
        else if (gcpMode === 'map_point' || gcpMode === 'ref_point') { cancelGcp(); }
        else if (newMode === 'idle' && gcpMode === 'idle') {
            if (selected) closeDetail();
            else if (selectedGcp) closeGcpDetail();
        }
    }

    // Is anything unsaved? (new record being drawn/filled in, pending/running batch, or a dirty detail)
    function hasUnsaved(): boolean {
        if (newMode !== 'idle' || gcpMode !== 'idle') return true;
        if (saveRunning || pending || gcpSaving) return true;
        if (selectedGcp && JSON.stringify(gcpDetailValues) !== gcpDetailOriginal) return true;
        return dirtyPayload() !== null;
    }
    function onBeforeUnload(e: BeforeUnloadEvent) {
        if (!hasUnsaved()) return;
        e.preventDefault();
        e.returnValue = ''; // forces the browser's native confirmation (close/F5/Back with unsaved work)
    }

    // Selected layers (order, enabled, visibility) are saved continuously.
    $effect(() => {
        const snap = JSON.stringify({ v: 2, layers: userLayers });
        if (!ready) return;
        try { localStorage.setItem(LAYERS_PREF_KEY, snap); } catch { /* ignore */ }
    });
    // Toggling the "mine only" filter → reload.
    $effect(() => {
        const _ = mineOnly;
        if (ready && lastBbox) loadFeatures(lastBbox);
    });
    // NOTE: auto-save is NOT triggered reactively from detailValues content (on open the form
    // fills empty/false into unset fields itself, which is not a user change). It is triggered only by
    // an actual event: a field edit (onDetailEdit from input/change) or a geometry edit (onModify).

    // Viewport loading is delegated to the data layer (cache + freshness probe + "last one wins").
    // A null return means "do nothing" (overtaken by a newer move / invalidation / error).
    async function loadFeatures(bbox: [number, number, number, number], force = false) {
        if (!force && isBusy()) return;
        featuresLoading = true;
        try {
            const set = await loadFeatureSet(bbox, { mine: mineOnly, force });
            if (set) features = set;
        } finally {
            featuresLoading = false;
        }
    }

    // A short delay collapses a series of quick moves (mouse wheel zoom = moveend on every
    // step) into ONE request – so shared hosting doesn't get a burst of PHP processes
    // and DB connections. The last bbox wins; the cache/epoch logic in featureData still applies.
    let moveLoadTimer: ReturnType<typeof setTimeout> | null = null;
    function onMoveEnd(bbox: [number, number, number, number]) {
        lastBbox = bbox;
        if (moveLoadTimer !== null) clearTimeout(moveLoadTimer);
        moveLoadTimer = setTimeout(() => {
            moveLoadTimer = null;
            loadFeatures(bbox);
        }, 250);
    }

    // --- Panel tabs ---
    function setTab(tab: 'layers' | 'add' | 'records') {
        if (tab === activeTab) return;
        // A record in progress (drawing and form) SURVIVES switching tabs – it stays on the map
        // and in state (dot on the "Add" tab) and continues on return. Drawing used to be cancelled,
        // which silently discarded already placed vertices; this also allows switching the basemap in "Layers" meanwhile.
        activeTab = tab;
    }
    // Click on a row in the Records list: center the map and open the detail.
    function selectFeatureFromList(f: ApiFeature) {
        if (isBusy()) { toast(BUSY_MSG, 'error'); return; }
        if (selectedGcp) closeGcpDetail();
        featuresVisible = true;
        openDetail(f);
        mapView?.focusFeature(f.id);
    }

    function selectGcpFromList(g: GcpPair) {
        if (isBusy()) { toast(BUSY_MSG, 'error'); return; }
        if (selected) closeDetail();
        gcpVisible = true;
        openGcpDetail(g);
        mapView?.focusGcp?.(g.id);
    }

    // After an admin edits layers, reload the public list and check the selection is still valid.
    async function reloadLayers() {
        try {
            const lr = await api.layers();
            layers = lr.layers;
            userLayers = reconcileUserLayers(userLayers.map((u) => ({ ...u }))); // deleted ones removed, new ones appended
        } catch (e) {
            console.error(e);
            toast(t.page.layersReloadFail, 'error');
        }
    }

    // After an admin edits the metadata field set, reload the definitions (reflected in the forms).
    async function reloadFields() {
        try {
            fields = (await api.fields()).fields;
        } catch (e) {
            console.error(e);
            toast(MSG.loadError, 'error');
        }
    }

    // --- New record ---
    function startDrawing(type: GeometryType) {
        if (selected) closeDetail();
        if (selectedGcp) closeGcpDetail();
        if (gcpMode !== 'idle') cancelGcp();
        featuresVisible = true;
        drawType = type;
        newMode = 'drawing';
    }
    function onDrawn(geom: Geometry, _type: GeometryType) {
        draftGeom = geom;
        createValues = drawType ? withDefaults(drawType) : {};
        createErrors = {};
        newMode = 'form';
    }
    function onDraftChange(geom: Geometry) {
        draftGeom = geom;
        if (!geomOk(geom)) toast(MIN_GEOM_MSG, 'error'); // immediate feedback; the shape can be fixed
    }
    function cancelNewrec() {
        newMode = 'idle';
        drawType = null;
        draftGeom = null;
        if (lastBbox) loadFeatures(lastBbox);
    }
    function discardNewrec() {
        if (!window.confirm(t.page.confirmDiscardDraft)) return;
        cancelNewrec();
    }
    async function saveNewrec(next = false) {
        if (!draftGeom || !drawType) return;
        if (!geomOk(draftGeom)) { toast(MIN_GEOM_MSG, 'error'); return; }
        createErrors = {};
        createSaving = true;
        const type = drawType;
        try {
            const resp = await api.createFeature({ geometry: draftGeom, geometry_type: type, attributes: createValues });
            features = [...features, resp];
            invalidateFeatures(); // data changed → discard cached viewports (otherwise the new object would be missing)
            toast(MSG.saved);
            if (next) {
                // "Create and continue": re-arm the SAME tool for the next record, without jumping to the detail
                draftGeom = null;
                createValues = withDefaults(type);
                createErrors = {};
                drawType = type;
                newMode = 'drawing';
            } else {
                newMode = 'idle';
                drawType = null;
                draftGeom = null;
                openDetail(resp); // select and show the newly created object right away
            }
        } catch (e) {
            // Failure is reported with a RED toast (not a message in the panel); on validation the fields stay highlighted.
            if (e instanceof ApiError && e.fields) { createErrors = mapErrors(e.fields); toast(MSG.formHasErrors, 'error'); }
            else { toast((e as Error).message || MSG.saveError, 'error'); }
        } finally {
            createSaving = false;
        }
    }
    const saveNewrecAndNext = () => saveNewrec(true);

    // --- Detail of an existing record ---
    // The map viewport carries full attributes, so we open the detail right away (no extra fetch).
    async function openDetail(f: ApiFeature) {
        if (newMode !== 'idle' || gcpMode !== 'idle') { toast(BUSY_MSG, 'error'); return; }
        if (selectedGcp) closeGcpDetail();
        // Clicking the already selected object again (common when working with vertices)
        // must not reset the in-progress form and discard unsaved data.
        if (selected && selected.id === f.id) return;
        // Switching to another object: first flush pending changes of the previous one (otherwise they'd be lost).
        if (selected) {
            if (!confirmLeaveIfBlocked()) return; // unsavable (rejected) change – don't discard without confirmation
            flushSave();
        }
        selected = f;
        detailValues = { ...(f.properties.attributes ?? {}) };
        detailErrors = {};
        geomOriginal = JSON.stringify(f.geometry);
        detailOriginal = JSON.stringify(detailValues);
        // After rendering, the form itself fills empty/false into unset fields (select bind:value,
        // checkbox bind:checked). We wait for that and take the baseline only AFTERWARDS – otherwise this
        // normalization would look like a change. (Saving itself is also triggered by an event, not reactivity.)
        await tick();
        if (selected && selected.id === f.id) detailOriginal = JSON.stringify(detailValues);
    }
    function closeDetail() {
        if (!confirmLeaveIfBlocked()) return; // unsavable (rejected) change – don't discard without confirmation
        flushSave(); // flush pending changes (self-guarded; saves nothing when there's nothing to save)
        selected = null;
        maybeSettleSave();
        if (lastBbox) loadFeatures(lastBbox);
    }
    function onMapDeselect() {
        if (selected) closeDetail();
        if (selectedGcp) closeGcpDetail();
    }
    function isBlocked(id: number, json: string): boolean {
        return !!lastFailed && lastFailed.id === id && lastFailed.json === json;
    }
    // Leaving (close/switch) asks only when the record has an unsaved value that the server
    // rejected in validation – it can't be auto-saved, so the typed (even if invalid) text would be silently lost.
    function confirmLeaveIfBlocked(): boolean {
        if (!selected) return true;
        const p = dirtyPayload();
        if (p && isBlocked(selected.id, JSON.stringify(p))) {
            return window.confirm(t.page.confirmLeaveRejected);
        }
        return true;
    }
    // What actually changed compared to the saved state (attributes and/or geometry). null = nothing.
    function dirtyPayload(): SavePayload | null {
        if (!selected) return null;
        const p: SavePayload = {};
        if (JSON.stringify(detailValues) !== detailOriginal) p.attributes = $state.snapshot(detailValues);
        if (JSON.stringify(selected.geometry) !== geomOriginal) p.geometry = selected.geometry;
        return p.attributes !== undefined || p.geometry !== undefined ? p : null;
    }
    // Saves IMMEDIATELY when a change is completed (called from onDetailEdit = `change`/blur and from onModify).
    // No window; one "Saving…" toast across any queued saves. History is handled by merging on the server.
    function onDetailEdit() {
        flushSave();
    }
    // The indicator settles once nothing is running, nothing is pending, and either there's nothing to save or only
    // a validation-blocked payload remains. "Saved" only if a real PUT happened, otherwise quietly back to idle.
    function maybeSettleSave() {
        if (saveRunning || pending) return;
        if (selected) {
            const p = dirtyPayload();
            if (p && !isBlocked(selected.id, JSON.stringify(p))) return; // can still be saved
        }
        if (!saveActive) return;
        saveActive = false;
        if (sessionDidSave) saveToast.saved(MSG.saved);
        else saveToast.clear(); // nothing was actually saved (revert) → disappear quietly, no false "Saved"
        sessionDidSave = false;
    }
    function flushSave() {
        if (!selected) { maybeSettleSave(); return; }
        const p = dirtyPayload();
        if (!p) {
            // The record is clean again (e.g. revert of a value rejected by validation) → discard stale errors.
            if (!saveRunning && !pending) {
                detailErrors = {};
                if (lastFailed && lastFailed.id === selected.id) lastFailed = null;
            }
            maybeSettleSave();
            return;
        }
        const json = JSON.stringify(p);
        if (isBlocked(selected.id, json)) {
            // the server already rejected exactly this payload in validation → don't retry, but "Saving…" must not hang
            if (saveActive) { saveToast.failed(MSG.formHasErrors); saveActive = false; sessionDidSave = false; }
            return;
        }
        if (!saveActive) { saveActive = true; sessionDidSave = false; saveToast.begin(); } // new save = new toast (slide+fade)
        else saveToast.saving(); // continuation of the same session → morph in place
        queueSave(selected.id, json, p);
    }
    function queueSave(id: number, json: string, payload: SavePayload) {
        if (id === inFlightId && json === inFlightJson) return; // identical to the one currently being sent for the SAME record
        if (pending && pending.id === id && pending.json === json) return; // an identical one is already pending
        pending = { id, json, payload }; // newest state wins (last write)
        void runSaveLoop();
    }
    // The loop processes the queue SERIALLY – no concurrent PUTs → no reordering. Status is reported by
    // one recycled status toast (Saving… → Saved / error) – it switches in place and doesn't move the others.
    async function runSaveLoop() {
        if (saveRunning) return;
        saveRunning = true;
        try {
            while (pending) {
                const job = pending;
                pending = null;
                inFlightJson = job.json;
                inFlightId = job.id;
                try {
                    await api.updateFeature(job.id, job.payload);
                    sessionDidSave = true;
                    invalidateFeatures(); // geometry/attributes changed → discard cached viewports
                    if (lastFailed && lastFailed.id === job.id) lastFailed = null;
                    if (selected && selected.id === job.id) {
                        detailErrors = {};
                        // Baseline = what the server holds NOW (the saved payload), NOT the current value. Otherwise
                        // a revert during the in-flight request would be lost (the server would stay at the intermediate state, the client would report
                        // clean). The reconciliation below then catches up any difference with a corrective PUT.
                        if (job.payload.attributes !== undefined) detailOriginal = JSON.stringify(job.payload.attributes);
                        if (job.payload.geometry !== undefined) geomOriginal = JSON.stringify(job.payload.geometry);
                    }
                } catch (e) {
                    // A validation error (ApiError with .fields OR code 'validation' – e.g. an invalid polygon)
                    // blocks retrying the same payload. Transient (5xx / network) → persistent banner + 1 automatic retry.
                    const apiErr = e instanceof ApiError ? e : null;
                    // Validation is recognized by status 422 (codes are now granular, e.g. polygon_self_intersect).
                    const validation = !!apiErr && (!!apiErr.fields || apiErr.status === 422);
                    if (validation) lastFailed = { id: job.id, json: job.json };
                    else if (lastFailed && lastFailed.id === job.id) lastFailed = null;
                    const failMsg = validation
                        ? (apiErr!.fields ? MSG.formHasErrors : (apiErr!.message || MSG.saveError))
                        : ((e as Error).message || MSG.saveError);
                    // Save failure is reported with a RED toast (not a message in the panel).
                    saveToast.failed(failMsg);
                    saveActive = false;
                    sessionDidSave = false;
                    if (validation) {
                        // On a validation error we also highlight the affected fields (those are not "messages in the panel").
                        if (apiErr!.fields && selected && selected.id === job.id) detailErrors = mapErrors(apiErr!.fields);
                    } else {
                        // Transient error (network/server): besides the red toast, one automatic save retry.
                        scheduleSaveRetry();
                    }
                } finally {
                    inFlightJson = '';
                    inFlightId = null;
                }
            }
        } finally {
            saveRunning = false;
        }
        // Reconciliation: if the current state diverged during saving from what the server now holds (typically
        // a revert or another edit on a slow network), a corrective PUT catches up. The baseline already reflects
        // the server's truth, so dirtyPayload detects the difference; queueSave dedup prevents looping.
        const reconcile = selected ? dirtyPayload() : null;
        if (reconcile && selected && !isBlocked(selected.id, JSON.stringify(reconcile))) {
            flushSave();
            return;
        }
        maybeSettleSave();
    }
    // After a transient save error, automatically try once to resend the current state.
    function scheduleSaveRetry() {
        if (saveRetryTimer !== null) return;
        saveRetryTimer = setTimeout(() => {
            saveRetryTimer = null;
            if (selected) flushSave(); // success replaces the red error toast with "Saved"
        }, 4000);
    }
    async function deleteDetail() {
        if (!selected) return;
        if (!window.confirm(MSG.confirmDelete)) return;
        const delId = selected.id;
        if (pending && pending.id === delId) pending = null; // drop the queue for the record being deleted
        if (lastFailed && lastFailed.id === delId) lastFailed = null;
        if (saveRetryTimer !== null) { clearTimeout(saveRetryTimer); saveRetryTimer = null; }
        saveToast.clear(); saveActive = false; sessionDidSave = false; // discard in-progress save state (we're deleting)
        try {
            const res = await api.deleteFeature(delId);
            selected = null;
            invalidateFeatures(); // the deleted object must not survive in cached viewports
            // "Undo" restores the record from the 'delete' revision (author and admin) – a safeguard against mistakes.
            toast(MSG.deleted, 'ok', res.revision_id ? { label: MSG.undo, fn: () => undoDelete(delId, res.revision_id as number) } : undefined);
            if (lastBbox) loadFeatures(lastBbox);
        } catch (e) {
            toast((e as Error).message || MSG.saveError, 'error');
        }
    }
    async function undoDelete(id: number, revisionId: number) {
        try {
            await api.restoreFeature(id, revisionId);
            invalidateFeatures();
            toast(MSG.restored);
            if (lastBbox) loadFeatures(lastBbox, true);
        } catch (e) {
            toast((e as Error).message || MSG.saveError, 'error');
        }
    }

    function onModify(id: number, geom: Geometry) {
        if (!geomOk(geom)) {
            toast(MIN_GEOM_MSG, 'error');
            if (lastBbox) loadFeatures(lastBbox, true); // restore the valid shape from the server
            return;
        }
        if (!selected || selected.id !== id) return; // geometry editing is only possible on the selected object
        selected = { ...selected, geometry: geom }; // reflect into state (dirty check; the map already holds the shape)
        flushSave(); // save immediately (same as attributes); the server may merge into a single revision
    }

    // --- Ground control points (GCP) ---
    async function startGcp() {
        if (selected) closeDetail();
        if (selectedGcp) closeGcpDetail();
        if (newMode !== 'idle') cancelNewrec();
        gcpVisible = true;
        gcpDraftMap = null;
        gcpDraftRef = null;
        gcpErrors = {};
        try {
            const next = await api.gcpNextLabel();
            gcpValues = { label: next.label, description: '' };
        } catch {
            gcpValues = { label: 'GCP 1', description: '' };
        }
        gcpMode = 'map_point';
    }

    function onGcpMapPlaced(geom: Geometry) {
        gcpDraftMap = geom;
        gcpMode = 'ref_point';
    }

    function onGcpRefPlaced(geom: Geometry) {
        gcpDraftRef = geom;
        gcpMode = 'form';
    }

    function backToGcpStep1() {
        gcpDraftMap = null;
        gcpDraftRef = null;
        gcpMode = 'map_point';
    }

    function cancelGcp() {
        gcpMode = 'idle';
        gcpDraftMap = null;
        gcpDraftRef = null;
        gcpErrors = {};
    }

    function discardGcp() {
        if (!window.confirm(t.page.confirmDiscardDraft)) return;
        cancelGcp();
    }

    async function saveGcp(next = false) {
        if (!gcpDraftMap || !gcpDraftRef) return;
        if (!gcpValues.label.trim()) {
            gcpErrors = { label: t.newRec.gcpLabelRequired };
            return;
        }
        gcpErrors = {};
        gcpSaving = true;
        try {
            const res = await api.createGcp({
                label: gcpValues.label.trim(),
                description: gcpValues.description?.trim() || null,
                map_geometry: gcpDraftMap,
                ref_geometry: gcpDraftRef
            });
            gcpPairs = [...gcpPairs, res];
            toast(MSG.saved);
            if (next) {
                gcpDraftMap = null;
                gcpDraftRef = null;
                gcpErrors = {};
                try {
                    const nextLbl = await api.gcpNextLabel();
                    gcpValues = { label: nextLbl.label, description: '' };
                } catch {
                    gcpValues = { label: '', description: '' };
                }
                gcpMode = 'map_point';
            } else {
                cancelGcp();
                openGcpDetail(res);
            }
        } catch (e) {
            if (e instanceof ApiError && e.fields) {
                gcpErrors = mapErrors(e.fields);
                toast(MSG.formHasErrors, 'error');
            } else {
                toast((e as Error).message || MSG.saveError, 'error');
            }
        } finally {
            gcpSaving = false;
        }
    }

    async function openGcpDetail(pair: GcpPair) {
        if (newMode !== 'idle' || gcpMode !== 'idle') {
            toast(BUSY_MSG, 'error');
            return;
        }
        if (selected) closeDetail();
        if (selectedGcp && selectedGcp.id === pair.id) return;
        if (selectedGcp) {
            flushGcpSave();
        }
        selectedGcp = pair;
        gcpDetailValues = { label: pair.label, description: pair.description ?? '' };
        gcpDetailErrors = {};
        gcpDetailOriginal = JSON.stringify(gcpDetailValues);
        await tick();
        if (selectedGcp && selectedGcp.id === pair.id) {
            gcpDetailOriginal = JSON.stringify(gcpDetailValues);
        }
    }

    function closeGcpDetail() {
        flushGcpSave();
        selectedGcp = null;
    }

    async function flushGcpSave() {
        if (!selectedGcp) return;
        const curId = selectedGcp.id;
        if (JSON.stringify(gcpDetailValues) === gcpDetailOriginal) return;
        if (!gcpDetailValues.label.trim()) {
            gcpDetailErrors = { label: t.newRec.gcpLabelRequired };
            return;
        }
        saveToast.begin();
        try {
            const updated = await api.updateGcp(curId, {
                label: gcpDetailValues.label.trim(),
                description: gcpDetailValues.description?.trim() || null
            });
            gcpPairs = gcpPairs.map((p) => (p.id === curId ? updated : p));
            gcpDetailOriginal = JSON.stringify({ label: updated.label, description: updated.description });
            if (selectedGcp && selectedGcp.id === curId) selectedGcp = updated;
            saveToast.saved(MSG.saved);
        } catch (e) {
            saveToast.failed((e as Error).message || MSG.saveError);
        }
    }

    async function onGcpModify(id: number, type: 'map' | 'ref', coord: [number, number]) {
        try {
            const geom: Geometry = { type: 'Point', coordinates: coord };
            const payload = type === 'map' ? { map_geometry: geom } : { ref_geometry: geom };
            const updated = await api.updateGcp(id, payload);
            gcpPairs = gcpPairs.map((p) => (p.id === id ? updated : p));
            if (selectedGcp && selectedGcp.id === id) {
                selectedGcp = updated;
            }
            toast(MSG.saved);
        } catch (e) {
            toast((e as Error).message || MSG.saveError, 'error');
        }
    }

    async function deleteGcpDetail() {
        if (!selectedGcp) return;
        if (!window.confirm(MSG.confirmDelete)) return;
        const delId = selectedGcp.id;
        try {
            await api.deleteGcp(delId);
            gcpPairs = gcpPairs.filter((p) => p.id !== delId);
            selectedGcp = null;
            toast(MSG.deleted);
        } catch (e) {
            toast((e as Error).message || MSG.saveError, 'error');
        }
    }
</script>

<svelte:head><title>{appName}</title></svelte:head>

<svelte:window onkeydown={onKeydown} onbeforeunload={onBeforeUnload} onresize={evalViewport} />

{#if blocked}
    <div class="gate">
        <div class="gate-box">
            <h1>{t.page.blockedTitle}</h1>
            <p>{t.page.blockedBody}</p>
            <p>{t.page.blockedBody2}</p>
        </div>
    </div>
{:else if authGate === 'redirecting'}
    <div class="gate">
        <div class="gate-box">
            <h1>{MSG.authRedirect}</h1>
            <p>{MSG.authRedirectHint}</p>
        </div>
    </div>
{:else if authGate === 'blocked'}
    <div class="gate">
        <div class="gate-box">
            <h1>{MSG.authLoopTitle}</h1>
            <p>{MSG.authLoopBody}</p>
            <button class="retry" onclick={() => location.replace('login.php?v=' + Date.now().toString(36))}>{MSG.authLoopButton}</button>
        </div>
    </div>
{:else if loadFailed}
    <div class="gate">
        <div class="gate-box">
            <h1>{MSG.loadFailed}</h1>
            <p>{chunkFail ? MSG.loadFailedStale : MSG.loadFailedNet}</p>
            <button class="retry" onclick={freshReload}>{MSG.retry}</button>
        </div>
    </div>
{:else if tooNarrow}
    <div class="gate">
        <div class="gate-box">
            <h1>{t.page.narrowTitle}</h1>
            <p>{t.page.narrowBody}</p>
            <button class="retry" onclick={() => { narrowOverride = true; tooNarrow = false; }}>{t.page.narrowContinue}</button>
        </div>
    </div>
{:else if ready && user && mapConfig}
    <div class="app">
        <a href="#panel" class="skip-link">{t.page.skipLink}</a>
        <TopBar {user} {csrf} name={appName} subtitle={appSubtitle}
            onManageLayers={() => (showLayers = true)}
            onManageFields={() => (showFields = true)}
            onManageUsers={() => (showUsers = true)}
            onManageHistory={() => openHistory(null)} />
        <div class="workspace">
            <MapViewComp
                bind:this={mapView}
                {mapConfig} {layers} {activeLayers}
                {features} mode={newMode} {drawType} {selectedId}
                {featuresVisible} {gcpVisible}
                {gcpPairs} {gcpMode} selectedGcpId={selectedGcp ? selectedGcp.id : null}
                {gcpDraftMap} {gcpDraftRef}
                onSelect={openDetail} onDeselect={onMapDeselect} {onDrawn} {onDraftChange} {onModify} {onMoveEnd}
                onSelectGcp={openGcpDetail} {onGcpMapPlaced} {onGcpRefPlaced} {onGcpModify}
                onLayerStatus={(s: Record<string, LayerStatus>) => (layerStatus = s)} />
            <aside class="panel" id="panel" tabindex="-1" aria-label={t.page.panelAria}>
                {#if selected}
                    <!-- The selected object's detail "takes over" the panel; the tiles are hidden. -->
                    <div class="bar">
                        <button class="back" onclick={closeDetail}>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
                            {t.common.back}
                        </button>
                    </div>
                    <!-- Saves on `change` (field completed = blur/Enter, selection, toggle) – not on
                         every keystroke and not when default values are filled in on open. No time window. -->
                    <div class="scroll" onchange={onDetailEdit}>
                        <DetailCard
                            title={detailTitle} canEdit={detailCanEdit}
                            geometryType={selected.properties.meta.geometry_type} fields={detailFields}
                            bind:values={detailValues} errors={detailErrors}
                            onDelete={deleteDetail}
                            onHistory={user.role === 'admin' ? () => openHistory(selected?.id ?? null) : undefined} />
                    </div>
                {:else if selectedGcp}
                    <!-- Ground control point (GCP) detail -->
                    <div class="bar">
                        <button class="back" onclick={closeGcpDetail}>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
                            {t.common.back}
                        </button>
                    </div>
                    <div class="scroll" onchange={flushGcpSave}>
                        <GcpDetailCard
                            pair={selectedGcp}
                            canEdit={selectedGcp.can_edit}
                            bind:values={gcpDetailValues}
                            errors={gcpDetailErrors}
                            onDelete={deleteGcpDetail} />
                    </div>
                {:else}
                    {#if showWelcome}
                        <div class="welcome" role="note">
                            <div class="welcome-txt">
                                <strong>{t.page.welcomeTitle}</strong>
                                <span>{t.page.welcomeBody}</span>
                            </div>
                            <button class="welcome-x" type="button" onclick={dismissWelcome} aria-label={t.page.welcomeClose}>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                            </button>
                        </div>
                    {/if}
                    <nav class="tabs" aria-label={t.page.tabsAria}>
                        <button class="tab" class:active={activeTab === 'layers'} aria-pressed={activeTab === 'layers'} onclick={() => setTab('layers')}>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l9 5-9 5-9-5 9-5z" /><path d="M3 13l9 5 9-5" /></svg>
                            <span>{t.page.tabLayers}</span>
                        </button>
                        <button class="tab" class:active={activeTab === 'add'} aria-pressed={activeTab === 'add'} onclick={() => setTab('add')}>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                            <span>{t.page.tabAdd}</span>
                            {#if newMode !== 'idle' || gcpMode !== 'idle'}<span class="dot" title={t.page.draftDotTitle}></span>{/if}
                        </button>
                        <button class="tab" class:active={activeTab === 'records'} aria-pressed={activeTab === 'records'} onclick={() => setTab('records')}>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 6h11M9 12h11M9 18h11" /><circle cx="4.5" cy="6" r="1.2" fill="currentColor" stroke="none" /><circle cx="4.5" cy="12" r="1.2" fill="currentColor" stroke="none" /><circle cx="4.5" cy="18" r="1.2" fill="currentColor" stroke="none" /></svg>
                            <span>{t.page.tabRecords}</span>
                        </button>
                    </nav>
                    <div class="scroll">
                        {#if activeTab === 'add'}
                            <NewRecordCard
                                state={newMode} {drawType} fields={createFields}
                                bind:values={createValues} errors={createErrors}
                                saving={createSaving || gcpSaving} onStart={startDrawing} onCancel={cancelNewrec}
                                onCreate={() => saveNewrec(false)} onCreateNext={saveNewrecAndNext} onDiscard={discardNewrec}
                                gcpState={gcpMode} bind:gcpValues {gcpErrors}
                                onStartGcp={startGcp} onCancelGcp={cancelGcp}
                                onCreateGcp={() => saveGcp(false)} onCreateNextGcp={() => saveGcp(true)} onDiscardGcp={discardGcp}
                                onBackToStep1={backToGcpStep1}
                                {layers}
                                bind:userLayers
                                {layerStatus} />
                        {:else if activeTab === 'records'}
                            <RecordsCard
                                features={viewFeatures}
                                gcpPairs={viewGcpPairs}
                                typeFilter={recordsTypeFilter}
                                onTypeFilterChange={onRecordsTypeFilterChange}
                                busy={isBusy()}
                                loading={featuresLoading}
                                currentUserId={user.id}
                                onSelectFeature={selectFeatureFromList}
                                onSelectGcp={selectGcpFromList} />
                        {:else}
                            <LayersCard
                                {layers}
                                bind:userLayers
                                bind:mineOnly
                                status={layerStatus} />
                        {/if}
                    </div>
                {/if}
            </aside>
        </div>
    </div>
{:else}
    <div class="boot">
        <img src={base + '/logo.svg'} alt="" width="46" height="50" />
        <p>{MSG.loading}</p>
    </div>
{/if}

{#if showLayers}
    <LayersManager onClose={() => (showLayers = false)} onChanged={reloadLayers} />
{/if}
{#if showFields}
    <FieldsManager onClose={() => (showFields = false)} onChanged={reloadFields} />
{/if}
{#if showUsers && user}
    <UsersManager onClose={() => (showUsers = false)} currentUserId={user.id} />
{/if}
{#if showHistory && HistoryManagerComp}
    <HistoryManagerComp onClose={() => (showHistory = false)} onChanged={afterRestore} focusFeatureId={historyFocusId} />
{/if}

<Toasts />

<style>
    .app { display: flex; flex-direction: column; height: 100vh; }
    /* Skip-to-panel link for keyboard users – hidden until focused. */
    .skip-link {
        position: absolute; left: 0.5rem; top: -3rem; z-index: 100;
        background: var(--header); color: #fff; padding: 0.5rem 0.9rem;
        border-radius: var(--r-sm); transition: top 0.15s;
    }
    .skip-link:focus { top: 0.5rem; }
    .workspace { flex: 1; display: flex; min-height: 0; }
    .panel {
        width: 366px; flex-shrink: 0; display: flex; flex-direction: column;
        background: var(--surface); border-left: 1px solid var(--border-strong);
    }
    /* Panel header (tiles / "Back") is outside the scroll area → always visible. */
    .scroll { flex: 1; min-height: 0; overflow-y: auto; padding: 0; }
    :global(.workspace > .map) { flex: 1; min-width: 0; }

    /* One-time welcome for a new collector (above the tiles, outside the scroll area). */
    .welcome {
        flex-shrink: 0; display: flex; gap: 0.6rem; align-items: flex-start;
        padding: 0.8rem 0.9rem; background: var(--surface-2);
        border-bottom: 1px solid var(--border); border-left: 3px solid var(--accent);
    }
    .welcome-txt { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.82rem; line-height: 1.45; color: var(--ink); }
    .welcome-txt strong { font-size: 0.9rem; }
    .welcome-x {
        flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center;
        width: 26px; height: 26px; padding: 0; border: none; border-radius: var(--r-sm);
        background: none; color: var(--muted); cursor: pointer; transition: background 0.12s, color 0.12s;
    }
    .welcome-x:hover { background: var(--hover); color: var(--ink); }
    .welcome-x:focus-visible { outline: 3px solid var(--ring); outline-offset: 1px; }
    .welcome-x svg { width: 16px; height: 16px; }

    /* Tiles switching panel sections. */
    .tabs {
        flex-shrink: 0; display: grid; grid-template-columns: repeat(3, 1fr);
        background: var(--surface-2); border-bottom: 1px solid var(--border-strong);
    }
    .tab {
        position: relative; display: flex; flex-direction: column;
        align-items: center; justify-content: center; gap: 0.3rem;
        min-height: 62px; padding: 0.55rem 0.35rem;
        background: none; border: none; border-right: 1px solid var(--border);
        cursor: pointer; font: inherit; font-size: 0.74rem; font-weight: 600;
        line-height: 1.15; text-align: center; color: var(--muted);
        transition: background 0.12s, color 0.12s, box-shadow 0.12s;
    }
    .tab:last-child { border-right: none; }
    .tab svg { width: 19px; height: 19px; }
    .tab:hover { background: var(--hover); color: var(--ink); }
    .tab:focus-visible { outline: 3px solid var(--ring); outline-offset: -3px; z-index: 1; }
    .tab.active { background: var(--surface); color: var(--accent); box-shadow: inset 0 -2px 0 var(--accent); }
    .tab .dot {
        position: absolute; top: 7px; right: 9px;
        width: 7px; height: 7px; border-radius: 50%; background: var(--accent);
    }

    /* "Back" bar above the selected object's detail. */
    .bar { flex-shrink: 0; padding: 0.45rem 0.55rem; border-bottom: 1px solid var(--border); }
    .back {
        display: inline-flex; align-items: center; gap: 0.25rem;
        padding: 0.3rem 0.45rem 0.3rem 0.3rem; border-radius: var(--r-sm);
        background: none; border: none; cursor: pointer;
        font: inherit; font-size: 0.85rem; font-weight: 600; color: var(--muted);
        transition: background 0.12s, color 0.12s;
    }
    .back:hover { background: var(--surface-2); color: var(--ink); }
    .back:focus-visible { outline: 3px solid var(--ring); outline-offset: 1px; }
    .back svg { width: 16px; height: 16px; }

    .gate { position: fixed; inset: 0; background: #20242a; color: #e8eaed; display: flex; align-items: center; justify-content: center; padding: 1.5rem; }
    .gate-box { max-width: 460px; text-align: center; }
    .gate-box h1 { font-family: var(--font-disp); font-size: 1.5rem; margin-bottom: 0.6rem; }
    .gate-box p { line-height: 1.55; color: #aeb6bd; }
    .retry { margin-top: 1.2rem; appearance: none; border: none; border-radius: 6px; padding: 0.6rem 1.2rem; background: var(--accent); color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
    .retry:hover { background: var(--accent-600); }
    .boot { position: fixed; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.8rem; background: var(--bg); color: var(--muted); }
    .boot img { opacity: 0.92; animation: bootpulse 1.6s ease-in-out infinite; }
    @keyframes bootpulse { 0%, 100% { opacity: 0.5; } 50% { opacity: 0.95; } }
    @media (prefers-reduced-motion: reduce) { .boot img { animation: none; } }
</style>
