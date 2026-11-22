# Tabulka: economy_codebooks_vat_registrations

Registrace k DPH se stavy dokumentů (`core.system.docStatesArchive`).
Firma může mít 0, 1, nebo více registrací (různé země, OSS, diskontinuity
v plátcovství). Záznamy vznikají manuálně přes UI; provisioner do nich
nesahá.

## Sloupce

### Skupina `identity`

| Sloupec | Typ | Popis |
|---|---|---|
| `code` | varchar(20) NULL, unikátní (`unq_code`) | **Kód registrace** — přirozený klíč záznamu (`exchangeKey`, #166 D16, D17): určuje registraci mimo zdroj dat (datové sady ji párují kódem, reference `"@cz"`). Viz „Kód registrace“ níže |
| `name` | varchar(50) | Lidský název registrace (např. `"ČR DPH"`, `"SK DPH OSS"`) |
| `region` | enumString(10), cfgItem `world.trade.unions`, default `'eu'` | Klíč obchodní unie — ovlivňuje pravidla pro VAT-ID, intra-community plnění apod. |
| `country` | enumString(2), cfgItem `world.base.countries`, default `'cz'` | ISO 3166-1 alpha-2 lowercase |
| `taxpayer_kind` | enumInt default 0, cfgItem `economy.codebooks.vatTaxpayerKinds` | 0 = Klasický plátce, 1 = OSS (One-Stop-Shop pro EU služby) |
| `vat_id` | varchar(30) NULL | DIČ; nullable kvůli stavu „v procesu registrace" — formálně registrace existuje, ale DIČ ještě nepřišlo |
| `tax_office_person` | int NULL, reference `base_persons_persons` | Správce daně (#55 D30) — osoba finančního úřadu; partner saldo řádku účetního dokladu přiznání (`economy.vat`). Ručně vybraná osoba, žádný číselník FÚ. Vedle `code` jediný sloupec editovatelný i ve stavu V pořádku (formulář i `POST /_vat/registration-tax-office` pro import) |

### Kód registrace

`VatRegistrationCodeGenerator` (#166 D17): registrace země s nejnižším id
mezi nesmazanými má kód = země malými písmeny (`cz`), další registrace téže
země `cz-2`, `cz-3`, … Tvar hlídá `Shipard\Core\Database\RecordCode` (bez
mezer, `@` a dvojtečky); kód je unikátní i proti registracím v koši
a velikost písmen se nerozlišuje.

- **Formulář** kód nové registraci navrhne podle země (při změně země
  znovu) a jde přepsat; prázdné pole doplní
  `VatRegistrationDocument::beforeSave`. Validace: tvar (`invalid_value`),
  unikátnost (`duplicate`, zpráva jmenuje registraci, která kód má),
  vymazání uloženého kódu (`required`). Kód jde změnit i ve stavu
  V pořádku — v databázi se na registraci odkazuje přes id.
- **`ds-upgrade`** doplní kód registracím bez něj
  (`VatRegistrationCodeBackfill`, vždy, i pod `skipProvisioning`) —
  registrace z doby před sloupcem a z importu přes generické CRUD. Živé
  podle id, pak koš; idempotentní.

Určení registrace dokladu (země + datum) se nemění — kód registrace do něj
nevstupuje. Sloupec je `nullable` jen kvůli pořadí `ds-upgrade`.

### Skupina `period`

| Sloupec | Typ | Popis |
|---|---|---|
| `tax_period_kind` | enumInt default 1, cfgItem `economy.codebooks.vatPeriodKinds` | Frekvence přiznání DPH: 1 = měsíční, 2 = čtvrtletní |
| `cs_period_kind` | enumInt default 1, cfgItem `economy.codebooks.vatPeriodKinds` | Frekvence kontrolního hlášení (CS); v ČR může být KH měsíční i u čtvrtletního plátce |
| `rs_period_kind` | enumInt default 1, cfgItem `economy.codebooks.vatPeriodKinds` | Frekvence souhrnného hlášení (RS); zákonnou podmínku čtvrtletního SH (§ 102 odst. 6) systém nehlídá |
| `valid_from` | date | Začátek platnosti registrace |
| `valid_to` | date NULL | Konec platnosti; `NULL` = bez konce |

### Systémové (bez skupiny)

| Sloupec | Typ | Popis |
|---|---|---|
| `docState` | tinyint default 10 | Stav dokumentu (Koncept 10, V pořádku 40, …) |
| `docStateMain` | tinyint default 1 | Sortovací sloupec stavů |

## Indexy

- `unq_code` — unikátní na `code`
- `idx_country` na `country`
- `idx_validity` na `valid_from, valid_to`
- `idx_doc_state` na `docStateMain ASC, name ASC`

## Pravidla

- Manuálně přes UI vznikne registrace jako `Koncept` (10); uživatel ji
  přepne do `V pořádku` (40), případně `Smazaný` (90).
- Generátor instancí tvrzení (`economy.vat` → `ReportPeriodsProvisioner`)
  zpracovává registrace s `docState IN (10, 40, 80)`; periodicity
  `tax_period_kind` / `cs_period_kind` / `rs_period_kind` jsou jeho defaulty,
  runtime logika reportů je nečte (D10).
- Doklad podle DUZP (a DPPD oříznutého do přiznání) spadne do instancí
  tvrzení své registrace — přiřazení dělá `economy.vat` při uložení.
- **Správce daně** (`tax_office_person`) musí být živá osoba (stav 10/40/80);
  jde doplnit i k registraci ve stavu V pořádku bez „Opravit" — formulář ho
  pouští jako jediný sloupec (`getReadOnlyEditableColumns`), import přes
  `POST /_vat/registration-tax-office {registrationId, personId}` v `economy.vat`
  (idempotentní; volá ho migrace po importu osob, protože při založení
  registrace osoba FÚ ještě neexistuje).

## Související

- [economy_vat_report_periods](../../vat/tables/economy_vat_report_periods.md) — instance daňových tvrzení navázané na registraci
- [economy_vat_deduction_coefficients](../../vat/tables/economy_vat_deduction_coefficients.md) — koeficient odpočtu per registrace × kalendářní rok (ř. 52 DP3); formulář registrace na něj jen odkazuje, `economy.codebooks` na `economy.vat` nezávisí
- [VatRegistrationDocument](../src/VatRegistrationDocument.php) — validace
