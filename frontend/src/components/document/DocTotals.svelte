<script>
  // Součty dokladu (#139 D48, D52, D55): rekapitulace DPH vlevo (tabulka
  // v obalu `TableScroll`, D44) a souhrn Základ / DPH / Zaokrouhlení /
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
  // z `DocumentDetail` beze změny vzhledu; `max-width: 420px` rekapitulace
  // a `min-width: 220px` souhrnu zůstávají v px — D46 je #138 task 4.
  import { t } from '../../i18n/index.js';
  import TableScroll from '../ui/TableScroll.svelte';

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
          <TableScroll>
            <table class="shpd-doc-totals__table">
              <thead>
                <tr>
                  <th>{t('document.section.recap')}</th>
                  <th class="num">{t('document.totals.base')}</th>
                  <th class="num">{t('document.totals.vat')}</th>
                  <th class="num">{t('document.totals.total')}</th>
                </tr>
              </thead>
              <tbody>
                {#each recap as r (r.key)}
                  <tr>
                    <td>{#if rate}{@render rate(r)}{:else}{r.rate ?? DASH}{/if}</td>
                    <td class="num">{r.base ?? DASH}</td>
                    <td class="num">{r.tax ?? DASH}</td>
                    <td class="num">{r.total ?? DASH}</td>
                  </tr>
                {/each}
              </tbody>
            </table>
          </TableScroll>
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

  /* Obal rekapitulace nese šířku flex položky (dřív na tabulce);
     min-width: 0, aby se tabulka při nedostatku místa posouvala v obalu
     TableScroll, ne přetékala (D44). */
  .shpd-doc-totals__recap {
    max-width: 420px;
    flex: 1 1 280px;
    min-width: 0;
  }

  .shpd-doc-totals__table {
    width: 100%;
    border-collapse: collapse;
  }

  .shpd-doc-totals__table th,
  .shpd-doc-totals__table td {
    padding: 0.375rem var(--shpd-space-xs);
    border-bottom: 1px solid var(--shpd-color-border);
    text-align: left;
  }

  /* Hlavička sloupce — body + strong: stejná velikost jako řádky, silnější,
     barvou textu, bez verzálek (není heading); čára pod ní silnější než
     mezi řádky. Rekapitulace se nezvýrazňuje (§12 Součty). */
  .shpd-doc-totals__table th {
    font-size: var(--shpd-font-size-body);
    font-weight: var(--shpd-font-weight-strong);
    color: var(--shpd-color-text);
    border-bottom-color: var(--shpd-color-border-strong);
  }

  /* Čísla vpravo; buňky rekapitulace se nelámou — v úzkém obalu se
     tabulka posune (TableScroll, D44), ne rozpadne na dva řádky. */
  .shpd-doc-totals__table .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  .shpd-doc-totals__table th,
  .shpd-doc-totals__table td {
    white-space: nowrap;
  }

  .shpd-doc-totals__summary {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    text-align: right;
    min-width: 220px;
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
