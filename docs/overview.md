# Přehled firmy — obrazovka „jak na tom firma je“

Specifikace obrazovky **Přehled firmy** (#120, D1–D19; zadání
`tasks/company-overview.md`). Dashboard odpovídá na otázku „co je potřeba
udělat“, Přehled firmy na „jak na tom firma je“: pět bloků — Peníze,
Pohledávky, Závazky, DPH, Výsledek hospodaření — složených z **existujících
výpočtů**. Každé číslo je proklik do zdroje a u každého zůstatku je datum,
ke kterému platí (D3). Je to **stav, ne fronta práce** — akce a upozornění
zůstávají na Dashboardu (#127, D11).

---

## 1. Hranice

- Jeden endpoint `GET /_ui/overview` skládá bloky z existujících výpočtů
  (saldokonto, bankovní výpisy, hlavní kniha, výsledovka, živé přiznání);
  jediný nový výpočet je termín podání DPH (D2, D17). Chybí-li zdroji
  číslo, blok ho **neukáže, nedopočítá** ho.
- Měny **per měna, domácí první**; žádný součet přes měny ani přepočet
  kurzem (D4; kurzy ČNB #121).
- Jen pro **adminy** (D10): `adminOnly` na panelu je úklid navigace,
  bariéra je v `OverviewController` (403 `FORBIDDEN`).
- Mimo v1: pruh nad feedem Dashboardu (D1), nástroj pro Chat / MCP (D2),
  obraty po zakázkách (D9), termíny Spisovny a K vyřízení (D13), práva pro
  ne-adminy (D10), zálohové faktury, zálohy, půjčky a úvěry ze saldokonta
  (D19).

---

## 2. Architektura

```
module.jsonc  overviewBlocks: [{id, section, order, class}]
      │  ModuleDefinition (validace) → ConfigCompiler
      ▼
cfgItem core.overview.blocks      {id: {section, order, class}}  (seřazeno [order, id])
cfgItem core.overview.sections    {sections: [{id, order, name}]}  (core.system)
      │
      ▼
OverviewBlockRegistry::fromConfig   instance bloků (class_exists + instanceof, jinak warn + skip)
OverviewCollector::collect          pořadí sekcí → order bloku, izolace per blok, stav sekce
OverviewController::index           401 → 403 → Response::success(...)
```

### 2.1 Registrace bloku (`overviewBlocks`)

```jsonc
"overviewBlocks": [
    {"id": "economy.bank.balances", "section": "money", "order": 10,
     "class": "Shipard\\Module\\Economy\\Bank\\Overview\\BankBalancesBlock"}
]
```

`id` identifikátor (`/^[a-z][a-zA-Z0-9_.]*$/`), `section` identifikátor
sekce z `core.overview.sections`, `class` FQCN, `order` int (default 1000).
Duplicitní `id` v modulu i napříč moduly je chyba (`ModuleDefinition`,
`ConfigCompiler`); cfgItem `core.overview.blocks` je rezervovaný
(`ConfigCompiler::OVERVIEW_BLOCKS_ITEM`). Blok s neznámou sekcí se při
sběru zaloguje a přeskočí. Po změně registrace je nutný `ds-upgrade`
(kompilace konfigurace).

### 2.2 Rozhraní bloku (`src/Core/Overview/`)

- `OverviewBlock` — `isAvailable(OverviewContext): bool` (typicky
  přítomnost klíčové tabulky přes `$ctx->hasTable()`; `false` = blok se
  do výsledku nedostane, bez chyby) a `collect(OverviewContext):
  OverviewBlockResult`. Bezparametrický konstruktor.
- `OverviewContext` (readonly): `db`, `?config` (`ConfigRuntime`),
  `language`, `today` (`DateTimeImmutable`), `reports` (`ReportRunner`),
  `tables` (registry `TableLoader::load`), `country`
  (`DataSourceConfig::getCountry()`), `homeCurrency`
  (`economy.homeCurrency`, fallback `czk`); helpery `hasTable()`,
  `todayIso()`, `texts(cfgItemId)` → `FeedTexts`.
- `OverviewItem` — `id, label, kind, amount {value, currency}|null, asOf,
  state (ok | stale | missing), note, dueDate, link`; `toArray()` posílá
  všechny klíče (null, kde hodnota chybí).
- `OverviewMessage` — `severity (info | warning | error), code, text,
  link`.
- `OverviewBlockResult` — `items`, `messages`, `state (ok | unavailable)`;
  `unavailable(message)` = blok číslo vědomě neukazuje (pojistka).

### 2.3 Sběr (`OverviewCollector`)

- Sekce v pořadí `core.overview.sections` (fallback pořadí + anglické
  názvy v PHP bez compiled configu); bloky uvnitř sekce dle `order`, pak
  `id`.
- **Izolace per blok** (vzor `FeedCollector`): výjimka v `isAvailable()`
  nebo `collect()` → `ErrorLogger::logException`, sekce dostane stav
  `error` a zprávu `blockFailed`, ostatní bloky běží dál.
- Stav sekce: `error` (některý blok selhal) > `unavailable` (sekce bez
  položek a některý blok ji vrátil) > `ok`. Sekce s položkami jiného
  bloku zůstává `ok` a zpráva pojistky stojí vedle položek (Peníze:
  banka + pokladna).
- Sekce bez položek i zpráv se **neposílá**.

### 2.4 Endpoint a práva

`Router`: `GET /_ui/overview` → `Route('overview', 'index')`;
`ReadOnlyPolicy`: `overview → Allow` (obrazovka jen čte, na read-only DS
má smysl). `public/index.php::dispatchOverview` staví `ReportRunner`
(`ReportDefinitionLoader`, `country`) a kontext **až po kontrole práv**
(closure v controlleru): nepřihlášený 401 `UNAUTHORIZED`, ne-admin 403
`FORBIDDEN`. Bez compiled configu 500 (jako `/_ui/ds-about`).

### 2.5 Texty

Texty sekcí: `name` z `core.overview.sections` (lokalizované kompilací).
Texty položek a zpráv: katalog modulu, který blok vyrábí (`*.overviewTexts`,
`config/overviewTexts.jsonc`, položka `{text, text:cs, text:en}`), čtení
`$ctx->texts('economy.bank.overviewTexts')->t(klíč, anglický fallback,
params)` — stejný mechanismus jako texty feedu (`FeedTexts`, ICU
MessageFormat, uvnitř plurálu `#`, vlastní číselný vzor bez mezery:
`{amount, number,#,##0.00}`). Fallback v PHP musí být znak po znaku holé
pole katalogu — hlídá `OverviewTextsCatalogTest` (společný základ
`TextsCatalogTestBase` s `FeedTextsCatalogTest`). Katalog sběru samotného:
`core.system.overviewTexts` (`section.blockFailed`).

---

## 3. Kontrakt `GET /_ui/overview`

```json
{
  "asOf": "2026-10-16",
  "sections": [
    {
      "id": "money", "title": "Peníze", "state": "ok",
      "items": [
        {
          "id": "bank:3", "label": "Běžný účet", "kind": "bank",
          "amount": {"value": 125000.0, "currency": "CZK"},
          "asOf": "2026-10-15", "state": "ok",
          "note": "Podle výpisu č. 182", "dueDate": null,
          "link": {"kind": "open_detail", "target": {"viewerId": "economy.bank.statements", "recordId": 812}}
        }
      ],
      "messages": [
        {"severity": "warning", "code": "openingMissing", "text": "…", "link": null}
      ]
    }
  ]
}
```

- `section.state`: `ok` | `unavailable` | `error` (§2.3).
- `item.state`: `ok` | `stale` (D14) | `missing` (účet bez výpisu).
- `amount.value` plná přesnost, `currency` velkými písmeny; formátuje
  frontend (`formatMoney`). Položka v jiné měně je samostatná položka,
  domácí měna první.
- `dueDate` (ISO) = termín — zatím jen podání DPH (D16, D17).
- `link` má tvar akcí reportů (`docs/reports.md` §16.5): `open_detail`,
  `open_viewer` (`viewGroup`, `filters`), `open_report` (`params`).
- `401` bez přihlášení, `403 FORBIDDEN` ne-admin, read-only DS `200`.

---

## 4. Bloky v1

| Blok (id) | Modul / třída | Sekce | Číslo | Datum (`asOf`) | Proklik |
|---|---|---|---|---|---|
| `economy.bank.balances` | `economy.bank` / `BankBalancesBlock` | money 10 | `closing_balance` posledního výpisu per platný účet (`valid_to`, `docState` ≠ 90; výpis podle `period_end`) | `period_end` výpisu | `open_detail` výpisu; `missing` → `open_viewer` výpisů |
| `economy.accounting.cashBalances` | `economy.accounting` / `CashBalancesBlock` | money 20 | zůstatek účtu pokladny z deníku za běžné období (`JournalAccountBalance`: otevírací + běžné měsíce, uzavírací mimo = `closing` hlavní knihy); cizoměnová pokladna z `money_dr_cur` / `money_cr_cur` (D19) | poslední `accounting_date` na účtu | `open_viewer` deníku (`fiscal_year`, `account`) |
| `economy.accbal.receivables` | `economy.accbal` / `ReceivablesBlock` | receivables | `CaseTotals` skupiny `receivables`: celkem (dluhy) + po splatnosti, per měna | dnešek | `open_viewer` případů s `viewGroup` + `filters` = tytéž filtry jako výpočet |
| `economy.accbal.payables` | `economy.accbal` / `PayablesBlock` | payables | skupina `payables`: po splatnosti + splatné do 7 dní (`dueWithin=7`) | dnešek | dtto |
| `economy.vat.return` | `economy.vat` / `VatBlock` | vat | řádek 64 / 65 reportu `economy.vat.returnLive` (`VatPositionReader`) pro předchozí období bez podání a běžné období (`VatPeriodSelector`) | dnešek; `dueDate` = termín podání | `open_report` živého přiznání (`period`) |
| `economy.accounting.profitLoss` | `economy.accounting` / `ProfitLossBlock` | result | `ytd` řádku `result` výsledovky za běžný měsíc (`ProfitLossReader`; plus `revenues` / `costs`) | dnešek | `open_report` výsledovky (`fiscalYear`, `monthFrom` = `monthTo` = běžný měsíc) |

### 4.1 Pravidla

- **Banka (D5, D14):** datum výpisu se posílá vždy; `period_end` starší
  než 35 dní před dneškem = `stale` (`BankBalancesBlock::STALE_DAYS`),
  účet bez výpisu nebo bez koncového zůstatku = `missing`.
- **Pokladna (D5):** poznámka „podle zaúčtovaných dokladů“; pokladna bez
  účtu (`accounting_account`) se vynechá (JOIN na `economy_accounting_accounts`).
- **Pojistka počátečních stavů (D6, D15)** —
  `Shipard\Module\Economy\Accounting\Overview\OpeningSequenceStatus::check(fiscalYearId)`:
  „chybí“ = běžné období (`yearForDate(today)`) nemá zápisy deníku
  v otevíracím měsíci (`period_type` 0) **a** předchozí období (nejbližší
  `date_end < date_begin`) zápisy má. První rok firmy ani prázdné předchozí
  období varování nedávají. Pokladna: místo položek zpráva `openingMissing`
  (bankovní položky zůstávají, sekce `ok`); Pohledávky a Závazky: sekce
  `unavailable`.
- **Saldokonto (D6, D18, D19):** jen skupiny `receivables` / `payables`,
  jen běžné účetní období (`fiscal_year` je součást klíče případu — součet
  přes období by dluh po převodu zdvojil). Bez případů položka s nulou
  v domácí měně. Zpráva `unmatchedPayments` per měna s počtem a
  `abs(residual)` otevřených případů skupiny `unmatched_payments`
  a proklikem (jen existuje-li skupina). Detaily `docs/accbal.md` §3.7.
- **DPH (D7, D16, D17):** neplátce (bez aktivní registrace k dnešku;
  nastavení `economy.vatAgenda === false` blok vypne) → blok bez položek,
  sekce se neposílá. Předchozí období se ukazuje, dokud k němu není
  podání ve stavu **Podáno** (`docState` 40) — sestavené nepodané podání
  termín neruší. Období bez dokladů = částka 0 + poznámka. Termín =
  `FilingDeadlines::forCountry(country)` → `CzFilingDeadlineRule`
  (konec období + 25 dní, posun vpřed přes víkend a svátek podle
  `Shipard\Module\World\Cz\Calendar\CzechHolidays` — pevné svátky,
  Velký pátek od 2016, Velikonoční pondělí; Meeus/Jones/Butcher); jiná
  země = termín se neposílá. Chybové zprávy reportu → zprávy sekce
  (`report.<code>`).
- **Výsledek hospodaření (D8):** `ProfitLossBuilder` dává stabilní klíče
  `result` / `costs` / `revenues` (`docs/reports.md` §16.4); blok čte podle
  nich, nikdy podle popisku. Výnosy a náklady kladně, výsledek kladný =
  zisk. Dnešek mimo běžný měsíc → zpráva `noCurrentMonth`.
- Všude: `docState` 90 (smazané) vynechat u výpisů, účtů, pokladen,
  registrací i instancí období; chybějící účetní období → zpráva
  `noFiscalYear`.

---

## 5. Jak přidat blok

1. Třída `Shipard\Module\<Group>\<Module>\Overview\<Name>Block implements
   OverviewBlock` (bezparametrický konstruktor); `isAvailable()` podle
   klíčové tabulky, `collect()` skládá `OverviewItem` / `OverviewMessage`
   z existujícího výpočtu — žádné nové sčítání.
2. Texty do `config/overviewTexts.jsonc` modulu + registrace cfgItem
   `<modul>.overviewTexts` v `config[]`; v PHP `->t('klíč', 'anglický
   fallback')`, fallback = holé pole katalogu.
3. `overviewBlocks` v `module.jsonc` (sekce z `core.overview.sections`;
   nová sekce = záznam v `modules/core/system/config/overviewSections.jsonc`).
4. `OverviewTextsCatalogTest::expected()` / `sources()` doplnit o katalog
   a zdroje; unit test bloku s mockem `DataSourceConnection`, u bloků nad
   `ReportRunner` (final) čistý reader + integrační test.
5. `ds-upgrade` na dev DS; `docs/overview.md` §4 a `help/uctarna/prehled-firmy.md`.

---

## 6. Frontend

- Panel `companyOverview` v `economy.accounting` `module.jsonc` (`navSection
  "_top"`, `navOrder 22` mezi Dashboardem 20 a Chatem 25, `adminOnly`,
  ikona `overview` = `faChartLine`); komponenta
  `frontend/src/components/overview/CompanyOverview.svelte` v mapě panelů
  `ContentArea.svelte`, API `frontend/src/api/overview.js` (vrací obálku,
  403 přes `translateError`).
- Sekce jako karty; položka = popisek (odkaz, je-li `link`), částka
  (`formatMoney` z `utils/formatNumber.js` — sdílené s pruhem Připraveno
  na Dashboardu), `asOf`, `dueDate` (zvýrazněný ≤ 7 dní před termínem
  a po něm), stav `stale` / `missing` jako badge, poznámka; zprávy sekce
  jako pruhy podle severity. Prokliky jako v `ReportsPage`:
  `open_detail` → `ViewerDetailModal`, `open_viewer` →
  `navigateToViewer(viewerId, recordId, viewGroup, filters)`,
  `open_report` → `navigateToReport(reportId, params)`.
- Texty rozhraní `overview.*` v `i18n/cs.js` + `en.js`; všechny tři
  shelly montují `ContentArea`, root leaf je generický — nic per shell.

---

## 7. Testy

- Jádro: `tests/Unit/Core/Overview/` (collector, registry, katalogy),
  `tests/Unit/Api/Controller/OverviewControllerTest.php` (401 / 403 / 200),
  `ReadOnlyPolicyTest`, `RouterTest`, `ModuleDefinitionTest`,
  `ConfigCompilerTest`, `NavigationControllerTest` (panel jen pro adminy).
- Bloky: `tests/Unit/Module/Economy/{Bank,Accounting,Accbal,Vat}/Overview/`,
  `tests/Unit/Module/World/Cz/CzechHolidaysTest.php`,
  `tests/Unit/Module/Economy/Vat/Deadline/`.
- Integrační (`SHIPARD_INTEGRATION_DS_PATH`): `tests/Integration/Accounting/
  CashBalancesBlockTest.php` (zůstatek = `closing` hlavní knihy),
  `ProfitLossBlockTest.php`, `tests/Integration/Accbal/CaseTotalsTest.php`
  (blok = patička vieweru), `tests/Integration/Vat/VatBlockTest.php`,
  `tests/Integration/Reports/ProfitLossBuilderTest.php` (klíče řádků).

---

## 8. Odchylky od zadání (`tasks/company-overview.md`)

1. Pokladna počítá zůstatek **přímým `SUM` nad deníkem**
   (`JournalAccountBalance`), ne přes report hlavní knihy — hlavní kniha
   ignoruje `money_*_cur` (D19) a `ReportRunner` je `final`; shoda
   s `closing` je invariant integračního testu.
2. „Podání“ u DPH (D16) = podání ve stavu **Podáno** (40); sestavené
   podání (10) předchozí období neskryje.
3. Kontrakt položky má navíc `dueDate`.
4. Tabulka pokladen je `economy_codebooks_cash_desks`, účet je FK
   `accounting_account` → číslo účtu přes JOIN.
5. `formatMoney` je v `utils/formatNumber.js` (vedle `formatAmount`), ne
   v nové `utils/money.js`; pruh Připraveno na Dashboardu tím v angličtině
   formátuje `1,234.56`.
6. Kalendář svátků a pravidlo termínu bez `class_exists` — `economy.vat`
   už na `world.cz` závisí; pravidlo nese vlastní konstantu 25 dní
   (`payableDueDays` je splatnost daně).
7. Chybějící třída bloku v cfgItemu = `warn` + přeskočit (DS před
   `ds-upgrade`), ne 500.
