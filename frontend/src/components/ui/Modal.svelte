<script lang="ts" module>
  // Modul-level stack otevřených modalů. Esc reaguje pouze na ten poslední (nejvýše
  // vykreslený) modal — jinak by se např. v subdialogu Kontaktu nad Osobou Esc trefil
  // do obou window listenerů a zavřel by oba.
  const modalStack: symbol[] = [];

  function isTopOfStack(id: symbol): boolean {
    return modalStack[modalStack.length - 1] === id;
  }

  function pushModal(id: symbol): number {
    modalStack.push(id);
    return modalStack.length - 1;
  }

  function removeModal(id: symbol): void {
    const i = modalStack.indexOf(id);
    if (i !== -1) modalStack.splice(i, 1);
  }

  /** Je otevřený aspoň jeden modal? Pro Esc koordinaci non-modálních
   *  vrstev (ViewerDetailDrawer) — drawer na Esc nereaguje, dokud je
   *  nad ním modal. Jediný zdroj pravdy je tento stack. */
  export function isModalOpen(): boolean {
    return modalStack.length > 0;
  }

  /** Esc události, které už v tomto stisku zavřely nějaký modal. Prohlížeč mezi
   *  jednotlivými listenery uživatelské události provádí microtask checkpoint,
   *  takže Svelte stihne vyrenderovat modal otevřený prvním listenerem
   *  (ConfirmDialog „Neuložené změny“ z FormDialog) a zapsat ho na vrchol stacku
   *  dřív, než přijde na řadu jeho vlastní window listener — ten by ho týmž Esc
   *  hned zase zavřel. Jeden stisk Esc = jeden zavřený modal. */
  const consumedEscapes = new WeakSet<KeyboardEvent>();
</script>

<script lang="ts">
  import { type Snippet } from 'svelte';
  import { t } from '../../i18n/index.js';

  interface Props {
    title: string;
    open: boolean;
    onClose: () => void;
    children: Snippet;
    /** Optional second header line, rendered below the title in a smaller,
     *  lighter style. Useful for record identifiers (e.g. „IČO 68253848 ·
     *  Kód osoby TEST-0098"). When provided, `headerExtra` (typicky badge)
     *  se posune na začátek subtitle řádku — tvoří „kontext záznamu" cluster
     *  (stav + identifikace). Bez subtitle zůstává headerExtra vedle titulky. */
    subtitle?: Snippet;
    /** Optional content rendered in the header between title and close button.
     *  Useful for badges or other status indicators. Pozice závisí na `subtitle`:
     *  bez subtitle vpravo od titulky, se subtitle inline před subtitle textem. */
    headerExtra?: Snippet;
    /** Optional icon block rendered on the far left of the header. Snippet má
     *  zodpovědnost vrendrovat ikonu (typicky `<Icon icon={...} />`); Modal jen
     *  poskytuje wrapper s pevnou velikostí a vertikálním vystředěním na celou
     *  výšku hlavičky. */
    iconSlot?: Snippet;
    /** Optional right-aligned summary block (např. shrnutí cen u dokladů).
     *  Snippet musí emitovat páry sourozenců `<label-element><value-element>`
     *  — Modal je rozloží do 2-sloupcového gridu (label vpravo zarovnán,
     *  value tučně). Renderuje se mezi `headerExtra` a `×`. */
    summary?: Snippet;
    /** Optional content rendered in a footer strip below the body.
     *  Useful for action buttons (Save, Cancel, etc.). */
    footer?: Snippet;
    /** Modal width, e.g. '720px' or '1200px'. Default '640px'.
     *  Special keyword `'full'` expands to 95vw × 95vh (no `height` needed). */
    width?: string;
    /** Modal height. If set, the card uses this fixed height (capped at 90vh).
     *  If not set, the card sizes to its content (max 90vh). Ignored when
     *  `width === 'full'` (which forces 95vh). */
    height?: string;
    /** Volitelný `data-testid` na kartě modalu (video-runner, smoke E2E). */
    testid?: string;
    /** Vyjme kartu z depth-shrinku vnořených modalů. Pro malé dialogy
     *  (ConfirmDialog, 480 px), které by po zmenšení o 60 px na každou
     *  hloubku přestaly být použitelné; rodič pod nimi vyčnívá i tak. */
    fixedSize?: boolean;
    /** Tělo bez odsazení — obsah sahá až k okrajům karty. Pro dialogy
     *  s vlastním rozvržením na celou plochu (formulář, detail záznamu,
     *  review, průvodce), které si odsazení řeší po sekcích. Výchozí
     *  (false) = tělo má padding `--shpd-space-lg`, vodorovně lícuje
     *  s hlavičkou a patičkou; obsah malých dialogů si ho nenese sám. */
    flush?: boolean;
    /** Navigační ovládání v hlavičce (šipky Předchozí/Další sub-záznamu,
     *  FormDialog `navigation`), renderované mezi `summary` a `×`. Na mobilu
     *  zůstává viditelné — na rozdíl od summary. */
    headerNav?: Snippet;
    /** Volitelný handler kláves. Modal ho volá jen když je tato karta na
     *  vrcholu stacku (stejné pravidlo jako Esc), takže vnořený dialog
     *  neposílá klávesy rodiči. Esc zůstává v režii Modalu. */
    onKeydown?: (e: KeyboardEvent) => void;
  }

  let {
    title,
    open,
    onClose,
    children,
    subtitle,
    headerExtra,
    iconSlot,
    summary,
    footer,
    width = '640px',
    height,
    testid,
    fixedSize = false,
    flush = false,
    headerNav,
    onKeydown,
  }: Props = $props();

  // Unikátní ID této instance modalu — slouží k identifikaci ve stacku.
  const modalId = Symbol('modal');

  // Klávesy jen pro modal na vrcholu stacku: Esc zavírá, ostatní dostane
  // volající přes `onKeydown` (FormDialog: Alt+←/→ Předchozí/Další).
  function handleKeydown(e: KeyboardEvent) {
    if (!isTopOfStack(modalId)) return;
    if (e.key === 'Escape') {
      if (consumedEscapes.has(e)) return;
      consumedEscapes.add(e);
      onClose();
      return;
    }
    onKeydown?.(e);
  }

  // Close when clicking the overlay but not the card
  function handleOverlayClick(e: MouseEvent) {
    if (e.target === e.currentTarget) onClose();
  }

  // Hloubka ve stacku (0 = nejnižší/rodičovský, 1+ = vnořený). Používá se pro
  // postupné zmenšení vnořených modalů, aby uživatel viděl rodičovský modal
  // vyčnívat ze všech stran a vizuálně chápal hierarchii.
  let depth = $state(0);

  // Registrace ve stacku + body scroll lock při otevření; cleanup při zavření.
  $effect(() => {
    if (open) {
      depth = pushModal(modalId);
      document.body.style.overflow = 'hidden';
      return () => {
        removeModal(modalId);
        // Body scroll obnovíme jen pokud už není otevřený žádný další modal.
        if (modalStack.length === 0) {
          document.body.style.overflow = '';
        }
      };
    }
  });

  // Side offset (px na každé straně) pro vnořený modal. 30px x depth → vnořený
  // je o 60px užší/nižší než rodič a vycentrovaný → rodič vykřukuje ze všech stran.
  // Inline max-width/max-height musí přepsat CSS pravidla (max-width: 100vw-2*lg,
  // max-height: 90vh), jinak by se na úzkém/nízkém viewportu obě hloubky capnuly na
  // stejnou hodnotu a depth-shrink by nebyl vidět.
  const cardStyle = $derived.by(() => {
    const off = fixedSize ? 0 : depth * 30;
    if (width === 'full') {
      return `width: calc(95vw - ${off * 2}px); max-width: calc(95vw - ${off * 2}px); height: calc(95vh - ${off * 2}px); max-height: calc(95vh - ${off * 2}px);`;
    }
    if (height) {
      return `width: calc(${width} - ${off * 2}px); max-width: calc(100vw - var(--shpd-space-lg) * 2 - ${off * 2}px); height: calc(${height} - ${off * 2}px); max-height: calc(90vh - ${off * 2}px);`;
    }
    return `width: calc(${width} - ${off * 2}px); max-width: calc(100vw - var(--shpd-space-lg) * 2 - ${off * 2}px);`;
  });
</script>

<svelte:window onkeydown={open ? handleKeydown : undefined} />

{#if open}
  <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
  <div class="shpd-modal" onclick={handleOverlayClick} role="dialog" aria-modal="true" aria-label={title} tabindex="-1">
    <div class="shpd-modal__card" style={cardStyle} data-testid={testid}>
      <div class="shpd-modal__header">
        {#if iconSlot}
          <div class="shpd-modal__header-icon">
            {@render iconSlot()}
          </div>
        {/if}
        <div class="shpd-modal__header-main">
          <span class="shpd-modal__title">{title}</span>
          {#if subtitle}
            <div class="shpd-modal__subtitle-row">
              {#if headerExtra}
                <span class="shpd-modal__header-extra shpd-modal__header-extra--inline">
                  {@render headerExtra()}
                </span>
              {/if}
              <span class="shpd-modal__subtitle">{@render subtitle()}</span>
            </div>
          {/if}
        </div>
        {#if !subtitle && headerExtra}
          <span class="shpd-modal__header-extra">
            {@render headerExtra()}
          </span>
        {/if}
        {#if summary}
          <div class="shpd-modal__header-summary">
            {@render summary()}
          </div>
        {/if}
        {#if headerNav}
          <div class="shpd-modal__header-nav">
            {@render headerNav()}
          </div>
        {/if}
        <button class="shpd-modal__close" onclick={onClose} aria-label={t('common.close')}>×</button>
      </div>
      <div class="shpd-modal__body" class:shpd-modal__body--padded={!flush}>
        {@render children()}
      </div>
      {#if footer}
        <div class="shpd-modal__footer">
          {@render footer()}
        </div>
      {/if}
    </div>
  </div>
{/if}

<style>
  .shpd-modal {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--shpd-color-overlay);
    animation: shpd-modal-fade-in 0.15s ease;
  }

  @keyframes shpd-modal-fade-in {
    from { opacity: 0; }
    to   { opacity: 1; }
  }

  .shpd-modal__card {
    position: relative;
    max-width: calc(100vw - var(--shpd-space-lg) * 2);
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    background: var(--shpd-color-bg);
    border-radius: var(--shpd-radius-lg);
    box-shadow: var(--shpd-shadow-lg);
    /* Clip dětských elementů podle zaoblených rohů — jinak vlastní pozadí
       FormEditor / FormStateBar přetéká přes spodní zaoblení. */
    overflow: hidden;
    /* min-height needed for flex children to overflow correctly */
    min-height: 0;
  }

  .shpd-modal__header {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-sm) var(--shpd-space-lg);
    border-bottom: 1px solid var(--shpd-color-border);
    flex-shrink: 0;
  }

  .shpd-modal__header-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    font-size: 1.75em;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-modal__header-main {
    flex: 1;
    /* min-width: 0 je nutné, aby ellipsis fungoval uvnitř flex containeru */
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .shpd-modal__title {
    font-size: var(--shpd-font-size-lg);
    font-weight: 600;
    color: var(--shpd-color-text);
    /* truncate long titles instead of pushing close button off-screen */
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .shpd-modal__subtitle-row {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    min-width: 0;
  }

  .shpd-modal__subtitle {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    font-weight: 400;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
  }

  .shpd-modal__header-extra {
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
  }

  /* Když je headerExtra na subtitle řádku (forma s HeaderInfo), nemá flex-shrink:0
     bránit elipsování subtitle textu — badge má pevnou velikost přes vlastní obsah. */
  .shpd-modal__header-extra--inline {
    flex-shrink: 0;
  }

  .shpd-modal__header-summary {
    display: grid;
    grid-template-columns: max-content max-content;
    column-gap: var(--shpd-space-sm);
    row-gap: 2px;
    align-items: baseline;
    flex-shrink: 0;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  /* Kontrakt: snippet emituje páry <label><value>. Lichý sourozenec = label
     (vpravo zarovnaný, sekundární barva), sudý = value (tučný, primární barva).
     :global() je nutné — elementy v summary snippetu vlastní volající komponenta
     (FormDialog), ne Modal, takže Svelte CSS scoping by je jinak minul. */
  .shpd-modal__header-summary > :global(:nth-child(odd)) {
    text-align: right;
    white-space: nowrap;
  }

  .shpd-modal__header-summary > :global(:nth-child(even)) {
    text-align: right;
    font-weight: 600;
    color: var(--shpd-color-text);
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
  }

  /* Navigace v hlavičce (‹ 2 / 12 ›) — kompaktní cluster před křížkem. */
  .shpd-modal__header-nav {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    flex-shrink: 0;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }

  .shpd-modal__close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    background: none;
    border: none;
    border-radius: var(--shpd-radius-sm);
    font-size: 1.25rem;
    line-height: 1;
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease;
    flex-shrink: 0;
  }

  .shpd-modal__close:hover {
    background-color: var(--shpd-color-bg-hover);
    color: var(--shpd-color-text);
  }

  .shpd-modal__body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
  }

  /* Výchozí odsazení obsahu (bez `flush`). Okraj u hrany drží padding,
     proto krajní děti ztrácejí vnější margin — jinak by se k paddingu
     přičetl (výchozí margin <p>, margin-bottom posledního pole). */
  .shpd-modal__body--padded {
    padding: var(--shpd-space-lg);
  }

  .shpd-modal__body--padded > :global(:first-child) {
    margin-top: 0;
  }

  .shpd-modal__body--padded > :global(:last-child) {
    margin-bottom: 0;
  }

  .shpd-modal__footer {
    padding: var(--shpd-space-md) var(--shpd-space-lg);
    border-top: 1px solid var(--shpd-color-border);
    display: flex;
    justify-content: flex-end;
    gap: var(--shpd-space-sm);
    flex-shrink: 0;
  }

  /* ============================================================
   * Mobilní fullscreen
   * ------------------------------------------------------------
   * Na ≤ 768px je každý modál fullscreen (100vw × 100vh), bez
   * zaoblení a okrajů. Přebíjí inline cardStyle (width/height/
   * max-* počítané z depth a width prop) — proto !important.
   *
   * Breakpoint 768px ladí s MOBILE_BREAKPOINT v layout.svelte.js.
   *
   * Depth-shrink (odsazení vnořených modálů) se tím ruší: všechny
   * hloubky dostanou stejný fullscreen rozměr, vnořený modál
   * překryje rodiče. Stack/Esc/zavírání funguje beze změny.
   * ============================================================ */
  @media (max-width: 768px) {
    .shpd-modal {
      /* Overlay okraje pryč — karta vyplní celou plochu. */
      align-items: stretch;
      justify-content: stretch;
    }

    .shpd-modal__card {
      width: 100vw !important;
      max-width: 100vw !important;
      /* 100dvh (dynamic viewport height) řeší ořez footeru pod adresní
         lištou mobilních prohlížečů; druhá deklarace přebije 100vh tam,
         kde prohlížeč dvh zná, staré ji ignorují a použijí 100vh. */
      height: 100vh !important;
      height: 100dvh !important;
      max-height: 100vh !important;
      max-height: 100dvh !important;
      border-radius: 0 !important;
    }

    /* Summary blok v hlavičce (shrnutí cen u dokladů) se na mobilu
       skrývá — redundantní s obsahem formuláře, hlavička musí zůstat
       kompaktní na úzké obrazovce. */
    .shpd-modal__header-summary {
      display: none;
    }

    /* Footer tlačítka roztáhnout na plnou šířku (lepší pro dotyk).
       Tlačítka emituje volající komponenta přes footer snippet (ne Modal),
       takže scoped CSS by je minul → :global(). Stejný vzor jako
       .shpd-modal__header-summary > :global(...) výše. */
    .shpd-modal__footer {
      justify-content: stretch;
    }

    .shpd-modal__footer > :global(*) {
      flex: 1;
    }
  }
</style>
