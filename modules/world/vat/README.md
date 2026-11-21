# Modul: world.vat

Konfigurační modul s pravidly DPH per stát. Bez vlastních databázových
tabulek — pouze cfgItem soubory a PHP resolver.

## Účel

Poskytuje:

- **Klasifikaci** sazeb DPH (`vatCategories`: standard, reduced, …;
  `exempt` = v rekapitulaci s nulovou daní, `outOfScope` = mimo rekapitulaci)
- **Konkrétní kódy DPH** per stát (`vatCodes`: cz-110, cz-150, …) — položky
  v roletce na řádku dokladu
- **Časově proměnná procenta** per kód (`vatPercents`: cz-110 → 20 % do 2012,
  21 % od 2013)
- **Texty na doklad** (`vatNotes`: "Daň odvede zákazník" pro PDP a EU)
- **Předměty plnění** tuzemského přenesení daňové povinnosti
  (`reverseChargeSubjects`: `4` stavební práce, `4a`, `5`, `14` mobilní
  telefony …) s platností v čase — kód pro oddíly A.1 a B.1 kontrolního
  hlášení

## Stav

V aktuální verzi je k dispozici **pouze CZ** (`world.vat.cz`). Ostatní
EU státy přijdou v navazujících fázích — viz `vat-{country}.jsonc` šablona.

## Použití

```php
use Shipard\Module\World\Vat\VatRateResolver;

$resolver = new VatRateResolver($config);

// Procento k datu
$pct = $resolver->resolveVatPct('cz', 'cz-110', '2024-06-01');
// → 21.0

// Filtrování pro UI roletku na řádku faktury vydané do tuzemska
$codes = $resolver->getVatCodes('cz', direction: 'output', place: 'domestic');
// → ['cz-120' => [...], 'cz-121' => [...], 'cz-150' => [...], ...]

// Detail kódu
$code = $resolver->getVatCode('cz', 'cz-115');

// „Mimo DPH“ — řádek, který není plněním: mimo rekapitulaci, v částce dokladu
$resolver->isOutOfScopeCode('cz', 'cz-000');                          // → true
VatRateResolver::isOutOfScopeDefinition($codes['cz-000']);            // totéž nad definicí kódu

// Tuzemské přenesení daňové povinnosti: kód s příznakem `reverseCharge`
// vyžaduje předmět plnění z číselníku platného k datu sazby dokladu
$resolver->isReverseChargeCode('cz', 'cz-115');                       // → true
$resolver->getReverseChargeSubjectsValidAt('cz', '2026-11-01');       // → [{code, name, legal, validFrom, validTo}, …]
$resolver->isReverseChargeSubjectValid('cz', '4a', '2017-06-30');     // → false (platí od 1. 7. 2017)
```

## Struktura cfgItem

Viz `config/vat-cz.jsonc` jako referenční vzor. Detailní popis atributů:

- `vatCategories` — klasifikace (klíč: lowercase slug, value: name vícejaz.)
- `vatCodes` — konkrétní kódy (klíč: `{country}-{nnn}`, value: object s atributy)
- `vatPercents` — pole časových intervalů per kód
- `vatNotes` — texty pro tisk dokladu
- `reverseChargeSubjects` — předměty plnění tuzemského přenesení (klíč: kód
  jako řetězec, value: `name` vícejaz., `legal`, nepovinné `validFrom` /
  `validTo` ve tvaru `YYYY-MM-DD`; chybějící = bez omezení). Číselník čte
  **jen** `VatRateResolver` — předmět nese řádek dokladu a rekapitulace
  (`reverse_charge_code`), ne kód DPH.

Plný popis atributů `vatCodes` viz `docs/docs-mvp.md` sekce 4.

### Kód „Mimo DPH“ a kategorie kódů (#164)

- **`cz-000` „Mimo DPH“** (kategorie `outOfScope`) je výslovný kód pro
  řádek, který není plněním — odpočet nezdaněné zálohy, kauce, místní
  poplatek, průběžná položka. Je **univerzální**: nemá `place` ani
  `direction`, `getVatCodes()` ho vrací pro každý směr i místo plnění
  a vždy jako **poslední** (první kód nabídky tak nikdy není „Mimo DPH“).
  Sazbu má 0 bez omezení platnosti. Jediný znak „mimo rekapitulaci“ je
  kategorie — čte ji `isOutOfScopeCode()` / `isOutOfScopeDefinition()`,
  žádný druhý příznak na kódu není.
- **`exempt`** = v rekapitulaci s nulovou daní: osvobozená plnění (`cz-123`,
  `cz-124`, vstupní `cz-114` / `cz-214` / `cz-414`) i plnění s místem plnění
  mimo tuzemsko (`cz-201`, `cz-202`, `cz-209`, `cz-401`, `cz-402`,
  třístranný obchod `cz-220` / `cz-221`), kde je zařazení jen technické —
  rozdíl nese řádek přiznání. Kategorie `zero` a kódy „Bez daně“ zanikly.
- `validateCountryConfig()` hlídá: kód `outOfScope` nesmí mít `place`,
  `direction`, `vatReturnRow`, `reverseVatCode` ani `supplyKind`; ostatní
  kódy `place` i `direction` mít musí (chybějící místo by filtr tiše bral
  jako tuzemsko).

Jak s kódem zachází doklad: `docs/vat-calculation.md` § 1.1.

## Vztah k `world.base` a `world.trade`

`world.base` poskytuje seznam zemí a měn. `world.trade` poskytuje obchodní
unie (EU). `world.vat` na obojí navazuje — kód státu v `world.vat.{country}`
musí existovat ve `world.base.countries`. Příslušnost k EU se odvozuje
ze `world.trade.unions`.
