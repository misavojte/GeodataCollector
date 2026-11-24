<script lang="ts">
    import type { Snippet } from 'svelte';

    type Variant = 'primary' | 'ghost' | 'danger' | 'text';
    let {
        variant = 'primary',
        type = 'button',
        disabled = false,
        small = false,
        big = false,
        block = false,
        title = '',
        onclick,
        children
    }: {
        variant?: Variant;
        type?: 'button' | 'submit';
        disabled?: boolean;
        small?: boolean;
        big?: boolean;
        block?: boolean;
        title?: string;
        onclick?: () => void;
        children?: Snippet;
    } = $props();
</script>

<button {type} {title} {disabled} class="btn {variant}" class:small class:big class:block onclick={onclick}>
    {@render children?.()}
</button>

<style>
    .btn {
        appearance: none;
        border: 1px solid transparent;
        border-radius: 6px;
        padding: 0.55rem 0.9rem;
        font: inherit;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        transition: background 0.12s, border-color 0.12s, color 0.12s;
    }
    .btn:disabled { opacity: 0.5; cursor: default; }
    .btn:focus-visible { outline: 3px solid var(--ring); outline-offset: 1px; }

    .primary { background: var(--accent); color: #fff; }
    .primary:hover:not(:disabled) { background: var(--accent-600); }

    .ghost { background: var(--surface-2); color: var(--ink); border-color: var(--border); }
    .ghost:hover:not(:disabled) { background: var(--hover); }

    .danger { background: transparent; color: var(--danger); border-color: var(--border-strong); }
    .danger:hover:not(:disabled) { background: var(--danger); color: #fff; border-color: var(--danger); }

    .text { background: none; border: none; color: var(--muted); padding: 0.35rem 0.3rem; }
    .text:hover:not(:disabled) { color: var(--ink); text-decoration: underline; }

    .small { padding: 0.4rem 0.8rem; font-size: 0.82rem; }
    .big { padding: 0.8rem 1rem; font-size: 1rem; }
    .block { width: 100%; }
</style>
