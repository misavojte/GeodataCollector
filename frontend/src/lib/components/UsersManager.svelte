<script lang="ts">
    import Button from './ui/Button.svelte';
    import { trapFocus } from '$lib/actions/trapFocus';
    import { fade, fly } from 'svelte/transition';
    import { cubicOut } from 'svelte/easing';
    import { reducedMotion } from '$lib/reducedMotion';
    import { api } from '$lib/api';
    import { toast } from '$lib/toast.svelte';
    import { t, DATE_LOCALE } from '$lib/i18n';
    import type { AdminUser } from '$lib/types';

    let { onClose, currentUserId }: { onClose: () => void; currentUserId: number } = $props();

    type Form = {
        id: number | null;
        username: string;
        display_name: string;
        email: string;
        role: 'admin' | 'collector';
        is_active: boolean;
        password: string;
    };

    let users = $state<AdminUser[]>([]);
    let loading = $state(true);
    let error = $state('');

    let form = $state<Form | null>(null);
    let saving = $state(false);
    let formError = $state('');

    async function load() {
        loading = true;
        error = '';
        try {
            users = (await api.users()).users;
        } catch (e) {
            error = (e as Error).message || t.users.loadFailed;
        } finally {
            loading = false;
        }
    }
    load();

    function blank(): Form {
        return { id: null, username: '', display_name: '', email: '', role: 'collector', is_active: true, password: '' };
    }
    function startAdd() { form = blank(); formError = ''; }
    function startEdit(u: AdminUser) {
        form = {
            id: u.id, username: u.username, display_name: u.display_name,
            email: u.email ?? '', role: u.role, is_active: u.is_active, password: ''
        };
        formError = '';
    }
    function cancelForm() { form = null; formError = ''; }

    async function save() {
        if (!form) return;
        const username = form.username.trim();
        const displayName = form.display_name.trim();
        if (displayName === '') { formError = t.users.displayNameRequired; return; }
        if (form.id === null) {
            if (!/^[A-Za-z0-9_.-]{3,64}$/.test(username)) { formError = t.users.usernameFormat; return; }
            if (form.password.length < 8) { formError = t.users.passwordShort; return; }
        } else if (form.password !== '' && form.password.length < 8) {
            formError = t.users.newPasswordShort; return;
        }

        saving = true;
        formError = '';
        try {
            if (form.id === null) {
                await api.createUser({ username, display_name: displayName, email: form.email.trim(), password: form.password, role: form.role });
            } else {
                const body: Record<string, unknown> = {
                    display_name: displayName, email: form.email.trim(), role: form.role, is_active: form.is_active
                };
                if (form.password !== '') body.password = form.password;
                await api.updateUser(form.id, body);
            }
            form = null;
            await load();
            toast(t.save.saved);
        } catch (e) {
            formError = (e as Error).message || t.save.failed;
        } finally {
            saving = false;
        }
    }

    async function toggleActive(u: AdminUser) {
        try {
            await api.updateUser(u.id, { is_active: !u.is_active });
            await load();
            toast(u.is_active ? t.users.deactivated : t.users.activated);
        } catch (e) {
            toast((e as Error).message || t.users.changeFailed, 'error');
        }
    }

    function lastLogin(s: string | null): string {
        if (!s) return t.users.never;
        const ms = Date.parse(s.replace(' ', 'T'));
        return Number.isNaN(ms) ? s : t.users.last(new Date(ms).toLocaleDateString(DATE_LOCALE));
    }

    function onKey(e: KeyboardEvent) {
        if (e.key !== 'Escape') return;
        if (form) cancelForm();
        else onClose();
    }
    function onOverlay(e: MouseEvent) {
        if (e.target === e.currentTarget) onClose();
    }
</script>

<svelte:window onkeydown={onKey} />

<div class="overlay" onclick={onOverlay} role="presentation" transition:fade={{ duration: reducedMotion ? 0 : 140 }}>
    <div class="modal" role="dialog" aria-modal="true" aria-label={t.users.title} tabindex="-1" use:trapFocus in:fly={{ y: reducedMotion ? 0 : 8, duration: reducedMotion ? 0 : 200, easing: cubicOut }}>
        <header class="m-head">
            <h2>{t.users.title}</h2>
            <button class="icon-btn" onclick={onClose} title={t.common.close} aria-label={t.common.close}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
            </button>
        </header>

        <div class="m-body">
            {#if loading}
                <p class="muted">{t.common.loading}</p>
            {:else if error}
                <div class="alert alert-error" role="alert">{error}</div>
                <Button variant="ghost" small onclick={load}>{t.common.retry}</Button>
            {:else if form}
                <!-- Account create / edit form -->
                <div class="form">
                    {#if formError}<div class="alert alert-error" role="alert">{formError}</div>{/if}

                    <div class="row">
                        <label class="field-label" for="u-username">{t.users.username} <span class="req">*</span></label>
                        {#if form.id === null}
                            <input id="u-username" class="ui-input" type="text" autocapitalize="none" maxlength="64" bind:value={form.username} />
                        {:else}
                            <input id="u-username" class="ui-input" type="text" value={form.username} disabled />
                            <p class="help">{t.users.usernameImmutable}</p>
                        {/if}
                    </div>

                    <div class="row">
                        <label class="field-label" for="u-name">{t.users.displayName} <span class="req">*</span></label>
                        <input id="u-name" class="ui-input" type="text" maxlength="120" bind:value={form.display_name} />
                    </div>

                    <div class="grid2">
                        <div class="row">
                            <label class="field-label" for="u-role">{t.users.role}</label>
                            <select id="u-role" class="ui-select" bind:value={form.role} disabled={form.id === currentUserId}>
                                <option value="collector">{t.topbar.collector}</option>
                                <option value="admin">{t.topbar.admin}</option>
                            </select>
                            {#if form.id === currentUserId}<p class="help">{t.users.ownRoleImmutable}</p>{/if}
                        </div>
                        <div class="row">
                            <label class="field-label" for="u-email">{t.users.email}</label>
                            <input id="u-email" class="ui-input" type="email" maxlength="190" bind:value={form.email} />
                        </div>
                    </div>

                    <div class="row">
                        <label class="field-label" for="u-pass">
                            {form.id === null ? t.users.passwordNew : t.users.passwordChange}
                            {#if form.id === null}<span class="req">*</span>{/if}
                        </label>
                        <input id="u-pass" class="ui-input" type="password" autocomplete="new-password" bind:value={form.password} />
                    </div>

                    {#if form.id !== null}
                        <label class="ui-check">
                            <input type="checkbox" bind:checked={form.is_active} disabled={form.id === currentUserId} />
                            {t.users.activeLabel} {#if form.id === currentUserId}{t.users.ownAccountNote}{/if}
                        </label>
                    {/if}

                    <div class="form-actions">
                        <Button variant="primary" disabled={saving} onclick={save}>{form.id === null ? t.users.createAccount : t.common.save}</Button>
                        <Button variant="ghost" onclick={cancelForm}>{t.common.cancel}</Button>
                    </div>
                </div>
            {:else}
                <!-- Account list -->
                <ul class="ulist">
                    {#each users as u (u.id)}
                        <li class="uitem" class:inactive={!u.is_active}>
                            <span class="uava" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="8" r="4" /><path d="M4 20c0-4 3.6-6 8-6s8 2 8 6z" /></svg>
                            </span>
                            <span class="utext">
                                <span class="uname">
                                    {u.display_name}
                                    <span class="badge {u.role}">{u.role === 'admin' ? t.topbar.admin : t.topbar.collector}</span>
                                    {#if !u.is_active}<span class="badge off">{t.users.inactiveBadge}</span>{/if}
                                </span>
                                <span class="umeta">@{u.username} · {lastLogin(u.last_login_at)}</span>
                            </span>
                            <span class="uactions">
                                <Button variant="ghost" small onclick={() => startEdit(u)}>{t.common.edit}</Button>
                                {#if u.is_active}
                                    <Button variant="danger" small disabled={u.id === currentUserId} onclick={() => toggleActive(u)}>{t.users.deactivate}</Button>
                                {:else}
                                    <Button variant="ghost" small onclick={() => toggleActive(u)}>{t.users.activate}</Button>
                                {/if}
                            </span>
                        </li>
                    {/each}
                </ul>
                <div class="add-row">
                    <Button variant="primary" onclick={startAdd}>{t.users.newUser}</Button>
                </div>
            {/if}
        </div>
    </div>
</div>

<style>
    .overlay {
        position: fixed; inset: 0; z-index: 2000;
        background: rgba(20, 28, 36, 0.45);
        display: flex; align-items: flex-start; justify-content: center;
        padding: 4vh 1rem;
    }
    .modal {
        width: 100%; max-width: 600px; max-height: 92vh; display: flex; flex-direction: column;
        background: var(--surface); border-radius: var(--r); box-shadow: var(--shadow); overflow: hidden;
    }
    .m-head {
        flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;
        padding: 0.9rem 1.1rem; border-bottom: 1px solid var(--border);
    }
    .m-head h2 { font-family: var(--font-disp); font-size: 1.2rem; }
    .icon-btn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 30px; height: 30px; border-radius: var(--r-sm);
        background: transparent; color: var(--muted); border: none; cursor: pointer;
    }
    .icon-btn:hover { background: var(--surface-2); color: var(--ink); }
    .icon-btn:focus-visible { outline: 3px solid var(--ring); outline-offset: 1px; }
    .icon-btn svg { width: 17px; height: 17px; }

    .m-body { padding: 1rem 1.1rem 1.2rem; overflow-y: auto; }
    .muted { color: var(--muted); }

    .ulist { list-style: none; margin: 0 0 1rem; padding: 0; }
    .uitem {
        display: flex; align-items: center; gap: 0.7rem;
        padding: 0.6rem 0.5rem; border-top: 1px solid var(--border);
    }
    .uitem:first-child { border-top: none; }
    .uitem.inactive { opacity: 0.6; }
    .uava { flex-shrink: 0; display: inline-flex; color: var(--muted); }
    .uava svg { width: 26px; height: 26px; }
    .utext { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; flex: 1; }
    .uname { font-weight: 600; font-size: 0.92rem; display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
    .umeta { font-size: 0.78rem; color: var(--muted); }
    .badge {
        padding: 0.05rem 0.45rem; border-radius: 999px;
        font-size: 0.68rem; font-weight: 600; letter-spacing: 0.02em;
        color: var(--muted); background: var(--surface-2); border: 1px solid var(--border);
    }
    .badge.admin { color: var(--accent-600); background: var(--accent-050); border-color: #f2c9c3; }
    .badge.off { color: #8a6d1f; background: #fff7e6; border-color: #f1dca6; }
    .uactions { flex-shrink: 0; display: flex; gap: 0.4rem; }

    .form { display: flex; flex-direction: column; gap: 0.85rem; }
    .row { display: flex; flex-direction: column; gap: 0.3rem; }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; align-items: start; }
    .req { color: var(--accent); }
    .help { font-size: 0.78rem; color: var(--muted); margin: 0; }
    .form-actions { display: flex; gap: 0.6rem; margin-top: 0.3rem; }
    .add-row { display: flex; }
</style>
