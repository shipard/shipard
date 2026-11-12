# Task: Předmět plnění u tuzemského přenesení daňové povinnosti — samostatný údaj řádku a rekapitulace

**Stav:** částečně — kód, korpus, schválené snímky sad a ověření na dev zdroji hotové 2026-11-12 (8 commitů); zbývá legislativní ověření číselníku, kontrola XML na portálu, `ai-eval` a komentář do #89

**Issue:** #89 (task B; task A je `exchange-received-foreign-vat.md`)

**Cíl:** Kód předmětu plnění pro kontrolní hlášení (oddíly A.1 a B.1) dnes
nese kód DPH, a číselník proto zná jen předměty 4 a 5. Doklad s jiným
předmětem (mobilní telefony, kovy, obiloviny, povolenky, poskytnutí
pracovníků …) nejde zadat správně a návrh z pošty u něj končí chybou.
Nově je předmět plnění **samostatný údaj** řádku dokladu i rekapitulace,
vybíraný z číselníku s platností v čase; kódy DPH nesou jen místo, směr
a sazbu. Kódy `cz-117` a `cz-152` (přenesení DP5) zanikají.

Návaznost: roadmapa M1 (kontrolní hlášení) a M2 (návrh přijaté faktury).
Navazuje na #86, #87 a #55. Na tasku A nezávisí; dělá se po něm.

## Před implementací přečti

- `docs/vat-calculation.md` — celé; rekapitulace je autorita, řádky jsou
  informativní
- `docs/document-system.md` — hooky, validace, child tabulky
- `docs/table-definitions.md` → *Bezpečné změny typu sloupce*
- `docs/exchange-format.md` — § 8.4 (derivace kódu, volby v náhledu),
  § 9 (`_resolve`, `userAction`), tabulka issues
- `docs/accounting.md` → *Konvence DPH analytik*
- `docs/edit-forms.md` kap. 15 (sub-tabulky) a `docs/edit-forms-cookbook.md`
- `tasks/exchange-received-reverse-charge.md`, `tasks/exchange-preview-vat-choices.md`,
  `tasks/taxes-phase01.md` (mapování výstupů), `tasks/vat-filing-xml.md`
- `tasks/exchange-contact-name.md` → Pasti (tři kopie schématu, editace
  `prompt_template`)

## Kontext — co je dnes

- **Kód DPH nese předmět plnění.** `modules/world/vat/config/vat-cz.jsonc`:
  vstup `cz-115` / `cz-116` / `cz-340` (`reverseChargeCode: 4`), `cz-117`
  (`5`); výstup `cz-150` / `cz-151` / `cz-350` (`4`), `cz-152` (`5`).
  Příznak `reverseCharge: 1` značí tuzemské přenesení.
- **Kontrolní hlášení čte předmět z mapování kódu.**
  `modules/economy/vat/config/vat-reports-cz.jsonc` (`kh.kodPredPl`: 4 | 5),
  `VatOutputsMapping::kh()`, `ControlStatementCalculator` (~ř. 198, skupina
  = oddíl + předmět), `FilingComposer` (~ř. 222 a 381). Typ je číslo:
  sloupce `kh_kod_pred_pl` (`economy_vat_filing_items`) a `kod_pred_pl`
  (`economy_vat_filing_cs_rows`) jsou `tinyint`, `Kh1XmlWriter` (~ř. 96)
  přetypovává `(string) (int)`.
- **Řádek předmět neukládá.** `docs_core_rows` ani `docs_core_vat_recap`
  sloupec nemají; canonical nese `rows[].vat.reverseChargeCode` (řetězec),
  applier ho použije jen k výběru kódu (`VatCodeDerivation::derive()`)
  a zahodí. `ApplyFidelityCheck` na to má výjimku věrnosti.
- **Prompt předmět vrací.** Text promptu (v4.11.0) říká „"4" stavební
  a montážní práce, "5" zboží z přílohy 5, jinak číslo předmětu plnění
  uvedené na dokladu“. Pro jiný předmět derivace nenajde kód →
  `vat_code_unknown`.
- **Rekapitulace** se skládá per (kód DPH, sazba) —
  `DocDocument::vatGroupKey()` (~ř. 2044) a jeho volání
  (`buildVatRecapitulation`, `takeOverVatRecapitulation`,
  `reconcileRowsToRecap`, kontroly součtů).
- **Účtování:** analytika 343 per kód DPH (`accountingRules.cz.jsonc`,
  konvence `343{NNN}`); `AccountingEngine::matchesQuery()` porovnává
  libovolný sloupec zdrojového záznamu, tedy i sloupec rekapitulace.
- **Starý Shipard** znal jen předměty 4 a 5 (`EUCZ115` … `EUCZ152`).

## Legislativní podklad

Číselník *Kód předmětu plnění* pro A.1 / B.1 podle metodické informace
Finanční správy k vyplnění kontrolního hlášení (stav k 1. 1. 2024):

| Kód | Předmět plnění | Ustanovení ZDPH | Platí od |
|-----|----------------|-----------------|----------|
| 1 | Zlato | § 92b odst. 1 a 2 | — |
| 1a | Zlato — zprostředkování dodání investičního zlata | § 92b odst. 3 | 2017-07-01 |
| 3 | Dodání nemovité věci | § 92d odst. 1 | — |
| 3a | Dodání nemovité věci v nuceném prodeji | § 92d odst. 2 | 2017-07-01 |
| 4 | Stavební a montážní práce | § 92e odst. 1 | — |
| 4a | Stavební a montážní práce — poskytnutí pracovníků | § 92e odst. 2 | 2017-07-01 |
| 5 | Zboží uvedené v příloze č. 5 | § 92c | — |
| 6 | Dodání zboží poskytnutého původně jako záruka | § 92ea písm. a) | 2017-07-01 |
| 7 | Dodání zboží po postoupení výhrady vlastnictví | § 92ea písm. b) | 2017-07-01 |
| 11 | Povolenky na emise skleníkových plynů | § 92f | — |
| 12 | Obiloviny a technické plodiny | § 92f | — |
| 13 | Kovy | § 92f | — |
| 14 | Mobilní telefony | § 92f | — |
| 15 | Integrované obvody | § 92f | — |
| 16 | Přenosná zařízení pro automatizované zpracování dat | § 92f | — |
| 17 | Videoherní konzole | § 92f | — |
| 18 | Dodání certifikátů elektřiny | § 92f | 2016-02-01 |
| 19 | Dodání elektřiny soustavami nebo sítěmi obchodníkovi | § 92f | 2016-02-01 |
| 20 | Dodání plynu soustavami nebo sítěmi obchodníkovi | § 92f | 2016-02-01 |
| 21 | Poskytnutí vymezených služeb elektronických komunikací | § 92f | 2016-10-01 |

„—“ = bez omezení (`validFrom` prázdné); kontrolní hlášení samo začíná
1. 1. 2016.

**K ověření před uzavřením tasku** (dělá chat / člověk, výsledek do
komentáře číselníku a do #89):

- seznam proti pokynům ke kontrolnímu hlášení ve vyhlášce č. 387/2025 Sb.
  (příloha 12, účinná od 1. 1. 2026);
- předměty 11–21 stojí na dočasném režimu (§ 92f, nařízení vlády
  č. 361/2014 Sb.); článek 199a směrnice o DPH dnes platí do 31. 12. 2026,
  návrh na prodloužení do 30. 6. 2030 nebyl k říjnu 2026 přijat. `validTo`
  se nastaví, jen když se změní **český** předpis.

## Rozhodnutí k designu (potvrzená)

Číslování navazuje na D1–D5 tasku A (obojí #89).

- ✓ **D6 — číselník předmětů.** `world.vat.cz` dostane sekci
  `reverseChargeSubjects`: klíč = kód (řetězec), `name` / `name:cs` /
  `name:en`, `legal` (ustanovení), `validFrom`, `validTo` (prázdné = bez
  omezení). Čte ho jen `VatRateResolver` (seznam, seznam platný k datu,
  ověření jednoho kódu k datu).
- ✓ **D7 — samostatný údaj.** Sloupec `reverse_charge_code` (krátký
  řetězec, nullable) v `docs_core_rows` i `docs_core_vat_recap`. Klíč
  skupiny rekapitulace = (kód DPH, sazba, předmět). Kód DPH s příznakem
  `reverseCharge` předmět **vyžaduje**, jiný kód ho mít nesmí (při uložení
  se vynuluje). Platnost předmětu se ověřuje k datu sazby
  (`DocHeadVatContext::vatRateDate()`).
- ✓ **D8 — kódy DPH.** `cz-115`, `cz-116`, `cz-340`, `cz-150`, `cz-151`,
  `cz-350` jsou obecné kódy přenesení: z názvů mizí „DP4“, z definic
  `reverseChargeCode`. **`cz-117` a `cz-152` se ruší.** Žádná migrace dat
  a žádný výchozí předmět z kódu — testovací zdroje se přeseedují,
  importované se reimportují.
- ✓ **D9 — účtování.** Odpočet u předmětu 5 se dál účtuje na 343117
  (pravidlo předpisu s `query` na `vat_code` + `reverse_charge_code`, před
  obecným řádkem `cz-115`), aby deník po reimportu seděl se starým systémem
  po účtech. Řádek předpisu pro `cz-152` a účet 343152 v seed osnovách
  zanikají.
- ✓ **D10 — výměnný formát.** `rows[].vat.reverseChargeCode` se ukládá
  na řádek; přibývá nepovinné `vatRecap[].reverseChargeCode`. Kód přenesení
  bez předmětu = error `reverse_charge_code_missing`, předmět mimo číselník
  nebo neplatný k datu = error `reverse_charge_code_unknown` — **i v importním
  módu**, nic se tiše nedoplňuje. Exporter oba údaje vyváží.
- ✓ **D11 — derivace.** Tuzemské přenesení (`vat.reverseCharge: true`,
  místo tuzemsko) → obecný vstupní kód přenesení základní sazby + předmět
  z `rows[].vat.reverseChargeCode`. Sníženou sazbu volí uživatel výběrem
  kódu v náhledu, jako dnes.
- ✓ **D12 — volba v náhledu.** Předmět jde zvolit u řádku i hromadně:
  cesta `rows[i].reverseChargeCode`, akce `useValue:<kód>`, ve stejné mapě
  rozhodnutí jako kód DPH (#76, #87). Nabídka `_resolve.reverseChargeOptions`
  = předměty platné k datu; volba mimo nabídku = error
  `reverse_charge_code_pin_invalid`. Chybějící předmět blokuje jednoklik
  (`allDecided()`).
- ✓ **D13 — výstupy.** Kontrolní hlášení A.1 / B.1 bere předmět
  z rekapitulace dokladu; `kodPredPl` z `vat-reports-cz.jsonc` mizí. Kód je
  řetězec od snapshotu podání po XML. Přiznání a souhrnné hlášení beze změny.
- ✓ **D14 — formuláře a tisk.** Pole **Předmět plnění** na řádku dokladu
  (přijaté i vydané doklady, pokladna) viditelné a povinné u kódu přenesení;
  totéž u ruční rekapitulace. Poznámky `pdp4` / `pdp5` se sloučí do jedné
  `pdp`. Předmět se na faktuře netiskne.
- ✓ **D15 — prompt a help.** Prompt v4.12.0 s výčtem předmětů a jejich
  popisem; `vatRecap[].reverseChargeCode` model nevyplňuje. Help aktualizovaný.
- ✓ **D16 — korpus.** Nová varianta s předmětem mimo 4 a 5.

## Datový model

```jsonc
// modules/world/vat/config/vat-cz.jsonc
"reverseChargeSubjects": {
    "4":  { "name": "Construction and assembly work", "name:cs": "Stavební a montážní práce",
            "legal": "§ 92e odst. 1" },
    "4a": { "name": "Construction and assembly work — provision of workers",
            "name:cs": "Stavební a montážní práce — poskytnutí pracovníků",
            "legal": "§ 92e odst. 2", "validFrom": "2017-07-01" },
    "14": { "name": "Mobile phones", "name:cs": "Mobilní telefony", "legal": "§ 92f" }
    // … celý seznam z tabulky výše
}
```

- `docs_core_rows.reverse_charge_code`, `docs_core_vat_recap.reverse_charge_code`
  — typ krátkého řetězce podle `docs/table-definitions.md` (3 znaky stačí),
  nullable, bez indexu.
- `economy_vat_filing_items` a `economy_vat_filing_cs_rows`: **nové**
  řetězcové sloupce (`kh_reverse_charge_code`, `reverse_charge_code`);
  staré `kh_kod_pred_pl` / `kod_pred_pl` z definic odstranit. `ds-upgrade`
  změnu `tinyint` → řetězec neprovádí a sloupce nemaže — staré zůstanou
  v databázi nepoužité.

## Chování

**Doklad (`DocDocument`)**

| Řádek / řádek rekapitulace | Výsledek |
|---|---|
| kód přenesení + platný předmět | uloží se; rekapitulace má skupinu per předmět |
| kód přenesení bez předmětu, Koncept | uloží se (Koncept = minimum) |
| kód přenesení bez předmětu, Potvrzení a výš | chyba validace na řádku (`rows.N.reverse_charge_code`) |
| předmět mimo číselník / neplatný k datu sazby | chyba validace vždy |
| jiný kód DPH (nebo bez kódu) + předmět | předmět se vynuluje, bez hlášky |

Oddaňovací pár (`cz-203` / `cz-204` / `cz-370`) nese předmět své skupiny.
Dva řádky se stejným kódem a sazbou, ale různým předmětem = dvě skupiny
rekapitulace (a dva páry). Převzatá rekapitulace (`declared`) předmět
přebírá ze vstupu; řádek rekapitulace s kódem přenesení bez předmětu je
stejná chyba jako u řádku.

**Applier (přijatý doklad, větev derivace)**

| Vstup | Výsledek |
|---|---|
| tuzemské přenesení, `reverseChargeCode` platný | `cz-115` + předmět, bez issue |
| tuzemské přenesení, `reverseChargeCode` chybí | `cz-115`, error `reverse_charge_code_missing` na řádku — řeší volba v náhledu |
| `reverseChargeCode` mimo číselník / neplatný k datu | error `reverse_charge_code_unknown` s hodnotou ve zprávě — řeší volba v náhledu |
| volba uživatele `useValue:<kód>` | zvolený předmět (`source: "user"`); mimo nabídku `reverse_charge_code_pin_invalid` |
| efektivní kód řádku není kód přenesení | předmět se ignoruje (i z volby) |
| vstupní kód `cz-117` / `cz-152` | `vat_code_unknown` (kód neexistuje) |

`_resolve.rows[i].reverseChargeCode {value, source: "document" | "user", status}`
jen u řádků s kódem přenesení; `_resolve.reverseChargeOptions [{code, label}]`
jednou na doklad.

**Kontrolní hlášení:** skupina = oddíl + předmět z řádku rekapitulace
(řetězec). Řádek rekapitulace s kódem přenesení bez předmětu (starý nebo
rozpracovaný doklad) skončí stávající chybou validace podání „chybí kód
předmětu plnění“ — žádná výchozí hodnota.

## Kroky a commity

Každý commit nechává testy zelené; pořadí drž. Pokud commit 2 bez commitu 3
testy zelené nenechá (applier ukládá doklady s kódem přenesení dřív, než
předmět umí předat), slouč je do jednoho.

### Commit 1 — `feat(world.vat): číselník předmětů plnění tuzemského přenesení (#89 D6, 1/7)`

- `vat-cz.jsonc`: sekce `reverseChargeSubjects` (celá tabulka výše, zdroj
  a datum ověření v komentáři). Stávající kódy beze změny.
- `VatRateResolver`: čtení seznamu, seznamu platného k datu a ověření kódu
  k datu; `README.md` modulu.
- Testy `VatRateResolverTest` (platnost `4a` před a po 1. 7. 2017, neznámý kód).

### Commit 2 — `feat(docs): předmět plnění na řádku a v rekapitulaci dokladu (#89 D7, D14, 2/7)`

- Sloupce v `docs_core_rows` a `docs_core_vat_recap`.
- `DocDocument`: `vatGroupKey()` se třetí složkou a všechna jeho volání;
  skládání i převzetí rekapitulace, dorovnání řádků, kontroly součtů;
  validace a nulování podle tabulky; `computeAmounts()` (náhled) stejně.
- `DocRowsForm`: select **Předmět plnění** (předměty platné k datu sazby),
  viditelný a povinný jen u kódu s `reverseCharge`; po změně kódu se
  přepočítá viditelnost (stávající `triggers: 'reload'`). `VatRecapForm`
  obdobně. Sub-tabulky řádků a rekapitulace (`renderSubtable()`), detail
  dokladu — předmět u kódu přenesení zobrazit.
- Popisky sloupců z definice tabulky (`name:cs` „Předmět plnění“).
- Stávající testy a datové sady s kódy přenesení doplň o předmět
  (kódy `cz-117` / `cz-152` zatím zůstávají — s předmětem `5`).
- Testy: `DocDocumentVatRecapTest`, `DocDocumentVatRecapDeclaredTest`,
  `DocDocumentRecapReconciliationTest`, `DocDocumentPdpOutputTest`,
  `DocDocumentValidateTest`, `DocRowsFormTest` — dva předměty na jednom
  dokladu, pár per předmět, validace per stav.
- `docs/vat-calculation.md` (klíč skupiny, předmět plnění), `modules/docs/core/README.md`.

### Commit 3 — `feat(exchange): předmět plnění ve výměnném formátu, derivaci a exportu (#89 D10, D11, 3/7)`

- Schéma `shpd.docs.document.v1`: `vatRecap[].reverseChargeCode` — všechny
  tři kopie (`.jsonc`, `.json`, inline v profilu pošty).
- `DocumentApplier`: předmět z canonicalu (a z rekapitulace) do dokladu,
  issues `reverse_charge_code_missing` / `reverse_charge_code_unknown`,
  i v importním módu; `_resolve.rows[i].reverseChargeCode`.
- `VatCodeDerivation`: tuzemské přenesení nevybírá kód podle předmětu —
  kandidát je vstupní kód s `reverseCharge` a `reverseVatCode` základní
  sazby; předmět derivace jen ověří proti číselníku. V tomto commitu ještě
  existuje `cz-117` — vyřaď ho z kandidátů výslovně (kód s vlastním
  `reverseChargeCode` v definici), ať výběr zůstane jednoznačný.
- `DocumentExporter` (řádky i rekapitulace), `CorrectiveDocBuilder`
  (opravný doklad přebírá předmět z původních řádků),
  `SavedDocumentProjection` (předmět z řádku, ne z definice kódu),
  `ApplyFidelityCheck` — zrušit výjimku `rows[].vat.reverseChargeCode`.
- Testy `DocumentApplierTest`, `VatCodeDerivationTest`, exporter, ISDOC
  round-trip (`IsdocCorpusRoundTripTest`) beze změny zelený.
- `docs/exchange-format.md`: pole, derivace, issues.

### Commit 4 — `feat(vat): kontrolní hlášení čte předmět plnění z rekapitulace; kód jako řetězec (#89 D13, 4/7)`

- `VatDocumentSelection` vrací předmět rekapitulace; `ControlStatementCalculator`,
  `FilingComposer`, `VatControlStatementLiveBuilder` skupinují podle něj.
- Nové řetězcové sloupce snapshotu (viz Datový model); `Kh1XmlWriter` bez
  přetypování na int, `vat-xml-cz.jsonc`, `FilingXmlInputLoader`,
  `FilingXmlValidator`, `FilingPdfService`, `FilingsViewer`,
  `EpoXmlLineComparer` a import podání z XML (`modules/economy/vat/src/Import/`).
- `vat-reports-cz.jsonc`: odstranit `kodPredPl` a upravit komentář;
  `VatOutputsMapping::kh()`.
- Datové sady: `VatExpectations` / `VatReportingCheck` porovnávají řetězec.
- Testy: `ControlStatementCalculatorTest` (předměty `4`, `4a`, `14` na jednom
  dokladu = tři řádky), `VatReportsMappingCompletenessTest`,
  `VatXmlMappingCompletenessTest`, `tests/Unit/Module/Economy/Vat/Xml/`.
- Dokumentace modulu (`modules/economy/vat/docs/README.md`).

### Commit 5 — `refactor(world.vat): obecné kódy přenesení, zrušení cz-117 a cz-152 (#89 D8, D9, 5/7)`

- `vat-cz.jsonc`: přejmenování šesti kódů (všechny jazykové varianty
  `fullName` / `name`), odstranění `reverseChargeCode` z definic, odstranění
  `cz-117` a `cz-152` včetně `vatPercents`; `vatNotes` `pdp4` + `pdp5` → `pdp`.
  Úvodní komentář souboru.
- `accountingRules.cz.jsonc`: řádek `343117` s `query`
  `{"vat_code": "cz-115", "reverse_charge_code": "5"}` **před** obecným
  řádkem `cz-115`; odstranit řádek `cz-152`. Ověř, že záznam rekapitulace
  předávaný do `matchesQuery()` nový sloupec nese (`buildVatLines()`).
  Seed osnovy (`accountChartDefault.jsonc`, `accountChartNpo.jsonc`):
  343152 odstranit, 343117 ponechat s názvem bez „DP5“ v kódu.
  `VatAnalyticsCompletenessTest` upravit na nové pravidlo.
- `vat-reports-cz.jsonc`: odstranit záznamy obou kódů.
- `VatCodeDerivation`: zrušit dočasné vyřazení z commitu 3.
- Testy, korpus a datové sady: `grep -rl --include=*.php --include=*.jsonc
  --include=*.json -e 'cz-117' -e 'cz-152' tests modules docs` — přepsat na
  `cz-115` / `cz-150` + předmět `5`; přegenerovat datové sady.
- `docs/accounting.md` (konvence analytik — výjimka 343117),
  `docs/docs-mvp.md` § 4, `modules/core/mail/docs/ai-prompts.md`,
  `docs/exchange-format.md` (příklady).

### Commit 6 — `feat(mail): volba předmětu plnění v náhledu návrhu; prompt v4.12.0; help (#89 D12, D15, 6/7)`

- `MessageProposalApplier::USER_ACTION_ROW_PATH_RE` + `reverseChargeCode`;
  expand, sanitizace, persistence jako u `vatCode`.
- `DocumentApplier::vatPins()` čte volbu předmětu; nabídka
  `_resolve.reverseChargeOptions`; `reverse_charge_code_pin_invalid`.
- Náhled: výběr předmětu u řádku s kódem přenesení a hromadně pro všechny
  takové řádky (vzor `VatCodeDecisionPanel.svelte`), gating v `reviewGate.js` /
  `allDecided()`, nový náhled po uložení. Texty `cs.js` / `en.js`.
- Prompt v4.12.0: pravidlo `rows[].vat.reverseChargeCode` s výčtem předmětů
  a krátkým popisem (model opisuje kód z dokladu, limit 100 000 Kč
  neposuzuje); `vatRecap[].reverseChargeCode` vynechat. Changelog
  v `modules/core/mail/docs/ai-prompts.md`.
- Help: `help/co-dnes-nejde.md`, `help/posta/kdyz-ai-cte-spatne.md`,
  `help/posta/kontrola-vytezeni.md`, stránky o řádcích dokladu a o podání
  DPH, které předmět plnění zmiňují; `python3 scripts/help-index.py`.

### Commit 7 — `test(corpus): tuzemské přenesení s předmětem 14; task hotový (#89 D16, 7/7)`

- Nová varianta korpusu podle `docs/test-corpus.md` § 14: tuzemský
  dodavatel, `vatTreatment: "reverseCharge"`, `reverseChargeCode: "14"`,
  štítek `it.hardware`, základ daně nad 100 000 Kč. Matice § 6,
  přegenerované `tests/datasets/`, očekávání DPH (`VatExpectations`).
- Snímky deníku datových sad schvaluje člověk. Když build korpusu na stroji
  nejde, commit nedělej a zapiš to do hlavičky tasku.
- `**Stav:**` tasku, `python3 scripts/tasks-index.py`.

## Ověření na dev zdroji

Na volném zdroji dat (třída `free`) po `ds-upgrade` a přeseedování:

1. Vydaná faktura s kódem přenesení: bez předmětu nejde potvrdit; s předměty
   `4` a `14` na dvou řádcích má rekapitulace dvě skupiny a kontrolní hlášení
   A.1 dva řádky.
2. Přijatá faktura s předmětem `4a`: B.1 s kódem `4a`, XML projde validací
   proti schématu a kontrolou portálu; přiznání ř. 10 a 43 beze změny.
3. Předmět `5`: odpočet zaúčtovaný na 343117, ostatní předměty na 343115.
4. Návrh z pošty s tuzemským přenesením: předmět z dokladu se převezme;
   návrh bez předmětu jde dořešit výběrem v náhledu (u řádku i hromadně),
   jednoklik je do té doby zablokovaný.
5. Předmět neplatný k datu (`4a` s datem plnění před 1. 7. 2017) — chyba
   ve formuláři i v návrhu.
6. Datové sady: `dataset-seed` + `dataset-verify` bez nálezů.

## Mimo rozsah

- Migrace existujících dat (D8) — přeseed / reimport.
- Runner importu ve starém Shipardu: `EUCZ117` → `cz-115` + `5`,
  `EUCZ152` → `cz-150` + `5`, ostatní kódy přenesení + `4`, na řádcích
  i v rekapitulaci (`old_shipard:modules/imports/newShipard/libs/VatCodeMap.php`,
  `DocsRunner`). Do té doby import dokladů s přenesením končí chybou D10.
- Tisk předmětu plnění na faktuře; vážení / množství u přílohy č. 5.
- Automatické posouzení limitu 100 000 Kč u předmětů 12–17.
- Výchozí předmět podle partnera nebo položky.

## Pasti

- **Jedna autorita.** Výstupy čtou předmět jen z rekapitulace, nikdy z řádků
  ani z definice kódu. Po commitu 5 nesmí v kódu zůstat čtení
  `reverseChargeCode` z definice kódu DPH (`grep`).
- **`reverseCharge` × `reverseVatCode`.** Tuzemské přenesení pozná příznak
  `reverseCharge` (vstup i výstup); `reverseVatCode` mají i kódy EU a dovozu,
  které předmět nemají.
- **Klíč skupiny všude.** `vatGroupKey()` se volá na deseti místech; jedno
  zapomenuté rozbije dorovnání řádků na rekapitulaci. Prázdný předmět musí
  dát stejný klíč jako dnes (žádný rozdíl u dokladů bez přenesení).
- **Změna typu sloupce.** `ds-upgrade` `tinyint` → řetězec ignoruje — proto
  nové sloupce, ne přetypování.
- **Tři kopie schématu** a `prompt_template` jako jeden JSON řetězec —
  viz Pasti v `tasks/exchange-contact-name.md`; hlídají `SchemaDriftTest`
  a `ProfileSchemaDriftTest`.
- **Pořadí pravidel předpisu.** Řádek 343117 musí být před obecným řádkem
  `cz-115` (první odpovídající vyhrává). Porovnání v `matchesQuery()` je
  volné — předmět `5` jako řetězec.
- **Generované doklady.** Řádky fakturačního předpisu zakázky
  (`economy.workOrders`) s kódem přenesení předmět nenesou — vygenerovaná
  faktura zůstane v Konceptu a nepůjde potvrdit. Ověř, jestli předpis kód
  přenesení dovolí; pokud ano, zapiš nález do `tasks/TODO.md`, neřeš ho tady.
- **Historie řádků.** `RowHistoryEnricher` doplňuje kód DPH z dřívějších
  dokladů partnera — předmět se nedoplňuje, bere se z dokladu nebo z volby.
- **Prompt stojí peníze.** Po změně promptu evaluaci (`ai-eval`) nespouštěj;
  rozhoduje o ní člověk.
- **XML.** Ověř v popisu struktury kontrolního hlášení, že atribut
  `kod_pred_pl` přijímá hodnoty s písmenem (`4a`), a zkontroluj test proti XSD.

## Hotovo když

- [x] Číselník předmětů s platností, sloupce na řádku a v rekapitulaci,
      validace a formuláře podle D6, D7, D14; testy `DocDocument|DocRowsForm|VatRateResolver`
      zelené.
- [x] Výměnný formát, derivace a export podle D10, D11; testy
      `DocumentApplier|VatCodeDerivation|SchemaDrift` zelené.
- [x] Kontrolní hlášení čte předmět z rekapitulace, kód je řetězec až do XML
      (D13); testy `ControlStatement|Filing|VatReportsMapping|VatXml` zelené.
- [x] `cz-117` a `cz-152` v repozitáři nejsou (kromě historických tasků
      a tohoto zadání); 343117 pro předmět 5 (D8, D9); testy
      `VatAnalyticsCompleteness|AccountingEngine` zelené.
- [x] Volba předmětu v náhledu, prompt v4.12.0, help (D12, D15); build
      frontendu, i18n parita, `help-index.py`.
- [x] Varianta korpusu s předmětem 14 (nebo zapsaný důvod, proč ne) —
      varianta 12; snímky hlavní knihy sad schválené 2026-11-12.
- [ ] Legislativní ověření (vyhláška č. 387/2025 Sb. příloha 12, stav
      k 1. 1. 2027) zapsané v komentáři číselníku a v #89.
- [ ] Ověření na dev zdroji (body 1–6), `tasks-index.py`, výsledek do #89 —
      ověření a index hotové 2026-11-12 (viz Stav implementace), komentář
      do #89 a kontrola XML na portálu zbývají.

## Stav implementace (2026-11-12)

Sedm commitů na `stable` podle Kroků a commitů, pořadí dodrženo, commit 2
a 3 zůstaly oddělené (celá sada byla po commitu 2 zelená); osmý commit
přebírá schválené snímky hlavní knihy sad.

### Odchylky od zadání a upřesnění

- **343 analytiku odvozovala z kódu DPH ještě dvě místa mimo engine** —
  uzávěrka analytik při zaúčtování přiznání (`VatReturnAccountingService`)
  a křížová kontrola přiznání proti deníku (`VatJournalCrossCheck`).
  S pravidlem 343117 (D9) by uzávěrka nulovala špatný účet a kontrola
  hlásila falešný rozdíl. Commit 5 proto zavádí sdílené vyhodnocení `query`
  (`RuleQuery::matches()`) a `VatAnalytics` (analytika řádku rekapitulace
  podle pravidel předpisu); součet daně snapshotu se dělí podle předmětu
  jen tam, kde má vlastní účet (klíč `kód|předmět`).
- **`reverseChargeCode` zmizel z definic šesti obecných kódů už v commitu 3**
  — dočasné vyřazení `cz-117` podle „vlastního `reverseChargeCode`
  v definici“ by jinak vyřadilo i `cz-115`.
- **Předmět ověřuje applier, ne derivace** — u každého řádku, na kterém
  skončí kód přenesení (i explicitní kód: import, vystavené doklady, opravný
  doklad, fakturace zakázek). `VatCodeDerivation::derive()` už parametr
  předmětu nemá; číselník čte `reverseChargeSubject()` /
  `reverseChargeSubjectOptions()` tamtéž.
- **Chybějící předmět blokuje jen stav V pořádku (40), ne V opravě (80)** —
  jinak by starý doklad bez předmětu nešel otevřít k opravě. Formulář řádku
  pole značí jako povinné, uložení řádku v Konceptu bez předmětu ale projde
  (Koncept = minimum); chybu hodnoty (mimo číselník / neplatná k datu)
  hlásí řádek i hlavička vždy.
- **`allDecided()` neexistuje** — brána je `reviewGate.js` (nový druh
  reference `reverseChargeCode`) a `REFRESH_PATH_RE` v modalu.
- **`vatGroupKey()` má osm volání** (ne deset); všechna jdou přes
  `vatGroupKeyOf()`.
- **Regex předmětu** v projekci korpusu a v `invoicegen/corpus.py` rozšířen
  na `^[0-9]{1,2}[a-z]?$` (`4a`); zmrazená kopie pro evaluace beze změny.
- **Detail dokladu a sub-tabulka řádků kód DPH neukazují** — předmět je
  vidět u sazby v rekapitulaci (sub-tabulka, tab, detail, náhled návrhu)
  a v sub-tabulce řádků jako sloupec, jen když ho některý řádek nese.
- **Fakturační předpis zakázek kód přenesení dovolí** a předmět nenese —
  nález zapsán do `tasks/TODO.md` (periodická fakturace u takové zakázky
  končí chybou `reverse_charge_code_missing`) a do `help/co-dnes-nejde.md`.
- **Varianta korpusu 12 má řádky pod 80 000 Kč i s daní** — nad tím štítek
  `it.hardware` návrh účtu nedává (`amountGuard`) a kontrola položek sady
  hlásí rozdíl; základ dokladu zůstává nad 100 000 Kč.

### Ověření

- Testy: celá sada PHPUnit zelená po každém commitu (na konci 11 510 testů),
  frontend 291 testů, `check:i18n`, build, generátor
  korpusu 324 testů; integrační `AccountingEngineTest` (46 testů vč. 343117)
  nad volným zdrojem dat.
- Datové sady (bod 6): `dataset-seed` + `dataset-verify` na zdrojích korpusu
  `ob9b-7` (plátce), `r1lf-w` (neplátce), `wp66-o` (spolek) — kontroly 1–5
  v pořádku u všech tří (přiznání i KH včetně B.1 s kódem 14). Kontrola 6
  (deník proti schválenému snímku) se lišila přesně o dva nové doklady:
  plátce 321100 a 501201 + 229 180,00, 343115 a 343203 + 48 127,80; neplátce
  a spolek 321100 a 501201 + 277 307,80. Nové snímky schválil člověk
  2026-11-12; po převzetí do `profiles/<profil>/approved/`
  a `build.py --refresh-approved` dává `dataset-seed` + `dataset-verify`
  u všech tří sad 6 kontrol z 6 v pořádku.
- Proklikání sady plátce v prohlížeči (`dataset-e2e`): 24 / 24 návrhů
  použito přes náhled, bez chyb prohlížeče; jediný nález byla kontrola 6
  před schválením snímků.
- Body 1–3 a 5 přes skutečné API na volném testovacím zdroji `060z-b`:
  vydaná faktura s `cz-150` bez předmětu se nepotvrdí (applier 422
  `reverse_charge_code_missing`, hlavička 422 `rows.0.reverse_charge_code`);
  s předměty 4 a 14 má rekapitulace dvě skupiny a živé KH v A.1 dva řádky;
  přijatá faktura s předměty 4a a 5 má v B.1 dva řádky (4a, 5), odpočet
  4a na 343115 a 5 na 343117, oddanění na 343203, přiznání ř. 10 a 43
  = základ 30 000 a zpráva „Součty daně souhlasí s účetním deníkem“;
  snapshot podání nese kódy jako řetězce. Formulář řádku k DUZP 15. 6. 2017
  předmět 4a nenabízí a uložení s ním odmítne.
- Bod 4 v headless prohlížeči nad zdrojem `ob9b-7` (náhled zprávy upravený
  tak, jako by doklad předmět nenesl): řádky ukazují „předmět chybí“,
  Použít je zablokované, volba u řádku i hromadná volba předmět doplní,
  rekapitulace ukáže „předmět 14“ u odpočtu i oddaňovacího řádku.
- **Neověřeno:** XML kontrolního hlášení na zdroji dat nevzniklo (testovací
  zdroj nemá vyplněnou hlavičku podání — typ subjektu a finanční úřad);
  kód `4a` proti schématu portálu ověřuje jen test
  `Kh1XmlWriterTest::testSubjectWithLetterIsWrittenAsIsAndValidates`.
  Kontrola na portálu a platnost předmětu k datu v návrhu z pošty (jen
  testy applieru — zdroje dat nemají registraci DPH platnou v roce 2017).
- Nová identita korpusu ověřena v ARES a VIES samostatně; hromadné
  `--verify-identities` neprošlo kvůli přetížení VIES, `verifiedAt` zůstává
  na 2026-11-11.

### Zbývá (člověk)

- Legislativní ověření číselníku proti vyhlášce č. 387/2025 Sb. příloha 12
  — komentář v `vat-cz.jsonc` říká „ověření čeká“.
- Kontrola XML s kódem `4a` na daňovém portálu; `ai-eval` po změně promptu.
- `ds-upgrade` zdrojů dat (nové sloupce, prompt v4.12.0); podání DPH
  sestavená dřív přepočítat, testovací zdroje přeseedovat, importované
  reimportovat (D8). Runner importu ve starém Shipardu je mimo rozsah.
- Push a komentář do #89.
