# Hledání bez ohledu na diakritiku (issue #18)

**Stav:** hotovo — implementace, testy a docs 2026-09-27 (3 commity, #18); ruční ověření na dev DS prošlo

> **Modul:** jádro (`src/Core/Database`, `TableViewer`) + moduly s vlastním
> hledáním · **Typ:** oprava chování · **Issue:** #18 · **Schéma:** beze změny

## Kontext

Hledání ve viewerech, lookupech a MCP nástrojích nenajde `cesk` v `česk…`.
Databáze i sloupce mají `utf8mb4_czech_ci`. Ta je case-insensitive a ignoruje
čárky a kroužky (á=a, í=i, ý=y), ale `č ř š ž` a `ch` jsou v češtině samostatná
písmena, takže háčky rozlišuje. Stejně se chová i `utf8mb4_uca1400_czech_ai_ci`.
Česká collation, která by řadila česky a zároveň hledala bez háčků, neexistuje.

Řešení: sloupce zůstávají v české collation (řazení, unikátní indexy) a volné
hledání porovnává v jazykově neutrální `utf8mb4_uca1400_ai_ci`, nastavené
explicitně na **parametru**:

```sql
`full_name` LIKE '%cesk%' COLLATE utf8mb4_uca1400_ai_ci
```

Ověřeno na MariaDB 10.11: explicitní collation parametru přebije implicitní
collation sloupce (žádné „Illegal mix of collations“), funguje i pro `ascii`
sloupce (`enumString`) a `ORDER BY` dál jede po indexu. Hledání `%term%`
index nepoužívá ani dnes, výkon se tedy nemění.

## Rozhodnutí k designu (potvrzená, #18)

- ✓ **D1** Sloupce a databáze zůstávají v `utf8mb4_czech_ci`. Schéma, `ds-upgrade` ani `ds-reset` se nemění.
- ✓ **D2** Volné textové hledání jde přes jeden sdílený helper s `COLLATE utf8mb4_uca1400_ai_ci` na parametru. Nikde se nepíše ručně.
- ✓ **D3** Helper escapuje `%` a `_` v uživatelském vstupu přes Dibi `%~like~`.
- ✓ **D4** Řadicí collation podle jazyka zdroje dat se odkládá (do prvního ne-českého zdroje dat).

## Před implementací přečti

- `CLAUDE.md` → *Databáze*
- `docs/table-definitions.md` → *Znaková sada a collation*
- `docs/viewer-grid.md` §3 (jak viewery skládají `selectRows()`)
- `docs/mcp-server.md` §6 (vyhledávací nástroje)

## Co je potřeba udělat

### 1. Helper `Shipard\Core\Database\SearchCondition`

Nová `final` třída se statickými metodami, bez stavu:

- `public const COLLATION = 'utf8mb4_uca1400_ai_ci';`
- `contains(string $columnSql): string` → `"{$columnSql} LIKE %~like~ COLLATE utf8mb4_uca1400_ai_ci"`.
  `$columnSql` je hotový SQL výraz sloupce (včetně aliasu a backticků, např. ``p.`full_name` ``).
- `anyContains(array $columnSqls, string $term): array{0: string, 1: list<string>}`
  → `['(a LIKE … OR b LIKE …)', [$term, $term, …]]`. Prázdný seznam sloupců
  nebo prázdný `$term` → `['', []]`.

Parametr je **surový** hledaný text. `%` přidává Dibi (`%~like~`), které
zároveň escapuje `%`, `_` a `\`. Volající proto už nesmí obalovat `'%' . $search . '%'`.

Docblock třídy: jedna věta proč (česká collation rozlišuje háčky, řazení ji
potřebuje, hledání ne) + odkaz na #18.

### 2. `TableViewer::buildSearchCondition()`

Signaturu ponechat (holé názvy sloupců, obaluje backticky), uvnitř delegovat
na `SearchCondition::anyContains()`. Tím se oprava dostane ke všem viewerům,
které helper už používají.

### 3. Ručně psané hledání → helper

Převést **volné textové hledání** (vyhledávací pole vieweru, `q` lookupu,
filtr „partner“ podle názvu, dotaz MCP nástroje). Pozor na pořadí a počet
parametrů — dnes se `$term` přidává ručně N×.

| Soubor | Místo |
|---|---|
| `src/Api/Controller/CrudController.php` | operátor `like` v `buildFilterFragment()` |
| `modules/core/mail/src/AIProfilesViewer.php` | hledání |
| `modules/core/mail/src/IncomingMessagesViewer.php` | hledání (7 sloupců) |
| `modules/core/ai/src/AIBackendLookup.php` | `q` |
| `modules/economy/codebooks/src/BankAccountsLookup.php` | `q` |
| `modules/economy/codebooks/src/CashDesksLookup.php` | `q` |
| `modules/economy/codebooks/src/WarehousesLookup.php` | `q` |
| `modules/economy/codebooks/src/PaymentTerminalsLookup.php` + `PaymentTerminalsViewer.php` | `q` / hledání |
| `modules/economy/codebooks/src/TransportsLookup.php` + `TransportsViewer.php` | `q` / hledání |
| `modules/economy/items/src/ItemsViewer.php` + `ItemsLookup.php` | hledání / `q` |
| `modules/economy/accounting/src/AccountsLookup.php` | jen `q` (ř. ~49), ne `number_prefix` |
| `modules/economy/accounting/src/JournalViewer.php` | hledání + filtr `partner`; filtry `account` a `payment_reference` zůstávají prefixové |
| `modules/economy/bank/src/BankTransactionsViewer.php` | hledání |
| `modules/economy/accbal/src/BalancesLookup.php` | `q` |
| `modules/economy/accbal/src/CasesViewer.php`, `LedgerViewer.php` | filtr `partner`; `payment_reference` a `specific_symbol` zůstávají prefixové |
| `modules/base/persons/src/PersonsLookup.php` | `q` |
| `modules/base/persons/src/Mcp/PersonsSearchTool.php` | dotaz |
| `modules/base/registry/src/RegistryDocumentsViewer.php` | `LIKE` část hledání (`MATCH` beze změny) |
| `modules/docs/core/src/Mcp/DocumentsSearchTool.php` | dotaz na čísla dokladů |
| `modules/docs/core/src/DocsHeadsViewer.php` | hledání |
| `modules/hosting/core/src/DsUsersViewer.php`, `AiTokensViewer.php`, `AiUsageViewer.php` | hledání |

Seznam vznikl grepem `LIKE`. Před začátkem ho ověř stejným grepem
(`grep -rn "LIKE" --include=*.php src modules`), jestli nepřibylo další volné hledání.

### 4. Mimo rozsah — neměnit

- **Strukturální `LIKE`** nad kódy a čísly: prefixy účtů (`%like~` v
  `AccountMaskResolver`, `LedgerOpenItemLookup`, `CaseClosureRerouteHandler`,
  `ContentTagSuggestionsSource`, `ClosedPeriodBalanceService`,
  `VatJournalCrossCheck`), kontroly v `BankAccountDocument`,
  `CashDeskDocument`, `PersonDocument`, generátor `message_id`
  v `IncomingMessageDocument`, seed příkazy.
- **Resolvery výměnného formátu** (`PartyResolver`, `ItemResolver`) —
  párování podle názvu při importu. Změna by ovlivnila výsledky párování,
  patří do samostatného rozhodnutí.
- **FULLTEXT** (`MATCH … AGAINST`) v registru dokumentů — tokeny se řídí
  collation sloupce, `COLLATE` na `MATCH` nejde.
- **Řadicí collation podle jazyka** (D4).

### 5. Testy

- `tests/Unit/Core/Database/SearchConditionTest.php`: tvar fragmentu,
  počet a hodnoty parametrů, prázdné vstupy, aliasované sloupce.
- Existující testy viewerů a lookupů, které kontrolují SQL nebo parametry,
  upravit. Spouštět s úzkým `--filter` na dotčené třídy.
- Ruční ověření na dev zdroji dat (do commitu nebo tasku jen výsledek, bez dat):
  - hledání `cesk` ve vieweru Osob najde záznamy s `Česk…`,
  - hledání `50%` nebo `_` se nechová jako zástupný znak,
  - řazení vieweru zůstává české (`ch` za `h`).

### 6. Dokumentace

- `docs/table-definitions.md` → *Znaková sada a collation*: odstavec
  **Vyhledávání** — proč dvě collation, helper `SearchCondition`, pravidlo
  „volné hledání vždy přes helper, strukturální prefixy ne“, požadavek
  MariaDB ≥ 10.10 (`uca1400`).
- `CLAUDE.md` → *Databáze*: jedna odrážka odkazující na helper.
- `DEVELOPERS.md`: minimální verze MariaDB 10.10 (Ubuntu 24.04 má 10.11).
- `help/` beze změny — žádná stránka chování hledání nepopisuje.

## Commity

1. `SearchCondition` + `TableViewer::buildSearchCondition()` + test helperu.
2. Převod ručně psaného hledání (sekce 3) + úpravy testů.
3. Dokumentace (sekce 6) + `**Stav:**` tohoto tasku + `python3 scripts/tasks-index.py`.

## Hotovo když

- [x] `SearchCondition` existuje a `buildSearchCondition()` na něj deleguje.
- [x] Všechna místa ze sekce 3 používají helper, žádné volné hledání nepíše `'%' . $x . '%'` ručně.
- [x] Strukturální `LIKE` ze sekce 4 jsou beze změny.
- [x] Testy dotčených tříd procházejí, celá sada lokálně zelená.
- [x] Ruční ověření ze sekce 5 na dev zdroji dat prošlo.
- [x] Dokumentace ze sekce 6 aktualizovaná.
- [x] `**Stav:**` aktualizovaný a index přegenerovaný.

## Výsledek ověření (2026-09-27, dev DS `4l3j`)

Grep `LIKE` před začátkem nenašel žádné nové volné hledání proti tabulce
v sekci 3. Unit sada zelená (6341 testů), integrační filtr
`Viewer|Lookup|Mcp|Crud|Search|Registry|Persons` proti `4l3j` zelený.

| Ověření | Před | Po |
|---|---|---|
| viewer Osob, hledání `cesk` / `Cesk` | 0 | 2 shody (záznamy s „Česk…“) |
| viewer Osob, hledání `%`, `_`, `50%` | všech 12 (zástupný znak) | 0 |
| lookup Osob `q=cesk` | 0 | 2 |
| viewer účtů, hledání `zbozi` | 0 | 36 |
| CRUD `filter[full_name]=like:cesk` | 0 | 2 |
| řazení `hora, chleba, ibis` v `utf8mb4_czech_ci` | `ch` za `h` | beze změny, `ORDER BY` po indexu |

Nález navíc: s klientem v `utf8mb3` (holé `mariadb` CLI) dotaz padá na
`ERROR 1253 COLLATION … not valid for CHARACTER SET 'utf8mb3'`. Aplikace
nastavuje `utf8mb4` v obou Dibi spojeních; do docs přidána poznámka pro
ruční ladění (`--default-character-set=utf8mb4`). Nad rámec sekce 6
aktualizován popis operátoru `like` v `docs/rest-api.md`.
