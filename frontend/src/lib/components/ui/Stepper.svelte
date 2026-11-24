<script lang="ts">
    import { t } from '$lib/i18n';

    export interface StepItem {
        label: string;
        badge?: string;
        shape?: 'circle' | 'square' | 'number';
        variant?: 'default' | 'orange' | 'blue';
    }

    let {
        steps,
        current,
        ariaLabel = t.ui.steps
    }: {
        steps: StepItem[];
        current: number;
        ariaLabel?: string;
    } = $props();

    function cleanLabel(label: string): string {
        return label.replace(/^\d+\.\s*/, '').trim();
    }

    const activeLabel = $derived(cleanLabel(steps[current]?.label ?? ''));
</script>

<nav class="stepper" aria-label={ariaLabel}>
    <ol class="step-track">
        {#each steps as step, i}
            {@const isActive = i === current}
            {@const isDone = i < current}
            {@const isUpcoming = i > current}
            {@const label = cleanLabel(step.label)}
            {@const statusText = isDone ? t.ui.stepDone : isActive ? t.ui.stepActive : t.ui.stepPending}

            {#if i > 0}
                <li class="step-connector" class:done={i <= current} aria-hidden="true">
                    <div class="connector-fill"></div>
                </li>
            {/if}

            <li
                class="step"
                class:active={isActive}
                class:done={isDone}
                class:upcoming={isUpcoming}
                aria-current={isActive ? 'step' : undefined}
                title="{label} – {statusText}"
            >
                <div class="step-circle" aria-hidden="true">
                    {#if isDone}
                        <svg class="check-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3.5 8.5L6.5 11.5L12.5 4.5" />
                        </svg>
                    {:else}
                        <span class="step-num">{i + 1}</span>
                    {/if}
                </div>

                <span class="sr-only">
                    {label} ({statusText})
                </span>
            </li>
        {/each}
    </ol>

    {#if activeLabel}
        <div class="step-active-label" aria-hidden="true">
            <span class="active-label-text">{activeLabel}</span>
        </div>
    {/if}
</nav>

<style>
    .stepper {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        width: 100%;
        margin-bottom: 0.85rem;
        padding: 0.35rem 3px;
        min-width: 0;
    }

    .step-track {
        display: flex;
        align-items: center;
        list-style: none;
        margin: 0;
        padding: 0;
        flex-shrink: 0;
    }

    .step {
        display: inline-flex;
        align-items: center;
        position: relative;
        flex-shrink: 0;
        cursor: default;
        user-select: none;
    }

    /* Connector line between steps */
    .step-connector {
        width: 18px;
        height: 2px;
        background: var(--border);
        margin: 0 0.4rem;
        position: relative;
        overflow: hidden;
        border-radius: 1px;
        flex-shrink: 0;
        list-style: none;
    }

    .connector-fill {
        position: absolute;
        top: 0;
        left: 0;
        bottom: 0;
        width: 0%;
        background: var(--ok);
        transition: width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .step-connector.done .connector-fill {
        width: 100%;
    }

    /* Step circle */
    .step-circle {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        font-size: 0.73rem;
        font-weight: 700;
        line-height: 1;
        flex-shrink: 0;
        transition: background-color 0.28s cubic-bezier(0.4, 0, 0.2, 1),
                    border-color 0.28s cubic-bezier(0.4, 0, 0.2, 1),
                    color 0.28s cubic-bezier(0.4, 0, 0.2, 1),
                    box-shadow 0.28s cubic-bezier(0.4, 0, 0.2, 1),
                    transform 0.28s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    /* Optical vertical centering of the number in the Poppins typeface */
    .step-num {
        display: inline-block;
        line-height: 1;
        transform: translateY(0.5px);
    }

    /* Upcoming step: white circle, red outline and red number */
    .step.upcoming .step-circle {
        background: var(--surface);
        border: 1.5px solid var(--accent);
        color: var(--accent);
    }

    /* Active step: red circle with a white number */
    .step.active .step-circle {
        background: var(--accent);
        border: 1.5px solid var(--accent);
        color: #ffffff;
        box-shadow: 0 0 0 3px var(--ring);
        transform: scale(1.06);
    }

    /* Completed step: green circle with a white check mark */
    .step.done .step-circle {
        background: var(--ok);
        border: 1.5px solid var(--ok);
        color: #ffffff;
    }

    /* Smooth check-mark micro-animation on completion */
    .check-icon {
        width: 13px;
        height: 13px;
        animation: check-pop 0.32s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
    }

    @keyframes check-pop {
        0% {
            transform: scale(0.5) rotate(-12deg);
            opacity: 0;
        }
        70% {
            transform: scale(1.2) rotate(2deg);
        }
        100% {
            transform: scale(1) rotate(0deg);
            opacity: 1;
        }
    }

    /* Title of the active step next to the graphic */
    .step-active-label {
        min-width: 0;
        flex: 1;
        overflow: hidden;
        display: flex;
        align-items: center;
    }

    .active-label-text {
        font-size: 0.83rem;
        font-weight: 600;
        color: var(--ink);
        letter-spacing: -0.01em;
        line-height: 1.2;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* Visually hidden text for screen readers */
    .sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    /* Respect the user's reduced-motion preference */
    @media (prefers-reduced-motion: reduce) {
        .step-circle,
        .connector-fill,
        .check-icon {
            transition: none !important;
            animation: none !important;
        }
    }
</style>
