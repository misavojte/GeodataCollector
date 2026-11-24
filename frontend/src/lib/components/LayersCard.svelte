<script lang="ts">
    import { t } from '$lib/i18n';
    import type { LayerDef, LayerStatus, UserLayer } from '$lib/types';
    import BaseLayersList from './BaseLayersList.svelte';

    let {
        layers,
        userLayers = $bindable(),
        mineOnly = $bindable(),
        status = {}
    }: {
        layers: LayerDef[];
        userLayers: UserLayer[];
        mineOnly: boolean;
        status?: Record<string, LayerStatus>;
    } = $props();

    const onCount = $derived(userLayers.filter((u) => u.on).length);
</script>

<section class="insp">
    <h2 class="insp-eyebrow">{t.layersCard.heading}</h2>

    <!-- Base maps -->
    <h3 class="grp-title">{t.layersCard.baseHeading} <span class="count">{onCount}</span></h3>

    {#if userLayers.length === 0}
        <p class="empty">{t.layersCard.empty}</p>
    {:else}
        <p class="scope">{t.layersCard.scope}</p>
        <BaseLayersList bind:userLayers {layers} {status} />
    {/if}

    <label class="ui-check toggle"><input type="checkbox" bind:checked={mineOnly} /> {t.layersCard.mineOnly}</label>
</section>

<style>
    /* Same visual language as the Records list: count in the title, range label,
       edge-to-edge list (no frame), rows separated by a rule. A layer being enabled
       is shown only by the checkbox, with no extra row highlight. */
    .grp-title {
        font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
        color: var(--muted); margin: 0.8rem 0 0.4rem;
    }
    .count { margin-left: 0.25rem; font-variant-numeric: tabular-nums; color: var(--ink); }
    .empty { margin: 0.3rem 0 0.2rem; color: var(--ink); font-size: 0.9rem; }
    .scope { margin: 0 0 0.7rem; font-size: 0.8rem; color: var(--muted); }
    .toggle { margin-top: 0.9rem; padding-top: 0.9rem; border-top: 1px solid var(--border); }
</style>
