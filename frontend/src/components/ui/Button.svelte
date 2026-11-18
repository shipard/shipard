<script lang="ts">
  import Icon from './Icon.svelte';

  interface Props {
    label?: string;
    icon?: object;
    iconOnly?: boolean;
    variant?: 'primary' | 'secondary' | 'danger' | 'success' | 'ghost';
    /**
     * `md` = běžná výška ovládacího prvku (lišta, patička dialogu, tlačítko
     * vedle pole), `sm` = kompaktní — jen akce v řádku tabulky, na kartě
     * a v hlavičce bloku (#156 D107).
     */
    size?: 'sm' | 'md';
    disabled?: boolean;
    loading?: boolean;
    type?: 'button' | 'submit' | 'reset';
    onclick?: () => void;
    /** Volitelný `data-testid` pro automatizaci (video-runner, smoke E2E). */
    testid?: string;
    /**
     * Dlouhý popisek se smí zalomit (výchozí `false` = jeden řádek). Jen pro
     * tlačítko s proměnným textem v úzkém místě (rychlé založení z registru
     * s názvem firmy): text vlevo, výška podle obsahu, ikona u prvního
     * řádku, nejvýš šířka rodiče. Jednořádkové je stejně vysoké jako bez
     * `wrap`.
     */
    wrap?: boolean;
    /**
     * Tlačítko, které dialog **jen zavírá** (Zavřít / Zrušit — stejný účinek
     * jako × v hlavičce a Esc). Vykreslí značku `data-modal-dismiss`; patička
     * `Modal`u takové tlačítko na telefonu skryje, zavírá × (#155 D116).
     * Žádná vlastní logika kliku. Nepatří na odpověď potvrzovacího dotazu
     * (`ConfirmDialog`) ani na tlačítko, které dělá víc než zavření.
     */
    dismiss?: boolean;
  }

  let {
    label,
    icon = undefined,
    iconOnly = false,
    variant = 'primary',
    size = 'md',
    disabled = false,
    loading = false,
    type = 'button',
    onclick,
    testid,
    wrap = false,
    dismiss = false,
  }: Props = $props();

  const isDisabled = $derived(disabled || loading);
  // Bez popisku je tlačítko čtverec výšky ovládacího prvku (D107) — i když
  // volající `iconOnly` nenastavil a popisek jen nepředal.
  const square = $derived(iconOnly || !label);
</script>

<button
  class="shpd-btn shpd-btn--{variant} shpd-btn--{size}"
  class:shpd-btn--loading={loading}
  class:shpd-btn--icon-only={square}
  class:shpd-btn--wrap={wrap}
  {type}
  data-testid={testid}
  data-modal-dismiss={dismiss ? '' : undefined}
  disabled={isDisabled}
  title={iconOnly ? label : undefined}
  {onclick}
>
  {#if loading}
    <span class="shpd-btn__spinner" aria-hidden="true"></span>
  {:else if icon}
    <Icon {icon} size={size === 'sm' ? 'sm' : 'md'} />
  {/if}
  {#if label && !iconOnly}
    <span class="shpd-btn__label">{label}</span>
  {/if}
</button>

<style>
  /* Výška je token ovládacího prvku, ne součet odsazení a řádku (#156 D106,
   * D107): tlačítko je stejně vysoké jako pole a výběr vedle něj. Svislé
   * odsazení je nulové, obsah centruje flex. Běžné tlačítko má písmo 16 px
   * a vodorovné odsazení `space-lg` (D110). */
  .shpd-btn {
    --shpd-btn-height: var(--shpd-control-height);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-sm);
    box-sizing: border-box;
    height: var(--shpd-btn-height);
    padding: 0 var(--shpd-space-lg);
    border-radius: var(--shpd-radius-md);
    font-size: var(--shpd-font-size-control);
    font-family: var(--shpd-font-family);
    font-weight: 500;
    cursor: pointer;
    border: 1px solid transparent;
    transition: background-color 0.15s ease, border-color 0.15s ease, opacity 0.15s ease;
    white-space: nowrap;
    line-height: 1;
  }

  /* Kompaktní: písmo 14 px (D110), vodorovné odsazení 12 px — `space-sm`
   * lepí text na zaoblený roh. */
  .shpd-btn--sm {
    --shpd-btn-height: var(--shpd-control-height-sm);
    padding: 0 0.75rem;
    font-size: var(--shpd-font-size-control-sm);
  }

  /* Jen ikona: čtverec výšky tlačítka, v řadě se nemačká. */
  .shpd-btn--icon-only {
    width: var(--shpd-btn-height);
    padding: 0;
    flex-shrink: 0;
  }

  .shpd-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  /* Primary */
  .shpd-btn--primary {
    background-color: var(--shpd-color-primary);
    color: var(--shpd-color-text-on-fill);
    border-color: var(--shpd-color-primary);
  }

  .shpd-btn--primary:hover:not(:disabled) {
    background-color: var(--shpd-color-primary-hover);
    border-color: var(--shpd-color-primary-hover);
  }

  /* Secondary. Text a rámeček mají volitelné tokeny s fallbackem: aplikace
   * je nedefinuje — rámeček je rámeček ovládacího prvku (#156 D109) —,
   * vývojářský dashboard je nastavuje v src/dev/theme.css: bílé tlačítko
   * s modrým textem (#129 D1, D4). */
  .shpd-btn--secondary {
    background-color: var(--shpd-color-bg);
    color: var(--shpd-btn-secondary-text, var(--shpd-color-text));
    border-color: var(--shpd-btn-secondary-border, var(--shpd-color-border-control));
  }

  /* Neaktivní prvek nemá rámeček ovládacího prvku (D109). */
  .shpd-btn--secondary:disabled {
    border-color: var(--shpd-btn-secondary-border, var(--shpd-color-border));
  }

  .shpd-btn--secondary:hover:not(:disabled) {
    background-color: var(--shpd-color-bg-hover);
  }

  /* Danger */
  .shpd-btn--danger {
    background-color: var(--shpd-color-danger);
    color: var(--shpd-color-text-on-fill);
    border-color: var(--shpd-color-danger);
  }

  .shpd-btn--danger:hover:not(:disabled) {
    background-color: var(--shpd-color-danger-hover);
    border-color: var(--shpd-color-danger-hover);
  }

  /* Success — použito pro "Přidat / Použít / Potvrdit" akce, kde se chce
   * od uživatele odlišit pozitivní očekávaný výsledek od neutrálního.
   * Záměrně málo častý — většina akcí jsou primary nebo secondary. */
  .shpd-btn--success {
    background-color: var(--shpd-color-success);
    color: var(--shpd-color-text-on-fill);
    border-color: var(--shpd-color-success);
  }

  .shpd-btn--success:hover:not(:disabled) {
    background-color: var(--shpd-color-success-hover);
    border-color: var(--shpd-color-success-hover);
  }

  /* Ghost — bez pozadí, jen ikona/text. Bez popisku čtverec jako každé
   * ikonové tlačítko; s popiskem (Obnovit, Náhled) výška z tokenu a užší
   * odsazení, protože nemá plochu ani rámeček. */
  .shpd-btn--ghost {
    background-color: transparent;
    color: var(--shpd-color-text-secondary);
    border-color: transparent;
  }

  .shpd-btn--ghost:not(.shpd-btn--icon-only) {
    padding-inline: var(--shpd-space-sm);
  }

  .shpd-btn--ghost:hover:not(:disabled) {
    background-color: var(--shpd-color-bg-hover);
    color: var(--shpd-color-text);
  }

  /* Zalamovaný popisek (prop `wrap`, opt-in — výchozí tlačítko zůstává na
   * jednom řádku). Řádkování jako běžný text, text vlevo, nejvýš šířka
   * rodiče. Výška tlačítka je tu minimum, ne pevná hodnota: svislé odsazení
   * doplňuje jeden řádek do výšky ovládacího prvku, takže jednořádkové
   * tlačítko je stejně vysoké jako bez `wrap` a další řádky ho zvyšují.
   * Ikona a spinner sedí na účaří prvního řádku a jsou posunuté jako ikona
   * v textu. */
  .shpd-btn--wrap {
    align-items: baseline;
    justify-content: flex-start;
    min-width: 0;
    max-width: 100%;
    height: auto;
    min-height: var(--shpd-btn-height);
    padding-block: max(0rem, calc((var(--shpd-btn-height) - 1lh) / 2 - 1px)); /* px-ok: rámeček tlačítka */
    white-space: normal;
    line-height: normal;
    text-align: left;
  }

  .shpd-btn--wrap .shpd-btn__label {
    min-width: 0;
    overflow-wrap: anywhere;
  }

  .shpd-btn--wrap :global(.shpd-icon),
  .shpd-btn--wrap .shpd-btn__spinner {
    position: relative;
    top: 0.125em;
  }

  /* Spinner */
  .shpd-btn__spinner {
    display: inline-block;
    width: 0.875rem;
    height: 0.875rem;
    border: 2px solid currentColor;
    border-top-color: transparent;
    border-radius: 50%;
    animation: shpd-spin 0.6s linear infinite;
    flex-shrink: 0;
  }

  @keyframes shpd-spin {
    to { transform: rotate(360deg); }
  }
</style>
