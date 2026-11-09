<script>
  // Obal tabulky v obsahu (#139 D44): každá tabulka v obsahu detailu,
  // review i stránky má vlastní obal s posunem do strany, vždy — stránka
  // ani panel nikdy nerolují do strany. Žádný vzhled navíc: čáry
  // a odsazení dává tabulka, mezery rodič. Dnes ho mají řádky dokladu
  // a rekapitulace DPH (`DocRows`, `DocTotals`); na ostatní tabulky
  // (obecný typ `table`, sub-tabulky, reporty) ho rozšíří #138 task 3.
  //
  // Bez `tabindex`: posuvný obal bez fokusovatelného obsahu dělá
  // fokusovatelným prohlížeč sám (Chromium, Firefox) a obal s `tabindex`
  // by přidal zastávku tabulátoru ke každé tabulce. Volitelný `label`
  // dává obalu `role="region"` a `aria-label` — pro tabulku bez vlastního
  // nadpisu sekce.
  let { label = '', children } = $props();
</script>

<div class="shpd-table-scroll" role={label ? 'region' : undefined} aria-label={label || undefined}>
  {@render children?.()}
</div>

<style>
  .shpd-table-scroll {
    max-width: 100%;
    overflow-x: auto;
  }
</style>
