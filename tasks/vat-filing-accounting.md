# Task: Zaúčtování přiznání DPH — účetní doklad per podání, správce daně, akce Zaúčtovat (M1 Fáze 4b) — #55 D28–D31

**Stav:** hotovo — 2026-09-12 na dev DS (4l3j: integrační testy + HTTP smoke,
btpg-p: zlatý test 01–04/2026); alfa vypuštěna 2026-11-17, ruční scénář
dodatečného podání → #158. Uzavřeno s #55. Odchylky viz níže.
**Issue:** #55 — komentář „Fáze 4 — Zámek a zaúčtování: rozhodnutí D23–D31 (2026-09-11)"
**Návaznost:** staví na podáních a jejich snapshotu (`economy_vat_filings`,
`economy_vat_filing_items`, `economy_vat_filing_return_rows` — `tasks/vat-filings.md`),
na účetním dokladu `cmnbkp` (`modules/docs/accountingDocs`, `AccountingDocument`),
účtovacím předpisu (`economy.accounting`, `accountingRules.cz.jsonc`) a saldokontu
(`economy.accbal`). Kontrola zůstatků 343 vznikla ve F4a (`ClosedPeriodBalanceService` +
alertová obálka `Checks\ClosedPeriodBalanceCheck`; TODO v hlavičce služby) —
tato fáze jí dodá druhou množinu dokladů (`acc_document`) a tím ji „zhasne".
Správce daně z registrace importuje `old_shipard` task 36.

## Cíl

Podané přiznání k DPH dnes v účetnictví nic nedělá: analytiky 343 rostou donekonečna
a závazek vůči FÚ neexistuje. Starý Shipard to řešil `VatReturnAccEngine` — jeden
`accDocument` na report, přepisovaný při každém podání. Nový model:

- **účetní doklad per podání** (`economy_vat_filings.acc_document`), jen typ `return`;
- řádné účtuje plný obsah, opravné i dodatečné **rozdíl proti kumulativnímu podanému
  stavu** (D28) — součet dokladů za instanci = poslední podaná pravda, nic se nepřepisuje;
- obsah dle starého vzoru (D29) + nově oddělená **neuplatnitelná část krácených kódů**
  (ř. 52) jako náklad, ne zaokrouhlení;
- partner saldo řádků = **správce daně na registraci** (D30);
- **explicitní akce „Zaúčtovat"** nad podaným podáním, doklad jako koncept (D31).

Invarianta po zaúčtování: Σ deníku na `343*` (mimo 343801/802) přes doklady instance
∪ účetní doklady jejích podání = 0 per analytika — přesně to, co hlídá
`ClosedPeriodBalanceService` z F4a.

Před implementací **přečti**:

- issue #55 komentáře D14–D22, D23–D31; `tasks/vat-filings.md` (snapshot: co je v
  `filing_items` — **vždy plný obsah**, i u dodatečného — a co v `_return_rows`;
  „Odchylky": dodatečné diffuje proti **kumulativnímu** stavu, `FilingComposer::cumulativeFiledRows`)
- `modules/economy/vat/src/FilingComposer.php` (ř. 260–340: koeficient, ř. 52,
  `filed` vs. exact), `FilingRounding.php`, `VatReturnCalculator.php` (které řádky
  nesou `taxReduced` — krácený sloupec ř. 40–47, ř. 52 odpočet), `FilingDocument.php`
  (FROZEN_COLUMNS, `afterPersist`, `note` jediná mutace ve 40), `FilingsViewer.php`,
  `VatFilingController.php`, `Xml/FilingFilesService.php` (vzor služby nad podaným
  snapshotem, jak řeší idempotenci a warnings), `config/vat-reports-cz.jsonc`
- `modules/docs/accountingDocs/src/AccountingDocument.php` (cmnbkp: řádky
  `acc.record`, kontrola Σ MD = Σ DAL při 40, partner per řádek),
  `modules/docs/core/config/rowOperations.jsonc` (`acc.record`: `rowSide`,
  `rowPartner`, `rowPaymentId`, `rowAccount: direct`), `tables/docs_core_rows.jsonc`
  (`account`, `acc_side`, `partner`, `payment_reference`, `specific_symbol`,
  `constant_symbol`, `due_date`, `total_price`), `DocDocument.php` (jak se doklad
  ukládá přes `TableGateway::saveDocument` s `rows` — vzor v exchange apply /
  `dataset-seed`; `docs/document-exchange.md`)
- `modules/economy/accounting/config/accountingRules.cz.jsonc` (kategorie, masky —
  přidají se `vat.payable`, `vat.receivable`, `vat.nondeductible`; existující
  `rounding.cost/revenue`), `accountChartDefault.jsonc` (343801/343802 už jsou),
  `src/AccountingEngine.php::buildVatLines` (konvence strany per směr kódu —
  účetní doklad přiznání dělá **opak**), `docs/accounting.md` §5 (analytiky 343 z kódu)
- `modules/economy/accbal` README (saldo identita řádku: partner + VS/SS/KS +
  splatnost; co potřebuje párování platby FÚ)
- `modules/economy/codebooks/tables/economy_codebooks_vat_registrations.jsonc`,
  `src/VatRegistrationsForm.php` (přidá se `tax_office_person`), exchange formát
  registrace (kde se importuje — `SetupController`? runner na staré straně posílá
  `saveDocument` payload)
- `src/Command/DataSource/VatFilingFilesCommand.php` (vzor CLI nad podáním)
- old_shipard: `modules/e10doc/taxes/VatReturn/VatReturnAccEngine.php` (**celý** —
  referenční obsah dokladu: `createDocRows`, `findRowData`: splatnost +25/+60, VS =
  DIČ bez „CZ", SS = `705` + číslo období, KS 1148, převzetí symbolů z předchozího
  dokladu), `install/data/countries/cz/debs/debs-accounts-default-vat.json`
  (343801 `useBalance` 2000 závazek, 343802 1000 pohledávka)

## Co vznikne

### 1. Schéma

- **`economy_vat_filings.acc_document`** (int, nullable, reference `docs_core_heads`)
  — přidat i do `FilingDocument::FROZEN_COLUMNS`? **Ne**: je to jediné pole vedle
  `note`, které se u podaného podání smí nastavit — a jen službou z §3 (Document
  hlídá, že hodnota míří na živý `cmnbkp` a že se přepisuje jen z NULL nebo
  z dokladu ve stavu 30/90). `.md` doplnit.
- **`economy_codebooks_vat_registrations.tax_office_person`** (int, nullable,
  reference `base_persons`) — „Správce daně"; formulář registrace (vedle profilu
  podatele), viewer detail. Registrace ve stavu 40 je readOnly — `tax_office_person`
  je (vedle poznámky) **jediné pole, které Document registrace povolí změnit i ve 40**
  (jako `note` u podání), aby ho šlo doplnit bez „Opravit" a aby ho mohl nastavit
  import. Endpoint **`POST /_vat/registration-tax-office {registrationId, personId}`**
  (`VatFilingController` nebo nový `VatRegistrationController`) → `saveDocument`
  registrace; idempotentní (stejná hodnota = no-op). Volá ho `old_shipard` task 36
  krokem `vat-tax-offices` **po** importu osob (při POST registrace ve fázi 02 osoba
  FÚ ještě namapovaná není).
- `ds-upgrade`.

### 2. Config (D29 — legislativa a konvence mimo PHP)

`vat-reports-cz.jsonc` → `reportTypes.return.accounting`:

```jsonc
"accounting": {
    "payableDueDays": 25,        // splatnost odvodu od konce období (starý vzor)
    "refundDueDays": 60,         // splatnost nadměrného odpočtu (starý vzor)
    "specificSymbolPrefix": "705",
    "constantSymbol": "1148",
    "excludeAccounts": ["343801", "343802"]   // saldo účty — nevynulovávají se
}
```

`accountingRules.cz.jsonc` → kategorie + masky:

```jsonc
"vat.payable":       {"name:cs": "DPH — odvod (závazek vůči FÚ)"},
"vat.receivable":    {"name:cs": "DPH — nadměrný odpočet (pohledávka za FÚ)"},
"vat.nondeductible": {"name:cs": "DPH bez nároku na odpočet (krácení)"},
…
{"cat": "vat.payable",       "accountMask": "343801"},
{"cat": "vat.receivable",    "accountMask": "343802"},
{"cat": "vat.nondeductible", "accountMask": "548"},
```

Rozvrhy bez 343801/802 (starší DS) → `AccountMaskResolver` chybu vrátí jako
`messages` dokladu, ne výjimku; doklad vznikne bez saldo řádku a ve `messages`
podání přibude chyba (uživatel doplní účet a spustí akci znovu).

### 3. Engine — `src/Accounting/VatReturnAccountingBuilder.php` (čistý) + `VatReturnAccountingService.php`

**Builder** (bez DB — dostane snapshot, předchozí kumulativní stav, config, účty;
testovatelný do posledního haléře):

Vstup: `filing` (hlavička), `items` tohoto podání, `itemsPrevious` (kumulativní
podaný stav — u řádného prázdné, u opravného items `previous_filing`, u dodatečného
items posledního podaného podání; items jsou vždy plný obsah, takže „kumulativní" =
items posledního podaného, řetěz se neskládá), `returnRows` tohoto a předchozího
podání (pro ř. 64/65/66 a ř. 40–47/52), koeficient, mapa `vat_code → account`
(`343{NNN}` dle `docs/accounting.md` §5, stejná konvence jako `AccountingEngine`
a `VatJournalCrossCheck`).

Výstup: seznam řádků `{account, acc_side, amount, description, partner?,
payment_reference?, specific_symbol?, constant_symbol?, due_date?}` + `messages`:

1. **Vynulování analytik**: per `vat_code` `delta = Σ items.tax_dom − Σ itemsPrevious.tax_dom`
   (přesné). Směr kódu z `world.vat` (`modules/world/vat/config`, `direction` input/output): vstupní kód (odpočet, v deníku MD)
   → řádek **DAL** `343{NNN}`; výstupní (v deníku D) → **MD**. Nulová delta → žádný řádek.
   Popis = název kódu.
2. **Saldo řádek** — podaná (zaokrouhlená) hodnota:
   - řádné: ř. 64 > 0 → `vat.payable` DAL (závazek); ř. 65 > 0 → `vat.receivable` MD;
   - opravné: (ř. 64 − ř. 65) tohoto − (ř. 64 − ř. 65) předchozího, znaménko rozhodne účet;
   - dodatečné: ř. 66 (změna daňové povinnosti), znaménko rozhodne účet.
   `partner` = `tax_office_person` registrace (chybí → řádek bez partnera + warning
   do `messages`: „Registrace nemá správce daně — saldo řádek bez partnera"),
   `payment_reference` = DIČ registrace bez prefixu země, `specific_symbol` =
   prefix + číslice z názvu období (starý vzor `preg_replace("/[^0-9]/", "", id)`;
   u nás z `name` instance, ověř tvar názvu — jinak z `date_end` `Ymm`),
   `constant_symbol`, `due_date` = `date_end` + dny dle směru. Popis
   „Odvod DPH {name}" / „Nadměrný odpočet DPH {name}" / u dodatečného
   „Dodatečné přiznání DPH {name}".
3. **Neuplatnitelná část krácení**: `nondeductible = Σ krácený sloupec ř. 40–47
   (přesné, `tax_reduced`) − ř. 52 odpočet (přesné)` — delta proti předchozímu
   podání stejně jako u kódů. > 0 → `vat.nondeductible` MD (náklad); < 0 (změna
   koeficientu dolů) → DAL. Bez krácených kódů řádek nevzniká.
4. **Zaokrouhlení**: zbytek do vyrovnání Σ MD = Σ DAL → `rounding.cost` (MD) /
   `rounding.revenue` (DAL). Sanity: |zbytek| ≤ 0,5 × počet řádků DP3 s daní +
   0,01; větší → **chyba** (ne warning) — znamená rozjetý snapshot nebo chybu
   mapování, doklad se nesestaví.
5. Prázdný výsledek (žádný řádek) → doklad se **nezakládá**, `messages` info.

**Service** (DB): načte podání (musí být `report_type = return`, docState 40,
`acc_document` NULL nebo míří na doklad ve 30/90), registraci, snapshot, předchozí
podané podání (`previous_filing` u opravného; u dodatečného poslední podané před
tímto — stejná logika jako `FilingComposer` pro kumulativní stav), účty přes
`AccountMaskResolver`; zavolá builder; založí `cmnbkp` přes
`TableGateway::saveDocument`:

- hlavička: `doc_type = cmnbkp`, `number_series` = výchozí řada cmnbkp DS
  (dohledej stejně jako `DocumentApplier` pro `cmnbkp` — výchozí nevázaná řada
  typu, ř. 370+; **žádná nová
  vázaná řada** — starý dbCounter „Přiznání DPH" nereprodukujeme, filtr dá
  `link` na podání), `issue_date` = dnes, `accounting_date` = `date_end`
  instance, `title` = „Přiznání DPH {name instance}" (+ „ — dodatečné/opravné
  č. {sequence}"), `partner` = správce daně (hlavičkový partner je u cmnbkp
  nepovinný — nastavit pro přehled), `docState` **10** (D31), `note` s odkazem
  na podání (id, druh, pořadí). Bez `vat_registration` a bez DPH (`vat_mode 0`)
  — proto ho zámek instance nechytá (F4a D23), i když datum leží v podaném období.
- řádky: `row_kind`/`operation = acc.record`, `account`, `acc_side`, `total_price`
  = částka, saldo pole per řádek.
- po uložení `UPDATE economy_vat_filings SET acc_document` přes `saveDocument`
  podání (Document guard z §1), do `messages` podání přidat záznam „zaúčtováno,
  doklad #…" + warnings builderu.
- vše v jedné transakci; chyba → rollback, výsledek `{ok: false, errors}`.

Idempotence: živý `acc_document` (≠ 30/90) → služba odmítne s odkazem
(`{ok: false, existing: docId}`); stornovaný/smazaný → nový doklad, FK přepíše.

### 4. Akce, endpoint, CLI (D31)

- `FilingsViewer::renderDetail`: u podání `return` ve stavu 40 bez živého
  `acc_document` akce `accountFiling` („Zaúčtovat", primary); s dokladem odkaz
  „Účetní doklad #… (stav)" (`open_viewer` na doklady) a akce „Zaúčtovat znovu"
  jen když je doklad 30/90. Properties detailu: řádek „Zaúčtování".
- `VatFilingController::account` — `POST /_vat/filing-account {filingId}`;
  `frontend/src/api/vat.js` + větev v `Viewer.svelte` (po úspěchu otevřít
  vzniklý doklad ve formuláři — uživatel ho má zkontrolovat a uzavřít).
- Po přechodu podání 10→40 se ve výsledku nabídnou obě akce (Zaúčtovat, Uzamknout
  tvrzení — F4a) — jen pořadí akcí v detailu, žádný nový dialog.
- CLI `vat-filing-account <filingId> [--dry-run]` (`src/Command/DataSource/
  VatFilingAccountCommand.php`): `--dry-run` vypíše řádky (účet, strana, částka,
  popis) bez zápisu — to je nástroj zlatého testu. Zápis na kopii reálných dat
  = mutace, spouští David.

### 5. Import (D21 návaznost)

Až bude importér starých podání (`old_shipard` D21), mapuje `reports.accDocument`
→ `acc_document` **řádného** podání instance (přes LocalIdMap dokladů). Importované
podání se **nezaúčtovává znovu** — akce v detailu ho vidí jako zaúčtované. Zapiš
do PRD importéru; tady jen guard: služba odmítne podání s `date_filed` před
`valid_from` importu? Ne — stačí, že `acc_document` je vyplněný.

### 6. Testy

- `VatReturnAccountingBuilderTest` — řádné: vynulování analytik per kód (obě strany),
  saldo řádek payable/receivable s VS/SS/KS/splatností, zaokrouhlení = filed − exact;
  krácené kódy: neuplatnitelná část na 548, zaokrouhlení zvlášť; dodatečné: delta
  per kód, saldo = ř. 66, záporná i kladná; opravné: delta ř. 64/65; prázdná delta
  → bez řádků; sanity zaokrouhlení → chyba; chybějící správce daně → warning,
  řádek bez partnera; chybějící účet → chyba v messages.
- `VatReturnAccountingServiceTest` (integrační, dev DS): založí `cmnbkp` 10 s řádky,
  Σ MD = Σ DAL, `acc_document` nastaven, idempotence (druhé volání odmítne; po
  stornu dokladu projde znovu), cizí typ (`cs`) odmítne, koncept podání odmítne.
- `FilingDocumentTest` — rozšířit: `acc_document` smí nastavit jen z NULL / z 30/90
  a jen na živý cmnbkp; ostatní frozen beze změny.
- `tests/Integration/Vat/ClosedPeriodBalanceServiceTest` (F4a) — rozšířit: po zaúčtování řádného podání
  je výsledek prázdný; po změně DPH dokladu (odemknuto) nenulový; po dodatečném
  podání + zaúčtování opět prázdný.
- **Zlatý test** (dev DS `btpg-p`, zdroj 689089): `vat-filing-account --dry-run`
  nad řádnými podáními DP3 01–04/2026 vs. řádky starých účetních dokladů přiznání
  (`cmnbkp`, titul „Přiznání DPH …", importované ze starého systému; najdi je
  podle `accounting_date` = konec období a účtů 343801/802). Shoda: per 343
  analytika částka a strana; saldo řádek částka, VS, SS; rozdíl smí být jen
  v rozdělení zbytku mezi zaokrouhlení a 548 (starý systém krácení nerozlišoval)
  — zdokumentuj. Výsledek do „Hotovo když".

### 7. Dokumentace

- `modules/economy/vat/docs/README.md` — sekce „Zaúčtování přiznání" (model per
  podání, delta, obsah dokladu, správce daně, co dělá akce, idempotence, vztah ke
  kontrole zůstatků a k zámku), `tables/economy_vat_filings.md`.
- `docs/accounting.md` §5 — doplnit uzavření analytik 343 přiznáním, kategorie
  `vat.payable/receivable/nondeductible`.
- `modules/economy/codebooks/README.md` — správce daně na registraci.
- Help akce (cs).

## Mimo scope

- Automatické zaúčtování při přechodu 10→40 (D31: explicitně ne).
- Zaúčtování KH/SH (neúčtují se), OSS, ř. 53 vypořádání koeficientu na konci roku
  (samostatné téma: rozdíl zálohového a vypořádacího koeficientu).
- Párování platby FÚ s 343801/802 — dělá `economy.accbal` obecně, žádná
  specialita tady (saldo identita je nastavená, aby to šlo).
- Vlastní číselná řada / aktivita „Přiznání DPH" (starý dbCounter).
- Import starých podání a jejich `accDocument` (D21, stará strana).
- Osoby FÚ jako číselník (`ufo` → osoby) — správce daně je ručně vybraná osoba.

## Commity

1. Schéma: `acc_document` + `tax_office_person` + formulář/viewer registrace +
   exchange pole + `FilingDocument` guard + `.md` + testy.
2. Config (`reportTypes.return.accounting`, kategorie a masky) + `VatOutputsMapping`
   čtení + `VatReturnAccountingBuilder` + testy.
3. `VatReturnAccountingService` + CLI `vat-filing-account` (`--dry-run`) + integrační test.
4. Akce Zaúčtovat (viewer, controller, api, Viewer.svelte) + rozšíření
   `ClosedPeriodBalanceService` o `acc_document` + testy.
5. Dokumentace + help; zlatý test zapsaný v „Hotovo když".

## Hotovo když

- [x] Testy zelené: `VatReturnAccountingBuilderTest` (18 scénářů: řádné odvod
      i odpočet, reverse charge pár, krácení + koeficient nahoru, dodatečné ±,
      opravné, prázdná delta, tolerance zaokrouhlení, chybějící účty, správce
      daně, DIČ, neznámý kód, čtvrtletní SS), `VatReturnAccountingServiceTest`
      (integrační, 4l3j: koncept 10 s vyrovnanými řádky, `acc_document` +
      zpráva, ALREADY_ACCOUNTED, po stornu znovu, koncept podání odmítnut +
      dry-run, KH odmítnuto, bez správce daně warning), `FilingDocumentTest`
      (+9 na `acc_document`/`messages`), `ClosedPeriodBalanceServiceTest`
      (+ doklad podání mimo instanci vypořádá, smazaný ne),
      `VatRegistrationDocumentTest` (+6 částečné uložení), `VatOutputsMappingTest`
      (+5 `accounting`), `ReadOnlyPolicyTest`, `HelpDriftTest`.
- [x] `ds-upgrade` na 4l3j i btpg-p projde (`acc_document`, `tax_office_person`).
- [x] 4l3j: podané řádné podání (Q3/2026) → `POST /_vat/filing-account` založil
      `cmnbkp` 10 s řádky 343110 DAL / 343802 MD / 648100 DAL (Σ = Σ), druhé
      volání `ALREADY_ACCOUNTED`, po smazání dokladu detail nabízí „Zaúčtovat
      znovu". Vypořádání po uzavření dokladu ověřuje integrační test kontroly
      zůstatků (deník přímo), ne proklik — proklik UI (uzavření dokladu →
      Zůstatky DPH prázdné) patří do scénáře níže.
- [ ] Dev DS ruční scénář: změna DPH dokladu v odemčené podané instanci →
      kontrola nenulová → dodatečné podání + zaúčtování → kontrola prázdná.
      Matematiku dodatečného (delta per kód + ř. 66) kryje builder test;
      proklik → #158.
- [x] **Zlatý test 689089 DP3 01–04/2026** (btpg-p, koncepty podání #1–#4
      přes `vat-filing-compose`, `vat-filing-account --dry-run`): per analytika
      343 **shodná strana i částka** ve všech čtyřech měsících (11 / 8 / 10 /
      10 řádků), saldo řádek 343801 DAL 260 864 / 135 796 / 120 203 / 143 583
      = staré doklady, VS = DIČ bez `CZ`, SS `705202601`…`705202604`, splatnost
      +25 dní shodná. Zbytek 1,06 / 0,02 / −0,97 / 0,48 shodný se starými
      doklady včetně strany; liší se jen účet zaokrouhlení — předpis dává
      `rounding.revenue` 648001 / `rounding.cost` 548001, starý systém účtoval
      668001 / 568001. Krácení 0,00 (koeficient 1,00), takže rozdělení zbytku
      mezi 548 a zaokrouhlení se neprojevilo.
- [x] Registrace bez správce daně: doklad vznikne, saldo řádek bez partnera,
      warning ve zprávách podání i v odpovědi akce (integrační test + btpg
      dry-run); po doplnění osoby a novém zaúčtování má partnera (test).
- [x] Dokumentace dle §7: README modulu vat (sekce Zaúčtování přiznání +
      architektura), `tables/economy_vat_filings.md`,
      `economy_codebooks_vat_registrations.md`, `docs/accounting.md` §5,
      README codebooks, `docs/edit-forms.md` kap. 26 (nový primitiv),
      `docs/cli.md`, `docs/ds-setup.md` §5.2, help `dph-podani.md`
      (Zaúčtování přiznání), `uzamceni-obdobi.md`, `co-dnes-nejde.md`.

## Odchylky od zadání

Odsouhlaseno před implementací (plán 2026-09-12):

- **Editace ve stavu 40 nejde přes Document, ale přes nový primitiv
  `TableForm::getReadOnlyEditableColumns()`.** Read-only stav vynucují
  controllery, ne Document — a `FormController::save()` ho dosud nehlídal
  vůbec (mrtvý `processDocState`), takže `note` u podaného podání šlo
  „povolit" jen na papíře: formulář byl celý zamčený. Teď server odmítne
  update read-only záznamu mimo whitelist (`DOCUMENT_READONLY`), klient
  odemkne jen vyjmenované sloupce a posílá jen je; první uživatelé `note`
  podání a `tax_office_person` registrace. `VatRegistrationDocument` proto
  zvládá částečné uložení (merge s uloženým řádkem). `docs/edit-forms.md` §26.
- **`messages` podání smí změnit jen uložení, které zároveň nastavuje
  `acc_document`** (záznam `vatReturn.accounted` + varování builderu);
  jinak zůstává ve `FROZEN_COLUMNS`.
- **Číselná řada z volitelného parametru vrstvy C
  `economy.vat.filingAccountingSeries`**, bez něj jen když je aktivní řada
  `cmnbkp` právě jedna; při více řadách chyba s pokynem (btpg-p má osm řad,
  „první podle id" by byla Saldokonto místo Daně). Mimo průvodce nastavením
  i `[TODO]` výpis `ds-upgrade` (`optional` ve specifikaci).
- **Saldo řádek jednotně = závazek(kumulativní podané po) − závazek(před)**
  přes `FilingSnapshotLoader::cumulativeFiledRows()` (extrakce z composeru);
  pro řádné to je ř. 64/65, pro opravné rozdíl proti kumulativnímu stavu,
  pro dodatečné přesně ř. 66.
- **SS = prefix + `date_end` ve tvaru RRRRMM** (název instance `01/2026`
  číslice ve správném pořadí nedá); u čtvrtletí koncový měsíc — starý systém
  bral číslice z id období.
- **CLI `--dry-run` jde i nad konceptem podání** (zápis ne) — bez toho by
  zlatý test na btpg-p vyžadoval podat čtyři přiznání.
- **Chybějící účet 343801/802 nebo 548 = chyba, doklad nevznikne** (zadání:
  doklad bez saldo řádku + chyba). Nevyrovnaný koncept nikomu nepomůže,
  uživatel účet doplní a akci spustí znovu tak jako tak.
- **Kódy mimo přiznání (`dp3_row` NULL) se neúčtují** — nejsou součástí
  podané povinnosti; kdyby nesly daň, ohlásí je kontrola zůstatků.
- **Endpoint správce daně používá částečný payload** (`{id, tax_office_person}`)
  místo celého řádku — registrace má strukturovaný `filing_profile`, celý
  řádek zpět přes gateway by ho zbytečně reserializoval.
- Import (§5): `old_shipard` task 36 na staré straně zatím neexistuje; runner
  registrací navíc posílá neexistující `report_period_kind` (má být
  `cs_period_kind`) — obojí je věc staré strany.
