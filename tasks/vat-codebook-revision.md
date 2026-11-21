# Task: Revize číselníku DPH — kód „Mimo DPH“, osvobozeno, nové kódy výkazů

**Stav:** naplánováno — zadání podle #164 D1–D10

**Issue:** #164 (nahrazuje #89 D1; navazuje #165)

**Cíl:** Číselník DPH přestane míchat tři různé věci. Řádek, který není
plněním, dostane výslovný kód `cz-000` „Mimo DPH“ (mimo rekapitulaci,
v částce dokladu). Osvobozené plnění má kód v rekapitulaci i na vstupu.
Řádek dokladu s DPH bez kódu znamená „nevyplněno“ a doklad s ním nejde
potvrdit. Kódy „Bez daně“ `cz-112` a `cz-122` zanikají. Přibudou kódy pro
ř. 26, 30 a 31 přiznání.

Jen Nový Shipard. Applier se mění v míře nutné, aby po zrušení `cz-112`
fungoval. Význam `null` ve výměnném formátu, importní mód a mapování
starých kódů (`EUCZ000`, `EUCZ112`, `EUCZ122`) řeší navazující issue.

## Před implementací přečti

- issue #164 (tělo a komentář s rozhodnutími D1–D10)
- `docs/vat-calculation.md` — § 1 (rekapitulace je autorita), § 4
  (speciální kódy, `sum_base` / `sum_tax` / `sum_total`), § 5 (převzatá
  rekapitulace), § 5.2 (datum sazby)
- `docs/exchange-format.md` — § 8.4 (odvození kódu DPH, doklad mimo české
  DPH, tabulka `vat.code` × derivace)
- `tasks/exchange-received-foreign-vat.md` — vzor: doklad vznikne celý
  Bez DPH (#89 D2–D4), druhy `outOfScope`
- `tasks/vat-rate-date-core.md` — vzor kontroly „varování v rozpracovaném
  dokladu, chyba při potvrzení“ (D15)
- `modules/world/vat/config/vat-cz.jsonc` — celý; `vatCategories`,
  `vatCodes`, `vatPercents`
- `modules/world/vat/src/VatRateResolver.php` — `getVatCodes()` (filtr
  směr / místo, chybějící `place` = `domestic`), `validateCountryConfig()`
- `modules/docs/core/src/DocDocument.php` — `validateDeclaredRecap()`
  (~ř. 285), `validateReverseChargeSubjects()` (~ř. 497, parametr
  `$requireFilled`), `validateVatRates()` (~ř. 579), `computeAmounts()`
  (~ř. 922), `buildVatRecapitulation()` (~ř. 1420) a obě seskupovací
  metody (~ř. 1557, ~ř. 1588), `willHaveVatRecap()` (~ř. 1714),
  `takeOverVatRecapitulation()` (~ř. 1773),
  `headTotalsIncludeRowsOutsideRecap()` (~ř. 1923),
  `sumRowsOutsideRecap()` (~ř. 1939), `sumTotals()` (~ř. 1970),
  `reconcileRowsToRecap()`
- `modules/docs/core/src/DocRowCalculator.php` (~ř. 100),
  `DocRowsDocument.php` (~ř. 52), `DocRowsForm.php` (výchozí kód nového
  řádku ~ř. 371, `buildVatCodeOptions()`), `DocHeadVatContext.php`
  (`vatCodeOptions()`)
- `modules/core/exchange/src/Document/VatCodeDerivation.php` — celý
- `modules/core/exchange/src/Document/DocumentApplier.php` — větev `$noVat`
  (~ř. 2176), `outOfScopeKind()` (~ř. 2283), zprávy dokladu mimo české DPH
  (~ř. 4157–4200), rozhodnutí o režimu (~ř. 4243–4295)
- `modules/core/exchange/src/Dataset/Verify/SavedDocumentProjection.php` —
  `withoutZeroVat()` (~ř. 163)
- `modules/economy/vat/config/vat-reports-cz.jsonc` (`vatOutputs`,
  `dp3Rows`), `vat-xml-cz.jsonc` (řádky 26, 30, 31 už zná),
  `src/VatOutputsMapping.php`, `src/RecapitulativeStatementCalculator.php`,
  `src/Xml/ShvXmlWriter.php`
- `modules/economy/accounting/config/accountingRules.cz.jsonc` (~ř. 110,
  komentář o trvale nulových kódech)
- testy úplnosti: `VatReportsMappingCompletenessTest`,
  `VatXmlMappingCompletenessTest`, `VatAnalyticsCompletenessTest`
- `docs/help-authoring.md` před úpravou `help/`

## Kontext

- Číselník je kopie starého jedna k jedné, jen bez `EUCZ000` „Nedaňový
  řádek“. #89 D1 zavedlo, že nedaňový řádek je `vat_code = null`. `null`
  tak znamená zároveň „záměrně mimo DPH“ i „zapomenutý kód“ a zapomenutý
  kód potichu vypadne ze základu i z přiznání.
- `cz-112` (vstup) a `cz-122` (výstup) „Bez daně“ nesou v datech směs:
  odpočty nezdaněných záloh, kauce, místní poplatky (nejsou plněním)
  i pojištění a poštovné (osvobozená plnění). Přijaté osvobozené plnění se
  nikde nevykazuje, proto starý číselník vstupní „Osvobozeno“ neměl.
- Odvozování kódu v tuzemsku jde podle **procenta**, ne podle kategorie
  (`VatCodeDerivation::deriveDomestic()`): po zrušení `cz-112` a přidání
  `cz-114` má 0 % zase jediného kandidáta.
- U převzaté rekapitulace se řádky mimo rekapitulaci do součtů hlavičky
  nepočítají (`headTotalsIncludeRowsOutsideRecap()`). Odpočet nezdaněné
  zálohy na dokladu s převzatou rekapitulací by z částky dokladu vypadl.
- Osvobozené pořízení zboží z jiného členského státu a osvobozená služba ze
  zahraničí nemají v přiznání řádek (ř. 3–6 a 12–13 nesou jen plnění,
  u nichž příjemce přiznává daň), v kontrolním hlášení také ne.
- Ř. 26 tiskopisu: ostatní plnění s nárokem na odpočet — plnění s místem
  plnění mimo tuzemsko (§ 24a) a osvobozená plnění (§ 67–71, 71h, 71i).
  Ř. 30 a 31: třístranný obchod prostřední osobou; ř. 31 jde do souhrnného
  hlášení s kódem 2. Do kontrolního hlášení nepatří žádný z nich.

## Rozhodnutí k designu (potvrzená, #164)

- ✓ **D1 — `cz-000` „Mimo DPH“.** Kód pro řádek, který není plněním
  (odpočet nezdaněné zálohy, kauce, místní poplatek, průběžná položka).
  Bez místa plnění a směru, nabízí se na každém dokladu s DPH. Je mimo
  rekapitulaci, nemá řádek výkazů. Nepohlcuje plnění s místem plnění mimo
  tuzemsko — ta mají vlastní kódy.
- ✓ **D2 — `null` znamená „nevyplněno“.** Položkový řádek dokladu s DPH
  bez kódu: v rozpracovaném dokladu varování, při potvrzení chyba.
  V importním módu zatím jen varování — rozhodne navazující issue.
- ✓ **D3 — Doklad Bez DPH kódy nenese.** Řádky bez kódu jsou na něm
  správně, kontrola z D2 se jich netýká.
- ✓ **D4 — Vstupní „Osvobozeno“ ve třech místech plnění:** `cz-114`
  Tuzemsko, `cz-214` EU, `cz-414` třetí země. V rekapitulaci, daň 0, bez
  řádku výkazů. Mimo tuzemsko se neodvozují, volí je uživatel.
- ✓ **D5 — `cz-112` a `cz-122` se ruší bez náhrady.**
  - **a)** Přijatý doklad od neplátce v tuzemsku vznikne celý Bez DPH
    s řádky bez kódu (nahrazuje nález B z #103, kde applier dával
    `cz-112` a nulovou rekapitulaci).
  - **b)** Řádek s 0 % na tuzemském dokladu plátce se odvodí jako
    `cz-114`.
  - **c)** `cz-000` se ze signálů neodvozuje. Volí ho uživatel (formulář,
    náhled návrhu) nebo přijde jako `vat.code` v payloadu.
- ✓ **D6 — Kategorie.** `zero` zaniká. `exempt` = „v rekapitulaci, daň 0“:
  `cz-123`, `cz-114`, `cz-214`, `cz-414`, `cz-201`, `cz-202`, `cz-401`
  a nové kódy z D8. Nová kategorie `outOfScope` = „mimo rekapitulaci“:
  `cz-000`. Zařazení `cz-202` / `cz-401` / `cz-209` / `cz-402` je
  technické (právně nejde o osvobození); rozdíl nese řádek přiznání.
- ✓ **D7 — Řádky `cz-000` vstupují do součtů hlavičky vždy**, i u převzaté
  rekapitulace.
- ✓ **D8 — Nové kódy výkazů:**

  | Kód | Název | Místo | Směr | Výkazy |
  |---|---|---|---|---|
  | `cz-124` | Tuzemsko/Výstup/Osvobozeno s nárokem na odpočet | domestic | output | ř. 26 |
  | `cz-209` | EU/Výstup/Ostatní s nárokem na odpočet | intracom | output | ř. 26, bez souhrnného hlášení |
  | `cz-402` | VÝVOZ/Výstup/Služby a ostatní | foreign | output | ř. 26 |
  | `cz-220` | EU/Vstup/Zboží — třístranný obchod | intracom | input | ř. 30 |
  | `cz-221` | EU/Výstup/Zboží — třístranný obchod | intracom | output | ř. 31, souhrnné hlášení kód 2 |

- ✓ **D9 — #89 D1 se nahrazuje, data se nemigrují.** Testovací zdroje se
  přeseedují, importované reimportují; žádný jednorázový příkaz.
- ✓ **D10 — Co se nepřidává, je v #165** (ř. 42, 47, 51 a další).

## Upřesnění pro implementaci

- **U1 — jediný znak „mimo rekapitulaci“ je kategorie `outOfScope`.**
  Žádný druhý příznak na kódu. Čte ji jedno místo:
  `VatRateResolver::isOutOfScopeCode(country, code)` a pro pole definic
  kódů (která `DocDocument` už drží v `$resolved['codes']`) statický
  pomocník v `docs.core` — např. `VatLineScope::inRecap(array $line,
  ?array $vatCodes): bool` = položkový řádek, neprázdný kód, kód není
  `outOfScope`. Všechna dnešní rozhodnutí `empty($row['vat_code'])` ve
  smyslu „řádek do rekapitulace nepatří“ přejdou na tohoto pomocníka.
- **U2 — kód `outOfScope` je univerzální.** `cz-000` nemá `place` ani
  `direction`; `getVatCodes()` ho vrací pro každý směr i místo (dnes by
  chybějící `place` znamenalo `domestic`). `validateCountryConfig()`
  hlídá: kód `outOfScope` nesmí mít `place`, `direction`, `vatReturnRow`,
  `reverseVatCode` ani `supplyKind`; ostatní kódy `place` i `direction`
  mít musí.
- **U3 — sazba.** `cz-000` i všechny nové kódy mají ve `vatPercents`
  záznam 0 bez omezení platnosti (jako dnes `cz-123`), takže
  `vat_rate_missing` u nich nevzniká. V nabídce kódů se u `cz-000`
  procento nevypisuje („Mimo DPH“, ne „Mimo DPH — 0 %“).
- **U4 — pořadí v nabídce.** `cz-000` je v nabídce vždy **poslední**.
  Nový řádek dál dostává první kód nabídky (`DocRowsForm` ~ř. 371) —
  nikdy `cz-000`. Test na pořadí.
- **U5 — výpočet řádku.** Řádek `cz-000`: `vat_pct` 0, `vat_amount` 0,
  `vat_total = vat_base`, v obou režimech cen — stejně jako dnes řádek bez
  kódu.
- **U6 — rekapitulace.** Řádky `cz-000` se neseskupují, nevstupují do
  kontroly převzaté rekapitulace ani do dorovnání řádků na rekapitulaci.
  Doklad jen s řádky `cz-000` rekapitulaci nemá (`willHaveVatRecap()`
  vrací false). Řádek **převzaté rekapitulace** s kódem `outOfScope` je
  chyba vždy (pole `recap`, kód `vat_recap_out_of_scope`).
- **U7 — součty (D7).** `sumTotals()` přičítá řádky `cz-000` vždy. Pro
  řádky **bez kódu** a řádky s běžným kódem, jehož skupina v rekapitulaci
  chybí, platí dnešní pravidlo beze změny (jen u přepočítané
  rekapitulace).
- **U8 — kontrola D2.** Nález `vat_code_missing` na `rows.N.vat_code`
  („Řádek nemá kód DPH. Vyberte kód, nebo „Mimo DPH“.“). Platí pro
  položkový řádek (`row_kind` 1) dokladu s `vat_mode` ≠ 0, u kterého se
  dohledala země registrace (jinak se nekontroluje, jako u ostatních
  kontrol DPH). Chyba při potvrzení dokladu — stejná podmínka jako
  `$requireFilled` u předmětu plnění —, jinak varování. **V importním módu
  vždy jen varování** (na rozdíl od předmětu plnění a `vat_rate_missing`).
  Uložení řádku ze sub-formuláře: varování.
- **U9 — doklad Bez DPH.** Chování kódů na řádcích dokladu s `vat_mode` 0
  se nemění.
- **U10 — applier, doklad od neplátce (D5a).** Nový druh dokladu mimo
  české DPH `supplierNonPayer`: přijatý doklad, naše registrace DPH platí,
  efektivní místo plnění tuzemsko, `vat.mode` je `none` (z dokladu nebo
  volbou uživatele) a žádný řádek nemá nenulovou sazbu. Doklad vznikne
  s `vat_mode` 0, řádky bez kódu, ceny řádků beze změny (neplátce daň
  neúčtuje, není co převádět). `_resolve.rows[].vatCode` jako u ostatních
  druhů (`matchedBy: "outOfScope"`), zpráva info `vat_supplier_non_payer`.
  Větev `$noVat` → `pct` 0 → `cz-112` zaniká. Deklarované `none`
  u dokladu, jehož řádky sazbu mají, řeší dál `vat_mode_derived`.
- **U11 — applier, odvozování a nabídka.**
  - kandidáti derivace nikdy neobsahují kód `outOfScope`;
  - `options()` (nabídka v náhledu a validace volby) kód `outOfScope`
    obsahuje vždy, pro každé místo, jako poslední;
  - `conflict()` u kódu `outOfScope` vrací vždy `null` (nemá místo, směr
    ani sazbu k porovnání);
  - `cz-214`, `cz-414`, `cz-220` se neodvodí (mimo tuzemsko derivace bere
    jen kódy s `reverseVatCode`) — test, že to tak zůstane;
  - `vat.code: "cz-000"` v payloadu projde jako platný kód.
- **U12 — výkazy.** `vat-reports-cz.jsonc`: `cz-000`, `cz-114`, `cz-214`,
  `cz-414` s `dp3` / `kh` / `sh` = `null`; `cz-124`, `cz-209`, `cz-402` →
  `dp3.row` 26; `cz-220` → `dp3.row` 30; `cz-221` → `dp3.row` 31
  a `sh.kod` 2; záznamy `cz-112` a `cz-122` pryč. Popisky `dp3Rows` pro
  26, 30, 31. Komentář tvarů `sh.kod` rozšířit na `0 | 2 | 3`. Ověř, že
  kód 2 projde `ShvXmlWriter` a XSD souhrnného hlášení a že řádky 30 a 31
  nevstupují do žádného součtu `VatReturnCalculator` (jsou to doplňující
  údaje).
- **U13 — účtování.** Nové kódy jsou trvale nulové — analytiku 343
  nemají; uprav komentář v `accountingRules.cz.jsonc` a seznam, o který se
  opírá `VatAnalyticsCompletenessTest`. Řádek `cz-000` se účtuje stejně
  jako dnes řádek bez kódu.
- **U14 — názvy.** `cz-000`: `name` / `fullName` / `print` „Mimo DPH“
  (en „Outside the scope of VAT“). `cz-114` „Tuzemsko/Vstup/Osvobozeno“,
  `cz-214` „EU/Vstup/Osvobozeno“, `cz-414` „DOVOZ/Vstup/Osvobozeno“;
  ostatní podle tabulky D8. `print` a překlady (`en`, `sk`, `de`) podle
  vzoru sousedních kódů. `cz-221` má `intracomCode` 2. Nové výstupní kódy
  `supplyKind` nemají. Kategorie `outOfScope`: „Mimo DPH“ / „Outside the
  scope of VAT“. Kódy v souboru zařaď do bloku svého místa a směru,
  `cz-000` do vlastního bloku na konec `vatCodes`.

## Co je potřeba udělat

### Commit 1 — číselník a výkazy: nové kódy (D1, D4, D6 část, D8; U1–U3, U12–U14)

- `vat-cz.jsonc`: kategorie `outOfScope`, kód `cz-000`, `cz-114`,
  `cz-214`, `cz-414`, `cz-124`, `cz-209`, `cz-402`, `cz-220`, `cz-221`
  a jejich `vatPercents`. `cz-112` / `cz-122` a kategorie `zero` zatím
  zůstávají (zanikají v commitu 3), hlavička souboru se přepíše až tam.
- `VatRateResolver`: `isOutOfScopeCode()`, univerzální kód v
  `getVatCodes()`, kontroly v `validateCountryConfig()`.
- `vat-reports-cz.jsonc` + popisky řádků; testy úplnosti zelené.
- Testy: `VatRateResolverTest` (univerzální kód ve všech směrech
  a místech, pořadí, validace konfigurace), mapování ř. 26 / 30 / 31,
  souhrnné hlášení s kódem 2.

### Commit 2 — docs.core: rekapitulace, součty, kontrola, nabídka (D1–D3, D7; U1, U4–U9)

- Pomocník „řádek patří do rekapitulace“ (U1) a jeho použití v
  `DocDocument`, `DocRowsDocument`, `DocRowCalculator` — projdi **každý**
  výskyt `vat_code` v `modules/docs/core/src/` a rozhodni, zda jde
  o „má kód“ (zůstává), nebo „patří do rekapitulace“ (pomocník).
- `sumTotals()` / `sumRowsOutsideRecap()` podle U7.
- Chyba `vat_recap_out_of_scope` (U6), kontrola `vat_code_missing` (U8).
- Nabídka kódů: `DocHeadVatContext::vatCodeOptions()` — `cz-000` poslední,
  bez procenta; výchozí kód nového řádku beze změny.
- Detail a sub-tabulka řádků: popisek kódu `cz-000` se zobrazí jako
  u jiných kódů.
- Testy (`DocDocumentVatRecapTest` a sousedé):
  - doklad s řádkem 21 % a řádkem `cz-000` — rekapitulace jen ze
    zdaněného řádku, částka dokladu = oba řádky, v obou režimech cen;
  - totéž s **převzatou** rekapitulací (D7) — částka dokladu obsahuje
    řádek `cz-000`; záporný řádek `cz-000` (odpočet zálohy) částku sníží;
  - doklad jen s `cz-000` nemá rekapitulaci, součty sedí;
  - `cz-000` v převzaté rekapitulaci = chyba;
  - řádek bez kódu: koncept varování, potvrzení chyba, importní mód
    varování, doklad Bez DPH nic;
  - `cz-114` je v rekapitulaci se základem a nulovou daní.

### Commit 3 — applier a zrušení `cz-112` / `cz-122` (D5, D6 zbytek; U10, U11)

- `DocumentApplier`: druh `supplierNonPayer` (U10), zrušení větve
  `$noVat` → `cz-112`.
- `VatCodeDerivation`: kandidáti bez `outOfScope`, `options()`,
  `conflict()` (U11).
- `vat-cz.jsonc`: odstranit `cz-112`, `cz-122` a jejich `vatPercents`,
  kategorii `zero`; `cz-201`, `cz-202`, `cz-401` do `exempt`; přepsat
  hlavičku souboru (co se proti starému číselníku liší a proč).
  `vat-reports-cz.jsonc` bez `cz-112` / `cz-122`.
- `SavedDocumentProjection::withoutZeroVat()` — srovnání s novým tvarem
  dokladu od neplátce (doklad Bez DPH); pokud projekce přestane být
  potřeba, odstraň ji.
- Testy a fixtury, které `cz-112` / `cz-122` používají (11 souborů
  v `tests/`): každý výskyt převeď podle významu — doklad od neplátce na
  Bez DPH, osvobozené plnění na `cz-114`, řádek mimo DPH na `cz-000`,
  výstupní „bez daně“ na `cz-000` nebo `cz-123`. Nemaž test jen proto,
  že kód zanikl.
- Nové testy: tuzemsko 0 % → `cz-114`; doklad od neplátce → Bez DPH bez
  kódů a bez rekapitulace, zpráva `vat_supplier_non_payer`; volba
  `useCode:cz-000` v náhledu projde pro každé místo plnění; `vat.code:
  "cz-000"` v payloadu projde; `cz-214` / `cz-414` / `cz-220` se neodvodí.
- Datové sady: `dataset-seed` + `dataset-verify` veřejných sad. Snímky,
  které se změní (doklady od neplátce, případné řádky „bez daně“),
  **neschvaluj potichu** — vypiš je v „Poznámky k implementaci“ s důvodem.

### Commit 4 — dokumentace, nápověda, stav

- `docs/vat-calculation.md`: § 1 a § 4 — tři stavy řádku dokladu s DPH
  (kód v rekapitulaci / `cz-000` mimo rekapitulaci, v částce / bez kódu =
  nevyplněno), D7 u převzaté rekapitulace (§ 5), kontrola
  `vat_code_missing`.
- `docs/exchange-format.md` § 8.4: tabulka odvození (`cz-112` pryč, 0 % →
  `cz-114`), doklad od neplátce jako další druh dokladu mimo české DPH,
  nové kódy zpráv (`vat_supplier_non_payer`), `cz-000` v nabídce.
- `modules/world/vat/README.md`, `modules/docs/core/README.md`,
  `CLAUDE.md` → Dokumentový systém: krátký odstavec „Kód DPH řádku“
  (tři stavy, jediné místo pravidla).
- `help/`: projdi stránky, které zmiňují kód DPH, „Bez daně“ nebo
  „Osvobozeno“ (`slovnicek.md`, `uctarna/dph-podani.md`,
  `posta/kontrola-vytezeni.md`, `posta/kdyz-ai-cte-spatne.md`,
  `faktury-vydane/*`, `pokladna/*`) — kdy zvolit „Mimo DPH“, co znamená
  varování u řádku bez kódu, doklad od neplátce je Bez DPH. Co nejde
  (ř. 42 a další z #165) do `help/co-dnes-nejde.md`.
  `python3 scripts/help-index.py`.
- `tasks/exchange-received-foreign-vat.md` a
  `tasks/vat-reverse-charge-subjects.md`: poznámka, že #89 D1 nahrazuje
  #164 D1–D2.
- `**Stav:**` tohoto tasku, `python3 scripts/tasks-index.py`, řádek
  v indexu podle oblastí (`tasks/README.md` → Dokladový systém).

### Ověření na dev zdroji (ukázkový zdroj s třídou dat `free`, sada plátce DPH)

1. `ds-upgrade`; ve formuláři přijaté faktury je „Mimo DPH“ poslední
   v nabídce kódů, nový řádek dostane běžný kód.
2. Faktura: řádek 21 % + záporný řádek „Mimo DPH“ — rekapitulace jen
   21 %, částka dokladu snížená; deník má stranu 321 ve výši částky
   dokladu.
3. Řádek bez kódu: koncept se uloží s varováním, potvrzení skončí chybou
   na řádku.
4. Vydaná faktura s `cz-402` → přiznání ř. 26; `cz-221` → ř. 31
   a souhrnné hlášení kód 2 (XML projde validací XSD).
5. Zpráva s dokladem od neplátce → návrh Bez DPH, bez kódů.
6. `dataset-verify` veřejných sad.

## Mimo rozsah

- Význam `null` ve výměnném formátu, importní mód applieru, signál „mimo
  DPH“ pro AI a ISDOC — navazující issue o ukládání dokladů.
- Runner importu ze starého Shipardu a mapování `EUCZ000` / `EUCZ112` /
  `EUCZ122`. **Počítej s tím**, že po tomto tasku import dokladů s těmito
  kódy padá a uložené doklady s nimi nejdou znovu uložit, dokud neproběhne
  třetí etapa (#164 → Rozsah a pořadí).
- Ř. 42, 47, 51 a další řádky přiznání — #165.
- Text o osvobození na tisku (`vatNotes`) pro nové výstupní kódy.
- Migrace dat (D9).

## Pasti

- `empty($row['vat_code'])` má v `DocDocument` dva významy. „Řádek nemá
  kód“ (kontroly sazby, předmět plnění) zůstává; „řádek nepatří do
  rekapitulace“ přechází na pomocníka z U1. Záměna prvního za druhé by
  u `cz-000` vypnula kontroly, záměna druhého za první by `cz-000` poslala
  do rekapitulace — a `docs_core_vat_recap.vat_code` by ho uložil.
- `getVatCodes()` dnes bere chybějící `place` jako `domestic`. Univerzální
  kód musí projít filtrem i pro `intracom` a `foreign` a pro oba směry.
- `VatCodeDerivation::pick()` vrací kód jen z **právě jednoho** kandidáta.
  Kdyby `cz-000` zůstal mezi kandidáty, 0 % v tuzemsku by mělo dva
  a skončilo by `vat_code_unknown`.
- Dokud v commitu 1–2 existuje `cz-112` vedle `cz-114`, má 0 % v tuzemsku
  dva kandidáty. `cz-114` proto do kandidátů derivace pusť až s odstraněním
  `cz-112` v commitu 3 (nebo oba kroky udělej v jednom commitu) — testy
  musí být zelené po každém commitu.
- `validateDeclaredRecap()` porovnává součty řádků per skupina
  s rekapitulací — řádky `cz-000` do žádné skupiny nepatří a nesmějí
  vyvolat „skupina v rekapitulaci chybí“.
- `VatPeriodLockProvider` se ptá `willHaveVatRecap()`. Doklad jen
  s řádky `cz-000` rekapitulaci nemá a do zamčeného období se nepočítá.
- Některým řádkům formulář pole Kód DPH vůbec nenabízí
  (`DocRowsForm::hasRowSideLayout()`). Kontrola `vat_code_missing` je
  nesmí hlásit — ověř, které operace to jsou, a vyjmi je stejným
  pravidlem jako formulář.
- Tisk a ISDOC: řádek `cz-000` se tiskne mezi řádky, v rekapitulaci není,
  částka k úhradě ho obsahuje. Ověř tiskovou fixturu a že `IsdocWriter`
  u takového řádku nevytvoří daňový součet (`TaxSubTotal` jen
  z rekapitulace).
- `VatPositionReader::KIND_ZERO` s kategorií `zero` nesouvisí — neměnit.
- Kompilovaná konfigurace: změna `.jsonc` se projeví až po rekompilaci
  a `ds-upgrade`.

## Hotovo když

- [ ] `cz-000` je v číselníku, nabízí se na každém dokladu s DPH jako
      poslední, není v rekapitulaci a je v částce dokladu — i u převzaté
      rekapitulace
- [ ] řádek dokladu s DPH bez kódu: varování v konceptu, chyba při
      potvrzení, varování v importním módu
- [ ] `cz-112`, `cz-122` a kategorie `zero` v repu nejsou (kód, konfigurace,
      testy, dokumentace)
- [ ] přijatý doklad od neplátce v tuzemsku vzniká Bez DPH bez kódů
- [ ] 0 % v tuzemsku se odvodí jako `cz-114`
- [ ] `cz-124`, `cz-209`, `cz-402` plní ř. 26, `cz-220` ř. 30, `cz-221`
      ř. 31 a souhrnné hlášení kód 2; testy úplnosti mapování zelené
- [ ] cílené testy zelené, `dataset-verify` veřejných sad `ok`, změněné
      snímky vypsané v poznámkách
- [ ] `docs/`, `help/`, `CLAUDE.md` a `**Stav:**` aktualizované ve stejných
      commitech jako kód
