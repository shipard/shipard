# Modul: Svět (world.base)

Základní číselníky používané napříč celým systémem — země, měny a jazyky.
Data jsou uložena výhradně v JSONC konfiguračních souborech (bez databázových
tabulek).

## Závislosti

- `core.system`

## Tabulky

Modul nemá vlastní tabulky.

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `world.base.countries` | [config/countries.jsonc](config/countries.jsonc) | Země světa — ISO 3166-1 |
| `world.base.currencies` | [config/currencies.jsonc](config/currencies.jsonc) | Měny — ISO 4217 |
| `world.base.languages` | [config/languages.jsonc](config/languages.jsonc) | Jazyky — ISO 639-1 |
| `world.base.documentLanguages` | [config/documentLanguages.jsonc](config/documentLanguages.jsonc) | Jazyky dokumentů — ve kterých lze partnerovi vystavit a poslat doklad (`cs`, `en`, `sk`, `de`); zdroj pro `base_persons_persons.language` a `DocumentLanguageResolver`. Není to seznam jazyků tisku |
| `world.base.cashRounding` | [config/cashRounding.jsonc](config/cashRounding.jsonc) | Zaokrouhlování hotovostních plateb podle měny a země (#73 D6) — krok a země, kde platí; na mód dokladu překládá `docs.core` (`TotalRoundingAuto`) |

## Struktura konfiguračních souborů

### countries.jsonc

Klíčem je ISO 3166-1 alpha-2 kód malými písmeny (`cz`, `de`, `us`).

Každý záznam obsahuje:

- `alpha3`, `numeric` — další ISO kódy země
- `name`, `name:cs`, `name:en` — vícejazyčné názvy (i18n dle konvencí Shipardu)
- `localName` — název v úředním jazyce dané země (např. „Česko", „Deutschland", „日本")
- `intlName` — mezinárodní název v latince; u zemí s latinkovým písmem se shoduje s anglickým názvem, u ostatních slouží jako transliterace (např. Ukrajina: `localName` = „Україна", `intlName` = „Ukraine")
- `phonePrefixes` — pole telefonních předvoleb (některé země mají víc, např. Dominikánská republika: `[1809, 1829, 1849]`)
- `tld` — národní doména nejvyšší úrovně
- `flag` — vlajka jako emoji
- `continent` — kontinent (`europe`, `asia`, `africa`, `north_america`, `south_america`, `oceania`)
- `currency` — výchozí měna (klíč do `currencies.jsonc`)
- `languages` — úřední jazyky (pole klíčů do `languages.jsonc`)

### currencies.jsonc

Klíčem je ISO 4217 alpha-3 kód malými písmeny (`czk`, `eur`, `usd`).

Každý záznam obsahuje:

- `alpha3` — ISO kód velkými písmeny (konvence ISO 4217, např. „CZK")
- `numeric` — ISO 4217 numerický kód
- `name`, `name:cs`, `name:en` — vícejazyčné názvy
- `symbol` — symbol měny (Kč, €, $)
- `decimals` — počet desetinných míst (0 pro JPY, 2 pro většinu, 3 pro BHD/KWD/OMR)

### cashRounding.jsonc

Klíčem je ISO 4217 alpha-3 kód měny malými písmeny (`czk`, `eur`).

Každý záznam obsahuje:

- `step` — krok zaokrouhlení hotovostní platby v jednotkách měny (`1` celé
  koruny, `0.05` pěticenty); kódy zaokrouhlovacích módů dokladu modul nezná,
  krok překládá `docs.core` (`RoundingModes::forStep()`)
- `countries` — země (klíče do `countries.jsonc`), kde pravidlo platí;
  hotovost v téže měně jinde se zaokrouhluje na nejmenší minci

### languages.jsonc

Klíčem je ISO 639-1 dvoupísmenný kód (`cs`, `en`, `de`).

Každý záznam obsahuje:

- `iso639_2` — ISO 639-2 třípísmenný kód
- `name`, `name:cs`, `name:en` — vícejazyčné názvy
- `localName` — název jazyka v tom jazyce samotném (Čeština, Deutsch, 日本語)
- `script` — typ písma (`latin`, `cyrillic`, `arabic`, `cjk`, `devanagari`, ...)
- `direction` — směr písma (`ltr` nebo `rtl`)
