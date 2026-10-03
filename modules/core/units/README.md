# Modul: Měrné jednotky (core.units)

Modul spravuje číselník měrných jednotek (kg, m, l, hod, ks, …) společný
pro celý systém. Slouží jako základ pro položky, doklady, sklady a další
moduly, které potřebují referencovat jednotku.

Jednotky jsou seskupené podle veličiny (`quantity`) — hmotnost, objem,
délka, plocha, čas, energie, počet, ostatní. V rámci jedné veličiny lze
mezi jednotkami převádět skrze koeficient k základní jednotce
(`is_base = 1`, `coefficient = 1`); jednotky veličiny `time` koeficient
nemají, protože převod mezi hodinou, dnem a měsícem není exaktní.

## Závislosti

- `core.system`

## Tabulky

| Tabulka | Popis |
|---|---|
| [core_units](tables/core_units.md) | Hlavní číselník jednotek |

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [UnitDocument.php](src/UnitDocument.php) | Validace (povinná pole, kladný koeficient) |
| [UnitsForm.php](src/UnitsForm.php) | Editační formulář |
| [UnitsViewer.php](src/UnitsViewer.php) | Viewer s tabem aktivní/archív/koš |
| [UnitsProvisioner.php](src/UnitsProvisioner.php) | Idempotentní seed systémových jednotek |

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `core.units.quantities` | [config/quantities.jsonc](config/quantities.jsonc) | Veličiny pro `enumString` sloupec `quantity` |
| `core.units.printShortcuts` | [config/printShortcuts.jsonc](config/printShortcuts.jsonc) | Tiskové zkratky systémových jednotek podle `system_code` — tisk je použije v jiném jazyce než českém |

## Seedovaná data

Provisioner při `bin/shpd-ds ds-upgrade` naplní 18 systémových jednotek
podle [config/unitsSeed.jsonc](config/unitsSeed.jsonc). Každý záznam má
`system_code` (NOT NULL = systémový), `name:cs/:en`, `shortcut`,
`quantity`, volitelný `coefficient` a flag `is_base`.

Pravidla provisioneru:

- záznam je identifikován přes `system_code`
- existuje-li v DB záznam se stejným `system_code` (i v archívu nebo koši),
  provisioner ho nechá být — uživatel si jednotku mohl odložit a nechceme
  mu ji znovu obnovovat
- nově vložené záznamy dostanou `docState = 40` (V pořádku) a
  `docStateMain = 3`

## Mechanika `is_base + coefficient`

Pro každou veličinu existuje (typicky) jedna **základní** jednotka:

- `weight` — kg (`coefficient = 1`)
- `length` — m
- `area` — m²
- `volume` — l (m³ má `coefficient = 1000`, tj. 1 m³ = 1000 l)
- `energy` — kWh (MWh = 1000, GJ ≈ 277,778)
- `count` — ks

Veličina `time` nemá žádnou základní jednotku a všechny její členy mají
`coefficient = NULL` — čas je v účetnictví a fakturaci kategoriální
veličina (hodina, den, měsíc, rok), ne lineárně převoditelná.

Sloupec `coefficient` má smysl jako **koeficient k základní jednotce
téže veličiny**. Hodnota `1000` u kilometru znamená "1 km = 1000 m".
NULL znamená "tato jednotka se nepřevádí".
