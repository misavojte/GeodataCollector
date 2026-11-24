<script lang="ts">
    import { t } from '$lib/i18n';
    import type { LayerDef, LayerStatus, UserLayer } from '$lib/types';

    let {
        layers,
        userLayers = $bindable(),
        status = {},
        compact = false
    }: {
        layers: LayerDef[];
        userLayers: UserLayer[];
        status?: Record<string, LayerStatus>;
        compact?: boolean;
    } = $props();

    const defById = (id: string) => layers.find((l) => l.id === id);
    const pct = (u: UserLayer) => Math.round(u.opacity * 100);

    let dragIndex = $state<number | null>(null);
    let overIndex = $state<number | null>(null);

    function onDragStart(e: DragEvent, i: number) {
        dragIndex = i;
        if (e.dataTransfer) {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', String(i));
        }
    }
    function onDragOver(e: DragEvent, i: number) {
        e.preventDefault();
        overIndex = i;
    }
    function onDragEnd() {
        dragIndex = null;
        overIndex = null;
    }
    function onDrop(i: number) {
        const from = dragIndex;
        dragIndex = null;
        overIndex = null;
        if (from === null || from === i) return;
        const arr = [...userLayers];
        const [moved] = arr.splice(from, 1);
        arr.splice(i, 0, moved);
        userLayers = arr;
    }
</script>

{#snippet dot(st: LayerStatus | undefined)}
    {#if st === 'loading'}
        <span class="st st-loading" role="img" aria-label={t.layersCard.loadingAria} title={t.layersCard.loadingTitle}></span>
    {:else if st}
        <span class="st" aria-hidden="true"></span>
    {/if}
{/snippet}

<ul class="ovlist" class:compact>
    {#each userLayers as u, i (u.id)}
        {@const def = defById(u.id)}
        {#if def}
            <!-- svelte-ignore a11y_no_static_element_interactions -->
            <li
                class="ovitem"
                class:dragover={overIndex === i}
                class:dragging={dragIndex === i}
                ondragover={(e) => onDragOver(e, i)}
                ondrop={(e) => { e.preventDefault(); onDrop(i); }}
            >
                <div class="ovrow">
                    <!-- svelte-ignore a11y_no_static_element_interactions -->
                    <span class="grip" title={t.common.gripTitle} aria-hidden="true"
                        draggable="true"
                        ondragstart={(e) => onDragStart(e, i)}
                        ondragend={onDragEnd}>
                        <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
                    </span>
                    <label class="ui-check ovcheck">
                        <input type="checkbox" checked={u.on}
                            onchange={(e) => (u.on = e.currentTarget.checked)} />
                        <span class="ovtitle" title={def.title}>{def.title}</span>
                    </label>
                    {@render dot(u.on ? status[u.id] : undefined)}
                </div>
                {#if u.on}
                    <div class="ovop">
                        <input class="ui-range" type="range" min="0" max="100" step="5"
                            value={pct(u)}
                            aria-label={t.layersCard.opacityAria(def.title)}
                            aria-valuetext={pct(u) + ' %'}
                            oninput={(e) => (u.opacity = Number(e.currentTarget.value) / 100)} />
                        <span class="val">{pct(u)} %</span>
                    </div>
                {/if}
            </li>
        {/if}
    {/each}
</ul>

<style>
    .ovlist { list-style: none; margin: 0 -1rem; padding: 0; }
    .ovlist.compact { margin: 0; border: 1px solid var(--border); border-radius: var(--r-sm); background: var(--surface-2); }
    .ovlist li + li { border-top: 1px solid var(--border); }
    .ovitem { transition: background 0.12s; }
    .ovitem:hover { background: var(--surface-2); }
    .compact .ovitem:hover { background: var(--hover); }
    .ovitem.dragging { opacity: 0.4; }
    .ovitem.dragover { box-shadow: inset 0 0 0 2px var(--ring); }
    .ovrow { display: flex; align-items: center; gap: 0.6rem; padding: 0.55rem 1rem; }
    .compact .ovrow { padding: 0.4rem 0.6rem; gap: 0.45rem; }
    .grip { display: inline-flex; flex-shrink: 0; color: var(--muted); cursor: grab; }
    .grip svg { width: 16px; height: 16px; }
    .compact .grip svg { width: 14px; height: 14px; }
    .ovcheck { flex: 1; min-width: 0; gap: 0.6rem; }
    .compact .ovcheck { gap: 0.45rem; }
    .ovtitle {
        min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        font-weight: 600; font-size: 0.9rem; line-height: 1.25;
    }
    .compact .ovtitle { font-size: 0.82rem; }
    .ovop { display: flex; align-items: center; gap: 0.55rem; padding: 0 1rem 0.55rem calc(1rem + 16px + 0.6rem + 16px + 0.6rem); }
    .compact .ovop { padding: 0 0.6rem 0.4rem calc(0.6rem + 14px + 0.45rem + 16px + 0.45rem); gap: 0.45rem; }
    .val { font-size: 0.78rem; color: var(--muted); font-variant-numeric: tabular-nums; min-width: 2.8em; text-align: right; }
    .compact .val { font-size: 0.72rem; }

    .st { flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; width: 14px; height: 14px; }
    .st-loading::before {
        content: ''; box-sizing: border-box; flex: none;
        width: 12px; height: 12px; border-radius: 50%;
        border: 2px solid var(--border-strong); border-top-color: var(--accent);
        animation: stspin 0.8s linear infinite;
    }
    @keyframes stspin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) {
        .st-loading::before { animation: none; border-color: var(--accent); border-top-color: var(--border-strong); }
    }
</style>
