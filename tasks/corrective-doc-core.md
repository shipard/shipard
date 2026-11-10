# Task: Opravný daňový doklad — task A: datový model, dokument, formulář, detail

**Stav:** naplánováno — zadání 2026-11-10 (D1–D11 zamčené v #92)

**Issue:** #92

**Cíl:** Faktura vydaná jde označit jako **opravný doklad** k jiné faktuře:
nese odkaz na původní doklad (nebo jen jeho číslo), datum plnění původního
dokladu a důvod opravy. Sazby DPH se určují k datu původního plnění, kurz
se nepřepisuje z lístku. Detail ukazuje vazbu z obou stran.

Task A je první ze tří: B (`corrective-doc-exchange.md`) přidá výměnný
formát a akci „Vystavit opravný doklad“, C (`corrective-doc-outputs.md`)
tisk a ISDOC. Po tasku A jde opravný doklad pořídit ručně.

Priorita: věcná správnost a náležitosti dokladu (`docs/roadmap.md` →
Pravidlo prioritizace, body 1 a 2). Dobropis je dnes jen faktura se
zápornými částkami — bez odkazu na původní doklad, bez důvodu opravy a se
sazbou DPH k datu opravy.

## Před implementací přečti

- `docs/vat-calculation.md` — sazba kódu DPH k datu, režimy `vat_mode`
- `docs/exchange-rates.md` § 5.1 — kdy formulář přepisuje kurz
- `docs/edit-forms.md` — lookup (`lookup.filter`, `TableLookup`,
  ~ř. 1900–1930), recalculate, kap. 23 (formuláře dokladů)
- `docs/frontend.md` — akce a odkazy v detailu (`open_detail`, ~ř. 1130)
- `docs/design-system.md` — detail záznamu, seznam vlastností, štítky
- `docs/accbal.md` D18 — dobropis v saldokontu (beze změny)
- `tasks/doc-row-unit-price-negative-qty.md` — řádky se záporným množstvím
- `modules/docs/core/tables/docs_core_heads.jsonc` — skupiny sloupců,
  vzor reference (`partner`, `work_order`), indexy
- `modules/docs/core/config/docTypes.jsonc`, `src/DocTypes.php` — vzor
  příznaku typu (`total_rounding_auto`, `tax_document`)
- `modules/docs/core/src/DocDocument.php` — `validate()` (~ř. 111),
  `validateDeclaredRecap()` (datum sazby ~ř. 294), `beforeSave()`
  (~ř. 452), `isLockExempt()` (import mód ve validaci)
- `modules/docs/core/src/DocHeadVatContext.php` — `vat_rate_date`
  (~ř. 52); spotřebitelé `DocRowsForm` (~ř. 385) a `VatRecapForm` (~ř. 100)
- `modules/docs/core/src/DocsHeadsFormBase.php` — `applyExchangeRate()`
  (~ř. 147), `recalculate()`, vzor zaškrtávátka s `triggers: 'reload'`
- `modules/docs/core/src/IssuedInvoiceFormBase.php` — `buildSettingsTab()`
- `modules/docs/core/src/DocsHeadsViewer.php` — řádek seznamu, přehled
  detailu, `detailActions()`
- `modules/economy/workOrders/src/WorkOrdersLookup.php` — vzor lookupu

## Kontext

- **Starý Shipard** má na hlavičce jen příznak `correctiveDoc`, bez vazby
  na původní doklad a bez důvodu opravy. Používal se u části záporných
  vydaných faktur, u přijatých nikdy.
- **Nový Shipard** dnes: dobropis = faktura vydaná se zápornými částkami.
  Saldokonto ho řeší znaménkem, kontrolní hlášení řadí podle absolutní
  hodnoty dokladu, přiznání bere záporné částky do stejných řádků. To
  všechno je pro opravný doklad správně a **nemění se**.
- Co chybí: náležitosti opravného dokladu (číslo původního dokladu, důvod
  opravy) a pravidlo, že oprava používá sazbu daně a kurz původního
  plnění.

## Rozhodnutí k designu (potvrzená)

- ✓ **D1** — Opravný doklad je faktura vydaná (`invno`) s příznakem na
  hlavičce, ne nový typ dokladu. Dobropis a vrubopis rozliší znaménko.
  Saldokonto, účtování, přiznání k DPH ani kontrolní hlášení se nemění.
- ✓ **D2** — Vazba na původní doklad je 1:1, bez podřízené tabulky. Na
  hlavičce: příznak, odkaz na původní doklad (nepovinný), číslo původního
  dokladu jako text, důvod opravy.
- ✓ **D3** — Pátý sloupec: datum plnění původního dokladu. S odkazem se
  vyplní samo, bez odkazu ručně.
- ✓ **D4** — Sazba DPH se určuje k datu plnění původního dokladu, ne
  k datu opravy. Kurz u cizí měny se převezme z původního dokladu.
- ✓ **D5** — Při potvrzení je povinný důvod opravy. Odkaz ani číslo
  původního dokladu povinné nejsou (souhrnný opravný doklad); prázdné
  obojí dá varování. Import mód nic nevyžaduje.
- ✓ **D7** — Detail původní faktury ukazuje odkazy na své opravné
  doklady (jedna faktura jich může mít víc).
- ✓ **D11** — Mimo rozsah: přijaté opravné doklady, vratky na prodejkách,
  oprava výše daně v jiných případech (§ 43 ZDPH), nedobytné pohledávky.

D6 a D10 řeší task B, D8 a D9 task C.

**Upřesnění při psaní tasku** (implementační, v duchu D1–D5, D7):

- **U1 — Sloupce.** Nová skupina sloupců `correction` („Oprava“ /
  „Correction“) v `docs_core_heads`:

  | sloupec | typ | název |
  |---|---|---|
  | `corrective` | `boolean`, default 0, not null | Opravný doklad |
  | `corrected_doc` | `int`, nullable, reference `docs_core_heads` | Původní doklad |
  | `corrected_doc_number` | `varchar` 40, nullable | Číslo původního dokladu |
  | `corrected_doc_date` | `date`, nullable | Datum plnění původního dokladu |
  | `correction_reason` | `text`, nullable | Důvod opravy |

  Index nad `corrected_doc` (zpětný dotaz D7).
- **U2 — Které typy.** Příznak `"corrective": true` v `docTypes.jsonc`
  u `invno`, čtení v `DocTypes` (vzor `hasTotalRoundingAuto()`). Na typu
  bez příznaku dokument při uložení `corrective` vynuluje a ostatní čtyři
  sloupce nastaví na NULL. Totéž na dokladu s `corrective` = 0.
- **U3 — Denormalizace z odkazu.** Je-li vyplněný `corrected_doc`, dokument
  při uložení přepíše `corrected_doc_number` číslem původního dokladu
  (`doc_number`) a `corrected_doc_date` jeho datem plnění (`vat_duzp`, bez
  něj `issue_date`). Bez odkazu zůstávají obě hodnoty ruční.
- **U4 — Validace odkazu** (vždy, i v konceptu): původní doklad existuje,
  je typu s příznakem z U2 a není to doklad sám (`corrective_doc_invalid`).
  Při potvrzení (stav 40) navíc: původní doklad je ve stavu V pořádku
  a má stejného partnera (`corrective_doc_state`,
  `corrective_doc_partner_mismatch`). Opravný doklad k opravnému dokladu
  je povolený.
- **U5 — Validace D5.** Při potvrzení mimo import mód: prázdný
  `correction_reason` → chyba `correction_reason_required`; prázdný odkaz
  i číslo → varování `correction_original_missing` („Opravný doklad nemá
  číslo původního dokladu — u souhrnné opravy uveď vymezení původních
  dokladů v důvodu opravy.“).
- **U6 — Datum sazby DPH (D4).** Jedno místo, které vrací datum pro
  hledání sazby: u opravného dokladu s vyplněným `corrected_doc_date` toto
  datum, jinak dnešní pravidlo (DUZP, bez něj datum vystavení). Použít ve
  všech místech, kde se dnes pravidlo opakuje: `DocHeadVatContext`
  (`vat_rate_date` → `DocRowsForm`, `VatRecapForm`)
  a `DocDocument::validateDeclaredRecap()`. Zařazení do období DPH, KH
  a zámky období se dál řídí DUZP opravného dokladu — těch se změna
  netýká.
- **U7 — Kurz (D4).** `DocsHeadsFormBase::applyExchangeRate()` u opravného
  dokladu kurz z lístku nedotahuje a nepřepisuje. Po výběru původního
  dokladu se kurz převezme z něj; bez odkazu ho uživatel zadá ručně
  (souhrnný doklad). Doklad v domácí měně má kurz 1 jako dnes.
- **U8 — Formulář.** Jen faktura vydaná (ne zálohová). V tabu Nastavení
  sekce „Oprava“: zaškrtávátko `corrective` (`triggers: 'reload'`); při
  zapnutí se ukážou lookup `corrected_doc` (`triggers: 'reload'`), číslo
  a datum plnění původního dokladu (při vyplněném odkazu `readOnly`)
  a důvod opravy (víceřádkové pole). `recalculate('corrected_doc')`
  doplní číslo, datum plnění a kurz; partnera a měnu jen když jsou na
  dokladu prázdné. Zrušení odkazu hodnoty nechá a odemkne.
- **U9 — Lookup dokladů.** Nový `DocsHeadsLookup` nad `docs_core_heads`:
  hledá podle čísla dokladu, variabilního symbolu a názvu partnera,
  nabízí jen doklady ve stavu V pořádku, řádek ukazuje číslo, partnera,
  datum vystavení a částku. Povolené filtry `doc_type` a `partner`.
  Formulář posílá `doc_type` = typ dokladu a `partner`, je-li vyplněný.
- **U10 — Seznam a detail.** Řádek seznamu a hlavička detailu opravného
  dokladu nesou štítek „Opravný doklad“. Přehled detailu opravného
  dokladu má skupinu „Oprava“ (původní doklad jako odkaz `open_detail`,
  nebo jen číslo; datum plnění původního dokladu; důvod opravy). Přehled
  původní faktury má sekci „Opravné doklady“ — tabulka číslo, datum
  vystavení, částka, stav, řádek otevírá náhled (`open_detail`). Koncepty
  se v ní ukazují, stornované ne.

## Co je potřeba udělat

### Commit 1 — sloupce, příznak typu, dokument (D1–D3, D5, U1–U5)

1. Skupina a sloupce podle U1, index, `docs_core_heads.md`.
2. Příznak typu a čtení v `DocTypes` (U2).
3. `DocDocument`: vynulování na dokladech bez opravy (U2), denormalizace
   z odkazu (U3), validace (U4, U5). Texty hlášek česky, kódy z U4 a U5.
4. Unit testy (nový `DocDocumentCorrectiveTest`): každé pravidlo U2–U5
   včetně případů, kdy chyba ani varování padnout nemají (koncept bez
   důvodu, import mód, typ bez příznaku, souhrnný doklad jen s důvodem).
5. Integrační test přes `TableGateway` (ukázkový zdroj): faktura, k ní
   opravný doklad s odkazem — po uložení má číslo a datum plnění
   původního dokladu, potvrzení bez důvodu selže, s důvodem projde.

### Commit 2 — sazba k datu původního plnění a kurz (D4, U6, U7)

1. Jedno místo pro datum sazby (U6) a jeho použití ve všech třech
   místech.
2. `applyExchangeRate()` podle U7.
3. Testy: opravný doklad s datem původního plnění před změnou sazby
   dostane na řádku i v rekapitulaci tehdejší sazbu (vzor v testech
   `VatRateResolver` — sazba platná od data); bez data původního plnění
   sazbu k DUZP; běžný doklad beze změny. Formulář: změna účetního data
   opravného dokladu v cizí měně kurz nepřepíše
   (`DocsHeadsFormExchangeRateTest`).

### Commit 3 — lookup a formulář (D2, D3, U8, U9)

1. `DocsHeadsLookup` + registrace, testy lookupu (hledání, filtry, jen
   stav V pořádku).
2. Sekce „Oprava“ ve formuláři faktury vydané, `recalculate()` podle U8.
3. Testy formuláře: pole skrytá bez příznaku a u zálohové faktury;
   `readOnly` čísla a data při vyplněném odkazu; doplnění po výběru
   původního dokladu; partner a měna se nepřepisují, když jsou vyplněné.

### Commit 4 — seznam, detail, dokumentace (D7, U10)

1. Štítek v seznamu a hlavičce detailu, skupina „Oprava“, sekce „Opravné
   doklady“ na původní faktuře (U10). Texty přes katalog popisků vieweru.
2. Testy vieweru: opravný doklad s odkazem a bez; původní faktura se
   dvěma opravnými doklady; faktura bez oprav sekci nemá.
3. Dokumentace: `docs/vat-calculation.md` (datum sazby u opravného
   dokladu), `docs/exchange-rates.md` § 5.1 (kurz), `docs/docs-mvp.md`
   nebo README modulu `docs.core` (sloupce a pravidla), `CLAUDE.md` →
   Systém dokladů (jeden odstavec).
4. Nápověda: nová stránka `help/faktury-vydane/opravny-doklad.md` (ruční
   pořízení; akce z detailu přijde s taskem B), odkaz ze stránky
   vystavení faktury, `co-dnes-nejde.md` uvést do souladu,
   `python3 scripts/help-index.py`.
5. `**Stav:**` tohoto tasku, `python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (ukázkový zdroj)

- `ds-upgrade`; existující doklady mají `corrective` 0 a ostatní sloupce
  prázdné.
- Faktura vydaná → nová faktura, příznak Opravný doklad, výběr původní
  faktury: číslo a datum plnění se doplní a zamknou.
- Řádek se záporným množstvím: sazba k datu původního plnění.
- Potvrzení bez důvodu opravy selže; s důvodem projde; bez odkazu i čísla
  projde s varováním.
- Detail opravného dokladu ukazuje odkaz na původní fakturu, detail
  původní faktury sekci Opravné doklady; odkazy otevřou náhled.
- Saldokonto, deník a kontrolní hlášení se pro opravný doklad chovají
  stejně jako pro dnešní zápornou fakturu.

## Mimo rozsah

- Výměnný formát, import a akce „Vystavit opravný doklad“ — task B.
- Tisk a ISDOC — task C. Do té doby se opravný doklad tiskne jako faktura.
- Přijaté opravné doklady, vratky na prodejkách, § 43 ZDPH, nedobytné
  pohledávky (D11).
- Ochrana původní faktury před stornem, když má opravné doklady.
- Párování opravného dokladu s původní fakturou v saldokontu — zůstává
  na variabilním symbolu a zápočtu jako dnes.
- Výchozí DUZP opravného dokladu se nemění (datum vystavení, jde
  přepsat) — pravidlo se ověřuje s účetní, strukturu neovlivní.

## Pasti

- **`validate()` běží před `beforeSave()`** — denormalizace z odkazu (U3)
  a kontroly (U4) nesmí spoléhat na pořadí; odkaz čti z dat, původní
  doklad z databáze. Import mód ve validaci přes `isLockExempt()`.
- **Dokument bez databáze a konfigurace** (unit testy): pravidla závislá
  na původním dokladu se bez databáze přeskočí, vynulování podle U2 bez
  konfigurace neběží (vzor `applyTotalRoundingAuto()`).
- **Datum sazby není DUZP.** Období DPH, KH a zámky dál čtou `vat_duzp` —
  nezaměnit.
- **Kurz u dokladu v cizí měně bez odkazu**: prázdný kurz při potvrzení
  zastaví stávající kontrola „Kurz je povinný“ — správně.
- **Lookup nesmí nabízet doklad sám sobě** a u nového dokladu nemá id —
  kontrolu dělá dokument (U4), ne lookup.
- **Řádek seznamu** je sdílený pro všechny typy dokladů — štítek jen
  u dokladu s příznakem, bez dalšího dotazu na řádek.

## Hotovo když

- [ ] Fakturu vydanou jde označit jako opravný doklad, vybrat původní
      fakturu nebo zadat jen její číslo, a doplnit důvod opravy.
- [ ] Sazby DPH opravného dokladu odpovídají datu původního plnění;
      kurz se z lístku nepřepisuje.
- [ ] Potvrzení bez důvodu opravy selže, souhrnný doklad jen s důvodem
      projde s varováním.
- [ ] Detail ukazuje vazbu z obou stran.
- [ ] Existující doklady, saldokonto, deník a kontrolní hlášení beze změny.
- [ ] Dokumentace a nápověda popisují nové chování.
- [ ] Testy zelené (cíleně, pak celá sada).
- [ ] `**Stav:**` aktualizován, `python3 scripts/tasks-index.py`.
