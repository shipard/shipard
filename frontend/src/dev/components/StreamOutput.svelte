<script>
  // Výstup streamované akce: řádky z stream.js (`kind` step / error /
  // done / text), barevně, s automatickým rolováním na konec.
  import { t } from '../i18n/index.js';

  let { lines = [] } = $props();

  let el = $state(null);

  $effect(() => {
    // závislost na délce — po každém novém řádku odroluj dolů
    void lines.length;
    if (el) el.scrollTop = el.scrollHeight;
  });
</script>

<pre class="dev-output" bind:this={el} data-empty={t('stream.empty')}>{#each lines as line, i (i)}{#if line.kind === 'step'}<span class="dev-output__step">▶ {line.text}</span>
{:else if line.kind === 'error'}<span class="dev-output__error">✖ {line.text}</span>
{:else if line.kind === 'done'}<span class="dev-output__done">✔ {line.payload?.message ?? line.text}</span>
{:else}{line.text}
{/if}{/each}</pre>
