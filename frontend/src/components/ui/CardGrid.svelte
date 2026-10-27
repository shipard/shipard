<script>
  // Mřížka karet (#137 D34): `repeat(auto-fill, minmax(min(<šířka>, 100%), 1fr))`
  // s mezerou md. Šířku sloupce určuje použití (`minWidth`: Přehled firmy
  // 20 rem, feed 22,5 rem); `min()` uvnitř `minmax` nechá sloupec na úzké
  // šířce smrsknout pod `minWidth` — bez něj by karta přetekla. Karty
  // v řádku jsou vždy stejně vysoké (`align-items: stretch`), obsah drží
  // nahoře flex sloupec karty. Roztáhne se jen přímá položka mřížky — kde
  // mezi mřížkou a `Card` musí být obal (komponenta s vlastním kořenem),
  // obal i karta dostanou `height: 100%`, jinak se výška v řádku nesrovná.
  // Pravidla: docs/design-system.md §8 Karty.
  let { minWidth = '20rem', children } = $props();
</script>

<div class="shpd-card-grid" style:--card-min={minWidth}>
  {@render children?.()}
</div>

<style>
  .shpd-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(var(--card-min, 20rem), 100%), 1fr));
    gap: var(--shpd-space-md);
    align-items: stretch;
  }
</style>
