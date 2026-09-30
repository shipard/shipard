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
| `interruptible` | metody, které lze přerušit |
| `intangibleTaxFrom` | od tohoto data zařazení se nehmotný majetek odpisuje jen podle účetnictví |
| `methods` | metody: `kind` (`annual`, `schedule`, `time`, `accounting`, `none`), `tangible` / `intangible`, volitelně `improvement: false` |
| `groups` | odpisové skupiny ročních metod; varianty se zvýšeným odpisem 1. roku mají `group` (základní skupina) a `methods` (omezení metod) |
| `straightRates` | sazby rovnoměrného odpisu v %: `first` / `next` / `increased` per `code` a interval `from`–`to` |
| `acceleratedCoefficients` | koeficienty zrychleného odpisu, stejný tvar |
| `timeRules` | časové odpisy: `months`, `monthsIncreased` (nejkratší doba po technickém zhodnocení), `from`–`to` |
| `extraordinaryRules` | mimořádné odpisy: `schedule` = úseky `{months, pct}`, `from`–`to` |

Intervaly `from` / `to` se vždy vztahují k **datu prvního zařazení**
majetku, ne k roku odpisu; `null` = bez omezení. Názvy jsou vícejazyčné
(`name`, `name:cs`, `name:en`).

## Přidání dalšího státu

1. `config/assets-{country}.jsonc` + registrace v `module.jsonc`.
2. Třída implementující `TaxDepreciationRules` se vzorci státu.
3. Záznam v `TaxRulesRegistry`.

V enginu (`economy.assets`) se nic nemění — žádné `if (cz)`.
