import { t } from './i18n';

type Kind = 'ok' | 'error' | 'pending';
export interface ToastAction { label: string; fn: () => void; }
interface Toast { id: number; msg: string; kind: Kind; action?: ToastAction; }

export const toasts = $state<Toast[]>([]); // general toasts (errors, deletions, loading…) – stack
let seq = 0;
const DISMISS_MS = 3200;
const ACTION_DISMISS_MS = 8000; // a toast with an action (e.g. "Undo") stays longer so it can be clicked
const dismissTimers = new Map<number, ReturnType<typeof setTimeout>>();

function clearDismiss(id: number): void {
    const h = dismissTimers.get(id);
    if (h !== undefined) { clearTimeout(h); dismissTimers.delete(id); }
}
function remove(id: number): void {
    clearDismiss(id);
    const i = toasts.findIndex((t) => t.id === id);
    if (i >= 0) toasts.splice(i, 1);
}
/** Closes a toast immediately (called by the action button after the action runs). */
export function dismissToast(id: number): void {
    remove(id);
}
function scheduleDismiss(id: number, ms: number = DISMISS_MS): void {
    clearDismiss(id);
    dismissTimers.set(id, setTimeout(() => remove(id), ms));
}
// Frequent messages with the same text (and kind) should not stack – keep only the newest.
// This also applies to repeated "errors" (e.g. incomplete-shape warnings while drawing), so they don't pile up.
function collapseSame(keepId: number, msg: string, kind: Kind): void {
    for (let i = toasts.length - 1; i >= 0; i--) {
        const t = toasts[i];
        if (t.id !== keepId && t.kind === kind && t.msg === msg) { clearDismiss(t.id); toasts.splice(i, 1); }
    }
}

export function toast(msg: string, kind: Kind = 'ok', action?: ToastAction): number {
    const id = ++seq;
    toasts.push({ id, msg, kind, action });
    if (kind === 'ok' || kind === 'error') collapseSame(id, msg, kind);
    if (kind !== 'pending') scheduleDismiss(id, action ? ACTION_DISMISS_MS : DISMISS_MS);
    return id;
}

/** Updates an existing toast in place (same id = same element). */
export function updateToast(id: number, msg: string, kind: Kind): void {
    const t = toasts.find((x) => x.id === id);
    if (t) { t.msg = msg; t.kind = kind; } else { toasts.push({ id, msg, kind }); }
    if (kind === 'ok' || kind === 'error') collapseSame(id, msg, kind);
    if (kind === 'pending') clearDismiss(id);
    else scheduleDismiss(id);
}

// --- Save status toast: its OWN slot with a single visible element ---------------------------
// Every new save = a NEW element (new `key`) → slides up from below + fade-in. If the previous one
// is still showing, it fades out in the same spot (the slot is an overlapping grid → no collision/shift).
// Within one editing "session" the text/state just morphs in place (same key: Saving… → Saved.).
interface SaveStatus { key: number; msg: string; kind: Kind; }
const SAVE_OUT_MS = 240; // ≥ out:fade duration in Toasts.svelte – how long to wait for the old one to vanish before showing the new one
let saveKey = 0;
let saveDismiss: ReturnType<typeof setTimeout> | undefined;
let saveSwap: ReturnType<typeof setTimeout> | undefined;
let queued: SaveStatus | null = null; // waiting for the previous toast to finish fading out (then shown)
// Container (we mutate `save.status` instead of reassigning the exported constant).
export const save = $state<{ status: SaveStatus | null }>({ status: null });

function saveDismissIn(forKey: number): void {
    clearTimeout(saveDismiss);
    saveDismiss = setTimeout(() => { if (save.status && save.status.key === forKey) save.status = null; }, DISMISS_MS);
}
// New event: if something is showing, FIRST let it disappear (out animation) and only once that finishes
// show the new one (slide+fade in). If nothing is showing, it appears immediately. Meanwhile we hold `queued`.
function beginSave(msg: string): void {
    clearTimeout(saveDismiss);
    const fresh: SaveStatus = { key: ++saveKey, msg, kind: 'pending' };
    if (save.status) {
        queued = fresh;
        save.status = null; // triggers out:fade of the current one
        clearTimeout(saveSwap);
        saveSwap = setTimeout(() => {
            save.status = queued;
            queued = null;
            saveSwap = undefined;
            if (save.status && save.status.kind !== 'pending') saveDismissIn(save.status.key);
        }, SAVE_OUT_MS);
    } else if (saveSwap) {
        queued = fresh; // we're in the gap (the previous one is fading out) → just replace what will be shown
    } else {
        save.status = fresh; // nothing showing → right away
    }
}
// Morph the current (or queued) state in place – without a new slide-in.
function applyState(msg: string, kind: Kind): void {
    if (queued) { queued.msg = msg; queued.kind = kind; return; } // shown only after the gap
    if (save.status) {
        save.status.msg = msg;
        save.status.kind = kind;
        if (kind !== 'pending') saveDismissIn(save.status.key);
        return;
    }
    save.status = { key: ++saveKey, msg, kind }; // nothing showing → create
    if (kind !== 'pending') saveDismissIn(save.status.key);
}
export const saveToast = {
    begin(msg = t.save.saving): void { beginSave(msg); },
    saving(msg = t.save.saving): void { applyState(msg, 'pending'); },
    saved(msg = t.save.saved): void { applyState(msg, 'ok'); },
    failed(msg = t.save.failed): void { applyState(msg, 'error'); },
    clear(): void {
        clearTimeout(saveDismiss);
        clearTimeout(saveSwap);
        saveSwap = undefined;
        queued = null;
        save.status = null;
    }
};
