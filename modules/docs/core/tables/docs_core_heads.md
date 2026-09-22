# Tabulka: docs_core_heads

Polymorfní hlavička dokladu. Konkrétní typ určuje sloupec `doc_type`
(enumString) — přes cfgItem `docs.core.docTypes` mapuje na Document subclass
v navazujícím modulu (např. `IssuedInvoiceDocument` v `docs.invoicesOut`).

`doc_type` je **denormalizovaný** ze sloupce `number_series.doc_type` —
nastavuje se v `DocDocument::beforeSave` a má příznak `system: true`.

## Skupiny sloupců

### `identity`

| Sloupec | Typ | Popis |
|---|---|---|
| `doc_type` | enumString(20), system | Typ dokladu, denormalizovaný z řady |
| `number_series` | int → `docs_core_number_series` | Řada, ze které pochází číslo |
| `cash_dir` | enumInt → `docs.core.cashDirections`, default 0 | Směr pokladního dokladu: 0 nepoužito (faktury, cmnbkp), 1 příjem, 2 výdej. Typ s `trade_dir_column: cash_dir` z něj odvozuje směr obchodu (`DocDocument::resolveTradeDir`: 1 → my dodavatel, 2 → my odběratel); ostatní typy musí mít 0. |
| `sequence_number` | int, nullable, system | Pořadové číslo v řadě, NULL pro Koncept |
| `doc_number` | varchar(40), system | Resolvované číslo dokladu (`126A0001`) nebo placeholder `!{id_padded}` u Konceptu |
| `doc_text` | varchar(200), nullable | Volný popisný text |
| `partner_doc_number` | varchar(40), nullable | Číslo dokladu přidělené druhou stranou (např. číslo faktury od dodavatele u přijaté faktury). Naše číslo z řady je v `doc_number`. Pro `invoiceReceived` ve stavu ≥ 20 doporučené non-empty — Exchange validator vyrobí warning; tvrdá per-docType validace bude follow-up v `docs.invoicesIn`. |

### `partner`

Partner (`base_persons_persons`), jeho adresa (`base_persons_addresses`),
bankovní účet (`base_persons_bank_accounts`) plus tři volné stringové
sloupce pro ruční přepis čísla účtu / IBAN / BIC.

### `dates`

`issue_date`, `due_date`, `accounting_date`, `vat_duzp`, `vat_dppd`,
`period_from`, `period_to`. Defaults plněné v Form recalculate / Document
beforeSave (Fáze 2).

### `accounting`

System sloupce mapování do účetních období: `fiscal_year`, `fiscal_month`,
`fiscal_period_type` (NULL = běžný měsíc podle účetního data; `opening` /
`closing` = jednodenní měsíc Otevření / Uzavření roku — plní jen import,
#69 D20; saldokonto uzávěrkové řádky nederivuje).
Plus uživatelem volitelná `vat_registration`. Zařazení do instancí daňových
tvrzení (`vat_period`, `cs_period`, `rs_period`) dodává extension modulu
`economy.vat` a plní jeho handler při uložení — viz
`modules/economy/vat/docs/README.md`.

### `vat`

`vat_mode` (0 bez DPH / 1 ze základu / 2 z ceny celkem),
`vat_calc_source` (0 z hlavičky = daň jednou ze součtu cen ve sazbě podle
normy / 1 z řádků = historický součet řádkových daní),
`vat_recap_source` (0 přepočítaná z řádků při každém uložení / 1 převzatá =
rekapitulace existujícího dokladu je vstup a fakt — import, přijatý doklad,
ruční oprava; `beforeSave` ji nepřepočítá), `vat_place` (tuzemsko /
intracom / zahraničí). Pravidla výpočtu: `docs/vat-calculation.md`.

### `currency`

`doc_currency`, `home_currency` (system, z DS configu), `exchange_rate`.

### `rounding`

`total_rounding_mode`, `vat_rounding_mode`.

### `totals` — všechny `system: true`

Sumace plněné v `beforeSave` ve Fázi 2. Doc currency: `total_base`,
`total_vat`, `total_amount`, `total_rounding`. Home currency:
`total_base_dom`, `total_vat_dom`, `total_amount_dom`.

### `payment`

`payment_method`, `bank_account` (náš účet, vazba na
`economy_codebooks_bank_accounts`), `cash_desk`, `payment_terminal`,
`transport`, `partner_balance`, `partner_balance_manual`,
`payment_reference`, `specific_symbol`, `constant_symbol`.

`cash_desk` (int, nullable → `economy_codebooks_cash_desks`, index
`idx_cash_desk`) má dvojí režim podle typu dokladu:

- typ s `series_binding: cash_desk` — **systémový**: denormalizuje se z řady
  (`DocDocument::denormalizeFromSeries`) bez ohledu na payload, řada bez
  pokladny je chyba `series_binding_missing`;
- ostatní typy (faktury) — uživatelský: smí být vyplněný jen při
  `payment_method = 0` (Hotovost), jinak chyba
  `cash_desk_requires_cash_payment`. Formulář pole ukazuje jen při
  Hotovosti a nabízí výchozí pokladnu (`is_default`) měny dokladu.

Sloupec proto nemá `system: true` — systémovost pro vázané typy vynucuje
`DocDocument`, stejně jako u `doc_type`.

**Prostředník platby a osoba pro saldokonto (#72 D2/D4/D5):**

- `payment_terminal` (int NULL → `economy_codebooks_payment_terminals`,
  index `idx_payment_terminal`) — terminál / brána použitá k platbě. U karty
  na prodejním dokladu doplní `PartnerBalanceResolver` default terminál
  pokladny hlavičky (na faktuře bez pokladny default mezi všemi), u brány
  (`payment_method` 5) je výběr povinný — chyba `payment_terminal_required`.
- `transport` (int NULL → `economy_codebooks_transports`) — způsob dopravy;
  jen prodejní směr.
- `partner_balance` (int NULL → `base_persons_persons`, formulář „Plátce",
  index `idx_partner_balance`) — **osoba pro saldokonto**: partner
  saldokontního řádku (`accountingRules` `partnerSrc: "balance"`). Odvozuje
  `PartnerBalanceResolver` ve `validate()` i `beforeSave()` pro prodejní směr
  (`invno`, `cashreg`, `cash` příjem): karta / brána → protistrana terminálu,
  dobírka → protistrana dopravce, jinak `= partner`. Pro `invni` a výdej jen
  ruční zadání.
- `partner_balance_manual` (boolean default 0) — ruční plátce: krok „jinak
  = partner" ho nepřepíše; terminál / dopravce má přednost i před ním. Import
  (`_importNumber`) s ručním plátcem se respektuje celý.
- Prodejní doklad nad pokladnou (prodejka, příjmový PD) placený kartou,
  dobírkou nebo bránou musí mít plátce — chyba `partner_balance_required`
  (`CashDeskDocumentBase`).

### `lineage` — system

Stopa, odkud doklad vznikl. Plní `core.exchange` Applier při apply
canonical dokumentu; pro doklady ručně pořízené přes UI zůstává NULL
(případně `'manual'`).

| Sloupec | Typ | Popis |
|---|---|---|
| `source_kind` | enumString(40), nullable, cfgItem `docs.core.sourceKinds` | `aiExtraction` / `isdoc` / `peppolUbl` / `manual` / `import.flexibee` / `import.pohoda` |
| `source_message` | int, nullable, ref → `core_mail_incoming_messages`, index `idx_source_message` | Zdrojová zpráva došlé pošty (reverse lookup z dokladu → původ). Plní applier ze server-injektovaného `source.message` při apply návrhu. |
| `source_extracted_at` | datetime, nullable | Časový bod extrakce / importu |

Vazba je obousměrná (D6 z `tasks/mail-message-centric.md`): forward lookup
(zpráva → výsledný doklad) je v `core_mail_incoming_messages.target_table_id`
/ `target_row` — obě strany zapisuje apply atomicky
(`DocumentApplier::writeLineageTargets`). Přes `source_message` skládá
`DocsHeadsViewer::sourceAttachmentGroups` skupinu „mail" příloh v detailu
dokladu.

### `snapshots` — system

JSON dumpy partnera a vlastní firmy, sestavované při Koncept → Potvrzeno
(Fáze 2). Drží stav adresy, DIČ, bankovního účtu, court_registration —
nezávisle na pozdějších změnách v `base_persons_*`.

### `notes`

`notice` (interní), `doc_notice` (na doklad).

### Trvalé system sloupce

#### `doc_state_changed_at`

Datum a čas posledního přechodu mezi `docState` hodnotami (`datetime`,
nullable, `system: true`). Vyplňováno automaticky v `DocDocument::trackStateChange`
(první krok `beforeSave`):

- Nový záznam → NOW.
- Změna `docState` → NOW.
- Update bez změny `docState` → původní hodnota zůstává (klient ji v payloadu
  nemá nastavovat).

Slouží jako vstup pro alert check `docs.core.stale_in_repair` (detekce
dokladů visících ve stavu 80 V opravě déle než 24 h). Backfill existujících
řádků dělá `DsUpgradeCommand` jednorázovým idempotentním `UPDATE`.

## Indexy

- `idx_series_seq` — `(number_series, fiscal_year, sequence_number DESC)`,
  primární přístupová cesta vieweru per řada
- **`unq_series_seq` UNIQUE** — `(number_series, fiscal_year, sequence_number)`,
  pojistka proti duplicitním číslům dokladu. NULL hodnoty UNIQUE neporušují,
  takže víc Konceptů koexistuje
- `idx_doc_state` — `(docStateMain, doc_number DESC)`
- `idx_doc_state_changed` — `(docState, doc_state_changed_at)`, predikát
  alert checku `docs.core.stale_in_repair`
- `idx_partner` — `(partner)`
- `idx_source_message` — `(source_message)`, lineage doklad ← zpráva
- `idx_accounting_date`, `idx_vat_duzp` — pro reporty
- `ft_doc_text` FULLTEXT — `doc_text`

## Stavový model

`docs.core.docStates` (NE `core.system.docStatesArchive`). Klíčové rozdíly:

- + 20 Potvrzeno (přidělené číslo, ale stále editovatelné)
- + 30 Storno (zachovává číslo, sdílí `mainState=4` s V pořádku)
- − 70 V archívu (u dokladů nadbytečné)

Detaily v `docs/docs-mvp.md` sekce 3.

## Vazby na child tabulky

```
docs_core_rows.doc_head      → docs_core_heads.id  (dataKey: rows)
docs_core_vat_recap.doc_head → docs_core_heads.id  (dataKey: vatRecap)
```

TableGateway sync: bez `id` = INSERT, s `id` = UPDATE, chybějící = DELETE.

## Související

- [docs_core_rows](docs_core_rows.md) — řádky
- [docs_core_vat_recap](docs_core_vat_recap.md) — rekapitulace DPH
- [docs_core_number_series](docs_core_number_series.md) — řada, ze které pochází číslo
- [DocDocument](../src/DocDocument.php) — abstract base
- `docs/docs-mvp.md` sekce 6 — kompletní design hlavičky
