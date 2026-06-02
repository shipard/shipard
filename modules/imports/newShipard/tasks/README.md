# Import to new Shipard — Tasks

Tento adresář obsahuje PRDs pro postupnou implementaci importu dat ze
starého Shipardu do nového Shipardu přes HTTPS REST API.

## Plánované fáze

| Fáze | PRD | Stav | Popis |
|---|---|---|---|
| 01 | [01-bootstrap.md](01-bootstrap.md) | ✅ Hotovo | Modul, CLI dispatcher, HTTP klient, LocalIdMap, `status` subcommand. |
| 02 | [02-codebooks.md](02-codebooks.md) | ✅ Hotovo | Číselníky — vat-registrations, fiscal-years/months, bank-accounts, cost-centers, warehouses, cash-desks, number-series, item-kinds + `all-codebooks` orchestrátor. |
| 02b | [02b-units.md](02b-units.md) | ✅ Hotovo | Měrné jednotky (`units`) — import uživatelských (`e10_witems_units`, system_code `_<ndx>`) i systémových (`e10.witems.units.json`, system_code = kód) → `core_units`. `system_code = původní token` → UnitResolver trefí items/docs. Řeší prázdné `core_units` při `skipProvisioning`. |
| 03 | [03-persons.md](03-persons.md) | ✅ Hotovo | Osoby přes `/_exchange/persons/person/apply`. |
| 03a | [03a-rate-limiting.md](03a-rate-limiting.md) | ✅ Hotovo | Rate limiting v HTTP klientu — respect 429 `_retry_after`, proaktivní throttling, exp. backoff pro 5xx/network. Patch cross-cutting pro všechny fáze. |
| 04 | [04-items.md](04-items.md) | ✅ Hotovo | Položky přes `/_exchange/items/item/apply`. |
| 05 | [05-docs.md](05-docs.md) | ✅ Hotovo | Doklady přes `/_exchange/docs/document/apply`. S filtrem `--from` / `--to` na `dateAccounting`. MVP: faktury (invni/invno). Prerekvizita: označená vlastní firma (`is_own=1`). |
| 05b | [05b-doc-numbers.md](05b-doc-numbers.md) | ✅ Hotovo | Import-mód čísla dokladu — parser pořadí z `docNumber` (řízený formulí), `applyOptions.importNumber` + `importOwnBankAccount`, sjednocení obou směrů na vložení rovnou na cílový stav, odstranění post-apply PATCHe. Counter naváže pro ostrý provoz. Prerekvizita: import-mód v novém Shipardu. |
| 06a | [06a-orchestrator.md](06a-orchestrator.md) | ✅ Hotovo | Orchestrátor `all` (codebooks → persons → items → docs), logování do souboru (`log/import-<ts>.log`), souhrnné statistiky, `reset` / `--reset` (smazání SQLite mapy) a chunkování dokladů po měsíčních úsecích (`--chunk-months`, řeší paměť u desetitisíců dokladů). `--from`/`--to` přes `all` omezí jen doklady. |
| 06b | (TBD) | Plán | Re-run — `--force-reimport=<entity>` (smaže mapping jen pro jednu entitu, vynutí re-import bez resetu celé mapy). Navazuje na 06a. |

PRDs pro fáze 03–06 vznikají postupně po dokončení předchozí fáze, aby
reflektovaly skutečnost po implementaci.

## Prerekvizity v novém Shipardu

| PRD | Stav | Důvod |
|---|---|---|
| `nov_shipard:tasks/exchange-format-items-phase1.md` | Hotovo | Exchange formát pro Položky — používá Fáze 04. |
| `nov_shipard:tasks/api-key-cli.md` | Hotovo | Generický CLI pro tvorbu API klíčů — používá importer pro autentizaci. |
| `nov_shipard:tasks/docs-import-number-mode.md` | Hotovo | Import-mód čísla dokladu (`applyOptions.importNumber` + counter sync) a oprava bank-validace přijatých faktur. Prerekvizita Fáze 05b. |

## Spuštění

```bash
cd /var/lib/shipard/data-sources/<dsid>

# Sanity check (Fáze 01)
shpd-app cli-action --action=imports.newShipard/import status

# Číselníky (Fáze 02)
shpd-app cli-action --action=imports.newShipard/import all-codebooks
shpd-app cli-action --action=imports.newShipard/import bank-accounts --dry-run
shpd-app cli-action --action=imports.newShipard/import fiscal-years -v

# Pozdější fáze (Fáze 03–05):
shpd-app cli-action --action=imports.newShipard/import persons
shpd-app cli-action --action=imports.newShipard/import items
shpd-app cli-action --action=imports.newShipard/import docs --from=2024-01-01 --to=2024-12-31

# Plné spuštění (Fáze 06)
shpd-app cli-action --action=imports.newShipard/import all
```

Pro pohodlnější UX si lze vytvořit shell alias:

```bash
alias shpd-ds-import='shpd-app cli-action --action=imports.newShipard/import'
shpd-ds-import status
```

## Společné CLI opce (rozšiřované per fáze)

- `--dry-run` — vypíše plán bez REST volání. (Fáze 02+)
- `-v` / `--verbose` — verbose output (request/response detaily). (Fáze 01+)
- `--continue-on-error` — pokračovat při chybě jednoho záznamu (default: stop). (Fáze 02+)
- `--from=YYYY-MM-DD` / `--to=YYYY-MM-DD` — filtr pro `docs` subkomandu. (Fáze 05)

## Klíčová design rozhodnutí

(Společná pro všechny fáze.)

- **Modul žije ve starém Shipardu** (`modules/imports/newShipard/`),
  paralelně k `modules/imports/erps/pohoda/`. Logicky a operačně blízko
  zdrojovým datům.
- **Komunikace přes HTTPS REST API** nového Shipardu, **žádný přímý
  přístup k DB**. Auth přes Bearer token (klíč `shpd_ak_…`).
- **Konfigurace v adresáři DS** — `config/import-newShipard.json`,
  per-DS, chmod 0600 kvůli API klíči.
- **Idempotence přes lokální SQLite mapu**
  (`import-newShipard.sqlite` v rootu DS) — `(entity_type, old_ndx) →
  new_id`. Pro číselníky bez business klíče. Pro entity s business
  klíčem (osoby přes IČO, doklady přes číslo) řeší idempotenci samotný
  exchange formát.
- **PK se nepřenáší** — nový Shipard si generuje vlastní `id`,
  mapování drží LocalIdMap.
- **Subkomand pattern přes pozicní argument**: `import <subcommand> [opts]`,
  jeden ModuleServices action `import` se větví podle `command(1)`.
- **Per-typ subkomanda** pro debuggable independent runs +
  orchestrátor (`all-codebooks`, později `all`) pro full sweep.
- **CLI framework starého Shipardu** — `shpd-app cli-action`. Žádná
  externí závislost (Symfony Console je v novém Shipardu, ne tady).
- **Verbose loggin volitelně přes `-v` / `--verbose`** flag.
