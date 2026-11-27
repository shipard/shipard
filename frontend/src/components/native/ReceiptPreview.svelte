<script>
  // Náhled účtenky (#177 U13): proužek papíru z výsledku `decodeEscPos` —
  // neproporcionální písmo, šířka podle počtu znaků tiskárny, značky toho,
  // co se nedá napsat (ořez, QR, šuplík, neznámý příkaz), jako popisky.
  //
  // Sdílí ho panel simulace v aplikaci i stránka Most ve vývojářském
  // dashboardu, proto nic neimportuje: texty značek dává volající v `labels`
  // (aplikace z `t()`, dashboard ze svého slovníku).
  let {
    /** Výsledek `decodeEscPos`: `{lines, marks}`. */
    decoded,
    /** Znaků na řádek — šířka proužku. */
    columns = 48,
    /** Popisky značek `{cut, qr, drawer, unknown}`. */
    labels = {},
  } = $props();

  const text = $derived({ cut: 'cut', qr: 'QR', drawer: 'drawer', unknown: 'unknown command', ...labels });

  // Řádky a značky v pořadí tisku: značka s `at` patří před řádek s tímto indexem.
  const items = $derived.by(() => {
    const lines = decoded?.lines ?? [];
    const marks = decoded?.marks ?? [];
    const out = [];
    for (let index = 0; index <= lines.length; index++) {
      for (const mark of marks) {
        if (mark.at === index) out.push({ type: 'mark', mark });
      }
      if (index < lines.length) out.push({ type: 'line', line: lines[index] });
    }
    return out;
  });

  function markDetail(mark) {
    if (mark.kind === 'qr') return mark.data?.content ?? '';
    if (mark.kind === 'unknown') return mark.data?.hex ?? '';
    return '';
  }
</script>

<div class="shpd-receipt" style:--receipt-columns={columns} data-testid="receipt-preview">
  {#each items as item, index (index)}
    {#if item.type === 'line'}
      <div
        class="shpd-receipt__line shpd-receipt__line--{item.line.align}"
        class:shpd-receipt__line--bold={item.line.bold}
        style:font-size="{item.line.size.h}em"
        style:width={item.line.size.w !== item.line.size.h ? `${(100 * item.line.size.h) / item.line.size.w}%` : null}
        style:transform={item.line.size.w !== item.line.size.h ? `scaleX(${item.line.size.w / item.line.size.h})` : null}
      >{item.line.text === '' ? ' ' : item.line.text}</div>
    {:else}
      <div class="shpd-receipt__mark shpd-receipt__mark--{item.mark.kind}">
        <span class="shpd-receipt__mark-label">{item.mark.kind === 'cut' ? '✂ ' : ''}{text[item.mark.kind] ?? item.mark.kind}</span>
        {#if markDetail(item.mark) !== ''}
          <span class="shpd-receipt__mark-detail">{markDetail(item.mark)}</span>
        {/if}
      </div>
    {/if}
  {/each}
</div>

<style>
  .shpd-receipt {
    box-sizing: content-box;
    width: calc(var(--receipt-columns) * 1ch);
    max-width: 100%;
    padding: var(--shpd-space-md);
    overflow-x: auto;
    border: 1px solid var(--shpd-color-border-strong);
    border-radius: var(--shpd-radius-sm);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text);
    font-family: var(--shpd-font-family-mono);
    font-size: var(--shpd-font-size-body);
    line-height: 1.35;
    box-shadow: var(--shpd-shadow-sm);
  }

  .shpd-receipt__line {
    white-space: pre;
    transform-origin: left center;
  }

  .shpd-receipt__line--center { text-align: center; }
  .shpd-receipt__line--right { text-align: right; }
  .shpd-receipt__line--bold { font-weight: var(--shpd-font-weight-strong); }

  /* Značka: popisek ve vlastním písmu rozhraní, ne jako text účtenky. */
  .shpd-receipt__mark {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-xs) var(--shpd-space-sm);
    justify-content: center;
    margin: var(--shpd-space-xs) 0;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border: 1px dashed var(--shpd-color-border-strong);
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text-secondary);
    font-family: var(--shpd-font-family);
    font-size: var(--shpd-font-size-meta);
  }

  .shpd-receipt__mark--cut {
    border-width: 1px 0 0;
    border-radius: 0;
  }

  .shpd-receipt__mark--unknown {
    border-color: var(--shpd-color-warning-text);
    color: var(--shpd-color-warning-text);
  }

  .shpd-receipt__mark-label { font-weight: var(--shpd-font-weight-strong); }

  .shpd-receipt__mark-detail {
    font-family: var(--shpd-font-family-mono);
    overflow-wrap: anywhere;
  }
</style>
