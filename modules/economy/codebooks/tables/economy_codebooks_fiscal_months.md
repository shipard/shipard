# Tabulka: economy_codebooks_fiscal_months

Měsíc fiskálního roku. Bez vlastních `docStates` — lifecycle dědí
přes rodičovský fiskální rok. Každý rok má právě 14 měsíců
(1× Otevření + 12× Běžné + 1× Uzavření).

## Sloupce

| Sloupec | Typ | Popis |
|---|---|---|
| `fiscal_year` | int, reference `economy_codebooks_fiscal_years` | Vlastnický fiskální rok |
| `date_begin` | date | Začátek období |
| `date_end` | date | Konec období |
| `period_type` | enumInt default 1, cfgItem `economy.codebooks.fiscalPeriodTypes` | 0=Otevření, 1=Běžné, 2=Uzavření |
| `calendar_year` | int | Denormalizováno z `date_begin` v `beforeSave` |
| `calendar_month` | smallint | Denormalizováno z `date_begin` v `beforeSave`, 1–12 |
| `locked` | boolean default 0 | Zámek měsíce (#55 D27) — viz níže. Jen `period_type` 1 |
| `locked_at` | datetime, nullable, system | Kdy byl měsíc zamčen; `FiscalMonthDocument` při `locked` 0→1, maže při 1→0. NULL ve strojovém kontextu |
| `locked_by` | int, reference `core_system_users`, nullable, system | Kdo zamkl (uživatel requestu) |

## Indexy

- `idx_fiscal_year` na `fiscal_year, date_begin`
- `idx_dates` na `date_begin, date_end` — lookup doklad → fiskální měsíc
  (`FiscalMonthLookup::monthIdForDate`)

## Denormalizace `calendar_year`/`calendar_month`

`FiscalMonthDocument::beforeSave` vždy přepíše `calendar_year` a
`calendar_month` hodnotami odvozenými z `date_begin` (formát
`YYYY-MM-DD`). Důvody:

- Sloupce slouží jako rychlý filtr/index pro dotazy typu „doklady
  za prosinec 2026" bez nutnosti DATE_FORMAT funkcí v WHERE.
- Při manuální editaci `date_begin` přes sub-form se hodnoty
  automaticky aktualizují — uživatel je nemusí psát.

Ve formuláři jsou pole `readOnly` jen pro orientaci; hodnota se
doplní po uložení.

## Zámek měsíce (#55 D27)

`locked = 1` na běžném měsíci blokuje **každý** doklad, jehož původní
`fiscal_month` nebo nový měsíc (dopočtený z `accounting_date` přes
`FiscalMonthLookup`, stejný dotaz jako `DocDocument::resolveFiscalMonthId`)
míří na tento měsíc — bez ohledu na obsah a stav dokladu, koncepty
i bezdaňové převody včetně (`FiscalMonthLockProvider`, registrovaný
v `module.jsonc` → `documentLockProviders`). Vynucení dělá `TableGateway`
(chyba formuláře `locked`), generické CRUD (`DOCUMENT_LOCKED`) a nabídka
přechodů (žádné). Import mód (`_importNumber`) zámek obchází.

Doklad se do jednodenního měsíce Otevření / Uzavření zařadí jen výslovně
— `docs_core_heads.fiscal_period_type` = `opening` / `closing` (#69 D20,
`FiscalMonthLookup::monthIdForYearAndType`, rokem + typem, ne datem);
běžné doklady tam podle data nikdy nespadnou. Uzávěrkové řádky deníku
saldokonto nederivuje (`docs/accbal.md` §4.2).

Zamčený měsíc nemění rozsah, typ ani rok — jediná povolená mutace je
přepnutí `locked` (formulář měsíce v detailu fiskálního roku). Roční
`economy_codebooks_fiscal_years.locked` se **nevynucuje** — sémantika
uzavřeného roku přijde s uzávěrkou.

Při zamykání měsíce `FiscalMonthDocument` varuje (neblokuje), když kontrola
zůstatků 343 za podané instance DPH končící v měsíci (`economy.vat`,
`ClosedPeriodBalanceCheck`) najde nenulový zůstatek — chybí zaúčtování
přiznání, nebo se DPH po podání změnila. Vazba je měkká: běží jen s aktivním
modulem `economy.vat` (přítomnost tabulky).

## Otevření a Uzavření jako jednodenní

Period typy 0 (Otevření) a 2 (Uzavření) mají vždy
`date_begin == date_end`:

- Otevření = `year.date_begin` (první den roku)
- Uzavření = `year.date_end` (poslední den roku)

Slouží počátečním stavům a závěrkovým operacím — doklady, které
patří „před začátek" nebo „po skončení" pravidelného účetního
rytmu, do nich logicky spadnou.

## Související

- [economy_codebooks_fiscal_years](economy_codebooks_fiscal_years.md) — rodičovský rok
- [FiscalMonthDocument](../src/FiscalMonthDocument.php) — validace + beforeSave
- [forms/economy_codebooks_fiscal_months.jsonc](../forms/economy_codebooks_fiscal_months.jsonc) — sub-form pro editační formulář roku
