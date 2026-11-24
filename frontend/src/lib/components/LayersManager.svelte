<script lang="ts">
    import Button from './ui/Button.svelte';
    import { trapFocus } from '$lib/actions/trapFocus';
    import { fade, fly } from 'svelte/transition';
    import { cubicOut } from 'svelte/easing';
    import { reducedMotion } from '$lib/reducedMotion';
    import { api } from '$lib/api';
    import { toast } from '$lib/toast.svelte';
    import { t } from '$lib/i18n';
    import { get as getProjection } from 'ol/proj';
    import { registerCrs, crsToEpsg, metersPerUnit, BUILTIN_CRS } from '$lib/crs';
    import type {
        MapLayerAdmin, MapLayerInput, CrsCfg, Capabilities, WmsCaps, WmtsCaps,
        WmsCapsLayer, WmtsLayerCfg
    } from '$lib/types';

    let { onClose, onChanged }: { onClose: () => void; onChanged: () => void } = $props();

    type Form = {
        id: number | null;
        title: string;
        type: 'wms' | 'xyz' | 'wmts';
        url: string;              // GetTile (wmts) / GetMap (wms) / XYZ template
        capabilities_url: string; // GetCapabilities (wms/wmts)
        attribution: string;
        subdomains: string;       // xyz
        layer: string;            // selected layer (identifier/name)
        matrix_set: string;       // wmts
        style: string;            // wmts
        format: string;
        crs_code: string;         // selected CRS (EPSG:NNNN)
        request_encoding: 'KVP' | 'REST';
        min_zoom: number | null;
        max_zoom: number | null;
        parent_id: number | null;
        // carried over from the saved layer (when capabilities aren't reloaded during editing):
        _tileGrid: WmtsLayerCfg['tileGrid'] | null;
        _crs: CrsCfg | null;
    };

    let layers = $state<MapLayerAdmin[]>([]);
    let loading = $state(true);
    let error = $state('');

    let form = $state<Form | null>(null);
    let saving = $state(false);
    let formError = $state('');

    // GetCapabilities wizard
    let caps = $state<Capabilities | null>(null);
    let capsLoading = $state(false);
    let capsError = $state('');

    let dragIndex = $state<number | null>(null);
    let overIndex = $state<number | null>(null);

    const PIXEL_SIZE = 0.00028; // OGC standard pixel size (resolution = scaleDenom·0.00028/mpu)

    async function load() {
        loading = true;
        error = '';
        try {
            layers = (await api.mapLayers()).layers;
        } catch (e) {
            error = (e as Error).message || t.layersMgr.loadFailed;
        } finally {
            loading = false;
        }
    }
    load();

    function blank(): Form {
        return {
            id: null, title: '', type: 'wms', url: '', capabilities_url: '', attribution: '',
            subdomains: '', layer: '', matrix_set: '', style: 'default', format: 'image/png',
            crs_code: '', request_encoding: 'KVP', min_zoom: null, max_zoom: null, parent_id: null, _tileGrid: null, _crs: null
        };
    }
    function startAdd() { form = blank(); formError = ''; resetCaps(); }
    function startEdit(l: MapLayerAdmin) {
        const w = l.wmts_config;
        form = {
            id: l.id, title: l.title, type: l.type, url: l.url,
            capabilities_url: l.capabilities_url ?? '', attribution: l.attribution ?? '',
            subdomains: l.subdomains ?? '',
            layer: l.type === 'wmts' ? (w?.layer ?? l.wms_layers ?? '') : (l.wms_layers ?? ''),
            matrix_set: w?.matrixSet ?? '', style: w?.style ?? 'default',
            format: l.format ?? 'image/png',
            crs_code: l.projection ?? '',
            request_encoding: w?.requestEncoding ?? 'KVP',
            min_zoom: l.min_zoom ?? null,
            max_zoom: l.max_zoom ?? null,
            parent_id: l.parent_id ?? null,
            _tileGrid: w?.tileGrid ?? null,
            _crs: l.crs_config ?? null
        };
        formError = '';
        resetCaps();
    }
    function cancelForm() { form = null; formError = ''; resetCaps(); }
    function resetCaps() { caps = null; capsError = ''; capsLoading = false; }

    // Changing the type/URL invalidates any earlier load.
    function onTypeChange() { if (form) { form.layer = ''; form.matrix_set = ''; resetCaps(); } }
    function onUrlChange() { if (caps) resetCaps(); }

    // --- GetCapabilities: load the available layers ---
    async function loadCaps() {
        if (!form) return;
        const url = form.capabilities_url.trim();
        if (!/^https?:\/\//i.test(url)) { capsError = t.layersMgr.capsUrlInvalid; return; }
        capsLoading = true;
        capsError = '';
        caps = null;
        try {
            const service = form.type === 'wmts' ? 'WMTS' : 'WMS';
            const r = await api.layerCapabilities(url, service);
            caps = r.capabilities;
            preselect();
        } catch (e) {
            capsError = (e as Error).message || t.layersMgr.capsLoadFailed;
        } finally {
            capsLoading = false;
        }
    }

    // After loading, preselect sensible defaults (first layer + its matrix set/format/style/CRS).
    function preselect() {
        if (!form || !caps) return;
        if (caps.service === 'wmts') {
            const l = caps.layers[0];
            if (l) { form.layer = l.identifier; onPickWmtsLayer(); }
        } else {
            const l = caps.layers[0];
            if (l) { form.layer = l.name; onPickWmsLayer(); }
        }
    }

    function wmtsLayer() {
        return caps?.service === 'wmts' ? caps.layers.find((l) => l.identifier === form?.layer) : undefined;
    }
    function selectedMatrixCrs(): string | undefined {
        const f = form;
        if (!f || caps?.service !== 'wmts') return undefined;
        return caps.tileMatrixSets.find((s) => s.identifier === f.matrix_set)?.supportedCRS;
    }
    function crsToEpsgSafe(raw: string | undefined): string {
        if (!raw) return '';
        try { return ' → ' + crsToEpsg(raw); } catch { return ''; }
    }
    function onPickWmtsLayer() {
        const l = wmtsLayer();
        if (!l || !form) return;
        form.matrix_set = l.matrixSets[0] ?? '';
        form.style = l.defaultStyle || l.styles[0] || 'default';
        form.format = l.formats.includes(form.format) ? form.format : (l.formats[0] ?? 'image/png');
    }

    function wmsLayerObj(): WmsCapsLayer | undefined {
        return caps?.service === 'wms' ? caps.layers.find((l) => l.name === form?.layer) : undefined;
    }
    function wmsCrsOptions(l: WmsCapsLayer | undefined): string[] {
        const out = new Set<string>();
        for (const c of l?.crs ?? []) { try { out.add(crsToEpsg(c)); } catch { /* skip unknown ones */ } }
        return [...out];
    }
    function onPickWmsLayer() {
        const l = wmsLayerObj();
        if (!l || !form) return;
        const opts = wmsCrsOptions(l);
        form.crs_code = opts.includes('EPSG:3857') ? 'EPSG:3857'
            : opts.includes('EPSG:4326') ? 'EPSG:4326' : (opts[0] ?? 'EPSG:3857');
        if (caps?.service === 'wms') {
            form.format = caps.formats.includes(form.format) ? form.format
                : (caps.formats.find((f) => /png|jpe?g/i.test(f)) ?? caps.formats[0] ?? 'image/png');
        }
    }

    // Returns the proj4-registered projection object + its meters-per-unit and axis orientation.
    async function ensureProjection(code: string): Promise<{ proj4def: string | null; mpu: number; swap: boolean }> {
        let proj4def: string | null = null;
        if (!BUILTIN_CRS.has(code)) {
            const r = await api.layerCrs(code); // always load the def (required for saving)
            registerCrs({ code, proj4def: r.proj4def });
            proj4def = r.proj4def;
        }
        const p = getProjection(code);
        if (!p) throw new Error(t.layersMgr.crsRegisterFailed(code));
        const axis = (p.getAxisOrientation() || 'enu').slice(0, 2).toLowerCase();
        const mpu = p.getMetersPerUnit?.() || metersPerUnit(p.getUnits());
        return { proj4def, mpu, swap: axis === 'ne' };
    }

    const KNOWN_PH = /^\{(Style|TileMatrixSet|TileMatrix|TileRow|TileCol|Layer)\}$/i;
    const templateUsable = (t: string) => (t.match(/\{[^}]+\}/g) ?? []).every((p) => KNOWN_PH.test(p));

    function parseZoom(val: number | null | undefined): number | null {
        if (val === null || val === undefined || val === ('' as any) || Number.isNaN(Number(val))) return null;
        return Number(val);
    }

    async function buildWmts(): Promise<MapLayerInput> {
        const f = form;
        if (!f) throw new Error(t.layersMgr.missingForm);
        // Editing without reloading capabilities → use the saved grid.
        if (!(caps && caps.service === 'wmts')) {
            if (!f._tileGrid) throw new Error(t.layersMgr.loadViaCaps);
            return {
                title: f.title, type: 'wmts', url: f.url, capabilities_url: f.capabilities_url,
                layer: f.layer, matrix_set: f.matrix_set, style: f.style, format: f.format,
                projection: f.crs_code || 'EPSG:3857', crs: f._crs,
                request_encoding: f.request_encoding, tile_grid: f._tileGrid, attribution: f.attribution,
                min_zoom: parseZoom(f.min_zoom), max_zoom: parseZoom(f.max_zoom)
            };
        }
        const c = caps;
        const layer = c.layers.find((l) => l.identifier === f.layer);
        if (!layer) throw new Error(t.layersMgr.pickLayer);
        const set = c.tileMatrixSets.find((s) => s.identifier === f.matrix_set);
        if (!set || set.matrices.length === 0) throw new Error(t.layersMgr.pickMatrixSet);

        const code = crsToEpsg(set.supportedCRS);
        const { proj4def, mpu, swap } = await ensureProjection(code);
        const crs: CrsCfg | null = proj4def ? {
            code, proj4def,
            units: getProjection(code)?.getUnits(),
            axisOrientation: getProjection(code)?.getAxisOrientation()
        } : null;

        const m = set.matrices;
        const resolutions = m.map((x) => (x.scaleDenominator * PIXEL_SIZE) / mpu);
        const matrixIds = m.map((x) => x.id);
        const tw = m[0].tileWidth, th = m[0].tileHeight;
        const tileSize: number | [number, number] = tw === th ? tw : [tw, th];
        const origins = m.map((x) => {
            const t = x.topLeftCorner ?? [0, 0];
            return (swap ? [t[1], t[0]] : [t[0], t[1]]) as [number, number];
        });
        const uniform = origins.every((o) => o[0] === origins[0][0] && o[1] === origins[0][1]);
        const tile_grid: WmtsLayerCfg['tileGrid'] = {
            matrixIds, resolutions, tileSize,
            ...(uniform ? { origin: origins[0] } : { origins })
        };

        // How tiles are fetched: REST (per-layer template) preferred, otherwise KVP.
        const rt = layer.resourceTemplates.find((r) => r.format === f.format && templateUsable(r.template))
            ?? layer.resourceTemplates.find((r) => templateUsable(r.template));
        let request_encoding: 'KVP' | 'REST';
        let url: string;
        if (rt) { request_encoding = 'REST'; url = rt.template; }
        else if (c.kvpGetTile) { request_encoding = 'KVP'; url = c.kvpGetTile; }
        else throw new Error(t.layersMgr.noTemplate);

        return {
            title: f.title, type: 'wmts', url, capabilities_url: f.capabilities_url,
            layer: f.layer, matrix_set: f.matrix_set, style: f.style, format: f.format,
            projection: code, crs, request_encoding, tile_grid, attribution: f.attribution,
            min_zoom: parseZoom(f.min_zoom), max_zoom: parseZoom(f.max_zoom)
        };
    }

    async function buildWms(): Promise<MapLayerInput> {
        const f = form;
        if (!f) throw new Error(t.layersMgr.missingForm);
        const code = f.crs_code || 'EPSG:3857';
        let crs: CrsCfg | null = f._crs;
        if (!BUILTIN_CRS.has(code)) {
            const { proj4def } = await ensureProjection(code);
            if (proj4def) {
                crs = { code, proj4def, units: getProjection(code)?.getUnits(), axisOrientation: getProjection(code)?.getAxisOrientation() };
            }
        } else {
            crs = null;
        }
        // GetMap URL: from capabilities, otherwise the entered service address.
        const getMap = caps?.service === 'wms' ? caps.getMapUrl : null;
        const url = getMap || f.url || f.capabilities_url.split('?')[0];
        return {
            title: f.title, type: 'wms', url, capabilities_url: f.capabilities_url,
            wms_layers: f.layer, format: f.format || 'image/png',
            projection: code, crs, attribution: f.attribution,
            min_zoom: parseZoom(f.min_zoom), max_zoom: parseZoom(f.max_zoom)
        };
    }

    function buildXyz(): MapLayerInput {
        const f = form!;
        return {
            title: f.title, type: 'xyz', url: f.url,
            subdomains: f.subdomains, attribution: f.attribution,
            min_zoom: parseZoom(f.min_zoom), max_zoom: parseZoom(f.max_zoom)
        };
    }

    // Can it be saved? (hard gate: wms/wmts require a selected layer)
    const canSave = $derived.by(() => {
        if (!form) return false;
        if (!form.title.trim()) return false;
        if (form.type === 'xyz') return form.url.trim() !== '';
        if (!form.layer) return false;
        if (form.type === 'wmts') return form.matrix_set !== '' && (caps?.service === 'wmts' || !!form._tileGrid);
        return form.crs_code !== '';
    });

    async function save() {
        if (!form || !canSave) return;
        saving = true;
        formError = '';
        try {
            const body = form.type === 'wmts' ? await buildWmts()
                : form.type === 'wms' ? await buildWms() : buildXyz();
            body.parent_id = form.parent_id ? Number(form.parent_id) : null;
            if (form.id === null) await api.createLayer(body);
            else await api.updateLayer(form.id, body);
            form = null;
            resetCaps();
            await load();
            onChanged();
            toast(t.save.saved);
        } catch (e) {
            formError = (e as Error).message || t.save.failed;
        } finally {
            saving = false;
        }
    }

    async function remove(l: MapLayerAdmin) {
        if (!window.confirm(t.layersMgr.confirmDelete(l.title))) return;
        try {
            await api.deleteLayer(l.id);
            await load();
            onChanged();
            toast(t.layersMgr.deleted);
        } catch (e) {
            toast((e as Error).message || t.layersMgr.deleteFailed, 'error');
        }
    }

    // --- drag-and-drop reordering (first in order = default base map) ---
    function onDragStart(i: number) { dragIndex = i; }
    function onDragOver(e: DragEvent, i: number) { e.preventDefault(); overIndex = i; }
    function onDragEnd() { dragIndex = null; overIndex = null; }
    async function onDrop(i: number) {
        const from = dragIndex;
        dragIndex = null;
        overIndex = null;
        if (from === null || from === i) return;
        const arr = [...layers];
        const [moved] = arr.splice(from, 1);
        arr.splice(i, 0, moved);
        layers = arr;
        try {
            await api.reorderLayers(arr.map((l) => l.id));
            onChanged();
        } catch (e) {
            toast((e as Error).message || t.common.reorderFailed, 'error');
            await load();
        }
    }

    function onKey(e: KeyboardEvent) {
        if (e.key !== 'Escape') return;
        if (form) cancelForm();
        else onClose();
    }
    function onOverlay(e: MouseEvent) {
        if (e.target === e.currentTarget) onClose();
    }
</script>

<svelte:window onkeydown={onKey} />

<div class="overlay" onclick={onOverlay} role="presentation" transition:fade={{ duration: reducedMotion ? 0 : 140 }}>
    <div class="modal" role="dialog" aria-modal="true" aria-label={t.layersMgr.title} tabindex="-1" use:trapFocus in:fly={{ y: reducedMotion ? 0 : 8, duration: reducedMotion ? 0 : 200, easing: cubicOut }}>
        <header class="m-head">
            <h2>{t.layersMgr.title}</h2>
            <button class="icon-btn" onclick={onClose} title={t.common.close} aria-label={t.common.close}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
            </button>
        </header>

        <div class="m-body">
            {#if loading}
                <p class="muted">{t.common.loading}</p>
            {:else if error}
                <div class="alert alert-error" role="alert">{error}</div>
                <Button variant="ghost" small onclick={load}>{t.common.retry}</Button>
            {:else if form}
                <div class="form">
                    {#if formError}<div class="alert alert-error" role="alert">{formError}</div>{/if}

                    <div class="row">
                        <label class="field-label" for="l-title">{t.layersMgr.name} <span class="req">*</span></label>
                        <input id="l-title" class="ui-input" type="text" maxlength="190" bind:value={form.title} />
                    </div>

                    <div class="row">
                        <label class="field-label" for="l-type">{t.layersMgr.serviceType}</label>
                        <select id="l-type" class="ui-select" bind:value={form.type} onchange={onTypeChange}>
                            <option value="wms">WMS</option>
                            <option value="wmts">WMTS</option>
                            <option value="xyz">{t.layersMgr.xyzOption}</option>
                        </select>
                    </div>

                    {#if form.type === 'xyz'}
                        <div class="row">
                            <label class="field-label" for="l-url">{t.layersMgr.urlTemplate} <span class="req">*</span></label>
                            <input id="l-url" class="ui-input" type="text" maxlength="1024"
                                placeholder="https://server/{'{z}'}/{'{x}'}/{'{y}'}.png" bind:value={form.url} />
                            <p class="help">{t.layersMgr.urlTemplateHelp}</p>
                        </div>
                        <div class="row">
                            <label class="field-label" for="l-sub">{t.layersMgr.subdomains}</label>
                            <input id="l-sub" class="ui-input" type="text" maxlength="16" placeholder="abc" bind:value={form.subdomains} />
                        </div>
                        <div class="grid2">
                            <div class="row">
                                <label class="field-label" for="l-minzoom">{t.layersMgr.minZoom}</label>
                                <input id="l-minzoom" class="ui-input" type="number" min="0" max="28" placeholder="0" bind:value={form.min_zoom} />
                            </div>
                            <div class="row">
                                <label class="field-label" for="l-maxzoom">{t.layersMgr.maxZoom}</label>
                                <input id="l-maxzoom" class="ui-input" type="number" min="0" max="28" placeholder="19" bind:value={form.max_zoom} />
                            </div>
                        </div>
                        <p class="help">{t.layersMgr.zoomRangeHelp}</p>
                    {:else}
                        <!-- WMS / WMTS: service address + loading layers via GetCapabilities -->
                        <div class="row">
                            <label class="field-label" for="l-caps">{t.layersMgr.capsUrl} <span class="req">*</span></label>
                            <div class="cap-row">
                                <input id="l-caps" class="ui-input" type="text" maxlength="1024"
                                    placeholder={form.type === 'wmts' ? 'https://…/WMTSCapabilities.xml' : 'https://server/wms'}
                                    bind:value={form.capabilities_url} oninput={onUrlChange} />
                                <Button variant="ghost" small disabled={capsLoading} onclick={loadCaps}>
                                    {capsLoading ? t.layersMgr.capsLoadingBtn : t.layersMgr.capsLoadBtn}
                                </Button>
                            </div>
                            <p class="help">{t.layersMgr.capsHelp}</p>
                        </div>

                        {#if capsError}<div class="alert alert-error" role="alert">{capsError}</div>{/if}

                        {#if caps && caps.service === 'wms'}
                            <div class="row">
                                <label class="field-label" for="l-wlayer">{t.layersMgr.layer} <span class="req">*</span></label>
                                <select id="l-wlayer" class="ui-select" bind:value={form.layer} onchange={onPickWmsLayer}>
                                    {#each caps.layers as l (l.name)}<option value={l.name}>{l.title} ({l.name})</option>{/each}
                                </select>
                            </div>
                            <div class="grid2">
                                <div class="row">
                                    <label class="field-label" for="l-wcrs">{t.layersMgr.crs}</label>
                                    <select id="l-wcrs" class="ui-select" bind:value={form.crs_code}>
                                        {#each wmsCrsOptions(wmsLayerObj()) as c (c)}<option value={c}>{c}</option>{/each}
                                    </select>
                                </div>
                                <div class="row">
                                    <label class="field-label" for="l-wfmt">{t.layersMgr.format}</label>
                                    <select id="l-wfmt" class="ui-select" bind:value={form.format}>
                                        {#each caps.formats.filter((f) => /png|jpe?g/i.test(f)) as f (f)}<option value={f}>{f}</option>{/each}
                                    </select>
                                </div>
                            </div>
                        {:else if caps && caps.service === 'wmts'}
                            <div class="row">
                                <label class="field-label" for="l-tlayer">{t.layersMgr.layer} <span class="req">*</span></label>
                                <select id="l-tlayer" class="ui-select" bind:value={form.layer} onchange={onPickWmtsLayer}>
                                    {#each caps.layers as l (l.identifier)}<option value={l.identifier}>{l.title} ({l.identifier})</option>{/each}
                                </select>
                            </div>
                            <div class="grid2">
                                <div class="row">
                                    <label class="field-label" for="l-tset">{t.layersMgr.matrixSet}</label>
                                    <select id="l-tset" class="ui-select" bind:value={form.matrix_set}>
                                        {#each (wmtsLayer()?.matrixSets ?? []) as ms (ms)}<option value={ms}>{ms}</option>{/each}
                                    </select>
                                </div>
                                <div class="row">
                                    <label class="field-label" for="l-tstyle">{t.layersMgr.style}</label>
                                    <select id="l-tstyle" class="ui-select" bind:value={form.style}>
                                        {#each (wmtsLayer()?.styles ?? ['default']) as s (s)}<option value={s}>{s}</option>{/each}
                                    </select>
                                </div>
                            </div>
                            {#if (wmtsLayer()?.formats?.length ?? 0) > 1}
                                <div class="row">
                                    <label class="field-label" for="l-tfmt">{t.layersMgr.format}</label>
                                    <select id="l-tfmt" class="ui-select" bind:value={form.format}>
                                        {#each (wmtsLayer()?.formats ?? []) as f (f)}<option value={f}>{f}</option>{/each}
                                    </select>
                                </div>
                            {/if}
                            {#if form.matrix_set}
                                {@const crsRaw = selectedMatrixCrs()}
                                <p class="help">{t.layersMgr.crsOfSet}<strong>{crsRaw}</strong>{crsToEpsgSafe(crsRaw)}{t.layersMgr.crsReproject}</p>
                            {/if}
                        {:else if form.id !== null && (form.type === 'wmts' || form.type === 'wms')}
                            <p class="hint">{t.layersMgr.editingSaved1}<strong>{form.layer || '—'}</strong>{t.layersMgr.editingSaved2}</p>
                        {/if}
                    {/if}

                    <div class="row">
                        <label class="field-label" for="l-parent">{t.ui.parentLayer}</label>
                        <select id="l-parent" class="ui-select" bind:value={form.parent_id}>
                            <option value={null}>{t.ui.noParentLayer}</option>
                            {#each layers.filter((other) => other.id !== form?.id && !other.parent_id) as p (p.id)}
                                <option value={p.id}>{p.title}</option>
                            {/each}
                        </select>
                        <p class="help">{t.ui.parentLayerHelp}</p>
                    </div>

                    <div class="row">
                        <label class="field-label" for="l-attr">{t.layersMgr.attribution}</label>
                        <input id="l-attr" class="ui-input" type="text" maxlength="255" bind:value={form.attribution} />
                    </div>

                    <div class="form-actions">
                        <Button variant="primary" disabled={saving || !canSave} onclick={save}>{form.id === null ? t.common.add : t.common.save}</Button>
                        <Button variant="ghost" onclick={cancelForm}>{t.common.cancel}</Button>
                    </div>
                </div>
            {:else}
                {#if layers.length === 0}
                    <p class="muted">{t.layersMgr.emptyDb}</p>
                {:else}
                    <p class="hint">{t.layersMgr.orderHint1}<strong>{t.layersMgr.orderHintStrong}</strong>{t.layersMgr.orderHint2}</p>
                    <ul class="llist">
                        {#each layers as l, i (l.id)}
                            <!-- svelte-ignore a11y_no_static_element_interactions -->
                            <li
                                class="litem"
                                class:dragover={overIndex === i}
                                class:dragging={dragIndex === i}
                                draggable="true"
                                ondragstart={() => onDragStart(i)}
                                ondragover={(e) => onDragOver(e, i)}
                                ondrop={() => onDrop(i)}
                                ondragend={onDragEnd}
                            >
                                <span class="grip" title={t.common.gripTitle} aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
                                </span>
                                <span class="badge">{l.type.toUpperCase()}</span>
                                <span class="ltitle">
                                    {l.title}
                                    {#if l.parent_id}
                                        {@const parent = layers.find((p) => p.id === l.parent_id)}
                                        <span class="sub-badge" title="Podvrstva vrstvy {parent?.title ?? l.parent_id}">↳ {parent?.title ?? 'podvrstva'}</span>
                                    {:else if i === 0}
                                        <em class="def">{t.layersMgr.defaultBase}</em>
                                    {/if}
                                </span>
                                <span class="lactions">
                                    <Button variant="ghost" small onclick={() => startEdit(l)}>{t.common.edit}</Button>
                                    <Button variant="danger" small onclick={() => remove(l)}>{t.layersMgr.deleteBtn}</Button>
                                </span>
                            </li>
                        {/each}
                    </ul>
                {/if}
                <div class="add-row">
                    <Button variant="primary" onclick={startAdd}>{t.layersMgr.addLayer}</Button>
                </div>
            {/if}
        </div>
    </div>
</div>

<style>
    .overlay {
        position: fixed; inset: 0; z-index: 2000;
        background: rgba(20, 28, 36, 0.45);
        display: flex; align-items: flex-start; justify-content: center;
        padding: 4vh 1rem;
    }
    .modal {
        width: 100%; max-width: 560px; max-height: 92vh; display: flex; flex-direction: column;
        background: var(--surface); border-radius: var(--r); box-shadow: var(--shadow); overflow: hidden;
    }
    .m-head {
        flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;
        padding: 0.9rem 1.1rem; border-bottom: 1px solid var(--border);
    }
    .m-head h2 { font-family: var(--font-disp); font-size: 1.2rem; }
    .icon-btn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 30px; height: 30px; border-radius: var(--r-sm);
        background: transparent; color: var(--muted); border: none; cursor: pointer;
    }
    .icon-btn:hover { background: var(--surface-2); color: var(--ink); }
    .icon-btn:focus-visible { outline: 3px solid var(--ring); outline-offset: 1px; }
    .icon-btn svg { width: 17px; height: 17px; }

    .m-body { padding: 1rem 1.1rem 1.2rem; overflow-y: auto; }
    .muted { color: var(--muted); }
    .hint { margin: 0 0 0.8rem; font-size: 0.82rem; color: var(--muted); }

    .llist { list-style: none; margin: 0 0 1rem; padding: 0; }
    .litem {
        display: flex; align-items: center; gap: 0.55rem;
        padding: 0.55rem 0.5rem; border: 1px solid var(--border); border-radius: var(--r-sm);
        margin-bottom: 0.4rem; background: var(--surface);
    }
    .litem.dragging { opacity: 0.4; }
    .litem.dragover { border-color: var(--accent); box-shadow: 0 0 0 2px var(--ring); }
    .grip { display: inline-flex; color: var(--muted); cursor: grab; flex-shrink: 0; }
    .grip svg { width: 16px; height: 16px; }
    .badge {
        flex-shrink: 0; padding: 0.1rem 0.45rem; border-radius: 999px;
        font-size: 0.68rem; font-weight: 600; letter-spacing: 0.03em;
        color: var(--muted); background: var(--surface-2); border: 1px solid var(--border);
    }
    .ltitle { flex: 1; min-width: 0; font-weight: 600; font-size: 0.92rem; overflow: hidden; text-overflow: ellipsis; }
    .ltitle .def { margin-left: 0.4rem; font-style: normal; font-size: 0.72rem; font-weight: 600; color: var(--ok); }
    .ltitle .sub-badge { margin-left: 0.4rem; font-size: 0.72rem; font-weight: 500; color: var(--muted); background: var(--surface-2); padding: 0.1rem 0.4rem; border-radius: var(--r-sm); border: 1px solid var(--border); }
    .lactions { flex-shrink: 0; display: flex; gap: 0.4rem; }

    .form { display: flex; flex-direction: column; gap: 0.85rem; }
    .row { display: flex; flex-direction: column; gap: 0.3rem; }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
    .cap-row { display: flex; gap: 0.5rem; align-items: stretch; }
    .cap-row .ui-input { flex: 1; min-width: 0; }
    .req { color: var(--accent); }
    .help { font-size: 0.78rem; color: var(--muted); margin: 0; }
    .form-actions { display: flex; gap: 0.6rem; margin-top: 0.3rem; }
    .add-row { display: flex; }
</style>
