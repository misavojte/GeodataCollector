<script lang="ts">
    import { onMount, onDestroy } from 'svelte';
    import Map from 'ol/Map';
    import View from 'ol/View';
    import TileLayer from 'ol/layer/Tile';
    import OSM from 'ol/source/OSM';
    import VectorLayer from 'ol/layer/Vector';
    import VectorSource from 'ol/source/Vector';
    import GeoJSON from 'ol/format/GeoJSON';
    import Style from 'ol/style/Style';
    import Stroke from 'ol/style/Stroke';
    import Fill from 'ol/style/Fill';
    import CircleStyle from 'ol/style/Circle';
    import { defaults as defaultControls } from 'ol/control';
    import type OlFeature from 'ol/Feature';
    import { t } from '$lib/i18n';
    import type { Geometry } from '$lib/types';

    let { before = null, after }: { before?: Geometry | null; after: Geometry | null } = $props();

    let el: HTMLDivElement;
    let map: Map | null = null;
    const gj = new GeoJSON();

    const BEFORE = '#8a93a0'; // grey – state before
    const AFTER = '#d93925'; // accent – state after

    function styleFor(role: 'before' | 'after'): Style {
        const color = role === 'before' ? BEFORE : AFTER;
        return new Style({
            stroke: new Stroke({ color, width: role === 'before' ? 2 : 3, lineDash: role === 'before' ? [6, 5] : undefined }),
            fill: new Fill({ color: color + (role === 'before' ? '22' : '2e') }),
            image: new CircleStyle({
                radius: role === 'before' ? 6 : 7,
                fill: new Fill({ color }),
                stroke: new Stroke({ color: '#fff', width: 1.5 })
            })
        });
    }

    onMount(() => {
        const src = new VectorSource();
        const add = (g: Geometry | null, role: 'before' | 'after') => {
            if (!g) return;
            const f = gj.readFeature(
                { type: 'Feature', geometry: g, properties: {} },
                { dataProjection: 'EPSG:4326', featureProjection: 'EPSG:3857' }
            ) as OlFeature;
            f.setStyle(styleFor(role));
            src.addFeature(f);
        };
        add(before, 'before');
        add(after, 'after');

        map = new Map({
            target: el,
            layers: [new TileLayer({ source: new OSM() }), new VectorLayer({ source: src })],
            controls: defaultControls({
                attribution: false,
                rotate: false,
                zoomOptions: { zoomInTipLabel: t.map.zoomIn, zoomOutTipLabel: t.map.zoomOut }
            }),
            view: new View({ center: [0, 0], zoom: 2 })
        });

        // The map is created in a container that was just revealed – in onMount the layout may not
        // have its width computed yet (OL would lock in a ~zero-size canvas). Once layout
        // completes we re-measure and only then fit the view to the geometry.
        const fitView = () => {
            if (!map) return;
            map.updateSize();
            const ext = src.getExtent();
            if (ext && Number.isFinite(ext[0]) && Number.isFinite(ext[2])) {
                map.getView().fit(ext, { padding: [28, 28, 28, 28], maxZoom: 17 });
            }
        };
        fitView();
        requestAnimationFrame(fitView);
    });

    onDestroy(() => {
        map?.setTarget(undefined);
        map?.dispose();
        map = null;
    });
</script>

<div class="gdiff">
    <div class="gmap" bind:this={el}></div>
    <div class="glegend">
        {#if before}<span class="lg lg-before">{t.diffMap.before}</span>{/if}
        <span class="lg lg-after">{before ? t.diffMap.after : t.diffMap.state}</span>
    </div>
</div>

<style>
    .gdiff {
        margin: 0.5rem 0 0.2rem;
    }
    .gmap {
        width: 100%;
        height: 220px;
        border: 1px solid var(--border);
        border-radius: var(--r-sm);
        overflow: hidden;
        background: #dde6ec;
    }
    .glegend {
        display: flex;
        gap: 0.5rem;
        margin-top: 0.4rem;
        font-size: 0.74rem;
    }
    .lg {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        color: var(--muted);
    }
    .lg::before {
        content: '';
        width: 14px;
        height: 0;
        border-top-width: 3px;
        border-top-style: solid;
    }
    .lg-before::before {
        border-top-color: #8a93a0;
        border-top-style: dashed;
    }
    .lg-after::before {
        border-top-color: #d93925;
    }
</style>
