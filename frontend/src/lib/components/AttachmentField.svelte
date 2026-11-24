<script lang="ts">
    import { onDestroy, tick } from 'svelte';
    import { attachmentUrl, uploadAttachment } from '$lib/api';
    import { toast } from '$lib/toast.svelte';
    import { t } from '$lib/i18n';
    import type { AttachmentRef, FieldDefinition } from '$lib/types';

    // Attachment field (photos / documents). A file is uploaded right after it is picked; the field value
    // only holds [{id,name,size}]. After a change it dispatches a bubbling `change` so the record auto-saves
    // just like after editing any other field.
    let {
        field,
        value = $bindable(),
        readonly = false,
        describedBy
    }: {
        field: FieldDefinition;
        value: unknown;
        readonly?: boolean;
        describedBy?: string;
    } = $props();

    const isPhotos = $derived(field.data_type === 'photos');
    const maxFiles = $derived(field.validation?.maxFiles ?? 20);
    const items = $derived<AttachmentRef[]>(Array.isArray(value) ? (value as AttachmentRef[]) : []);
    const ACCEPT_FILES = '.pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.txt,.csv,.jpg,.jpeg,.png,.tif,.tiff';

    let root = $state<HTMLDivElement>();
    let input = $state<HTMLInputElement>();
    let uploading = $state<{ name: string; progress: number }[]>([]);
    let viewer = $state<number | null>(null); // index of the photo open in the viewer
    let destroyed = false;
    onDestroy(() => { destroyed = true; });

    async function commit(next: AttachmentRef[]) {
        // The component is gone (the user closed the record meanwhile) → don't write into another form.
        if (destroyed) return;
        value = next;
        await tick();
        root?.dispatchEvent(new Event('change', { bubbles: true }));
    }

    async function onPick(e: Event) {
        e.stopPropagation(); // picking alone changes nothing yet – we send change only after the upload
        const files = Array.from(input?.files ?? []);
        if (input) input.value = '';
        const room = maxFiles - items.length - uploading.length;
        if (files.length > room) toast(t.att.tooMany(maxFiles), 'error');
        for (const file of files.slice(0, Math.max(0, room))) {
            const job = { name: file.name, progress: 0 };
            uploading.push(job);
            const slot = uploading[uploading.length - 1];
            try {
                const ref = await uploadAttachment(field.field_key, file, (f) => { slot.progress = f; });
                await commit([...items, ref]);
            } catch (err) {
                toast(t.att.uploadFailed(file.name, (err as Error).message), 'error');
            } finally {
                uploading.splice(uploading.indexOf(slot), 1);
            }
        }
    }

    function remove(item: AttachmentRef) {
        if (!window.confirm(t.att.removeConfirm(item.name))) return;
        commit(items.filter((i) => i.id !== item.id));
    }

    function fmtSize(b: number): string {
        if (b < 1024 * 1024) return Math.max(1, Math.round(b / 1024)) + ' kB';
        return (b / 1024 / 1024).toFixed(1).replace('.0', '') + ' MB';
    }

    function onKey(e: KeyboardEvent) {
        // Capture phase + stopPropagation: Esc/arrows while a photo is open must not close the record
        // or move the map (the page's global shortcuts run after us).
        if (viewer === null) return;
        if (e.key === 'Escape') viewer = null;
        else if (e.key === 'ArrowRight') viewer = (viewer + 1) % items.length;
        else if (e.key === 'ArrowLeft') viewer = (viewer - 1 + items.length) % items.length;
        else return;
        e.preventDefault();
        e.stopPropagation();
    }
</script>

<svelte:window onkeydowncapture={onKey} />

<div class="att" bind:this={root} aria-describedby={describedBy}>
    {#if isPhotos}
        {#if items.length}
            <ul class="grid">
                {#each items as item, i (item.id)}
                    <li class="thumb">
                        <button type="button" class="open" onclick={() => (viewer = i)} aria-label={t.att.open(item.name)}>
                            <img src={attachmentUrl(item.id, 't')} alt={item.name} loading="lazy" />
                        </button>
                        {#if !readonly}
                            <button type="button" class="rm" onclick={() => remove(item)} aria-label={t.att.remove(item.name)} title={t.att.remove(item.name)}>×</button>
                        {/if}
                    </li>
                {/each}
            </ul>
        {/if}
    {:else if items.length}
        <ul class="files">
            {#each items as item (item.id)}
                <li>
                    <a href={attachmentUrl(item.id)} target="_blank" rel="noopener">{item.name}</a>
                    <span class="size">{fmtSize(item.size)}</span>
                    {#if !readonly}
                        <button type="button" class="rm-inline" onclick={() => remove(item)} aria-label={t.att.remove(item.name)} title={t.att.remove(item.name)}>×</button>
                    {/if}
                </li>
            {/each}
        </ul>
    {/if}

    {#if readonly && !items.length}<p class="empty">{t.att.empty}</p>{/if}

    {#each uploading as u}
        <div class="up">
            <span class="up-name">{u.name}</span>
            <progress max="1" value={u.progress}></progress>
        </div>
    {/each}

    {#if !readonly && items.length + uploading.length < maxFiles}
        <label class="add">
            <input bind:this={input} type="file" multiple
                accept={isPhotos ? 'image/*,.heic,.heif' : ACCEPT_FILES}
                onchange={onPick} />
            {isPhotos ? t.att.addPhotos : t.att.addFiles}
        </label>
    {/if}
</div>

{#if viewer !== null && items[viewer]}
    {@const cur = items[viewer]}
    <div class="lb" role="dialog" aria-modal="true" aria-label={cur.name} tabindex="-1"
        onclick={(e) => { if (e.target === e.currentTarget) viewer = null; }}
        onkeydown={() => {}}>
        <img src={attachmentUrl(cur.id, 'd')} alt={cur.name} />
        <div class="lb-bar">
            <span class="lb-name">{cur.name} · {viewer + 1}/{items.length}</span>
            <a href={attachmentUrl(cur.id)} target="_blank" rel="noopener">{t.att.original}</a>
        </div>
        {#if items.length > 1}
            <button type="button" class="lb-nav prev" aria-label={t.att.prev} onclick={() => (viewer = (viewer! - 1 + items.length) % items.length)}>‹</button>
            <button type="button" class="lb-nav next" aria-label={t.att.next} onclick={() => (viewer = (viewer! + 1) % items.length)}>›</button>
        {/if}
        <button type="button" class="lb-close" aria-label={t.att.close} onclick={() => (viewer = null)}>×</button>
    </div>
{/if}

<style>
    .att { display: flex; flex-direction: column; gap: 0.5rem; }
    .grid { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(84px, 1fr)); gap: 0.4rem; }
    .thumb { position: relative; aspect-ratio: 1; border-radius: var(--r-sm); overflow: hidden; background: var(--surface-2); border: 1px solid var(--border); }
    .open { display: block; width: 100%; height: 100%; padding: 0; border: 0; background: none; cursor: zoom-in; }
    .open img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .rm { position: absolute; top: 3px; right: 3px; width: 24px; height: 24px; border-radius: 50%; border: 0;
        background: rgba(20, 24, 28, 0.65); color: #fff; font-size: 16px; line-height: 1; cursor: pointer; }
    .rm:hover { background: var(--danger); }
    .files { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.3rem; }
    .files li { display: flex; align-items: center; gap: 0.5rem; font-size: 0.86rem; min-width: 0; }
    .files a { color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
    .files a:hover { color: var(--accent); }
    .size { color: var(--muted); font-size: 0.78rem; flex-shrink: 0; }
    .rm-inline { margin-left: auto; border: 0; background: none; color: var(--muted); font-size: 18px; cursor: pointer; padding: 0 0.3rem; }
    .rm-inline:hover { color: var(--danger); }
    .empty { margin: 0; font-size: 0.82rem; color: var(--muted); }
    .up { display: flex; align-items: center; gap: 0.5rem; font-size: 0.8rem; color: var(--muted); }
    .up-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; flex: 1; }
    .up progress { width: 90px; flex-shrink: 0; accent-color: var(--accent); }
    .add { align-self: flex-start; display: inline-flex; align-items: center; padding: 0.45rem 0.8rem; border: 1px dashed var(--border-strong);
        border-radius: var(--r-sm); font-size: 0.85rem; color: var(--ink); cursor: pointer; background: var(--surface); }
    .add:hover { border-color: var(--accent); color: var(--accent); }
    .add:focus-within { outline: 2px solid var(--ring); outline-offset: 1px; }
    .add input { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }

    .lb { position: fixed; inset: 0; z-index: 2000; background: rgba(10, 12, 14, 0.92); display: flex; align-items: center; justify-content: center; }
    .lb img { max-width: 100vw; max-height: calc(100vh - 3rem); object-fit: contain; }
    .lb-bar { position: absolute; left: 0; right: 0; bottom: 0; display: flex; justify-content: space-between; gap: 1rem;
        padding: 0.7rem 1rem; color: #fff; font-size: 0.85rem; background: rgba(0, 0, 0, 0.4); }
    .lb-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
    .lb-bar a { color: #fff; flex-shrink: 0; }
    .lb-nav, .lb-close { position: absolute; border: 0; background: rgba(255, 255, 255, 0.12); color: #fff; cursor: pointer;
        width: 44px; height: 44px; border-radius: 50%; font-size: 28px; line-height: 1; }
    .lb-nav:hover, .lb-close:hover { background: rgba(255, 255, 255, 0.25); }
    .lb-nav { top: 50%; transform: translateY(-50%); }
    .prev { left: 0.75rem; }
    .next { right: 0.75rem; }
    .lb-close { top: 0.75rem; right: 0.75rem; font-size: 24px; }
</style>
