# Modul: world.validations

Čistá knihovna **pravidel hodnot per stát** (#118 D5, D11): tvar
a platnost IČO, DIČ / VAT ID, PSČ, tuzemského čísla účtu, IBAN, BIC,
telefonu, e-mailu, webu a platebních symbolů. Bez tabulek, bez cfgItem,
bez `ConfigRuntime` a bez databáze — použitelná v produkční cestě
(normalizátor a validátor canonicalu v `core.exchange`), v resolverech,
v porovnávači evaluace i v preflightu datových sad.

## Účel

Dva kroky (D1):

- **Normalizace** sjednocuje tvar hodnoty mlčky a idempotentně, obsah
  nemění: mezery, velikost písmen, vedoucí nuly IČO, prefix DIČ, předvolba
  telefonu.
- **Validace** kontroluje platnost (kontrolní součty, tvar podle země)
  a hodnotu nemění. Kde je pravidlo, je i nález; kde není, hodnota platí.

Co se **nehádá**: stát bez vlastní třídy dostane `GenericValueRules` —
IČO a PSČ jak na dokladu, telefon bez předvolby, účet bez převodu, DIČ jen
obecným tvarem. Chybějící údaj se nikdy nedopočítává; jedinou výjimkou je
oprava IBAN ↔ číslo účtu z redundance na dokladu (D4), kterou dělá
normalizátor canonicalu v `core.exchange`, ne tato knihovna.

## Použití

```php
use Shipard\Module\World\Validations\ValueRules;
use Shipard\Module\World\Validations\ValueRulesRegistry;
use Shipard\Module\World\Validations\Iban;
use Shipard\Module\World\Validations\DomesticAccount;

// Podle druhu a země (normalizátor canonicalu, porovnávač)
ValueRules::normalize(ValueRules::PHONE, '577 123 456', 'cz');     // '+420577123456'
ValueRules::normalize(ValueRules::COMPANY_ID, '6947', 'cz');        // '00006947'
ValueRules::isValid(ValueRules::IBAN, 'CZ6508000000192000145399');  // true

// Čitelný tvar jen pro zobrazení (D21) — nikdy se neukládá
ValueRules::format(ValueRules::ZIP, '76001', 'cz');                  // '760 01'
ValueRules::format(ValueRules::IBAN, 'CZ6508000000192000145399');    // 'CZ65 0800 0000 1920 0014 5399'
ValueRules::format(ValueRules::PHONE, '577 123 456', 'cz');          // '+420 577 123 456'
ValueRules::ibanFromAccountNumber('19-2000145399/0800', 'cz');       // 'CZ6508000000192000145399' (jen tvar, bez mod 11)

// Přímo přes třídy
$rules = ValueRulesRegistry::forCountry('cz');
$rules->isValidCompanyId('52654150');                               // true
$account = DomesticAccount::parse('19-2000145399/0800');
$rules->ibanFromDomesticAccount($account);                          // 'CZ6508000000192000145399'
Iban::isValid('CZ6508000000192000145399');                          // mod 97 + délka + mod 11 uvnitř
```

| Třída | Role |
|---|---|
| `CountryValueRules` | rozhraní pravidel jednoho státu — to, co se mezi státy liší |
| `AbstractCountryValueRules` | výchozí chování: trim, žádná kontrola, žádná předvolba |
| `GenericValueRules` | stát bez třídy, neznámá nebo chybějící země |
| `CzSkValueRulesBase`, `CzValueRules`, `SkValueRules` | IČO s kontrolním součtem, PSČ 5 číslic, účet `[předčíslí-]číslo/kód` s mod 11, BBAN ↔ IBAN 24 znaků |
| `DeValueRules` | USt-IdNr s kontrolní číslicí ISO 7064 MOD 11,10, PSČ 5 číslic, IBAN 22 znaků |
| `ValueRulesRegistry` | výběr pravidel podle země (`forCountry`), prefixu IBAN (`forIban`) nebo prefixu DIČ (`forVatPrefix`) |
| `ValueRules` | jeden vstup podle druhu hodnoty (`ValueRules::PHONE`, …) a země: `normalize()`, `isValid()`, `format()`, `ibanFromAccountNumber()` |
| `ValueChecks`, `ValueFinding` | kontroly s důvodem (#118 D13, D19): kód nálezu, hodnota, česká věta; `message(vlastník)` skládá „IČO dodavatele „x“ nemá platný kontrolní součet.“ i „IČO „x“ …“ — jediný zdroj textů pro validátor canonicalu i formuláře adresáře |
| `BankAccountKey` | klíč účtu pro párování z hodnoty (ne ze sloupce): IBAN cz / sk i tuzemské číslo → `bban:`, jiný IBAN → `iban:`, jinak `raw:` (D16, D23) |
| `BankAccountShape` | tvar trojice číslo účtu / IBAN / BIC při uložení a přesun IBAN ze sloupce čísla účtu do `iban` (D20) |
| `Iban`, `Bic`, `VatId`, `Phone`, `DomesticAccount`, `Contact`, `PaymentSymbols`, `Country` | obecná pravidla a primitiva |

## Pravidla

**Obecná (bez země)**

| Hodnota | Normalizace | Validace |
|---|---|---|
| země | malými, trim | — |
| IBAN | velkými, bez mezer | `^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$` + ISO 7064 MOD 97-10 + pravidlo státu podle prefixu |
| BIC | velkými, bez mezer | `^[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$` |
| DIČ / VAT ID | velkými, bez mezer, teček a pomlček; jen číslice + stát s doplněním → prefix | `^[A-Z]{2}[0-9A-Z]{2,13}$`; prefix s třídou → pravidlo třídy |
| e-mail | trim, malými | — |
| web | malými, bez schématu a koncového lomítka | — |
| telefon | `+` a číslice (viz níže) | — |
| VS, KS, SS | bez mezer | — |
| platební reference | bez mezer, velkými | — |
| tuzemský účet | tvar `…/kód` bez mezer a vedoucích nul; jiný tvar jen bez mezer | tvar `…/kód` → mod 11 bez ohledu na zemi; stát s tuzemským tvarem jiný tvar neuznává |

**Čitelný tvar (D21):** `ValueRules::format()` nejdřív normalizuje, pak
PSČ cz / sk `760 01`, IBAN po čtveřicích, telefon cz / sk
`+420 577 123 456`, de `+49 301234567` (skupiny německých čísel závisí
na předčíslí oblasti); ostatní druhy, jiný stát a nekanonický tvar
= normalizovaná hodnota. Používají ho tisky a detail osoby; formuláře
ukazují uložený (kanonický) tvar. Výstup se nikdy neukládá.

**Telefon (D12, D26):** mezery a interpunkce pryč, `(0)` pryč, `00` na
začátku → `+` (jen když za ním nenásleduje `0`). Číslo bez `+` u státu
s třídou: zahodit národní předčíslí a doplnit `+` + předvolku; číslo
začínající předvolkou státu v plné délce se bere jako mezinárodní.
U státu bez třídy zůstává bez předvolby. Předvolba každé třídy je
konstanta; shodu s `world.base.countries` (`phonePrefixes[0]`) hlídá
`PhonePrefixGuardTest`.

Normalizuje se **jen hodnota, která vypadá jako jedno číslo** (D26);
jinak zůstává beze změny (jen oříznutá) a nález nevzniká: víc než
15 číslic (E.164) vždy beze změny; mezinárodní zápis (`+`, `00`) jen
se 7–15 číslicemi; národní zápis u státu se známou délkou jen když po
odebrání národního předčíslí délka sedí (cz, sk 9 číslic), u státu bez
délky (de) jen když výsledek padne do okna 7–15 číslic; stát bez
předvolby jen číslice. Dvě čísla v jednom poli (`577 123 456, 777 888
999`) ani číslo s linkou (`577 123 456 linka 25`) se tak neslijí;
písmena kolem jednoho čísla (`tel. 577 123 456`) normalizaci nebrání.

**Státy s třídou**

| Země | PSČ | IČO | DIČ | Účet / IBAN | Telefon |
|---|---|---|---|---|---|
| cz | 5 číslic bez mezer | 8 číslic (samé číslice do 8 míst dostanou vedoucí nuly), kontrolní součet | `CZ` + 8–10 číslic; jen číslice → doplní `CZ`; 8místné = `CZ` + IČO (9–10místné = fyzická osoba, vazba neplatí) | `[předčíslí-]číslo/kód`, mod 11 předčíslí i čísla; BBAN = kód 4 + předčíslí 6 + číslo 10; IBAN 24 znaků, platný jen s platným číslem uvnitř | `+420`, bez národního předčíslí, národní číslo 9 číslic |
| sk | 5 číslic | jako cz | `SK` + 10 číslic | jako cz, prefix `SK` | `+421`, předčíslí `0`, 9 číslic |
| de | 5 číslic | jak na dokladu (trim) | `DE` + 9 číslic, kontrolní číslice ISO 7064 MOD 11,10 | IBAN 22 znaků, bez tuzemského tvaru | `+49`, předčíslí `0`, proměnlivá délka (výsledek 7–15 číslic) |

## Testy

Sdílený fixture `tests/Fixtures/World/value-cases.jsonc` (sekce
`normalize`, `validate`, `repair`, `format`) čte PHPUnit
(`tests/Unit/Module/World/Validations/ValueCasesFixtureTest`) i Python
generátoru korpusu (`tools/invoice-generator/tests/test_checks.py`, jen
kontroly, které `checks.py` umí; položka s `"python": false` je mimo
Python; sekci `format` čte jen PHP). Jedna sada případů = jedna pravda
pro obě implementace (D6).

## Závislosti

- `world.base` — číselník zemí (předvolby ke strážci testem)

## Přidání dalšího státu

1. Třída `XxValueRules extends AbstractCountryValueRules` (nebo
   `CzSkValueRulesBase`, sdílí-li tvar účtu) — přepsat jen to, co stát
   určuje: předvolba, národní předčíslí, PSČ, IČO, DIČ, IBAN.
2. Záznam v `ValueRulesRegistry::CLASSES`.
3. Případy do `tests/Fixtures/World/value-cases.jsonc`.

Normalizátor a validátor canonicalu, resolvery ani porovnávač se nemění —
žádné `if (cz)` mimo tento modul.

## Kdo knihovnu používá

- canonical dokladu: normalizátor a validátor v `core.exchange`
  (`docs/exchange-format.md` → Normalizace a validace hodnot);
- adresář při uložení (#118 D18–D20): `base.persons` `Values\PersonValues`
  (osoba, adresa, kontakt, účet osoby) a `economy.codebooks`
  `BankAccountDocument` — kanonický tvar a kontroly D19 (partner varování,
  vlastní firma chyba), `docs/exchange-format-persons.md` § 5–7;
- zobrazení (D21): Twig filtry `zip`, `iban`, `phone` v tisku
  (`docs/prints.md`) a detail osoby;
- párování účtů přes `BankAccountKey` (D16, D23): `BankAccountResolver`,
  protiúčet a vlastní účet z výpisu (`economy.bank`), průvodce nastavením;
- QR platba a ISDOC: `ValueRules::ibanFromAccountNumber()`; ABO mod 11
  v `ParserUtils`;
- existující data adresáře: `shpd-ds values-normalize` (#118 D22,
  `docs/cli.md`) — `base.persons` `Values\DirectoryValuesNormalizer` volá
  tytéž funkce jako uložení nad všemi tabulkami adresáře.

## Mimo rozsah

Ověření v registrech (ARES, VIES) je #142.
