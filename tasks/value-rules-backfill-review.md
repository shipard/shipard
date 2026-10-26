# `values-normalize`: podrobný výpis a postupný běh, účet bez čísla, QR jen s platným IBAN

**Stav:** naplánováno — rozhodnutí schválená 2026-10-26 (#118 D27–D29), neimplementováno; pustit **před** ostrým během `values-normalize` na kopiích reálných dat


## Kontext / Cíl

`values-normalize --dry-run` na největší kopii reálných dat (2026-10-26)
ukázal 1 446 změn a 29 nevalidních hodnot. Vyhodnocení (read-only,
agregovaně):

- **Nálezy jsou skutečné chyby starých dat** — znaky navíc na konci čísla
  účtu (`<`, `;`), písmena z české klávesnice místo číslic, číslo účtu bez
  kódu banky, IBAN bez jedné číslice, IBAN se správnou kontrolní číslicí
  z čísla účtu, které neprojde mod 11, písmena v IČO fyzické osoby, IČO
  s překlepem (u jedné firmy je správné IČO v DIČ), PSČ se 3, 4 nebo 6
  číslicemi. Falešný poplach žádný.
- **Výpis nestačí k opravě ani ke kontrole:** ID je ID řádku tabulky
  (u adres a účtů chybí osoba), chybí hodnota a důvod; změny nejsou vidět
  vůbec — ostrý běh stovek změn se nedá zodpovědně pustit naslepo.
- **D20 vyrábí účty, které formulář neuloží:** u zahraničního IBAN ve
  sloupci čísla účtu zůstane číslo účtu prázdné, formulář účtu osoby ho ale
  vyžaduje (v jedné kopii 5 takových + 1 účet bez čísla už dnes).
- **QR platba vezme neplatný IBAN:** `SpaydGenerator::resolveIban()`
  kontroluje u uloženého IBAN jen tvar. Aktivní vlastní účet v jedné kopii
  má platné číslo účtu a IBAN s chybou ve dvou znacích — tisk i QR nesou
  chybný IBAN.

**Cíl:** výpis, podle kterého jde každou změnu zkontrolovat a každý nález
dohledat a opravit; běh po druzích hodnot; účet osoby s IBAN bez čísla
účtu; QR jen s platným IBAN.

## Návaznost

- **#118** D20, D22, D24 (pravidla backfillu), D19 (texty nálezů), D4
  (oprava z redundance), D27–D29 (níže)
- `tasks/value-rules-backfill.md`, `tasks/value-rules-phase2.md`,
  `tasks/value-rules-phone-guard.md`

## Před implementací přečti

- `src/Command/DataSource/ValuesNormalizeCommand.php`,
  `modules/base/persons/src/Values/DirectoryValuesNormalizer.php`,
  `PersonValues.php`, `PersonContext.php`
- `modules/world/validations/src/ValueChecks.php`, `ValueFinding.php`,
  `BankAccountShape.php`, `ValueRules.php`
- `modules/core/exchange/src/Values/CanonicalValueNormalizer.php` (oprava
  D4 — vzor pro návrhy oprav)
- `modules/base/persons/forms/base_persons_bank_accounts.jsonc`,
  `tables/base_persons_bank_accounts.jsonc` (`displayPattern`),
  `src/PersonBankAccountDocument.php`, `src/BankAccountsLookup.php`
- `modules/economy/codebooks/src/BankAccountDocument.php` (pravidlo „číslo
  účtu nebo IBAN“ — vzor)
- `modules/docs/core/src/Prints/PaymentQr/SpaydGenerator.php`,
  `PaymentQrInput.php`, `modules/docs/core/prints/_layout/messages.jsonc`
  (`message.qrNoAccount`)
- `docs/cli.md` → `values-normalize`

## Rozhodnutí

- **D27 — Podrobný výpis a postupný běh.**
  - `--verbose` (text i `--json`): u každé **změny** tabulka · ID ·
    osoba (ID, kód osoby, název; u vlastního účtu kód a název účtu) ·
    sloupec · hodnota před → po; přesun IBAN (D20) jako jedna položka se
    všemi dotčenými sloupci. U každého **nálezu** totéž plus hodnota,
    kód a srozumitelný důvod a, kde to jde z redundance, **návrh opravy**
    (nic se neopravuje): IČO z 8místného českého DIČ (`CZ` + IČO), IBAN
    z platného tuzemského čísla účtu a naopak (D4).
  - Bez `--verbose` výstup beze změny: počty, ID, kódy — bez osobních
    údajů (D22). `--verbose` obsahuje osobní údaje — jen na obrazovku,
    nikdy do issue (nápověda příkazu a `docs/cli.md` to říkají).
  - `--only=<druhy>` (čárkami): `phone`, `email`, `web`, `zip` (PSČ
    a země adresy), `ids` (IČO, DIČ, daňové číslo), `accounts` (číslo
    účtu, IBAN, BIC včetně D20). Ostatní sloupce se v běhu nemění ani
    nepočítají; nálezy jen pro vybrané druhy. Bez `--only` všechno jako
    dnes.
  - Důvody nálezů rozlišit podle příčiny (texty ve `world.validations`,
    sdílí je formuláře i canonical): u IBAN zvlášť „chybná délka“,
    „chybná kontrolní číslice“ a „kontrolní číslice sedí, ale číslo účtu
    v IBANu neprojde kontrolou“; u čísla účtu „chybí kód banky“,
    „nepovolené znaky“, „neprojde kontrolou“.
- **D28 — Účet osoby: číslo účtu nebo IBAN.** Formulář i
  `PersonBankAccountDocument::validate()` vyžadují aspoň jeden z údajů
  (chyba, stejný text jako `BankAccountDocument`); `account_number`
  přestane být ve formuláři povinné. Účet bez čísla se všude zobrazuje
  IBANem (`displayPattern`, lookup, snapshot na dokladu, tisk).
- **D29 — QR platba jen s platným IBAN.** `resolveIban()`: uložený IBAN
  jen platný (`world.validations`); jinak IBAN spočítaný z platného
  tuzemského čísla účtu; jinak QR chybí a tisk ukáže hlášku jako dnes
  (`message.qrNoAccount`, text upravit, aby zahrnul i neplatný IBAN).
  Vytištěný IBAN v platebních údajích se nemění — tiskne se, co je
  uložené; oprava dat je na člověku (nález D19).

## Kroky

1. D28 + D29 → commit
   `fix(persons, prints): účet osoby s číslem účtu nebo IBAN, QR platba jen s platným IBAN (#118 D28, D29)`
2. Důvody nálezů (D27) ve `world.validations` + testy → commit
   `feat(world): důvod nálezu IBAN a čísla účtu podle příčiny (#118 D27)`
3. `--verbose`, `--only`, návrhy oprav → commit
   `feat(persons): values-normalize --verbose a --only, návrhy oprav (#118 D27)`
4. `docs/cli.md`, `docs/exchange-format-persons.md`, help (formulář účtu),
   `**Stav:**`, `python3 scripts/tasks-index.py` → commit
   `docs: values-normalize --verbose a --only, účet bez čísla (#118 D27–D29)`

## Hotovo když

- [ ] D28: účet osoby jen s IBAN se uloží, bez obou údajů ne; účet bez
      čísla se v lookupu, na dokladu i na tisku ukazuje IBANem.
- [ ] D29: neplatný uložený IBAN + platné číslo účtu → QR s IBAN z čísla;
      neplatný IBAN bez platného čísla → bez QR s hláškou; platný IBAN beze
      změny (stávající testy QR a ISDOC).
- [ ] D27: testy výpisu — změna (před → po, osoba), přesun D20 jako jedna
      položka, nález s důvodem a návrhem opravy (IČO z DIČ, IBAN z čísla,
      číslo z IBAN), bez `--verbose` žádné hodnoty ani jména, `--only`
      mění a počítá jen vybrané druhy, `--only` s neznámým druhem → chyba
      volání.
- [ ] Na volných zdrojích dat `--verbose --dry-run` a `--only=phone` ostrý
      běh + druhý běh 0 změn.
- [ ] Na kopiích reálných dat **jen `--dry-run`**; do závěrečné zprávy jen
      agregovaně (počty po druzích, počty návrhů oprav) — **žádný výstup
      `--verbose` z reálných dat do tasku, commitu ani zprávy**.
- [ ] `vendor/bin/phpunit --filter` na dotčené testy; `docs/cli.md`,
      help, `**Stav:**`, index tasků.

## Pasti

- **Osobní údaje:** `--verbose` je jediný výstup s hodnotami; testy
  hlídají, že výchozí text i JSON hodnoty neobsahují.
- **Návrh opravy se nikdy neprovede** — ani v ostrém běhu; jen výpis.
- **`--only=accounts` a D20:** přesun IBAN je jedna změna účtu; počty
  ostatních druhů se nesmí započítat.
- **QR:** neměnit tvar platného IBAN ani pořadí polí SPAYD (zlaté výstupy).
- **Kopie reálných dat jen `--dry-run`.**
