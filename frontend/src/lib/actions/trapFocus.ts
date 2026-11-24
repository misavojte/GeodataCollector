// Svelte action for a modal dialog: on open it moves focus inside, keeps Tab/Shift+Tab
// within the dialog (focus can't escape to content under the overlay) and on close returns focus
// to the element that opened it. Apply it to the dialog element: <div role="dialog" tabindex="-1" use:trapFocus>.
const FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export function trapFocus(node: HTMLElement) {
    const opener = (typeof document !== 'undefined' ? document.activeElement : null) as HTMLElement | null;

    const focusable = (): HTMLElement[] =>
        Array.from(node.querySelectorAll<HTMLElement>(FOCUSABLE)).filter((el) => el.offsetParent !== null);

    function onKeydown(e: KeyboardEvent) {
        if (e.key !== 'Tab') return;
        const items = focusable();
        if (items.length === 0) {
            e.preventDefault();
            node.focus();
            return;
        }
        const first = items[0];
        const last = items[items.length - 1];
        const active = document.activeElement as HTMLElement | null;
        if (e.shiftKey) {
            if (active === first || !node.contains(active)) {
                e.preventDefault();
                last.focus();
            }
        } else if (active === last || !node.contains(active)) {
            e.preventDefault();
            first.focus();
        }
    }

    // After rendering, move focus to the dialog itself (it has an aria-label → screen readers announce its name).
    queueMicrotask(() => node.focus());
    node.addEventListener('keydown', onKeydown);

    return {
        destroy() {
            node.removeEventListener('keydown', onKeydown);
            opener?.focus?.();
        }
    };
}
