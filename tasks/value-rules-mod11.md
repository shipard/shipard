# Mod 11 jako podezření, ne jistá chyba

**Stav:** naplánováno — rozhodnutí schválené 2026-10-27 (#118 D30), neimplementováno


## Kontext / Cíl

`values-normalize --verbose` na kopii reálných dat ukázal čísla účtů
a IBAN, které neprojdou kontrolou mod 11, a přitom jsou oficiálně
zveřejněné v registru plátců DPH. Mod 11 je podle vyhlášky ČNB
169/2011 Sb. pravidlo pro **vytvoření** čísla účtu; buď jsou v registru
chyby, nebo existují čísla vzniklá mimo pravidlo — ověřit to nejde.
Neprojitý mod 11 je tedy **podezření**, kdežto špatná kontrolní číslice
IBAN (MOD 97-10) nebo délka je **jistá chyba přepisu**.

Dnešní pravidla jsou na dvou místech zbytečně tvrdá:

- **IBAN se správnou kontrolní číslicí a délkou** je neplatný, když číslo
  účtu uvnitř neprojde mod 11 (`CzSkValueRulesBase::isValidIban()`); u
  účtu s IBAN i číslem vzniknou dva nálezy, i když IBAN sám je v pořádku.
- **Vlastní účty** (D19): neprojitý mod 11 je chyba — skutečný účet s
  takovým číslem by nešel uložit.

**Cíl:** jeden nález „podezřelé číslo účtu“ pro neprojitý mod 11,
varování všude (u vlastních účtů taky); chybou dál jen jisté chyby. Oprava
D4 a dopočet IBAN dál jen z čísla, které projde vším.

## Návaznost

- **#118** D3, D4, D13, D19, D27, D29, D30 (níže)
- `tasks/value-rules.md`, `tasks/value-rules-phase2.md`,
  `tasks/value-rules-backfill-review.md`

## Před implementací přečti

- `modules/world/validations/src/Iban.php` (`isValid()`, `checksumOk()`),
  `CzSkValueRulesBase.php` (`isValidIban()`), `DomesticAccount.php`,
  `ValueChecks.php` (`accountNumber()`, `iban()`, `ibanCountryReason()`,
  `bankAccount()`, `ibanImpliedByAccountNumber()`), `ValueFinding.php`,
  `ValueRules.php`, `BankAccountKey.php`
- `modules/core/exchange/src/Values/CanonicalValueNormalizer.php` (oprava
  D4, ř. ~217–226), `CanonicalValueValidator.php`
- `modules/docs/core/src/Prints/PaymentQr/SpaydGenerator.php`
  (`resolveIban()`, D29)
- `modules/economy/codebooks/src/BankAccountDocument.php`,
  `modules/base/persons/src/PersonBankAccountDocument.php` a místo, kde
  D19 rozhoduje chyba × varování u vlastní firmy (grep)
- `modules/base/persons/src/Values/DirectoryValuesNormalizer.php`
  (výpis nálezů a návrhů oprav)
- `tests/Fixtures/World/value-cases.jsonc`

## Rozhodnutí

- **D30 — Mod 11 jako podezření.**
  - **IBAN** je platný, když má tvar, platnou kontrolní číslici a délku
    státu. Číslo účtu uvnitř (cz, sk) se kontroluje zvlášť.
  - **Nový kód nálezu `account_number_checksum`** — tuzemské číslo účtu
    má správný tvar, ale neprojde mod 11 (předčíslí nebo číslo). Hlásí se
    jednou na účet: u čísla účtu, nebo — když je jen IBAN — u IBAN
    s textem „číslo účtu uvnitř … neprojde kontrolou mod 11 — ověřte“.
    Když účet má obojí a jde o tentýž účet, nález jen u čísla účtu.
  - `account_number_invalid` zůstává pro jisté chyby tvaru (nepovolené
    znaky, chybějící kód banky, jiný tvar); `iban_invalid` pro tvar,
    kontrolní číslici a délku.
  - **Závažnost:** `account_number_checksum` je **varování všude** —
    partner, vlastní firma, vlastní účet v číselníku i návrh dokladu
    (v návrhu dál strop pásma `review`, D15 beze změny). Ostatní kódy
    beze změny (u vlastních chyba).
  - **Oprava D4, dopočet IBAN pro QR (D29) a návrhy oprav (D27)** dál jen
    z hodnoty, která projde vším **včetně** mod 11 — nikdy nedopočítávat
    z podezřelého čísla.
  - QR (D29): uložený IBAN stačí platný podle nového pravidla (tvar,
    kontrolní číslice, délka).

## Kroky

1. Knihovna: `Iban::isValid()` bez mod 11 uvnitř; přísná varianta pro
   opravy (např. `Iban::isValidWithDomesticAccount()`) — **projít všechny
   volající** (`grep --include=*.php 'Iban::isValid\|isValidIban'`)
   a u každého rozhodnout: oprava a dopočet přísně, nálezy a QR podle D30;
   nový kód a texty v `ValueFinding` / `ValueChecks`; fixture → commit
   `fix(world): mod 11 jako podezření — IBAN s platnou kontrolní číslicí je platný, nový nález account_number_checksum (#118 D30)`
2. Závažnost u vlastní firmy a vlastních účtů, canonical, výpis
   `values-normalize` (nový kód se vypisuje, návrh opravy se nevytváří) →
   commit
   `fix(persons, codebooks): podezřelé číslo účtu jen varování i u vlastních účtů (#118 D30)`
3. `docs/exchange-format.md` (tabulka kódů nálezů, D4), README modulu,
   help k formuláři účtu (`help/osoby/…`), `**Stav:**`,
   `python3 scripts/tasks-index.py` → commit
   `docs: nález account_number_checksum (#118 D30)`

## Hotovo když

- [ ] IBAN s platnou kontrolní číslicí, jehož číslo uvnitř neprojde mod 11:
      platný IBAN, jeden nález `account_number_checksum`; s číslem účtu
      téhož účtu taky jen jeden nález (u čísla).
- [ ] Vlastní účet s podezřelým číslem se uloží s varováním; s neplatnou
      kontrolní číslicí IBAN, nepovolenými znaky nebo bez kódu banky dál ne.
- [ ] Návrh dokladu s podezřelým číslem: varování a strop `review` jako
      dnes; oprava D4 z podezřelého čísla nevznikne (test).
- [ ] QR: platný IBAN s podezřelým číslem uvnitř se použije; dopočet
      IBAN z podezřelého čísla ne.
- [ ] `values-normalize --dry-run` na volném zdroji dat: nový kód ve výpisu,
      bez návrhu opravy; na kopiích reálných dat jen `--dry-run`, do zprávy
      jen počty po kódech.
- [ ] `vendor/bin/phpunit --filter` na dotčené testy (World*, ValueChecks*,
      Iban*, CanonicalValue*, PersonBankAccountDocument*,
      BankAccountDocument*, Spayd*, DirectoryValuesNormalizer*,
      CorpusValueRules*); `python3 -m unittest` generátoru; strážce korpusu.
- [ ] Dokumentace, help, `**Stav:**`, index tasků.

## Pasti

- **Do fixture, testů, dokumentace ani commitů nepatří skutečná čísla
  účtů z reálných dat** — případy jen vymyšlené (číslo se správným tvarem,
  které mod 11 neprojde; IBAN k němu s dopočtenou platnou kontrolní
  číslicí).
- **Korpus:** identity korpusu jsou validní; strážce (validace očekávání
  bez nálezů) musí dál projít — výpočty evaluace se nemění.
- **`BankAccountKey`** se nemění (`domesticAccountFromIban()` validitu
  nevyžaduje) — párování zůstává stejné.
- Python `checks.py` mod 11 nemění (generátor dál vyrábí jen validní
  účty); `test_checks.py` čte jen kontroly, které `checks.py` umí.
