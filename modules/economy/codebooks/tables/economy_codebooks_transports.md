# Tabulka: economy_codebooks_transports

Způsoby dopravy (#72 D5) — záměrně minimální: název, kód a protistrana.
Řidič, RZ ani hmotnost ze starého Shipardu se nepřebírají. Stavy dokumentů
(`core.system.docStatesArchive`), záznamy vznikají ručně jako `Koncept`
(10), uživatel je přepne do `V pořádku` (40).

`partner` je **Osoba pro saldokonto** — dopravce, který vybírá dobírku.
Doklad na dobírku s takovou dopravou zaúčtuje pohledávku 311 za dopravcem
s VS = číslo dokladu (`docs_core_heads.partner_balance`). Vlastní doprava
protistranu nemá (`NULL`) — pohledávka pak zůstává za odběratelem.

## Sloupce

### Skupina `identity`

| Sloupec | Typ | Popis |
|---|---|---|
| `code` | varchar(10), UNIQUE | Krátký kód (`PPL`, `OSOB`) |
| `name` | varchar(150) | Název způsobu dopravy |
| `notice` | varchar(250) NULL | Poznámka |

### Skupina `settings`

| Sloupec | Typ | Popis |
|---|---|---|
| `partner` | int NULL → `base_persons_persons` | Osoba pro saldokonto — dopravce; NULL = bez protistrany |
| `valid_from` | date NULL | Platnost od |
| `valid_to` | date NULL | Platnost do |
| `sort_order` | smallint default 0 | Pořadí ve výpisu |

### Systémové (bez skupiny)

| Sloupec | Typ | Popis |
|---|---|---|
| `docState` | tinyint default 10 | Stav dokumentu |
| `docStateMain` | tinyint default 1 | Sortovací sloupec stavů |

## Indexy

- `unq_code` UNIQUE na `code`
- `idx_partner` na `partner`
- `idx_sort_order` na `sort_order ASC, name ASC`
- `idx_doc_state` na `docStateMain ASC, sort_order ASC`

## Pravidla

- `code` a `name` povinné; `valid_from <= valid_to`; textová pole se
  trimují (`TransportDocument`).
- Bez výlučného defaultu — doprava se na dokladu vybírá vždy ručně.

## Návaznosti

- `docs_core_heads.transport` — způsob dopravy dokladu (prodejní směr);
  při způsobu úhrady **3 Dobírkou** a dopravě s protistranou se z ní
  odvodí `partner_balance`.
- Lookup [`TransportsLookup`](../src/TransportsLookup.php).

## Související

- [TransportDocument](../src/TransportDocument.php)
- [forms/economy_codebooks_transports.jsonc](../forms/economy_codebooks_transports.jsonc)
- `docs/accounting.md` § Osoba pro saldokonto
