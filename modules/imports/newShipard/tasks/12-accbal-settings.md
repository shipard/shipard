# 12 — Import nastavení saldokont (accbal settings)

> **Stav:** ✅ Hotovo (2026-06-21) — `AccbalSettingsRunner` (`--dump` + `--import`)
> + CLI dispatch `accbal-settings` v `ImportApp.php`, PHP lint OK. Runtime běh
> (dump ze staré DB → ruční ladění JSONu → import na migrovaný DS → smoke v UI)
> je operační krok na vyžádání, ne součást kódu. Odchylky od PRD viz
> **Implementace ✓** níže (`docStateMain` / `modify_sign` / cesta JSONu).

> PRD pro jednu Claude Code session (old_shipard). Cílem je dostat nastavení
> saldokont ze starého Shipardu do nového. Nová strana **nepotřebuje nic
> nového** — settings tabulky `economy_accbal_balances` /
> `economy_accbal_balance_accounts` (nov Fáze 1) mají generický CRUD.
> Design saldokonta: `shpd:docs/accbal.md`.

## Kontext

Nastavení saldokont v novém Shipardu normálně „přijede" provisionerem, ten je
ale u importovaných (migrovaných) DS vypnutý (`skipProvisioning`). To je
záměrně dobře: u migrace chceme **simulovat chování starého saldokonta**, které
je mírně jiné než nový default — hlavní rozdíl jsou **dobropisy**: starý Shipard
je neuměl, takže staré nastavení nemá řádky pro přesměrování záporné pohledávky
(311−) do závazků. Migrované saldo tedy musí běžet na „starém" nastavení, ať
sedí čísla proti starému systému.

Řešení: hand-laděný JSON soubor s nastavením (žije ve starém Shipardu) + runner,
který ho naimportuje do nového přes generický CRUD. JSON se dá vygenerovat ze
staré DB (`--dump`) jako odrazový můstek — staré nastavení dobropisy nemá, takže
dump dá „staré chování" zadarmo; doladí se ručně jen mapování čísel účtů na
novou osnovu a kódy skupin.

## Cíl

1. `AccbalSettingsRunner` se dvěma režimy:
   - `--dump` — přečte staré `e10doc_accBal_balances` + `…_balancesAccounts`,
     zapíše JSON v seed tvaru nového Shipardu.
   - `--import` — přečte JSON, POSTne skupiny a jejich účty do nového Shipardu
     přes `CrudClient`.
2. JSON soubor (odrazový můstek z `--dump`, dál ručně laděný).

## Návaznost

- **Prerekvizita (nová strana):** nov Fáze 1 nasazená (tabulky + generický CRUD).
  Cílem je **migrovaný DS** (`skipProvisioning` → default seed neběžel → žádná
  kolize na unique `code`).
- **Mimo tento task (krok 3, odloženo):** až se v novém začnou pořizovat ostré
  doklady, starému nastavení se nastaví `valid_to` a přihrají se nová s
  `valid_from` (vč. dobropisů). To **vyžaduje**, aby generátor pohybů (nov
  Fáze 2b) ctil `valid_from/valid_to` podle účetního data řádku — ne „všechna
  aktivní". (Otevřený bod nov 2b → tímhle se rozhoduje pro přesnou validitu.)
- Není součástí standardní `AllRunner` data-pipeline — je to **konfigurace**,
  spouští se samostatně na vyžádání.

## Před implementací přečti

- `shpd:docs/accbal.md` §3.1/§3.2 (cílové tabulky), §3.2 (proč dobropisy
  potřebují modify_sign — ty ve starém nastavení nebudou)
- Staré tabulky:
  - `modules/e10doc/accBal/tables/balances.json` — sloupce `ndx`, `fullName`,
    `shortName`, `globalId`, `order`, `validFrom`, `validTo`, `docState`
  - `modules/e10doc/accBal/tables/balancesAccounts.json` — `ndx`, `balance`
    (FK), `accountId`, `accSide` (0 MD/1 DAL), `accAmountsSign` (0/1/2),
    `balSide` (0 Předpis/1 Úhrada), `balModifySign` (0 Ne/1 obrátit), `note`,
    `systemOrder`, `validFrom`, `validTo`, `docState`
- Vzor runneru + transport:
  - `modules/imports/newShipard/libs/runners/CashDesksRunner.php` (mapRow,
    sourceQuery, deriveCode)
  - `modules/imports/newShipard/libs/BaseCodebookRunner.php`
    (`processRow` → `CrudClient::create` + návratové id, report)
  - `modules/imports/newShipard/libs/ImportRunner.php` (`$this->context`,
    `$this->http()`, stará Dibi pro `--dump`, logy)
  - `modules/imports/newShipard/libs/runners/AllRunner.php` (jak se runnery
    instancují) + **CLI dispatch jednotlivých runnerů** (dohledat, jak se
    spouští samostatný runner / `--only`, kam přidat `accbal-settings`)
- Cílový tvar JSONu = seed nového Shipardu:
  `shpd:modules/economy/accbal/config/balancesDefault*.jsonc` — **porovnej
  názvy polí** (POSTuje se přímo do generického CRUD, musí sedět na sloupce
  tabulek)

## Scope

**Uvnitř:** `AccbalSettingsRunner` (`--dump` + `--import`); JSON soubor;
mapování starých sloupců → nový seed tvar; CLI zapojení samostatného běhu.

**Mimo:** jakákoliv změna nové strany (generický CRUD stačí); `LocalIdMap`
(re-import = `ds-reset`, viz Rozhodnutí); krok 3 (výměna platnosti); plnění
dobropisů.

## Co implementovat

### A. Tvar JSONu

`balances[]`, každá skupina s vnořenými `accounts[]` (ekvivalent seedu):

```json
{
  "balances": [
    {
      "code": "receivables",
      "name": "Pohledávky",
      "short_name": "Pohledávky",
      "sort_order": 1,
      "valid_from": null,
      "valid_to": null,
      "accounts": [
        {"account_number": "311", "acc_side": 0, "amounts_sign": 1, "bal_side": 0, "modify_sign": 0, "note": null, "sort_order": 1},
        {"account_number": "311", "acc_side": 1, "amounts_sign": 1, "bal_side": 1, "modify_sign": 0, "note": null, "sort_order": 2}
      ]
    }
  ]
}
```

Názvy polí = sloupce nových tabulek (`code`/`name`/`short_name`/`sort_order`/
`valid_from`/`valid_to`; účty `account_number`/`acc_side`/`amounts_sign`/
`bal_side`/`modify_sign`/`note`/`sort_order`). Default cesta souboru např.
`modules/imports/newShipard/data/accbalSettings.json` (volitelně `--file`).

### B. `--dump` (stará DB → JSON)

Mapování je téměř čistý rename:

| staré (`balances`) | JSON |
|---|---|
| `fullName` | `name` |
| `shortName` | `short_name` |
| `globalId` | `code` (viz pozn.) |
| `order` | `sort_order` |
| `validFrom`/`validTo` | `valid_from`/`valid_to` |

| staré (`balancesAccounts`) | JSON (`accounts[]`) |
|---|---|
| `accountId` | `account_number` |
| `accSide` | `acc_side` |
| `accAmountsSign` | `amounts_sign` |
| `balSide` | `bal_side` |
| `balModifySign` (0/1) | `modify_sign` (0/1) |
| `note` | `note` |
| `systemOrder` | `sort_order` |
| `validFrom`/`validTo` | `valid_from`/`valid_to` |

- Dibi proti staré DS: `balances` `WHERE docState != 9800 ORDER BY [order], [ndx]`;
  pro každou její `balancesAccounts` `WHERE [balance] = %i AND docState != 9800
  ORDER BY [systemOrder]`.
- `code`: použij `globalId`; je-li prázdné, odvoď slug z `name` a **warni**
  (`$this->warn(...)`) — uživatel doladí na seed kódy (`receivables`/`payables`/…).
- Zapiš pretty JSON (`JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE`) na cestu.
- Souhrn: kolik skupin / účtů vypsáno + upozornění na prázdné/podezřelé kódy.

> Dobropisy: staré nastavení řádky pro 311− nemá → dump je přirozeně bez nich.
> Ruční ladění se týká hlavně mapování `account_number` na novou osnovu a kódů
> skupin.

### C. `--import` (JSON → nový Shipard)

- Načti JSON, validuj základní tvar (pole `balances`, povinné `code`/`name`).
- `CrudClient` (vzor `BaseCodebookRunner::processRow`): pro každou skupinu:
  1. POST do `economy_accbal_balances`: `code`, `name`, `short_name`,
     `sort_order`, `valid_from`, `valid_to`, `docState` 40, `docStateMain` 3 →
     zachyť **nové id**.
  2. Pro každý účet POST do `economy_accbal_balance_accounts`: `balance` = nové
     id, `account_number`, `acc_side`, `amounts_sign`, `bal_side`,
     `modify_sign`, `note`, `sort_order`, `valid_from`, `valid_to`,
     `docState` 40, `docStateMain` 3.
- Report `created` (skupiny/účty) / `failed`; `--continue-on-error` jako
  ostatní runnery.
- **Bez `LocalIdMap`** — re-import po doladění = `ds-reset` + znovu (Rozhodnutí).
  FK skupiny řeší vnoření v JSONu (id z kroku 1), žádná cross-runner resoluce.

### D. CLI zapojení

Samostatný běh (ne v `AllRunner`): `--dump` / `--import` (+ volitelně `--file`).
Zapoj mírou existujícího dispatch jednotlivých runnerů (dohledané v
`Před implementací přečti`).

## Hotovo když

- `… accbal-settings --dump` vyrobí validní JSON ze staré DB (skupiny + účty,
  rename sloupců sedí, dobropisové řádky chybí).
- Po ručním doladění `… accbal-settings --import` na **migrovaném** DS založí
  skupiny a jejich účty v `economy_accbal_balances` /
  `economy_accbal_balance_accounts` (docState 40), FK účtů míří na správné nové
  id skupiny.
- Nastavení je vidět v novém Shipardu (Nastavení → Saldokonta), detail skupiny
  ukazuje účty.
- Generátor pohybů (nov 2b) z tohoto nastavení generuje saldo pohyby (smoke:
  jeden migrovaný doklad → očekávaný předpis), **bez** dobropisového
  přesměrování (sedí starému chování).
- Nová strana beze změny (žádný nový endpoint/schéma/migrace na nov).

## Doporučené pořadí

1. `--dump` (stará DB → JSON) + ruční kontrola výstupu.
2. `--import` (JSON → CrudClient) na migrovaném DS.
3. CLI zapojení + report + `--continue-on-error`.
4. Smoke: nastavení v UI + jeden migrovaný doklad → pohyb.

## Rozhodnutí ✓

1. **Lean transport + `ds-reset`** — runner POSTuje přes generický CRUD do
   existujících tabulek; nová strana beze změny. Re-import po doladění =
   `ds-reset` + znovu (sedí „re-import přes ds-reset"). Bez `LocalIdMap`. *(David ✓)*
2. **`--dump` + `--import` v jednom runneru**; `--dump` ze staré DB jako
   odrazový můstek (přirozeně bez dobropisů), pak ruční ladění. *(David ✓)*
3. **Tvar JSONu = seed `balancesDefault`** (skupiny + vnořené účty); jeden
   formát pro seed i import. *(David ✓)*

## Implementace ✓

- **Soubory:**
  - `libs/runners/AccbalSettingsRunner.php` — `extends ImportRunner` přímo (ne
    `BaseCodebookRunner`): vlastní cyklus dump/import přes JSON, bez
    `LocalIdMap`, FK účtů přes vnoření (POST skupiny → vrácené id → POST účtů).
  - `data/accbalSettings.json` — vyrábí `--dump`, dál ručně laděný (verzovaný).
  - `libs/ImportApp.php` — `case 'accbal-settings'` v `dispatch()` (mimo `all`,
    je to konfigurace) + blok v `printUsage()`.
- **`--dump`:** Dibi proti staré DS (`docState != 9800`, řazení `order`/
  `systemOrder`), pretty JSON. Kód skupiny = `globalId`; prázdné → slug z názvu
  + `warn`. Enum hodnoty se starý↔nový kryjí (acc_side 0/1, amounts_sign 0/1/2,
  bal_side 0/1) → čistý rename. Dobropisy přirozeně chybí.
- **`--import`:** per skupina POST do `economy_accbal_balances` → id jako FK
  `balance` pro POST účtů. Sdílí `--dry-run` / `--continue-on-error` / `-v` /
  `--file=`.
- **Odchylky od PRD (rozhodnuto podle existujícího kódu):**
  1. **`docStateMain` se neposílá** — je `system:true`, generický CRUD ho
     `filterWritableFields` zahodí; server ho dopočítá z `docState=40`
     (`initDocState`). Shodně s `BaseCodebookRunner` a všemi ostatními runnery.
  2. **`modify_sign` je boolean** (`true/false`) — sloupec je `boolean` a seed
     `balancesDefault.cz.jsonc` to tak má (PRD příklad měl `0/1`).
  3. **JSON žije v repu** (`__DIR__`-relativní default), ne v DS adresáři.

## Otevřené body

- ~~**CLI dispatch samostatného runneru**~~ — ✅ vyřešeno: `case
  'accbal-settings'` v `ImportApp::dispatch()`, sub-režimy přes
  `--dump`/`--import` (vzor `forget --entity`).
- **Mapování `code` skupiny** — staré `globalId` vs. nové seed kódy
  (`receivables`/`payables`/…). Dump dává `globalId`; reconciliation je ruční
  krok (hlavní důvod JSON mezivrstvy).
- **Mapování `account_number`** — stará osnova vs. nová (CZ standardní účty
  311/321/314/324 jsou nejspíš shodné; analytiky 311000 vs 311100 doladit ručně
  v JSONu).
