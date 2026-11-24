<script lang="ts">
    import type { Snippet } from 'svelte';
    import { EDIT_HINT, DELETE_HINT } from '$lib/i18n';
    import type { GeometryType } from '$lib/types';

    let {
        geometryType,
        saveTail = '',
        variant = 'accent',
        title = '',
        text = '',
        badge = '',
        shape = 'none',
        children
    }: {
        geometryType?: GeometryType;
        saveTail?: string;
        variant?: 'accent' | 'orange' | 'blue';
        title?: string;
        text?: string;
        badge?: string;
        shape?: 'circle' | 'square' | 'none';
        children?: Snippet;
    } = $props();
</script>

<div
    class="edit-hint"
    class:hint-orange={variant === 'orange'}
    class:hint-blue={variant === 'blue'}
>
    {#if badge}
        <span
            class="hint-badge"
            class:hint-badge-orange={variant === 'orange'}
            class:hint-badge-blue={variant === 'blue'}
            class:shape-square={shape === 'square'}
            aria-hidden="true"
        >
            {badge}
        </span>
    {:else if geometryType === 'point'}
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9" /><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z" /></svg>
    {:else if geometryType}
        <!-- Icon = the actual vertex marker on the map (white circle with a colored outline). -->
        <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="5" fill="#fff" stroke="currentColor" stroke-width="2.5" /></svg>
    {:else}
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10" /><line x1="12" y1="16" x2="12" y2="12" /><line x1="12" y1="8" x2="12.01" y2="8" /></svg>
    {/if}

    <div class="hint-content">
        {#if title}
            <strong class="hint-title">{title}</strong>
        {/if}
        {#if geometryType}
            <p>{EDIT_HINT[geometryType]}{saveTail ? ' ' + saveTail : ''}</p>
            {#if geometryType !== 'point'}<p class="del">{DELETE_HINT}</p>{/if}
        {:else if text}
            <p>{text}</p>
        {/if}
        {@render children?.()}
    </div>
</div>

<style>
    .hint-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 18px;
        height: 18px;
        font-size: 0.75rem;
        line-height: 1;
        color: #fff;
        background: var(--accent);
        border-radius: 50%;
        flex-shrink: 0;
        margin-top: 1px;
    }
    .hint-badge-orange { background: var(--gcp-map); }
    .hint-badge-blue { background: var(--gcp-ref); }
    .shape-square { border-radius: 3px; }

    .hint-content {
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
        min-width: 0;
        flex: 1;
    }
    .hint-title {
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--ink);
        line-height: 1.3;
    }
    .hint-content p {
        margin: 0;
        font-size: 0.79rem;
        line-height: 1.35;
        color: var(--muted);
    }
</style>
