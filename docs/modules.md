# Shipard — Modulový systém

## 1. Přehled

Shipard je rozdělen do modulů. Modul je logická jednotka, která zapouzdřuje databázové tabulky, konfigurační soubory a business logiku pro určitou oblast systému (evidence osob, fakturace, smluvní agenda atd.).

Moduly jsou organizovány do **skupin** (groups) pro přehlednost. Skupina je čistě organizační složka bez vlastních metadat.

Na jednom serveru běží stovky modulů. Každý zdroj dat (data source) má v konfiguraci seznam aktivovaných modulů. Existují speciální instalační moduly (např. `install.base`), které nemají vlastní tabulky, ale přes závislosti aktivují sadu potřebných modulů.

### Druhy modulů

Moduly jsou tří druhů (#170 D1). O místě rozhoduje, **komu kód patří**, ne kolik zákazníků ho použije:

| Druh | Kde leží | Jak se dostane do zdroje dat |
|------|----------|------------------------------|
| **jádro** | `modules/` | závislost `install.base` |
| **doplněk** | `modules/`, v `module.jsonc` má `"kind": "addon"` | zapne se ve zdroji dat (`shpd-ds ds-module enable`); případně ho přinese vlastní instalační modul dedikovaného zdroje dat (jako `install.hosting`) |
| **privátní** | vlastní repozitář, `extraModulesPath` (kapitola 10) | zapne se ve zdroji dat |

Obecné a veřejné patří do hlavního repozitáře — jako jádro, nebo jako doplněk, když ho většina firem nepoužije (správa budovy, správa počítačové sítě). Logika jedné firmy nebo neveřejný kód je privátní modul mimo repozitář. Doplňky popisuje kapitola 9, přehled všech modulů po druzích je v generovaném rozcestníku [`modules/README.md`](../modules/README.md).

---

## 2. Formát konfiguračních souborů — JSONC

Všechny ručně psané konfigurační a definiční soubory v Shipard používají formát **JSONC** (JSON with Comments).

### Co JSONC přidává oproti JSON

- Jednořádkové komentáře: `// komentář`
- Víceřádkové komentáře: `/* komentář */`
- Trailing čárky: `{"a": 1, "b": 2,}` (čárka za posledním prvkem)

### Přípony souborů

| Typ souboru | Přípona | Formát | Příklad |
|-------------|---------|--------|---------|
| Ručně psané definiční soubory | `.jsonc` | JSONC | `module.jsonc`, `core_system_users.jsonc` |
| Generované / kompilované soubory | `.json` | Čistý JSON | `compiled.cs.json`, `compiled.en.json` |

### PHP implementace

PHP nemá nativní JSONC parser. Shipard implementuje vlastní utilitu `JsoncParser`, která:

1. Odstraní jednořádkové komentáře (`// ...`)
2. Odstraní víceřádkové komentáře (`/* ... */`)
3. Odstraní trailing čárky
4. Předá výsledek standardnímu `json_decode()`

Komentáře uvnitř řetězců (v uvozovkách) se zachovávají beze změny.

### Příklad JSONC souboru

```jsonc
{
    // Identifikace modulu
    "id": "economy.docs",
    "name": "Documents",
    "name:cs": "Doklady",

    /*
     * Závislosti na dalších modulech.
     * Musí být aktivovány před tímto modulem.
     */
    "dependencies": [
        "core.system",
        "base.persons",
    ],

    "tables": [
        "economy_docs_heads",
        "economy_docs_rows",  // trailing čárka je povolena
    ]
}
```

---

## 3. Vícejazyčnost (i18n)

Shipard podporuje vícejazyčnost přímo v definičních souborech. Překlady se uvádějí pomocí suffixu `:jazyk` u příslušných polí.

### Jazykové kódy

Dvoupísmenné kódy dle ISO 639-1: `cs`, `en`, `de`, `sk`, `pl` atd.

### Podporované jazyky (počáteční)

- `cs` — čeština
- `en` — angličtina

Další jazyky lze přidat bez změny formátu.

### Formát

Vícejazyčné pole se uvádí ve třech variantách:

```jsonc
{
    // Holé pole — povinné, slouží jako fallback / vývojový popisek
    "name": "Column Name",

    // Jazykové varianty — volitelné
    "name:cs": "Název sloupce",
    "name:en": "Column Name"
}
```

### Pravidla

1. **Holé pole (bez suffixu) je vždy povinné** — slouží jako záchytná síť, když překlad chybí
2. Jazykové varianty jsou volitelné — systém funguje i bez nich
3. Vícejazyčnost se vztahuje na **metadata i obsahová data** — názvy modulů, sloupců, popisky, ale i názvy sazeb DPH, typů dokladů atd.

### Fallback logika

Při vyhodnocování vícejazyčného pole pro jazyk `XX`:

1. Hledej `pole:XX` (požadovaný jazyk, např. `name:cs`)
2. Pokud neexistuje → použij `pole:en` (angličtina jako univerzální fallback)
3. Pokud ani to neexistuje → použij `pole` (holé pole bez suffixu)

### Která pole jsou vícejazyčná

Vícejazyčná jsou všechna pole, která se zobrazují uživateli v UI:

| Kontext | Vícejazyčná pole |
|---------|-----------------|
| `module.jsonc` | `name`, `description` |
| Definice tabulky | `name` (název tabulky pro UI) |
| Definice sloupce | `name` (název sloupce pro formuláře a seznamy) |
| Konfigurační soubory | Jakékoliv pole s lidsky čitelným textem (např. `name` u sazeb DPH) |

Technická pole (`id`, `type`, `length`, `dependencies` atd.) nejsou vícejazyčná.

### Příklady

**module.jsonc:**
```jsonc
{
    "id": "economy.docs",
    "name": "Documents",
    "name:cs": "Doklady",
    "name:en": "Documents",
    "description": "Invoices, orders and other documents",
    "description:cs": "Faktury, objednávky a další účetní doklady",
    "description:en": "Invoices, orders and other accounting documents"
}
```

**Konfigurační soubor (vatRates.jsonc):**
```jsonc
{
    "rates": [
        {
            "id": "standard",
            "rate": 21,
            "name": "Standard rate",
            "name:cs": "Základní sazba",
            "name:en": "Standard rate"
        },
        {
            "id": "reduced1",
            "rate": 12,
            "name": "Reduced rate",
            "name:cs": "Snížená sazba",
            "name:en": "Reduced rate"
        }
    ]
}
```

### Kompilace — jazykové varianty

Kompilátor konfigurace generuje **jeden soubor na jazyk**:

```
config/configuration/compiled.cs.json    — česká varianta
config/configuration/compiled.en.json    — anglická varianta
config/configuration/compiled.sk.json    — jazyky dokumentů (sk, de)
config/configuration/compiled.de.json
```

Jazyky kompilace = jazyky rozhraní (`cs`, `en`) ∪ klíče cfgItemu
`world.base.documentLanguages` (`ConfigCompiler::languages()`). Rozhraní
zůstává `cs` / `en`; kompilát v jazyce dokumentů čte tisk dokladu
([prints.md](prints.md) §3). Bez modulu `world.base` se kompilují jen
jazyky rozhraní.

Při kompilaci se pro každý jazyk:

1. Pro každé vícejazyčné pole aplikuje fallback logika
2. Výsledné `name` obsahuje vždy jeden řetězec (pro daný jazyk)
3. Jazykové suffixy (`name:cs`, `name:en`) se z kompilovaného souboru odstraní

**Zdrojový soubor (vatRates.jsonc):**
```jsonc
{
    "rates": [
        {"id": "standard", "rate": 21, "name": "Standard rate", "name:cs": "Základní sazba", "name:en": "Standard rate"}
    ]
}
```

**Kompilovaný (compiled.cs.json):**
```json
{
    "rates": [
        {"id": "standard", "rate": 21, "name": "Základní sazba"}
    ]
}
```

**Kompilovaný (compiled.en.json):**
```json
{
    "rates": [
        {"id": "standard", "rate": 21, "name": "Standard rate"}
    ]
}
```

### ICU vzory v katalozích textů

Lokalizované pole nemusí být jen hotový řetězec — katalogy textů, které
skládá server s parametry nebo plurálem, nesou **vzor ICU MessageFormat**
(stejná syntaxe jako frontend, `frontend/src/i18n/cs.js`). Kompilace
s ním zachází jako s každým jiným polem (`text`, `text:cs`, `text:en` →
jedno `text` per jazyk); formátování dělá až čtenář katalogu přes
`\MessageFormatter` s locale uživatele. První katalog tohoto druhu jsou
texty karet feedu (`*.feedTexts`, `Shipard\Core\Feed\FeedTexts`,
[dashboard.md](dashboard.md) §5).

```jsonc
"digest.title": {
    "text": "{n, plural, one {# message auto-archived} other {# messages auto-archived}}",
    "text:cs": "{n, plural, one {# zpráva automaticky archivována} few {# zprávy automaticky archivovány} other {# zpráv automaticky archivováno}}",
    "text:en": "{n, plural, one {# message auto-archived} other {# messages auto-archived}}"
}
```

Pravidla pro vzory (omezení PHP `ext-intl`):

- uvnitř plurálu **vždy `#`**, ne `{n}` — PHP předá netypovaný `{n}` jako
  řetězec a plurálový selektor jako číslo, tentýž argument v obou rolích
  skončí `U_ARGUMENT_TYPE_MISMATCH`; `#` je číslo formátované v locale
  (v `cs` s nezlomitelnou mezerou u tisíců);
- apostrof je escape znak ICU — v anglických textech `''`;
- české plurály: `one` = 1, `few` = 2–4, `other` = 5+ (i 21, 22…),
  `many` jen pro desetinná čísla;
- holé pole je anglický fallback a musí se shodovat s fallbackem v PHP,
  který čtenář použije bez compiled configu; test úplnosti katalogu
  (vzor `FeedTextsCatalogTest`) hlídá cs/en, parsovatelnost a shodné
  parametry obou jazyků.

### Runtime

Při startu aplikace se načte kompilovaný soubor pro jazyk přihlášeného uživatele:

```php
$lang = $user->getLanguage(); // "cs"
$config = ConfigRuntime::load($dataSourcePath, $lang);
$vatRates = $config->cfgItem('economy.docs.vatRates');
// $vatRates['rates'][0]['name'] === "Základní sazba"
```

### Výchozí jazyk

V `config/main.json` zdroje dat se nastavuje výchozí jazyk volitelným polem `defaultLanguage` (ISO 639-1, lowercase):

```json
{
    "id": "a3f2-b8c1-d4e7-f9a0",
    "name": "Naše firma s.r.o.",
    "defaultLanguage": "cs",
    "modules": ["install.base"]
}
```

Výchozí jazyk se použije jako fallback, pokud informace o jazyce uživatele chybí (chybějící hlavička `Accept-Language`, API volání bez jazykového kontextu atd.).

Backend čte volbu z `Accept-Language`. Pokud hlavička chybí, použije se `DataSourceConfig::getDefaultLanguage()` — vrací hodnotu z `main.json`, fallback `'en'` když pole chybí. Frontend odesílá `Accept-Language: {language.current}` z `language` storu (viz `docs/frontend.md` sekce *Internacionalizace*).

---

## 4. Identifikace modulu

### ID modulu

Formát: `{skupina}.{modul}` — tečková notace.

Příklady:
- `base.persons` — evidence dodavatelů/odběratelů
- `economy.docs` — doklady (faktury, objednávky)
- `economy.contracts` — smlouvy
- `install.base` — instalační profil se základními moduly
- `core.system` — systémové tabulky (uživatelé, sessions, nastavení)

Pravidla:
- Skupina: jedno slovo malými písmeny, bez diakritiky a podtržítek — `[a-z][a-z0-9]*`
- Modul: camelCase s malým počátečním písmenem, bez diakritiky a podtržítek — `[a-z][a-zA-Z0-9]*` (např. `workOrders`, `invoicesOut`)
- Vždy právě dvě úrovně (skupina.modul)
- ID modulu je globálně unikátní
- ID je trvalé — určuje názvy tabulek, namespace PHP tříd a klíče konfigurace. Druh modulu (jádro × doplněk) trvalý není, mění se jedním polem

Adresář s `module.jsonc`, jehož jméno skupiny nebo modulu tvaru neodpovídá, je **chyba při načtení modulů** (`RuntimeException` s cestou a očekávaným tvarem), ne tiché přeskočení — modul by se jinak nikdy nenačetl a nic by neřeklo proč. Adresáře bez `module.jsonc` se ignorují.

### Adresářová struktura

ID modulu přímo odpovídá cestě v souborovém systému:

```
/opt/shipard/shpd/modules/
├── core/
│   └── system/
│       ├── module.jsonc
│       ├── tables/
│       │   ├── core_system_users.jsonc
│       │   ├── core_system_sessions.jsonc
│       │   └── core_system_settings.jsonc
│       └── config/
│           └── systemSettings.jsonc
├── base/
│   └── persons/
│       ├── module.jsonc
│       ├── tables/
│       │   └── base_persons_contacts.jsonc
│       ├── config/
│       │   └── virtualGroups.jsonc
│       └── extensions/
│           └── ext-some-table.jsonc
├── economy/
│   ├── docs/
│   │   ├── module.jsonc
│   │   ├── tables/
│   │   │   ├── economy_docs_heads.jsonc
│   │   │   └── economy_docs_rows.jsonc
│   │   └── config/
│   │       └── vatRates.jsonc
│   └── contracts/
│       ├── module.jsonc
│       └── tables/
│           └── economy_contracts_main.jsonc
└── install/
    ├── base/
    │   └── module.jsonc
    └── full/
        └── module.jsonc
```

> Adresářová struktura se vztahuje na **jakýkoliv root**, ne jen na hlavní `modules/`. Stejné pravidlo platí pro externí rooty z `extraModulesPath` (viz sekce 10). Cesta k modulu se vždy odvozuje z jeho ID jako `{root}/{group}/{module}/`.

---

## 5. Definiční soubor modulu — `module.jsonc`

### Struktura

```jsonc
{
    // Identifikace
    "id": "economy.docs",
    "name": "Documents",
    "name:cs": "Doklady",
    "name:en": "Documents",
    "description": "Invoices, orders and other documents",
    "description:cs": "Faktury, objednávky a další účetní doklady",
    "description:en": "Invoices, orders and other accounting documents",

    // Závislosti
    "dependencies": [
        "core.system",
        "base.persons"
    ],

    // Tabulky vlastněné tímto modulem
    "tables": [
        "economy_docs_heads",
        "economy_docs_rows"
    ],

    // Rozšíření tabulek jiných modulů
    "extensions": [
        {
            "table": "base_persons_contacts",
            "file": "extensions/ext-base-persons-contacts.jsonc"
        }
    ],

    // Konfigurační položky
    "config": [
        {
            "id": "economy.docs.vatRates",
            "file": "config/vatRates.jsonc"
        },
        {
            "id": "economy.docs.docTypes",
            "file": "config/docTypes.jsonc"
        }
    ]
}
```

### Pole

| Pole | Typ | Povinné | Vícejazyčné | Popis |
|------|-----|---------|-------------|-------|
| `id` | string | Ano | Ne | ID modulu v tečkové notaci |
| `name` | string | Ano | Ano | Lidsky čitelný název modulu |
| `description` | string | Ne | Ano | Popis modulu |
| `kind` | string | Ne | Ne | Druh modulu: jediná povolená hodnota `"addon"` = doplněk; bez pole je modul jádro. Instalační modul (skupina `install`) pole mít nesmí. Jiná hodnota nebo typ je chyba načtení modulu (kapitola 9 → Doplňky) |
| `dependencies` | string[] | Ne | Ne | Seznam ID modulů, na kterých tento modul závisí |
| `tables` | string[] | Ne | Ne | Seznam ID tabulek (odpovídá názvům souborů v `tables/`) |
| `extensions` | object[] | Ne | Ne | Rozšíření tabulek jiných modulů |
| `extensions[].table` | string | Ano | Ne | ID cílové tabulky |
| `extensions[].file` | string | Ano | Ne | Relativní cesta k JSONC souboru s definicí rozšíření |
| `config` | object[] | Ne | Ne | Konfigurační položky modulu |
| `config[].id` | string | Ano | Ne | Globální identifikátor konfigurace |
| `config[].file` | string | Ano | Ne | Relativní cesta ke konfiguračnímu JSONC souboru |
| `keepOnReset` | string[] | Ne | Ne | Vlastní tabulky modulu, které `shpd-ds ds-reset` nesmaže |
| `clearSettingsOnReset` | object[] | Ne | Ne | Nastavení v `core_system_settings` s id řádku tabulky, které `ds-reset` smaže spolu s ní (viz níže) |
| `clearSettingsOnReset[].key` | string | Ano | Ne | Klíč nastavení (vlastní klíč modulu) |
| `clearSettingsOnReset[].table` | string | Ano | Ne | Tabulka, jejíž id řádku klíč nese (smí být cizí) |
| `settingsItems` | object[] | Ne | Ne | Položky v navigaci režimu Nastavení (viz níže) |
| `navSections` | object[] | Ne | Ano (`name`) | Vlastní sekce hlavního sidebaru (#170 D13): `id` (`[a-z][a-zA-Z0-9]*`), `name`, `order` (int) povinné, `icon` volitelný. `ConfigCompiler` je připojí k sekcím instalačního modulu v cfgItem `global.navSections`; shodné `id` je chyba kompilace (viz Sekce hlavního sidebaru) |
| `settingsSections` | object[] | Ne | Ano (`name`) | Vlastní sekce Nastavení (#170 D13): tvar jako `navSections`, navíc volitelné `subsections` (`id` ve tvaru `<id sekce>.<název>`, `name`, `order`). Připojí se k cfgItem `global.settingsSections` ([app-settings.md](app-settings.md)) |
| `settingsPages` | object[] | Ne | Ano | Server-driven stránky vlastností v Nastavení (viz níže) |
| `documentEventHandlers` | object[] | Ne | Ne | Hooky na události dokumentů cizích tabulek (viz níže) |
| `documentEventHandlers[].table` | string | Ano | Ne | ID cílové tabulky |
| `documentEventHandlers[].class` | string | Ano | Ne | FQCN handleru (implements `DocumentEventHandler`) |
| `documentEventHandlers[].events` | string[] | Ne | Ne | `beforeSave`, `afterSave`, `stateChanged`, `beforeDelete`; default všechny |
| `attachmentGuards` | object[] | Ne | Ne | Ochrana příloh záznamu před změnou (viz níže) |
| `attachmentGuards[].table` | string | Ano | Ne | ID cílové tabulky |
| `attachmentGuards[].class` | string | Ano | Ne | FQCN guardu (implements `AttachmentGuard`) |
| `documentLockProviders` | object[] | Ne | Ne | Zámek záznamů cizí tabulky — providery důvodů (viz níže) |
| `documentLockProviders[].table` | string | Ano | Ne | ID cílové tabulky |
| `documentLockProviders[].class` | string | Ano | Ne | FQCN providera (implements `DocumentLockProvider`) |
| `journalEventHandlers` | object[] | Ne | Ne | Hooky na (pře)zápis účetního deníku (viz níže) |
| `journalEventHandlers[].class` | string | Ano | Ne | FQCN handleru (implements `JournalEventHandler`) |
| `journalEventHandlers[].events` | string[] | Ne | Ne | `journalWritten`; default všechny |
| `openItemLookup` | string | Ne | Ne | FQCN poskytovatele dohledání otevřeného předpisu (implements `OpenItemLookup`); jeden per DS (viz níže) |
| `journalContributors` | string[] | Ne | Ne | FQCN contributorů deníku (implements `JournalContributor`) — příspěvky do deníku zdroje před zápisem; víc modulů smí přispívat (viz níže) |
| `journalDimensions` | object[] | Ne | Ano (`name`) | Analytické dimenze deníku: `id`, `rowColumn`, `journalColumn`, `table`, `name` povinné, `headColumn`, `rowFlag`, `forms` volitelné (přirozený klíč pro výměnný formát říká `exchangeKey` v definici cílové tabulky, ne dimenze — #166 D8). Standardní dimenze jádra (#110 D20): sloupce leží v definicích `docs_core_heads`, `docs_core_rows` a `economy_accounting_journal`, deklaraci nese `economy.accounting`; `ConfigCompiler` je skládá do cfgItem `core.accounting.journalDimensions` (`docs/accounting.md` §6 Dimenze deníku) |
| `sendPurposes` | object[] | Ne | Ano (`name`) | Účely odesílání: `id`, `name` povinné, `order` volitelné (default 1000). `ConfigCompiler` je skládá do cfgItem `base.persons.sendPurposes` (viz níže) |
| `recordSenderProviders` | object[] | Ne | Ne | Odesílatel podle záznamu (#90 D39): `{table, class}`, třída implementuje `RecordSenderProvider`. Jeden poskytovatel na tabulku — druhá registrace téže tabulky je chyba. Čte `RecordSenderProviderLoader`, ptá se `SenderResolver` (`docs/mail/outbound.md`) |
| `workOrderInvoiceContributors` | object[] | Ne | Ano (`name`) | Přispěvatelé obsahu faktury periodické zakázky (#110 D7, D10): `id`, `class`, `name` povinné. `ConfigCompiler` je skládá do cfgItem `economy.workOrders.invoiceContributors` (id → název, třída); instance staví `InvoiceContributorRegistry::fromConfig` (viz níže) |
| `overviewBlocks` | object[] | Ne | Ne | Bloky Přehledu firmy (#120): `id`, `section`, `class` povinné, `order` volitelné (default 1000). `ConfigCompiler` je skládá do cfgItem `core.overview.blocks` (seřazeno `[order, id]`); instance staví `OverviewBlockRegistry::fromConfig` (viz níže, `docs/overview.md`) |
| `aiFunctions` | object[] | Ne | Ano (`name`, `description`) | AI funkce modulu (#85 D36): `id` (`oblast.nazev`), `name`, `tier` (`fast` / `balanced` / `strong`), `defaults.maxTokens` povinné; `defaults.temperature`, `requires` (`tools`, `pdf`), `description` volitelné. `ConfigCompiler` je skládá do cfgItem `core.ai.functions`; čte `AiFunctionRegistry`, volání skládá `AiFunctionResolver` (viz níže, `docs/ai.md` §5.3) |
| `commands` | string[] | Ne | Ne | Příkazy `shpd-ds`, které patří jen tomuto modulu (#172 D2): FQCN tříd odvozených od `Symfony\Component\Console\Command\Command` v namespace `Shipard\Module\<Skupina>\<Modul>\Command\` (viz níže) |
| `serverCommands` | string[] | Ne | Ne | Příkazy `shpd-server` modulu — stejný tvar jako `commands` (viz níže) |
| `cronJobs` | object[] | Ne | Ne | Úlohy cronu modulu (#172 D6, viz níže) |
| `cronJobs[].command` | string | Ano | Ne | Jméno příkazu z `commands` téhož modulu, případně s volbami oddělenými mezerou (`mail-preprocess --sweep`) |
| `cronJobs[].slot` | string | Ano | Ne | Slot cronu: `minute`, `two-minutes`, `five-minutes`, `daily`, `weekly` (`CronProvisioner::SLOTS`) |
| `cronJobs[].order` | int | Ne | Ne | Pořadí ve slotu, výchozí 500; řadí se `order` vzestupně, pak id modulu, pak pořadí deklarace |
| `cronJobs[].states` | string[] | Ne | Ne | Lifecycle stavy zdroje dat (`active`, `read_only`, `suspended`, `pending_deletion`), ve kterých úloha běží; výchozí `["active"]` |
| `serverConfig` | object[] | Ne | Ne | Sekce `/etc/shipard/server.json`, které čte jen kód tohoto modulu (#172 D7, D10, viz níže) |
| `serverConfig[].key` | string | Ano | Ne | Cesta sekce — segmenty `[a-z][a-zA-Z0-9]*` oddělené tečkou (`exchangeRates`, `ai.analysis`) |
| `serverConfig[].class` | string | Ano | Ne | FQCN třídy v namespace modulu, která sekci načte a zkontroluje (implements `ServerConfigSection`) |
| `detailExtensions` | object[] | Ne | Ne | Taby a štítky detailu záznamu cizí tabulky (#172 D16, viz níže): `{table, class, order?}`, třída implementuje `Shipard\Core\Viewer\DetailExtension` |
| `formTabs` | object[] | Ne | Ne | Záložka formuláře cizí tabulky (#172 D17, viz níže): `{table, class, order?}`, třída dědí `Shipard\Core\Form\ModuleFormTab` |
| `rowDecorators` | object[] | Ne | Ne | Štítky řádku seznamu cizí tabulky (#172 D18, viz níže): `{table, class, order?}`, třída implementuje `Shipard\Core\Viewer\RowDecorator` |

### Pole `commands`, `serverCommands` a `cronJobs`

Co patří jen modulu, žije v modulu (#172 D1): příkaz CLI i úloha cronu se
deklarují v `module.jsonc`, třídy leží v `modules/<skupina>/<modul>/src/Command/`.
Jádro nese mechanismus (registraci, dispatcher) a příkazy společné všem
zdrojům dat.

```jsonc
"commands": [
    "Shipard\\Module\\Economy\\Vat\\Command\\VatPeriodsEnsureCommand"
],
"serverCommands": [],
"cronJobs": [
    {"command": "vat-periods-ensure", "slot": "daily"}
    // volitelně: "order": 500 (výchozí), "states": ["active"] (výchozí)
]
```

- **`commands`** — příkazy `shpd-ds`. Třída je `Command` z Symfony Console,
  jde vytvořit bez argumentů a leží v namespace
  `Shipard\Module\<Skupina>\<Modul>\Command\`.
- **`serverCommands`** — příkazy `shpd-server`, stejná pravidla.
- **`cronJobs`** — `command` je jméno příkazu z `commands` **téhož** modulu,
  volby se oddělují mezerou; `slot` je jeden ze slotů cronu; `order` určuje
  pořadí ve slotu (úloha, která má běžet až po ostatních, dostane vyšší
  číslo); `states` říká, ve kterých stavech zdroje dat úloha běží — bez pole
  jen v `active` (fail-closed, `docs/ds-state.md`).

Tvar deklarace hlídá `ModuleDefinition::fromArray()` — špatný typ, neznámý
slot, neznámý stav, `order` jiného typu než celé číslo, prázdný `command`
nebo neznámý klíč úlohy je chyba při načtení modulu. Třídy se při načtení
nenačítají. Co potřebuje vidět třídy nebo víc modulů najednou, hlídá
`ModuleCommandRules` testem nad celým stromem `modules/`:

- každá třída existuje, je `Command` a leží v namespace svého modulu;
- jména příkazů (i aliasy) jsou unikátní v rámci binárky — mezi moduly
  navzájem i proti příkazům jádra;
- první slovo `cronJobs[].command` je příkaz z `commands` téhož modulu
  a tentýž řetězec příkazu není ve slotu dvakrát.

### Pole `serverConfig`

Sekce `/etc/shipard/server.json` patří tam, kde žije kód, který ji čte
(#172 D7, D10). Co čte jádro (databáze, režim, logy, `mail`, `render`,
`devAi`, `hosting`), má typovaný getter v `ServerConfig`. Co čte jen modul,
modul deklaruje a načte si sám — `ServerConfig` mu dává jen surová data.

```jsonc
"serverConfig": [
    {
        "key": "exchangeRates",
        "class": "Shipard\\Module\\Economy\\ExchangeRates\\ExchangeRatesServerConfig"
    }
]
```

- **`key`** — cesta sekce v `server.json`: segmenty `[a-z][a-zA-Z0-9]*`
  oddělené tečkou. Jednosegmentová cesta je klíč nejvyšší úrovně
  (`exchangeRates`), vícesegmentová vnořený objekt (`ai.analysis` =
  `{"ai": {"analysis": {…}}}`). Modul smí deklarovat víc sekcí.
- **`class`** — třída modulu, která implementuje
  `Shipard\Core\Config\ServerConfigSection`:

  ```php
  public static function key(): string;                                   // tatáž cesta jako v deklaraci
  public static function fromServerConfig(ServerConfig $config): static;  // načte a zkontroluje
  ```

  Sekci čte přes `ServerConfig::getSection(string $path): mixed` — surová
  hodnota na cestě, `null`, když cesta chybí (i když segment uprostřed
  není objekt). Typ hodnoty, výchozí stav a chybové hlášky jsou věc třídy;
  `ServerConfig` dostává celý, aby mohla číst i režim serveru. Chybná
  sekce vyhazuje `\RuntimeException` **při `fromServerConfig()`**, ne při
  `ServerConfig::load()` — chybná sekce jednoho modulu nesmí rozbít
  načtení konfigurace ostatním.

Kód modulu pak nevolá getter `ServerConfig`, ale třídu sekce:

```php
$url = ExchangeRatesServerConfig::fromServerConfig($serverConfig)->serviceUrl();
```

Tvar deklarace hlídá `ModuleDefinition::fromArray()` — špatný typ, neznámý
klíč položky, cesta mimo vzor nebo prázdná třída je chyba při načtení
modulu. Ostatní hlídá `ModuleServerConfigRules` testem nad celým stromem
`modules/`:

- třída existuje, implementuje `ServerConfigSection`, leží v namespace
  svého modulu (`Shipard\Module\<Skupina>\<Modul>\`, kdekoli pod ním)
  a její `key()` se shoduje s deklarací;
- žádné dvě deklarace nekolidují — stejná cesta, nebo jedna je prefixem
  druhé **po segmentech**: `ai` × `ai.analysis` kolidují, `ai.analysis`
  × `ai.chat` ne (sekci tak čte vždy právě jedna třída);
- první segment cesty není klíč, který čte jádro
  (`ServerConfig::CORE_KEYS` — `host`, `port`, `admin_user`,
  `admin_password`, `mode`, `domainsFile`, `dataSources`, `logFile`,
  `logLevel`, `extraModulesPath`, `mail`, `render`, `devAi`, `hosting`).

Neznámé klíče v `server.json` se tolerují: sekce neaktivního nebo
odebraného modulu načtení konfigurace nerozbije a nikdo ji nečte.

### Pole `detailExtensions`, `formTabs` a `rowDecorators`

Body rozšíření v UI tabulky, kterou modul nevlastní (#172 D16–D19). Modul
(typicky doplněk) jimi přispívá do detailu záznamu, do formuláře a do
seznamu, aniž by viewer nebo formulář jádra o modulu věděl — a bez změny
frontendu: všechno jde existujícími kontrakty.

```jsonc
"detailExtensions": [
    {"table": "docs_core_heads", "class": "Shipard\\Module\\Economy\\SalesRegistration\\DocDetailExtension"}
],
"formTabs": [
    {"table": "docs_core_heads", "class": "Shipard\\Module\\Economy\\SalesRegistration\\DocFormTab", "order": 500}
],
"rowDecorators": [
    {"table": "docs_core_heads", "class": "Shipard\\Module\\Economy\\SalesRegistration\\DocRowDecorator"}
]
```

| Pole | Co přidává | Třída |
|------|------------|-------|
| `detailExtensions` | taby (sdílené typy obsahu) a štítky hlavičky detailu, za taby a štítky vieweru | implementuje `Shipard\Core\Viewer\DetailExtension` — `tabs()`, `badges()` |
| `formTabs` | celou záložku se sloupci tabulky na konec formuláře; ukládá se běžným uložením | dědí `Shipard\Core\Form\ModuleFormTab` — `build()`, `null` = bez záložky |
| `rowDecorators` | spany na konec pole `t2` řádku seznamu (layout `list`) | implementuje `Shipard\Core\Viewer\RowDecorator` — `decorate()`, jedno volání na stránku řádků |

Položka je `{table, class, order?}`:

- **`table`** — tabulka, do které modul přispívá: vlastní, nebo tabulka
  modulu, na kterém modul (tranzitivně) závisí. Sloupce, které záložka
  formuláře edituje, tabulce přidá týž modul přes `extensions`.
- **`class`** — třída v namespace modulu
  (`Shipard\Module\<Skupina>\<Modul>\`, kdekoli pod ním). Vzniká **bez
  argumentů konstruktoru**; kontext dostává v parametru metody.
- **`order`** — celé číslo, výchozí 500. Pořadí poskytovatelů: `order`
  vzestupně, pak id modulu, pak pořadí deklarace.

**Kontext** je jeden objekt pro všechny tři druhy —
`Shipard\Core\Module\ModuleExtensionContext`: `table`, `tableDef` (definice
tabulky včetně sloupců z rozšíření), `db` (`DataSourceConnection`), `config`
(`ConfigRuntime`), `dsConfig` (`DataSourceConfig`) a `language`.

**Jen aktivní moduly.** Registrace se čtou z modulů zapnutých ve zdroji dat
— `ModuleUiExtensions::fromModules()` nad týmž `ModuleResolver::resolve`
jako viewery a formuláře (`ViewerLoader`, `FormLoader`). Rozšíření
vypnutého doplňku tak ve zdroji dat neexistuje.

**Izolace poskytovatele.** Výjimka z poskytovatele, chybějící třída, třída
bez správného rozhraní i výsledek mimo kontrakt se zaloguje
(`ErrorLogger::error` „Module UI extension failed“ s id modulu, třídou,
tabulkou a druhem rozšíření) a poskytovatel se přeskočí — detail, formulář
i seznam se vykreslí bez něj. Jediné místo izolace je
`ModuleUiExtensions::call()`.

**Polymorfní tabulky.** Registrace je per tabulka (`docs_core_heads` pro
všechny typy dokladů). Jestli se rozšíření záznamu týká — typ dokladu,
stav —, rozhoduje poskytovatel sám a vrátí prázdný výsledek (`[]`, u
záložky `null`).

Tvar deklarace hlídá `ModuleDefinition::fromArray()` — špatný typ, neznámý
klíč položky, chybějící tabulka nebo třída a `order` jiného typu než celé
číslo je chyba při načtení modulu. Ostatní hlídá `ModuleUiExtensionRules`
testem nad celým stromem `modules/`:

- třída existuje, implementuje rozhraní svého druhu, jde vytvořit bez
  argumentů konstruktoru a leží v namespace svého modulu;
- tabulka je vlastní tabulka modulu nebo tabulka modulu, na kterém modul
  tranzitivně závisí;
- tatáž třída není pro tabulku v jednom druhu registrovaná dvakrát.

### Pole `sendPurposes`

Účely odesílání (#90 D34) — k čemu slouží kontakt osoby
(`base_persons_contacts.send_purposes`) a co posílá tisk
(`prints[].sendPurpose`). Cfg položky se mezi moduly **neslučují** (cfgItem
se stejným id z pozdějšího modulu ten dřívější přepíše celý), proto má
rozšiřitelný seznam vlastní klíč — stejný vzor jako `journalDimensions`:

```jsonc
"sendPurposes": [
    { "id": "contracts", "name": "Contracts", "name:cs": "Smlouvy", "name:en": "Contracts", "order": 50 }
]
```

`ConfigCompiler` účely všech aktivních modulů složí do cfgItemu
**`base.persons.sendPurposes`** (klíč = `id`, pořadí podle `order`, pak
`id`). Základní čtyři účely deklaruje `base.persons`; další modul přidá
vlastní bez zásahu do něj. Stejné `id` ve dvou modulech zastaví kompilaci,
cfgItem `base.persons.sendPurposes` nejde deklarovat v `config`.
Po přidání účelu je potřeba `ds-upgrade`.

### Pole `aiFunctions`

AI funkce (#85 D36, `tasks/ai-functions.md`) — co modul volá na jazykovém
modelu. Deklarace nese **úroveň modelu**, ne model: který model úroveň
dnes znamená, říká mapa `core.ai.tiers` v repu; zdroj dat může u funkce
přepsat připojení, model i ladění v tabulce `core_ai_function_settings`
(řádek zakládá `ds-upgrade`). Stejný vzor jako `sendPurposes` — vlastní
klíč, protože cfg položky se mezi moduly neslučují:

```jsonc
"aiFunctions": [
    {
        "id": "mail.extraction",
        "name": "Incoming mail extraction",
        "name:cs": "Vytěžení došlé pošty",
        "name:en": "Incoming mail extraction",
        "description:cs": "Strukturované vytěžení dokladů z došlých zpráv…",
        "tier": "balanced",                  // fast | balanced | strong
        "defaults": { "maxTokens": 32768, "temperature": 0 },
        "requires": ["pdf"]                  // tools | pdf
    }
]
```

Validace v `ModuleDefinition`: `id` ve tvaru `oblast.nazev` (unikátní
v modulu), `tier` z trojice, `defaults.maxTokens` kladné celé číslo,
`defaults.temperature` číslo nebo nic (jiné klíče v `defaults` = chyba),
`requires` jen `tools` (tool smyčka chatu — popis pro detail funkce,
model podle něj resolver neodmítá; #85 D54) a `pdf`. `thinking` a `effort` deklarace nemá (výchozí = neposílat).
`ConfigCompiler` funkce všech aktivních modulů složí do cfgItemu
**`core.ai.functions`** (klíč = `id`, pořadí deklarací; stejné `id` ve dvou
modulech zastaví kompilaci, cfgItem nejde deklarovat v `config`). `core.ai`
na deklarujících modulech nezávisí — `AiFunctionRegistry::fromConfig()`
čte compiled config. Po přidání funkce je potřeba `ds-upgrade` (kompilace
+ založení řádku nastavení). Volající pak píše jen
`$resolver->resolve('mail.extraction', $override)->chatParams(...)` —
viz `docs/ai.md` §5.3–5.4.

### Pole `attachmentGuards`

Ochrana příloh záznamu před smazáním, přejmenováním, přeřazením a přidáním další — pro
záznamy, jejichž přílohy jsou vázané na nevratný stav. Registrace se čtou
za běhu (`AttachmentGuardLoader`, vzor `documentEventHandlers`), ptá se jich
`AttachmentService` a odmítnutí vrátí API jako 409 `ATTACHMENT_LOCKED`.

Guard se ptá i u **nahrání** nové přílohy (operace `upload`; `$attachment`
je v tu chvíli jen `table_id`, `record_id` a `name`). Většina guardů ho
pouští: k podanému tvrzení DPH je potřeba doložit potvrzení o přijetí,
k uzavřenému dokladu korespondenci. Blokuje se typicky jen změna toho, co
už tam je — a ne všeho, ale jen souborů, které si záznam vygeneroval sám
(`FilingAttachmentGuard` pozná svoje podle `metadata.kind`). Nahrání odmítá
jen záznam s pevným obsahem — odeslaná zpráva (`SentMessageAttachmentGuard`).

Guardy se načítají **jen v API**; CLI a seedery s přílohami pracují záměrně
bez omezení (migrace, úklid).

```jsonc
"attachmentGuards": [
    {
        "table": "economy_vat_filings",
        "class": "Shipard\\Module\\Economy\\Vat\\FilingAttachmentGuard"
    }
]
```


### Pole `documentLockProviders`

Zámek záznamů **cizí** tabulky (#55 D24): modul dodá provider důvodů, proč
záznam nejde uložit, změnit stav ani smazat, a jádro ho vynucuje ve všech
zápisových cestách (`TableGateway`, generické CRUD, nabídka přechodů,
přeúčtování) a posílá důvody do UI (banner, read-only formulář). Registrace
se čtou za běhu (`DocumentLoader` → `DocumentRegistry::getLockProviders()`),
instance staví `DocumentLockRegistry`. Vlastník tabulky na modulu
s providerem nezávisí — `docs.core` o DPH ani fiskálních měsících neví.

```jsonc
"documentLockProviders": [
    {
        "table": "docs_core_heads",
        "class": "Shipard\\Module\\Economy\\Vat\\VatPeriodLockProvider"
    }
]
```

Provider implementuje `Shipard\Core\Document\DocumentLockProvider`
(`lockReasons(string $tableId, array $data, ?array $original): list<DocumentLockReason>`),
typicky dědí z `AbstractDocumentLockProvider` (settery `db`/`config`/
`dsConfig`). Výjimka providera zápis **odmítne** (fail-closed). Import mód
(`Document::isLockExempt`) providery nevolá. Dnešní providery:
`economy.vat` → `VatPeriodLockProvider` (zamčená instance tvrzení),
`economy.codebooks` → `FiscalMonthLockProvider` (zamčený fiskální měsíc).
Detaily: `docs/document-system.md` sekce 16.

### Pole `workOrderInvoiceContributors`

Přispěvatelé obsahu faktury periodické zakázky (#110 D7, D10,
`docs/work-orders.md` §5.5): jiný modul doplní nebo upraví řádky faktury
za období podle podkladů (první případ: přefakturace spotřeby). Zapíná se
řádkem fakturačního předpisu zakázky s `contributor = id`; zakázka bez
takového řádku přispěvatele nevolá.

```jsonc
"workOrderInvoiceContributors": [
    {
        "id": "energy.consumption",
        "class": "Shipard\\Module\\Economy\\Energy\\ConsumptionContributor",
        "name": "Energy consumption",
        "name:cs": "Spotřeba energií"
    }
]
```

`ConfigCompiler` registrace aktivních modulů složí do cfgItem
`economy.workOrders.invoiceContributors` (klíč = id, `name` lokalizovaný,
`class`); duplicitní id přes moduly je chyba kompilace. Z cfgItem bere
formulář řádku předpisu nabídku pole *Přispěvatel obsahu* (bez registrace
se pole neukáže), `WorkOrderRowDocument` ověřuje id a
`InvoiceContributorRegistry::fromConfig` staví instance (neexistující
třída nebo třída bez rozhraní = `LogicException`). Třída implementuje
`Shipard\Module\Economy\WorkOrders\Invoicing\Contributor\InvoiceContributor`
(`id()`, `contribute(ContributionContext): ContributionResult` — *Ready*
s kanonickými řádky, které nahradí řádky přispěvatele, *Waiting* s důvodem,
*Failed* se zprávou), typicky dědí `AbstractInvoiceContributor` (settery
`db` / `config` / `dsConfig`). Běh (`InvoicingRunService`) přispěvatele
volá nad rozpracovaným kanonickým dokladem před apply; v produkci zatím
žádný registrovaný není.

### Pole `overviewBlocks`

Bloky obrazovky **Přehled firmy** (#120, `docs/overview.md`): modul dodá
jeden příspěvek do jedné sekce (`money`, `receivables`, `payables`, `vat`,
`result` — cfgItem `core.overview.sections` v `core.system`).

```jsonc
"overviewBlocks": [
    {
        "id": "economy.bank.balances",
        "section": "money",
        "order": 10,
        "class": "Shipard\\Module\\Economy\\Bank\\Overview\\BankBalancesBlock"
    }
]
```

`ConfigCompiler` registrace aktivních modulů složí do cfgItem
`core.overview.blocks` (klíč = id, hodnota `section`, `order`, `class`;
duplicitní id přes moduly i rezervovaný cfgItem = chyba kompilace).
`OverviewBlockRegistry::fromConfig` staví instance bezparametrickým
konstruktorem — neexistující třída nebo třída bez rozhraní se zaloguje
a přeskočí (DS před `ds-upgrade` nesmí dávat 500). Třída implementuje
`Shipard\Core\Overview\OverviewBlock` (`isAvailable(OverviewContext)`,
`collect(OverviewContext): OverviewBlockResult`); texty bere z katalogu
modulu `<modul>.overviewTexts` (`config/overviewTexts.jsonc`, čtení přes
`FeedTexts`). Sběr s izolací per blok dělá `OverviewCollector`
(`GET /_ui/overview`, jen admin).

### Pole `journalEventHandlers`, `openItemLookup` a `journalContributors`

Hooky účtování pro cizí moduly (saldokonto): účtovací enginy na modulu
`economy.accbal` nezávisí, ten se registruje sám.

```jsonc
"journalEventHandlers": [
    { "class": "Shipard\\Module\\Economy\\Accbal\\JournalLedgerHandler",       "events": ["journalWritten"] },
    { "class": "Shipard\\Module\\Economy\\Accbal\\ClearingRerouteHandler",     "events": ["journalWritten"] },
    { "class": "Shipard\\Module\\Economy\\Accbal\\CaseClosureRerouteHandler", "events": ["journalWritten"] }
],
"openItemLookup": "Shipard\\Module\\Economy\\Accbal\\LedgerOpenItemLookup",
"journalContributors": ["Shipard\\Module\\Economy\\Accbal\\CaseClosureContributor"]
```

- `journalEventHandlers` — registrace `{class, events}` **bez `table`**
  (událost `journalWritten(sourceKind, sourceId)` vysílají oba účtovací
  enginy po commitu (pře)zápisu i vymazání deníku zdroje). Čte se za běhu
  (`JournalEventHandlerLoader` → `JournalEventDispatcher`). **Pořadí je
  významové** — handlery se volají v pořadí resolvovaných modulů × pořadí
  pole, výjimka se zaloguje a spolkne; handler dědí z
  `AbstractJournalEventHandler` (settery `db`/`config`/`dsConfig` +
  `journalEvents` = dispatcher sám, pro handlery, které samy účtují).
- `openItemLookup` — holý FQCN, **jeden poskytovatel per DS** (dvě
  registrace = `LogicException` v `OpenItemLookupLoader`; bez registrace
  `NullOpenItemLookup`). Rozhraní `Shipard\Core\Accounting\OpenItemLookup`,
  báze `AbstractOpenItemLookup` (settery). Používá ho bankovní účtovací
  engine (účet úhrady = účet otevřeného předpisu, jinak clearing —
  `docs/bank.md` §6.1) a `ClearingRouter`; do document handlerů ho vkládá
  `DocumentEventHandlerLoader` automaticky. Detaily `docs/accounting.md`
  §7.1, `docs/accbal.md` rozhodnutí #19.
- `journalContributors` — seznam FQCN (#79 D3b), **víc modulů smí
  přispívat**, pořadí = pořadí resolvovaných modulů × pořadí pole;
  neexistující třída nebo třída bez rozhraní = `LogicException`
  v `JournalContributorLoader`, bez registrace prázdná
  `JournalContributorSet`. Rozhraní
  `Shipard\Core\Accounting\JournalContributor`, báze
  `AbstractJournalContributor` (settery). Volají ho **oba** účtovací
  enginy po sestavení řádků a před zápisem deníku — contributor vrátí
  požadavky na další řádky (kategorie předpisu nebo přesný účet), engine
  je doplní. Sadu injektují oba dispatchery (`setJournalContributors`)
  a loadery si ji při nepředání sestaví z modulů. Detaily
  `docs/accounting.md` §7.1, `docs/accbal.md` §5.8.

### Pole `documentEventHandlers`

Hook na události dokumentů **cizí** tabulky — modul reaguje na změny
dokumentů, aniž by vlastník tabulky na něm závisel (např. `economy.accounting`
účtuje `docs_core_heads`; později sklad, saldo). Registrace se čtou za běhu
(`DocumentEventHandlerLoader`, vzor `documentClasses`), dispatch dělá
`TableGateway` přes `DocumentEventDispatcher`.

Cílem nemusí být jen dokladová tabulka — handler lze registrovat i na
**číselník jiného modulu**: `docs.core` se hákuje na
`economy_codebooks_cash_desks` (`CashDeskSeriesEventHandler`, `afterSave`)
a po uložení pokladny do stavu 40 jí zakládá vázané číselné řady;
`economy.vat` stejně seeduje instance tvrzení po uložení registrace DPH.
Pozor na přechody stavu: bez `stateTransitionsRunDocumentHooks: true` na
tabulce jde změna stavu z UI přímým UPDATE a **žádný handler se nespustí** —
číselník, na který se hákuje kvůli stavu, musí mít flag zapnutý.

```jsonc
"documentEventHandlers": [
    {
        "table": "docs_core_heads",
        "class": "Shipard\\Module\\Economy\\Accounting\\DocsHeadsEventHandler",
        "events": ["stateChanged", "beforeDelete"]
    }
]
```

Sémantika událostí:

- **`beforeSave`** — uvnitř save transakce, po `Document::beforeSave()`
  a **před** zápisem hlavičky; child sety jsou v `$data` ještě přítomné
  a handler smí `$data` mutovat (dopočet sloupců hlavičky vlastněných
  cizím modulem — extension sloupce, např. `economy.vat` plní
  `vat_period`/`cs_period`/`rs_period` na `docs_core_heads`). Výjimka se
  **propaguje**, transakce se rollbackne a uložení selže.
- **`afterSave`** — po commitu a po `Document::afterSave()`, při **každém**
  uložení bez ohledu na změnu stavu (např. seed instancí tvrzení po
  uložení registrace DPH). Výjimka se zaloguje a **spolkne**; vedlejší
  efekty musí být idempotentní.
- **`stateChanged`** — po commitu uložení a po `afterSave`, jen pokud se
  `docState` reálně změnil. Přechod poskytuje `Document::getStateTransition()`
  (plní `trackStateChange`); pro nový záznam vzniklý rovnou mimo Koncept
  (import) je `old = 0`. Výjimka handleru se zaloguje a **spolkne** —
  uložení dokladu nesmí selhat kvůli hooku.
- **`beforeDelete`** — uvnitř delete transakce, před mazáním child tabulek
  (handler maže závislá data, např. řádky deníku). Výjimka se **propaguje**
  a transakce se rollbackne.

Handler dědí z `AbstractDocumentEventHandler` (settery `setDb`/`setConfig`/
`setDsConfig`, injektuje dispatcher). Víc handlerů na tabulku se volá
v pořadí registrace modulů. Pozn.: cesta `DocRowsDocument::recomputeHeader`
gateway obchází — z přepočtu řádků se eventy nevyvolávají (stav se tam nemění).

### Pole `keepOnReset`

Volitelné pole názvů **vlastních** tabulek modulu, které příkaz
`shpd-ds ds-reset` nesmaže (systémové/konfigurační tabulky vs. data).
Vše neuvedené reset dropuje a rekreuje. Položky musí být tabulky vlastněné
tímto modulem (uvedené v `tables`), jinak nahlášení modulu skončí fatální
chybou (záchyt překlepů a zákaz „chránit" cizí tabulku).

Funguje i pro third-party moduly z `extraModulesPath` — každý modul si sám
deklaruje, co je systém a co data.

```jsonc
// modules/core/system/module.jsonc — chráníme všechny systémové tabulky
"keepOnReset": [
    "core_system_users",
    "core_system_sessions",
    "core_system_settings",
    "core_system_api_keys",
    "core_system_rate_limits"
]

// modules/core/mail/module.jsonc — konfigurace (AI profily, SMTP sendery),
// ne data (outbox a log se při resetu mažou)
"keepOnReset": [
    "core_mail_ai_profiles",
    "core_mail_senders"
]
```

Viz [docs/cli.md](cli.md) → `ds-reset`.

### Pole `clearSettingsOnReset`

Volitelné pole objektů `{key, table}`: nastavení v `core_system_settings`
(tabulka je `keepOnReset`, reset ji nemaže), jehož hodnota je **id řádku**
tabulky `table`. `ds-reset` takový klíč smaže, **jen když tabulku `table`
skutečně dropuje** — řádek je pryč a id by po `ds-upgrade` ukázalo na
cokoli, co stejné id dostane příště (#131 D42: řada „Majetek“ se po resetu
nezaložila, protože provisioner vyplněné nastavení nepřepisuje, a klíč pak
mířil na pokladní řadu). Po smazání klíč znovu nastaví provisionery
`ds-upgrade`. S `--keep=<table>` klíč zůstává (id dál platí); na zdroji dat
bez `core_system_settings` se nic nemaže. `--dry-run` klíče vypíše jako
`[clear] <key> (id in <table>)`.

Deklaruje modul, který klíč **vlastní**; `table` smí patřit jinému modulu
(říká, kam id míří). Oba řetězce jsou povinné a neprázdné, jinak načtení
modulu skončí chybou.

```jsonc
// modules/economy/assets/module.jsonc — řada účetních dokladů majetku
"clearSettingsOnReset": [
    { "key": "economy.assets.accountingSeries", "table": "docs_core_number_series" }
]
```

Datové sady tytéž klíče nenesou (`SetupExporter::SETTINGS_EXCLUDED`,
[docs/datasets.md](datasets.md)); test parity hlídá, že konstanta odpovídá
deklaracím modulů.

### Pole `settingsItems`

Položky, které modul přidává do navigačního stromu režimu **Nastavení**.
Každá položka patří do sekce (definované v cfgItem `global.settingsSections`,
viz `modules/install/base/config/settingsSections.jsonc`) a odkazuje na
**právě jeden** cíl — `viewer`, `table`, nebo `page`:

```jsonc
"settingsItems": [
    { "viewer": "core.units", "section": "items" },
    { "table": "base_persons_contacts", "section": "other", "subsection": "other.persons", "order": 10 },
    { "page": "appSettings", "section": "app", "order": 10 }
]
```

| Pole | Typ | Povinné | Popis |
|------|-----|---------|-------|
| `viewer` / `table` / `page` | string | Právě jedno | ID vieweru, název tabulky, nebo ID settings page (z `settingsPages` téhož modulu) |
| `section` | string | Ano | ID sekce v Nastavení |
| `subsection` | string | Ne | ID podsekce |
| `order` | int | Ne | Pořadí v rámci sekce |
| `visibilityClass` | string | Ne | FQCN implementace `Shipard\Core\Navigation\NavItemVisibilityGate` — runtime podmínka viditelnosti odvozená z dat (protějšek `navigationProviders`, který položky přidává). Fail-open: bez DB, při chybějící třídě i při výjimce se položka zobrazí. První konzument: `VatAgendaNavGate` (agenda DPH u neplátce bez jediné registrace, `ds-setup.md` D11) |

Položky s vadnou strukturou (chybějící `section`, víc než jeden cíl) se
tolerantně přeskakují. Tabulky/viewery uvedené v `settingsItems` se
automaticky skrývají z hlavního navigačního stromu.

### Sekce hlavního sidebaru — `global.navSections` + `navSection`

Hlavní navigace (app mód) seskupuje viewery a tabulky do **sémantických
sekcí**, ne podle prefixu module ID. Mechanismus je paralelní k Nastavení:

- **cfgItem `global.navSections`**
  (`modules/install/base/config/navSections.jsonc`) definuje sekce sidebaru —
  `id`, `name`/`name:cs`/`name:en`, `icon`, `order`. Stejná struktura jako
  `global.settingsSections` (ploché sekce, bez podsekcí). Registruje se přes
  `config[]` v `module.jsonc` úplně stejně jako settingsSections; po přidání
  je nutný `vendor/bin/shpd-ds ds-upgrade`, aby se dostal do
  `compiled.<jazyk>.json`.
- **`navSection` + `navOrder` na vieweru** (`viewers[]`) — id sekce z
  navSections a pořadí v sekci (int). Tabulky bez vieweru (generický fallback
  item) je mohou nést v `tables/*.jsonc`.
- **Sentinel `navSection: "_top"`** = root-level leaf nad sekcemi (Došlá pošta,
  Úkoly). Řadí se dle `navOrder` společně se syntetickými Dashboard
  (`_order` 20) a Chat (`_order` 25) — položka s `navOrder` < 20 se zobrazí
  před Dashboardem (portál hostingu má 10).
- **Panel v hlavní navigaci:** panel z `panels[]` (viz
  [app-settings.md](app-settings.md) — „Panel — klientská komponenta
  v navigaci"), který deklaruje `navSection` (+ volitelně `navOrder`),
  se emituje i do hlavní navigace jako `{type: 'panel', panelId}`. Panel
  bez `navSection` zůstává jen v Nastavení/Účtu. Vzor: `hostingPortal`
  v `hosting.core` (`navSection: "_top"`, `navOrder: 10`).
- **`adminOnly` na deklaraci vieweru/panelu:** ne-adminovi se položka
  nevrací. Nezávisle na tom `NavigationController` ne-adminovi ořezává
  položky nad tabulkami s prefixem `core_system_` nebo s `adminOnly: true`
  v definici tabulky — tentýž zdroj pravdy, který na datech vynucuje
  `TableAccessGuard`. Navigace tak jen zrcadlí serverové bariéry.
  **Pozor na sémantiku vrstev:** viewer-level `adminOnly` je **úklid
  navigace, ne serverová bariéra** — data chrání jen table-level
  `adminOnly` / prefix `core_system_` (`TableAccessGuard`). Typický
  případ: viewer dosažitelný ne-adminům jinou cestou (dashboard karta
  s akcí `open_viewer`/`open_form`) — vzor `core.alerts.alerts`
  (task `hosting-07b`, D7): viewer `adminOnly`, tabulka bez.
- **Root leaf Chat** se emituje jen při aktivním `core.chat`
  (`core_chat_conversations` v runtime tabulkách) a ne-adminovi na DS
  s aktivním `hosting.core` se nevrací — identické s capability `chat`
  v `GET /_ui/dashboard` (viz [dashboard.md](dashboard.md)).
- **Fallback:** viewer/tabulka bez `navSection` (nebo s neznámou sekcí) padá do
  sekce `system`. Prázdné sekce se ve výstupu vynechají.
- **Vlastní sekce modulu** (#170 D13): položku do **existující** sekce modul
  přidá tak, že její `id` uvede v `navSection` — bez dalšího. **Novou** sekci
  deklaruje polem `navSections` ve svém `module.jsonc` (viz níže); potřebuje
  ji hlavně doplněk, který nemá kam dát svou agendu. Sekce instalačních
  modulů zůstávají v `config/navSections.jsonc`.

```jsonc
"viewers": [
    {
        "id": "docs.invoicesIn.heads",
        "name:cs": "Faktury přijaté", "name:en": "Received invoices",
        "icon": "invoice-in",
        "table": "docs_core_heads",
        "class": "…",
        "navSection": "purchase",   // sekce z global.navSections
        "navOrder": 10              // pořadí v sekci
    }
]
```

#### Vlastní sekce modulu — `navSections` a `settingsSections`

```jsonc
"navSections": [
    { "id": "facility", "name": "Facility", "name:cs": "Správa budovy",
      "name:en": "Facility", "icon": "company", "order": 37 }
],
"settingsSections": [
    { "id": "facility", "name": "Facility", "name:cs": "Správa budovy",
      "name:en": "Facility", "icon": "company", "order": 16 }
]
```

- Tvar položky je stejný jako v `navSections.jsonc` / `settingsSections.jsonc`
  instalačního modulu: `id` (`[a-z][a-zA-Z0-9]*`), `name` s jazykovými
  variantami a celočíselné `order` jsou povinné, `icon` volitelný. Sekce
  Nastavení smí nést `subsections` — podsekce má `id` ve tvaru
  `<id sekce>.<název>` (jako `other.system`), `name` a `order`. Chybný tvar
  nebo neznámý klíč je `InvalidArgumentException` při načtení modulu.
- `ConfigCompiler` sekce aktivních modulů **připojí** do `sections` cfgItemu
  `global.navSections`, resp. `global.settingsSections`, který dodal
  instalační modul — před lokalizací, takže `name:cs` se přeloží stejně jako
  u sekcí ze souboru. Pořadí v poli nehraje roli, oba controllery řadí podle
  `order`.
- **Shodné `id`** se sekcí instalačního modulu nebo se sekcí jiného modulu je
  chyba kompilace (`RuntimeException` se jmény obou modulů), ne tiché
  přepsání. Sekce deklarovaná ve zdroji dat, kde cfgItem žádný instalační
  modul nedodal, je také chyba — zdroj dat bez instalačního modulu
  nepodporujeme.
- Modul smí přidat jen **vlastní** sekci (případně s podsekcemi); podsekci do
  cizí sekce Nastavení nepřidává.
- Sekce bez položek se nevykreslí — prázdné sekce vynechává
  `NavigationController` i `SettingsController`.

**Viewer bez `table`:** klíč `table` je volitelný. Viewer bez tabulky
(přehled z konfigurace, vzor `core.ai.modelCatalog`) přepisuje
`TableViewer::loadRecord()`, nemá formulář ani zámky a v hlavní navigaci
se neobjeví — deklaruje se v `settingsItems`. Detaily `docs/frontend.md`
→ Registrace vieweru.

**`hideFromNavigation` na vieweru:** kromě tabulky (`tables/*.jsonc`) lze
`hideFromNavigation: true` deklarovat i na vieweru v `module.jsonc`. Skryje
**jen ten viewer**; sdílenou tabulku dál zobrazují ostatní viewery (vzor:
souhrnný `docs.core.heads` skrytý, ale Faktury přijaté/vydané nad sdílenou
`docs_core_heads` viditelné). Tabulka reprezentovaná jakýmkoli viewerem se
nikdy nevykreslí jako syrový fallback table item.

Seskupování provádí `NavigationController` (`src/Api/Controller/`); API tvar
odpovědi je shodný se starým prefix-groupingem, takže `Sidebar.svelte` se
nemění. Detaily viz [docs/frontend.md](frontend.md) — „Sidebar — dynamická
navigace ze serveru".

### Pole `settingsPages`

Server-driven stránky vlastností — třetí typ obsahu v Nastavení vedle
vieweru a tabulky. Hodnoty polí žijí v key-value tabulce
`core_system_settings` (`id` pole = klíč, tečkové namespacy). Stránka se
zapojuje do navigace přes `settingsItems` s `page`.

```jsonc
"settingsPages": [
    {
        "id": "appSettings",
        "name": "Application",
        "name:cs": "Aplikace",
        "icon": "settings",
        "fields": [
            {
                "id": "app.name",              // klíč v core_system_settings
                "type": "text",
                "name:cs": "Název zdroje dat", "name:en": "Data source name",
                "hint:cs": "Plný název — výchozí hodnota pochází z instalace.",
                "maxLength": 120
            },
            {
                "id": "app.icon",
                "type": "image",
                "slot": "icon",                // branding slot (viz app-settings.md)
                "name:cs": "Ikona aplikace", "name:en": "Application icon"
            }
        ]
    }
]
```

Typy polí (`text` jako default, `image`, `select`, `color`, `checkbox`,
`theme`, `shell`, `language`, `avatar`), mezinadpis bez hodnoty
(`section`) a jejich atributy popisuje
[docs/app-settings.md](app-settings.md) §6. `name`/`hint` jsou
vícejazyčné (`:cs`/`:en` suffixy, standardní fallback chain). Pole s
neznámým typem nebo bez `id` se tolerantně přeskakují. `image` pole
neukládá hodnotu přes stránku — odkazuje na branding slot s vlastním
upload endpointem.

Endpointy, ukládání a branding sloty: [docs/app-settings.md](app-settings.md).

### Instalační modul — příklad

```jsonc
{
    "id": "install.base",
    "name": "Base installation",
    "name:cs": "Základní instalace",
    "name:en": "Base installation",
    "description": "Base set of modules for system operation",
    "description:cs": "Základní sada modulů pro provoz systému",
    "description:en": "Base set of modules for system operation",
    "dependencies": [
        "core.system",
        "base.persons"
    ]
}
```

Instalační modul nemá vlastní tabulky ani konfigurace — slouží výhradně jako metabalík, který přes závislosti zajistí aktivaci potřebných modulů.

---

## 6. Závislosti

### Pravidla

- Závislosti jsou jednoduché — bez verzování (celý systém je jedno monorepo s jednou verzí)
- Modul deklaruje závislosti v poli `dependencies` v `module.jsonc`
- Závislosti jsou tranzitivní: pokud A závisí na B a B závisí na C, pak aktivace A aktivuje i B i C
- **Cirkulární závislosti jsou chyba** — systém je musí detekovat a odmítnout (ignorovat modul s cyklickou závislostí)

### Řešení závislostí — algoritmus

1. Načíst seznam aktivovaných modulů z konfigurace zdroje dat
2. Pro každý aktivovaný modul rekurzivně načíst závislosti
3. Detekovat cykly (DFS s detekcí back-edge)
4. Pokud je nalezen cyklus — zalogovat chybu, přeskočit problematický modul
5. Topologicky seřadit moduly (závislosti před závislými)
6. Výsledné pořadí se použije pro:
   - Vytváření tabulek (nejdříve tabulky modulů bez závislostí)
   - Aplikaci extensions (až po vytvoření cílových tabulek)
   - Kompilaci konfigurace

---

## 7. Tabulky

### Pojmenování tabulek

Formát: `{skupina}_{modul}_{tabulka}`

Příklady:
- `core_system_users`
- `core_system_sessions`
- `base_persons_contacts`
- `economy_docs_heads`
- `economy_docs_rows`

Pravidla:
- Malá písmena, podtržítka jako oddělovač
- Prefix vždy odpovídá modulu, který tabulku vlastní
- Název tabulky je globálně unikátní v rámci databáze

### Definiční soubor tabulky

Umístění: `modules/{skupina}/{modul}/tables/{id_tabulky}.jsonc`

Podrobný formát definice tabulky bude specifikován v samostatném dokumentu `docs/table-definitions.md`.

### Extensions — rozšíření tabulek

Extension je JSONC soubor, který přidává sloupce, indexy nebo cizí klíče do tabulky jiného modulu.

Umístění: `modules/{skupina}/{modul}/extensions/ext-{cilova-tabulka}.jsonc`

Extension může přidat:
- Nové sloupce
- Nové indexy
- Nové cizí klíče

Extension nemůže:
- Odebírat existující sloupce, indexy nebo klíče
- Měnit definici existujících sloupců (to může jen vlastník tabulky)

**Pořadí aplikace:**
1. Nejprve se sestaví základní definice všech tabulek z jejich vlastnících modulů
2. Poté se aplikují extensions v pořadí daném topologickým seřazením modulů (podle závislostí)
3. Modul s extension musí mít v `dependencies` uveden modul vlastnící cílovou tabulku

---

## 8. Konfigurace modulů

### Definiční soubory

Každý modul může definovat libovolný počet konfiguračních položek. Konfigurační soubor je JSONC s libovolnou datovou strukturou — sazby DPH, typy dokladů, definice virtuálních skupin atd. Obsahová pole mohou být vícejazyčná.

### ID konfigurace

ID konfigurace je globální identifikátor ve formátu tečkové notace. Nemusí odpovídat ID modulu — existují i globální/nesystémové konfigurace.

Příklady:
- `economy.docs.vatRates` — sazby DPH (patří modulu `economy.docs`)
- `economy.docs.docTypes` — typy dokladů
- `base.persons.virtualGroups` — virtuální skupiny osob
- `global.currencies` — příklad globální konfigurace

### Kompilace konfigurace

Všechny konfigurační soubory ze všech aktivních modulů se „zkompilují" — jeden soubor na jazyk:

**Umístění:**
```
/opt/shipard/data-sources/{ds-id}/config/configuration/compiled.cs.json
/opt/shipard/data-sources/{ds-id}/config/configuration/compiled.en.json
```

**Struktura kompilovaného souboru (compiled.cs.json):**

```json
{
    "_meta": {
        "compiled": "2026-03-14T10:30:00+01:00",
        "version": "0.1.0",
        "language": "cs",
        "modules": ["core.system", "base.persons", "economy.docs"]
    },
    "items": {
        "economy.docs.vatRates": {
            "rates": [
                {"id": "standard", "rate": 21, "name": "Základní sazba"},
                {"id": "reduced1", "rate": 12, "name": "Snížená sazba"}
            ]
        },
        "base.persons.virtualGroups": {
            "groups": [
                {"id": "suppliers", "name": "Dodavatelé"},
                {"id": "customers", "name": "Odběratelé"}
            ]
        }
    }
}
```

V kompilovaných souborech jsou vícejazyčné suffixy odstraněny — pole `name` vždy obsahuje hodnotu pro daný jazyk.

**Přístup v aplikaci:**

```php
$lang = $user->getLanguage(); // "cs"
$config = ConfigRuntime::load($dataSourcePath, $lang);
$vatRates = $config->cfgItem('economy.docs.vatRates');
// $vatRates['rates'][0]['name'] === "Základní sazba"
```

Soubor se načítá při startu aplikace jedním čtením z disku.

---

## 9. Aktivace modulů ve zdroji dat

### Konfigurace — `config/main.json`

Do konfigurace zdroje dat se přidá pole `modules` a `defaultLanguage`
(heslo k databázi v `main.json` není — žije v `secrets/db.password`,
viz [operations/secrets.md](operations/secrets.md) §5):

```json
{
    "id": "a3f2-b8c1-d4e7-f9a0",
    "name": "Naše firma s.r.o.",
    "database_name": "a3f2_b8c1_d4e7_f9a0",
    "database_user": "shpd_a3f2b8c1",
    "created": "2026-03-12T14:30:00+01:00",
    "defaultLanguage": "cs",
    "modules": [
        "install.base"
    ]
}
```

Pole `modules` obsahuje seznam přímo aktivovaných modulů. Tranzitivní závislosti se dořeší automaticky. Zdroj dat má v seznamu svůj instalační modul; další moduly (doplňky, privátní moduly) se přidávají příkazem `shpd-ds ds-module`, ne ruční úpravou souboru.

Pole `defaultLanguage` určuje jazyk, pokud informace o jazyce uživatele chybí (nepřihlášený uživatel, API volání bez jazykového kontextu).

### Deaktivace modulu

Při odebrání modulu z `modules`:
- Tabulky se **neodstraňují** automaticky
- Konfigurační položky modulu se odeberou z kompilovaných souborů při další kompilaci
- Časem bude existovat servisní chod pro výmaz nepotřebných tabulek

### Doplňky

**Doplněk** je modul, který není součástí `install.base` a ve zdroji dat se zapíná — správa budovy, správa počítačové sítě (#170). Většina firem ho nepoužije, ale je obecný a veřejný, takže patří do hlavního repozitáře.

**Umístění.** Doplněk leží v `modules/{skupina}/{modul}/` jako každý jiný modul, ve skupině podle své domény. Žádný podadresář `addons/` ani druhý kořen: ID je trvalé (tabulky, namespace, klíče konfigurace), zařazení ne — doplněk přejde do jádra a naopak změnou jednoho pole. Doplněk je vždy **jeden modul**.

**Deklarace.** Od jádra se doplněk liší jen polem v `module.jsonc`:

```jsonc
{
    "id": "facility.core",
    "name": "Facility management",
    "kind": "addon",
    "dependencies": ["core.system", "base.persons"]
}
```

`ModuleDefinition` vrací druh jako `ModuleKind` (`Core`, `Addon`, `Install` — instalační podle skupiny `install`). Pravidla mezi druhy drží `ModuleKindRules` a test je pouští nad celým stromem `modules/`:

- modul jádra nezávisí, ani tranzitivně, na doplňku;
- `install.base` nezávisí, ani tranzitivně, na doplňku — jiný instalační modul smí (dedikovaný zdroj dat doplněk přinese rovnou);
- každý modul je dosažitelný z některého instalačního modulu, nebo je doplněk, nebo závislost doplňku.

**Zapnutí a vypnutí** — `shpd-ds ds-module` v adresáři zdroje dat ([cli.md](cli.md)):

```bash
shpd-ds ds-module list [--json]        # přímé a aktivní moduly, dostupné doplňky
shpd-ds ds-module enable <id>          # jen doplněk nebo modul z dalšího kořene
shpd-ds ds-module disable <id>         # jen přímo zapnutý modul mimo install.*
```

Příkaz zapíše `modules` v `config/main.json` a ve stejném procesu spustí `ds-upgrade` — jinak by `main.json` tvrdil aktivní modul bez tabulek a dispatcher periodických úloh, který aktivní moduly čte z `main.json`, by pouštěl jeho úlohy. Vypnutí nechává tabulky modulu v databázi (viz Deaktivace modulu). Stránka Doplňky v Nastavení, kde doplněk zapne admin zdroje dat, je cílový stav — zatím jen CLI.

**Jádro o doplňku neví.** Doplněk se napojuje jen přes body rozšíření — `extensions`, `documentEventHandlers`, `overviewBlocks`, `workOrderInvoiceContributors`, položky do existujících sekcí přes `navSection`, vlastní sekce přes `navSections` / `settingsSections`, vlastní příkazy CLI přes `commands` / `serverCommands`, vlastní úlohy cronu přes `cronJobs`, vlastní sekci `server.json` přes `serverConfig` (#172) a další pole z kapitoly 5. Příkaz i úlohu doplňku tak vidí jen zdroj dat, který ho má zapnutý — factory CLI ani dispatcher cronu se kvůli doplňku nemění. Stejně tak `ServerConfig`: nastavení doplňku na úrovni serveru čte třída doplňku ze své sekce, jádro pro ni getter nemá. Chybějící bod rozšíření je **změna jádra s vlastní issue**, řešená obecně, ne na míru jednomu doplňku. Formulář jádra proto sloupce doplňku nejmenuje; do formuláře a detailu cizí tabulky se doplněk napojuje celou vlastní záložkou (mechanismus má vlastní issue).

**Dokumentace doplňku** žije u modulu: `README.md` podle [documentation.md](documentation.md) a specifikace v `modules/{skupina}/{modul}/docs/` (vzor `modules/core/mail/docs/`) — do kořenového `docs/` patří jen obecná pravidla. Uživatelská dokumentace zůstává v `help/`, zadání v `tasks/`. Přehled všech modulů po druzích je v generovaném rozcestníku [`modules/README.md`](../modules/README.md) (`php scripts/modules-index.php`, `--check` běží v pre-commit hooku).

**Co běží u zákazníka** — agent v jeho síti, sběr dat z čidel — není modul, ale samostatná komponenta podle [services.md](services.md).

---

## 10. Third-party moduly

Hlavní repozitář Shipardu obsahuje moduly v adresáři `modules/`. Pro některé situace ale potřebujeme moduly držet mimo hlavní repozitář:

- **Interní zákaznické moduly** — specifická logika pro konkrétního zákazníka, která nemá smysl pro veřejnost
- **Placené moduly** — moduly, které jsou zpoplatněné a nemohou být v public repozitáři
- **Experimenty a prototypy** — moduly, které se zatím nezveřejnily

Shipard takové moduly podporuje přes mechanismus **dalších rootů** (extra module roots). Modul z externího rootu se chová identicky jako modul z hlavního `modules/` — stejné `module.jsonc`, stejné tabulky, stejné PHP třídy, stejné závislosti.

### 10.1 Konfigurace — `extraModulesPath` v `server.json`

V serverové konfiguraci se přidá volitelné pole `extraModulesPath` se seznamem absolutních cest k dalším rootům:

```json
{
    "host": "127.0.0.1",
    "port": 3306,
    "admin_user": "root",
    "admin_password": "...",
    "mode": "production",
    "extraModulesPath": [
        "/opt/shipard/extra/acme-customer/modules",
        "/opt/shipard/extra/billing-pro/modules"
    ]
}
```

Pravidla:
- Pole je **volitelné** — bez něj se chová systém jako dřív (jen hlavní `modules/`)
- Cesty jsou **absolutní** a musí existovat (jinak start aplikace selže s chybou)
- **Pořadí v poli definuje pořadí načítání** — hlavní `modules/` je vždy první, extras pak v deklarovaném pořadí
- Změna `extraModulesPath` vyžaduje **restart aplikace / nový request** — není to live reload

### 10.2 Adresářová struktura externího rootu

Externí root má stejnou strukturu jako hlavní `modules/`:

```
/opt/shipard/extra/acme-customer/modules/
└── custacme/                        ← skupina
    └── reporting/                   ← modul
        ├── module.jsonc
        ├── tables/
        │   └── custacme_reporting_reports.jsonc
        ├── src/
        │   └── ReportDocument.php   ← Shipard\Module\Custacme\Reporting\ReportDocument
        └── forms/
            └── custacme_reporting_reports.jsonc
```

Skupiny v externím rootu mohou být úplně nové (`custacme`) nebo i existující (`economy`, pokud chce externí modul přidávat do oficiální skupiny). Jediná podmínka — ID modulu `{skupina}.{modul}` musí být globálně unikátní napříč všemi rooty.

### 10.3 Konvence pro ID modulu

Aby se předešlo kolizím mezi externími moduly, doporučujeme tyto prefixy ve skupině — **bez podtržítka**, skupina je jedno slovo malými písmeny (kapitola 4):

| Prefix | Účel | Příklad |
|--------|------|---------|
| `cust…` | Zákaznické (in-house) moduly | `custacme.reporting` |
| `ext…` | Placené moduly od vendorů | `extbillingpro.invoices` |

Konvence prefixů **není vynucena v kódu** — systém hlídá jen tvar jména (10.8) a kolize ID. Pokud dva externí rooty deklarují stejné ID modulu, `ds-upgrade` (nebo HTTP request) selže s konkrétní chybovou hláškou jmenující obě cesty:

```
Module 'custacme.reporting' found in multiple roots:
  '/opt/shipard/extra/acme-v1/modules/custacme/reporting'
  '/opt/shipard/extra/acme-v2/modules/custacme/reporting'
```

### 10.4 Rezervované rozsahy `tableId`

`tableId` musí být globálně unikátní napříč všemi moduly. Pro řízenou alokaci ve smíšeném prostředí (core + externí moduly) doporučujeme následující rozdělení:

| Rozsah | Použití |
|--------|---------|
| `1 – 9 999` | Core (oficiální Shipard moduly) |
| `10 000 – 19 999` | Custom (in-house zákaznické moduly) |
| `20 000 – 29 999` | Vendor (placené moduly od třetích stran) |
| `30 000 – 65 535` | Rezerva |

Rozsahy jsou **konvence, ne enforcement** — `ds-upgrade` neodmítne `tableId` z důvodu "špatného rozsahu". Slouží jako koordinační nástroj při paralelním vývoji a usnadňují alokaci přes `next-table-id --range`.

Pro alokaci v rámci rozsahu:

```bash
bin/shpd-server next-table-id --range=10000:10099
# 10000
```

Příkaz vrátí **první volný** `tableId` v daném rozsahu, ne `max + 1` — to umožňuje znovu použít ID po smazaných modulech a hospodárně využít rozsah.

Bez `--range` vrací příkaz historicky `max(použité) + 1` napříč všemi rooty.

### 10.5 PHP třídy v externím modulu

PHP třídy externích modulů se autoloadují **automaticky**. Konvence namespace → adresář:

- Namespace: `Shipard\Module\{Group}\{Module}\{ClassName}`
- Cesta: `{root}/{group}/{module}/src/{ClassName}.php`

Konverze:
- Group v namespace = první písmeno velké → adresář malými písmeny (`Custacme` → `custacme`)
- Module v namespace = PascalCase → adresář v camelCase (`Reporting` → `reporting`, `MyModule` → `myModule`)

Pravidlo: první písmeno na malé, zbytek beze změny.

Příklady:

| Namespace | Adresář |
|-----------|---------|
| `Shipard\Module\Base\Persons\PersonDocument` | `base/persons/src/PersonDocument.php` |
| `Shipard\Module\Docs\InvoicesOut\Foo` | `docs/invoicesOut/src/Foo.php` |
| `Shipard\Module\Custacme\Reporting\ReportDocument` | `custacme/reporting/src/ReportDocument.php` |

> ⚠️ **Skupina je jedno slovo malými písmeny.** Jméno skupiny nesmí obsahovat podtržítko ani velké písmeno (`[a-z][a-z0-9]*`) — `cust_acme` ani `custAcme` neprojde a adresář s `module.jsonc` pod takovým jménem je chyba při načtení modulů (10.8). Skupina `custacme` má namespace `Shipard\Module\Custacme\Reporting\…`: v namespace se z ní stane jen první písmeno velké. Víceslovné jméno v camelCase smí mít jen **modul** (`myModule` → `MyModule`).

`composer.json` **nemusí být upravován** — registrace tříd je dynamická přes `ModuleClassLoader`.

### 10.6 Frontend

Externí moduly jsou **backend-only**. Veškeré UI se renderuje přes server-driven UI mechanismus (viewers, forms, settings) definovaný v `module.jsonc` a JSONC souborech. Vlastní Svelte komponenty z externího modulu zatím podporované nejsou — frontend je SPA bundle build-ovaný z hlavního repozitáře.

Praktický důsledek: externí modul může definovat tabulky, viewer pro CRUD, edit formy přes JSONC, validační logiku v PHP Document třídách a vlastní endpointy přes Controller třídy. Ale nemůže přidat vlastní Svelte view.

### 10.7 Instalace externího modulu — postup

1. Naklonovat / zkopírovat externí modul na server, např. do `/opt/shipard/extra/acme-customer/modules/`
2. Přidat cestu do `server.json` pole `extraModulesPath`
3. Restartovat aplikaci (nebo počkat na další request — PHP nemá perzistentní stav)
4. Aktivovat modul v konkrétním DS: `shpd-ds ds-module enable <id>` v adresáři DS — zapíše ID do `modules` v `config/main.json` a spustí `ds-upgrade` (kapitola 9 → Doplňky)

Update modulu = `git pull` (nebo nahrání nové verze) v externím adresáři + `ds-upgrade` na všech DS, kde je modul aktivní.

### 10.8 Detekce kolizí a chybové stavy

| Situace | Chování |
|---------|---------|
| Cesta v `extraModulesPath` neexistuje | `RuntimeException` při startu — adminkovi se ukáže konkrétní hláška |
| Stejné ID modulu ve dvou rootech | `RuntimeException` při startu s oběma cestami |
| Adresář s `module.jsonc` a neplatným jménem skupiny nebo modulu (`cust_acme/reporting`, `core/Work_orders`) | `RuntimeException` při načtení modulů s cestou a očekávaným tvarem — stejná závažnost jako kolize ID; adresáře bez `module.jsonc` se ignorují |
| Stejné `tableId` ve dvou modulech | `SchemaValidator` selže během `ds-upgrade` se seznamem konfliktních souborů |
| Modul aktivovaný v DS, ale neexistující v žádném rootu | `ModuleResolver` zaloguje warning a modul přeskočí |
| PHP třída z modulu, který není v žádném rootu | Standardní PHP `Class not found` při použití |

### 10.9 Co Shipard zatím nedělá

Pro úplnost — tyto věci jsou mimo scope aktuální implementace a budou řešeny později:

- **Verzování modulů** — neexistuje `require: "extbillingpro.invoices: ^2.0"`. Externí moduly jsou prostě adresáře s aktuální verzí
- **Distribuce přes composer** — externí moduly nejsou composer balíky, instalují se ručně (případně jako git submoduly do separátního privátního repozitáře)
- **Sandbox / izolace PHP kódu** — PHP třídy z externích modulů běží ve stejném procesu se stejnými oprávněními
- **Frontend komponenty** — viz 10.6
- **Per-modul oprávnění** — kdo smí co v jakém modulu se zatím neřeší

---

## 11. CLI příkaz `shpd-ds ds-upgrade`

Příkaz se spouští z adresáře zdroje dat:

```bash
cd /opt/shipard/data-sources/{ds-id}
shpd-ds ds-upgrade
```

### Kroky

1. **Načtení konfigurace** — přečíst `config/main.json`, načíst seznam aktivovaných modulů
2. **Řešení závislostí** — rekurzivně načíst závislosti, detekovat cykly, topologicky seřadit
3. **Načtení definic tabulek** — pro každý modul načíst definiční soubory tabulek (JSONC)
4. **Aplikace extensions** — v pořadí závislostí aplikovat extensions na základní definice
5. **Kompilace konfigurace** — pro každý podporovaný jazyk vygenerovat `compiled.{lang}.json`
6. **Kontrola databáze:**
   - Pro každou tabulku v definici:
     - Pokud tabulka neexistuje → `CREATE TABLE`
     - Pokud tabulka existuje → porovnat sloupce:
       - Chybějící sloupec → `ALTER TABLE ADD COLUMN`
       - Změněný typ (bezpečná změna — prodloužení stringu, rozšíření int) → `ALTER TABLE MODIFY COLUMN`
       - Přebytečný sloupec → **ignorovat** (nechat)
   - Zkontrolovat indexy:
     - Chybějící index → `CREATE INDEX`
7. **Výstup** — zobrazit souhrn provedených změn

### Příklad výstupu

```
Shipard Data Source Upgrade v0.1.0
Data source: Naše firma s.r.o. (a3f2-b8c1-d4e7-f9a0)

Resolving modules...
  Active modules: 5 (install.base + 4 dependencies)
  Module order: core.system, base.persons, economy.docs, economy.contracts, install.base

Compiling configuration...
  Config items: 8
  Languages: cs, en, sk, de
  Written to: config/configuration/compiled.cs.json
  Written to: config/configuration/compiled.en.json
  Written to: config/configuration/compiled.sk.json
  Written to: config/configuration/compiled.de.json

Checking database...
  [CREATE] core_system_users
  [CREATE] core_system_sessions
  [CREATE] core_system_settings
  [OK]     base_persons_contacts
  [ALTER]  economy_docs_heads — added column: payment_method (varchar(50))
  [CREATE] economy_docs_rows

Upgrade complete. 3 tables created, 1 table altered, 1 table unchanged.
```

### Spouštění

- **Vývoj:** manuálně přes CLI
- **Produkce:** automaticky po deploy

---

## 12. Modul `core.system`

Základní modul, který je vždy aktivní (závislost `install.base` a všech dalších instalačních profilů).

### Tabulky

#### `core_system_users`

Uživatelé systému s přihlašovacími údaji.

| Sloupec | Účel |
|---------|------|
| `id` | Primární klíč |
| `login` | Přihlašovací jméno |
| `password_hash` | Hash hesla |
| `full_name` | Celé jméno uživatele |
| `email` | E-mail |
| `is_active` | Aktivní/neaktivní |
| `created` | Datum vytvoření |
| `modified` | Datum poslední změny |

#### `core_system_sessions`

Aktivní uživatelské relace.

| Sloupec | Účel |
|---------|------|
| `id` | Primární klíč |
| `user_id` | FK na users |
| `token` | Session token |
| `ip_address` | IP adresa |
| `created` | Datum vytvoření |
| `expires` | Datum expirace |

#### `core_system_settings`

Systémová nastavení zdroje dat (key-value).

| Sloupec | Účel |
|---------|------|
| `id` | Primární klíč |
| `key` | Klíč nastavení |
| `value` | Hodnota (JSON) |
| `modified` | Datum poslední změny |

---

## 13. Implementační plán pro Claude Code

### Prerekvizita

Tento dokument předpokládá existující kostru projektu z PRD v0.1 (CLI utility, server config, data source config).

### Fáze 1 — JSONC parser a i18n resolver

**Soubory:**
- `src/Core/Utils/JsoncParser.php` — parsování JSONC (strip komentářů, trailing čárek)
- `src/Core/I18n/LocalizedFieldResolver.php` — fallback logika pro vícejazyčná pole
- `src/Core/I18n/ConfigLocalizer.php` — rekurzivní rozbalení vícejazyčných polí pro daný jazyk

**Testy:**
- JSONC: komentáře (jednořádkové, víceřádkové), trailing čárky, komentáře uvnitř řetězců
- I18n: fallback chain (cs → en → holé), chybějící překlady, vnořené struktury

### Fáze 2 — Loader modulů

**Soubory:**
- `src/Core/Module/ModuleDefinition.php` — datová třída pro `module.jsonc`
- `src/Core/Module/ModuleLoader.php` — načtení `module.jsonc` ze souborového systému
- `src/Core/Module/ModuleResolver.php` — řešení závislostí, detekce cyklů, topologické řazení

**Testy:**
- Načtení validního `module.jsonc`
- Chybějící povinná pole
- Řešení závislostí — lineární řetěz, strom, diamantový pattern
- Detekce cyklických závislostí
- Topologické řazení

### Fáze 3 — Definice tabulek

**Soubory:**
- `src/Core/Database/TableDefinition.php` — datová třída pro definici tabulky
- `src/Core/Database/ExtensionLoader.php` — načtení a aplikace extensions
- `src/Core/Database/TableMerger.php` — sloučení základní definice s extensions

**Testy:**
- Načtení definice tabulky z JSONC
- Aplikace extension (přidání sloupce, indexu)
- Sloučení v správném pořadí

### Fáze 4 — Kompilace konfigurace

**Soubory:**
- `src/Core/Config/ConfigCompiler.php` — kompilace konfiguračních souborů, generování jazykových variant
- `src/Core/Config/ConfigRuntime.php` — runtime přístup přes `cfgItem()` s podporou jazyků

**Testy:**
- Kompilace z více modulů, více jazyků
- Správný fallback pro chybějící překlady
- Přístup přes `cfgItem()`
- Chybějící klíč

### Fáze 5 — Database upgrade

**Soubory:**
- `src/Core/Database/SchemaComparator.php` — porovnání definice vs. skutečný stav DB
- `src/Core/Database/SchemaMigrator.php` — generování a provádění ALTER/CREATE příkazů

**Testy:**
- CREATE TABLE z definice
- Detekce chybějícího sloupce
- Bezpečný ALTER (prodloužení stringu, rozšíření int)
- Ignorování přebytečných sloupců

### Fáze 6 — CLI příkaz `ds-upgrade`

**Soubory:**
- `src/Command/DataSource/DsUpgradeCommand.php`

**Testy:**
- End-to-end test s mockovanou DB

### Fáze 7 — Základní moduly

- Vytvořit `modules/core/system/module.jsonc` a definiční soubory tabulek
- Vytvořit `modules/install/base/module.jsonc`

---

## 14. Otevřené otázky

- [ ] Formát definice tabulky (JSONC) — bude specifikován v `docs/table-definitions.md`
- [ ] Formát extension JSONC
- [ ] Servisní chod pro výmaz nepotřebných tabulek
- [ ] Migrace dat při změně struktury tabulek
- [ ] Správa oprávnění (který uživatel smí co v kterém modulu)
- [ ] Přidávání dalších jazyků za běhu
- [ ] Detekce chybějících překladů (validační nástroj)
