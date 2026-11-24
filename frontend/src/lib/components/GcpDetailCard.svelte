<script lang="ts">
    import Button from './ui/Button.svelte';
    import EditHint from './EditHint.svelte';
    import { MSG, t } from '$lib/i18n';
    import type { GcpPair } from '$lib/types';

    let {
        pair,
        canEdit,
        values = $bindable(),
        errors = {},
        onDelete
    }: {
        pair: GcpPair;
        canEdit: boolean;
        values: { label: string; description: string | null };
        errors?: Record<string, string>;
        onDelete: () => void;
    } = $props();

    function fmtCoord(pt?: { lon: number; lat: number } | null): string {
        if (!pt) return '—';
        return `${pt.lat.toFixed(5)}, ${pt.lon.toFixed(5)}`;
    }
</script>

<section class="insp detail">
    <div class="head">
        <h2 class="dtitle">{values.label || pair.label}</h2>
        <span class="dtype-ico" role="img" aria-label={t.detail.gcpDetailTitle} title={t.detail.gcpDetailTitle}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="6" cy="12" r="3" />
                <circle cx="18" cy="12" r="3" />
                <line x1="9" y1="12" x2="15" y2="12" stroke-dasharray="2 2" />
            </svg>
        </span>
    </div>

    <div class="stack">
        {#if canEdit}
            <EditHint variant="blue" text="{t.ui.gcpDragHint} {t.detail.autoSave}" />
        {:else}
            <p class="read-only-note">{MSG.readOnly}</p>
        {/if}

        <div class="form-row">
            <label for="gcp-detail-label">{t.newRec.gcpLabel} <span class="req" aria-label={t.ui.required}>*</span></label>
            <input
                id="gcp-detail-label"
                class="ui-input"
                class:has-err={!!errors.label}
                type="text"
                bind:value={values.label}
                disabled={!canEdit}
                required
            />
            {#if errors.label}<span class="field-err">{errors.label}</span>{/if}
        </div>

        <div class="form-row">
            <label for="gcp-detail-desc">{t.newRec.gcpDesc}</label>
            <textarea
                id="gcp-detail-desc"
                class="ui-input"
                rows="4"
                bind:value={values.description}
                disabled={!canEdit}
                placeholder={t.newRec.gcpDescPlaceholder}
            ></textarea>
        </div>

        <div class="coord-box">
            <div class="coord-item">
                <span class="c-badge badge-map" aria-hidden="true">●</span>
                <div class="c-info">
                    <span class="c-label">{t.detail.gcpMapCoord}</span>
                    <span class="c-val">{fmtCoord(pair.map_point)}</span>
                </div>
            </div>
            <div class="coord-item">
                <span class="c-badge badge-ref" aria-hidden="true">■</span>
                <div class="c-info">
                    <span class="c-label">{t.detail.gcpRefCoord}</span>
                    <span class="c-val">{fmtCoord(pair.ref_point)}</span>
                </div>
            </div>
        </div>

        {#if canEdit}
            <div class="actions">
                <Button variant="danger" onclick={onDelete}>{t.detail.deleteBtn}</Button>
            </div>
        {/if}
    </div>
</section>

<style>
    .head { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; margin-bottom: 0.85rem; }
    .dtitle { font-family: var(--font-disp); font-size: 1.18rem; line-height: 1.25; text-wrap: balance; color: var(--ink); min-width: 0; }
    .dtype-ico { flex-shrink: 0; display: inline-flex; color: var(--gcp-ref); }
    .dtype-ico svg { width: 22px; height: 22px; }

    .stack { display: flex; flex-direction: column; gap: 0.8rem; }
    .read-only-note { margin: 0; font-size: 0.82rem; color: var(--muted); font-style: italic; }

    .form-row { display: flex; flex-direction: column; gap: 0.25rem; }
    .form-row label { font-size: 0.82rem; font-weight: 600; color: var(--ink); }
    .req { color: var(--accent); }
    .has-err { border-color: var(--accent) !important; }
    .field-err { font-size: 0.75rem; color: var(--accent); }

    .coord-box {
        display: flex; flex-direction: column; gap: 0.5rem;
        padding: 0.65rem 0.8rem; background: var(--surface-2); border: 1px solid var(--border);
        border-radius: var(--r-sm); margin-top: 0.2rem;
    }
    .coord-item { display: flex; align-items: center; gap: 0.6rem; }
    .c-badge {
        font-size: 0.85rem; line-height: 1; width: 22px; height: 22px; display: inline-flex;
        align-items: center; justify-content: center;
        color: #fff; flex-shrink: 0;
    }
    .badge-map { background: var(--gcp-map); border-radius: 50%; }
    .badge-ref { background: var(--gcp-ref); border-radius: 3px; }
    .c-info { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; }
    .c-label { font-size: 0.72rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; }
    .c-val { font-size: 0.82rem; font-variant-numeric: tabular-nums; color: var(--ink); }

    .actions { display: flex; justify-content: flex-end; margin-top: 0.4rem; }
</style>
