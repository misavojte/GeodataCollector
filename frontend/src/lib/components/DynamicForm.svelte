<script lang="ts">
    import type { FieldDefinition, HierarchyNode } from '$lib/types';
    import { MSG } from '$lib/i18n';
    import AttachmentField from './AttachmentField.svelte';

    let {
        fields,
        values = $bindable(),
        errors = {},
        readonly = false
    }: {
        fields: FieldDefinition[];
        values: Record<string, any>;
        errors?: Record<string, string>;
        readonly?: boolean;
    } = $props();

    function multiValues(key: string): string[] {
        const v = values[key];
        return Array.isArray(v) ? (v as string[]) : [];
    }
    function toggleMulti(key: string, opt: string, checked: boolean) {
        const arr = multiValues(key).slice();
        const i = arr.indexOf(opt);
        if (checked && i < 0) arr.push(opt);
        if (!checked && i >= 0) arr.splice(i, 1);
        values[key] = arr;
    }

    function getHierarchyLevelOptions(f: FieldDefinition, lvlIndex: number): HierarchyNode[] {
        const levels = f.validation?.levels ?? [{ key: f.field_key, label: f.label }];
        let currentNodes = (f.options as HierarchyNode[]) ?? [];
        if (lvlIndex === 0) {
            return currentNodes;
        }

        for (let i = 0; i < lvlIndex; i++) {
            const parentKey = levels[i].key;
            const parentVal = values[parentKey];
            if (!parentVal) return [];
            const found = currentNodes.find((n) => n.value === parentVal || n.label === parentVal);
            if (!found || !found.children) return [];
            currentNodes = found.children;
        }
        return currentNodes;
    }

    function onHierarchyChange(f: FieldDefinition, lvlIndex: number, newVal: string) {
        const levels = f.validation?.levels ?? [{ key: f.field_key, label: f.label }];
        values[levels[lvlIndex].key] = newVal;
        for (let j = lvlIndex + 1; j < levels.length; j++) {
            values[levels[j].key] = '';
        }
    }

    // Links the help text and the error to the field for screen readers (aria-describedby).
    function describedBy(f: FieldDefinition): string | undefined {
        const ids: string[] = [];
        if (f.help_text) ids.push('f_' + f.field_key + '_help');
        if (errors[f.field_key]) ids.push('f_' + f.field_key + '_err');
        return ids.length ? ids.join(' ') : undefined;
    }
</script>

<div class="form">
    {#each fields as f (f.field_key)}
        {#if f.data_type === 'hierarchy'}
            {@const levels = f.validation?.levels ?? [{ key: f.field_key, label: f.label }]}
            {#each levels as lvl, lvlIdx (lvl.key)}
                {@const parentLvl = lvlIdx > 0 ? levels[lvlIdx - 1] : null}
                {@const parentVal = parentLvl ? values[parentLvl.key] : true}
                {@const isDisabled = readonly || !parentVal}
                {@const opts = getHierarchyLevelOptions(f, lvlIdx)}
                {@const err = errors[lvl.key] || (lvlIdx === 0 ? errors[f.field_key] : undefined)}

                <div class="row" class:has-error={!!err}>
                    <label class="field-label" id={'f_' + lvl.key + '_label'} for={'f_' + lvl.key}>
                        {lvl.label}{#if f.is_required}<span class="req" aria-hidden="true"> *</span>{/if}
                    </label>

                    <select id={'f_' + lvl.key} class="ui-select" disabled={isDisabled}
                        aria-required={f.is_required} aria-invalid={!!err}
                        value={values[lvl.key] ?? ''}
                        onchange={(e) => onHierarchyChange(f, lvlIdx, e.currentTarget.value)}>
                        <option value="">
                            {isDisabled && parentLvl ? MSG.selectParentFirst(parentLvl.label) : MSG.selectEmpty}
                        </option>
                        {#each opts as opt}
                            <option value={opt.value}>{opt.label}</option>
                        {/each}
                    </select>

                    {#if lvlIdx === levels.length - 1 && f.help_text}
                        <p class="help" id={'f_' + f.field_key + '_help'}>{f.help_text}</p>
                    {/if}
                    {#if err}
                        <p class="err" id={'f_' + lvl.key + '_err'}>{err}</p>
                    {/if}
                </div>
            {/each}
        {:else}
            <div class="row" class:has-error={errors[f.field_key]}>
                <label class="field-label" id={'f_' + f.field_key + '_label'}
                    for={['multiselect', 'boolean', 'photos', 'files'].includes(f.data_type) ? undefined : 'f_' + f.field_key}>
                    {f.label}{#if f.is_required}<span class="req" aria-hidden="true"> *</span>{/if}
                </label>

                {#if f.data_type === 'photos' || f.data_type === 'files'}
                    <AttachmentField field={f} bind:value={values[f.field_key]} {readonly} describedBy={describedBy(f)} />
                {:else if f.data_type === 'textarea'}
                    <textarea id={'f_' + f.field_key} class="ui-textarea long" rows="5" disabled={readonly}
                        maxlength={f.validation?.maxLength ?? undefined}
                        aria-required={f.is_required} aria-invalid={!!errors[f.field_key]} aria-describedby={describedBy(f)}
                        bind:value={values[f.field_key]}></textarea>
                {:else if f.data_type === 'number' || f.data_type === 'integer'}
                    <input id={'f_' + f.field_key} class="ui-input" type="number"
                        step={f.data_type === 'integer' ? '1' : 'any'}
                        min={f.validation?.min ?? undefined} max={f.validation?.max ?? undefined}
                        aria-required={f.is_required} aria-invalid={!!errors[f.field_key]} aria-describedby={describedBy(f)}
                        disabled={readonly} bind:value={values[f.field_key]} />
                {:else if f.data_type === 'boolean'}
                    <label class="ui-check">
                        <input id={'f_' + f.field_key} type="checkbox" disabled={readonly}
                            aria-labelledby={'f_' + f.field_key + '_label'}
                            aria-required={f.is_required} aria-invalid={!!errors[f.field_key]} aria-describedby={describedBy(f)}
                            bind:checked={values[f.field_key]} /> {MSG.boolYes}
                    </label>
                {:else if f.data_type === 'date'}
                    <input id={'f_' + f.field_key} class="ui-input" type="date" disabled={readonly}
                        aria-required={f.is_required} aria-invalid={!!errors[f.field_key]} aria-describedby={describedBy(f)}
                        bind:value={values[f.field_key]} />
                {:else if f.data_type === 'select'}
                    <select id={'f_' + f.field_key} class="ui-select" disabled={readonly}
                        aria-required={f.is_required} aria-invalid={!!errors[f.field_key]} aria-describedby={describedBy(f)}
                        bind:value={values[f.field_key]}>
                        <option value="">{MSG.selectEmpty}</option>
                        {#each f.options ?? [] as opt}
                            <option value={opt.value}>{opt.label}</option>
                        {/each}
                    </select>
                {:else if f.data_type === 'multiselect'}
                    <div class="multi" role="group" aria-labelledby={'f_' + f.field_key + '_label'} aria-describedby={describedBy(f)}>
                        {#each f.options ?? [] as opt}
                            <label class="ui-check">
                                <input type="checkbox" disabled={readonly}
                                    checked={multiValues(f.field_key).includes(opt.value)}
                                    onchange={(e) => toggleMulti(f.field_key, opt.value, e.currentTarget.checked)} />
                                {opt.label}
                            </label>
                        {/each}
                    </div>
                {:else}
                    <input id={'f_' + f.field_key} class="ui-input" type="text"
                        maxlength={f.validation?.maxLength ?? undefined} disabled={readonly}
                        aria-required={f.is_required} aria-invalid={!!errors[f.field_key]} aria-describedby={describedBy(f)}
                        bind:value={values[f.field_key]} />
                {/if}

                {#if f.help_text}<p class="help" id={'f_' + f.field_key + '_help'}>{f.help_text}</p>{/if}
                {#if errors[f.field_key]}<p class="err" id={'f_' + f.field_key + '_err'}>{errors[f.field_key]}</p>{/if}
            </div>
        {/if}
    {/each}
</div>

<style>
    .form { display: flex; flex-direction: column; gap: 0.85rem; }
    .row { display: flex; flex-direction: column; gap: 0.3rem; }
    .req { color: var(--accent); }
    .help { font-size: 0.78rem; color: var(--muted); margin: 0; }
    .err { font-size: 0.78rem; color: var(--danger); margin: 0; }
    /* Longer text (description): grows with its content (where the browser supports it), otherwise manual resize. */
    .long { min-height: 7.5em; max-height: 60vh; resize: vertical; field-sizing: content; }
    .multi { display: flex; flex-direction: column; gap: 0.3rem; }
    .row.has-error :global(.ui-input),
    .row.has-error :global(.ui-select),
    .row.has-error :global(.ui-textarea) { border-color: var(--danger); }
</style>
