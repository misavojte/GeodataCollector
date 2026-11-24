<script lang="ts">
    let { text = '' }: { text?: string } = $props();
</script>

<p class="note">{text}</p>

<style>
    .note {
        display: block;
        margin: 0;
        padding: 0.6rem 0.7rem 0.6rem 2.1rem;
        font-size: 0.82rem;
        line-height: 1.45;
        color: #5a4a1f;
        background: #fff7e6;
        border: 1px solid #f1dca6;
        border-radius: var(--r-sm);
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16'%3E%3Ccircle cx='8' cy='8' r='7' fill='none' stroke='%23c79a2e' stroke-width='1.5'/%3E%3Ccircle cx='8' cy='4.6' r='1' fill='%23c79a2e'/%3E%3Crect x='7.1' y='6.7' width='1.8' height='5.5' rx='.9' fill='%23c79a2e'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: 0.6rem 0.65rem;
    }
</style>
