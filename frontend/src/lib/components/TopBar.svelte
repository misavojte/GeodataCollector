<script lang="ts">
    import { base } from '$app/paths';
    import { t, switchLocale } from '$lib/i18n';
    import type { User } from '$lib/types';
    let { user, csrf, name, subtitle, onManageLayers, onManageFields, onManageUsers, onManageHistory }:
        { user: User; csrf: string; name: string; subtitle: string; onManageLayers?: () => void; onManageFields?: () => void; onManageUsers?: () => void; onManageHistory?: () => void } = $props();
    const roleLabel = $derived(user.role === 'admin' ? t.topbar.admin : t.topbar.collector);

    function switchLang() {
        // Close the menu BEFORE reloading: with unsaved work the browser may show
        // a leave-page confirmation, and if that is cancelled the menu must not stay open.
        open = false;
        switchLocale();
    }

    function manageLayers() {
        open = false;
        onManageLayers?.();
    }
    function manageFields() {
        open = false;
        onManageFields?.();
    }
    function manageUsers() {
        open = false;
        onManageUsers?.();
    }
    function manageHistory() {
        open = false;
        onManageHistory?.();
    }

    let open = $state(false);
    let root: HTMLElement;

    function onWindowClick(e: MouseEvent) {
        if (open && root && !root.contains(e.target as Node)) open = false;
    }
    function onKey(e: KeyboardEvent) {
        if (e.key === 'Escape') open = false;
    }
</script>

<svelte:window onclick={onWindowClick} onkeydown={onKey} />

<header class="topbar">
    <div class="brand">
        <a class="logo" href={base + '/'} aria-label={t.topbar.home}>
            <img src={base + '/logo.svg'} alt={name} width="30" height="33" />
        </a>
        <h1 class="title">{name}</h1>
        {#if subtitle}
            <span class="sep" aria-hidden="true"></span>
            <span class="sub">{subtitle}</span>
        {/if}
    </div>

    <div class="user" bind:this={root}>
        <button class="chip" onclick={() => (open = !open)} aria-haspopup="menu" aria-expanded={open}>
            <svg class="ico" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="8" r="4" /><path d="M4 20c0-4 3.6-6 8-6s8 2 8 6z" /></svg>
            <span class="name">{user.display_name}</span>
            <svg class="chev" class:open viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
        </button>

        {#if open}
            <div class="menu" role="menu">
                <div class="menu-head">
                    <span class="menu-name">{user.display_name}</span>
                    <span class="menu-role">{roleLabel}</span>
                </div>
                {#if user.role === 'admin'}
                    <button type="button" class="menu-item" role="menuitem" onclick={manageLayers}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l9 5-9 5-9-5 9-5z" /><path d="M3 13l9 5 9-5" /></svg>
                        {t.topbar.manageLayers}
                    </button>
                    <button type="button" class="menu-item" role="menuitem" onclick={manageFields}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10" /></svg>
                        {t.topbar.manageFields}
                    </button>
                    <button type="button" class="menu-item" role="menuitem" onclick={manageUsers}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.2" /><path d="M3.5 19c0-3 2.5-4.7 5.5-4.7s5.5 1.7 5.5 4.7" /><path d="M17 8.5h4M19 6.5v4" /></svg>
                        {t.topbar.manageUsers}
                    </button>
                    <button type="button" class="menu-item" role="menuitem" onclick={manageHistory}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8" /><path d="M3 4v4h4" /><path d="M12 8v4l3 2" /></svg>
                        {t.topbar.history}
                    </button>
                {/if}
                <button type="button" class="menu-item" role="menuitem" onclick={switchLang}>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M3 12h18" /><path d="M12 3a13.5 13.5 0 0 1 0 18a13.5 13.5 0 0 1 0-18z" /></svg>
                    {t.topbar.switchLang}
                </button>
                <form method="post" action={base + '/logout.php'}>
                    <input type="hidden" name="csrf" value={csrf} />
                    <button type="submit" class="menu-item" role="menuitem">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3" /><path d="M10 17l-5-5 5-5" /><path d="M5 12h11" /></svg>
                        {t.topbar.logout}
                    </button>
                </form>
            </div>
        {/if}
    </div>
</header>

<style>
    .topbar {
        flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;
        gap: 1rem; height: 60px; padding: 0 1rem 0 1.1rem;
        background: var(--header); color: #eef0f2; border-bottom: 1px solid var(--header-line);
    }
    .brand { display: flex; align-items: center; gap: 0.8rem; min-width: 0; }
    .logo { display: inline-flex; line-height: 0; }
    .logo img { display: block; }
    .title { font-family: var(--font-disp); font-size: 1.22rem; font-weight: 700; white-space: nowrap; }
    .sep { width: 1px; height: 26px; background: var(--header-line); }
    .sub { color: #9aa2aa; font-size: 0.85rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .user { position: relative; flex-shrink: 0; }
    .chip {
        display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.35rem 0.6rem 0.35rem 0.7rem;
        background: rgba(255,255,255,.08); border: 1px solid var(--header-line); border-radius: 999px;
        color: #e8eaed; font: inherit; font-size: 0.85rem; cursor: pointer; white-space: nowrap;
        transition: background 0.12s;
    }
    .chip:hover { background: rgba(255,255,255,.16); }
    .chip:focus-visible { outline: 3px solid var(--ring); outline-offset: 2px; }
    .chip .ico { width: 15px; height: 15px; opacity: 0.85; }
    .chip .chev { width: 14px; height: 14px; opacity: 0.8; transition: transform 0.15s; }
    .chip .chev.open { transform: rotate(180deg); }

    .menu {
        position: absolute; top: calc(100% + 8px); right: 0; min-width: 200px;
        background: var(--surface); color: var(--ink); border: 1px solid var(--border);
        border-radius: var(--r); box-shadow: var(--shadow); overflow: hidden; z-index: 1200;
    }
    .menu-head { display: flex; flex-direction: column; gap: 0.1rem; padding: 0.7rem 0.85rem; border-bottom: 1px solid var(--border); }
    .menu-name { font-weight: 600; font-size: 0.9rem; }
    .menu-role { font-size: 0.78rem; color: var(--muted); }
    .menu form { margin: 0; }
    .menu-item {
        display: flex; align-items: center; gap: 0.6rem; width: 100%;
        padding: 0.7rem 0.85rem; background: none; border: none; cursor: pointer;
        font: inherit; font-size: 0.9rem; color: var(--ink); text-align: left;
    }
    .menu-item:hover { background: var(--surface-2); }
    .menu-item:focus-visible { outline: 3px solid var(--ring); outline-offset: -3px; }
    .menu-item svg { width: 17px; height: 17px; color: var(--muted); }

    @media (max-width: 860px) { .sep, .sub { display: none; } }
</style>
