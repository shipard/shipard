<script>
  // Součty dokladu (#139 D48, D52, D55): rekapitulace DPH vlevo (sdílená
  // `VatRecapTable` v kompaktní podobě, #150 D86) a souhrn Základ / DPH / Zaokrouhlení /
  // Celkem vpravo — tvar detailu, sdílený s review (task 2 #139). Jeden
  // tvar dat z adaptéru (`documentParts.js`): hotové texty i s měnou,
  // zaokrouhlení jen když je (null = řádek chybí), `domestic` = přepočet
  // v domácí měně (meta šedě). Popisek řádku součtu je „Celkem“, ne
  // „Celkem k úhradě“ (D55) — u pokladního dokladu, zaplacené faktury nebo
  // interního dokladu by „k úhradě“ bylo zavádějící.
  //
  // Review přidává snippety: `before()` = zdroj čísel (převzaté /
  // přepočtené) v roli meta nad rekapitulací, `rate(r)` = sazba s kódem
  // DPH a dvojicí přenesené povinnosti místo výchozího `r.rate` (řádek
  // rekapitulace smí nést vlastní data pro snippet — `DocTotals` je
  // nečte), `after()` = `Notice` warning při nesouladu s částkou
  // dodavatele pod souhrnem. `totalTestId` → `data-testid` na řádku Celkem
  // (review `review-total`, čte ho proklikání sady #103). CSS přenesené
  // z `DocumentDetail` beze změny vzhledu; `max-width` rekapitulace
  // (26.25rem) a `min-width` souhrnu (13.75rem) jsou v rem (#133 D46).
  import { t } from '../../i18n/index.js';
  import VatRecapTable from './VatRecapTable.svelte';

  let {
    totals = null,
    totalTestId = undefined,
    before = undefined,
    rate = undefined,
    after = undefined,
  } = $props();

  const DASH = '—';

  let recap = $derived(totals?.recap ?? []);
  let summary = $derived(totals?.summary ?? null);
  let domestic = $derived(totals?.domestic ?? null);
</script>

{#if recap.length > 0 || summary || before || after}
  <section class="shpd-doc-totals">
    {#if before}
      <div class="shpd-doc-totals__before">{@render before()}</div>
    {/if}
    <div class="shpd-doc-totals__row">
      {#if recap.length > 0}
        <div class="shpd-doc-totals__recap">
          <VatRecapTable rows={recap} {rate} />
        </div>
      {/if}
      {#if summary}
        <div class="shpd-doc-totals__summary">
          <div>{t('document.totals.base')}: <strong>{summary.base ?? DASH}</strong></div>
          <div>{t('document.totals.vat')}: <strong>{summary.vat ?? DASH}</strong></div>
          {#if summary.rounding}
            <div>{t('document.totals.rounding')}: <strong>{summary.rounding}</strong></div>
          {/if}
          <div class="shpd-doc-totals__total" data-testid={totalTestId}>
            {t('document.totals.total')}: <strong>{summary.total ?? DASH}</strong>
          </div>
          {#if domestic}
            <div class="shpd-doc-totals__domestic">{domestic}</div>
          {/if}
        </div>
      {/if}
    </div>
    {#if after}
      <div class="shpd-doc-totals__after">{@render after()}</div>
    {/if}
  </section>
{/if}

<style>
  .shpd-doc-totals {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
    font-size: var(--shpd-font-size-body);
  }

  /* Rekapitulace vlevo, souhrn vpravo; na úzké šířce se zalomí pod sebe. */
  .shpd-doc-totals__row {
    display: flex;
    justify-content: space-between;
    gap: var(--shpd-space-md);
    align-items: flex-end;
    flex-wrap: wrap;
  }

  /* Obal rekapitulace nese šířku flex položky; min-width: 0, aby se
     tabulka (`VatRecapTable`) při nedostatku místa posouvala v obalu
     TableScroll, ne přetékala (D44). */
  .shpd-doc-totals__recap {
    max-width: 26.25rem;
    flex: 1 1 17.5rem;
    min-width: 0;
  }

  .shpd-doc-totals__summary {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    text-align: right;
    min-width: 13.75rem;
    font-variant-numeric: tabular-nums;
  }

  /* Jedna tučná úroveň: <strong> má výchozích 700, tady vždy token. */
  .shpd-doc-totals__summary strong {
    font-weight: var(--shpd-font-weight-strong);
  }

  /* Řádek Celkem — title, čára nad (§12 Součty). */
  .shpd-doc-totals__total {
    border-top: 1px solid var(--shpd-color-border);
    padding-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-title);
  }

  /* Přepočet v domácí měně — doplněk, meta šedě. */
  .shpd-doc-totals__domestic {
    font-size: var(--shpd-font-size-meta);
    color: var(--shpd-color-text-secondary);
  }
</style>
