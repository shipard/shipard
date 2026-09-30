# Majetek Fáze 2a — pravidla země a odpisový engine

**Stav:** hotovo

> PRD pro jednu Claude Code session (4 commity). Design: `docs/assets.md`
> §4 (D6, D7, D10, D16, D27–D45), issue #83. Navazuje `tasks/assets-phase2b.md`
> (události v DB, UI, odpisy za období) — ta tenhle engine jen volá.

## Kontext

Odpisy majetku se počítají ve dvou okruzích — **daňovém** (pravidla státu)
a **účetním** (metoda zvolená firmou). Starý Shipard měl výpočet v jedné
třídě se zadrátovanými sazbami a UI (`old_shipard:modules/e10pro/property/DepreciationsEngine.php`,
sazby v `old_shipard:modules/install/country-modules/debs/cz/config/property.json`).
Tahle fáze staví **čistý výpočet bez databáze a bez UI**:

1. modul `world.assets` s pravidly daňových odpisů per stát (zatím CZ),
2. `DepreciationPlanner` v `economy.assets` — z nastavení karty, událostí
   a účetních období spočítá plán odpisů obou okruhů.

Nic se neukládá, nic se neúčtuje. Fáze 2b napojí engine na tabulku událostí
a UI.

## Cíl

1. Modul `world.assets` (bez tabulek): `config/assets-cz.jsonc`, rozhraní
   `TaxDepreciationRules`, `CzTaxDepreciationRules`, fallback pro státy bez
   pravidel, registr podle země.
2. Hodnotové objekty vstupu a výstupu enginu v `economy.assets`.
3. `DepreciationPlanner` — daňový a účetní plán včetně počátečního stavu,
   TZ, snížení, přerušení, vyřazení (polovina), mimořádných a časových
   odpisů, zaokrouhlení a hlášení nesouladu.
4. Jednotkové testy na anonymizovaných scénářích (D40).

## Před implementací přečti

- `docs/assets.md` — §2.2 (starý engine a jeho chyby), §3.2 (podoby dat),
  §4 D6, D7, D10, D11, D16, D27–D45
- Vzor modulu pravidel per stát: `modules/world/vat/` (`module.jsonc`,
  `config/vat-cz.jsonc` s intervaly `from`/`to`, `src/VatRateResolver.php`,
  `README.md`)
- `modules/economy/assets/src/AssetCategories.php` (čtení cfgItem druhů,
  příznaky místo klíčů)
- `src/Core/Config/DataSourceConfig.php` — `getCountry()` / `hasCountry()`
- Starý engine pro srovnání (ne jako vzor struktury):
  `old_shipard:modules/e10pro/property/DepreciationsEngine.php`
  (`taxDeprecationValue`, `accDeprecationValue`, `createTaxDepsPlan`)

## Scope

**Uvnitř:** `world.assets` (config + PHP), hodnotové objekty, planner,
testy, `README.md` modulu `world.assets`, doplnění `docs/assets.md` §5
(engine).

**Mimo:** tabulka událostí, sloupce karty, formuláře, viewer, akce „Odpisy
za období“ (vše 2b); zaúčtování (fáze 3); odložená daň, skupina X,
účetní metody AV/AM (fáze 8); podklad pro DPPO (fáze 5).

## Modul `world.assets`

`modules/world/assets/module.jsonc` — `dependencies: ["world.base"]`,
config `{"id": "world.assets.cz", "file": "config/assets-cz.jsonc"}`.
`install.base` → `dependencies` + `world.assets`; `economy.assets`
→ `dependencies` + `world.assets`.

### `config/assets-cz.jsonc`

Struktura (hodnoty podle ZDP k 8/2025; historické sazby převzít ze
starého `property.json` → `amortizationPeriod`, platnost `from`/`to` =
**datum prvního zařazení** majetku, D43):

```jsonc
{
    "rounding": {"mode": "ceil", "precision": 0},          // D10, D36
    "halfYearOnDisposal": ["straight", "accelerated"],     // D35
    "interruptible": ["straight", "accelerated"],          // D34
    "intangibleTaxFrom": "2021-01-01",                     // NIM od 2021: jen accounting/none

    "methods": {
        "straight":      {"name:cs": "Rovnoměrný", "kind": "annual"},
        "accelerated":   {"name:cs": "Zrychlený", "kind": "annual"},
        "extraordinary": {"name:cs": "Mimořádný (§30a)", "kind": "schedule"},
        "time":          {"name:cs": "Časový (nehmotný do 2020)", "kind": "time"},
        "accounting":    {"name:cs": "Podle účetních odpisů", "kind": "accounting"},
        "none":          {"name:cs": "Neodepisuje se daňově", "kind": "none"}
    },

    // Skupiny pro straight/accelerated. Varianty +10/15/20 % (D42) jsou
    // samostatné kódy se stejnou skupinou a vlastními sazbami.
    "groups": {
        "cz-1": {"name:cs": "Odpisová skupina 1", "years": 3, "taxReturnGroup": 1},
        "cz-1-b10": {"name:cs": "Odp. sk. 1 (1. rok +10 %)", "group": "cz-1", "methods": ["straight"]},
        // … cz-1-b15, cz-1-b20, cz-2 … cz-6, varianty skupin 2 a 3
    },

    "straightRates": [
        {"code": "cz-1", "from": "2005-01-01", "to": null, "first": 20.0, "next": 40.0, "increased": 33.3},
        // … historické intervaly ze starého cfg (cr1/crn/cri)
    ],
    "acceleratedCoefficients": [
        {"code": "cz-1", "from": "2005-01-01", "to": null, "first": 3, "next": 4, "increased": 3},
        // … (cz1/czn/czi)
    ],

    // Časové odpisy nehmotného majetku pořízeného do 31. 12. 2020 (§32a,
    // ve starém AI1–AI5). monthsIncreased = doba po technickém zhodnocení.
    "timeRules": {
        "cz-nim-software": {"name:cs": "NIM — software", "months": 36, "monthsIncreased": 18, "from": null, "to": "2020-12-31"},
        "cz-nim-av":       {"months": 18, "monthsIncreased": 9,  "to": "2020-12-31"},
        "cz-nim-rd":       {"months": 36, "monthsIncreased": 18, "to": "2020-12-31"},
        "cz-nim-setup":    {"months": 60, "monthsIncreased": 0,  "to": "2020-12-31"},
        "cz-nim-other":    {"months": 72, "monthsIncreased": 36, "to": "2020-12-31"}
    },

    // Mimořádné odpisy §30a (D41): měsíční rozpis od měsíce po zařazení.
    "extraordinaryRules": {
        "cz-30a-1":  {"name:cs": "Mimořádné — skupina 1", "groups": ["cz-1"], "from": "2020-01-01", "to": "2023-12-31",
                      "schedule": [{"months": 12, "pct": 100}]},
        "cz-30a-2":  {"name:cs": "Mimořádné — skupina 2", "groups": ["cz-2"], "from": "2020-01-01", "to": "2023-12-31",
                      "schedule": [{"months": 12, "pct": 60}, {"months": 12, "pct": 40}]},
        "cz-30a-ev": {"name:cs": "Mimořádné — bezemisní vozidlo", "from": "2024-01-01", "to": "2028-12-31",
                      "schedule": [{"months": 12, "pct": 60}, {"months": 12, "pct": 40}]}
    }
}
```

Sazby a platnosti ověř proti aktuálnímu ZDP (§30a, §31, §32, §32a) a
starému cfg; odchylky od starého cfg zapiš do komentáře v souboru.
`taxReturnGroup` připravuje podklad pro DPPO (fáze 5), engine ho nečte.

### PHP (`Shipard\Module\World\Assets\`)

- `TaxDepreciationRules` (interface):
  - `country(): string`
  - `availableMethods(string $acquiredDate, bool $intangible): list<string>`
  - `rules(string $method, string $acquiredDate): list<array{code, name}>`
    (skupiny / časová / mimořádná pravidla platná pro datum zařazení)
  - `isInterruptible(string $method): bool`
  - `allowsHalfYearOnDisposal(string $method): bool`
  - `round(float $amount): float`
  - `annualAmount(TaxYearInput $in): TaxAmount` — jeden rok ročních metod
  - `scheduleAmount(TaxScheduleInput $in): TaxAmount` — měsíce časových
    a mimořádných metod
- `CzTaxDepreciationRules` — vzorce CZ (níže), čísla jen z cfgItem.
- `AccountingOnlyTaxRules` — fallback pro stát bez configu:
  `availableMethods()` = `['accounting', 'none']`.
- `TaxRulesRegistry::forCountry(ConfigRuntime $config, string $country)`.
- `TaxAmount` — `{amount, formula}`; `formula` je lidsky čitelný text
  (`"100 000,00 × 22,25 %"`, `"2 × 48 000,00 / (6 − 2)"`), zobrazí ho
  plán na kartě (2b).

### Vzorce CZ

Roční metody (`straight`, `accelerated`) — za zdaňovací období = účetní rok;
rok zařazení se odpisuje celý (D37); `n` = počet let, za která už byl
daňový odpis **uplatněn** (přerušený rok se nezapočítá, D34).

| Situace | `straight` (§31) | `accelerated` (§32) |
|---|---|---|
| 1. rok | VC × `first` % | VC / `first` |
| další roky | VC × `next` % | 2 × ZC / (`next` − n) |
| rok TZ a dál | VCzvýš × `increased` % | rok TZ: 2 × ZCzvýš / `increased`; dál 2 × ZC / (`increased` − n′), n′ = roky od roku TZ |
| rok vyřazení, polovina (D35) | ½ ročního odpisu | ½ ročního odpisu |

Výsledek zaokrouhlit dle `rounding` a omezit zůstatkem (ZC). Zrychlený
odpis vzorec pro TZ ověř v §32 odst. 3 ZDP — starý engine po TZ `n`
nenuloval; rozdíl patří do komentáře testu.

Časové (`time`, §32a do 2020): měsíčně VC / `months`, od měsíce po
zařazení; po TZ ZCzvýš / `monthsIncreased` od měsíce po TZ.
Mimořádné (`extraordinary`, D41): měsíčně VC × `pct` / `months` dle
rozpisu, od měsíce po zařazení; nepřerušitelné; TZ na kartě s touto
metodou engine hlásí jako chybu (TZ má vlastní kartu). U obou se součet
měsíců období zaokrouhlí jednou za období.

`accounting` — daňový plán = účetní plán téže karty sečtený po účetních
letech. `none` — žádný daňový plán.

## Engine (`Shipard\Module\Economy\Assets\Depreciation\`)

Hodnotové objekty (readonly, `fromArray()`), žádná DB:

- `DepreciationSettings` — daňová metoda + kód pravidla, účetní metoda
  (`as_tax` / `time`) + délka v měsících, příznak `intangible` z druhu.
- `AssetEvent` — druh, okruh (`both` / `tax` / `acc`), datum, období
  od–do, částka, stav (potvrzeno / ne), původ, a u počátečního stavu
  oprávky, `unitsDone` (roky u ročních, měsíce u časových / mimořádných /
  účetních), `priceIncreased`, `originalDate`; příznak `claimUnrecorded`
  (D11), `halfYear` u odpisu roku vyřazení.
- `Period` — `{id|null, begin, end}`; `PeriodCalendar` z účetních let
  (a měsíců), za posledním rokem extrapolace po 12 měsících jako starý
  `fiscalPeriod()` (D32).
- `PlanRow` — období, druh, stav (`confirmed` / `planned`), základ,
  vzorec, částka, oprávky, zůstatek, hlášení `list<PlanMessage>`.
- `Plan` — řádky per okruh + souhrn (vstupní cena, oprávky, zůstatek,
  letošní odpis) + hlášení.

`DepreciationPlanner::plan(DepreciationSettings, list<AssetEvent>,
TaxDepreciationRules, PeriodCalendar $tax, PeriodCalendar $acc, string
$asOf): array{tax: Plan, acc: Plan}`:

1. Potvrzené události okruhu projde chronologicky (`both` patří do obou
   okruhů), průběžně drží VC, ZC, n / měsíce, příznak zvýšené VC.
   Potvrzené odpisy **nepřepočítává** — převezme je a vedle nich uvede
   spočtenou hodnotu; rozdíl = hlášení `mismatch` (varování, ne chyba,
   stejně jako starý engine).
2. Od posledního potvrzeného období dopočítá plán do nulového zůstatku,
   vyřazení nebo konce rozpisu.
3. Počáteční stav (D16): start v období jeho data, VC a oprávky z události,
   `n` = `unitsDone`; kontrola, zda oprávky odpovídají pravidlům a počtu
   let — nesoulad = varování `openingMismatch`.
4. Účetní okruh (engine obecný, ne per stát): `time` — měsíčně ZC /
   zbývající měsíce, od měsíce po zařazení (D37); po TZ (ZC + TZ) /
   zbývající měsíce (D44); `as_tax` — vzorec daňové metody karty nad
   účetním zůstatkem a vlastním počitadlem let, bez daňového přerušení;
   při měsíční četnosti rozpuštěno rovnoměrně do měsíců v užívání,
   poslední měsíc dorovná roční částku (D45). Zaokrouhlení nahoru za
   období (D36).
5. Snížení hodnoty snižuje VC i ZC v obou okruzích; vyřazení ukončí plán
   (účetní odpis do měsíce vyřazení, daňový polovina dle `halfYear`).
6. Hlášení (kódy, text česky přes cfgItem, vzor `PlanMessage`):
   `mismatch`, `openingMismatch`, `missingPeriod` (potvrzený odpis chybí
   před pozdějším potvrzeným), `dateOutsidePeriod`, `improvementOnSchedule`
   (TZ u mimořádné metody), `notWholeUnits` (necelé koruny mimo import),
   `ruleNotValid` (pravidlo neplatí pro datum zařazení).

## Testy

`tests/Unit/Module/World/Assets/CzTaxDepreciationRulesTest.php`,
`tests/Unit/Module/Economy/Assets/Depreciation/DepreciationPlannerTest.php`.
Scénáře jsou anonymizované podle vzorů ze starých dat (poměry zachované,
částky vymyšlené); očekávané hodnoty spočítané ručně v komentáři testu:

| Scénář | Očekávání |
|---|---|
| rovnoměrný sk. 2, VC 100 000, zařazení 2022 | 11 000, 22 250 × 4 |
| zrychlený sk. 2, VC 100 000 | 20 000, 32 000, 24 000, 16 000, 8 000 |
| rovnoměrný sk. 1, VC 90 000, zařazení 12/2017, přerušení 2019 | 18 000, 36 000, 0, 36 000 |
| rovnoměrný sk. 2, vyřazení v 3. roce s polovinou | 11 000, 22 250, 11 125 |
| rovnoměrný sk. 2 + TZ 20 000 ve 2. roce | 11 000, 24 000 × 4, 13 000 |
| zrychlený + TZ | dle §32 odst. 3; rozdíl proti starému enginu v komentáři |
| mimořádný sk. 2, VC 120 000, zařazení 5/2021 | 42 000, 58 000, 20 000 |
| mimořádný sk. 1, zařazení 12/2021 | 0 (2021), 100 % (2022) |
| časový NIM 36 m, VC 72 000, zařazení 5/2019 | 14 000, 24 000, 24 000, 10 000 |
| počáteční stav sk. 5 po 10 letech, VC 3 000 000, oprávky 960 000 | 102 000 v 11. roce, bez varování; s oprávkami 950 000 varování |
| účetní časový 60 m, VC 60 000, zařazení 3/2022, ročně | 9 000, 12 000 × 4, 3 000 |
| účetní časový + TZ (D44), měsíčně i ročně | ručně spočtené |
| `as_tax` měsíčně (D45) | součet měsíců = roční daňový vzorec |
| potvrzený odpis ≠ spočtený | převzatý, hlášení `mismatch` |
| NIM zařazený 2022 | `availableMethods` = accounting, none |
| stát bez pravidel | `AccountingOnlyTaxRules` |

`vendor/bin/phpunit --filter 'World\\\\Assets|Depreciation'`.

## Task breakdown

1. **`world.assets`** — modul, `assets-cz.jsonc`, `README.md`, registrace
   závislostí. *Hotovo když:* `ds-upgrade` na ukázkovém DS zkompiluje
   cfgItem `world.assets.cz`.
2. **Pravidla** — interface, `CzTaxDepreciationRules`,
   `AccountingOnlyTaxRules`, registr + testy pravidel.
3. **Engine** — hodnotové objekty, `PeriodCalendar`, `DepreciationPlanner`
   + testy scénářů.
4. **Dokumentace** — `docs/assets.md` §5 (engine a pravidla), Stav tasku
   + `python3 scripts/tasks-index.py`.

## Upřesnění z plánování (potvrzeno 2026-09-30)

Doplnění nad rámec textu výše:

- **Zaokrouhlení odolné proti plovoucí čárce.** Prosté `ceil(VC × sazba
  / 100)` přestřeluje o korunu (50 000 × 5,15 % = 2 576 místo 2 575);
  `round()` pravidel počítá `ceil(round(x, 4))`, test na to. Zlatý test
  (fáze 6) může proti starým datům ukázat ±1 Kč.
- **`PlanRow.computed`** — spočtená hodnota vedle převzaté potvrzené.
- **Hlášení** — planner vrací kód, závažnost a parametry; texty v cfgItem
  `economy.assets.planMessages` s helperem pro 2b (engine bez
  `ConfigRuntime`).
- **`methods` v configu** nesou příznaky `tangible` / `intangible`;
  `availableMethods()` se neptá na klíče metod.
- **Kód `settingsInvalid`** — daňová `accounting` nebo `none` s účetní
  `as_tax` (kruh, resp. prázdný vzorec). Totéž pravidlo do validace karty
  ve 2b.
- **`PeriodCalendar`** extrapoluje i před první založený rok (historie
  z importu).
- **Krátký účetní rok** roční daňový odpis nekrátí (starý engine krátil
  poměrem měsíců); rozdíl do komentáře testu.

Rozhodnutí měnící očekávané hodnoty (body 3–5 ověřeny při implementaci,
výsledek u každého):

1. **`as_tax` měsíčně, zařazení v posledním měsíci roku** (D45 × D37):
   celý roční odpis jde do posledního měsíce roku — roční součet je
   stejný při měsíční i roční četnosti.
2. **Počáteční stav, zrychlený odpis se zvýšenou cenou** (D16):
   `unitsDone` = roky odpisované ze zvýšené ZC; kontrola
   `openingMismatch` jen bez příznaku zvýšené ceny.
3. **Časový odpis NIM po TZ:** ZCzvýš / max(zbývající měsíce,
   `monthsIncreased`) — §32a odst. 6 „nejméně však“. Paragraf je od 2021
   zrušen; znění potvrzují jen sekundární zdroje.
4. **TZ v roce zařazení** — **změněno proti původnímu návrhu.** TZ před
   prvním uplatněným odpisem je součást pořizovací ceny: 1. rok sazba
   `first` (koeficient `first`) ze zvýšené ceny a dál **běžná** sazba
   `next`, ne `increased`. Pokyn GFŘ D-59 případ neřeší; odborné zdroje
   se shodují, že se na majetek hledí, jako by byl pořízen najednou.
5. **Vyřazení u časové a mimořádné daňové metody:** odpis do měsíce
   vyřazení včetně, stejně jako v účetním okruhu. Odpovídá §30a odst. 2
   (odpisy „ve výši připadající na toto zdaňovací období“).

## Výsledek (2026-09-30)

Popis hotového stavu: `docs/assets.md` §5.1–5.2,
`modules/world/assets/README.md`. Odchylky od textu výše:

- **Rozhraní `TaxDepreciationRules`** má navíc `methodKind()` (druh
  metody pro engine: `annual` / `monthly` / `accounting` / `none`),
  `allowsImprovement()` a `scheduleMonths()`. `TaxAmount` nese i `exact`
  (hodnota před zaokrouhlením) — období rozdělené technickým zhodnocením
  se zaokrouhluje jednou.
- **`TaxRulesRegistry::forCountry()`** přijme i `null` místo konfigurace
  (→ `AccountingOnlyTaxRules`).
- **`PeriodCalendar`** se staví `yearly($years)` / `monthly($years,
  $months)`; `Period` je `{id, begin, end}`.
- **`Plan`** nese souhrn ze skutečného (potvrzeného) stavu
  a `currentYearAmount`; řádek má `entryPrice` (základ), `computed`,
  `eventId`.
- **Plánované odpisy před pozdější událostí.** Před změnou hodnoty a před
  přerušením se doplní plán období, která skončila dřív; potvrzený odpis
  za plánovaným řádkem nebo mezerou = `missingPeriod`.
- **`as_tax` v roce vyřazení** — poměrná část ročního odpisu podle měsíců
  v užívání (PRD říkal jen „do měsíce vyřazení“).
- **Řádek plánu měsíčních metod při roční četnosti** je za celý rok, i
  když rozpis skončí dřív; zkracuje se jen rok vyřazení.
- **Hlášení:** `mismatch` a `openingMismatch` jsou varování, ostatní
  chyby; `notWholeUnits` se u původu `import` nehlásí.

Pro 2b: engine nehlídá snížení hodnoty větší než zůstatková cena
(zůstatek vyjde záporný) ani události po vyřazení — patří do validace
`AssetEventDocument`. Roční součet `as_tax` při měsíční četnosti se od
roční liší jen tehdy, když TZ ke konci roku roční částku sníží pod už
odepsané měsíce.

Ověření: `vendor/bin/phpunit --filter 'World\\Assets|Depreciation'`
(105 testů), celá sada 6 649 testů; `ds-upgrade` na `4l3j-z0bz-kz39-echj`
zkompiloval `world.assets.cz` a `economy.assets.planMessages`. Mimo
testy prošlo 6 000 náhodných karet kontrolou invariantů (součet odpisů =
vstupní cena, zůstatek ≥ 0, daňový `accounting` = účetní plán).

## Rozhodnutí k designu (potvrzená)

- ✓ D31 Pravidla per stát v `world.assets`, čísla v configu, vzorce v PHP.
- ✓ D32 Engine je čistá funkce bez DB.
- ✓ D34 Přerušení jen daňově a jen u ročních metod.
- ✓ D35 Polovina ročního odpisu v roce vyřazení.
- ✓ D36 Zaokrouhlení nahoru na celé koruny za období, strop zůstatkem.
- ✓ D37 Účetní a časové odpisy od měsíce po zařazení; roční daňový za rok
  zařazení celý.
- ✓ D41 Mimořádné odpisy §30a hned (2020–2023 sk. 1 a 2, bezemisní
  vozidla 2024–2028).
- ✓ D42 Varianty +10/15/20 % jen jako data.
- ✓ D43 Sazby a koeficienty podle data prvního zařazení (ne podle roku
  odpisu jako starý engine).
- ✓ D44 TZ v účetní časové metodě: (ZC + TZ) / zbývající měsíce původní
  doby.
- ✓ D45 `as_tax` při měsíčních účetních odpisech: roční daňový odpis
  rozpuštěný do měsíců, poslední měsíc dorovná roční částku.
