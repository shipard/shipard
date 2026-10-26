<script>
  // Karta v obsahu (#137 D33–D35): jedna sdílená komponenta pro kartu
  // Přehledu firmy, feedu dashboardu, stránek Nastavení, stran dokladu,
  // návrhu z pošty i náhledu importu — rámeček `--shpd-color-border`, pozadí
  // plochy, zaoblení md, odsazení md (`spacious` = lg u karet stránek
  // v Nastavení). Stín nemá: rámeček + zaoblení md = karta v obsahu,
  // zaoblení lg + stín = plovoucí plocha (Modal, Popover, StatusScreen),
  // která kartou není (D35). Vnější okraj také ne — mezery dává rodič
  // nebo `CardGrid`.
  //
  // Nadpis karty je `SectionHeading` (`title`, `headingLevel` 2–4); snippet
  // `aside` sedí vpravo v řádku nadpisu a bez `title` se nekreslí. Tón
  // `warning` / `danger` mění jen barvu rámečku, `info` dává tónované pozadí
  // (AI shrnutí). Kořen je flex sloupec, aby v roztaženém řádku mřížky
  // zůstal obsah nahoře a případná patička mohla dostat `margin-top: auto`;
  // `position: relative` nese stavový proužek karty feedu. Vnitřní
  // rozložení (řádek ikona + tělo) patří do obsahu, ne na kořen — rodič
  // kořen přes vlastní třídu nestyluje, co karta potřebuje, je prop. Zbylé
  // atributy (`data-testid`, `role`, `aria-*`, `class` se stavovou třídou
  // feedu) jdou na kořen. Pravidla: docs/design-system.md §8 Karty.
  import SectionHeading from './SectionHeading.svelte';

  let {
    title = '',
    headingLevel = 3,
    aside = undefined,
    tone = 'default',
    spacious = false,
    as = 'section',
    class: extraClass = '',
    children,
    ...rest
  } = $props();

  const TONES = ['default', 'warning', 'danger', 'info'];
  let safeTone = $derived(TONES.includes(tone) ? tone : 'default');
</script>

<svelte:element
  this={as}
  class="shpd-card shpd-card--{safeTone} {extraClass}"
  class:shpd-card--spacious={spacious}
  {...rest}
>
  {#if title}
    <SectionHeading level={headingLevel} {aside}>{title}</SectionHeading>
  {/if}
  {@render children?.()}
</svelte:element>

<style>
  .shpd-card {
    position: relative;
    display: flex;
    flex-direction: column;
    box-sizing: border-box;
    min-width: 0;
    padding: var(--shpd-space-md);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text);
  }

  .shpd-card--spacious {
    padding: var(--shpd-space-lg);
  }

  /* Tón: warning / danger jen barva rámečku, info tónované pozadí. Jen
     tokeny — tmavý vzhled je přebarví sám. */
  .shpd-card--warning {
    border-color: var(--shpd-color-warning);
  }

  .shpd-card--danger {
    border-color: var(--shpd-color-danger);
  }

  .shpd-card--info {
    background: var(--shpd-color-primary-soft);
    border-color: var(--shpd-color-primary-soft-2);
  }
</style>
