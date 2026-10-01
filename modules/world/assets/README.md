# Modul: world.assets

Konfigurační modul s pravidly **daňových odpisů majetku** per stát. Bez
vlastních databázových tabulek — čísla jsou v cfgItem, vzorce v PHP
(`docs/assets.md` D31).

## Účel

Poskytuje pro stát zdroje dat (`DataSourceConfig::getCountry()`):

- **metody** daňového odpisování a pro jaký majetek se nabízejí,
- **odpisové skupiny** se sazbami a koeficienty, časová a mimořádná
  pravidla — vše s platností podle data prvního zařazení majetku,
- **zaokrouhlení**, přerušitelnost a poloviční odpis při vyřazení.

Účetní odpisy (metoda zvolená firmou) sem nepatří — počítá je obecný
engine v `economy.assets` (`DepreciationPlanner`), který tahle pravidla
jen volá.

## Stav

K dispozici je **pouze CZ** (`world.assets.cz`). Stát bez konfigurace
dostane `AccountingOnlyTaxRules` — nabízí jen metody `accounting`
a `none`.

## Použití

```php
use Shipard\Module\World\Assets\TaxRulesRegistry;
use Shipard\Module\World\Assets\TaxYearInput;

$rules = TaxRulesRegistry::forCountry($config, $dsConfig->getCountry());

// Nabídka pro kartu zařazenou 2022: hmotný / nehmotný majetek
$rules->availableMethods('2022-03-15', intangible: false);
// → ['straight', 'accelerated', 'extraordinary', 'accounting', 'none']
$rules->rules('straight', '2022-03-15');
// → [['code' => 'cz-1', 'name' => 'Odpisová skupina 1'], …]

// Druhý rok rovnoměrného odpisu ve skupině 2
$amount = $rules->annualAmount(new TaxYearInput(
    method: 'straight', ruleCode: 'cz-2', acquiredDate: '2022-03-15',
    entryPrice: 100000.0, residual: 89000.0, yearsApplied: 1,
));
// → amount 22250.0, formula "100 000,00 × 22,25 %"
```

| Třída | Role |
|---|---|
| `TaxDepreciationRules` | rozhraní pravidel jednoho státu — jediné, co engine zná |
| `CzTaxDepreciationRules` | vzorce ZDP (§ 30a, § 31, § 32, § 32a) nad cfgItem `world.assets.cz`; `validateConfig()` hlídá konzistenci dat |
| `AccountingOnlyTaxRules` | fallback státu bez pravidel |
| `TaxRulesRegistry` | výběr pravidel podle státu |
| `TaxYearInput`, `TaxScheduleInput`, `TaxAmount` | vstup ročních a měsíčních metod, výsledek (`amount`, `formula`, `exact`) |

Rozdělení práce: pravidla znají **sazby a vzorec jednoho období**;
kalendář, počitadla let a měsíců, pořadí událostí a plán drží engine.
Roční metody (`annualAmount`) dostanou počet let s uplatněným odpisem
a příznak zvýšené ceny, měsíční (`scheduleAmount`) polohu v rozpisu.
Zaokrouhlení `round()` je odolné proti chybě plovoucí čárky
(50 000 × 5,15 % = 2 575, ne 2 576).

## Závislosti

- `world.base` — číselník zemí

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `world.assets.cz` | [config/assets-cz.jsonc](config/assets-cz.jsonc) | Pravidla daňových odpisů ČR (ZDP § 26–§ 32a) |

### Struktura `assets-{country}.jsonc`

| Sekce | Obsah |
|---|---|
| `rounding` | `mode` (`ceil` / `round` / `floor`) a `precision` (počet desetinných míst) |
| `halfYearOnDisposal` | metody, u nichž lze v roce vyřazení uplatnit polovinu ročního odpisu |
| `shortPeriodHalfYear` | metody, u nichž zdaňovací období kratší než 12 měsíců dává polovinu ročního odpisu (D46) |
| `interruptible` | metody, které lze přerušit |
| `intangibleTaxFrom` | od tohoto data zařazení se nehmotný majetek odpisuje jen podle účetnictví |
| `methods` | metody: `kind` (`annual`, `schedule`, `time`, `accounting`, `none`), `tangible` / `intangible`, volitelně `improvement: false` |
| `groups` | odpisové skupiny ročních metod; varianty se zvýšeným odpisem 1. roku mají `group` (základní skupina) a `methods` (omezení metod) |
| `straightRates` | sazby rovnoměrného odpisu v %: `first` / `next` / `increased` per `code` a interval `from`–`to` |
| `acceleratedCoefficients` | koeficienty zrychleného odpisu, stejný tvar |
| `timeRules` | časové odpisy: `months`, `monthsIncreased` (nejkratší doba po technickém zhodnocení), `from`–`to` |
| `extraordinaryRules` | mimořádné odpisy: `schedule` = úseky `{months, pct}`, `from`–`to` |
| `taxReturnGroups` | členění daňových odpisů pro přiznání k dani z příjmů (klíč → název, v pořadí výkazu); klíč nese `taxReturnGroup` skupiny, pravidla nebo metody, varianta skupiny ho dědí ze základní (`TaxDepreciationRules::taxReturnGroup()` / `taxReturnGroups()`, přehled „Daňové odpisy pro DPPO“) |

Intervaly `from` / `to` se vždy vztahují k **datu prvního zařazení**
majetku, ne k roku odpisu; `null` = bez omezení. Názvy jsou vícejazyčné
(`name`, `name:cs`, `name:en`).

## Přidání dalšího státu

1. `config/assets-{country}.jsonc` + registrace v `module.jsonc`.
2. Třída implementující `TaxDepreciationRules` se vzorci státu.
3. Záznam v `TaxRulesRegistry`.

V enginu (`economy.assets`) se nic nemění — žádné `if (cz)`.
