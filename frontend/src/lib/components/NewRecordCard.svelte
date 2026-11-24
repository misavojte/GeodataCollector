<script lang="ts">
    import Button from './ui/Button.svelte';
    import Stepper, { type StepItem } from './ui/Stepper.svelte';
    import DynamicForm from './DynamicForm.svelte';
    import BaseLayersList from './BaseLayersList.svelte';
    import { GUIDE, t } from '$lib/i18n';
    import type { FieldDefinition, GeometryType, LayerDef, LayerStatus, UserLayer } from '$lib/types';

    let {
        state, drawType, fields,
        values = $bindable(),
        errors = {}, saving = false,
        onStart, onCancel, onCreate, onCreateNext, onDiscard,
        gcpState = 'idle',
        gcpValues = $bindable({ label: '', description: '' }),
        gcpErrors = {},
        onStartGcp,
        onCancelGcp,
        onCreateGcp,
        onCreateNextGcp,
        onDiscardGcp,
        onBackToStep1,
        layers = [],
        userLayers = $bindable([]),
        layerStatus = {}
    }: {
        state: 'idle' | 'drawing' | 'form';
        drawType: GeometryType | null;
        fields: FieldDefinition[];
        values: Record<string, any>;
        errors?: Record<string, string>;
        saving?: boolean;
        onStart: (t: GeometryType) => void;
        onCancel: () => void;
        onCreate: () => void;       // just create (stay on the record)
        onCreateNext: () => void;   // create and immediately draw the next one (bulk entry)
        onDiscard: () => void;
        gcpState?: 'idle' | 'map_point' | 'ref_point' | 'form';
        gcpValues?: { label: string; description?: string | null };
        gcpErrors?: Record<string, string>;
        onStartGcp?: () => void;
        onCancelGcp?: () => void;
        onCreateGcp?: () => void;
        onCreateNextGcp?: () => void;
        onDiscardGcp?: () => void;
        onBackToStep1?: () => void;
        layers?: LayerDef[];
        userLayers?: UserLayer[];
        layerStatus?: Record<string, LayerStatus>;
    } = $props();

    const featureSteps: StepItem[] = $derived([
        { label: t.newRec.stepDrawing },
        { label: t.newRec.stepForm }
    ]);

    const gcpSteps: StepItem[] = $derived([
        { label: t.newRec.gcpStep1Header },
        { label: t.newRec.gcpStep2Header },
        { label: t.newRec.gcpStep3Header }
    ]);

    const gcpCurrentIdx = $derived(
        gcpState === 'map_point' ? 0 : gcpState === 'ref_point' ? 1 : gcpState === 'form' ? 2 : 0
    );

    const currentHeading = $derived.by(() => {
        if (state !== 'idle' && drawType) {
            if (drawType === 'point') return t.newRec.newPoint;
            if (drawType === 'line') return t.newRec.newLine;
            if (drawType === 'polygon') return t.newRec.newPolygon;
        }
        if (gcpState !== 'idle') {
            return t.newRec.newGcp;
        }
        return t.newRec.heading;
    });

    // When the form opens, move the cursor to the first field (name) – smooth drawing→typing.
    function focusFirstInput(node: HTMLElement) {
        (node.querySelector('input, textarea, select') as HTMLElement | null)?.focus();
    }
</script>

<section class="insp">
    <h2 class="insp-eyebrow">{currentHeading}</h2>

    {#if state === 'idle' && gcpState === 'idle'}
        <p class="sub">{t.newRec.pickType}</p>
        <div class="tools" role="group" aria-label={t.newRec.typeGroupAria}>
            <button class="tile tool" onclick={() => onStart('point')}>
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z" /></svg>
                <span>{t.newRec.point}</span>
            </button>
            <button class="tile tool" onclick={() => onStart('line')}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 18L10 8l4 5 6-9" /><circle cx="4" cy="18" r="1.6" fill="currentColor" stroke="none" /><circle cx="20" cy="4" r="1.6" fill="currentColor" stroke="none" /></svg>
                <span>{t.newRec.line}</span>
            </button>
            <button class="tile tool" onclick={() => onStart('polygon')}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 6-3 9H7L4 9z" /></svg>
                <span>{t.newRec.polygon}</span>
            </button>
        </div>

        <div class="divider" aria-hidden="true">
            <span class="divider-line"></span>
        </div>

        <p class="gcp-sub">{t.newRec.gcpDividerText}</p>
        <button class="tile gcp-btn" type="button" onclick={onStartGcp}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="6" cy="12" r="3" />
                <circle cx="18" cy="12" r="3" />
                <line x1="9" y1="12" x2="15" y2="12" stroke-dasharray="2 2" />
            </svg>
            <span>{t.newRec.gcpBtn}</span>
        </button>

    {:else if state !== 'idle'}
        <!-- Wizard for regular features (point / line / polygon) -->
        <Stepper steps={featureSteps} current={state === 'drawing' ? 0 : 1} ariaLabel={t.ui.featureSteps} />

        {#if state === 'drawing' && drawType}
            <div class="stack">
                <ul class="guide-list">
                    {#each GUIDE[drawType] as item}
                        <li>{item}</li>
                    {/each}
                </ul>
                <div class="actions"><Button variant="ghost" small onclick={onCancel}>{t.common.cancel}</Button></div>
            </div>
        {:else if state === 'form' && drawType}
            <!-- Enter in a field submits "Create and continue" (type=submit); Enter in a textarea does not submit. -->
            <form class="stack" use:focusFirstInput onsubmit={(e) => { e.preventDefault(); onCreateNext(); }}>
                <DynamicForm {fields} bind:values {errors} />
                <Button variant="primary" big block type="submit" disabled={saving}>
                    {saving ? t.save.saving : t.newRec.createNext}
                </Button>
                <div class="sub-actions">
                    <Button variant="ghost" small disabled={saving} onclick={onCreate}>{t.newRec.createOnly}</Button>
                    <Button variant="danger" small onclick={onDiscard}>{t.newRec.discard}</Button>
                </div>
            </form>
        {/if}

    {:else if gcpState !== 'idle'}
        <!-- Georeferencing wizard (3 steps) -->
        <Stepper steps={gcpSteps} current={gcpCurrentIdx} ariaLabel={t.ui.gcpSteps} />

        {#if gcpState === 'map_point'}
            <div class="stack">
                <ul class="guide-list">
                    <li>{t.newRec.gcpStep1Desc}</li>
                    {#if userLayers.length > 0}
                        <li>{t.newRec.gcpStep1LayerHint}</li>
                    {/if}
                </ul>

                {#if userLayers.length > 0}
                    <BaseLayersList bind:userLayers {layers} status={layerStatus} compact={true} />
                {/if}

                <div class="actions">
                    <Button variant="ghost" small onclick={onCancelGcp}>{t.common.cancel}</Button>
                </div>
            </div>

        {:else if gcpState === 'ref_point'}
            <div class="stack">
                <ul class="guide-list">
                    <li>{t.newRec.gcpStep2Desc1}</li>
                    <li>{t.newRec.gcpStep2Desc2}</li>
                    {#if userLayers.length > 0}
                        <li>{t.newRec.gcpStep2LayerHint}</li>
                    {/if}
                </ul>

                {#if userLayers.length > 0}
                    <BaseLayersList bind:userLayers {layers} status={layerStatus} compact={true} />
                {/if}

                <div class="actions">
                    {#if onBackToStep1}
                        <Button variant="ghost" small onclick={onBackToStep1}>{t.newRec.gcpBackToStep1}</Button>
                    {/if}
                    <Button variant="ghost" small onclick={onCancelGcp}>{t.common.cancel}</Button>
                </div>
            </div>

        {:else if gcpState === 'form'}
            <form class="stack" use:focusFirstInput onsubmit={(e) => { e.preventDefault(); onCreateNextGcp?.(); }}>
                <ul class="guide-list">
                    <li>{t.newRec.gcpStep3Desc}</li>
                </ul>

                <div class="gcp-fields">
                    <div class="form-row">
                        <label for="gcp-label">{t.newRec.gcpLabel} <span class="req" aria-label={t.ui.required}>*</span></label>
                        <input id="gcp-label" class="ui-input" class:has-err={!!gcpErrors.label} type="text"
                            bind:value={gcpValues.label} placeholder={t.newRec.gcpLabelPlaceholder} required />
                        {#if gcpErrors.label}<span class="field-err">{gcpErrors.label}</span>{/if}
                    </div>
                    <div class="form-row">
                        <label for="gcp-desc">{t.newRec.gcpDesc}</label>
                        <textarea id="gcp-desc" class="ui-input" rows="3"
                            bind:value={gcpValues.description} placeholder={t.newRec.gcpDescPlaceholder}></textarea>
                    </div>
                </div>

                <Button variant="primary" big block type="submit" disabled={saving}>
                    {saving ? t.save.saving : t.newRec.gcpCreateNext}
                </Button>
                <div class="sub-actions">
                    <Button variant="ghost" small disabled={saving} onclick={onCreateGcp}>{t.newRec.gcpCreateOnly}</Button>
                    <Button variant="danger" small onclick={onDiscardGcp}>{t.newRec.discard}</Button>
                </div>
            </form>
        {/if}
    {/if}
</section>

<style>
    .sub { color: var(--muted); font-size: 0.85rem; margin: 0 0 0.8rem; }
    .tools { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; }

    /* Uniform tile buttons */
    .tile {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--r-sm); cursor: pointer; font: inherit; color: var(--ink);
        box-shadow: var(--shadow-sm);
        transition: background 0.12s, border-color 0.12s, box-shadow 0.12s;
    }
    .tile:hover { background: var(--surface-2); border-color: var(--border-strong); }
    .tile:focus-visible { outline: 3px solid var(--ring); outline-offset: 1px; }

    .tool {
        display: flex; flex-direction: column; align-items: center; gap: 0.35rem;
        padding: 0.75rem 0.3rem; font-size: 0.82rem; font-weight: 600;
    }
    .tool svg { width: 20px; height: 20px; color: var(--muted); }

    .divider { margin: 1rem 0 0.7rem; display: flex; align-items: center; }
    .divider-line { flex: 1; height: 1px; background: var(--border); }

    .gcp-sub { color: var(--muted); font-size: 0.8rem; margin: 0 0 0.5rem; line-height: 1.35; }
    .gcp-btn {
        display: flex; align-items: center; justify-content: center; gap: 0.6rem;
        width: 100%; padding: 0.7rem 0.8rem; font-size: 0.85rem; font-weight: 600;
    }
    .gcp-btn svg { width: 20px; height: 20px; color: var(--gcp-ref); }

    /* Stack and forms */
    .stack { display: flex; flex-direction: column; gap: 0.7rem; }
    .guide-list {
        margin: 0.15rem 0 0.25rem 1.25rem;
        padding: 0;
        font-size: 0.83rem;
        line-height: 1.5;
        color: var(--ink);
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
    }
    .guide-list li {
        padding-left: 0.15rem;
    }
    .actions { display: flex; gap: 0.5rem; align-items: center; }
    .actions :global(.btn.primary) { flex: 1; }
    .sub-actions { display: flex; gap: 0.6rem; }
    .sub-actions :global(.btn) { flex: 1; }

    /* GCP fields */
    .gcp-fields { display: flex; flex-direction: column; gap: 0.65rem; }
    .form-row { display: flex; flex-direction: column; gap: 0.25rem; }
    .form-row label { font-size: 0.82rem; font-weight: 600; color: var(--ink); }
    .req { color: var(--accent); }
    .has-err { border-color: var(--accent) !important; }
    .field-err { font-size: 0.75rem; color: var(--accent); }
</style>
