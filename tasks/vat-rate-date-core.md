# Task: Datum sazby DPH — task A: model, výpočet, formuláře (docs.core)

**Stav:** naplánováno — zadání 2026-11-17 (#159 D1–D10, D13–D15)

**Issue:** #159

**Cíl:** Procento DPH na řádku dokladu a na řádku převzaté rekapitulace se
přestane zadávat ručně — vždy se odvodí z kódu DPH a **data sazby**. Datum
sazby určuje přepínač na hlavičce „Sazby DPH podle“: automaticky (DPPD),
vlastní datum dokladu, nebo vlastní datum na řádcích. Nabídka kódů ukazuje
jen kódy se sazbou k datu sazby, s procentem v popisku. Odchylka od běžné
sazby má na dokladu viditelnou stopu (datum), ne přepsané číslo.

Task A je první ze dvou v tomto repu: B (výměnný formát, applier, export
dokladu) navazuje. Po tasku A jde doklad s jinou sazbou než k DPPD pořídit
a opravit ručně.

## Před implementací přečti

- `docs/vat-calculation.md` — § 4.1 (předmět plnění: vzor pravidel sloupce
  řádku a rekapitulace), § 5 (převzatá rekapitulace — **mění se**: procento
  už není vstup), § 5.2 (datum sazby opravného dokladu)
- `docs/edit-forms.md` — select s `triggers: 'reload'`, recalculate,
  sub-tabulky
- `tasks/corrective-doc-core.md` U6 — dnešní jedno místo data sazby
- `modules/docs/core/src/DocHeadVatContext.php` — `vatRateDate()`,
  `load()` (klíč `vat_rate_date`), `vatCodeOptions()`
- `modules/docs/core/src/ReverseChargeSubjects.php` — **vzor** třídy
  pravidel sloupce: `normalized()`, `problem()`, `headContext()`,
  `normalizeLine()`, `lineProblem()`, `options()` (čte `vat_rate_date`
  kontextu, ~ř. 116)
- `modules/docs/core/src/DocDocument.php` — `validate()` (~ř. 113),
  `validateDeclaredRecap()` (~ř. 285), `validateReverseChargeSubjects()`
  (~ř. 501), `computeAmounts()` (~ř. 848), `persistRowComputedColumns()`
  (~ř. 998), `buildVatRecapitulation()` (~ř. 1289),
  `takeOverVatRecapitulation()` (~ř. 1629)
- `modules/docs/core/src/DocRowsDocument.php`, `VatRecapDocument.php`,
  `DocHeadRecomputer.php` (`updateRecapInPlace()`)
- `modules/docs/core/src/DocRowsForm.php` — DPH blok (~ř. 159),
  `applyNewRecordDefaults()`, `deriveVatPct()` (~ř. 392), `recalculate()`
  (~ř. 556), `buildLiveSummary()`
- `modules/docs/core/src/VatRecapForm.php`
- `modules/docs/core/src/DocsHeadsFormBase.php` — hlavní tab s DUZP / DPPD
  (~ř. 700), `renderRecapRows()` (~ř. 852), `renderItemRows()` (~ř. 914,
  vzor podmíněného sloupce `$hasSubject`)
- formuláře s DPH nastavením: `IssuedInvoiceFormBase::buildSettingsTab()`,
  `ReceivedInvoiceFormBase` (data ~ř. 114, `buildSettingsTab()` ~ř. 181),
  `modules/docs/cashDocs/src/CashDocForm.php`,
  `modules/docs/cashRegister/src/CashRegisterForm.php`
- `modules/docs/core/src/DocsHeadsViewer.php` — `buildDetailRows()`
  (~ř. 898), `buildDetailVatRecap()` (~ř. 941)
- `modules/docs/core/config/vatRecapSources.jsonc` — vzor cfgItem enumu
- `modules/world/vat/config/vat-cz.jsonc` — `vatPercents`: kódy
  s platností (`cz-111` / `cz-121` bez sazby 2015–2023, `cz-301` /
  `cz-302` / `cz-310` / `cz-311` jen 2015–2023)

## Kontext

- Dnes je procento řádku jen předvyplněné z kódu k DUZP (opravný doklad
  k datu původního plnění) a uživatel ho smí přepsat. Nezůstane stopa
  (záměr, nebo překlep) a nabídka kódů nezná čas — nabízí všechny
  kategorie v každém roce, bez procent. Ruční procento u kódu, který
  k datu sazbu nemá, skončí ve špatném sloupci KH.
- Sazba se řídí dnem vzniku povinnosti přiznat daň; u přijatého dokladu
  je to DUZP z dokladu dodavatele, které se v KH B.2 uvádí jako DPPD.
  DUZP přijatého dokladu u nás řídí období přiznání (datum odpočtu), proto
  datem sazby není.
- Vyúčtování záloh zdaněných různými sazbami se vypořádá sazbou té které
  zálohy — jeden doklad nese víc sazeb (informace GFŘ k § 37a, příklad 3).
- Starý systém měl na hlavičce přepínač data pro procenta a řádek datum;
  procento se nezadávalo. Převod do nového (task B + import) je bezztrátový.
- Přechod pravidla z DUZP na DPPD nezmění sazbu na žádném řádku dnešních
  dat (ověřeno na všech zdrojích dev serveru, #159).

## Rozhodnutí k designu (potvrzená, #159)

- ✓ **D1** — Procento DPH se needituje: na řádku dokladu i řádku převzaté
  rekapitulace se vždy odvodí z kódu a data sazby
  (`VatRateResolver::resolveVatPct`). Pole procenta ve formulářích zmizí,
  poznámka „Lze přepsat pro doklady z jiného státu“ zaniká. Sloupec
  `vat_pct` zůstává jako uložená hodnota.
- ✓ **D2** — Přepínač „Sazby DPH podle“ na hlavičce (`vat_rate_source`):
  `0` Automaticky (DPPD), výchozí; `1` Vlastní datum dokladu; `2` Vlastní
  datum na řádcích. Tab Nastavení, jen daňové doklady s DPH.
- ✓ **D3** — Automatika: opravný doklad → datum plnění původního dokladu
  (#92 D4); jinak DPPD; bez něj DUZP; bez něj datum vystavení.
- ✓ **D4** — Vlastní datum dokladu (`vat_rate_date` na hlavičce, hlavní
  tab u DUZP a DPPD) řídí všechny řádky i řádky převzaté rekapitulace.
- ✓ **D5** — Vlastní datum na řádcích (`vat_rate_date` na řádku dokladu
  a řádku převzaté rekapitulace); prázdné = automatika. V seznamu řádků
  sloupec.
- ✓ **D6** — Data mimo svůj režim se při uložení mažou.
- ✓ **D7** — Nabídka kódů jen se sazbou k datu sazby, s procentem v popisku.
- ✓ **D8** — Převzatá rekapitulace: procento podle D1; jinou sazbu
  dodavatele ukáže `vat_recap_inconsistent`. Kopie přepočítaná → převzatá
  dá řádku datum prvního řádku skupiny.
- ✓ **D9** — Kód bez sazby k datu sazby je chyba, i v importním módu;
  vlastní datum pozdější než automatické je varování.
- ✓ **D10** — Help: u přijaté faktury vyplnit DPPD; vlastní datum jen tam,
  kde DPPD nestačí.
- ✓ **D13** — `ds-upgrade` jen přidá sloupce, procenta nepřepočítává.
- ✓ **D14** — Klíč kontextu `vat_rate_date` → `effective_rate_date`.
- ✓ **D15** (upřesnění D9) — Kód bez sazby k datu sazby je chyba při
  potvrzení dokladu, v importním módu a při uložení řádku ze sub-formuláře;
  v rozpracovaném dokladu jen varování. Vlastní datum dokladu prázdné při
  zdroji 1: do potvrzení platí automatika, při potvrzení chyba.

D11 (výměnný formát) řeší task B, D12 (pošta) je mimo rozsah.

**Upřesnění při psaní tasku** (implementační, v duchu D1–D10; U4 je
potvrzené jako D15):

- **U1 — Sloupce.**

  | tabulka | sloupec | typ | název |
  |---|---|---|---|
  | `docs_core_heads` | `vat_rate_source` | `enumInt`, default 0, cfgItem `docs.core.vatRateSources`, skupina `vat` | Sazby DPH podle |
  | `docs_core_heads` | `vat_rate_date` | `date`, nullable, skupina `dates` | Datum sazby DPH |
  | `docs_core_rows` | `vat_rate_date` | `date`, nullable | Datum sazby DPH |
  | `docs_core_vat_recap` | `vat_rate_date` | `date`, nullable | Datum sazby DPH |

  cfgItem `docs.core.vatRateSources` (`config/vatRateSources.jsonc`, vzor
  `vatRecapSources.jsonc`, registrace v `module.jsonc`): `0` Automaticky
  (DPPD) / Automatic (VAT obligation date), `1` Vlastní datum dokladu /
  Document date, `2` Vlastní datum na řádcích / Date per row. Popisy
  tabulek (`docs_core_*.md`) doplnit.
- **U2 — Jedno místo pravidel.** Nová třída `VatRateDates` (docs.core,
  vzor `ReverseChargeSubjects`):
  - `automaticDate(head)` — D3 (dnešní `DocHeadVatContext::vatRateDate`
    s DPPD před DUZP; staré jméno buď deleguje, nebo se nahradí ve všech
    konzumentech);
  - `headDate(head)` — D4: při zdroji 1 vyplněné `vat_rate_date` hlavičky,
    jinak automatika;
  - `lineDate(head, line)` — D5: při zdroji 2 datum řádku, prázdné =
    automatika; jinak `headDate`;
  - `normalize(head, line)` / `normalizeHead(head)` — D6 a vynucení zdroje
    0 na nedaňovém dokladu a dokladu bez DPH (`vat_mode` 0);
  - `pct(resolver, country, code, date)` — D1;
  - `problem(…)` — D9 (U4), kódy hlášek `vat_rate_missing`,
    `vat_rate_date_late`, `vat_rate_date_required`.
- **U3 — Kde se procento odvozuje.** Stejná místa jako předmět plnění:
  `DocDocument::computeAmounts()` (řádky před `calculateRowVat`),
  `buildVatRecapitulation()` / `takeOverVatRecapitulation()` (řádky
  rekapitulace), `DocRowsDocument::beforeSave()`,
  `VatRecapDocument::beforeSave()`. `persistRowComputedColumns()` zapisuje
  i `vat_pct` a `vat_rate_date` (uložení hlavičky bez řádků v payloadu —
  změna DPPD nebo přepínače se tak propíše do řádků v DB);
  `DocHeadRecomputer::updateRecapInPlace()` aktualizuje procento a datum
  řádků převzaté rekapitulace na místě, částky nemění.
- **U4 — Kód bez sazby k datu (D9, D15).** Chyba při potvrzení dokladu (stav
  40) a v importním módu; v rozpracovaném dokladu (koncept, V opravě)
  **varování** a procento řádku zůstane uložené. Jinak by změna DPPD na
  hlavičce nešla uložit, dokud nejsou opravené řádky, a řádky (nabídka
  kódů) se řídí uloženou hlavičkou — zablokovalo by se to. Uložení řádku
  ze sub-formuláře s kódem bez sazby k datu je chyba (formulář ho stejně
  nenabízí). Zdroj 1 bez vyplněného data: do potvrzení platí automatika,
  při potvrzení chyba `vat_rate_date_required`.
- **U5 — Rekapitulace v režimu 2.** Přepočítaná rekapitulace nese
  `vat_rate_date` prvního řádku skupiny (kód, sazba, předmět); kopie při
  přepnutí na převzatou ho tak převezme sama (D8). V režimech 0 a 1 je
  datum řádků rekapitulace NULL. Klíč skupiny se nemění — řádky s různým
  datem a stejným procentem jsou jedna skupina.
- **U6 — Oddaňovací pár** (`is_reverse_pair`) procento dál dědí
  z primární skupiny; vlastní kontrolu sazby k datu nemá.
- **U7 — Předmět plnění** (`ReverseChargeSubjects`) se ověřuje k datu
  sazby svého řádku (`lineDate`), ne k datu hlavičky; nabídka předmětů
  ve formuláři řádku k datu řádku.
- **U8 — Bez konfigurace nebo bez země registrace** (unit testy, doklad
  bez registrace DPH) se procento neodvozuje a zůstává hodnota ze vstupu —
  stejně degradovaně jako dnešní výpočet bez definic kódů.
- **U9 — Nabídka kódů (D7).** `DocHeadVatContext::vatCodeOptions()`
  dostane datum (řádku, resp. hlavičky) a vrátí jen kódy se sazbou k němu;
  popisek `„<fullName> — 21 %“`. Uložený kód mimo nabídku (rozpracovaný
  doklad po změně data) zůstane ve formuláři vidět s označením „k datu
  bez sazby“, aby ho šlo vědomě vyměnit.
- **U10 — Viditelnost stopy.**
  - hlavička: přepínač v tabu Nastavení všech formulářů s DPH
    (`triggers: 'reload'`); při zdroji 1 pole `vat_rate_date` na hlavním
    tabu hned za DPPD, s nápovědou „Sazby DPH všech řádků se berou k
    tomuto datu“;
  - sub-tabulka řádků a rekapitulace: při zdroji 2 sloupec „Datum sazby“
    (vzor podmíněného sloupce předmětu);
  - formulář řádku: při zdroji 2 pole `vat_rate_date` v bloku DPH
    (`triggers: 'reload'`, nápověda „Prázdné = podle dokladu“); procento
    jen v popisku kódu a v živém pruhu;
  - formulář řádku rekapitulace: totéž, pole procenta zmizí;
  - detail dokladu (viewer): při zdroji 1 a 2 řádek v přehledu DPH
    („Sazby DPH k 31. 12. 2012 — vlastní datum dokladu“, resp. „vlastní
    datum na řádcích“), při zdroji 2 datum u řádku v tabulce řádků.
- **U11 — Kontrola pozdějšího data (D9).** Vlastní datum (hlavičky nebo
  řádku) pozdější než automatické datum dokladu = varování
  `vat_rate_date_late`, uložení ani potvrzení neblokuje.
- **U12 — Mezistav do tasku B.** Applier dál posílá `vat_pct` z číselníku
  k DUZP; dokument ho podle D1 přepočítá k datu sazby. U přijatého dokladu,
  kde DPPD a DUZP leží na různých stranách změny sazby, může kód zvolený
  applierem k DUZP k DPPD sazbu nemít → chyba při potvrzení. Vyřeší task B
  (applier k datu sazby); v datech dnes takový doklad z pošty není.

## Co je potřeba udělat

### Commit 1 — sloupce, číselník, pravidla (D2–D6, D14, U1, U2)

1. Sloupce a cfgItem podle U1, `docs_core_*.md`.
2. `VatRateDates` podle U2; `DocHeadVatContext::load()` vrací
   `vat_rate_source`, `effective_rate_date` (D14 — přejmenovat ve všech
   konzumentech: `DocRowsForm`, `VatRecapForm`,
   `ReverseChargeSubjects::options()`, testy) a datum hlavičky.
3. Unit testy pravidel (`VatRateDatesTest`): automatika ve všech větvích
   D3 (opravný doklad, DPPD, DUZP, datum vystavení), zdroj 1 s datem
   i bez, zdroj 2 s prázdným i vyplněným datem řádku, mazání podle D6,
   vynucení zdroje 0 na nedaňovém dokladu.

### Commit 2 — výpočet a kontroly (D1, D8, D9, U3–U8, U11)

1. Odvození procenta podle U3 ve všech místech, zápis přes
   `persistRowComputedColumns()` a `DocHeadRecomputer`.
2. Kontroly podle U4 a U11 v `DocDocument::validate()` (řádky
   `rows.N.vat_code`, převzatá rekapitulace pole `recap`),
   `DocRowsDocument::validate()`, `VatRecapDocument::validate()`.
   `validateDeclaredRecap()` kontroluje sazbu k datu řádku rekapitulace.
3. Předmět plnění k datu řádku (U7).
4. Testy (`DocDocumentVatRateDateTest` + úpravy stávajících):
   - přijatá faktura DUZP 4. 1. 2013, DPPD 31. 12. 2012, kód Základní →
     řádek i rekapitulace 20 %;
   - zdroj 1 s datem 2012 na dokladu z 2013 → 20 %, bez data → 21 %;
   - závěrečná faktura (zdroj 2): běžné řádky 2014 → 21 %, řádek odpočtu
     zálohy s datem 2012 → 20 %; rekapitulace má dvě skupiny téhož kódu;
   - doklad 2024, řádek odpočtu s datem 2023 a kódem Druhá snížená → 10 %;
     tentýž kód bez data → v konceptu varování, při potvrzení chyba;
   - přepnutí 2 → 0 smaže data řádků a vrátí procento automatiky;
   - převzatá rekapitulace: procento ze vstupu se přepíše odvozeným,
     částky zůstanou; dodavatel se starou sazbou → `vat_recap_inconsistent`;
   - kopie přepočítaná → převzatá v režimu 2 nese datum prvního řádku
     skupiny;
   - vlastní datum pozdější než automatické → varování;
   - import mód: kód bez sazby k datu → chyba.
   Stávající testy s procentem, které k datu neodpovídá číselníku: opravit
   přípravu dat (sazba z číselníku, nebo zdroj 1 / 2 s datem), **ne**
   očekávané částky. Test, který ruční procento ověřoval záměrně, přepsat
   na zdroj 1 / 2.

### Commit 3 — formuláře, sub-tabulky, detail (D1, D2, D4, D5, D7, U9, U10)

1. Přepínač ve formulářích s DPH nastavením (faktura vydaná, přijatá,
   pokladní doklad, prodejka; zálohové faktury ne — nejsou daňové),
   datum hlavičky na hlavním tabu podle U10.
2. `DocRowsForm`: pole procenta pryč, datum řádku při zdroji 2, nabídka
   podle U9, `recalculate()` při změně kódu i data řádku odvodí procento
   (`applyLiveCalculation` s odvozeným procentem), živý pruh ukazuje
   procento.
3. `VatRecapForm`: totéž; poznámka „Sazba z dokladu …“ zaniká.
4. Sub-tabulky a detail podle U10.
5. Testy formulářů: pole procenta ve formuláři není; datum řádku jen při
   zdroji 2; datum hlavičky jen při zdroji 1; nabídka kódů k datu 2020
   neobsahuje Sníženou (`cz-111` / `cz-121`), k datu 2024 neobsahuje První a Druhou
   sníženou; popisek nese procento; recalculate při změně data řádku
   přepočte procento; sloupec „Datum sazby“ v sub-tabulce jen při zdroji 2;
   detail ukazuje stopu jen při zdroji 1 / 2.

### Commit 4 — dokumentace, nápověda, stav (D10)

1. `docs/vat-calculation.md`: nový oddíl „Datum sazby DPH“ (D1–D9, U4–U6,
   nahrazuje § 5.2 a doplňuje § 5 — procento převzaté rekapitulace už není
   vstup, částky ano); `docs/docs-mvp.md` (sloupce); `CLAUDE.md` (odkaz
   `DocHeadVatContext::vat_rate_date` → `VatRateDates`, jeden odstavec do
   Systému dokladů).
2. Nápověda: nová stránka `help/uctarna/datum-sazby-dph.md` (kdy a jak:
   přijatá faktura přes změnu sazby → DPPD; vlastní datum dokladu;
   závěrečná faktura s odpočtem záloh v různých sazbách → data na
   řádcích); upravit `faktury-vydane/vystaveni-faktury.md` (procento už
   nejde přepsat), `faktury-prijate/dokonceni-dokladu.md` (DPPD u přijaté
   faktury, D10), `pokladna/pokladni-doklad.md`,
   `faktury-vydane/zalohova-faktura.md`,
   `faktury-prijate/zalohova-faktura-prijata.md`,
   `faktury-vydane/opravny-doklad.md` (automatika = datum původního
   plnění); `python3 scripts/help-index.py`.
3. `**Stav:**` tohoto tasku, řádek v `tasks/README.md`,
   `python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (ukázkový zdroj s třídou dat `free`)

- `ds-upgrade`; existující doklady mají zdroj 0, data prázdná, procenta
  beze změny (`dataset-verify` na zdroji ze seedu datové sady `ok`).
- Přijatá faktura z ledna 2013 s DPPD v prosinci 2012: nabídka kódů
  „… — 20 %“, rekapitulace 20 %.
- Faktura vydaná, zdroj 2, řádek odpočtu zálohy s datem v předchozím
  roce: v sub-tabulce sloupec Datum sazby, rekapitulace se dvěma
  skupinami; detail ukazuje stopu.
- Koncept: změna DPPD na datum, ke kterému kód řádku sazbu nemá → uložení
  s varováním, potvrzení s chybou; po výměně kódu projde.
- Přepnutí zpět na Automaticky smaže data a vrátí procenta.

## Mimo rozsah

- Výměnný formát, applier, export dokladu a datových sad (D11) — task B.
- Import ze starého systému (runner, mimo toto repo) — po tasku B.
- Pošta (AI): doklad s odpočtem zálohy ve staré sazbě dál končí
  „doklad založ ručně“ (D12).
- Automatické datum řádku odpočtu z navázaného daňového dokladu k záloze.
- Tisk se nemění — procenta tiskne z rekapitulace jako dnes.
- Zařazení do období DPH, KH a zámky (`VatPeriodAssigner`) se nemění —
  datum sazby do nich nevstupuje.

## Pasti

- **`validate()` běží před `beforeSave()`** — pravidla čti z dat
  (`VatRateDates` nad payloadem), ne z výsledků výpočtu.
- **Uvíznutí rozpracovaného dokladu** — kód bez sazby k datu musí jít
  v konceptu uložit s varováním (U4), jinak nejde opravit hlavičku ani
  řádky.
- **Uložení hlavičky bez řádků v payloadu** — změna DPPD nebo přepínače
  musí přepsat procento a smazat data v řádcích v DB
  (`persistRowComputedColumns()`), jinak zůstane rozpor do příštího
  uložení řádku.
- **Převzatá rekapitulace je pořád fakt v částkách** — `DocHeadRecomputer`
  smí na místě měnit jen procento, datum a odvozené sloupce, nikdy základ,
  daň a celkem.
- **Přejmenování klíče `vat_rate_date` v kontextu** — `grep` přes
  `modules/docs` a `tests` (`--include=*.php`), ať nezůstane konzument,
  který tiše dostane null.
- **Datum sazby ≠ DUZP** — období přiznání, KH a zámky dál čtou
  `vat_duzp` / `vat_dppd` jako dnes.
- **Unit testy bez konfigurace** (U8) procento neodvozují — test pravidla
  D1 musí konfiguraci mít.

## Hotovo když

- [ ] Procento DPH nejde ve formuláři řádku ani řádku rekapitulace zadat;
      vždy odpovídá kódu a datu sazby.
- [ ] Přepínač „Sazby DPH podle“ funguje ve všech třech hodnotách, data
      mimo režim se mažou.
- [ ] Nabídka kódů je podle data, s procentem v popisku.
- [ ] Kontroly D9 / U4 / U11 hlásí podle stavu dokladu a importního módu.
- [ ] Stopa (datum sazby) je vidět v sub-tabulce i v detailu.
- [ ] Existující doklady beze změny procent (`dataset-verify` `ok`).
- [ ] Dokumentace a nápověda popisují nové chování.
- [ ] Testy zelené (cíleně, pak celá sada).
- [ ] `**Stav:**` aktualizován, `python3 scripts/tasks-index.py`.
