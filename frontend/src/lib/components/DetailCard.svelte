<script lang="ts">
    import Button from './ui/Button.svelte';
    import DynamicForm from './DynamicForm.svelte';
    import EditHint from './EditHint.svelte';
    import { MSG, TYPE_LABEL, t } from '$lib/i18n';
    import type { FieldDefinition, GeometryType } from '$lib/types';

    let {
        title, canEdit, geometryType, fields,
        values = $bindable(),
        errors = {},
        onDelete, onHistory
    }: {
        title: string;
        canEdit: boolean;
        geometryType: GeometryType;
        fields: FieldDefinition[];
        values: Record<string, any>;
        errors?: Record<string, string>; // inline errors of individual fields (a failed save is reported by a red toast)
        onDelete: () => void;
        onHistory?: () => void; // admin only – opens the change history of this record
    } = $props();

    const typeLabel = $derived(TYPE_LABEL[geometryType]); // "dateline"-style meta below the serif title
</script>

<section class="insp detail">
    <div class="head">
        <h2 class="dtitle">{title}</h2>
        <span class="dtype-ico" role="img" aria-label={t.detail.typeLabel(typeLabel)} title={t.detail.typeLabel(typeLabel)}>
            {#if geometryType === 'point'}
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z" /></svg>
            {:else if geometryType === 'line'}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 18L10 8l4 5 6-9" /></svg>
            {:else}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 6-3 9H7L4 9z" /></svg>
            {/if}
        </span>
    </div>

    <div class="stack">
        {#if canEdit}
            <EditHint {geometryType} saveTail={t.detail.autoSave} />
        {:else}
            <p class="read-only-note">{MSG.readOnly}</p>
        {/if}
        <DynamicForm {fields} bind:values readonly={!canEdit} {errors} />
        {#if canEdit}
            <div class="actions">
                <Button variant="danger" onclick={onDelete}>{t.detail.deleteBtn}</Button>
            </div>
        {/if}
        {#if onHistory}
            <button type="button" class="hist-link" onclick={onHistory}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8" /><path d="M3 4v4h4" /><path d="M12 8v4l3 2" /></svg>
                {t.detail.history}
            </button>
        {/if}
    </div>
</section>

<style>
    .head { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; margin-bottom: 0.85rem; }
    .dtitle { font-family: var(--font-disp); font-size: 1.18rem; line-height: 1.25; text-wrap: balance; color: var(--ink); min-width: 0; }
    .dtype-ico { flex-shrink: 0; display: inline-flex; color: var(--muted); }
    .dtype-ico svg { width: 20px; height: 20px; }
    .stack { display: flex; flex-direction: column; gap: 0.8rem; }
    .actions { display: flex; justify-content: flex-end; }
    .hist-link {
        display: inline-flex; align-items: center; gap: 0.35rem; align-self: flex-start;
        margin-top: 0.2rem; padding: 0.25rem 0.1rem; background: none; border: none; cursor: pointer;
        font: inherit; font-size: 0.82rem; color: var(--muted);
    }
    .hist-link:hover { color: var(--accent); text-decoration: underline; }
    .hist-link svg { width: 15px; height: 15px; }
</style>
