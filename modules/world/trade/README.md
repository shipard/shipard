# Modul: Obchodní unie (world.trade)

Definice nadnárodních ekonomických a daňových uskupení (obchodních unií),
členství zemí v nich a daňových prefixů. Primárně slouží pro Evropskou unii
a její pravidla pro DPH (reverse charge, OSS), ale struktura je dostatečně
obecná pro další unie (GCC apod.).

## Závislosti

- `world.base` — číselníky zemí, měn a jazyků

## Tabulky

Modul nemá vlastní tabulky.

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `world.trade.unions` | [config/tradeUnions.jsonc](config/tradeUnions.jsonc) | Obchodní unie, členství a daňové prefixy |

## Struktura konfiguračního souboru

### tradeUnions.jsonc

Klíčem je identifikátor unie malými písmeny (`eu`, `gcc`).

Každá unie obsahuje:

- `name`, `name:cs`, `name:en` — vícejazyčné názvy
- `shortName` — zkratka (EU, GCC)
- `taxType` — typ daně v rámci unie (`vat`)

#### members

Členské země unie. Klíčem je ISO 3166-1 alpha-2 kód země (odkaz do
`world.base.countries`).

- `joinedAt` — datum vstupu do unie (`null` = zakládající člen / datum není známo)
- `leftAt` — datum odchodu z unie (`null` = stále člen)

Příklad: Velká Británie (`gb`) má `leftAt: "2020-12-31"` (Brexit).

#### taxPrefixes

Prefixy používané v daňových identifikačních číslech (VAT-ID). Klíčem je
prefix **velkými písmeny** — přesně tak, jak se reálně používá ve VAT-ID
(CZ12345678, EL123456789).

- `country` — ISO kód země, ke které prefix patří
- `validFrom` / `validTo` — platnost prefixu (`null` = od začátku / bez konce)
- `region` (volitelné) — sub-národní oblast, pokud prefix není celostátní
- `supplyKinds` (volitelné) — seznam druhů plnění (`goods`, `services`),
  pro které prefix v unii platí; chybí = platí pro všechna plnění. Dnes
  jen `XI` (`["goods"]`). Čte ho obecně derivace místa plnění přijatého
  dokladu (`VatPlaceDerivation` v `core.exchange`) — žádná výjimka pro
  konkrétní prefix v kódu.
- `note` (volitelné) — poznámka ke speciálním případům

Většina prefixů odpovídá 1:1 ISO kódu země. Existují dvě výjimky:

- **EL** → Řecko (ISO kód země je `gr`, ale VAT prefix je historicky `EL`)
- **XI** → Severní Irsko; speciální post-Brexit režim (Windsor Framework),
  kde firmy ze Severního Irska nadále fungují v EU VAT systému pro zboží.
  Mapuje se na zemi `gb` s upřesněním `region: "Northern Ireland"`.

## PHP

### `TradeUnionResolver` (`src/TradeUnionResolver.php`)

Čtení cfgItem s cache (vzor `VatRateResolver` v `world.vat`); `ConfigRuntime`
v konstruktoru. Chybějící cfgItem = žádné unie, ne výjimka.

- `unionsOf(string $country, string $date): list<string>` — klíče unií,
  jejichž členem je země k datu (`joinedAt` ≤ datum, `leftAt` null nebo
  ≥ datum);
- `taxPrefix(string $union, string $prefix): ?array` — záznam prefixu
  (`country`, `validFrom`, `validTo`, `supplyKinds`, …) bez vyhodnocení
  data; `null` = unie prefix nezná;
- `isPrefixValid(array $entry, string $date): bool` — platnost záznamu
  k datu.

Resolver drží jen čtení dat. Rozhodování (místo plnění dokladu podle
prefixu DIČ dodavatele) patří do modulu, který ho potřebuje —
`Shipard\Module\Core\Exchange\Document\VatPlaceDerivation`,
`tasks/exchange-received-vat-place.md`.

```php
$unions = new TradeUnionResolver($config);
$unions->unionsOf('cz', '2026-04-15');          // ['eu']
$unions->unionsOf('cz', '2003-12-31');          // []
$entry = $unions->taxPrefix('eu', 'EL');        // ['country' => 'gr', …]
$gb = $unions->taxPrefix('eu', 'GB');
$unions->isPrefixValid($gb, '2020-06-30');      // true
$unions->isPrefixValid($gb, '2021-01-01');      // false (Brexit)
```

Dále cfgItem čtou `VatRegistrationsForm` / `VatRegistrationsViewer`
(`economy.codebooks`) pro nabídku regionů registrace DPH.

## Plánovaná rozšíření

- VAT sazby členských zemí EU (standardní, snížené, nulové) — pro OSS
- Validační vzory VAT-ID per prefix (regex)
