<script lang="ts">
    import Button from './ui/Button.svelte';
    import Note from './ui/Note.svelte';
    import { api } from '$lib/api';
    import { toast } from '$lib/toast.svelte';
    import { TYPE_LABEL, t } from '$lib/i18n';
    import { featureTitle, featureChangedMs, featureChangedBy, relativeTime, fullDateTime } from '$lib/feature';
    import type { ApiFeature, GcpPair } from '$lib/types';

    type ItemKind = 'feature' | 'gcp';
    interface ListItem {
        uid: string;
        kind: ItemKind;
        id: number;
        title: string;
        ms: number;
        author: string;
        canEdit: boolean;
        typeLabel: string;
        raw: ApiFeature | GcpPair;
    }

    let {
        features,
        gcpPairs = [],
        typeFilter = $bindable<'features' | 'gcp' | 'all'>('features'),
        busy = false,
        loading = false,
        currentUserId,
        onSelectFeature,
        onSelectGcp,
        onTypeFilterChange
    }: {
        features: ApiFeature[];
        gcpPairs?: GcpPair[];
        typeFilter?: 'features' | 'gcp' | 'all';
        busy?: boolean;
        loading?: boolean;
        currentUserId: number;
        onSelectFeature: (f: ApiFeature) => void;
        onSelectGcp: (g: GcpPair) => void;
        onTypeFilterChange?: (filter: 'features' | 'gcp' | 'all') => void;
    } = $props();

    const PAGE = 50;

    let mineOnly = $state(false);
    let query = $state('');
    let page = $state(1);
    let exporting = $state(false);

    // Search ignoring diacritics and letter case (Zürich ~ zurich).
    const norm = (s: string) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

    // Map features to unified items
    const featureItems = $derived<ListItem[]>(features.map((f) => ({
        uid: 'f_' + f.id,
        kind: 'feature' as const,
        id: f.id,
        title: featureTitle(f),
        ms: featureChangedMs(f),
        author: featureChangedBy(f) ?? '',
        canEdit: f.properties.meta.can_edit,
        typeLabel: TYPE_LABEL[f.properties.meta.geometry_type],
        raw: f
    })));

    // Map gcpPairs to unified items
    const gcpItems = $derived<ListItem[]>(gcpPairs.map((g) => ({
        uid: 'g_' + g.id,
        kind: 'gcp' as const,
        id: g.id,
        title: g.label,
        ms: Date.parse(g.updated_at || g.created_at) || 0,
        author: g.created_by_name ?? '',
        canEdit: g.can_edit,
        typeLabel: t.records.gcpPairBadge,
        raw: g
    })));

    // Filter by the selected tab (Features / Georeferencing / All)
    const byType = $derived<ListItem[]>(
        typeFilter === 'features' ? featureItems :
        typeFilter === 'gcp' ? gcpItems :
        [...featureItems, ...gcpItems]
    );

    // Sort newest first
    const sorted = $derived([...byType].sort((a, b) => b.ms - a.ms));

    // "Mine only" filter
    const byMine = $derived<ListItem[]>(
        mineOnly
            ? sorted.filter((item) => {
                if (item.kind === 'feature') return (item.raw as ApiFeature).properties.meta.created_by === currentUserId;
                return (item.raw as GcpPair).created_by === currentUserId;
            })
            : sorted
    );

    // Text search
    const filtered = $derived(
        query.trim() === '' ? byMine : byMine.filter((item) => norm(item.title).includes(norm(query.trim())))
    );

    const pageCount = $derived(Math.max(1, Math.ceil(filtered.length / PAGE)));
    const rows = $derived(filtered.slice((page - 1) * PAGE, page * PAGE));
    const fromIdx = $derived(filtered.length === 0 ? 0 : (page - 1) * PAGE + 1);
    const toIdx = $derived(Math.min(page * PAGE, filtered.length));

    // Changing the filter / search → back to the first page; list shrinks → clamp the page.
    $effect(() => { typeFilter; mineOnly; query; page = 1; });
    $effect(() => { if (page > pageCount) page = pageCount; });

    // Ticking "now" for relative times.
    let now = $state(Date.now());
    $effect(() => {
        const t = setInterval(() => (now = Date.now()), 30000);
        return () => clearInterval(t);
    });

    async function download() {
        if (filtered.length === 0 || exporting) return;
        exporting = true;
        try {
            if (typeFilter === 'gcp') {
                // Export GCP points to GeoJSON
                const gcpList = filtered.filter((i) => i.kind === 'gcp').map((i) => i.raw as GcpPair);
                const featuresOut: any[] = [];
                for (const g of gcpList) {
                    if (g.map_point) {
                        featuresOut.push({
                            type: 'Feature',
                            geometry: { type: 'Point', coordinates: [g.map_point.lon, g.map_point.lat] },
                            properties: { gcp_id: g.id, label: g.label, point_type: 'map', description: g.description }
                        });
                    }
                    if (g.ref_point) {
                        featuresOut.push({
                            type: 'Feature',
                            geometry: { type: 'Point', coordinates: [g.ref_point.lon, g.ref_point.lat] },
                            properties: { gcp_id: g.id, label: g.label, point_type: 'ref', description: g.description }
                        });
                    }
                }
                const blob = new Blob([JSON.stringify({ type: 'FeatureCollection', features: featuresOut }, null, 2)], { type: 'application/geo+json' });
                triggerDownload(blob, 'referencni-body.geojson');
                toast(t.records.downloaded(gcpList.length));
            } else {
                const featIds = filtered.filter((i) => i.kind === 'feature').map((i) => i.id);
                if (featIds.length > 0) {
                    const blob = await api.exportGeoJson(featIds);
                    triggerDownload(blob, t.records.fileName);
                    toast(t.records.downloaded(featIds.length));
                }
            }
        } catch (e) {
            toast((e as Error).message || t.records.downloadFailed, 'error');
        } finally {
            exporting = false;
        }
    }

    function triggerDownload(blob: Blob, fileName: string) {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = fileName;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    function setTypeFilter(val: 'features' | 'gcp' | 'all') {
        typeFilter = val;
        onTypeFilterChange?.(val);
    }
</script>

<section class="insp">
    <h2 class="insp-eyebrow">{t.records.heading} <span class="count">{filtered.length}</span></h2>

    <!-- Unified block of filters and actions for search, switching and export -->
    <div class="filter-panel">
        <!-- 3-way switch: Features | Georeferencing | All -->
        <div class="filter-switch" role="radiogroup" aria-label={t.ui.recordTypeFilter}>
            <button
                type="button"
                role="radio"
                aria-checked={typeFilter === 'features'}
                class="switch-btn"
                class:active={typeFilter === 'features'}
                onclick={() => setTypeFilter('features')}
            >{t.records.filterFeatures}</button>
            <button
                type="button"
                role="radio"
                aria-checked={typeFilter === 'gcp'}
                class="switch-btn"
                class:active={typeFilter === 'gcp'}
                onclick={() => setTypeFilter('gcp')}
            >{t.records.filterGcp}</button>
            <button
                type="button"
                role="radio"
                aria-checked={typeFilter === 'all'}
                class="switch-btn"
                class:active={typeFilter === 'all'}
                onclick={() => setTypeFilter('all')}
            >{t.records.filterAll}</button>
        </div>

        <!-- Search by name -->
        <div class="search-box">
            <svg class="search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="11" cy="11" r="8" />
                <line x1="21" y1="21" x2="16.65" y2="16.65" />
            </svg>
            <input
                class="search-input"
                type="search"
                placeholder={t.records.searchPlaceholder}
                bind:value={query}
                aria-label={t.records.searchAria}
                onkeydown={(e) => { if (e.key === 'Escape') query = ''; }}
            />
            {#if query.trim() !== ''}
                <button
                    type="button"
                    class="clear-btn"
                    onclick={() => (query = '')}
                    title={t.ui.clearSearch}
                    aria-label={t.ui.clearSearch}
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            {/if}
        </div>

        <!-- Bottom bar: checkmark and download -->
        <div class="filter-toolbar">
            <label class="ui-check filter-check">
                <input type="checkbox" bind:checked={mineOnly} />
                <span class="check-text">{t.records.mineOnly}</span>
            </label>
            <button
                type="button"
                class="export-btn"
                disabled={exporting || filtered.length === 0}
                onclick={download}
                title={filtered.length === 0 ? t.ui.noRecordsToDownload : t.ui.downloadGeojson}
            >
                {#if exporting}
                    <svg class="spin-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                        <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" stroke-linecap="round" />
                    </svg>
                    <span>{t.records.exporting}</span>
                {:else}
                    <svg class="dl-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                        <polyline points="7 10 12 15 17 10" />
                        <line x1="12" y1="15" x2="12" y2="3" />
                    </svg>
                    <span>GeoJSON</span>
                {/if}
            </button>
        </div>
    </div>

    {#if busy}
        <div class="busy-wrap">
            <Note text={t.records.busyNote} />
        </div>
    {/if}

    {#if filtered.length === 0}
        {#if loading}
            <p class="empty">{t.records.loadingList}</p>
        {:else if query.trim() !== ''}
            <p class="empty">{t.records.noMatch(query.trim())}</p>
            <p class="empty-hint">{t.records.noMatchHint}</p>
        {:else}
            <p class="empty">{mineOnly ? t.records.emptyMine : t.records.emptyAll}</p>
            <p class="empty-hint">{mineOnly ? t.records.emptyHintMine : t.records.emptyHintAll}</p>
        {/if}
    {:else}
        <p class="scope">{t.records.scope}</p>
        <ul class="list" class:busy>
            {#each rows as item (item.uid)}
                <li>
                    <button type="button" class="rec" disabled={busy}
                        onclick={() => item.kind === 'feature' ? onSelectFeature(item.raw as ApiFeature) : onSelectGcp(item.raw as GcpPair)}
                        title={fullDateTime(item.ms)}>
                        <span class="ico" class:editable={item.canEdit} class:gcp={item.kind === 'gcp'} title="{item.typeLabel} · {item.canEdit ? t.records.editable : t.records.readOnlyShort}">
                            {#if item.kind === 'gcp'}
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <circle cx="6" cy="12" r="3" />
                                    <circle cx="18" cy="12" r="3" />
                                    <line x1="9" y1="12" x2="15" y2="12" stroke-dasharray="2 2" />
                                </svg>
                            {:else if (item.raw as ApiFeature).properties.meta.geometry_type === 'point'}
                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z" /></svg>
                            {:else if (item.raw as ApiFeature).properties.meta.geometry_type === 'line'}
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 18L10 8l4 5 6-9" /></svg>
                            {:else}
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 6-3 9H7L4 9z" /></svg>
                            {/if}
                        </span>
                        <span class="txt">
                            <span class="name">{item.title}</span>
                            <span class="meta">{relativeTime(item.ms, now)}{#if item.author} · {item.author}{/if}</span>
                        </span>
                    </button>
                </li>
            {/each}
        </ul>

        {#if pageCount > 1}
            <div class="pager">
                <Button variant="ghost" small disabled={page <= 1} onclick={() => (page -= 1)}>{t.records.prev}</Button>
                <span class="pginfo">{t.records.pageInfo(fromIdx, toIdx, filtered.length)}</span>
                <Button variant="ghost" small disabled={page >= pageCount} onclick={() => (page += 1)}>{t.records.next}</Button>
            </div>
        {/if}
    {/if}
</section>

<style>
    .count { margin-left: 0.25rem; font-variant-numeric: tabular-nums; color: var(--ink); }

    /* --- Unified filter and action block --- */
    .filter-panel {
        display: flex;
        flex-direction: column;
        gap: 0.55rem;
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--r);
        padding: 0.65rem;
        margin-bottom: 0.9rem;
    }

    /* 3-way switch */
    .filter-switch {
        display: flex;
        gap: 2px;
        background: #e3e6ea;
        border-radius: var(--r-sm);
        padding: 2px;
    }
    .switch-btn {
        flex: 1 1 auto;
        min-width: max-content;
        white-space: nowrap;
        padding: 0.32rem 0.5rem;
        font: inherit;
        font-size: 0.78rem;
        font-weight: 600;
        color: var(--muted);
        background: none;
        border: none;
        border-radius: calc(var(--r-sm) - 2px);
        cursor: pointer;
        text-align: center;
        transition: background 0.12s, color 0.12s, box-shadow 0.12s;
    }
    .switch-btn.active {
        background: var(--surface);
        color: var(--ink);
        box-shadow: var(--shadow-sm);
    }
    .switch-btn:hover:not(.active) {
        color: var(--ink);
        background: rgba(255, 255, 255, 0.4);
    }
    .switch-btn:focus-visible {
        outline: 2px solid var(--ring);
    }

    /* Search field */
    .search-box {
        position: relative;
        display: flex;
        align-items: center;
        width: 100%;
    }
    .search-ico {
        position: absolute;
        left: 0.6rem;
        width: 15px;
        height: 15px;
        color: var(--muted);
        pointer-events: none;
    }
    .search-input {
        width: 100%;
        font: inherit;
        font-size: 0.82rem;
        color: var(--ink);
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r-sm);
        padding: 0.42rem 1.85rem 0.42rem 2.05rem;
        transition: border-color 0.12s, box-shadow 0.12s;
    }
    .search-input:hover {
        border-color: var(--border-strong);
    }
    .search-input:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 2.5px var(--ring);
    }
    .search-input::placeholder {
        color: var(--muted);
        opacity: 0.85;
    }
    .search-input::-webkit-search-decoration,
    .search-input::-webkit-search-cancel-button,
    .search-input::-webkit-search-results-button,
    .search-input::-webkit-search-results-decoration {
        -webkit-appearance: none;
        appearance: none;
        display: none;
    }
    .clear-btn {
        position: absolute;
        right: 0.35rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 20px;
        height: 20px;
        padding: 0;
        border: none;
        border-radius: 50%;
        background: none;
        color: var(--muted);
        cursor: pointer;
        transition: background 0.12s, color 0.12s;
    }
    .clear-btn:hover {
        background: var(--hover);
        color: var(--ink);
    }
    .clear-btn svg {
        width: 12px;
        height: 12px;
    }

    /* Bottom tool row (checkbox + download) */
    .filter-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        padding: 0 0.1rem;
    }
    .filter-check {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        font-size: 0.8rem;
        font-weight: 500;
        color: var(--ink);
        cursor: pointer;
        user-select: none;
    }
    .filter-check input {
        accent-color: var(--accent);
        width: 15px;
        height: 15px;
        margin: 0;
        cursor: pointer;
    }
    .check-text {
        white-space: nowrap;
    }

    .export-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.32rem 0.65rem;
        font: inherit;
        font-size: 0.78rem;
        font-weight: 600;
        line-height: 1.2;
        color: var(--ink);
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r-sm);
        cursor: pointer;
        white-space: nowrap;
        transition: background 0.12s, border-color 0.12s, color 0.12s, box-shadow 0.12s;
    }
    .export-btn:hover:not(:disabled) {
        background: var(--hover);
        border-color: var(--border-strong);
    }
    .export-btn:focus-visible {
        outline: 2px solid var(--ring);
        outline-offset: 1px;
    }
    .export-btn:disabled {
        opacity: 0.45;
        cursor: default;
        background: transparent;
        border-color: var(--border);
        color: var(--muted);
    }
    .export-btn .dl-ico {
        width: 14px;
        height: 14px;
        color: var(--muted);
        transition: color 0.12s;
    }
    .export-btn:hover:not(:disabled) .dl-ico {
        color: var(--ink);
    }
    .export-btn .spin-ico {
        width: 14px;
        height: 14px;
        color: var(--accent);
        animation: spin 0.8s linear infinite;
    }
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    @media (prefers-reduced-motion: reduce) {
        .export-btn .spin-ico { animation: none; }
    }

    .busy-wrap {
        margin-bottom: 0.75rem;
    }

    /* --- Record list --- */
    .scope { margin: 0 0 0.55rem; font-size: 0.78rem; color: var(--muted); }
    .empty { margin: 0.3rem 0 0.2rem; color: var(--ink); font-size: 0.9rem; }
    .empty-hint { margin: 0; color: var(--muted); font-size: 0.82rem; }

    .list {
        list-style: none;
        margin: 0 -1rem;
        padding: 0;
        border-top: 1px solid var(--border);
    }
    .list.busy { opacity: 0.55; }
    .list li { border-bottom: 1px solid var(--border); }

    .rec {
        display: flex; align-items: center; gap: 0.6rem; width: 100%;
        padding: 0.55rem 1rem; background: none; border: none; cursor: pointer;
        font: inherit; text-align: left; color: var(--ink);
        border-left: 3px solid transparent;
        transition: background 0.12s, border-color 0.12s;
    }
    .rec:not(:disabled):hover { background: var(--surface-2); border-left-color: var(--accent); }
    .rec:focus-visible { outline: 3px solid var(--ring); outline-offset: -3px; }
    .rec:disabled { cursor: default; }

    .ico { flex-shrink: 0; display: inline-flex; color: var(--muted); }
    .ico.editable { color: var(--accent); }
    .ico.gcp { color: var(--gcp-ref); }
    .ico svg { width: 18px; height: 18px; }

    .txt { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; flex: 1; }
    .name { font-weight: 600; font-size: 0.9rem; line-height: 1.25; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .meta { font-size: 0.78rem; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .pager { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-top: 0.8rem; }
    .pginfo { font-size: 0.8rem; color: var(--muted); font-variant-numeric: tabular-nums; }
</style>
