<script>
  // Drobečková navigace: [{label, href?}] — poslední bez odkazu.
  import { onLinkClick } from '../router.svelte.js';
  let { items = [] } = $props();
</script>

<div class="dev-breadcrumb">
  {#each items as item, i (i)}
    {#if i > 0}<span>›</span>{/if}
    {#if item.href}
      <a href={item.href} onclick={onLinkClick}>{item.label}</a>
    {:else}
      <span>{item.label}</span>
    {/if}
  {/each}
</div>
