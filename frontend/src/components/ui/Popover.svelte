<script>
  // Universal floating panel anchored to a DOM element. Used by the
  // Exchange resolve-decision flow (Phase 3b) to render decision actions
  // (Create / Pick existing / Skip) next to a clicked status badge.
  //
  // Props:
  //   open       boolean
  //   anchor     HTMLElement | null — element to position relative to
  //   placement  'bottom' | 'top' | 'right' | 'left' (default 'bottom')
  //   onClose    () => void  — fires on Escape or outside click
  //   testid     volitelný `data-testid` kořene panelu (vzor Button / Modal)
  //   children   Svelte 5 children snippet
  //
  // Click-outside is portal-aware: if the outside target is inside a
  // `.shpd-modal` (e.g. a nested FormDialog mounted into body), the click
  // is treated as "inside the popover's logical hierarchy" and ignored.
  // Without this guard, clicking inside the nested dialog would
  // simultaneously close the popover that opened it.

  let {
    open = false,
    anchor = null,
    placement = 'bottom',
    width = null,
    onClose = () => {},
    testid = null,
    children,
  } = $props();

  let panelEl = $state(null);
  let position = $state({ top: 0, left: 0 });
  // max-height v px, když se panel nevejde do viewportu ani na jednu stranu
  // od kotvy; null = bez omezení. Viz reposition().
  let maxHeight = $state(null);

  const GAP = 8; // mezera mezi kotvou a panelem
  const MARGIN = 8; // minimální odstup od okraje viewportu
  const MIN_HEIGHT = 160; // pod tuto výšku panel nestlačujeme

  // Spočítá pozici (a případně max-height) panelu vůči kotvě a viewportu.
  //
  // Volá se při otevření, při změně velikosti panelu (ResizeObserver) a při
  // změně velikosti okna. Obsah popoveru se často načítá asynchronně
  // (např. ResolveDecisionPanel: „Načítám…“ → seznam výsledků), takže
  // jednorázové měření při otevření nestačí — panel po doplnění obsahu
  // doroste přes spodní okraj a flip se nikdy neprovede.
  //
  // Pro 'bottom' / 'top': když se přirozená výška panelu nevejde na
  // požadovanou stranu, flipne se na stranu s větším volným prostorem.
  // Když se nevejde ani tam, dostane panel max-height podle dostupného
  // místa a stane se scrollovatelným (modifikátor --constrained), aby
  // spodní část nebyla useknutá bez možnosti se k ní dostat.
  function reposition() {
    if (!open || !anchor || !panelEl) return;
    const r = anchor.getBoundingClientRect();
    const vh = window.innerHeight;
    const vw = window.innerWidth;

    // Přirozená výška = bez aktuálního omezení. Dočasné vypnutí max-height je
    // synchronní (bez překreslení), ResizeObserver mezistav nevidí.
    panelEl.style.maxHeight = '';
    const pr = panelEl.getBoundingClientRect();
    const natural = pr.height;

    let top;
    let left;
    let maxH = null;

    if (placement === 'bottom' || placement === 'top') {
      const spaceBelow = vh - r.bottom - GAP - MARGIN;
      const spaceAbove = r.top - GAP - MARGIN;
      let side = placement;
      if (side === 'bottom' && natural > spaceBelow && spaceAbove > spaceBelow) side = 'top';
      if (side === 'top' && natural > spaceAbove && spaceBelow > spaceAbove) side = 'bottom';
      const avail = side === 'bottom' ? spaceBelow : spaceAbove;
      if (natural > avail) maxH = Math.max(MIN_HEIGHT, Math.floor(avail));
      const h = maxH === null ? natural : Math.min(natural, maxH);
      top = side === 'bottom' ? r.bottom + GAP : r.top - GAP - h;
      left = r.left;
    } else {
      // 'right' / 'left' — zarovnat horní hranu s kotvou; když přesahuje
      // spodní okraj, posunout nahoru; když je vyšší než viewport, omezit.
      top = r.top;
      left = placement === 'right' ? r.right + GAP : r.left - pr.width - GAP;
      const availTotal = vh - 2 * MARGIN;
      if (natural > availTotal) maxH = Math.max(MIN_HEIGHT, Math.floor(availTotal));
      const h = maxH === null ? natural : Math.min(natural, maxH);
      if (top + h > vh - MARGIN) top = vh - MARGIN - h;
    }

    // Vodorovně držet odstup od okrajů viewportu.
    left = Math.max(MARGIN, Math.min(left, vw - pr.width - MARGIN));
    // Nikdy nad horní okraj.
    top = Math.max(MARGIN, top);

    panelEl.style.maxHeight = maxH === null ? '' : `${maxH}px`;
    maxHeight = maxH;
    position = { top, left };
  }

  // Přepočet při otevření / změně kotvy / umístění + sledování velikosti
  // panelu a okna po dobu otevření.
  $effect(() => {
    if (!open || !anchor || !panelEl) return;
    reposition(); // čte placement → efekt na jeho změnu reaguje
    // Přepočet z ResizeObserveru odložit do rAF — reposition() mění rozměr
    // pozorovaného prvku a synchronní změna v callbacku by vyvolala
    // „ResizeObserver loop completed with undelivered notifications“.
    let raf = 0;
    const ro =
      typeof ResizeObserver !== 'undefined'
        ? new ResizeObserver(() => {
            cancelAnimationFrame(raf);
            raf = requestAnimationFrame(reposition);
          })
        : null;
    ro?.observe(panelEl);
    window.addEventListener('resize', reposition);
    return () => {
      cancelAnimationFrame(raf);
      ro?.disconnect();
      window.removeEventListener('resize', reposition);
    };
  });

  // Document-level click + Escape, only while open.
  function handleDocClick(event) {
    if (!open || !panelEl) return;
    // Inside the panel → ignore.
    if (panelEl.contains(event.target)) return;
    // On the anchor itself (e.g. the kebab button) → ignore here and let the
    // anchor's own click handler decide. Otherwise the capture-phase close
    // would race the button's toggle and the popover couldn't be closed by
    // re-tapping the trigger.
    if (anchor?.contains?.(event.target)) return;
    // Inside a *nested* modal (a FormDialog the popover itself opened, which
    // sits as a separate `.shpd-modal` under body) → ignore, so opening that
    // dialog doesn't close this popover. But a popover that *lives inside* a
    // modal (e.g. the mobile FormStateBar footer in a fullscreen modal) must
    // still close on clicks elsewhere in that same modal — so only ignore a
    // modal that is NOT the popover's own modal.
    const targetModal = event.target?.closest?.('.shpd-modal');
    if (targetModal && targetModal !== anchor?.closest?.('.shpd-modal')) return;
    onClose();
  }

  function handleKey(event) {
    if (event.key === 'Escape' && open) {
      onClose();
    }
  }

  $effect(() => {
    if (!open) return;
    document.addEventListener('click', handleDocClick, true);
    document.addEventListener('keydown', handleKey);
    return () => {
      document.removeEventListener('click', handleDocClick, true);
      document.removeEventListener('keydown', handleKey);
    };
  });
</script>

{#if open}
  <div
    class="shpd-popover"
    class:shpd-popover--constrained={maxHeight !== null}
    bind:this={panelEl}
    style:top="{position.top}px"
    style:left="{position.left}px"
    style:width={width}
    style:max-width={width}
    role="dialog"
    data-testid={testid}
  >
    {@render children?.()}
  </div>
{/if}

<style>
  .shpd-popover {
    position: fixed;
    z-index: 1000;
    min-width: 240px;
    max-width: 360px;
    background: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: 6px;
    box-shadow: var(--shpd-shadow-lg, 0 4px 12px rgba(0, 0, 0, 0.15));
    padding: var(--shpd-space-sm);
    box-sizing: border-box;
  }

  /* Panel omezený max-height (viz reposition()): flex sloupec, aby si
     obsah mohl sám rozhodnout, co zmenšit (např. seznam výsledků), a
     overflow jako záchrana pro obsah, který se zmenšit neumí. Ve
     výchozím stavu zůstává display: block — nemění layout ostatních
     konzumentů popoveru. */
  .shpd-popover--constrained {
    display: flex;
    flex-direction: column;
    overflow-y: auto;
  }
</style>
