# Tabulka: Osoby (base_persons_persons)

Hlavní evidence osob v systému. Tabulka ukládá jak fyzické osoby, tak firmy
do jedné struktury — typ záznamu určuje sloupec `person_type`, který řídí
validaci i chování formuláře.

## Struktura

Sloupce jsou organizovány do skupin:

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `person_id` | varchar(10), NOT NULL, UNIQUE | Unikátní kód osoby, slouží jako lidsky čitelný identifikátor |
| `person_type` | enumInt | Typ osoby — viz [personTypes.jsonc](../config/personTypes.jsonc) a PHP enum `PersonType` |
| `company_id` | varchar(30) | IČO — relevantní u firem |
| `tax_id` | varchar(30) | DIČ |
| `vat_id` | varchar(30) | DIČ pro DPH |
| `court_registration` | varchar(250) | Zápis v obchodním rejstříku — typ "Městský soud v Praze, oddíl C, vložka 12345" |
| `gov_e_box_id` | varchar(10), nullable | ID datové schránky (CZ; 7 znaků v praxi, 10 rezervou) |

### Jméno (name)

| Sloupec | Typ | Popis |
|---|---|---|
| `full_name` | varchar(200), NOT NULL | Celý název — u firmy se zadává přímo, u osoby se skládá automaticky |
| `complex_name` | boolean | Zapne rozšířená pole jména (tituly, prostřední jméno) |
| `title_before` | varchar(50) | Tituly před jménem |
| `first_name` | varchar(100), NOT NULL | Křestní jméno |
| `middle_name` | varchar(100) | Prostřední jméno |
| `last_name` | varchar(100), NOT NULL | Příjmení |
| `title_after` | varchar(50) | Tituly za jménem |

### Osobní údaje (personal)

| Sloupec | Typ | Popis |
|---|---|---|
| `birth_date` | date | Datum narození |
| `national_id` | varchar(30) | Rodné číslo |
| `id_card_number` | varchar(30) | Číslo osobního dokladu |

### Kontaktní údaje (contact)

| Sloupec | Typ | Popis |
|---|---|---|
| `email` | varchar(200) | E-mail |
| `phone` | varchar(30) | Telefon |
| `web` | varchar(200) | Webová stránka |

### Dokumenty (documents)

| Sloupec | Typ | Popis |
|---|---|---|
| `language` | enumString(2), nullable | Jazyk dokumentů — klíč z `world.base.documentLanguages` (`cs`, `en`, `sk`, `de`). Výslovné přepsání; `NULL` = automaticky podle země (`DocumentLanguageResolver`). Čte se živě při tisku, do snapshotu strany na dokladu nepatří |
| `send_attachments_merged` | boolean | Extension z `docs.core` — přílohy dokladu připojit do PDF dokladu při odeslání (konzument: odeslání dokladu, #90 fáze 4) |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `is_closed` | boolean | Příznak uzavřeného záznamu |
| `closed_date` | date | Datum uzavření |
| `is_own` | boolean | Příznak vlastní firmy — max 1 záznam v DS, jen pro firmy |

### Původ záznamu (lineage)

| Sloupec | Typ | Popis |
|---|---|---|
| `source_kind` | varchar(40), nullable | Klíč z [base.persons.sourceKinds](../config/sourceKinds.jsonc) — `manual`, `aiExtraction`, `import.ares`, `import.rpo`, `import.handelsregister`, `import.shipardRegistry`, `import.csv` |
| `source_ref` | varchar(60), nullable | Identifikátor v zdrojovém registru — typicky IČO, ARES snapshot ID, RPO ID apod. |
| `source_imported_at` | datetime, nullable | Čas posledního importu / synchronizace |

Lineage sloupce vyplňuje `PersonApplier` (modul `core.exchange`) při apply
canonical payloadu — viz [exchange-format-persons.md §13](../../../../docs/exchange-format-persons.md#13-lineage).
Manuálně pořízené osoby přes UI mají `source_kind = NULL` (nebo zachovanou
hodnotu z dřívějšího importu — manuální editace přes UI lineage nepřepisuje).

## Obchodní logika (PersonDocument)

Dokumentová třída [PersonDocument.php](../src/PersonDocument.php) implementuje
hooky `validate` a `beforeSave`, které řídí chování podle typu osoby.

### Firma (person_type = 2)

- Při zadávání se vyplňuje `full_name` jako název firmy.
- `first_name`, `last_name`, `title_before`, `middle_name`, `title_after` se při uložení automaticky vyprázdní.
- Nastaví se jen `last_name` = `full_name`.
- Validace vyžaduje vyplněný `full_name`.
- Skupina `personal` (datum narození, rodné číslo, číslo dokladu) se v UI
  nezobrazuje — tyto sloupce nemají u firmy smysl.

### Fyzická osoba (person_type = 1)

- Validace vyžaduje vyplněné `first_name` i `last_name`.
- Skupina `personal` se zobrazuje — sloupce jsou nepovinné, vyplňují se
  dle potřeby (např. zaměstnanci, kde je potřeba rodné číslo).
- Chování při uložení závisí na hodnotě `complex_name`:

**complex_name = 0 (výchozí):**
- Zadává se pouze `first_name` a `last_name`.
- `title_before`, `middle_name` a `title_after` se při uložení nastaví
  na prázdný řetězec.
- `full_name` se složí jako `first_name + " " + last_name`.

**complex_name = 1 (rozšířený režim):**
- Zadává se všech pět sloupců: `title_before`, `first_name`, `middle_name`,
  `last_name`, `title_after`.
- `full_name` se při uložení sestaví ze všech vyplněných částí — nevyplněné
  se přeskočí, aby v názvu nevznikaly mezery navíc.

### Společné

- Sloupec `person_type` je vždy povinný — hodnota `Undefined` (0) neprojde
  validací.
- Sloupec `person_id` se generuje automaticky při prvním uložení záznamu —
  krátký alfanumerický hash (písmena + číslice, cca 5 znaků). Slouží
  k jednoznačné identifikaci na tištěných sestavách (faktury, dodací listy),
  kde může dojít k záměně u duplicitních jmen.

- Sloupec `language`: prázdný řetězec se ukládá jako `NULL` (automaticky
  podle země); hodnota mimo `world.base.documentLanguages` neprojde validací
  (chyba na poli `language`, kód `invalid_language`).

### Vlastní firma (is_own)

Flag `is_own = 1` označuje záznam jako "naši firmu" — z toho dokladový
systém čerpá údaje pro snapshot dodavatele/odběratele při potvrzení
dokladu.

Validace:
- Maximálně **jedna** osoba v DS smí mít `is_own = 1` (přes všechny
  aktivní stavy `docState != 90`).
- Vlastní firma musí být typu `Company` (`person_type = 2`).

Při instalaci nového DS uživatel ručně označí svou firmu — nelze vytvářet
doklady, dokud vlastní firma není nastavená (kontrola v dokladovém modulu).

### Lineage (původ záznamu)

Sloupce `source_kind`, `source_ref`, `source_imported_at` vyplňuje
`PersonApplier` v modulu `core.exchange` při apply canonical payloadu —
typicky při importu z ARES / RPO / vlastního Shipard registru nebo při
periodické synchronizaci. Sloupce jsou volitelné — manuálně pořízené
osoby přes FormEditor mají `NULL`.

`PersonApplier` lineage přepisuje **jen** když payload obsahuje
`source.kind` — UI editace osoby (která `source` neposílá) zachová
předchozí hodnoty.

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `unq_person_id` | unique | `person_id` | Unikátní kód osoby |
| `idx_full_name` | index | `full_name` | |
| `idx_last_name` | index | `last_name` ASC, `first_name` ASC | Řazení podle příjmení |
| `idx_company_id` | index | `company_id` | Vyhledávání podle IČO |
| `idx_email` | index | `email` | |
| `ft_full_name` | fulltext | `full_name` | Fulltextové vyhledávání |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [base_persons_contacts](base_persons_contacts.md) | `contacts.person` → `persons.id` | Kontaktní osoby a kontaktní místa přiřazená k osobě/firmě |
| [base_persons_bank_accounts](base_persons_bank_accounts.md) | `bank_accounts.person` → `persons.id` | Bankovní účty osoby/firmy |
| [base_persons_addresses](base_persons_addresses.md) | `addresses.person` → `persons.id` | Adresy — sídla, doručovací, provozovny, zařízení |
