<script lang="ts">
    import { onMount } from 'svelte';
    import Button from './ui/Button.svelte';
    import { trapFocus } from '$lib/actions/trapFocus';
    import { fade, fly } from 'svelte/transition';
    import { cubicOut } from 'svelte/easing';
    import { reducedMotion } from '$lib/reducedMotion';
    import { api } from '$lib/api';
    import { toast } from '$lib/toast.svelte';
    import { MSG, t, DATE_LOCALE } from '$lib/i18n';
    import { relativeTime } from '$lib/feature';
    import GeometryDiffMap from './GeometryDiffMap.svelte';
    import type { ChangeEntry, FeatureRevision, Attributes, Geometry } from '$lib/types';

    let {
        onClose,
        onChanged,
        focusFeatureId = null
    }: { onClose: () => void; onChanged: (featureId: number) => void; focusFeatureId?: number | null } = $props();

    const PAGE = 50;
    const ACTION: Record<string, { label: string; cls: string }> = {
        create:  { label: t.history.action.create,  cls: 'a-create' },
        update:  { label: t.history.action.update,  cls: 'a-update' },
        delete:  { label: t.history.action.delete,  cls: 'a-delete' },
        restore: { label: t.history.action.restore, cls: 'a-restore' }
    };

    let view = $state<'list' | 'feature'>('list');
    let loading = $state(true);
    let error = $state('');

    let changes = $state<ChangeEntry[]>([]);
    let hasMore = $state(false);
    let listDirty = $state(false); // after a restore, reload the list when going back

    let featureId = $state<number | null>(null);
    let revisions = $state<FeatureRevision[]>([]);
    let restoring = $state(false);
    let openGeom = $state<number | null>(null); // id of the revision with the expanded mini-map

    // --- attribute diff (− old / + new, a change on one line old → new) ---
    type DiffRow = { key: string; kind: 'add' | 'del' | 'chg'; oldText?: string; newText?: string };

    function isEmpty(v: unknown): boolean {
        return v === null || v === undefined || v === '';
    }
    function fmtVal(v: unknown): string {
        if (isEmpty(v)) return '∅';
        if (typeof v === 'boolean') return v ? t.history.yes : t.history.no;
        // Attachments (photos/documents) are [{id,name,size}] – file names are enough in the history.
        if (Array.isArray(v)) return v.map((x) => (x && typeof x === 'object' && 'name' in x ? String((x as { name: unknown }).name) : String(x))).join('; ');
        return String(v);
    }
    function diffAttrs(cur: Attributes, prev: Attributes | null): DiffRow[] {
        const keys = Array.from(new Set([...Object.keys(prev ?? {}), ...Object.keys(cur ?? {})]));
        const rows: DiffRow[] = [];
        for (const k of keys) {
            const c = cur?.[k];
            const p = prev?.[k];
            const cE = isEmpty(c);
            const pE = isEmpty(p);
            if (cE && pE) continue; // both empty – no change
            const cT = fmtVal(c);
            const pT = fmtVal(p);
            // emptiness is judged via isEmpty (not via the formatted '∅'),
            // so an actual value of '∅' behaves correctly
            if (pE) rows.push({ key: k, kind: 'add', newText: cT });
            else if (cE) rows.push({ key: k, kind: 'del', oldText: pT });
            else if (cT !== pT) rows.push({ key: k, kind: 'chg', oldText: pT, newText: cT });
        }
        return rows;
    }
    function geomChanged(cur: Geometry, prev: Geometry | null): boolean {
        if (!prev) return false;
        return cur?.type !== prev?.type || JSON.stringify(cur?.coordinates) !== JSON.stringify(prev?.coordinates);
    }
    // The newest version (i === 0) is the current valid state → nothing to restore to.
    // Exception: if the last action was a delete, the record is now deleted and restoring brings it back (undelete).
    function canRestore(r: FeatureRevision, i: number): boolean {
        return i !== 0 || r.action === 'delete';
    }

    function nameOf(c: ChangeEntry): string {
        return c.nazev && c.nazev.trim() !== '' ? c.nazev : `${MSG.untitled} #${c.feature_id}`;
    }
    function revName(r: FeatureRevision): string {
        const n = r.attributes?.nazev;
        return typeof n === 'string' && n.trim() !== '' ? n : `${MSG.untitled}`;
    }
    function when(s: string): string {
        const ms = Date.parse(s.replace(' ', 'T'));
        return Number.isNaN(ms) ? s : relativeTime(ms);
    }
    function whenFull(s: string): string {
        const ms = Date.parse(s.replace(' ', 'T'));
        return Number.isNaN(ms) ? s : new Date(ms).toLocaleString(DATE_LOCALE);
    }

    async function loadList(more = false) {
        loading = true;
        error = '';
        try {
            const r = await api.changes(PAGE, more ? changes.length : 0);
            // de-dup when merging (a revision may have been added between pages → window shift)
            changes = more
                ? [...changes, ...r.changes.filter((n) => !changes.some((o) => o.id === n.id))]
                : r.changes;
            hasMore = r.changes.length === PAGE;
        } catch (e) {
            error = (e as Error).message || t.history.loadFailed;
        } finally {
            loading = false;
        }
    }

    async function openFeature(id: number) {
        featureId = id;
        view = 'feature';
        openGeom = null;
        loading = true;
        error = '';
        try {
            revisions = (await api.featureRevisions(id)).revisions;
        } catch (e) {
            error = (e as Error).message || t.history.revsFailed;
        } finally {
            loading = false;
        }
    }

    function backToList() {
        view = 'list';
        featureId = null;
        if (listDirty || changes.length === 0) {
            listDirty = false;
            loadList();
        }
    }

    async function restore(revId: number) {
        if (featureId === null || restoring) return;
        if (!window.confirm(t.history.confirmRestore)) return;
        restoring = true;
        try {
            await api.restoreFeature(featureId, revId);
            listDirty = true; // the change list is now stale
            const fid = featureId;
            await openFeature(fid); // reload revisions (a "restored" entry appears)
            onChanged(fid); // refresh map data + sync the open detail
            toast(MSG.restored);
        } catch (e) {
            toast((e as Error).message || t.history.restoreFailed, 'error');
        } finally {
            restoring = false;
        }
    }

    // Start: either a specific record (from the detail) or the change overview.
    onMount(() => {
        if (focusFeatureId !== null) openFeature(focusFeatureId);
        else loadList();
    });

    function onKey(e: KeyboardEvent) {
        if (e.key !== 'Escape') return;
        if (view === 'feature' && focusFeatureId === null) backToList();
        else onClose();
    }
    function onOverlay(e: MouseEvent) {
        if (e.target === e.currentTarget) onClose();
    }
</script>

<svelte:window onkeydown={onKey} />

<div class="overlay" onclick={onOverlay} role="presentation" transition:fade={{ duration: reducedMotion ? 0 : 140 }}>
    <div class="modal" role="dialog" aria-modal="true" aria-label={t.history.title} tabindex="-1" use:trapFocus in:fly={{ y: reducedMotion ? 0 : 8, duration: reducedMotion ? 0 : 200, easing: cubicOut }}>
        <header class="m-head">
            {#if view === 'feature' && focusFeatureId === null}
                <button class="back" onclick={backToList}>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
                    {t.common.back}
                </button>
            {:else}
                <h2>{t.history.title}</h2>
            {/if}
            <button class="icon-btn" onclick={onClose} title={t.common.close} aria-label={t.common.close}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
            </button>
        </header>

        <div class="m-body">
            {#if loading}
                <p class="muted">{t.common.loading}</p>
            {:else if error}
                <div class="alert alert-error" role="alert">{error}</div>
                <Button variant="ghost" small onclick={() => (view === 'feature' && featureId !== null ? openFeature(featureId) : loadList())}>{t.common.retry}</Button>
            {:else if view === 'list'}
                {#if changes.length === 0}
                    <p class="muted">{t.history.emptyList}</p>
                {:else}
                    <p class="hint">{t.history.listHint}</p>
                    <ul class="clist">
                        {#each changes as c (c.id)}
                            <li>
                                <button type="button" class="crow" onclick={() => openFeature(c.feature_id)}>
                                    <span class="dot {ACTION[c.action].cls}" aria-hidden="true"></span>
                                    <span class="cmain">
                                        <span class="cname">{nameOf(c)}{#if c.feature_deleted}<span class="del-flag">{t.history.deletedFlag}</span>{/if}</span>
                                        <span class="cverb">{ACTION[c.action].label}</span>
                                    </span>
                                    <span class="cmeta">
                                        <span class="ctime" title={whenFull(c.changed_at)}>{when(c.changed_at)}</span>
                                        {#if c.changed_by_name}<span class="cwho">{c.changed_by_name}</span>{/if}
                                    </span>
                                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
                                </button>
                            </li>
                        {/each}
                    </ul>
                    {#if hasMore}
                        <div class="more"><Button variant="ghost" small onclick={() => loadList(true)}>{t.history.loadMore}</Button></div>
                    {/if}
                {/if}
            {:else}
                <!-- revisions of a single record -->
                {#if revisions.length === 0}
                    <p class="muted">{t.history.emptyFeature}</p>
                {:else}
                    <h3 class="feat-title">{revName(revisions[0])}</h3>
                    <p class="hint">{t.history.revHint1}<span class="lg lg-del">{t.history.legendDel}</span> / <span class="lg lg-add">{t.history.legendAdd}</span>{t.history.revHint2}</p>
                    <ul class="rlist">
                        {#each revisions as r, i (r.id)}
                            {@const prev = revisions[i + 1] ?? null}
                            {@const rows = diffAttrs(r.attributes, prev?.attributes ?? null)}
                            {@const showGeom = !prev || geomChanged(r.geometry, prev.geometry)}
                            <li class="ritem">
                                <span class="dot {ACTION[r.action].cls}" aria-hidden="true"></span>
                                <div class="rhead">
                                    <span class="rverb">{ACTION[r.action].label}</span>
                                    <span class="sep">·</span>
                                    <span class="rwho">{r.changed_by_name ?? '—'}</span>
                                    <span class="sep">·</span>
                                    <span class="rwhen" title={whenFull(r.changed_at)}>{when(r.changed_at)}</span>
                                    {#if i === 0}<span class="rcurrent">{t.history.current}</span>{/if}
                                    {#if canRestore(r, i)}
                                        <span class="robnovit"><Button variant="ghost" small disabled={restoring} onclick={() => restore(r.id)}>{t.common.restore}</Button></span>
                                    {/if}
                                </div>

                                {#if r.action === 'delete'}
                                    <p class="rnote">{t.history.deletedNote}</p>
                                {:else if rows.length === 0}
                                    <p class="rnote">{prev ? t.history.noChanges : t.history.noAttrs}</p>
                                {:else}
                                    <div class="diff" role="group" aria-label={t.history.diffAria}>
                                        {#each rows as d (d.key)}
                                            {#if d.kind === 'chg'}
                                                <div class="dl dl-chg"><span class="dk">{d.key}</span><span class="dv"><span class="old">{d.oldText}</span><span class="arrow">→</span><span class="new">{d.newText}</span></span></div>
                                            {:else if d.kind === 'add'}
                                                <div class="dl dl-add"><span class="dk">{d.key}</span><span class="dv">{d.newText}</span></div>
                                            {:else}
                                                <div class="dl dl-del"><span class="dk">{d.key}</span><span class="dv">{d.oldText}</span></div>
                                            {/if}
                                        {/each}
                                    </div>
                                {/if}

                                {#if showGeom}
                                    <button type="button" class="geo-toggle" aria-expanded={openGeom === r.id} onclick={() => (openGeom = openGeom === r.id ? null : r.id)}>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 20l-5.5 2V6L9 4m0 16l6-2m-6 2V4m6 14l5.5 2V6L15 4m0 14V4m0 0L9 4" /></svg>
                                        {prev ? t.history.geomBeforeAfter : t.history.geomShow}
                                        <svg class="caret" class:open={openGeom === r.id} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
                                    </button>
                                    {#if openGeom === r.id}
                                        <div class="rgeo"><GeometryDiffMap before={prev?.geometry ?? null} after={r.geometry} /></div>
                                    {/if}
                                {/if}
                            </li>
                        {/each}
                    </ul>
                {/if}
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
        --c-restore: #2a4d8f; /* local color for "restored" – without a new global token */
        width: 100%; max-width: 600px; max-height: 92vh; display: flex; flex-direction: column;
        background: var(--surface); border-radius: var(--r); box-shadow: var(--shadow); overflow: hidden;
    }
    .m-head {
        flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;
        padding: 0.9rem 1.1rem; border-bottom: 1px solid var(--border);
    }
    .m-head h2 { font-family: var(--font-disp); font-size: 1.2rem; }
    .back {
        display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.3rem 0.45rem 0.3rem 0.3rem;
        border-radius: var(--r-sm); background: none; border: none; cursor: pointer;
        font: inherit; font-size: 0.95rem; font-weight: 600; color: var(--muted);
    }
    .back:hover { background: var(--surface-2); color: var(--ink); }
    .back svg { width: 16px; height: 16px; }
    .icon-btn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 30px; height: 30px; border-radius: var(--r-sm);
        background: transparent; color: var(--muted); border: none; cursor: pointer;
    }
    .icon-btn:hover { background: var(--surface-2); color: var(--ink); }
    .icon-btn svg { width: 17px; height: 17px; }

    .m-body { padding: 1rem 1.1rem 1.2rem; overflow-y: auto; }
    .muted { color: var(--muted); }
    .hint { margin: 0 0 0.7rem; font-size: 0.82rem; color: var(--muted); }
    .feat-title { margin: 0 0 0.2rem; font-family: var(--font-disp); font-size: 1.1rem; }

    .clist, .rlist { list-style: none; margin: 0; padding: 0; }

    /* a dot instead of a pill – the sole carrier of the action color.
       The box is as tall as a line and the circle is centered in it → it easily
       aligns vertically to the middle of the text line in both views. */
    .dot {
        display: flex; align-items: center; justify-content: center;
        width: 8px; height: 1.2rem; flex-shrink: 0;
    }
    .dot::before {
        content: ''; width: 8px; height: 8px; border-radius: 50%; background: var(--muted);
    }
    .dot.a-create::before  { background: var(--ok); }
    .dot.a-delete::before  { background: var(--accent); }
    .dot.a-restore::before { background: var(--c-restore); }

    /* === change list (table rhythm via grid) === */
    .crow {
        display: grid; grid-template-columns: 1.25rem minmax(0, 1fr) max-content 16px;
        column-gap: 0.55rem; align-items: start; width: 100%; text-align: left;
        padding: 0.5rem 0.4rem; background: none; border: none; border-top: 1px solid var(--border);
        cursor: pointer; font: inherit; color: var(--ink);
    }
    .clist li:first-child .crow { border-top: none; }
    .crow:hover { background: var(--surface-2); }
    .crow:focus-visible { outline: 3px solid var(--ring); outline-offset: -3px; }
    .chev { width: 16px; height: 16px; color: var(--muted); align-self: center; }

    .cmain { display: flex; flex-direction: column; gap: 0.05rem; min-width: 0; }
    .cname { font-weight: 600; font-size: 0.9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cverb { font-size: 0.76rem; color: var(--muted); }
    .cmeta { display: flex; flex-direction: column; gap: 0.05rem; text-align: right; white-space: nowrap; }
    .ctime { font-size: 0.78rem; color: var(--muted); font-variant-numeric: tabular-nums; }
    .cwho { font-size: 0.76rem; color: var(--muted); }

    .del-flag {
        margin-left: 0.4rem; padding: 0.02rem 0.35rem; border-radius: var(--r-sm);
        font-size: 0.66rem; font-weight: 500; color: var(--muted); background: none; border: 1px solid var(--border);
        vertical-align: 0.05em;
    }

    /* === revisions of a single record === */
    .ritem {
        display: grid; grid-template-columns: 1.25rem minmax(0, 1fr); column-gap: 0.55rem;
        padding: 0.7rem 0.2rem; border-top: 1px solid var(--border);
    }
    .ritem > * { grid-column: 2; min-width: 0; } /* min-width:0 → map/diff stretch to the cell width */
    .ritem > .dot { grid-column: 1; grid-row: 1; align-self: center; } /* center on the header row */
    .rlist li:first-child .ritem { border-top: none; }

    .rhead { display: flex; align-items: center; flex-wrap: wrap; gap: 0.3rem; }
    .rverb { font-weight: 600; font-size: 0.85rem; color: var(--ink); }
    .rwho { font-size: 0.82rem; color: var(--muted); }
    .rwhen { font-size: 0.82rem; color: var(--muted); font-variant-numeric: tabular-nums; }
    .rhead .sep { color: var(--border-strong); }
    .rcurrent {
        font-size: 0.68rem; color: var(--muted); border: 1px solid var(--border);
        border-radius: var(--r-sm); padding: 0 0.3rem; margin-left: 0.1rem;
    }
    .robnovit { margin-left: auto; }
    .rnote { margin: 0.45rem 0 0; font-size: 0.8rem; color: var(--muted); font-style: italic; }

    /* diff: thin colored rule + subtle tint, a calm "ledger" look instead of solid fills */
    .diff {
        margin-top: 0.5rem; display: flex; flex-direction: column; gap: 2px;
        font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace;
    }
    .dl {
        display: flex; gap: 0.45rem; align-items: baseline;
        padding: 0.14rem 0.5rem; font-size: 0.75rem; line-height: 1.4;
        border-left: 2px solid transparent; border-radius: 0 var(--r-sm) var(--r-sm) 0;
    }
    .dl::before { content: ''; width: 0.8em; flex-shrink: 0; font-weight: 700; opacity: 0.7; text-align: center; }
    .dl-add { background: color-mix(in srgb, var(--ok) 8%, transparent); border-left-color: var(--ok); }
    .dl-add::before { content: '+'; color: var(--ok); }
    .dl-del { background: color-mix(in srgb, var(--accent) 7%, transparent); border-left-color: var(--accent); }
    .dl-del::before { content: '−'; color: var(--accent-600); }
    .dl-chg { background: var(--surface-2); border-left-color: var(--border-strong); }
    .dk { font-weight: 600; color: var(--muted); flex-shrink: 0; }
    .dk::after { content: ':'; }
    .dv { word-break: break-word; white-space: pre-wrap; }
    .dl-add .dv { color: var(--ok); }
    .dl-del .dv { color: var(--accent-600); }
    .dv .old { color: var(--accent-600); }
    .dv .arrow { color: var(--muted); padding: 0 0.25rem; }
    .dv .new { color: var(--ok); }

    /* legend in the help text – colored text only, no fills */
    .lg { font-weight: 600; }
    .lg-del { color: var(--accent-600); }
    .lg-add { color: var(--ok); }

    /* geometry toggle as a quiet text link */
    .geo-toggle {
        display: inline-flex; align-items: center; gap: 0.35rem; margin-top: 0.45rem;
        padding: 0.25rem 0.35rem; border: none; background: none; cursor: pointer;
        font: inherit; font-size: 0.76rem; font-weight: 600; color: var(--muted);
    }
    .geo-toggle:hover { color: var(--ink); }
    .geo-toggle svg { width: 15px; height: 15px; }
    .geo-toggle .caret { transition: transform 0.15s; }
    .geo-toggle .caret.open { transform: rotate(180deg); }

    .more { display: flex; justify-content: center; margin-top: 0.8rem; }
</style>
