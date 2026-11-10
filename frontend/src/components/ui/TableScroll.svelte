<script>
  // Obal tabulky (#133 D44, D57). Stránka ani panel nikdy nerolují do
  // strany — posune se tabulka ve svém obalu. Žádný vzhled navíc: čáry
  // a odsazení dává tabulka, mezery rodič. Dva druhy tabulek:
  //
  // - **Tabulka v toku obsahu** (detail, nastavení, dialogy, chat,
  //   vývojářský dashboard): `<TableScroll>` — posun jen do strany, dolů
  //   roluje stránka nebo panel, hlavička nedrží.
  // - **Datová mřížka s držící hlavičkou** (report, sub-tabulka formuláře):
  //   `<TableScroll fill>` — obal zabere nejvýš zbytek výšky své oblasti
  //   a roluje v obou osách, sticky hlavička drží vůči němu. Seznam vieweru
  //   a prohlížeč tabulek mají týž vzor s vlastním obalem (`ViewerGrid`,
  //   `TableBrowser` — na obalu jim visí infinite scroll).
  //
  // Proč dva: prvek s `overflow-x: auto` má spočítané i `overflow-y: auto`
  // — je rolovací oblastí, která svisle neroluje, a `position: sticky`
  // uvnitř se drží jeho okraje, ne okraje stránky. Obal s posunem jen do
  // strany tedy držící hlavičku ruší; mřížka musí rolovat celá v obalu.
  //
  // `fill` potřebuje rodiče jako flex sloupec s určenou výškou (každý
  // článek řetězu `flex: 1; min-height: 0` nebo výška, ne `min-height`) —
  // jinak obal naroste s obsahem a roluje zase stránka. Obal neroste nad
  // výšku tabulky (`flex: 0 1 auto`): krátká tabulka má pod sebou hned
  // další obsah (zprávy reportu), dlouhá se smrští na zbytek a roluje.
  //
  // Bez `tabindex`: posuvný obal bez fokusovatelného obsahu dělá
  // fokusovatelným prohlížeč sám (Chromium, Firefox) a obal s `tabindex`
  // by přidal zastávku tabulátoru ke každé tabulce. Volitelný `label`
  // dává obalu `role="region"` a `aria-label` — pro tabulku bez vlastního
  // nadpisu sekce.
  let { label = '', fill = false, children } = $props();
</script>

<div
  class="shpd-table-scroll"
  class:shpd-table-scroll--fill={fill}
  role={label ? 'region' : undefined}
  aria-label={label || undefined}
>
  {@render children?.()}
</div>

<style>
  .shpd-table-scroll {
    max-width: 100%;
    overflow-x: auto;
  }

  .shpd-table-scroll--fill {
    flex: 0 1 auto;
    min-height: 0;
    overflow: auto;
  }
</style>
