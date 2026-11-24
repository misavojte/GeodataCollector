<script lang="ts">
    // ol.css first, app.css after it → our branded overrides of the OpenLayers controls win.
    import 'ol/ol.css';
    import '../app.css';
    import type { Snippet } from 'svelte';
    let { children }: { children: Snippet } = $props();
</script>

{@render children()}
