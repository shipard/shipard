# Doplněk Budovy — fáze 2: fakturace smluv (#174 D29–D38)

**Stav:** naplánováno — design #174 D29–D38 schválen 2026-11-27, task k implementaci

> Task pro Claude Code (4 commity). Design: issue #174. Navazuje na fázi 1
> (`tasks/buildings-phase1.md`) a na engine periodické fakturace
> `economy.periodicInvoicing` (#175, `tasks/periodic-invoicing-sources.md`).
> Smlouvy se stanou druhým zdrojem fakturace vedle Zakázek. Commit 1 sahá
> i do Zakázek (společný základ řádku předpisu, D31) — bez změny chování.

## Kontext

- **Engine** (`modules/economy/periodicInvoicing/README.md`): rozhraní
  `PeriodicInvoicingSource`, normalizovaný záznam (#175 U1), tvar řádku
  předpisu (#175 U2), evidence období per zdroj (#175 D1), `InvoicingSettings`
  (`COLUMNS` na druhu i záznamu, `WORK_ORDER_COLUMNS` jen na záznamu,
  `SOURCE_*`), `InvoicingSettingsResolver`, `InvoicingFormOptions` (trait
  pro formuláře druhu a záznamu), `InvoicingDetailTab`, akce
  `IssueDueAction` / `RegenerateAction` / `RestoreAction`, `DocTextTemplate`.
- **Zakázky jako vzor zdroje:** `WorkOrderInvoicingSource`, registrace
  `periodicInvoicingSources` a `recordActions` v `module.jsonc`,
  `WorkOrderRowsForm` (≈ 230 řádků: lookup položky, nabídka DPH podle
  efektivního typu dokladu a kontextu DPH, pohyby podle typu dokladu,
  jednotky, přispěvatelé), `WorkOrderRowDocument` (tvarová validace,
  `order_pos`), `WorkOrderRowLockProvider` (řádky zamčené se stavem
  rodiče), sekce Fakturace a Přepisy ve `WorkOrdersForm`, záložka řádků
  jako sub-tabulka s `renderSubtable()`.
- **Budovy po fázi 1:** `ContractDocument`, `ContractKindDocument`,
  `ContractsForm`, `ContractKindsForm`, `ContractsViewer`,
  `ContractKindsViewer`, tabulky `facility_buildings_contracts`
  / `_contract_kinds` / `_contract_places` / `_number_counters`
  (`tableId` 472–475).

## Rozhodnutí (#174, schválena 2026-11-27)

- **D29** — Nastavení fakturace jako u zakázek (#175 D4): druh nese výchozí
  `inv_doc_type`, `inv_number_series`, `inv_due_days`, `inv_timing`,
  `inv_vat_mode`, `inv_payment_method`, `inv_bank_account`; smlouva
  `inv_periodicity`, `inv_from`, `inv_doc_text` a přepis těch sedmi
  (prázdné = z druhu); na smlouvu přibude `currency`, `payment_reference`
  (pevný VS) a `cost_center`.
- **D30** — Řádky předpisu `facility_buildings_contract_rows` ve tvaru
  #175 U2; sub-tabulka smlouvy, rozvržení jako u zakázek.
- **D31** — Společný základ řádku předpisu v `economy.periodicInvoicing`:
  abstraktní formulář a dokument řádku; Zakázky i Budovy dědí a dodají
  rodiče. Pro Zakázky bez změny chování.
- **D32** — Šablona řádků na druhu `facility_buildings_contract_kind_rows`
  (tvar řádku bez platnosti); kopii dělá dokument smlouvy při uložení
  konceptu bez řádků, jehož druh šablonu má (i po změně druhu); šablona
  jako sub-tabulka druhu.
- **D33** — Výchozí kód DPH přes šablonu řádků druhu, žádná vlastnost typu.
- **D34** — `ContractInvoicingSource` (`buildingContracts`), evidence období
  `facility_buildings_contract_periods`; kandidáti V pořádku s periodicitou
  a `inv_from`, konec fakturace = `date_end`, dimenze `costCenter`, sekce
  `buildings`; akce nad tabulkou smluv, záložka Fakturace v detailu.
- **D35** — `InvoicingSettings::SOURCE_WORK_ORDER` → `SOURCE_RECORD`,
  `WORK_ORDER_COLUMNS` → `RECORD_COLUMNS`, bez změny chování.
- **D36** — Periodicita nepovinná. S periodicitou při potvrzení `inv_from`
  povinné (předvyplní se zahájením, ne dřív) a aspoň jeden řádek; platnost
  řádků uvnitř platnosti smlouvy.
- **D37** — Text dokladu jako u zakázek (prázdná šablona = „název — {období}“),
  `{místa}` ne.
- **D38** — Mimo: zálohy na služby a vyúčtování, jistota, indexace, měřiče,
  import `689089`, demo data Budov.

## Před implementací přečti

- `modules/economy/periodicInvoicing/README.md` (celý — jak přidat zdroj)
- `docs/work-orders.md` §5.3–5.5 (řádky předpisu, fakturace, zámek řádků)
- `tasks/periodic-invoicing-sources.md` → Odchylky (R1–R3: kde smí ležet
  třída akce, `recordNoun()` / `navSection()` / `tableId()` zdroje)
- `modules/facility/buildings/README.md`
- `docs/edit-forms.md` kap. 15 (sub-tabulky), `docs/document-system.md`
  (lock providery, `afterPersist`)

## Kroky

### Commit 1 — engine: úklid názvů a společný základ řádku (D31, D35)

- D35: přejmenovat konstanty v `InvoicingSettings` a všechna použití
  (`InvoicingSettingsResolver`, `WorkOrderDocument`, `WorkOrdersForm`,
  testy). Hodnota zdroje v `sources` (`'workOrder'`) se klientovi
  neposílá — ověřit; jinak hodnotu nechat a přejmenovat jen konstantu.
- D31 v `economy.periodicInvoicing` (namespace `…\PeriodicInvoicing\Rows`):
  - `AbstractPrescriptionRowsForm extends TableForm` — celý dnešní obsah
    `WorkOrderRowsForm` kromě rodiče; abstraktní `parentColumn()`,
    `loadParentHeadAndKind(int $parentId): ?array{head: ?array, kind:
    ?array}` (pro šablonu na druhu je `head` null a typ dokladu se bere
    z druhu), `rowTitle()` / `rowTitleNew()`, `hasValidity(): bool`
    (šablona bez platnosti) a texty nápověd přes katalog enginu;
  - `AbstractPrescriptionRowDocument extends Document` — validace
    a `beforeSave()` z `WorkOrderRowDocument`, abstraktní
    `parentColumn()` / `parentTable()` / `parentLabel()`;
  - `AbstractPrescriptionRowLockProvider` — zámek podle stavu rodiče,
    abstraktní tabulka rodiče a sloupec;
  - helper `PrescriptionRowsSubtable::render()` pro `renderSubtable()`
    formuláře rodiče (sloupce položka, text, množství, cena, DPH,
    platnost), pokud je kód ve `WorkOrdersForm` obecný.
- Zakázky: `WorkOrderRowsForm`, `WorkOrderRowDocument`,
  `WorkOrderRowLockProvider` jen dědí a dodají rodiče — beze změny
  deklarací v `module.jsonc`.
- Testy Zakázek beze změny asercí; nové testy základu s fake rodičem
  (šablona bez platnosti, typ dokladu z druhu).

### Commit 2 — model Budov (D29, D30, D32, D36)

- `facility_buildings_contract_kinds` + sedm sloupců `inv_*` (definice
  a cfgItem reference jako u `economy_work_orders_kinds`).
- `facility_buildings_contracts` + `currency`, `payment_reference`,
  `cost_center`, `inv_periodicity`, `inv_from`, `inv_doc_text` a sedm
  `inv_*` přepisů (jako `economy_work_orders_heads`); `childTables` beze
  změny (řádky jsou sub-tabulka, ne `multilookup`).
- Nové tabulky (`tableId` přes `next-table-id`):
  - `facility_buildings_contract_rows` — `contract` + tvar #175 U2;
  - `facility_buildings_contract_kind_rows` — `kind` + tvar bez
    `valid_from` / `valid_to`;
  - `facility_buildings_contract_periods` — tvar
    `economy_work_orders_periods`, sloupec záznamu `contract`, unikátní
    `contract` × začátek období.
- `ContractRowDocument`, `ContractKindRowDocument`, `ContractRowLockProvider`
  (řádky smlouvy zamčené ve stavech jen ke čtení), `ContractKindRowLockProvider`
  jen pokud druh má zámek podle stavu (archiv) — jinak ne.
- `ContractDocument`:
  - D36 — `inv_from` se při vytvoření / změně zahájení předvyplní
    zahájením, když je prázdné; `inv_from < date_start` = chyba; při
    potvrzení s periodicitou `inv_from` povinné a aspoň jeden řádek;
    řádek s platností mimo platnost smlouvy = chyba s textem řádku
    (kontrola při potvrzení i v `ContractRowDocument`);
  - D32 — v `afterPersist()`: když jde o vložení **nebo** změnu druhu,
    smlouva je Koncept, nemá žádné řádky a druh má šablonu → zkopírovat
    řádky šablony (pořadí, bez platnosti) v transakci uložení;
  - měna: prázdná = měna zdroje dat (jako u zakázek).
- `ContractKindDocument`: `inv_*` validace jako `KindDocument` Zakázek
  (řada patří typu dokladu, splatnost ≥ 0).
- Testy: kopie šablony (vložení, změna druhu, smlouva s řádky se nemění,
  smazání všech řádků a nové uložení šablonu **nekopíruje**), D36
  validace, zámek řádků.

### Commit 3 — zdroj fakturace a UI (D34, D37)

- `ContractInvoicingSource extends AbstractPeriodicInvoicingSource`
  (`id` `buildingContracts`, tabulka a období podle commitu 2,
  `viewerId` smluv, `navSection` `buildings`); kandidáti: stav V pořádku,
  `inv_periodicity` a `inv_from` vyplněné; normalizovaný záznam podle
  #175 U1 (`customer` = nájemce, `date_end` = ukončení); řádky
  `order_pos` ASC; dimenze jen `costCenter`; `recordLabel` číslo + název;
  `recordNoun` „smlouva“; `openActionLabel` „Otevřít smlouvu“.
- `module.jsonc`: závislost `economy.periodicInvoicing`,
  `periodicInvoicingSources`, `recordActions` (`issueDue`, `regenerate`,
  `restore` nad `facility_buildings_contracts`), `documentLockProviders`,
  formuláře řádků a šablony.
- `ContractKindsForm`: sekce Fakturace (výchozí hodnoty, trait
  `InvoicingFormOptions`), záložka **Šablona řádků** (sub-tabulka).
- `ContractsForm`: sekce **Fakturace** (periodicita, fakturovat od, text
  dokladu, měna, pevný VS, středisko) a **Přepisy výchozích hodnot druhu**
  (zástupný text „z druhu: …“ jako u zakázek); záložka **Řádky**
  (sub-tabulka) mezi hlavní záložkou a Přílohami. Přepočet po změně
  `inv_doc_type` vynuluje řadu (jako zakázky).
- `ContractsViewer`: záložka **Fakturace** přes `InvoicingDetailTab`
  (jen u smlouvy s periodicitou); řádek seznamu beze změny.
- Testy: zdroj (kandidáti, normalizace, dimenze), kontrakt detailu
  (záložka Fakturace, akce v liště), formuláře, běh enginu se dvěma
  skutečnými zdroji (Zakázky + Smlouvy) na fixture — oddělená evidence
  období.

### Commit 4 — nápověda, dokumentace, ověření

- `modules/facility/buildings/README.md` — fakturace, šablona řádků, zdroj.
- `modules/economy/periodicInvoicing/README.md` — druhý zdroj, společný
  základ řádku (D31), přejmenované konstanty (D35).
- Nápověda: `help/budovy/smlouvy.md` (Fakturace, Řádky, akce),
  `help/budovy/nastaveni.md` (výchozí hodnoty fakturace, šablona řádků,
  DPH bytů přes šablonu), `help/co-dnes-nejde.md` (odstavec „Smlouvy se
  nefakturují“ nahradit tím, co dál chybí — D38),
  `help/zakazky/periodicka-fakturace.md` jen pokud zmiňuje, že zdrojem jsou
  jen zakázky; `python3 scripts/help-index.py`.
- `docs/features.md` — Budovy fáze 2.
- **Ruční ověření** na volném zdroji dat s doplňkem (ne `realCopy`):
  druh s šablonou řádků → nová smlouva dostane řádky → potvrzení →
  `shpd-ds periodic-invoicing-run --source=buildingContracts` → koncepty
  faktur s textem a obdobím, středisko na dokladu → záložka Fakturace,
  Přegenerovat, Obnovit → pojistka dohánění dá badge v sekci Budovy;
  Zakázky na stejném zdroji fakturují dál. Výsledek do #174.
- `**Stav:**`, `python3 scripts/tasks-index.py`.

## Hotovo když

- [ ] konstanty přejmenované, Zakázky po refaktoringu D31 beze změny
      chování (testy beze změny asercí)
- [ ] druh smlouvy má výchozí hodnoty fakturace a šablonu řádků; nová
      smlouva (i po změně druhu v konceptu) dostane řádky šablony jen
      když žádné nemá
- [ ] smlouva s periodicitou nejde potvrdit bez „fakturovat od“ a bez
      řádku; řádek mimo platnost smlouvy je chyba
- [ ] `periodic-invoicing-run` vystaví koncepty za smlouvy i zakázky,
      evidence období se nemíchají; ukončená smlouva po `date_end`
      nefakturuje
- [ ] záložka Fakturace a akce v detailu smlouvy, upozornění s badge
      v sekci Budovy
- [ ] nápověda, README, `docs/features.md`, ruční ověření, `**Stav:**`

## Pasti

- **Šablona jen při vložení nebo změně druhu.** Podmínka „smlouva nemá
  řádky“ sama nestačí — uživatel, který řádky smaže a smlouvu uloží,
  nesmí dostat šablonu znovu.
- **Řádky smlouvy jsou sub-tabulka, místa `multilookup`** — dva různé
  mechanismy na jednom formuláři; uložení hlavičky na řádky nesahá
  (gateway synchronizuje jen přítomný klíč).
- **Zámek řádků** musí platit i na generickém CRUD (lock provider), ne jen
  ve formuláři — vzor `WorkOrderRowLockProvider`.
- **Jádro o doplňku neví** — engine nesmí jmenovat Budovy; všechno, co
  je specifické pro smlouvy, žije v doplňku (zdroj, formuláře, deklarace).
- **D35 je přejmenování, ne změna hodnot** — uložené hodnoty ani JSON
  odpovědí se nemění, jen jména konstant.
- **`tableId` přes `next-table-id` až po aktuálním `git pull`** — při
  souběžné práci na jiném doplňku hrozí kolize jako ve fázi 1.
