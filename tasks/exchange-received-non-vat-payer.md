# Task: Přijatý doklad u neplátce DPH — daň dodavatele do ceny v řádcích

**Stav:** naplánováno — rozhodnutí D1–D9 zamčena 2026-10-05 (#97)

**Issue:** #97

**Cíl:** Když zdroj dat není k datu dokladu plátcem DPH a přijme doklad od
plátce, vznikne doklad **Bez DPH**, jehož řádky nesou ceny **včetně daně
dodavatele**. Daň je pro neplátce součástí ceny pořízení — musí skončit
v nákladech (501, 518, …) a v závazku vůči dodavateli, ne zmizet.

Priorita: věcná správnost (`docs/roadmap.md` → Pravidlo prioritizace,
bod 1) — systém dnes tvrdí nepravdu o penězích. Patří k přijaté faktuře
z pošty (milník M2).

## Před implementací přečti

- `docs/ds-setup.md` §0 D5 a D10, §6 — plátcovství = registrace DPH
  platná k datu, příznak `economy.vatAgenda` řídí jen výchozí hodnoty
- `docs/exchange-format.md` — `vat.mode` (~ř. 209–222), § „Pole
  `vatRecap` a `totals`“ (~ř. 328), `_resolve.computed` (~ř. 374),
  §8.4 a podsekce derivace místa a kódu (~ř. 743–870), tabulka issues
  (~ř. 1103)
- `docs/vat-calculation.md` — režimy `vat_mode` 0/1/2
- `tasks/docs-vat-mode-derivation.md`, `tasks/exchange-preview-vat-recompute.md`,
  `tasks/exchange-preview-vat-choices.md` — kontext `effectiveVatMode()`,
  náhledu a voleb DPH
- `modules/core/exchange/src/Document/DocumentApplier.php`:
  `computePreviewAmounts()` (~ř. 357), `transform()` (~ř. 1290),
  `vatContext()` (~ř. 1611), `ownVatRegistration()` (~ř. 1910),
  `resolveRecapSource()` (~ř. 2405), `appendRecapSourceIssue()` (~ř. 2576),
  `appendVatModeIssue()` (~ř. 2601), `effectiveVatMode()` (~ř. 2645),
  `deriveTotalRoundingMode()` (~ř. 2830), `transformRows()` (~ř. 2917),
  `resolveVatRegistrationFor()` (~ř. 3125), `withVatBlocks()` (~ř. 3252)
- `modules/core/exchange/src/Document/VatModeDerivation.php` — `tolerance()`
- `modules/core/exchange/src/Isdoc/IsdocReader.php` — `mapRows()` (~ř. 305)
- `modules/docs/core/src/DocRowCalculator.php` — `computePrice()`,
  `computeVat()` (proč dnes vyjde základ místo ceny s daní)
- `frontend/src/components/exchange/DocumentExchangePreview.svelte` —
  tabulka řádků (~ř. 860–930), `vatChoiceField` (~ř. 681)

## Kontext — diagnostika

Zdroj dat nastavený jako neplátce (žádná registrace DPH), přijatá faktura
od plátce z ISDOC. Canonical je v pořádku — ceny řádků bez DPH, sazba 21 %,
úplná rekapitulace dodavatele (ilustrativní čísla, poměry zachované):

| | základ | daň | celkem |
|---|---|---|---|
| rekapitulace dodavatele 21 % | 1 000,00 | 210,00 | 1 210,00 |

Řetěz v applieru:

1. `vatContext()`: `ownVatRegistration()` nic nenajde → `derive = false`
   → kódy DPH řádků se neodvodí, zůstanou prázdné.
2. `effectiveVatMode()`: canonical z ISDOC nemá `vat.mode`, derivace řádků
   sedí na základ → `vat_mode = 1` (Ze základu). Neplátce se nikde neřeší.
3. `DocDocument::computeAmounts()`: bez `vat_registration` je
   `resolveVatCodesForDoc()` null → rekapitulace prázdná;
   `DocRowCalculator::computeVat()` u řádku bez kódu dá základ = celkem =
   cena bez DPH → doklad **1 000,00 místo 1 210,00**.
4. Náhled hlásí `computed_total_mismatch` s textem, který chybu svádí na
   AI („řádky jsou nejspíš neúplné nebo špatně přečtené“), a info
   `recap_source_computed_fallback`.

Dál: potvrzení s `vat_mode = 1` neprojde („Registrace DPH je povinná“);
přepnutí na Bez DPH projde, ale řádky zůstanou bez daně → na nákladový
účet i na 321 jde jen základ. **Účtování samo je správně** — doklad Bez DPH
s cenami s daní zaúčtuje správně (`buildRowLines` bere `vat_base_dom`).
Ruční formulář funguje (neplátce má default Bez DPH a píše ceny s daní).

Test `DocumentApplierTest::testNonVatPayerDataSourceKeepsLegacyBehaviour`
dnešní chování zabetonoval jako „chování jako dřív“ — nikdo ho nenavrhl.

Starý Shipard řešil totéž v importu ISDOC: plátcovství podle období DPH
k DUZP, neplátce bral od dodavatele `UnitPriceTaxInclusive` (experimentálně
`LineExtensionAmountTaxInclusive`) a nic nedorovnával — haléřové rozdíly
proti částce k úhradě zůstávaly. Import z PDF neplátce neřešil vůbec.
`IsdocReader` dnes ceny s daní na řádcích zahazuje.

## Rozhodnutí k designu (potvrzená)

- ✓ **D1 — kde:** oprava v `DocumentApplier` (+ `IsdocReader`, D6), ne
  v `DocDocument` ani v účtování. `DocDocument` nemá z `vat_pct` hádat, že
  jde o neplátce.
- ✓ **D2 — kdo je neplátce:** přijatý doklad (`selfParty: customer`)
  a žádná aktivní registrace DPH **platná k datu dokladu** — DUZP, bez něj
  datum vystavení, bez obou dnešek. `economy.vatAgenda` se nepoužije
  (ds-setup D5). `ownVatRegistration()` začne respektovat `valid_from` /
  `valid_to` — dnes platnost ignoruje i pro plátce (bývalý plátce by dostal
  derivaci plátce i u dokladů z doby neplátcovství).
- ✓ **D3 — výsledek:** `vat_mode = 0`, daň dodavatele je součástí ceny
  v řádcích, řádky mají `price_calc_mode` 1 (z celkové ceny).
- ✓ **D4 — cena řádku s daní, priorita:**
  1. `rows[].computed.vatTotal` od dodavatele (ISDOC po D6, případně AI),
  2. řádky už v cenách s daní — efektivní režim fromTotal (deklarovaný
     nebo odvozený `VatModeDerivation`) → `totalPrice` beze změny,
  3. `totalPrice × (1 + vat.pct / 100)`, zaokrouhleno na 2 místa.
  Řádek bez sazby (nebo 0 %) = cena beze změny.
- ✓ **D5 — dorovnání na rekapitulaci dodavatele:** řádky každé sazby se
  srovnají na `vatRecap[].total` té sazby (jen když je rekapitulace úplná
  — stejná podmínka jako v `VatModeDerivation::references()`); rozdíl jde
  na řádek s **největší absolutní částkou** v sazbě. Doklad tak sedí na
  částku k úhradě i v haléřích (na rozdíl od starého Shipardu).
- ✓ **D6 — `IsdocReader`** plní `rows[].computed` (`vatBase` =
  `LineExtensionAmount`, `vatAmount` = `LineExtensionTaxAmount`, `vatTotal` =
  `LineExtensionAmountTaxInclusive`; u cizí měny `*Curr`). Canonical
  zůstává věrný ISDOC, jen přestane zahazovat data. Schéma pole má
  (`RowComputed`), formát se nemění.
- ✓ **D7 — náhled:** `_resolve.vat.mode` = `{value: 'none', source:
  'nonPayer', auto: 'none'}`; info issue `vat_non_payer` vysvětlí, že daň
  dodavatele je součástí ceny pořízení. Volba režimu se u neplátce
  nenabízí (už dnes — `vatChoiceEnabled` stojí na `vatCodeOptions`, které
  neplátce nemá; uložené piny `vat.mode` se mimo `derive` ignorují).
  `computed_total_mismatch` pak u neplátce sám zmizí; jeho text se obecně
  zneutralizuje (bez svádění na AI).
- ✓ **D8 — rekapitulace dodavatele se na doklad neukládá:** neplátce ji
  nepotřebuje, stopa zůstává v canonicalu analýzy.
- ✓ **D9 — mimo rozsah:** samovyměření u neplátce, který je
  identifikovanou osobou (jen warning, viz níže); dohledání už potvrzených
  dokladů se ztracenou daní (testovací zdroje se resetují a sjedou znovu).

**Upřesnění při psaní tasku** (implementační, v duchu D3/D7):

- Řádky neplátce **bez `vat_code` a `vat_pct`** — kód z historie
  (RowHistoryEnricher) ani sazba z canonicalu se na doklad Bez DPH
  nepropisují.
- Sleva na řádku: cena s daní se počítá **po slevě** (D4 bod 3 nad
  `totalPrice` po slevě, D4 bod 1 už slevu obsahuje) a `discount_pct` /
  `discount_amount` se u převedeného řádku vynulují — jinak by se sleva
  odečetla podruhé z ceny, která ji už obsahuje.
- Náhled ukazuje ceny řádků, které skončí na dokladu: `_resolve.computed`
  dostane `rows` (podle **indexu canonicalu**, ne pořadí v `transform()`)
  s `unitPrice` a `totalPrice`; tabulka řádků je použije, když existují.
  Bez toho by náhled ukazoval ceny bez daně a součty s daní.

## Algoritmus (D2–D5)

```
nonPayer = selfParty === 'customer'
           && ownVatRegistration(datum dokladu) === null

pokud nonPayer:
  pro každý položkový řádek (rowKind item, bez accSide, ne kontační):
    gross = computed.vatTotal          (D4.1)
         ?? totalPrice po slevě        (D4.2, efektivní fromTotal)
         ?? round(totalPrice po slevě × (1 + pct/100), 2)   (D4.3)
  pokud vatRecap úplný:
    pro každou sazbu pct v vatRecap:
      diff = recap[pct].total − Σ gross řádků se sazbou pct
      pokud 0 < |diff| ≤ VatModeDerivation::tolerance(počet řádků sazby):
        řádek s max |gross| v sazbě: gross += diff
      (|diff| nad tolerancí → nedorovnávat; computed_total_mismatch to ukáže)
  hlavička: vat_mode 0, vat_recap_source 0, bez vatRecap, vat_registration null
  řádky:    total_price = gross, price_calc_mode 1, unit_price null
            (dopočítá DocRowCalculator), vat_code/vat_pct null, slevy null
```

Sazba řádku pro skupinu: `rows[].vat.pct` z canonicalu (porovnání
s `vatRecap[].vatPct` s tolerancí float, ne `===`).

## Chování v applieru

| Situace | `vat_mode` | Řádky | Issues |
|---|---|---|---|
| neplátce, dodavatel plátce, ceny bez daně (ISDOC, AI fromBase) | 0 | ceny s daní dle D4/D5 | info `vat_non_payer` |
| neplátce, ceny s daní (účtenka, fromTotal) | 0 | beze změny (+ D5) | info `vat_non_payer` |
| neplátce, dodavatel neplátce (bez daně, `vat.mode: none`) | 0 | beze změny | bez issue |
| neplátce, doklad nese samovyměření (`vat.reverseCharge` nebo `rows[].vat.reverseChargeCode`) | 0 | dle D4 | + warning `non_payer_reverse_charge` (D9) |
| plátce (registrace platná k datu) | beze změny | beze změny | beze změny |
| bývalý plátce, doklad po `valid_to` | 0 | dle D4/D5 | info `vat_non_payer` (dřív derivace plátce) |
| vystavený doklad (`selfParty: supplier`) | beze změny | beze změny | beze změny |

U neplátce se **nepřidávají** `vat_mode_derived`, `vat_mode_suspect`
a `recap_source_computed_fallback` — režim i rekapitulace jsou dané D3/D8,
hlášky by uživatele mátly.

## Co je potřeba udělat

### Commit 1 — `IsdocReader`: ceny řádků s daní (D6)

- `mapRows()`: blok `computed` (`vatBase`, `vatAmount`, `vatTotal`) z
  `LineExtensionAmount`, `LineExtensionTaxAmount`, `LineExtensionAmountTaxInclusive`
  (cizí měna `*Curr`); chybějící element → klíč vynechat, celý blok jen
  když je aspoň jedna hodnota. Docblock třídy: proč (neplátce, #97).
- `docs/exchange-format.md` kap. 4 (adaptér ISDOC, ~ř. 122): zmínka, že
  řádky nesou `computed` od dodavatele. Mapovací tabulka
  `tasks/mail-isdoc-import.md` (Řádky, ~ř. 176): řádek o `computed`
  s poznámkou „(#97)“.
- Test `tests/Unit/Module/Core/Exchange/Isdoc/IsdocReaderTest.php`:
  tuzemská faktura → `computed` u řádků; cizí měna → `*Curr`; ISDOC bez
  `LineExtensionAmountTaxInclusive` → bez `vatTotal`; dobropis — znaménka
  stejná jako `totalPrice`.

Ověření: `php -l`, `vendor/bin/phpunit --filter 'IsdocReaderTest|IsdocImportServiceTest'`.

### Commit 2 — applier: neplátce k datu, převod cen, issues (D2–D5, D7 server, D8, D9)

- `ownVatRegistration(?string $date)`: filtr `valid_from <= datum`
  a `valid_to IS NULL OR valid_to >= datum` (sloupce jsou nullable —
  null = neomezeno); datum z `vatContext()` (`taxPointDate` → `issueDate`
  → dnes). Docblock: ds-setup D5.
- `vatContext()`: nový klíč `nonPayer` (bool) — `selfParty === 'customer'`
  a registrace k datu chybí. Klíč cache už data obsahuje (`dates`).
- `effectiveVatMode()`: `nonPayer` → `{value: 'none', source: 'nonPayer',
  reason: null, pinned: false, auto: 'none'}` jako **první** větev (před
  derivací i ochranou samovyměření — ta stojí na registraci).
- Nová privátní metoda (např. `nonPayerGrossTotals(array $canonical,
  array $vatCtx): array<int, float>`, index canonicalu → cena s daní)
  podle Algoritmu; jediné místo výpočtu pro `transform()` i náhled.
- `transform()` / `transformRows()`: u `nonPayer` hlavička dle Algoritmu
  (`vat_recap_source` 0, bez `vatRecap`, `vat_registration` null), řádky
  `total_price` = gross, `price_calc_mode` 1, bez `unit_price`,
  `vat_code`, `vat_pct`, slev. Kontační řádky beze změny.
- `appendVatModeIssue()` / `appendRecapSourceIssue()` /
  `appendVatHeaderIssues()`: u `nonPayer` nic z `vat_mode_*`
  a `recap_source_computed_fallback`; místo toho info `vat_non_payer`
  (path `vat.mode`) a podle tabulky warning `non_payer_reverse_charge`.
- `computePreviewAmounts()`: `rows` v `_resolve.computed` (index
  canonicalu → `unitPrice`, `totalPrice` z `computeAmounts()['rows']`;
  `transform()` přeskakuje `rowSkips` — mapování indexů si drž, nehádej
  z pořadí). Text `computed_total_mismatch` zneutralizovat, např.
  „… se liší od částky, která skončí na dokladu (X) — zkontroluj řádky
  a režim DPH.“
- `deriveTotalRoundingMode()` ověřit nad převedeným dokladem (Σ řádků
  s daní = Σ `vatRecap.total`) — zaokrouhlení celkové částky na koruny
  musí dál vyjít.

Docs (stejný commit):

- `docs/exchange-format.md`: komentář `vat.mode` (u přijatého dokladu
  neplátce přebíjí applier na `none`), nová podsekce **„Přijatý doklad
  neplátce DPH“** u §8.4 (definice neplátce k datu, priorita D4,
  dorovnání D5, tabulka chování); `_resolve.computed.rows` (~ř. 374);
  `vat_non_payer` a `non_payer_reverse_charge` do tabulky issues,
  upravený popis `computed_total_mismatch`; věta o „naší registraci“
  (~ř. 757) doplnit o platnost k datu.
- `docs/ds-setup.md` §6 bod 3, odstavec „Účtování žádnou změnu
  nepotřebuje“: doplnit, že přijaté doklady z pošty převádí na Bez DPH
  s cenami s daní applier (odkaz na tento task).

Testy (`DocumentApplierTest`, fiktivní dodavatelé a částky):

- `testNonVatPayerDataSourceKeepsLegacyBehaviour` **nahradit** testy
  nového chování (název nesmí tvrdit „legacy“).
- neplátce + ISDOC s `computed.vatTotal` → `vat_mode` 0, řádky = vatTotal,
  `price_calc_mode` 1, bez `vat_code` / `vat_pct` / `vat_registration` /
  `vatRecap`, issue `vat_non_payer`, ne `vat_mode_derived`.
- neplátce + AI fromBase bez `computed` → `net × 1,21`; dvě sazby
  (21 + 12) dorovnané každá na svou rekapitulaci; rozdíl padne na
  největší řádek sazby; rozdíl nad tolerancí → nedorovnáno.
- neplátce + řádky v cenách s daní (fromTotal) → ceny beze změny.
- neplátce + sleva na řádku → cena s daní po slevě, slevy null.
- neplátce + dodavatel neplátce (`vat.mode: none`, bez rekapitulace) →
  beze změny, bez issue.
- neplátce + `vat.reverseCharge` → `non_payer_reverse_charge`.
- registrace s `valid_to` před DUZP → neplátce; s `valid_from` po DUZP →
  neplátce; platná k DUZP → dnešní chování plátce (regrese).
- náhled: `_resolve.vat.mode.source = 'nonPayer'`, `computed.totals`
  = částka k úhradě, `computed.rows` podle indexu canonicalu (i s
  přeskočeným řádkem), bez `computed_total_mismatch`.
- regrese zelená: `VatModeDerivationTest`, `VatCodeDerivationTest`,
  `VatPlaceDerivationTest`, `testReceivedDocument*`, `testDeclaredRecap*`,
  `DocumentApplierNoItemTest`.

DB mock: `ownVatRegistration()` dostane parametry data — mocky
`dbWithVatRegistration` (~ř. 199) upravit tak, aby registraci vracely
nezávisle na datech v dotazu, a pro testy platnosti přidat mock, který
data skutečně vyhodnotí (nebo test přes integrační zdroj).

Ověření: `php -l`, `vendor/bin/phpunit --filter 'DocumentApplierTest|DocumentApplierNoItemTest|VatModeDerivationTest|VatCodeDerivationTest|VatPlaceDerivationTest'`.

### Commit 3 — náhled ve frontendu, help, uzavření

- `DocumentExchangePreview.svelte`: tabulka řádků bere **Cena/j**
  a **Celkem** z `resolve.computed.rows[i]`, když existují (jinak
  canonical jako dnes). Hlavička Režim DPH ukáže zdroj `nonPayer`.
- `frontend/src/i18n/cs.js` + `en.js`:
  `exchange.preview.vatChoice.source.nonPayer` (cs např. „neplátce DPH —
  daň v ceně“), parita přes `npm run check:i18n`.
- `help/posta/kontrola-vytezeni.md`, sekce **Na co narazíš**: odstavec
  **Neplátce DPH** — faktura od plátce se vystaví jako *Bez DPH*, daň
  dodavatele je v cenách řádků, **Celkem** se rovná částce k úhradě;
  když zdroj byl plátcem jen část období, rozhoduje datum zdanitelného
  plnění. Názvy ověřit ve zdroji (`exchange.preview.vatMode.none`,
  `docs.core.vatModes`). Pak `python3 scripts/help-index.py`.
- `**Stav:**` → `hotovo` (nebo `částečně — zbývá ověření`)
  + `python3 scripts/tasks-index.py`.

Ověření: `cd frontend && npm run check:i18n && npm run build`, na konci
celá PHPUnit sada lokálně.

### Ověření na dev zdroji

Ukázkový zdroj (režim *volný*) nastavený jako neplátce s nahranými
fakturami od plátce; konkrétní zprávy jsou v chatu, ne v repu.

1. Náhled zprávy s ISDOC: Režim DPH *Bez DPH* (neplátce), ceny řádků
   s daní, **Celkem** = částka k úhradě, bez upozornění na rozdíl částek.
2. **Vystavit koncept** → doklad `vat_mode` 0, bez rekapitulace, součet
   = částka k úhradě; potvrzení projde bez registrace DPH.
3. Deník: nákladové účty a 321 nesou částku s daní, 343 nic.
4. Zpráva bez ISDOC (AI analýza PDF) — totéž přes D4 bod 3.
5. Regrese: na zdroji plátce (registrace platná) náhled i doklad beze změny.

## Mimo rozsah

- Samovyměření neplátce — identifikované osoby (jen warning, D9).
- Dohledání a oprava už potvrzených dokladů (D9).
- Výchozí registrace ve formuláři (`DocsHeadsFormBase::resolveVatRegistrationOptions()`)
  podle platnosti k datu — applier se tím od formuláře v pořadí volby
  odchýlí jen u registrací neplatných k datu; formulář samostatně.
- Prompt AI analyzeru — `rows[].computed` od AI se využije, když přijde,
  ale prompt se kvůli tomu nemění.
- Vystavené doklady a `DocumentExporter`.

## Pasti

- **„Neplátce“ = registrace k datu, ne příznak** `economy.vatAgenda` —
  bývalý plátce má příznak `false`, ale staré doklady s DPH (ds-setup D10).
- **`valid_from` / `valid_to` jsou nullable** — null je neomezeno, ne
  „neplatí“. Porovnávat jako data, ne řetězce s časem.
- **Ochrana samovyměření v `effectiveVatMode()`** přepíná `none` →
  `fromBase`; větev neplátce musí jít **před** ní, jinak neplátce se
  samovyměřením skončí s `vat_mode` 1 a padne na povinné registraci.
- **Sleva se nesmí odečíst dvakrát** — převedená cena slevu obsahuje.
- **`price_calc_mode` 1 + `unit_price`**: neposílat původní jednotkovou
  cenu bez daně — `DocRowCalculator` ji dopočítá z celkové (4 desetinná
  místa); poslaná by v náhledu i na dokladu mátla.
- **Index řádků:** `transform()` přeskakuje `rowSkips`, `computeAmounts()`
  vrací řádky v pořadí transformace — `computed.rows` musí mít index
  canonicalu, jinak náhled ukáže cenu u jiného řádku.
- **Float sazby:** `21` vs `21.0` vs `"21"` — skupiny podle sazby
  porovnávat numericky.
- **Kontační řádky** (`accSide`, operace s `rowSide`) se nepřevádějí —
  účtují částku přímo.
- **Fixture a docs:** jen fiktivní dodavatelé a částky, žádná data
  z diagnostiky (`scripts/check-sensitive.py`).

## Hotovo když

- [ ] `IsdocReader` plní `rows[].computed`, testy a mapovací tabulka.
- [ ] `ownVatRegistration()` respektuje platnost k datu.
- [ ] Applier: neplátce → `vat_mode` 0, ceny s daní dle D4, dorovnání D5,
      bez rekapitulace a registrace; issues podle tabulky; legacy test
      nahrazený, nové i regresní testy zelené.
- [ ] Náhled: zdroj `nonPayer`, `computed.rows` v tabulce řádků,
      neutrální text `computed_total_mismatch`.
- [ ] `docs/exchange-format.md` a `docs/ds-setup.md` §6 aktualizované.
- [ ] Help `kontrola-vytezeni.md` s odstavcem Neplátce DPH,
      `help-index.py` prošel.
- [ ] Ověření na dev zdroji: faktura od plátce na zdroji neplátce dá
      doklad Bez DPH s částkou k úhradě a deník s daní v nákladech.
