# Task: Jednotková cena řádku z celkové ceny při záporném množství

**Stav:** naplánováno — zadání 2026-11-10

**Issue:** #98

**Cíl:** Řádek dokladu počítaný **z celkové ceny** (`price_calc_mode` 1) se
záporným množstvím dostane správnou jednotkovou cenu (`−2 × ? = −100` →
cena za jednotku 50), ne nulu. Množství 0 nebo prázdné se nemění.

Priorita: věcná správnost údaje na dokladu (`docs/roadmap.md` → Pravidlo
prioritizace, bod 1). Částky dokladu, rekapitulace ani účtování chybou
zasažené nejsou — počítají se z `total_price`. Špatně je jen cena za
jednotku na řádku, v náhledu návrhu a na tisku. Task je předpokladem
opravných daňových dokladů (#92), kde budou řádky se záporným množstvím
běžné.

## Před implementací přečti

- `modules/docs/core/src/DocRowCalculator.php` — `computePrice()` a úvodní
  komentář třídy (proč `total_price` před slevou a `net_total` po ní)
- `tests/Unit/Module/Docs/Core/DocRowCalculatorTest.php` — stávající
  případy výpočtu z celkové ceny (`testFromTotalPrice*`)
- volající `computePrice()`: `DocDocument::calculateRowPrice()`,
  `DocRowsForm` (přepočet řádku ve formuláři, ~ř. 634), `DocumentApplier`
  (ceny řádků pro náhled, ~ř. 3419–3442)
- `docs/exchange-format.md` — „Co skončí na dokladu“ u přijatého dokladu
  neplátce (~ř. 915–925): applier tam řádky převádí na výpočet z celkové
  ceny (#97), takže přijatý dobropis se záporným množstvím je první místo,
  kde je chyba vidět

## Kontext

```php
$unitPrice = $qty > 0 ? round(($totalPrice ?? 0.0) / $qty, 4) : 0.0;
```

Podmínka `> 0` vznikla jako ochrana před dělením nulou, ale vyřadila
i záporné množství. Jiné místo v kódu jednotkovou cenu nedopočítává
(ověřeno 2026-11-10: backend volá jen `computePrice()`, frontend cenu jen
zobrazuje).

## Co je potřeba udělat

### Commit 1 — oprava a testy

1. `DocRowCalculator::computePrice()`: jednotkovou cenu z celkové ceny
   dopočítat pro **nenulové** množství. Množství 0 a prázdné zůstává
   s cenou 0 (kontační řádky bez množství).
2. Znaménka se neupravují, platí prosté dělení:
   - `−2` ks, celkem `−100` → cena za jednotku `50`
   - `2` ks, celkem `−100` → cena za jednotku `−50` (dnešní chování, jen ho
     test zafixuje)
   - `−2` ks, celkem `100` → cena za jednotku `−50`
3. Testy v `DocRowCalculatorTest`: tři případy výše, množství `0`, `null`
   a prázdný řetězec, zaokrouhlení na 4 místa u záporného množství
   (`−3` ks, celkem `−100` → `33.3333`).
4. Test přes dokument (`DocDocumentRowCalcTest`): řádek z celkové ceny se
   záporným množstvím má po `computeAmounts()` nenulovou `unit_price`
   a nezměněné `total_price`, `vat_base`, `vat_total`.
5. Test applieru pro případ z #97: přijatý dobropis (záporné množství) na
   zdroji neplátce DPH má v náhledu (`_resolve.computed.rows`) i na
   uloženém dokladu nenulové jednotkové ceny. Přidat k existujícím testům
   přijatého dokladu neplátce.
6. `docs/exchange-format.md` (~ř. 921): doplnit jednou větou, že
   jednotková cena se dopočítá i u záporného množství.

## Existující data

Bez migrace. Řádky uložené s jednotkovou cenou 0 se opraví při dalším
uložení dokladu. Na kopiích reálných dat jde o jednotky řádků, hromadný
přepočet se kvůli nim nedělá.

## Mimo rozsah

- Opravný daňový doklad (#92).
- Tolerance u množství blízkého nule — množství je desetinné číslo
  z databáze, porovnání s nulou stačí.

## Hotovo když

- [ ] Řádek z celkové ceny se záporným množstvím má jednotkovou cenu
      `celkem / množství`; množství 0 a prázdné dává 0.
- [ ] Částky řádku a dokladu se proti dnešku nezměnily (testy dokumentu).
- [ ] Náhled i uložený přijatý dobropis na zdroji neplátce ukazují
      nenulové jednotkové ceny.
- [ ] `vendor/bin/phpunit --filter 'DocRowCalculatorTest|DocDocumentRowCalcTest'`
      a testy applieru zelené, pak celá sada.
- [ ] `**Stav:**` tohoto tasku aktualizován, `python3 scripts/tasks-index.py`.
