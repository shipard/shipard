# Jak se počítá DPH na dokladech

Autoritativní pravidla DPH na dokladu pro `docs.core` — kde se kód liší, platí
tento dokument a rozdíl je chyba (#75). Implementace:
`DocDocument::beforeSave` → `calculateRowPrice` / `calculateRowVat` →
`buildVatRecapitulation` (nebo `takeOverVatRecapitulation` u převzaté) →
`sumTotals` / `applyTotalRounding` → `reconcileRowsToRecap` →
`applyDomesticAmounts`.

## 1. Tři úrovně, jedna autorita

| úroveň | tabulka | role |
|---|---|---|
| řádek | `docs_core_rows` | cena, množství, sazba, DPH kód; `vat_base` / `vat_amount` / `vat_total` jsou **informativní** (tisk řádku) |
| rekapitulace | `docs_core_vat_recap` | **autoritativní** DPH dokladu per (kód, sazba): `base`, `tax`, `total` + `_dom` |
| hlavička | `docs_core_heads` | součty z rekapitulace (`total_base`, `total_vat`, `total_amount`, `total_rounding`) |

Z rekapitulace čte účtování (343), saldo, DPH výstupy (DP3/KH/SH) a podání.
Řádkové hodnoty DPH do žádného výkazu nevstupují.

## 2. Režim cen — `vat_mode`

Vlastnost dokladu (`docs.core.vatModes`), určuje, co znamená cena na řádku:

- `0 Bez DPH` — nedaňový doklad; základ = cena, daň 0.
- `1 Ze základu` — ceny na řádcích jsou bez DPH.
- `2 Z ceny celkem` — ceny na řádcích jsou s DPH.

## 3. Metoda výpočtu — `vat_calc_source`

Platí jen pro **přepočítanou** rekapitulaci (§ 5). Default vždy `0`.

**`0 Z hlavičky`** (norma, § 37 odst. 1 ZDPH): řádky se seskupí per (DPH kód, sazba),
sečtou se **řádkové ceny** a daň se počítá jednou, ze součtu:

| `vat_mode` | výpočet skupiny |
|---|---|
| 1 ze základu | `base = Σ cena`; `tax = round(base × pct / 100)`; `total = base + tax` |
| 2 z ceny celkem | `total = Σ cena`; `base = round(total / (1 + pct / 100))`; `tax = total − base` |

Výsledek: celková částka dokladu je vždy přesně Σ řádkových cen; daň je spočtená
jednou, z celého plnění v dané sazbě. Řádkové `vat_base`/`vat_amount` se
z rekapitulace odchylují o haléře — to je v pořádku, jsou informativní.

Příklad (`mode 2`, 2 řádky à 55,00 s DPH 21 %): `total = 110,00`,
`base = round(110 / 1,21) = 90,91`, `tax = 19,09`. Součet řádkových rozpočtů by dal
2 × (45,45 + 9,55) = 90,90 + 19,10 — jiné rozdělení, stejná částka.

**`1 Z řádků`**: rekapitulace = součet **řádkových** `vat_base`/`vat_amount`
(daň tedy sečtená z per-řádek zaokrouhlených hodnot, v obou režimech cen).
Historický režim (řádek = samostatná účtenka v jednom pokladním lístku). Ponechán
pro doklady, kde protistrana počítala po řádcích a my doklad počítáme sami; u
vydaných faktur a prodejek nedává smysl a časem se z nich odstraní.

`round()` = `vat_rounding_mode` dokladu (cfgItem `docs.core.vatRoundingModes`
— jen na haléře (0, default) nebo matematicky na 1; sémantiku kódů drží
`RoundingModes`), aplikuje se na dokladové úrovni: při `vat_mode` 1 na daň,
při `vat_mode` 2 na základ.

Koeficientová metoda (do 2019, `k = round(pct / (100 + pct), 4)`) se **neimplementuje**;
historické doklady kryje převzatá rekapitulace.

## 4. Speciální kódy

- **Reverse charge (samovyměření):** primární řádek nese spočtenou daň jako nárok na
  odpočet (DP3 ř. 43/44), párový řádek `is_reverse_pair` oddanění; do `total`
  vstupuje jen daň placená dodavateli (`noPayTax` → jen základ).
- **`noPayTax` / 0 % / osvobozené:** daň 0 nebo informativní, `total = base`.
- **`sum_base` / `sum_tax` / `sum_total`** z definice kódu říkají, co z řádku
  rekapitulace vstupuje do součtů hlavičky.

## 5. Autorita rekapitulace — `vat_recap_source`

Vlastnost **celého dokladu**:

- `0 Přepočítaná` — rekapitulace vzniká z řádků podle § 2–4 při každém uložení.
  Doklady, které vystavuje Nový Shipard (FVB, prodejky, pokladní doklady, opravné
  doklady).
- `1 Převzatá` — rekapitulace je **vstup a fakt**: to, co je na existujícím dokladu.
  `beforeSave` ji nepřepočítá; spočítá z ní `_dom`, součty hlavičky a `sum_*`.
  Použití: import ze starého Shipardu, přijaté doklady (rekapitulace dodavatele je
  závazná i když je haléřově „špatně" — nárok na odpočet je částka z faktury),
  ruční oprava účetní.

Výchozí hodnota podle původu: vystavený doklad → přepočítaná; import → převzatá
(exchange `vat.recapSource: "declared"`, `vatRecap` z dat zdroje); přijatý doklad
z AI extrakce → převzatá, když `vatRecap` je vnitřně konzistentní (`base + tax =
total`) a žádný řádek nemá kód se samovyměřením (`reverseVatCode`); jinak
přepočítaná. Rekapitulace dodavatele s přenesením daňové povinnosti je z jeho
pohledu (0 %, daň 0) a nepřebírá se — naše nese nárok na odpočet a oddaňovací pár
(`docs/exchange-format.md` § 5, D3).

Editace: při `převzatá` je rekapitulace editovatelná. Změna částek řádků ji
**nepřepíše** — uložení vydá warning `rows_recap_mismatch`. Oprava: upravit
rekapitulaci, nebo přepnout na `přepočítaná` (přegeneruje se z řádků) a případně
zpět. Změny textů a jiných neobnosových polí rekapitulaci nedotknou.

Kontroly (warning, neblokují): `vat_recap_inconsistent` (`tax ≠ base × pct`
v toleranci `max(0,05; |base| × 0,001)`, `base + tax ≠ total` ± 0,02) — u převzaté
zviditelní nesrovnalost dodavatele; `rows_recap_mismatch` (Σ řádků ≠ rekapitulace
dle režimu) — signál neúplných nebo špatně zadaných řádků.

### 5.1 Implementace

- **Přechody zdroje** rozhoduje `DocDocument::useDeclaredRecap()` porovnáním
  `vat_recap_source` v payloadu se stavem v DB: přepnutí kterýmkoli směrem
  přegeneruje rekapitulaci z řádků (u `přepočítaná → převzatá` je to ta
  „kopie startovní převzaté"), uložení už převzatého dokladu ji nechá být.
  Bez `originalData` (applier, interní přepočet z dětské tabulky) rozhoduje
  payload: přišla-li rekapitulace, převezme se; u uloženého dokladu se
  převezme ta v DB.
- **Normalizace vstupu** (`takeOverVatRecapitulation`): flagy `sum_*` vždy
  z definice kódu (autorita definice, ne vstupu), `total` se dopočítá jen
  když ho vstup nenese, `id` řádku se zachovává (child sync `TableGateway`
  aktualizuje na místě, sub-tabulka ve formuláři neztratí identitu).
  Neznámý DPH kód je `DomainException` — stejně jako u přepočítané.
  Z výměnného formátu se k ní ale nedostane: applier každý kód rekapitulace
  ověří `VatCodeResolver`em (stejná kaskáda země jako u řádků) a
  nedohledatelný kód znamená přepočítanou + `recap_source_computed_fallback`
  s důvodem (I7), ne 500.
- **Součty hlavičky** se u převzaté berou **jen z rekapitulace**
  (`headTotalsIncludeRowsOutsideRecap()` = false): řádek s kódem, který
  v rekapitulaci není (import s jiným mapováním kódů), by se jinak započítal
  podruhé. Prázdná rekapitulace = fallback na řádky jako dřív.
- **Rekapitulace se neváže na řádky** — `docs_core_vat_recap` nemá FK na
  řádky, párování při `rows_recap_mismatch` je jen per (kód, sazba).
- **Editace** ve formuláři: přepnutí na převzatou se projeví **po uložení**
  (tehdy vznikne kopie přepočítané rekapitulace, kterou jde editovat); tab
  se řídí uloženou hodnotou, ne přepínačem. Tab „Rekapitulace DPH" je u převzaté sub-tabulka
  nad `docs_core_vat_recap` (`VatRecapForm`, `VatRecapDocument`), u
  přepočítané zůstává přehled ke čtení. Po změně řádku rekapitulace
  přepočítá hlavičku `DocHeadRecomputer` — u převzaté aktualizuje řádky
  **na místě** podle `id`, nikdy nemění částky. Účtování jde na přechodu
  stavu, takže po ruční opravě zaúčtovaného dokladu je potřeba Přeúčtovat.
- **Výchozí hodnota z výměnného formátu**: `vat.recapSource` (`computed` /
  `declared`), `vat.calcSource` (`header` / `rows`). Chybějící `recapSource`
  applier odvodí — u dokladu, který přijímáme, `declared` při neprázdné,
  aritmeticky konzistentní rekapitulaci s dohledatelnými kódy, jinak
  `computed` + info issue `recap_source_computed_fallback`. Explicitní
  `declared` se aritmetikou nepodmiňuje: u přenesení daňové povinnosti
  `base + tax ≠ total` platí. Rekapitulaci bez kódů (ISDOC) applier doplní
  z řádků, když je pro sazbu jednoznačný kód. Detaily
  `docs/exchange-format.md`.

## 6. Zaokrouhlení celkové částky — `total_rounding_mode`

Až po rekapitulaci: `total_amount` se zaokrouhlí (matematicky na celé jednotky,
nahoru, dolů, **matematicky na 0,05** — hotovost SK a část eurozóny, nebo na
haléře = bez efektu), rozdíl jde do `total_rounding`; rekapitulace zůstává.
Kódy módů a výpočet `kód → (krok, směr)` drží `RoundingModes`
(`modules/docs/core/src/`), jsonc `docs.core.roundingModes` nese jen názvy;
historický kód 2 (matematicky na 0,01) byl totožný s 0 a `ds-upgrade` ho slévá
(#63). `vat_rounding_mode` má užší nabídku `docs.core.vatRoundingModes` (§ 4).
Hotovostní úhrada faktury na celé Kč = #73.

## 7. Dorovnání řádků na rekapitulaci — obě měny

Rekapitulace je autorita (§ 1); řádkové `vat_base` / `vat_amount` jsou z ní odvozené
a **dorovnávají se na ni top-down v obou měnách nezávisle** — v měně dokladu i v
domácí. Rozdíl per skupina (kód, sazba) absorbuje poslední řádek skupiny s nenulovou
hodnotou; cena řádku (`total_price`) se nikdy nemění, jen odvozený rozpad. V mode 1
se dorovnává jen `vat_amount` (základ = cena, sedí konstrukčně); v mode 2 `vat_base`
i `vat_amount` (`vat_total` = cena, sedí konstrukčně).

Proč obě měny: deník účtuje výnos/náklad z řádků a 311/321 z hlavičky v obou
měnách — bez dorovnání cur by byl sloupec měny dokladu per doklad rozjetý o haléře
(u tuzemského dokladu s kurzem 1 dokonce dva sloupce téhož řádku různě). A tisk
řádkových DPH hodnot musí dávat rekapitulaci. Starý Shipard dorovnával jen Hc
(`taxBaseHcCorr`, jen tuzemské doklady); tuzemsky to vyšlo nastejno, cizoměnově ne.

Domácí měna: `base_dom`/`tax_dom` rekapitulace = `round(cur × kurz)`; hlavička se
sčítá z rekapitulace; `total_rounding_dom` absorbuje kurzový zbytek hlavičky.

Implementace: `DocDocument::reconcileRowsToRecap($rows, $recap, $suffix, $tolerance)`
— jedna metoda, volaná dvakrát (`''` pro měnu dokladu z `beforeSave`, `'_dom'`
z `applyDomesticAmounts` nad už dorovnanými cur hodnotami, takže při kurzu 1 jsou
obě měny shodné). Cíl dorovnání se v obou průchodech vybírá podle cur hodnot —
řádek s nulou v měně dokladu má nulu i v domácí, takže haléř základu i daně
padne na týž řádek a `vat_total` zůstane konzistentní. `vat_total` po dorovnání
drží politiku `DocRowCalculator::computeVat`: součet částí jen tam, kde je daň
součástí placené ceny (mode 1 s běžným kódem), jinak je autoritou cena řádku.

Invarianty (per skupina kód+sazba a per doklad, v cur i dom):

```
Σ rows.vat_base      == recap.base        Σ rows.vat_base_dom   == recap.base_dom
Σ rows.vat_amount    == recap.tax         Σ rows.vat_amount_dom == recap.tax_dom
Σ recap.base (sum_base) == total_base     Σ recap.base_dom == total_base_dom
Σ recap.tax  (sum_tax)  == total_vat      Σ recap.tax_dom  == total_vat_dom
total_base + total_vat + total_rounding == total_amount   (obdobně _dom)
```

**Tolerance u převzaté rekapitulace (§ 5):** dorovnání se provede jen do meze
haléřového zaokrouhlení — `max(0,02; 0,01 × počet řádků skupiny)` na základ i daň.
Větší rozdíl znamená chybějící nebo špatně zadané řádky: řádky zůstanou, jak jsou,
invariant pro tu skupinu neplatí a uložení vydá `rows_recap_mismatch`. U přepočítané
je rozdíl konstrukčně vždy v mezi.

## 8. Co se tiskne

Rekapitulace (základ / daň / celkem per sazba) a součty hlavičky. Řádkové DPH
hodnoty jsou volitelný komfort tiskové šablony, nikdy zdroj pro součet. Otevřené:
u dokladů v cenách s DPH řádkové DPH raději netisknout.

## 9. Odkazy

`docs/docs-mvp.md` §7–8 (historie návrhu; kde se liší, platí tento dokument),
`docs/exchange-format.md` (`vat`, `vatRecap`, `totals`), `docs/accounting.md`
(343 z rekapitulace), `modules/economy/vat/docs/README.md` (výstupy z recapu),
issue #75, zákon č. 235/2004 Sb. § 37.
