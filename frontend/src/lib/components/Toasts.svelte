<script lang="ts">
    import { fly, fade } from 'svelte/transition';
    import { cubicOut } from 'svelte/easing';
    import { toasts, save, dismissToast } from '$lib/toast.svelte';

    // Respect "reduce motion" – animations then have zero duration.
    const reduce = typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches;
</script>

<div class="toast-area" aria-live="polite">
    <!-- General toasts (errors, deletions, loading…) – a simple fade. -->
    {#each toasts as t (t.id)}
        <div
            class="toast {t.kind}"
            in:fade={{ duration: reduce ? 0 : 160 }}
            out:fade={{ duration: reduce ? 0 : 200 }}
        >
            <span class="ic">
                {#if t.kind === 'pending'}
                    <span class="spin"></span>
                {:else if t.kind === 'ok'}
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5" /></svg>
                {:else}
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                {/if}
            </span>
            <span class="msg">{t.msg}</span>
            {#if t.action}
                <button type="button" class="toast-action" onclick={() => { t.action?.fn(); dismissToast(t.id); }}>{t.action.label}</button>
            {/if}
        </div>
    {/each}

    <!-- Save status toast: its own slot. Every save = a new element (slide+fade in from below);
         the previous one (if still showing) fades out in the SAME spot – overlapping grid → no collision/shift. -->
    <div class="saveslot" role="status" aria-live="assertive" aria-atomic="true">
        {#if save.status}
            <div
                class="toast {save.status.kind}"
                in:fly={{ y: reduce ? 0 : 26, duration: reduce ? 0 : 300, easing: cubicOut }}
                out:fade={{ duration: reduce ? 0 : 200 }}
            >
                <span class="ic">
                    {#if save.status.kind === 'pending'}
                        <span class="spin"></span>
                    {:else if save.status.kind === 'ok'}
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5" /></svg>
                    {:else}
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                    {/if}
                </span>
                <span class="msg">{save.status.msg}</span>
            </div>
        {/if}
    </div>
</div>

<style>
    .toast-area {
        position: fixed;
        bottom: 22px;
        left: 50%;
        transform: translateX(-50%);
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        align-items: center;
        z-index: 9000;
        pointer-events: none;
    }
    /* Save slot: both the old and the new element go into ONE grid cell → they overlap (crossfade),
       so the new one arriving shifts nothing and the slot height stays constant. */
    .saveslot {
        display: grid;
        justify-items: center;
        align-items: end;
    }
    .saveslot > * {
        grid-area: 1 / 1;
    }

    .toast {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.55rem;
        background: var(--ink);
        color: #fff;
        padding: 0.65rem 1.05rem;
        border-radius: var(--r-sm);
        box-shadow: var(--shadow);
        font-size: 0.9rem;
        /* Fixed min-width: "Saving…"/"Saved." get the same width → the in-place morph doesn't re-center. */
        min-width: 9.5em;
        max-width: 80vw;
        text-align: center;
        /* smooth state transition (Saving… → Saved / error) */
        transition: background-color 0.3s ease;
    }
    .toast.ok { background: var(--ok); }
    .toast.error { background: var(--danger); }
    .toast.pending { background: var(--ink); } /* neutral until saved */

    /* Action button (e.g. "Undo"). The toast area has pointer-events:none → re-enable them here. */
    .toast-action {
        pointer-events: auto;
        margin-left: 0.4rem;
        flex-shrink: 0;
        appearance: none;
        border: 1px solid rgba(255, 255, 255, 0.5);
        background: transparent;
        color: #fff;
        font: inherit;
        font-size: 0.82rem;
        font-weight: 700;
        padding: 0.2rem 0.6rem;
        border-radius: var(--r-sm);
        cursor: pointer;
        transition: background 0.12s;
    }
    .toast-action:hover { background: rgba(255, 255, 255, 0.18); }
    .toast-action:focus-visible { outline: 2px solid #fff; outline-offset: 1px; }

    .ic {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 15px;
        height: 15px;
        flex-shrink: 0;
    }
    .ic svg { width: 15px; height: 15px; }
    .spin {
        width: 13px;
        height: 13px;
        border: 2px solid rgba(255, 255, 255, 0.35);
        border-top-color: #fff;
        border-radius: 50%;
        animation: spin 0.7s linear infinite;
    }
    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    @media (prefers-reduced-motion: reduce) {
        .spin { animation: none; }
        .toast { transition: none; }
    }
</style>
