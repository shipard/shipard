# Shipard — Modulový systém

## 1. Přehled

Shipard je rozdělen do modulů. Modul je logická jednotka, která zapouzdřuje databázové tabulky, konfigurační soubory a business logiku pro určitou oblast systému (evidence osob, fakturace, smluvní agenda atd.).

Moduly jsou organizovány do **skupin** (groups) pro přehlednost. Skupina je čistě organizační složka bez vlastních metadat.

Na jednom serveru běží stovky modulů. Každý zdroj dat (data source) má v konfiguraci seznam aktivovaných modulů. Existují speciální instalační moduly (např. `install.base`), které nemají vlastní tabulky, ale přes závislosti aktivují sadu potřebných modulů.

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
```

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
- Skupina i modul: malá písmena, bez diakritiky, `[a-z][a-z0-9]*`
- Vždy právě dvě úrovně (skupina.modul)
- ID modulu je globálně unikátní

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
| `dependencies` | string[] | Ne | Ne | Seznam ID modulů, na kterých tento modul závisí |
| `tables` | string[] | Ne | Ne | Seznam ID tabulek (odpovídá názvům souborů v `tables/`) |
| `extensions` | object[] | Ne | Ne | Rozšíření tabulek jiných modulů |
| `extensions[].table` | string | Ano | Ne | ID cílové tabulky |
| `extensions[].file` | string | Ano | Ne | Relativní cesta k JSONC souboru s definicí rozšíření |
| `config` | object[] | Ne | Ne | Konfigurační položky modulu |
| `config[].id` | string | Ano | Ne | Globální identifikátor konfigurace |
| `config[].file` | string | Ano | Ne | Relativní cesta ke konfiguračnímu JSONC souboru |
| `keepOnReset` | string[] | Ne | Ne | Vlastní tabulky modulu, které `shpd-ds ds-reset` nesmaže |
| `settingsItems` | object[] | Ne | Ne | Položky v navigaci režimu Nastavení (viz níže) |
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

### Pole `attachmentGuards`

Ochrana příloh záznamu před smazáním, přejmenováním a přeřazením — pro
záznamy, jejichž přílohy jsou vázané na nevratný stav. Registrace se čtou
za běhu (`AttachmentGuardLoader`, vzor `documentEventHandlers`), ptá se jich
`AttachmentService` a odmítnutí vrátí API jako 409 `ATTACHMENT_LOCKED`.

Guard **neblokuje nahrání** nové přílohy: k podanému tvrzení DPH je potřeba
doložit potvrzení o přijetí, k uzavřenému dokladu korespondenci. Blokuje se
jen změna toho, co už tam je — a typicky ne všeho, ale jen souborů, které
si záznam vygeneroval sám (`FilingAttachmentGuard` pozná svoje podle
`metadata.kind`).

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

### Pole `journalEventHandlers` a `openItemLookup`

Hooky účtování pro cizí moduly (saldokonto): účtovací enginy na modulu
`economy.accbal` nezávisí, ten se registruje sám.

```jsonc
"journalEventHandlers": [
    { "class": "Shipard\\Module\\Economy\\Accbal\\JournalLedgerHandler",   "events": ["journalWritten"] },
    { "class": "Shipard\\Module\\Economy\\Accbal\\ClearingRerouteHandler", "events": ["journalWritten"] }
],
"openItemLookup": "Shipard\\Module\\Economy\\Accbal\\LedgerOpenItemLookup"
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
  `compiled.{cs,en}.json`.
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

Podporované typy polí: `text` (default) a `image`. `name`/`hint` jsou
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

Do konfigurace zdroje dat se přidá pole `modules` a `defaultLanguage`:

```json
{
    "id": "a3f2-b8c1-d4e7-f9a0",
    "name": "Naše firma s.r.o.",
    "database": {
        "name": "a3f2_b8c1_d4e7_f9a0",
        "user": "shpd_a3f2b8c1",
        "password": "nahodne-generovane-heslo"
    },
    "created": "2026-03-12T14:30:00+01:00",
    "defaultLanguage": "cs",
    "modules": [
        "install.base"
    ]
}
```

Pole `modules` obsahuje seznam přímo aktivovaných modulů. Tranzitivní závislosti se dořeší automaticky.

Pole `defaultLanguage` určuje jazyk, pokud informace o jazyce uživatele chybí (nepřihlášený uživatel, API volání bez jazykového kontextu).

### Deaktivace modulu

Při odebrání modulu z `modules`:
- Tabulky se **neodstraňují** automaticky
- Konfigurační položky modulu se odeberou z kompilovaných souborů při další kompilaci
- Časem bude existovat servisní chod pro výmaz nepotřebných tabulek

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
└── cust_acme/                       ← skupina
    └── reporting/                   ← modul
        ├── module.jsonc
        ├── tables/
        │   └── cust_acme_reporting_reports.jsonc
        ├── src/
        │   └── ReportDocument.php   ← Shipard\Module\CustAcme\Reporting\ReportDocument
        └── forms/
            └── cust_acme_reporting_reports.jsonc
```

Skupiny v externím rootu mohou být úplně nové (`cust_acme`) nebo i existující (`economy`, pokud chce externí modul přidávat do oficiální skupiny). Jediná podmínka — ID modulu `{skupina}.{modul}` musí být globálně unikátní napříč všemi rooty.

### 10.3 Konvence pro ID modulu

Aby se předešlo kolizím mezi externími moduly, doporučujeme tyto prefixy ve skupině:

| Prefix | Účel | Příklad |
|--------|------|---------|
| `cust_*` | Zákaznické (in-house) moduly | `cust_acme.reporting` |
| `ext_*` | Placené moduly od vendorů | `ext_billingpro.invoices` |

Konvence **není vynucena v kódu** — systém pouze detekuje kolize ID. Pokud dva externí rooty deklarují stejné ID modulu, `ds-upgrade` (nebo HTTP request) selže s konkrétní chybovou hláškou jmenující obě cesty:

```
Module 'cust_acme.reporting' found in multiple roots:
  '/opt/shipard/extra/acme-v1/modules/cust_acme/reporting'
  '/opt/shipard/extra/acme-v2/modules/cust_acme/reporting'
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
- Group v namespace = PascalCase → adresář v lowercase (`CustAcme` → `cust_acme` — pozor, **lcfirst**, ne `strtolower`)
- Module v namespace = PascalCase → adresář v camelCase (`Reporting` → `reporting`, `MyModule` → `myModule`)

Pravidlo: první písmeno na malé, zbytek beze změny.

Příklady:

| Namespace | Adresář |
|-----------|---------|
| `Shipard\Module\Base\Persons\PersonDocument` | `base/persons/src/PersonDocument.php` |
| `Shipard\Module\Docs\InvoicesOut\Foo` | `docs/invoicesOut/src/Foo.php` |
| `Shipard\Module\CustAcme\Reporting\ReportDocument` | `custAcme/reporting/src/ReportDocument.php` |

> ⚠️ **Pozor na skupiny s podtržítkem v ID.** ID modulu `cust_acme.reporting` znamená skupina `cust_acme` a modul `reporting`. V namespace se ale skupina musí psát jako jeden PascalCase token: `CustAcme`. Tj. pokud máš podtržítkové ID, namespace odpovídá `Shipard\Module\CustAcme\Reporting\…` a adresář `cust_acme/reporting/`. Autoloader se řídí podle adresářového ID, takže funkčně to sedí — ale **konvence pojmenování PHP tříd v takových modulech vyžaduje pozornost**. Doporučujeme držet jednoduchá skupinová jména bez podtržítek tam, kde to jde.

`composer.json` **nemusí být upravován** — registrace tříd je dynamická přes `ModuleClassLoader`.

### 10.6 Frontend

Externí moduly jsou **backend-only**. Veškeré UI se renderuje přes server-driven UI mechanismus (viewers, forms, settings) definovaný v `module.jsonc` a JSONC souborech. Vlastní Svelte komponenty z externího modulu zatím podporované nejsou — frontend je SPA bundle build-ovaný z hlavního repozitáře.

Praktický důsledek: externí modul může definovat tabulky, viewer pro CRUD, edit formy přes JSONC, validační logiku v PHP Document třídách a vlastní endpointy přes Controller třídy. Ale nemůže přidat vlastní Svelte view.

### 10.7 Instalace externího modulu — postup

1. Naklonovat / zkopírovat externí modul na server, např. do `/opt/shipard/extra/acme-customer/modules/`
2. Přidat cestu do `server.json` pole `extraModulesPath`
3. Restartovat aplikaci (nebo počkat na další request — PHP nemá perzistentní stav)
4. Aktivovat modul v konkrétním DS přidáním jeho ID do `modules` v `config/main.json`
5. Spustit `shpd-ds ds-upgrade` v adresáři DS

Update modulu = `git pull` (nebo nahrání nové verze) v externím adresáři + `ds-upgrade` na všech DS, kde je modul aktivní.

### 10.8 Detekce kolizí a chybové stavy

| Situace | Chování |
|---------|---------|
| Cesta v `extraModulesPath` neexistuje | `RuntimeException` při startu — adminkovi se ukáže konkrétní hláška |
| Stejné ID modulu ve dvou rootech | `RuntimeException` při startu s oběma cestami |
| Stejné `tableId` ve dvou modulech | `SchemaValidator` selže během `ds-upgrade` se seznamem konfliktních souborů |
| Modul aktivovaný v DS, ale neexistující v žádném rootu | `ModuleResolver` zaloguje warning a modul přeskočí |
| PHP třída z modulu, který není v žádném rootu | Standardní PHP `Class not found` při použití |

### 10.9 Co Shipard zatím nedělá

Pro úplnost — tyto věci jsou mimo scope aktuální implementace a budou řešeny později:

- **Verzování modulů** — neexistuje `require: "ext_billingpro.invoices: ^2.0"`. Externí moduly jsou prostě adresáře s aktuální verzí
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
  Languages: cs, en
  Written to: config/configuration/compiled.{cs,en}.json

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
