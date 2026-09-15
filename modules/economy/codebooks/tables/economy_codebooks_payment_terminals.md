# Tabulka: economy_codebooks_payment_terminals

Platební terminály a platební brány — prostředníci, přes které zákazník
platí kartou nebo on-line (#72 D4). Jeden číselník pro oba druhy záměrně:
přibývají alternativní metody (QR platby ap.), u nichž není předem jasné,
zda jde o přímou platbu, nebo o prostředníka. Stavy dokumentů
(`core.system.docStatesArchive`), záznamy vznikají ručně jako `Koncept`
(10), uživatel je přepne do `V pořádku` (40).

Klíčem je sloupec `partner` — **Osoba pro saldokonto**. Doklad placený
kartou (terminál) nebo bránou zaúčtuje pohledávku 311 za touto osobou
s VS = číslo dokladu; zákazník z hlavičky zůstává jen jako odběratel.
Odvození dělá `docs.core` (`PartnerBalanceResolver` →
`docs_core_heads.partner_balance`), `economy.codebooks` na `docs.core`
nezávisí.

## Sloupce

### Skupina `identity`

| Sloupec | Typ | Popis |
|---|---|---|
| `code` | varchar(10), UNIQUE | Krátký kód (`T-HP1`, `GOPAY`) |
| `name` | varchar(150) | Název terminálu / brány |
| `notice` | varchar(250) NULL | Poznámka (číslo smlouvy, kontakt na provozovatele) |

### Skupina `settings`

| Sloupec | Typ | Popis |
|---|---|---|
| `kind` | enumInt, cfgItem `economy.codebooks.paymentTerminalKinds` | 0 Platební terminál / 1 Platební brána |
| `cash_desk` | int NULL → `economy_codebooks_cash_desks` | Pokladna, ke které terminál patří (1:N). **Povinná u terminálu, prázdná u brány.** |
| `partner` | int NULL → `base_persons_persons` | Osoba pro saldokonto — protistrana pohledávky. Ve stavu V pořádku povinná. |
| `is_default` | boolean default 0 | Výchozí terminál **v rámci pokladny**, resp. výchozí **brána** — výlučnost vynucuje `afterPersist` |
| `valid_from` | date NULL | Platnost od |
| `valid_to` | date NULL | Platnost do |
| `sort_order` | smallint default 0 | Pořadí ve výpisu a při výběru defaultu |

### Systémové (bez skupiny)

| Sloupec | Typ | Popis |
|---|---|---|
| `docState` | tinyint default 10 | Stav dokumentu (Koncept 10, V pořádku 40, …) |
| `docStateMain` | tinyint default 1 | Sortovací sloupec stavů |

## Indexy

- `unq_code` UNIQUE na `code`
- `idx_cash_desk` na `cash_desk` — default terminál pokladny
  (`WHERE cash_desk = ? AND kind = 0 AND is_default = 1`)
- `idx_partner` na `partner` — dohledání terminálů / bran dané osoby
- `idx_sort_order` na `sort_order ASC, name ASC` — výchozí řazení
- `idx_doc_state` na `docStateMain ASC, sort_order ASC` — viewer řadí
  aktivní záznamy nahoře

## Pravidla

- `kind = 0` (terminál) vyžaduje `cash_desk` (`cash_desk_required`);
  `kind = 1` (brána) ho zakazuje (`cash_desk_not_allowed`) a `beforeSave`
  ho vynuluje.
- `cash_desk` musí existovat a nebýt archivovaná (`invalid`) — klientský
  filtr lookupu není bezpečnostní hranice.
- `partner` je povinný ve stavech 40 a 80 (`partner_required`) —
  prostředník bez protistrany nedává smysl.
- `is_default = 1` je unikátní per pokladna (terminál) resp. mezi bránami:
  `PaymentTerminalDocument::afterPersist` odznačí ostatní defaulty ve
  stejném rozsahu, v transakci se save.
- `valid_from <= valid_to`; `code`, `name`, `notice` se trimují.

## Návaznosti

- `docs_core_heads.payment_terminal` — terminál / brána použitá k platbě;
  u karty se doplní default terminál pokladny hlavičky, u brány default
  brána. Způsob úhrady **5 Platební bránou** terminál (bránu) vyžaduje.
- `docs_core_heads.partner_balance` — odvozený plátce (`partner` odsud).
- Lookup [`PaymentTerminalsLookup`](../src/PaymentTerminalsLookup.php)
  s cascade filtry `kind` a `cash_desk`.

## Související

- [PaymentTerminalDocument](../src/PaymentTerminalDocument.php) — validace + výlučnost defaultu
- [forms/economy_codebooks_payment_terminals.jsonc](../forms/economy_codebooks_payment_terminals.jsonc) — deklarativní edit form
- [config/paymentTerminalKinds.jsonc](../config/paymentTerminalKinds.jsonc) — druhy
- `docs/accounting.md` § Osoba pro saldokonto
