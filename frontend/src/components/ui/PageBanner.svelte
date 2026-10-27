<script>
  // Pruh nad obsahem (#137 D41): jen ke čtení, kopie reálných dat, cron
  // vypnutý, pojistka pošty. Je to jiný prvek než hláška `Notice`: celá
  // šířka, linka 2 px dole, bez zaoblení a bez okraje — jedna komponenta
  // místo dřívějších čtyř kopií CSS, vzhled beze změny. Sticky pozici drží
  // kontejner `.shpd-content__banners` v ContentArea, ne pruh sám.
  // `tone` `warning` (výchozí) | `info` | `danger` (tokeny `alert-error-*`)
  // pro budoucí pruhy; text dává volající (`children`), atributy
  // (`data-testid`) jdou na kořen, `role="status"` je výchozí.
  let { tone = 'warning', role = 'status', children, ...rest } = $props();

  const TONES = ['warning', 'info', 'danger'];
  let safeTone = $derived(TONES.includes(tone) ? tone : 'warning');
</script>

<div class="shpd-page-banner shpd-page-banner--{safeTone}" {role} {...rest}>
  {@render children?.()}
</div>

<style>
  .shpd-page-banner {
    padding: var(--shpd-space-xs) var(--shpd-space-lg);
    border-bottom: 2px solid transparent;
    font-size: var(--shpd-font-size-body);
    font-weight: var(--shpd-font-weight-strong);
    text-align: center;
  }

  .shpd-page-banner--warning {
    background: var(--shpd-color-alert-warning-bg);
    color: var(--shpd-color-alert-warning-text);
    border-bottom-color: var(--shpd-color-alert-warning-bar);
  }

  .shpd-page-banner--info {
    background: var(--shpd-color-alert-info-bg);
    color: var(--shpd-color-alert-info-text);
    border-bottom-color: var(--shpd-color-alert-info-bar);
  }

  .shpd-page-banner--danger {
    background: var(--shpd-color-alert-error-bg);
    color: var(--shpd-color-alert-error-text);
    border-bottom-color: var(--shpd-color-alert-error-bar);
  }
</style>
