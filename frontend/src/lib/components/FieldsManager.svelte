<script lang="ts">
    import Button from './ui/Button.svelte';
    import { trapFocus } from '$lib/actions/trapFocus';
    import { fade, fly } from 'svelte/transition';
    import { cubicOut } from 'svelte/easing';
    import { reducedMotion } from '$lib/reducedMotion';
    import { api } from '$lib/api';
    import { toast } from '$lib/toast.svelte';
    import { TYPE_LABEL, t } from '$lib/i18n';
    import type { FieldDefinition, FieldOption, FieldValidation, DataType, GeometryType, FieldInput, HierarchyLevel, HierarchyNode } from '$lib/types';

    let { onClose, onChanged }: { onClose: () => void; onChanged: () => void } = $props();

    const PROTECTED = 'nazev';
    const GEOMS: GeometryType[] = ['point', 'line', 'polygon'];
    const TYPE_NAMES = t.fields.typeNames;
    const ASCII: Record<string, string> = {
        'á':'a','č':'c','ď':'d','é':'e','ě':'e','í':'i','ň':'n','ó':'o','ř':'r','š':'s','ť':'t','ú':'u','ů':'u','ý':'y','ž':'z',
        'Á':'a','Č':'c','Ď':'d','É':'e','Ě':'e','Í':'i','Ň':'n','Ó':'o','Ř':'r','Š':'s','Ť':'t','Ú':'u','Ů':'u','Ý':'y','Ž':'z'
    };

    type OptRow = { value: string; label: string };
    type Form = {
        id: number | null;
        field_key: string;
        label: string;
        data_type: DataType;
        origType: DataType;        // type when opened (to drop rules that no longer fit after a change)
        is_required: boolean;
        help_text: string;
        applies: Record<GeometryType, boolean>;
        options: OptRow[];
        validation: FieldValidation | null; // pass-through (not edited in the UI)
        default_value: unknown;             // pass-through
        sort_order: number;
        // Hierarchy field support:
        levels: HierarchyLevel[];
        hierarchyText: string;
        stats: { levels: number; items: number } | null;
    };

    let nameField = $state<FieldDefinition | null>(null);
    let items = $state<FieldDefinition[]>([]);    // active fields except "nazev", in order
    let inactive = $state<FieldDefinition[]>([]); // removed (is_active = 0)
    let loading = $state(true);
    let error = $state('');

    let form = $state<Form | null>(null);
    let saving = $state(false);
    let formError = $state('');

    let dragIndex = $state<number | null>(null);
    let overIndex = $state<number | null>(null);

    const isSelectType = $derived(form ? (form.data_type === 'select' || form.data_type === 'multiselect') : false);
    const isHierarchyType = $derived(form ? form.data_type === 'hierarchy' : false);

    async function load() {
        loading = true;
        error = '';
        try {
            const all = (await api.allFields()).fields;
            nameField = all.find((f) => f.field_key === PROTECTED) ?? null;
            items = all.filter((f) => f.is_active && f.field_key !== PROTECTED);
            inactive = all.filter((f) => !f.is_active && f.field_key !== PROTECTED);
        } catch (e) {
            error = (e as Error).message || t.fields.loadFailed;
        } finally {
            loading = false;
        }
    }
    load();

    function nextSort(): number {
        const vals = [...items, ...inactive].map((i) => i.sort_order);
        if (nameField) vals.push(nameField.sort_order);
        return (vals.length ? Math.max(...vals) : 0) + 1;
    }

    function slug(label: string): string {
        let s = [...label].map((c) => ASCII[c] ?? c).join('').toLowerCase();
        s = s.replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        if (s === '' || !/^[a-z]/.test(s)) s = ('pole_' + s).replace(/_+$/, '');
        return s.slice(0, 56);
    }
    function uniqueKey(label: string): string {
        // Dedup against ALL keys (including removed ones), otherwise a re-add would collide with a hidden inactive row.
        const taken = new Set(
            [...items, ...inactive, ...(nameField ? [nameField] : [])].map((f) => f.field_key)
        );
        const base = slug(label);
        let cand = base, n = 1;
        while (taken.has(cand)) { n++; cand = base + '_' + n; }
        return cand;
    }

    function treeToIndentedText(nodes: HierarchyNode[], depth = 0): string {
        const indent = '\t'.repeat(depth);
        const lines: string[] = [];
        for (const n of nodes) {
            lines.push(indent + (n.label || n.value));
            if (n.children && n.children.length > 0) {
                lines.push(treeToIndentedText(n.children, depth + 1));
            }
        }
        return lines.join('\n');
    }

    function parseHierarchyText(text: string): { tree: HierarchyNode[]; stats: { levels: number; items: number } } {
        const lines = text.split(/\r?\n/).map((l) => l.replace(/\s+$/, '')).filter((l) => l.trim() !== '');
        if (!lines.length) return { tree: [], stats: { levels: 0, items: 0 } };

        const isTable = lines.some((l) => l.trim().includes('\t') || l.trim().includes(';'));
        const tree: HierarchyNode[] = [];
        let totalItems = 0;
        let maxDepth = 0;

        if (isTable) {
            for (const line of lines) {
                const parts = (line.includes('\t') ? line.split('\t') : line.split(';'))
                    .map((p) => p.trim())
                    .filter((p) => p !== '');
                if (!parts.length) continue;

                let currentLevelNodes = tree;
                parts.forEach((part, d) => {
                    maxDepth = Math.max(maxDepth, d + 1);
                    let found = currentLevelNodes.find((n) => n.value === part || n.label === part);
                    if (!found) {
                        found = { value: part, label: part, children: [] };
                        currentLevelNodes.push(found);
                        totalItems++;
                    }
                    if (!found.children) found.children = [];
                    currentLevelNodes = found.children;
                });
            }
        } else {
            const stack: { depth: number; node: HierarchyNode }[] = [];
            for (const rawLine of lines) {
                let indent = 0;
                let i = 0;
                while (i < rawLine.length) {
                    if (rawLine[i] === '\t') indent += 1;
                    else if (rawLine[i] === ' ' && rawLine[i + 1] === ' ') { indent += 1; i++; }
                    else if (rawLine[i] === ' ') { /* single space */ }
                    else break;
                    i++;
                }
                const label = rawLine.trim();
                if (!label) continue;

                const node: HierarchyNode = { value: label, label: label, children: [] };
                maxDepth = Math.max(maxDepth, indent + 1);
                totalItems++;

                while (stack.length > 0 && stack[stack.length - 1].depth >= indent) {
                    stack.pop();
                }

                if (stack.length === 0) {
                    tree.push(node);
                } else {
                    if (!stack[stack.length - 1].node.children) stack[stack.length - 1].node.children = [];
                    stack[stack.length - 1].node.children!.push(node);
                }
                stack.push({ depth: indent, node });
            }
        }

        function cleanChildren(nodes: HierarchyNode[]) {
            for (const n of nodes) {
                if (n.children && n.children.length > 0) cleanChildren(n.children);
                else delete n.children;
            }
        }
        cleanChildren(tree);

        return { tree, stats: { levels: maxDepth, items: totalItems } };
    }

    function blank(): Form {
        return {
            id: null, field_key: '', label: '', data_type: 'text', origType: 'text',
            is_required: false, help_text: '',
            applies: { point: false, line: false, polygon: false }, options: [],
            validation: null, default_value: undefined, sort_order: nextSort(),
            levels: [
                { key: 'level_1', label: t.ui.level(1) },
                { key: 'level_2', label: t.ui.level(2) },
                { key: 'level_3', label: t.ui.level(3) }
            ],
            hierarchyText: '',
            stats: null
        };
    }
    function startAdd() { form = blank(); formError = ''; }
    function startEdit(f: FieldDefinition) {
        const isHier = f.data_type === 'hierarchy';
        const defaultLevels = isHier && f.validation?.levels?.length
            ? f.validation.levels
            : [
                { key: 'level_1', label: t.ui.level(1) },
                { key: 'level_2', label: t.ui.level(2) },
                { key: 'level_3', label: t.ui.level(3) }
            ];
        const hierText = isHier && f.options
            ? treeToIndentedText(f.options as HierarchyNode[])
            : '';
        const parsedStats = isHier && hierText ? parseHierarchyText(hierText).stats : null;

        form = {
            id: f.id, field_key: f.field_key, label: f.label, data_type: f.data_type, origType: f.data_type,
            is_required: f.is_required, help_text: f.help_text ?? '',
            applies: {
                point: f.applies_to?.includes('point') ?? false,
                line: f.applies_to?.includes('line') ?? false,
                polygon: f.applies_to?.includes('polygon') ?? false
            },
            options: (f.options ?? []).map((o: any) => ({ value: o.value ?? '', label: o.label ?? '' })),
            validation: f.validation, default_value: f.default_value, sort_order: f.sort_order,
            levels: defaultLevels,
            hierarchyText: hierText,
            stats: parsedStats
        };
        formError = '';
    }
    function cancelForm() { form = null; formError = ''; }

    function addOption() { if (form) form.options = [...form.options, { value: '', label: '' }]; }
    function removeOption(i: number) { if (form) form.options = form.options.filter((_, idx) => idx !== i); }

    function addLevel() {
        if (!form) return;
        const idx = form.levels.length + 1;
        form.levels = [...form.levels, { key: 'level_' + idx, label: t.ui.level(idx) }];
    }
    function removeLevel(i: number) {
        if (!form || form.levels.length <= 2) return;
        form.levels = form.levels.filter((_, idx) => idx !== i);
    }
    function updateHierarchyStats() {
        if (!form) return;
        const res = parseHierarchyText(form.hierarchyText);
        form.stats = res.stats;
    }
    function onLevelLabelInput(i: number) {
        if (!form || !form.levels[i]) return;
        const lvl = form.levels[i];
        if (!lvl.key || lvl.key === slug(lvl.label.slice(0, -1))) {
            lvl.key = slug(lvl.label);
        }
    }
    function onHierarchyKeydown(e: KeyboardEvent) {
        if (e.key === 'Tab') {
            e.preventDefault();
            const ta = e.currentTarget as HTMLTextAreaElement;
            const start = ta.selectionStart;
            const end = ta.selectionEnd;
            const val = ta.value;
            ta.value = val.substring(0, start) + '\t' + val.substring(end);
            ta.selectionStart = ta.selectionEnd = start + 1;
            if (form) {
                form.hierarchyText = ta.value;
                updateHierarchyStats();
            }
        }
    }

    async function save() {
        if (!form) return;
        const label = form.label.trim();
        if (label === '') { formError = t.fields.labelRequired; return; }

        const isSelect = form.data_type === 'select' || form.data_type === 'multiselect';
        const isHier = form.data_type === 'hierarchy';
        let options: (FieldOption | HierarchyNode)[] | null = null;
        let validation = form.validation;

        if (isSelect) {
            const selectOptions: FieldOption[] = form.options
                .map((o) => ({ label: o.label.trim(), value: o.value.trim() || slug(o.label) || o.label.trim() }))
                .filter((o) => o.label !== '');
            if (selectOptions.length === 0) { formError = t.fields.optionRequired; return; }
            const seen = new Set<string>();
            for (const o of selectOptions) {
                if (seen.has(o.value)) { formError = t.fields.optionDup; return; }
                seen.add(o.value);
            }
            options = selectOptions;
        } else if (isHier) {
            if (!form.levels || form.levels.length < 2) {
                formError = t.fields.hierarchyMinLevels;
                return;
            }
            for (const lvl of form.levels) {
                if (!lvl.label.trim() || !lvl.key.trim()) {
                    formError = t.fields.hierarchyLevelRequired;
                    return;
                }
                if (!/^[a-z][a-z0-9_]*$/.test(lvl.key.trim())) {
                    formError = t.fields.hierarchyLevelKeyInvalid;
                    return;
                }
            }
            const parsed = parseHierarchyText(form.hierarchyText);
            if (!parsed.tree.length) {
                formError = t.fields.hierarchyEmpty;
                return;
            }
            options = parsed.tree;
            validation = {
                ...(validation ?? {}),
                levels: form.levels.map((l) => ({ key: l.key.trim(), label: l.label.trim() }))
            };
        }

        // Drop rules and the default value that no longer fit (otherwise they'd be meaningless / block saving).
        const typeChanged = form.id !== null && form.data_type !== form.origType;
        if (typeChanged) {
            validation = isHier ? validation : null;
        }
        let default_value = form.default_value;
        if (typeChanged) {
            default_value = undefined;
        } else if (isSelect && typeof default_value === 'string' && !(options as FieldOption[]).some((o) => o.value === default_value)) {
            default_value = undefined; // the default pointed at an option that no longer exists
        }

        const ap = GEOMS.filter((t) => form!.applies[t]);
        const body: FieldInput = {
            field_key: form.id === null ? uniqueKey(label) : form.field_key,
            label,
            data_type: form.data_type,
            is_required: form.is_required,
            help_text: form.help_text.trim() || null,
            options,
            applies_to: ap.length === 0 ? null : ap, // none = applies to all types
            validation,
            default_value,
            sort_order: form.sort_order,
            is_active: true
        };

        saving = true;
        formError = '';
        try {
            if (form.id === null) await api.createField(body);
            else await api.updateField(form.id, body);
            form = null;
            await load();
            onChanged();
            toast(t.save.saved);
        } catch (e) {
            formError = (e as Error).message || t.save.failed;
        } finally {
            saving = false;
        }
    }

    async function remove(f: FieldDefinition) {
        if (!window.confirm(t.fields.confirmRemove(f.label))) return;
        try {
            await api.deleteField(f.id);
            await load();
            onChanged();
            toast(t.fields.removed);
        } catch (e) {
            toast((e as Error).message || t.fields.removeFailed, 'error');
        }
    }

    async function restore(f: FieldDefinition) {
        try {
            await api.updateField(f.id, {
                field_key: f.field_key, label: f.label, data_type: f.data_type,
                is_required: f.is_required, help_text: f.help_text,
                options: f.options, applies_to: f.applies_to,
                validation: f.validation, default_value: f.default_value,
                sort_order: f.sort_order, is_active: true
            });
            await load();
            onChanged();
            toast(t.fields.restored);
        } catch (e) {
            toast((e as Error).message || t.fields.restoreFailed, 'error');
        }
    }

    // --- drag-and-drop reordering (among active fields except "nazev"; the name is always first) ---
    function onDragStart(i: number) { dragIndex = i; }
    function onDragOver(e: DragEvent, i: number) { e.preventDefault(); overIndex = i; }
    function onDragEnd() { dragIndex = null; overIndex = null; }
    async function onDrop(i: number) {
        const from = dragIndex;
        dragIndex = null;
        overIndex = null;
        if (from === null || from === i) return;
        const arr = [...items];
        const [moved] = arr.splice(from, 1);
        arr.splice(i, 0, moved);
        // Sync the in-memory sort_order with what the server stores (nazev=0, others 1..n),
        // otherwise a later field edit would write back a stale order.
        arr.forEach((f, idx) => { f.sort_order = idx + 1; });
        items = arr;
        try {
            await api.reorderFields(arr.map((f) => f.id));
            onChanged();
        } catch (e) {
            toast((e as Error).message || t.common.reorderFailed, 'error');
            await load();
        }
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
    <div class="modal" role="dialog" aria-modal="true" aria-label={t.fields.title} tabindex="-1" use:trapFocus in:fly={{ y: reducedMotion ? 0 : 8, duration: reducedMotion ? 0 : 200, easing: cubicOut }}>
        <header class="m-head">
            <h2>{t.fields.title}</h2>
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
                <!-- Add / edit field form -->
                <div class="form">
                    {#if formError}<div class="alert alert-error" role="alert">{formError}</div>{/if}

                    <div class="row">
                        <label class="field-label" for="f-label">{t.fields.labelField} <span class="req">*</span></label>
                        <input id="f-label" class="ui-input" type="text" maxlength="190" bind:value={form.label} />
                    </div>

                    <div class="grid2">
                        <div class="row">
                            <label class="field-label" for="f-type">{t.fields.type}</label>
                            <select id="f-type" class="ui-select" bind:value={form.data_type}>
                                {#each Object.entries(TYPE_NAMES) as [val, name]}<option value={val}>{name}</option>{/each}
                            </select>
                        </div>
                        <div class="row req-row">
                            <span class="field-label">{t.fields.required}</span>
                            <label class="ui-check"><input type="checkbox" bind:checked={form.is_required} /> {t.fields.requireFill}</label>
                        </div>
                    </div>

                    <div class="row">
                        <label class="field-label" for="f-help">{t.fields.helpLabel}</label>
                        <input id="f-help" class="ui-input" type="text" maxlength="500" bind:value={form.help_text} />
                    </div>

                    {#if isSelectType}
                        <div class="row">
                            <span class="field-label">{t.fields.optionsLabel} <span class="req">*</span></span>
                            <div class="opts">
                                {#each form.options as opt, i (i)}
                                    <div class="opt">
                                        <input class="ui-input" type="text" placeholder={t.fields.optLabelPh} bind:value={form.options[i].label} />
                                        <input class="ui-input val" type="text" placeholder={t.fields.optValuePh} bind:value={form.options[i].value} />
                                        <button type="button" class="opt-del" title={t.fields.optRemove} aria-label={t.fields.optRemove} onclick={() => removeOption(i)}>×</button>
                                    </div>
                                {/each}
                                <Button variant="ghost" small onclick={addOption}>{t.fields.addOption}</Button>
                            </div>
                        </div>
                    {/if}

                    {#if isHierarchyType}
                        <!-- Hierarchy levels -->
                        <div class="row">
                            <span class="field-label">{t.fields.hierarchyLevels} <span class="req">*</span></span>
                            <div class="levels-list">
                                {#each form.levels as lvl, i (i)}
                                    <div class="level-row">
                                        <span class="level-num">{i + 1}.</span>
                                        <input class="ui-input" type="text" placeholder={t.fields.levelLabel} bind:value={form.levels[i].label}
                                            oninput={() => onLevelLabelInput(i)} />
                                        <input class="ui-input val" type="text" placeholder={t.fields.levelKey} bind:value={form.levels[i].key} />
                                        {#if form.levels.length > 2}
                                            <button type="button" class="opt-del" title={t.fields.removeLevel} aria-label={t.fields.removeLevel} onclick={() => removeLevel(i)}>×</button>
                                        {/if}
                                    </div>
                                {/each}
                                <Button variant="ghost" small onclick={addLevel}>{t.fields.addLevel}</Button>
                            </div>
                        </div>

                        <!-- Hierarchy values -->
                        <div class="row">
                            <span class="field-label">{t.fields.hierarchyData} <span class="req">*</span></span>
                            <textarea class="ui-textarea hier-area" rows="9" placeholder={t.ui.hierarchyPlaceholder}
                                bind:value={form.hierarchyText} oninput={updateHierarchyStats} onkeydown={onHierarchyKeydown}></textarea>
                            <p class="help">{t.fields.hierarchyIndentHelp}</p>

                            {#if form.stats}
                                <div class="stats-badge">
                                    {t.fields.hierarchyStats(form.stats.levels, form.stats.items)}
                                </div>
                            {/if}
                        </div>
                    {/if}

                    <div class="row">
                        <span class="field-label">{t.fields.appliesLabel}</span>
                        <div class="applies">
                            {#each GEOMS as g}
                                <label class="ui-check"><input type="checkbox" bind:checked={form.applies[g]} /> {TYPE_LABEL[g]}</label>
                            {/each}
                        </div>
                        <p class="help">{t.fields.appliesHelp}</p>
                    </div>

                    <div class="form-actions">
                        <Button variant="primary" disabled={saving} onclick={save}>{form.id === null ? t.common.add : t.common.save}</Button>
                        <Button variant="ghost" onclick={cancelForm}>{t.common.cancel}</Button>
                    </div>
                </div>
            {:else}
                <!-- Field list -->
                <p class="hint">{t.fields.listHint}</p>

                {#if nameField}
                    <div class="litem locked">
                        <span class="lock" title={t.fields.lockTitle} aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                        </span>
                        <span class="badge">TEXT</span>
                        <span class="ltitle">{nameField.label} <em class="def">{t.fields.alwaysRequired}</em></span>
                    </div>
                {/if}

                <ul class="llist">
                    {#each items as f, i (f.id)}
                        <!-- svelte-ignore a11y_no_static_element_interactions -->
                        <li
                            class="litem"
                            class:dragover={overIndex === i}
                            class:dragging={dragIndex === i}
                            draggable="true"
                            ondragstart={() => onDragStart(i)}
                            ondragover={(e) => onDragOver(e, i)}
                            ondrop={() => onDrop(i)}
                            ondragend={onDragEnd}
                        >
                            <span class="grip" title={t.common.gripTitle} aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
                            </span>
                            <span class="badge">{f.data_type === 'hierarchy' ? t.fields.hierarchyBadge(f.validation?.levels?.length ?? 3) : TYPE_NAMES[f.data_type]}</span>
                            <span class="ltitle">
                                {f.label}
                                {#if f.is_required}<em class="reqmark">{t.fields.requiredMark}</em>{/if}
                            </span>
                            <span class="lactions">
                                <Button variant="ghost" small onclick={() => startEdit(f)}>{t.common.edit}</Button>
                                <Button variant="danger" small onclick={() => remove(f)}>{t.fields.removeBtn}</Button>
                            </span>
                        </li>
                    {/each}
                </ul>

                <div class="add-row">
                    <Button variant="primary" onclick={startAdd}>{t.fields.addField}</Button>
                </div>

                {#if inactive.length > 0}
                    <h3 class="sub-h">{t.fields.inactiveHeading}</h3>
                    <p class="hint">{t.fields.inactiveHint}</p>
                    <ul class="llist">
                        {#each inactive as f (f.id)}
                            <li class="litem muted-item">
                                <span class="badge">{f.data_type === 'hierarchy' ? t.fields.hierarchyBadge(f.validation?.levels?.length ?? 3) : TYPE_NAMES[f.data_type]}</span>
                                <span class="ltitle">{f.label}</span>
                                <span class="lactions"><Button variant="ghost" small onclick={() => restore(f)}>{t.common.restore}</Button></span>
                            </li>
                        {/each}
                    </ul>
                {/if}
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
    .hint { margin: 0 0 0.8rem; font-size: 0.82rem; color: var(--muted); }
    .sub-h { margin: 1.2rem 0 0.5rem; font-size: 0.95rem; font-weight: 600; color: var(--ink); }

    .llist { list-style: none; margin: 0 0 1rem; padding: 0; }
    .litem {
        display: flex; align-items: center; gap: 0.55rem;
        padding: 0.55rem 0.5rem; border: 1px solid var(--border); border-radius: var(--r-sm);
        margin-bottom: 0.4rem; background: var(--surface);
    }
    .litem.locked { background: var(--surface-2); }
    .litem.muted-item { background: var(--surface-2); }
    .litem.muted-item .ltitle { color: var(--muted); font-weight: 500; }
    .litem.dragging { opacity: 0.4; }
    .litem.dragover { border-color: var(--accent); box-shadow: 0 0 0 2px var(--ring); }
    .grip { display: inline-flex; color: var(--muted); cursor: grab; flex-shrink: 0; }
    .grip svg { width: 16px; height: 16px; }
    .lock { display: inline-flex; color: var(--muted); flex-shrink: 0; }
    .lock svg { width: 15px; height: 15px; }
    .badge {
        flex-shrink: 0; padding: 0.1rem 0.45rem; border-radius: 999px;
        font-size: 0.66rem; font-weight: 600; letter-spacing: 0.02em; white-space: nowrap;
        color: var(--muted); background: var(--surface-2); border: 1px solid var(--border);
    }
    .litem.locked .badge, .litem.muted-item .badge { background: var(--surface); }
    .ltitle { flex: 1; min-width: 0; font-weight: 600; font-size: 0.92rem; overflow: hidden; text-overflow: ellipsis; }
    .ltitle .def { margin-left: 0.4rem; font-style: normal; font-size: 0.72rem; font-weight: 600; color: var(--ok); }
    .ltitle .reqmark { margin-left: 0.4rem; font-style: normal; font-size: 0.72rem; font-weight: 600; color: var(--accent-600); }
    .lactions { flex-shrink: 0; display: flex; gap: 0.4rem; }

    .form { display: flex; flex-direction: column; gap: 0.85rem; }
    .row { display: flex; flex-direction: column; gap: 0.3rem; }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; align-items: start; }
    .req-row .ui-check { margin-top: 0.15rem; }
    .req { color: var(--accent); }
    .help { font-size: 0.78rem; color: var(--muted); margin: 0; }
    .applies { display: flex; gap: 1rem; flex-wrap: wrap; }
    .opts { display: flex; flex-direction: column; gap: 0.4rem; }
    .opt { display: grid; grid-template-columns: 1fr 1fr auto; gap: 0.4rem; align-items: center; }
    .opt .val { color: var(--muted); }
    .opt-del {
        width: 28px; height: 28px; flex-shrink: 0; border-radius: var(--r-sm);
        background: transparent; border: 1px solid var(--border); color: var(--muted);
        cursor: pointer; font-size: 1.1rem; line-height: 1;
    }
    .opt-del:hover { border-color: var(--danger); color: var(--danger); }
    .levels-list { display: flex; flex-direction: column; gap: 0.4rem; }
    .level-row { display: grid; grid-template-columns: auto 1fr 1fr auto; gap: 0.4rem; align-items: center; }
    .level-row .val { color: var(--muted); font-family: monospace; font-size: 0.85rem; }
    .level-num { font-size: 0.82rem; font-weight: 600; color: var(--muted); min-width: 1.2rem; text-align: right; }
    .hier-area { font-family: monospace; font-size: 0.84rem; tab-size: 4; white-space: pre; line-height: 1.4; }
    .stats-badge {
        margin-top: 0.2rem; display: inline-flex; align-items: center;
        padding: 0.25rem 0.55rem; background: rgba(46, 125, 50, 0.1); color: var(--ok);
        border-radius: var(--r-sm); font-size: 0.78rem; font-weight: 600;
    }
    .form-actions { display: flex; gap: 0.6rem; margin-top: 0.3rem; }
    .add-row { display: flex; }
</style>
