# Doplňkové moduly — druh modulu, `ds-module`, rozcestník, sekce z modulů (#170)

**Stav:** naplánováno — design #170 D1–D13, upřesnění U1–U4 potvrzena

> Jednorázový task pro Claude Code (5 commitů). Design: issue #170.

## Kontext

Doplněk je modul, který není součástí `install.base` a v konkrétním zdroji
dat se zapne — správa budovy, správa počítačové sítě apod. (#170 D1). Leží
v `modules/{skupina}/{modul}/` jako každý jiný modul a od jádra se liší jen
deklarací `"kind": "addon"` v `module.jsonc` (D2–D4).

Dnes:

- přidat modul do existujícího zdroje dat jde jen ruční úpravou `modules`
  v `config/main.json` a `ds-upgrade`;
- o druhu modulu nic nevíme — přehled modulů neexistuje;
- `ModulePathResolver::scanRoot()` tiše přeskočí adresář s neplatným
  jménem skupiny nebo modulu. Dokumentace přitom doporučuje prefixy
  `cust_*` / `ext_*` (`docs/modules.md` §10.3), které kód kvůli podtržítku
  nikdy nenačte, a §10.5 si odporuje (`cust_acme/` v textu, `custAcme/`
  v tabulce). Platí kód; opraví se dokumentace a tiché přeskočení (D11).

Tento task zavádí druh modulu, CLI pro zapínání, test druhů, generovaný
rozcestník `modules/README.md`, vlastní sekce sidebaru a Nastavení
deklarované v `module.jsonc` (D13) a dokumentaci. Položky do existujících
sekcí doplněk přidává už dnes (`navSection` na vieweru nebo panelu);
chybí mu jen vlastní sekce — `global.navSections` i
`global.settingsSections` jsou jediná položka konfigurace v instalačním
modulu a `ConfigCompiler` při stejném ID položku přepíše. Ostatní body
rozšíření (záložky formuláře a detailu cizí tabulky — D12, filtr nápovědy)
sem nepatří — mají vlastní issue (D6).

## Před implementací přečti

- `docs/modules.md` §1, §4, §5 (tabulka polí), §6, §9, §10
- `docs/documentation.md`
- `src/Core/Module/` — `ModuleDefinition.php` (`fromArray`),
  `ModulePathResolver.php` (`scanRoot`), `ModuleClassLoader.php`,
  `ModuleResolver.php`, `ModuleLoader.php`, `InstallModuleRegistry.php`
- `src/Core/Server/DataSourceModules.php`, `MainConfigFile.php`,
  `MainConfigPatcher.php`; `src/Core/Config/DataSourceConfig.php`
  (`getModules`)
- `src/Command/DataSource/DsClassCommand.php` (vzor příkazu nad
  `main.json`), `DsUpgradeCommand.php`
- `scripts/help-index.py` a `.githooks/pre-commit` (vzor generovaného
  rozcestníku s `--check`)
- `docs/cli.md` § `shpd-ds` (vzor `ds-class`)
- `public/index.php` — `buildMcpToolRegistry` (registrace nástrojů
  `core.help`)
- `src/Core/Config/ConfigCompiler.php` — skládání `rawItems` (vzor
  `overviewBlocks`), `src/Api/Controller/NavigationController.php`,
  `SettingsController.php`; `modules/install/base/config/navSections.jsonc`
  a `settingsSections.jsonc`
- `docs/modules.md` § Sekce hlavního sidebaru, `docs/app-settings.md`
  (settings sekce)

## Co je potřeba udělat

### 1. Druh modulu v `module.jsonc` (D4)

- Volitelné pole `kind`, jediná povolená hodnota `"addon"`. Bez pole =
  jádro. Instalační modul (skupina `install`) pole mít nesmí.
- Jiná hodnota, jiný typ nebo `kind` na instalačním modulu →
  `InvalidArgumentException` ve `ModuleDefinition::fromArray`.
- `ModuleDefinition` vrací druh jako enum (`ModuleKind`: `Core`, `Addon`,
  `Install`) — instalační podle skupiny, doplněk podle pole.

### 2. Neplatná jména v kořeni modulů (D11)

`ModulePathResolver::scanRoot()`:

- podadresář skupiny s neplatným jménem, který obsahuje aspoň jeden
  podadresář s `module.jsonc`, a adresář modulu s neplatným jménem
  a `module.jsonc` → `RuntimeException` s cestou a očekávaným tvarem
  (skupina `[a-z][a-z0-9]*`, modul `[a-z][a-zA-Z0-9]*`) — stejná
  závažnost jako kolize ID;
- adresáře bez `module.jsonc` se dál ignorují (třeba `docs/` uvnitř
  modulu).

Hlavní `modules/` dnes nic takového nemá — ověř testem nad stromem
(krok 3).

### 3. Pravidla druhů a test (D4)

Pravidla jako čistá funkce v `src/Core/Module/` (např.
`ModuleKindRules::violations(list<ModuleDefinition>): list<string>`),
ať jdou testovat na syntetických definicích:

- modul jádra nezávisí, ani tranzitivně, na doplňku;
- `install.base` nezávisí, ani tranzitivně, na doplňku — jiný instalační
  modul smí (dedikovaný zdroj dat, D1);
- každý modul je dosažitelný z některého instalačního modulu, nebo je
  doplněk, nebo závislost doplňku.

Test nad skutečným stromem `modules/` dnes najde jediného sirotka:
**`core.help`**. Jeho nástroje registruje `public/index.php` pro každý
zdroj dat, ale v žádném zdroji dat není modul aktivní. Přidej ho do
závislostí `install.base` (U3).

### 4. `shpd-ds ds-module` (D5)

Spouští se v adresáři zdroje dat jako ostatní `shpd-ds` příkazy. Výstup
anglicky jako zbytek CLI. Bez interaktivního potvrzení.

**`list`** (výchozí), volitelně `--json`:

- přímé moduly z `main.json`,
- aktivní moduly celkem (rozvinuté `ModuleResolver`) s druhem,
- dostupné neaktivní doplňky (`kind: addon`),
- dostupné neaktivní moduly z dalších kořenů (`extraModulesPath`).

**`enable <id>`:**

- modul musí existovat v některém kořeni;
- povolen jen doplněk nebo modul z dalšího kořene (U2) — jádro
  a `install.*` odmítne s vysvětlením (jádro přichází s instalačním
  modulem);
- už aktivní (přímo i přes závislost) → `unchanged`, exit 0, `main.json`
  beze změny;
- před zápisem ověř, že nový seznam projde `ModuleResolver` — chybějící
  závislost nebo cyklus = chyba a nic se nezapíše;
- zápis přes `MainConfigFile::patch` + nová
  `MainConfigPatcher::withModules()`; pořadí stávajících položek zůstává,
  nový modul na konec;
- pak `ds-upgrade` ve stejném procesu (`Application::find('ds-upgrade')`,
  stejná verbosity) — U1. `--no-upgrade` ho vynechá a vypíše, co spustit.
  Když `ds-upgrade` selže: exit `FAILURE`, `main.json` zůstává změněný,
  hláška řekne, ať se chyba opraví a spustí `shpd-ds ds-upgrade`.

**`disable <id>`:**

- jen modul uvedený přímo v `modules` a mimo `install.*`; jinak chyba
  s vysvětlením (instalační modul se nevypíná, tranzitivně aktivní modul
  vypnout nejde);
- když modul po odebrání dál zůstane aktivní, protože ho potřebuje jiný
  aktivní modul → odmítnout a vypsat, které moduly ho potřebují;
- odebrat z `modules`, `ds-upgrade` jako u `enable`; výpis připomene, že
  tabulky modulu v databázi zůstávají (`docs/modules.md` §9).

### 5. Rozcestník `modules/README.md` (D7)

- `scripts/modules-index.php` (PHP kvůli JSONC — `JsoncParser`,
  `ModuleLoader`) přepíše blok mezi `<!-- MODULY:BEGIN — generováno
  scripts/modules-index.php, needituj ručně -->` a `<!-- MODULY:END -->`;
  `--check` jen ověří (exit 1 = liší se).
- Ruční úvod nad blokem: tři druhy modulů (D1) s odkazem na
  `docs/modules.md`, kde je dokumentace modulu (`docs/documentation.md`),
  jak se doplněk zapíná (`ds-module`). Pozor na záměnu: `modules/docs/` je
  skupina dokladů, ne dokumentace.
- Generovaný blok: oddíly Instalační moduly, Jádro (po skupinách),
  Doplňky (prázdný → „Zatím žádný doplněk.“). Řádek: ID (odkaz na
  `README.md` modulu, když existuje, jinak na `module.jsonc`), název
  a popis česky (`name:cs` / `description:cs`, fallback holé pole).
  Řazení podle ID.
- `.githooks/pre-commit`: `php scripts/modules-index.php --check` se
  stejným typem hlášky jako u `help-index.py`.

### 6. Pravidla ID a kapitola 10 (D11)

`docs/modules.md`:

- §4 Pravidla: skupina `[a-z][a-z0-9]*`, modul `[a-z][a-zA-Z0-9]*`
  (camelCase, např. `workOrders`) — dnes tvrdí pro oba první tvar;
- §10.2 příklad stromu, §10.3 prefixy `cust` / `ext` bez podtržítka
  (`custacme.reporting`, `extbillingpro.invoices`) včetně příkladu kolize,
  §10.5 tabulka a varovný blok (skupina je jedno slovo malými písmeny,
  namespace `Shipard\Module\Custacme\Reporting`);
- §10.8 nový řádek: adresář s `module.jsonc` a neplatným jménem →
  `RuntimeException` při načtení.

### 7. Dokumentace (D1–D10)

- `docs/modules.md` — §1 tři druhy modulů; §5 pole `kind` v tabulce polí;
  nová podkapitola v §9 **Doplňky**: co je doplněk, umístění a jeden modul
  (D2, D3), `kind` a pravidla (D4), `ds-module` (D5), jádro o doplňku neví
  (D6), kde je dokumentace (D7), co běží u zákazníka (D10). Čísla kapitol
  se nemění — odkazuje na ně `DEVELOPERS.md`.
- `docs/documentation.md` — modul smí mít `docs/` se specifikacemi;
  doplněk tam drží to, co by jinak bylo v kořenovém `docs/`; rozcestník
  `modules/README.md`.
- `docs/cli.md` § `shpd-ds` — `ds-module` (vzor `ds-class`).
- `DEVELOPERS.md` „Základ a moduly“ — třetí větev: obecné, ale jen pro
  některé firmy → doplněk v hlavním repu; odkaz na `modules/README.md`.
- `README.md` „Pro vývojáře“ — odkaz na přehled modulů a doplňků
  (`modules/README.md`).
- `CLAUDE.md` — „Kam patří nový dokument“: specifikace doplňku u modulu
  (`modules/<skupina>/<modul>/docs/`); „Modulový systém“: odrážka o druhu
  `kind: addon`, `ds-module` a D6; řádek `docs/modules.md` v tabulce
  doplnit o druhy modulů. Stručně.
- `docs/features.md` — oddíl **Doplňky** před „Vědomě mimo rozsah“
  (správa budovy, správa počítačové sítě — obojí `[ ]`); z řádku mimo
  rozsah odebrat „IT síť“ (D8).
- `docs/roadmap.md` — oddíl **Doplňky** před „Vědomě odložené“: pravidlo
  D9, první doplněk správa budovy (#170, číselník míst v jádru #169).
- `tasks/README.md` — oddíl „Moduly třetích stran“ přejmenovat na
  „Doplňky a moduly třetích stran“, upravit úvodní větu, přidat řádek
  tohoto tasku.

### 8. Vlastní sekce sidebaru a Nastavení z `module.jsonc` (D13)

Položky do **existujících** sekcí se nemění — viewer nebo panel uvede
`navSection` (Nastavení: `section` u `settingsItems`). Nově může modul
deklarovat **vlastní sekce**:

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

(příklad ilustrační — název doplňku se rozhodne v jeho issue)

- `ModuleDefinition` validuje obě pole: pole objektů, povinné `id`
  (`[a-z][a-zA-Z0-9]*`), `name` (vícejazyčné), `order` (int); `icon`
  volitelný. U `settingsSections` volitelně `subsections` ve stejném tvaru
  jako v `settingsSections.jsonc` (`SettingsController` je umí). Chyba →
  `InvalidArgumentException`.
- Podsekce do **cizí** sekce Nastavení modul nepřidává — jen vlastní
  sekce, případně s podsekcemi.
- `ConfigCompiler`: po načtení `config[]` všech modulů a **před
  lokalizací** připojí sekce modulů do `sections` cfgItemu
  `global.navSections`, resp. `global.settingsSections`.
  - `id` shodné se sekcí instalačního modulu nebo s jiným modulem →
    `RuntimeException` se jmény modulů (vzor `overviewBlocks`).
  - Modul sekce deklaruje, ale cfgItem žádný instalační modul nemá →
    `RuntimeException` (zdroj dat bez instalačního modulu nepodporujeme).
  - Pořadí v poli řešit netřeba — `NavigationController`
    a `SettingsController` řadí podle `order`.
- `NavigationController`, `SettingsController` ani frontend se nemění;
  prázdná sekce se dál vynechá.
- Dokumentace: `docs/modules.md` — tabulka polí v §5 a § Sekce hlavního
  sidebaru (vlastní sekce modulu); `docs/app-settings.md` u věty „Sekce
  musí existovat v `settingsSections.jsonc`“ doplnit sekci z modulu;
  odrážka `global.navSections` v `CLAUDE.md`.

## Mimo rozsah

- ostatní body rozšíření pro doplňky — záložky ve formuláři a detailu
  cizí tabulky (D12), filtr nápovědy podle aktivních modulů (vlastní
  issue, D6);
- převod sekcí instalačních modulů z `config/*.jsonc` na nová pole
  `module.jsonc` — instalační moduly zůstávají, jak jsou;
- stránka Doplňky v Nastavení, nabídka doplňků v portálu hostingu (D5);
- doplněk správy budovy sám;
- doplňky ve výpisu `ds-list` a `doctor`;
- servisní výmaz tabulek vypnutého modulu (`docs/modules.md` §14).

## Testy

- `ModuleDefinitionTest` — `kind: addon` projde; jiná hodnota, jiný typ
  a `kind` na instalačním modulu jsou chyba; druh `Install` podle skupiny.
- `ModulePathResolverTest` — neplatná skupina s modulem uvnitř → výjimka;
  neplatný modul s `module.jsonc` → výjimka; adresář bez `module.jsonc`
  se ignoruje.
- pravidla druhů na syntetických definicích — jádro závisí na doplňku
  (i tranzitivně), `install.base` tranzitivně na doplňku, sirotek,
  dedikovaný instalační modul s doplňkem projde, doplněk dosažitelný jen
  přes jiný doplněk projde; skutečný strom `modules/` bez porušení.
- `DsModuleCommandTest` (moduly z dočasného kořene) — `list` a `--json`;
  `enable` doplňku zapíše a spustí upgrade (šev); `enable` jádra
  a `install.*` odmítnuto; `enable` už aktivního = `unchanged`;
  chybějící závislost → nic se nezapíše; `disable` přímého; `disable`
  tranzitivně potřebného a `install.*` odmítnuto; `--no-upgrade`; selhání
  upgradu → `FAILURE`.
- `MainConfigPatcherTest` — `withModules()` zachová pořadí a ostatní
  klíče.
- `ModuleDefinitionTest` — `navSections` / `settingsSections`: platná
  položka projde; chybějící `id`, `name` nebo `order`, neplatné `id`,
  jiný typ jsou chyba.
- `ConfigCompiler` — sekce modulu se připojí k sekcím instalačního
  modulu a jsou lokalizované (`name:cs`); kolize `id` s instalačním
  modulem i mezi dvěma moduly je chyba se jmény modulů; chybějící cfgItem
  instalačního modulu je chyba; bez polí v modulech beze změny.

## Commity

1. `feat(modules): druh modulu kind: addon, neplatná jména v kořeni modulů jsou chyba, pravidla druhů (#170 D4, D11)`
   — kroky 1–3 a testy, `core.help` v `install.base`.
2. `feat(cli): shpd-ds ds-module list|enable|disable (#170 D5)` — krok 4.
3. `feat(modules): generovaný rozcestník modules/README.md (#170 D7)` —
   krok 5 včetně hooku.
4. `feat(modules): vlastní sekce sidebaru a Nastavení z module.jsonc (#170 D13)` —
   krok 8 a testy.
5. `docs: doplňkové moduly (#170 D1–D13)` — kroky 6–7 a dokumentace
   kroku 8, `**Stav:**`, `python3 scripts/tasks-index.py`.

## Ověření na vývojářském stroji

- `php scripts/modules-index.php --check` projde.
- Na zdroji dat s třídou `free` (`shpd-server ds-list`): `shpd-ds
  ds-module list`; `enable core.mail` a `disable install.base` skončí
  srozumitelnou chybou. `ds-upgrade` po commitu 1 (nový `core.help`) jen
  na zdroji `free` — ostatní zdroje dat ho dostanou běžným upgradem.

## Hotovo když

- [ ] `kind: addon` se validuje a `ModuleDefinition` vrací druh
- [ ] neplatné jméno modulu nebo skupiny s `module.jsonc` je chyba, ne
      tiché přeskočení
- [ ] pravidla druhů hlídá test; `core.help` je v `install.base`
- [ ] `shpd-ds ds-module list|enable|disable` podle kroku 4
- [ ] `modules/README.md` generuje skript, `--check` běží v pre-commit
      hooku
- [ ] modul může deklarovat vlastní sekce sidebaru a Nastavení
      (`navSections`, `settingsSections`), kolize `id` je chyba
- [ ] dokumentace podle kroků 6–8
- [ ] `php -l`, cílené testy, celá sada
- [ ] `**Stav:**` aktualizován, `python3 scripts/tasks-index.py`

## Rozhodnutí k designu (potvrzená)

Z issue #170:

- ✓ **D1** — tři druhy modulů: jádro, doplněk, privátní; o místě
  rozhoduje, komu kód patří
- ✓ **D2** — doplněk v `modules/{skupina}/{modul}/`, skupina podle domény,
  žádný podadresář ani druhý kořen
- ✓ **D3** — doplněk je vždy jeden modul
- ✓ **D4** — `"kind": "addon"` v `module.jsonc`; test pravidel druhů
- ✓ **D5** — zapínání zatím jen `shpd-ds ds-module`; cílově stránka
  v Nastavení pro admina zdroje dat
- ✓ **D6** — jádro o doplňku neví; chybějící bod rozšíření = změna jádra
  s vlastní issue
- ✓ **D7** — technická dokumentace u modulu, rozcestník
  `modules/README.md`, uživatelská v `help/` s `modules:`, tasky
  v `tasks/`
- ✓ **D8** — `features.md`: oddíl Doplňky, „IT síť“ pryč z mimo rozsah
- ✓ **D9** — doplňky jako samostatný oddíl roadmapy mimo milníky;
  kategorie 1 blokuje i je
- ✓ **D10** — co běží u zákazníka, je komponenta podle `docs/services.md`
- ✓ **D11** — u privátních modulů platí kód, opraví se dokumentace;
  neplatné jméno v kořeni je chyba
- ✓ **D12** — doplněk se do formuláře a detailu cizí tabulky napojuje
  celou vlastní záložkou (mechanismus mimo tento task)
- ✓ **D13** — položky do existujících sekcí přes `navSection` jako dnes;
  vlastní sekce přes pole `navSections` a `settingsSections`
  v `module.jsonc`, připojené k sekcím instalačního modulu; kolize `id`
  je chyba kompilace

Upřesnění tohoto tasku (v issue nejsou; potvrzeno 2026-11-24):

- ✓ **U1** — `enable` / `disable` spouští `ds-upgrade` ve stejném procesu.
  Jinak by `main.json` tvrdil aktivní modul bez tabulek a dispatcher
  cronu, který aktivní moduly čte z `main.json` (#115 D15), by pouštěl
  jeho úlohy. `--no-upgrade` pro dávkové použití.
- ✓ **U2** — `enable` přijme jen doplněk nebo modul z dalšího kořene;
  `disable` jen přímo zapnutý modul mimo `install.*`, který žádný jiný
  aktivní modul nepotřebuje.
- ✓ **U3** — `core.help` do závislostí `install.base`: jediný sirotek, jeho
  nástroje se registrují pro každý zdroj dat.
- ✓ **U4** — rozcestník generuje PHP skript (kvůli JSONC), `--check` běží
  v pre-commit hooku jako u `help-index.py`.
