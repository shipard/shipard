<script>
  // Formulářová komponenta `docVatRecap` (#150 D86): rekapitulace DPH
  // v tabu formuláře dokladu — rozšířená podoba sdílené `VatRecapTable`
  // (sazba, %, měna, základ, DPH, celkem; u cizí měny i řádky v domácí
  // měně). Params skládá `DocsHeadsFormBase::buildRecapParams()`:
  // `{currency, homeCurrency, rows, totals}`, hodnoty jsou hotové texty
  // z backendu — frontend částky neformátuje. Prázdnou rekapitulaci
  // komponenta nedostane (backend místo ní pošle blok `empty`).
  import VatRecapTable from '../document/VatRecapTable.svelte';

  let { params = {} } = $props();
</script>

<VatRecapTable
  extended
  rows={params.rows ?? []}
  currency={params.currency ?? ''}
  homeCurrency={params.homeCurrency ?? null}
  totals={params.totals ?? null}
/>
