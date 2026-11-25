# Konfigurace serveru z modulů — sekce `server.json` deklaruje modul (#172 D7, D10)

**Stav:** naplánováno — zadání 2026-11-25 (#172 D7, D10)

> Task pro Claude Code (3 commity). Design: issue #172. Navazuje na
> `tasks/module-commands-cron.md` (hotový — příkazy a úlohy cronu
> z `module.jsonc`). První odběratel mechanismu mimo přesouvané sekce je
> doplněk Evidence tržeb (#171 D9, sekce `salesRegistration`).

## Kontext

`ServerConfig` (`src/Core/Config/ServerConfig.php`) čte `/etc/shipard/server.json`
a nabízí typované gettery. Tři z nich čte jen kód modulu:

| Getter | Cesta v `server.json` | Výchozí stav / validace | Čte |
|--------|----------------------|-------------------------|-----|
| `getExchangeRatesServiceUrl()` + `DEFAULT_EXCHANGE_RATES_SERVICE_URL` | `exchangeRates.serviceUrl` | chybí → veřejná služba; `""` → `null` = stahování vypnuté; jiný typ / ne-http(s) → výjimka; koncové `/` pryč | `ExchangeRatesDownloadCommand::resolveServiceUrl()` (`economy.exchangeRates`) |
| `getRegistryPersonsBaseUrl()` | `registry.persons.baseUrl` | chybí → `https://data.shipard.org/persons`; prázdné / ne-http(s) → výjimka; koncové `/` pryč | `PersonsRegistryClient::fromServerConfig()` (`base.persons`); integrační test `PersonsRegistryControllerTest` |
| `getAiAnalysisMaxConcurrent()` + `DEFAULT_AI_ANALYSIS_MAX_CONCURRENT` | `ai.analysis.maxConcurrent` | chybí → 2; `0` = analýza vypnutá; záporné / nečíselné → výjimka | `AnalysisSlots::maxConcurrentOf()` (`core.mail`) |

Ostatní sekce (`mail`, `render`, `devAi`, `hosting` a základní klíče
serveru) čte kód jádra a zůstávají v `ServerConfig` (D10).

## Rozhodnutí (#172)

- **D7** — Modul deklaruje svou sekci `server.json`; dvě deklarace téhož
  klíče jsou chyba; třída modulu sekci načte a zkontroluje sama,
  `ServerConfig` dává jen surová data; sekce modulů se stěhují hned.
- **D10** (upřesňuje D7) — Sekce patří tam, kde žije kód, který ji čte.
  Klíč je **cesta s tečkami** (`ai.analysis`, `registry.persons`), aby
  dnešní `server.json` platil beze změny. Dvě deklarace kolidují, když je
  jedna cesta prefixem druhé (po segmentech), a cesta modulu nesmí začínat
  klíčem jádra. Stěhují se jen tři sekce z tabulky výše.

## Před implementací přečti

- `tasks/module-commands-cron.md` — hlavně implementační poznámky
  (`ModuleCommandRules` jako čistá funkce pravidel, test stromu)
- `docs/modules.md` kap. 5 (pole `commands`, `cronJobs` — vzor nové
  podkapitoly), kap. 9 (Doplňky — body rozšíření)
- `src/Core/Config/ServerConfig.php`, `tests/Unit/Core/Config/ServerConfigTest.php`
- `src/Core/Module/ModuleDefinition.php` (`parseCommandClasses`,
  `parseCronJobs`), `src/Core/Module/ModuleCommandRules.php` a jeho test
- `src/Core/Mail/MailSafetyConfig.php` — vzor konfigurační třídy, která
  potřebuje režim serveru
- čtenáři z tabulky výše a jejich testy
- `docs/exchange-rates.md` §9, `docs/ai.md` (limit souběhu analýz),
  `docs/operations/production.md`, `docs/dev/local-dev.md` — kde se klíče
  popisují

## 1. Mechanismus

```jsonc
// module.jsonc
"serverConfig": [
    {
        "key": "exchangeRates",
        "class": "Shipard\\Module\\Economy\\ExchangeRates\\ExchangeRatesServerConfig"
    }
]
```

- **Pole `serverConfig`** — seznam `{key, class}` (modul smí vlastnit víc
  sekcí). `key` = cesta segmentů `[a-z][a-zA-Z0-9]*` oddělených tečkou.
  Validace tvaru při načtení modulu (výjimka jako u `cronJobs`).
- **Rozhraní** `Shipard\Core\Config\ServerConfigSection`:
  - `public static function key(): string` — tatáž cesta jako
    v deklaraci;
  - `public static function fromServerConfig(ServerConfig $config): static`
    — načte sekci přes `getSection()` a zkontroluje ji; výchozí hodnoty
    a chybové hlášky jsou věc třídy. `ServerConfig` dostává třída celou,
    aby mohla číst režim serveru (vzor `MailSafetyConfig`).
- **`ServerConfig::getSection(string $path): mixed`** — surová hodnota na
  cestě, `null` když cesta chybí (prostřední segment, který není objekt,
  = `null`, ne výjimka; typ hodnoty kontroluje třída sekce).
- **`ServerConfig::CORE_KEYS`** — klíče nejvyšší úrovně, které čte jádro:
  `host`, `port`, `admin_user`, `admin_password`, `mode`, `domainsFile`,
  `dataSources`, `logFile`, `logLevel`, `extraModulesPath`, `mail`,
  `render`, `devAi`, `hosting` (ověř proti getterům, které po kroku 2
  v `ServerConfig` zůstanou).
- **Pravidla** jako čistá funkce (vzor `ModuleCommandRules`, např.
  `ModuleServerConfigRules`) + test nad celým stromem `modules/`:
  - třída existuje, implementuje `ServerConfigSection`, leží v namespace
    svého modulu a její `key()` se shoduje s deklarací;
  - žádné dvě deklarace nekolidují (stejná cesta, nebo jedna je prefixem
    druhé po segmentech — `ai` × `ai.analysis` kolidují, `ai.analysis`
    × `ai.chat` ne);
  - první segment cesty modulu není v `CORE_KEYS`.
- Neznámé klíče v `server.json` se dál tolerují (sekce neaktivního nebo
  odebraného modulu nesmí rozbít načtení).

Commit 1 (`feat(config): sekce server.json deklarované modulem (#172 D7, D10)`)
— pole, rozhraní, `getSection()`, `CORE_KEYS`, pravidla a test stromu,
podkapitola v `docs/modules.md` kap. 5. Ještě bez přesunů.

## 2. Přestěhování tří sekcí

| Modul | Klíč | Třída (návrh jména) | Nahrazuje |
|-------|------|---------------------|-----------|
| `economy.exchangeRates` | `exchangeRates` | `ExchangeRatesServerConfig` (`serviceUrl(): ?string`, konstanta výchozí adresy) | `getExchangeRatesServiceUrl()`, `DEFAULT_EXCHANGE_RATES_SERVICE_URL` |
| `base.persons` | `registry.persons` | `PersonsRegistryServerConfig` (`baseUrl(): string`) | `getRegistryPersonsBaseUrl()` |
| `core.mail` | `ai.analysis` | `MailAnalysisServerConfig` (`maxConcurrent(): int`, konstanta výchozího limitu) | `getAiAnalysisMaxConcurrent()`, `DEFAULT_AI_ANALYSIS_MAX_CONCURRENT` |

- **Chování beze změny:** stejné výchozí hodnoty, stejné chybové hlášky
  (text včetně cesty klíče), `""` u `serviceUrl` dál vypíná stahování,
  `0` u `maxConcurrent` dál vypíná analýzu, chyba v `ai.analysis` dál
  vede v `AnalysisSlots` na log + výchozí hodnotu.
- Kde se výjimka dnes vyhazuje až při volání getteru (ne při `load()`),
  zůstane to tak — jinak by chybná sekce jednoho modulu rozbila načtení
  konfigurace všem.
- Čtenáři přejdou na třídy modulů; gettery a konstanty z `ServerConfig`
  zmizí.
- Testy getterů z `ServerConfigTest` přejdou do testů tříd modulů
  (`tests/Unit/Module/<Skupina>/<Modul>/…`) se stejnými případy;
  v `ServerConfigTest` zůstane test `getSection()`.
- `grep -rn "getExchangeRatesServiceUrl\|getRegistryPersonsBaseUrl\|getAiAnalysisMaxConcurrent\|DEFAULT_AI_ANALYSIS_MAX_CONCURRENT\|DEFAULT_EXCHANGE_RATES_SERVICE_URL"`
  přes `src modules tests docs CLAUDE.md` musí po kroku vrátit jen
  historické zmínky v `tasks/`.

Commit 2 (`refactor(config): sekce kurzů, registru osob a analýzy pošty v modulech (#172 D10)`).

## 3. Dokumentace

- `docs/modules.md` kap. 9 Doplňky — mezi body rozšíření doplň
  `serverConfig`.
- `docs/exchange-rates.md` §9, `docs/ai.md`, `docs/operations/production.md`,
  `docs/dev/local-dev.md`, `docs/cli.md` — klíče se nemění, jen odkazy na
  gettery `ServerConfig` nahraď třídou modulu a u klíče uveď, který modul
  ho čte.
- `CLAUDE.md` — řádek `docs/exchange-rates.md` zmiňuje
  `ServerConfig::getExchangeRatesServiceUrl`; oprav.
- README dotčených modulů — sekce konfigurace serveru, pokud ji mají.

Commit 3 (`docs: sekce server.json z modulů (#172 D7, D10)`) včetně
`**Stav:**` tohoto tasku a `python3 scripts/tasks-index.py`.

## Hotovo když

- [ ] `module.jsonc` umí `serverConfig`; vadný tvar selže při načtení
      modulu; test stromu hlídá třídu, rozhraní, namespace, shodu klíče,
      kolize cest i kolizi s `CORE_KEYS`
- [ ] `ServerConfig::getSection()` vrací surovou hodnotu na cestě,
      `CORE_KEYS` odpovídá getterům, které v jádře zůstaly
- [ ] tři sekce čtou třídy modulů; gettery a konstanty z `ServerConfig`
      zmizely; chování (výchozí hodnoty, vypínače, chybové hlášky) beze
      změny — testy přestěhované se stejnými případy
- [ ] dnešní `server.json` na dev serveru se načte beze změny a
      `exchange-rates-download --dry-run` na volném zdroji dat i limit
      souběhu analýz dávají stejný výsledek jako před změnou
- [ ] dokumentace podle kroku 3, `**Stav:**` aktualizovaný, index tasků
      přegenerovaný
- [ ] celá sada PHPUnit lokálně zelená

## Implementační poznámky

(doplní Claude Code)
