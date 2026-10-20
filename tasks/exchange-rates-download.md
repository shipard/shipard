# Kurzy cizích měn — stahování lístků ze služby (#121, task B)

**Stav:** hotovo — implementace 2026-10-20 (5 commitů, #121 D2, D3, D9, U1–U7), ověřeno na volném zdroji dat `4l3j`; id upozornění je `economy.exchangerates.list_missing` (malá písmena), lístky k přítomným datům se ignorují, nález jen u typu s lístkem — viz Implementační poznámky

> Task pro Claude Code (5 commitů). Navazuje na task A
> (`tasks/exchange-rates-core.md`, hotový): lístky, importer, resolver,
> kurz v dokladech a bance. Tento task doplní stahování lístků ze služby
> v periodických úlohách, ruční doplnění přes CLI a upozornění na
> chybějící lístek.

## Kontext

Po tasku A se lístky do zdroje dat dostanou jen příkazem
`shpd-ds exchange-rates-import` nebo z datové sady. Bez nich formulář kurz
nedoplní a výpis z účtu v cizí měně se nenahraje (D7).

**Služba** (starý Shipard, `modules/services/money/libs/MoneyExchangeRateListsFeed.php`):

```
GET https://data.shipard.app/feed/money-exchange-rates
    ?date=YYYY-MM-DD                      lístek s validFrom = datum
    ?dateFrom=YYYY-MM-DD&dateTo=…         lístky s validFrom v rozsahu
    &listType=1|2|3                       1 = ČNB denní, 2 = ČNB ostatní měny, 3 = celní
```

- Odpověď je vždy HTTP 200 a JSON: `{"valid": 1, "data": [lístky]}`; žádný
  lístek → `{"valid": 0}`; chybné parametry → `{"valid": 0, "error": "…"}`.
- Lístky jsou ve formátu `shpd.exchangeRates.list.v1`: `listType`
  `cz.cnb.daily` / `cz.cnb.other` / `cz.customs.monthly`, denní
  `validTo: null`, měsíční `validTo` = poslední den měsíce, `listNumber`
  řetězec.
- Ověřeno 2026-10-20: denní 1.–20. 10. 2026 = 13 lístků (jen pracovní
  dny), měsíční leden–říjen 2026, dnešek dopoledne `valid: 0`. Velikost:
  denní lístek kolem 4,6 kB, měsíc denních kolem 60 kB, měsíční lístek
  kolem 18 kB; 22 měsíců denních v jednom dotazu 2,1 MB.
- Denní lístek obsahuje XDR, měsíční SVC a VEF — v `world.base.currencies`
  nejsou a importer z tasku A by kvůli nim odmítl celý lístek (U1).
  Měny, které starý Shipard neumí převést na kód, služba vynechává
  (dořeší se časem, mimo tento task).

## Rozhodnutí

Z #121:

- **D2** — zdroj je vlastní služba, ne přímo ČNB; adresa v `server.json`.
- **D3** — úloha ve slotu `five-minutes`; síť volá jen v pracovní den po
  14:35 (Europe/Prague) a jen dokud lístek pro ten den chybí; doplňuje
  chybějící lístky od začátku předchozího fiskálního roku, s omezeným
  počtem dotazů na jedno spuštění.
- **D9** — když lístek za pracovní den nepřibude ani další den, objeví se
  upozornění.

Upřesnění (potvrzená 2026-10-20):

- ✓ **U1 — Neznámá měna** se při importu přeskočí s varováním, zbytek
  lístku se uloží. Platí pro všechny cesty importu (CLI, datová sada,
  služba).
- ✓ **U2 — Konfigurace stahování per typ** v `world.cz.exchangeRateLists`
  (`download`): denní `listType` 1, vyhlášení po 14:35 Europe/Prague,
  pracovní dny podle `CzechHolidays`; měsíční `listType` 2, očekává se od
  1. dne měsíce.
- ✓ **U3 — Adresa služby** `exchangeRates.serviceUrl` v `server.json`,
  výchozí `https://data.shipard.app/feed/money-exchange-rates`; prázdný
  řetězec stahování vypne. Vývojářské servery stahují taky (#115).
- ✓ **U4 — Úloha `exchange-rates-download`** (`five-minutes`, modul
  `economy.exchangeRates`): okno od začátku předchozího fiskálního roku;
  chybějící lístky lokálním SQL, bez sítě, když nic nechybí; dotazy po
  kalendářních měsících, nejvýš 6 za běh; shodný lístek přeskočen, jiný
  obsah jen do logu; prázdný výsledek pro očekávaný den → další pokus
  o tentýž měsíc nejdřív za hodinu (dnešní lístek po 14:35 každý běh);
  chyba sítě nebo služby → log, nic se nezapíše.
- ✓ **U5 — CLI `shpd-ds exchange-rates-download [--from] [--to] [--type] [--dry-run]`**
  — ruční doplnění bez limitu dotazů.
- ✓ **U6 — Upozornění `economy.exchangeRates.list_missing`** (warning,
  1 h): chybí denní lístek za pracovní den v posledních 30 dnech před
  dneškem, nebo od 2. dne měsíce chybí měsíční lístek běžného měsíce.
- ✓ **U7 — Testy bez sítě**: vyměnitelný HTTP transport a fixtury
  s uloženými odpověďmi služby.

## Před implementací přečti

- `tasks/exchange-rates-core.md` (hlavně implementační poznámky),
  `docs/exchange-rates.md`
- `modules/economy/exchangeRates/src/` — `ExchangeRateListImporter`
  (`semanticErrors`, porovnání obsahu pro `skipped` / `conflict`),
  `ExchangeRateListImportResult`, `ExchangeRateListTypes`,
  `ExchangeRateListType`, `ExchangeRateResolver`
- `modules/world/cz/config/exchangeRateLists.jsonc`,
  `modules/world/cz/src/Calendar/CzechHolidays.php`
- `src/Command/Server/CronCommand.php` (`SLOT_JOBS`, `JOB_MODULES`),
  `tasks/cron-module-aware.md`
- `src/Core/Config/ServerConfig.php` (`getRegistryPersonsBaseUrl` jako vzor
  klíče s výchozí URL), `modules/base/persons/src/Registry/PersonsRegistryClient.php`
  (vzor HTTP klienta s timeoutem)
- `src/Core/Alerts/AlertCheck.php`, `docs/alerts.md`,
  `modules/economy/assets/src/Checks/` (vzor kontroly)
- `src/Core/Reports/DbFiscalPeriodProvider.php`,
  tabulka `economy_codebooks_fiscal_years`
- `src/Command/DataSource/ExchangeRatesImportCommand.php` (vzor příkazu)
- služba jen pro představu:
  `old_shipard:modules/services/money/libs/MoneyExchangeRateListsFeed.php`

## Rozsah

**V rozsahu:**

1. Importer: neznámá měna přeskočena (U1) — §1
2. Konfigurace stahování, `server.json`, klient služby (U2, U3) — §2, §3
3. Stahovač, CLI `exchange-rates-download`, periodická úloha (U4, U5) — §4, §5
4. Upozornění na chybějící lístek (U6) — §6
5. Dokumentace, nápověda, testy (U7) — §7, §8

**Mimo rozsah:**

- celní kurz (`cz.customs.monthly`) a kurz ECB
- doplnění měn, které služba vynechává, do `world.base.currencies`
- kontrola kurzů na dokladech (#130)
- kurzy v sadách `tests/datasets/` (task A U6)
- sdílená cache lístků pro celý server — každý zdroj dat stahuje sám

## 1. Importer — neznámá měna (U1)

`ExchangeRateListImporter::semanticErrors`: měna mimo `world.base.currencies`
už není chyba. Importer ji z lístku vyřadí, zapamatuje si ji a uloží zbytek.

- `ExchangeRateListImportResult` dostane `skippedCurrencies` (seznam kódů
  velkými písmeny).
- Lístek, ve kterém po vyřazení nezbude žádná měna → chyba „Lístek nemá
  žádnou známou měnu.“
- **Porovnání obsahu** (`skipped` × `conflict`) běží až nad vyfiltrovaným
  lístkem. Jinak by každé další stažení téhož lístku hlásilo konflikt.
- CLI `exchange-rates-import` vypíše přeskočené měny jako varování
  u souboru; seeder datové sady je hlásí v detailu výsledku, stav zůstává
  ok.
- Dvojí výskyt měny zůstává chybou.

## 2. Konfigurace (U2, U3)

### 2.1 Typy lístků

`modules/world/cz/config/exchangeRateLists.jsonc` — u každého typu klíč
`download`:

```jsonc
"cz.cnb.daily": {
    // … dosavadní klíče
    "download": {
        "serviceListType": 1,
        "frequency": "workingDays",   // lístek každý pracovní den
        "calendar": "cz",             // pracovní dny podle CzechHolidays
        "publishAfter": "14:35",      // dnešní lístek se čeká až po tomto čase
        "timezone": "Europe/Prague"
    }
},
"cz.cnb.other": {
    // … dosavadní klíče
    "download": {
        "serviceListType": 2,
        "frequency": "monthly"        // lístek s validFrom = 1. den měsíce
    }
}
```

- `ExchangeRateListType` dostane nepovinné `download` (hodnotový objekt);
  typ bez `download` se nestahuje.
- Kalendář `cz` → `Shipard\Module\World\Cz\Calendar\CzechHolidays`. Mapa
  kalendářů je v `economy.exchangeRates` (dnes jediný záznam); neznámý
  kalendář = typ se nestahuje a do logu jde varování.
- Neplatné `publishAfter` nebo `timezone` = typ se nestahuje + varování
  (ne pád úlohy).

### 2.2 `server.json`

`ServerConfig::getExchangeRatesServiceUrl(): ?string` — klíč
`exchangeRates.serviceUrl`:

- klíč chybí → `https://data.shipard.app/feed/money-exchange-rates`,
- prázdný řetězec → `null` = stahování vypnuto,
- jiná hodnota musí začínat `http://` nebo `https://`, jinak výjimka (jako
  `registry.persons.baseUrl`).

## 3. Klient služby

`ExchangeRateServiceClient` (`modules/economy/exchangeRates/src/Download/`):

```php
public function fetch(int $serviceListType, string $dateFrom, string $dateTo): ExchangeRateServiceResponse
```

- Vždy `dateFrom` + `dateTo` (i pro jeden den `from = to`) a `listType`.
- Timeout 15 s, připojení 5 s, bez přesměrování na jiný host. URL jen
  z `server.json`, nikdy z odpovědi.
- Výsledek: `lists` (dekódované lístky), `none` (`valid: 0` bez `error`),
  nebo `error` se zprávou (síť, HTTP ≠ 200, nevalidní JSON, `valid: 0`
  s `error`, chybějící `data`). Do logu jde zpráva, ne tělo odpovědi.
- HTTP jde přes rozhraní transportu (např. `ExchangeRateServiceTransport`),
  aby testy podstrčily uložené odpovědi (U7). Produkční transport podle
  vzoru `PersonsRegistryClient`.

## 4. Stahovač (U4)

`ExchangeRateDownloader` — vstupy: čas (injektovaný), typy, importer,
klient, přístup k fiskálním rokům, úložiště stavu pokusů.

### 4.1 Okno

- Konec = dnešní datum v Europe/Prague.
- Začátek = začátek předchozího fiskálního roku:
  1. fiskální rok, který končí den před začátkem roku obsahujícího dnešek
     → jeho `date_begin`;
  2. jinak `date_begin` roku obsahujícího dnešek minus 1 rok;
  3. bez fiskálních let 1. 1. předchozího kalendářního roku.
- `--from` / `--to` okno přepíšou (`--to` nejvýš dnešek).

### 4.2 Co chybí

Per typ s `download`:

- `workingDays`: každý pracovní den kalendáře v okně; dnešek jen tehdy,
  když je v `timezone` už po `publishAfter`.
- `monthly`: 1. den každého měsíce od měsíce začátku okna po běžný měsíc.
- Přítomné = `valid_from` lístků typu v okně (jeden SQL dotaz na typ).
  **Nic nechybí → žádná síť, žádný zápis do logu.**

### 4.3 Dotazy

- Chybějící data se seskupí po kalendářních měsících; dotaz = `dateFrom`
  první a `dateTo` poslední chybějící datum měsíce.
- Pořadí: nejnovější měsíc první (běžný měsíc je nejdůležitější).
- Z cronu (bez `--from` / `--to`) nejvýš **6 dotazů** na běh, typy
  dohromady. S `--from` / `--to` bez limitu.
- **Opakování:** měsíc, ve kterém po dotazu pořád něco chybí, se znovu
  zkusí nejdřív za hodinu. Výjimka: měsíc s dnešním chybějícím lístkem
  (po `publishAfter`) se zkouší v každém běhu. Stav pokusů (typ + měsíc →
  čas posledního pokusu) v nastavení zdroje dat přes `SettingsStore`,
  klíč `economy.exchangeRates.downloadState`; staré záznamy (měsíce mimo
  okno) se při zápisu zahodí. S `--from` / `--to` se pauza neuplatní.
- **Chyba služby nebo sítě:** varování do logu, běh skončí (další dotazy
  by nejspíš selhaly taky), pokus se zaznamená, nic se nezapíše.

### 4.4 Zápis

- Každý vrácený lístek přes `ExchangeRateListImporter` se zdrojem 3
  (služba). Lístky mimo očekávaná data (služba má lístek ke dni, který
  kalendář považuje za svátek) se uloží taky.
- `created` / `skipped` ok; `conflict` → varování do logu (typ, datum),
  lístek se **nepřepisuje**; `failed` → varování s důvodem.
- Přeskočené měny (U1) do logu jen u nově založeného lístku, ne při
  každém `skipped`.
- Výsledek běhu: `DownloadReport` per typ — očekáváno, chybí, dotazů,
  created / skipped / conflict / failed, chyby, přeskočené měny.

## 5. CLI a periodická úloha (U5)

**`shpd-ds exchange-rates-download [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--type=<id>] [--dry-run]`**

- Bez parametrů = běh z cronu (okno §4.1, limit 6, pauza 1 h).
- `--type` = jen jeden typ.
- `--dry-run` = okno, chybějící data a dotazy, které by šly ven; žádná síť
  ani zápis.
- Stahování vypnuté (`serviceUrl` prázdné) → zpráva a exit 0.
- Exit 1 při chybě služby nebo sítě, při `conflict` nebo `failed`; jinak 0.
- Registrace v `src/Cli/DsApplicationFactory.php` a v `HelpCommand`.

**Cron:** `CronCommand::SLOT_JOBS['five-minutes']` + `exchange-rates-download`,
`JOB_MODULES['exchange-rates-download'] = 'economy.exchangeRates'`.
Přepínač cronu per zdroj dat a stav zdroje dat řeší dispatcher jako
u ostatních úloh.

## 6. Upozornění na chybějící lístek (U6, D9)

`alertChecks` v `modules/economy/exchangeRates/module.jsonc`:
`economy.exchangeRates.list_missing`, severity `warning`, interval `1h`,
třída `Checks\ListMissingCheck`.

- **Denní typy (`workingDays`):** pracovní dny v [dnes − 30 dní, včera]
  (Europe/Prague), ale ne před začátkem okna §4.1, bez lístku → jeden nález
  na typ: počet a nejvýš 5 nejnovějších dat, např. „Chybí kurzovní lístek
  ČNB — kurzy devizového trhu za 3 pracovní dny (19. 10., 16. 10., 15. 10.).
  K těmto datům se kurz nedoplní.“
- **Měsíční typy:** od 2. dne měsíce chybí lístek s `valid_from` = 1. den
  běžného měsíce → nález.
- Dnešní lístek se nekontroluje (D9: „ani další den“).
- Kontrola běží i se stahováním vypnutým — lístky jsou potřeba i při
  ručním importu.
- Akce nálezu otevře viewer Kurzovní lístky, pokud to `docs/alerts.md`
  umí; jinak bez akce.

## 7. Dokumentace a nápověda

- `docs/exchange-rates.md`: §1 (`download`), §3 (neznámá měna, U1), §9
  přepsat z „Co přinese task B“ na popis stahování (služba, `server.json`,
  okno, limit, pauza, log), upozornění, pasti
- `docs/cli.md`: `exchange-rates-download`; u `exchange-rates-import`
  přeskočené měny
- `docs/alerts.md`: nová kontrola, pokud má katalog kontrol
- `help/co-dnes-nejde.md`: věta o tom, že se lístky nestahují samy
- `CLAUDE.md`: seznam příkazů `shpd-ds` a řádek `docs/exchange-rates.md`
- `tasks/README.md`: index

## 8. Testy

Úzké, PHPUnit jen s `--filter`. Fixtury v `tests/fixtures/exchange-rates/`
— uložené odpovědi služby (denní říjen 2026, měsíční říjen 2026, `valid: 0`,
`error`); kurzy ČNB jsou veřejná data.

- **Importer:** neznámá měna přeskočena a hlášena, lístek bez známé měny
  → chyba, opakovaný import téhož lístku s neznámou měnou → `skipped`
  (ne `conflict`).
- **Konfigurace:** parsování `download`, neplatné `publishAfter` /
  `timezone` / kalendář → typ se nestahuje; `ServerConfig` — výchozí URL,
  prázdný řetězec → null, ne-HTTP → výjimka.
- **Klient:** lístky, `valid: 0`, `error`, HTTP 500, nevalidní JSON, timeout
  (falešný transport).
- **Stahovač** (falešný čas a klient): víkend, svátek (28. 10., Vánoce),
  dnešek před a po 14:35 v Praze i se serverem v UTC, měsíční lístek
  1. den, okno ze všech tří větví §4.1, seskupení po měsících a pořadí od
  nejnovějšího, limit 6, pauza 1 h a výjimka pro dnešek, chyba ukončí běh,
  nic nechybí → nula dotazů, `conflict` nepřepisuje.
- **Upozornění:** chybí včerejší lístek → nález; dnešní před 14:35 → nic;
  víkend → nic; měsíční 1. den → nic, 2. den → nález; před začátkem okna
  → nic.
- **Cron:** úloha ve slotu `five-minutes` a vazba na modul (existující
  testy `SLOT_JOBS` / `JOB_MODULES`).

## Commity

1. Importer: neznámá měna přeskočena (§1)
2. Konfigurace stahování, `server.json`, klient služby (§2, §3)
3. Stahovač, CLI `exchange-rates-download`, periodická úloha (§4, §5)
4. Upozornění na chybějící lístek (§6)
5. Dokumentace, nápověda, hlavička tasku (§7)

## Nasazení a ověření

Na vývojářském stroji, **volný** zdroj dat bez ručně nahraných lístků
(lístky z ověření tasku A mají jiné hodnoty než ČNB — jejich data se
nestahují, protože jsou přítomná):

1. `ds-upgrade` → cfgItemy s `download`, upozornění v katalogu.
2. `exchange-rates-download --dry-run` → okno, chybějící data, dotazy.
3. `exchange-rates-download` bez parametrů několikrát → nejvýš 6 dotazů
   na běh, pak „nic nechybí“ a žádná síť.
4. `exchange-rate eur <včera>` → kurz ČNB; `exchange-rate xdr` → nenalezen
   (měna přeskočená při importu).
5. `shpd-server cron --slot five-minutes --ds <id>` → úloha proběhla;
   další běh bez sítě.
6. `alerts-run --check=economy.exchangeRates.list_missing` se staženými
   lístky → bez nálezu.
7. Reálné kopie: nic. Cron tam neběží (#115) a `ds-upgrade` jen po
   schválení člověkem.

## Pasti

- Porovnání obsahu až po vyřazení neznámých měn (§1) — jinak konflikt
  při každém stažení.
- Čas vyhlášení se porovnává v `Europe/Prague`, ne v časové zóně serveru.
- Úloha běží každých 5 minut na každém zdroji dat: bez chybějícího lístku
  žádná síť ani zápis do logu.
- Kalendář se může se službou rozejít (den bez lístku, který kalendář
  považuje za pracovní): pauza 1 h a upozornění to podrží viditelné, úloha
  nesmí zkoušet každých 5 minut donekonečna.
- Konflikt se nepřepisuje — lístek mohly použít doklady.

## Hotovo když

- [x] importer přeskočí neznámou měnu, hlásí ji a porovnává obsah po
      vyřazení
- [x] `download` u typů v `world.cz`, `ServerConfig::getExchangeRatesServiceUrl`
- [x] `ExchangeRateServiceClient` s vyměnitelným transportem
- [x] `ExchangeRateDownloader`: okno, chybějící data, měsíční dotazy od
      nejnovějšího, limit 6, pauza 1 h, chyba ukončí běh, konflikt
      nepřepisuje
- [x] `shpd-ds exchange-rates-download` s `--from` / `--to` / `--type` /
      `--dry-run`; úloha v `five-minutes` s vazbou na modul
- [x] upozornění `economy.exchangeRates.list_missing`
- [x] testy z §8 zelené (úzké `--filter`)
- [x] dokumentace a nápověda z §7; hlavička tasku aktualizovaná
- [x] ověření 1–6 na volném zdroji dat

## Implementační poznámky (2026-10-20)

Odchylky a upřesnění proti PRD (potvrzené v chatu 2026-10-20):

- **Id upozornění je `economy.exchangerates.list_missing`** (malá
  písmena): `AlertCheckDefinition` povoluje jen `[a-z][a-z0-9_.]*`,
  camelCase id by shodilo celý registr upozornění zdroje dat (odhalilo
  ověření krokem 6; konvence jako `economy.workorders.*`). Test modulu
  registr z `module.jsonc` staví.
- **`AlertCheck` dostává volitelný 4. parametr `?DataSourceConfig`**
  (reconciler, `alerts-run`, `AlertsController`): typy lístků žijí
  v `world.<země>.exchangeRateLists` a kontrola potřebuje zemi; existující
  kontroly beze změny (`docs/alerts.md` §4).
- **Lístky k datům, která typ už má, stahovač ignoruje bez porovnání**
  (`ignored`): dotaz na měsíc vrací i přítomné dny a ruční lístky s jinými
  hodnotami by jinak každou hodinu hlásily konflikt a shazovaly úlohu.
  Konflikt tak vzniká jen při souběhu dvou běhů; změnu obsahu lístku ve
  službě úloha nezjistí (odchylka od U4 „jiný obsah jen do logu“).
- **Nález upozornění jen u typu, který už nějaký lístek má**: zdroj dat
  bez kurzů by jinak nesl trvalé varování (odchylka od U6; kontrola dál
  běží i se stahováním vypnutým).
- **Chyba služby pozastaví celý následující běh na hodinu** (`lastError`
  ve stavu), jinak by výjimka pro dnešní lístek bušila do nedostupné
  služby každých 5 minut s varováním do logu. Výjimka pro dnešek platí jen
  u denního typu a jde samostatným dotazem `dnes..dnes` bez zápisu pokusu;
  měsíční lístek 1. dne čeká jako každý měsíc.
- **Ruční mód** (`--from` / `--to`) stav pokusů nečte ani nepíše; `--type`
  sám zůstává v cron módu a prořezání stavu jde jen podle měsíce. Stav se
  zapisuje jen v běhu, který poslal dotaz; `--dry-run` nikdy.
- Měsíční typ očekává 1. den měsíců od měsíce začátku okna (okno
  z fiskálního roku začínajícího uprostřed měsíce), dotaz na přítomná data
  jde přes rozsah očekávaných dat. Bez tabulky fiskálních let
  (`economy.codebooks` není závislost) nebo bez roku s dneškem platí
  větev 3 (1. 1. předchozího kalendářního roku).
- „Dnešek“ a vyhlášení se počítají v zóně typu (`ListDownloadConfig`,
  výchozí Europe/Prague), konec okna v Europe/Prague; `timezone` není
  u `monthly` povinná, u `workingDays` jsou `calendar` a `publishAfter`
  povinné.
- `failed` z importeru se jednou opakuje (souběh dvou běhů na unikátním
  klíči `list_type` + `valid_from`); lístek s budoucím datem se přeskočí
  s jedním varováním; lístek mimo očekávaná data (svátek, který služba má)
  se uloží.
- Stahovač sám neloguje; `DownloadReport` nese varování, poznámky a chybu
  a CLI je píše do `ErrorLogger` jen v běhu, který poslal dotaz (bez
  chybějícího lístku žádný záznam). Přeskočené měny jdou jako `info` per
  nově založený lístek — při prvním dohnání historie to je jeden řádek
  na lístek.
- Modul neaktivní = exit 0 s hláškou (cron), jako `vat-periods-ensure`;
  `exchange-rates-download` běží ve slotu před `alerts-run`, aby čerstvý
  zdroj dat nedostal nález dřív, než poprvé stáhne.
- Fixtury v `tests/Fixtures/Module/Economy/ExchangeRates/Service/`
  (konvence repa, ne `tests/fixtures/`): uložené odpovědi služby
  z 20. 10. 2026 (denní 1.–20. 10. = 13 lístků, měsíční říjen, `valid: 0`,
  `error`) + `FakeExchangeRateServiceTransport`; stahovač se testuje
  s `FakeExchangeRateListImporter`, `ArrayExchangeRateListPresence`
  a `tests/Fixtures/Core/Settings/InMemoryKeyValueStore`.

Ověření na volném zdroji dat `4l3j` (2026-10-20, fiskální roky 2026
a 2027 → okno od 1. 1. 2025, tři ruční lístky z tasku A):

1. `ds-upgrade` → cfgItem s `download`, `alerts-run` kontrolu zná.
2. `--dry-run`: okno 2025-01-01 – 2026-10-20, denní očekáváno 452 /
   přítomno 2 / chybí 450 (dopoledne „dnešní lístek se očekává až po
   vyhlášení“), měsíční 22 / 1 / 21, 44 dotazů od října 2026 dolů,
   6 označených k odeslání.
3. Osm běhů bez parametrů po nejvýš 6 dotazech (77 / 64 / 66 / 64 / 69 /
   64 / 66 / 1 založeno, 2 ignorovány = ruční lístky z tasku A, 0 konfliktů,
   XDR a SVC / VEF jako přeskočené měny), devátý „Nic nechybí.“ bez sítě;
   v DB 450 denních a 21 měsíčních lístků se zdrojem *služba*, stav
   `downloadState` s pokusy per měsíc.
4. `exchange-rate eur 2026-10-19` → kurz ČNB z lístku č. 26201;
   `eur 2026-10-16` → ruční lístek č. 201 beze změny; `xdr` → nenalezen
   (měna přeskočená).
5. `shpd-server cron --slot five-minutes --ds 4l3j…` → obě úlohy ok,
   stahování bez sítě za 200 ms.
6. `alerts-run --check=economy.exchangerates.list_missing` → 0 nálezů.
7. Reálné kopie: nic. Chování po 14:35 (dotaz jen na dnešek každý běh)
   pokrývají jednotkové testy, živě neověřeno (ověření proběhlo
   dopoledne).
