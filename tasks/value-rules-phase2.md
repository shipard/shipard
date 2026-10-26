# Normalizace a validace hodnot — fáze 2: adresář, zobrazení, zbylá místa

**Stav:** hotovo — implementováno 2026-10-26 v 6 commitech (formátovač, normalizace při uložení, validace ve formulářích, tisky a detail osoby, zbylá místa, dokumentace); odchylky a ověření níže


## Kontext / Cíl

Fáze 1 (`tasks/value-rules.md`) normalizuje a validuje canonical dokladu.
Adresář ale dál ukládá hodnoty v libovolném tvaru a zapisuje se do něj
několika cestami:

| Tabulka | Cesty zápisu |
|---------|--------------|
| `base_persons_persons` | `PersonDocument` (formulář, `PersonApplier` přes gateway, průvodce nastavením) |
| `base_persons_addresses` | formulář (bez třídy dokumentu), `PersonDocument::insertAddress()`, `PersonApplier::insertSubRecord()` / `updateSubRecord()` |
| `base_persons_contacts` | `ContactDocument`, `PersonApplier` (podřízené záznamy) |
| `base_persons_bank_accounts` | formulář (bez třídy dokumentu), `PersonApplier` (podřízené záznamy), `DocumentApplier` (`bankCreate`, přímý insert) |
| `economy_codebooks_bank_accounts` | `BankAccountDocument` (formulář, `SetupController`) — vlastní regex IBANu |

Tisky (`modules/docs/core/prints/_partials/party.html.twig`,
`payment.html.twig`) vypisují PSČ, telefon a IBAN, jak jsou uložené. Po
normalizaci by se na faktuře objevilo `76001` a `+420577123456` — část
dat je kanonická už od fáze 1 (osoby a účty založené z návrhů).

Agregovaný pohled do kopií reálných dat: IBAN ve sloupci čísla účtu má
9–37 % účtů partnerů (většinou české IBAN), sloupec `iban` je skoro
prázdný; telefony osob skoro všechny v národním tvaru; osob bez adresy
je minimum; vlastní účty mají IBAN v pořádku.

**Cíl:** všechny zápisy do adresáře ukládají kanonický tvar (D18, D20),
formuláře hlásí nevalidní hodnoty (D19), tisky a detail osoby ukazují
čitelný tvar (D21) a zbylé vlastní implementace přejdou na
`world.validations` (D23).

Mimo tento task: normalizace **existujících** dat (`values-normalize`,
D22, `tasks/value-rules-backfill.md` — pustí se až po tomto tasku).

## Návaznost

- **#118** D1–D17 (fáze 1), D18–D23 (komentář 2026-10-26)
- `tasks/value-rules.md` — knihovna `world.validations`, normalizátor
  a validátor canonicalu, `BankAccountKey`
- navazuje `tasks/value-rules-backfill.md` (D22)

## Před implementací přečti

- `modules/world/validations/README.md`, `src/ValueRules.php`,
  `src/ValueRulesRegistry.php`, `tests/Fixtures/World/value-cases.jsonc`
- `docs/exchange-format.md` → Normalizace a validace hodnot;
  `docs/exchange-format-persons.md`
- `docs/edit-forms.md` → Warningy (neblokující); `docs/table-definitions.md`;
  `docs/architecture.md` (vrstvy — `src/Core` vs. moduly)
- `docs/prints.md`, `src/Core/Prints/Twig/PrintTwigExtension.php`
  (+ test), `modules/docs/core/prints/_partials/party.html.twig`,
  `payment.html.twig`, `parties.html.twig`, `tests/Fixtures/Prints/`
- `modules/base/persons/module.jsonc` (`documentClasses`,
  `addressTypes` — sídlo = `address_type` 1), `src/PersonDocument.php`,
  `src/ContactDocument.php`, `src/PersonsViewer.php` (detail)
- `modules/core/exchange/src/Person/PersonApplier.php`
  (`insertSubRecord()`, `updateSubRecord()`),
  `src/Document/DocumentApplier.php` (`bankCreate`),
  `src/Resolve/AddressResolver.php` (`buildCreatePayload()`),
  `src/Values/CanonicalValueValidator.php` (texty nálezů)
- `modules/economy/codebooks/src/BankAccountDocument.php`,
  `src/Api/Controller/SetupController.php`
- `modules/economy/bank/src/Import/PartnerResolver.php`,
  `Import/Parsers/ParserUtils.php`
- `modules/docs/core/src/Prints/PaymentQr/CzIbanCalculator.php`,
  `SpaydGenerator.php`, `modules/core/exchange/src/Isdoc/IsdocWriter.php`
- `help/osoby/zalozeni-osoby.md`, `docs/help-authoring.md`

## Rozhodnutí

Závazné znění je v komentáři #118 (2026-10-26).

- **D18 — Normalizace při uložení.** Jedna funkce pro každou tabulku
  adresáře, volaná ze **všech** cest zápisu (tabulka výše). Adresy a účty
  osob dostanou vlastní třídu dokumentu, přímé zápisy se napojí. Země
  osoby = země adresy sídla (`address_type` 1), jinak první adresy, jinak
  země zdroje dat (`DataSourceConfig::getCountry()`).
- **D19 — Validace ve formulářích.** Partneři: varování (žlutý banner),
  uložení neblokuje. Vlastní firma (`is_own`, její adresy a účty)
  a vlastní účty v číselníku: chyba.
- **D20 — IBAN ve sloupci čísla účtu** se přesune do `iban`; u cz a sk se
  do `account_number` dopočte tuzemské číslo, jinak zůstane prázdné. Číslo
  účtu bez IBANu se IBANem nedoplňuje.
- **D21 — Zobrazení.** Uložený tvar je kanonický; čitelný tvar dělá
  formátovač knihovny. Tisky a detail osoby; formuláře ukazují uložený
  tvar. Snapshoty na dokladech se nemění, formátuje se při tisku.
- **D23 — Zbylá místa na `world.validations`:** `BankAccountDocument`,
  párování protiúčtu u importu výpisů, `ParserUtils::mod11`,
  `CzIbanCalculator`.

## 1. Formátovač (D21)

`ValueRules::format(string $kind, string $value, ?string $country): string`
v `world.validations` — nejdřív normalizuje (stejná pravidla jako
`normalize()`), pak:

| Hodnota | Tvar pro zobrazení |
|---------|--------------------|
| PSČ cz, sk | `760 01` |
| PSČ jinde | beze změny |
| IBAN | po čtveřicích (`CZ65 0800 0000 1920 0014 5399`) |
| telefon cz, sk (9 číslic) | `+420 577 123 456` |
| telefon de | předvolba oddělená mezerou, národní číslo vcelku (`+49 301234567`) — skupiny německých čísel závisí na předčíslí oblasti |
| telefon jinde / nekanonický | beze změny |
| ostatní (IČO, DIČ, číslo účtu, BIC) | kanonický tvar |

Případy formátování doplnit do `value-cases.jsonc` (sekce `format`);
Python je číst nemusí.

## 2. Normalizace při uložení (D18, D20)

V `base.persons` jedna čistá třída (např. `PersonValues`) nad názvy
sloupců: osoba (`company_id`, `vat_id`, `tax_id`, `phone`, `email`,
`web`), adresa (`zip`, `country`), kontakt (`email`, `phone`), bankovní
účet (`account_number`, `iban`, `bic`). Normalizuje jen přítomné klíče
(částečný update), `null` a prázdné nechá.

**Bankovní účet (D20)** — po normalizaci tvaru:

| Stav | Výsledek |
|------|----------|
| `account_number` má tvar IBAN, `iban` prázdný | `iban` = ten IBAN; `account_number` = tuzemské číslo (cz, sk), jinak `''` |
| `account_number` má tvar IBAN, `iban` je tentýž účet (`BankAccountKey`) | `account_number` jako výše |
| `account_number` má tvar IBAN, `iban` je jiný účet | beze změny (validace D19 nahlásí nesoulad) |
| jinak | jen tvary obou sloupců |

**Země** (D18): u osoby z adres v témže zápisu (nová osoba nese
`addresses`), jinak z uložených adres; adresa má vlastní `country`;
kontakt a účet berou zemi své osoby; vlastní účet v číselníku zemi
zdroje dat.

**Napojení:**

- `PersonDocument::beforeSave()` — sloupce osoby + čekající adresy;
  `insertAddress()` normalizuje řádek.
- `ContactDocument` — `beforeSave()`.
- Nové třídy dokumentů pro `base_persons_addresses`
  a `base_persons_bank_accounts` (registrace v `documentClasses`) —
  `beforeSave()` + `validate()` (D19); formuláře adres a účtů je tím
  dostanou.
- `PersonApplier::insertSubRecord()` / `updateSubRecord()` — podle
  tabulky; u updatu PSČ bez `country` v payloadu vzít zemi z uloženého
  řádku.
- `DocumentApplier` — `bankCreate` před insertem.
- `BankAccountDocument` (číselník) — `beforeSave()` nahradí dnešní
  `strtoupper` IBAN / BIC.
- Na konci grepem (`--include=*.php`) ověřit, že žádný jiný zápis do
  pěti tabulek normalizaci neobchází; výsledek do závěrečné zprávy.

## 3. Validace ve formulářích (D19)

- `PersonDocument::validate()`: IČO, DIČ, nesoulad 8místného českého DIČ
  s IČO; adresy: PSČ (cz, sk); účty: číslo účtu, IBAN, BIC, nesoulad
  čísla účtu a IBAN. Kódy a texty jako D13 (sdílet texty
  s `CanonicalValueValidator`, ne kopírovat).
- Partner → `addWarning()`; vlastní firma (`is_own` osoby, u adresy
  a účtu podle osoby) → `addError()`.
- `BankAccountDocument` (vlastní účty): chyba u nevalidního IBAN, čísla
  účtu, BIC a nesouladu — nahradí dnešní regex. Zachovat dnešní pravidlo
  „aspoň jeden z údajů číslo účtu nebo IBAN“.
- Telefon, e-mail a web nálezy nevyrábí (D12).

## 4. Tisky a detail osoby (D21)

- Twig filtry `zip(country)`, `iban`, `phone(country)` delegující na
  `ValueRules::format()`; místo registrace podle vrstev
  (`docs/architecture.md`) — `PrintTwigExtension` v `src/Core`, nebo
  rozšíření dodané modulem, pokud jádro tisku na modulu záviset nesmí.
- `party.html.twig`: PSČ ve větvi bez `display_block`, telefon kontaktu
  (země z `party.address.country`); `payment.html.twig`: IBAN. Číslo
  účtu a BIC beze změny. `display_block` / `display_line` se tisknou, jak
  jsou.
- `PersonsViewer` detail: telefon osoby a kontaktů přes formátovač
  (země osoby podle D18). Výpis osob beze změny.
- Golden výstupy tisků (`tests/Fixtures/Prints/`) se změní jen
  v PSČ / IBAN / telefonu — změny projít a vyjmenovat v závěrečné zprávě.

## 5. Zbylá místa (D23)

- Import výpisů — `Import\PartnerResolver`: protiúčet páruje na účty
  osob přes `BankAccountKey` z obou sloupců (nahradí vlastní varianty);
  test: IBAN uložený ve sloupci čísla účtu se napáruje na tuzemské číslo
  z výpisu a naopak.
- `ParserUtils::mod11` → knihovna (chování parserů beze změny).
- `CzIbanCalculator` → knihovna (`DomesticAccount`, `ValueRulesRegistry`);
  QR platba (`SpaydGenerator`) i `IsdocWriter` dávají stejný výstup
  (stávající testy).
- Grepem dohledat další vlastní regexy / kontrolní součty IČO, DIČ, IBAN
  a čísla účtu v modulech; převést, nebo v závěrečné zprávě uvést proč ne.

## 6. Dokumentace

- `modules/world/validations/README.md` — `format()`.
- `docs/exchange-format-persons.md` a `docs/exchange-format.md`
  (Normalizace a validace hodnot) — adresář normalizovaný při uložení,
  pravidlo D20.
- `docs/prints.md` — nové filtry.
- `help/osoby/zalozeni-osoby.md` — Shipard sjednotí zápis, upozorní na
  neplatné IČO / DIČ / účet; u vlastní firmy a vlastních účtů uložení
  nedovolí. Názvy ověřit ve zdroji popisků; `python3 scripts/help-index.py`.
- `CLAUDE.md` — doplnit stávající větu o `world.validations`: zobrazení
  přes `ValueRules::format()` / Twig filtry, uložený tvar je kanonický.

## Kroky

1. Formátovač + fixture → commit
   `feat(world): čitelný tvar PSČ, IBAN a telefonu pro zobrazení (#118 D21)`
2. Normalizace při uložení, třídy dokumentů adres a účtů, D20 → commit
   `feat(persons): normalizace údajů osob a účtů při uložení (#118 D18, D20)`
3. Validace ve formulářích → commit
   `feat(persons): kontrola IČO, DIČ, PSČ a účtů ve formulářích (#118 D19)`
4. Tisky a detail osoby → commit
   `feat(prints): PSČ, IBAN a telefon v čitelném tvaru (#118 D21)`
5. Zbylá místa → commit
   `refactor: účty, QR platba a import výpisů na world.validations (#118 D23)`
6. Dokumentace, help, `CLAUDE.md`, `**Stav:**`,
   `python3 scripts/tasks-index.py` → commit
   `docs: normalizace údajů adresáře a zobrazení (#118 fáze 2)`

## Hotovo když

- [x] Formátovač: testy nad fixture (PSČ, IBAN, telefon cz / sk / de,
      nekanonický vstup).
- [x] Každá cesta zápisu z tabulky výše ukládá kanonický tvar (test na
      cestu); D20 všechny čtyři stavy; částečný update adresy bez země.
- [x] Formulář partnera s neplatným IČO / IBAN uloží a vrátí varování;
      vlastní firma a vlastní účet s neplatnou hodnotou neuloží.
- [x] Tisk faktury vydané: PSČ, IBAN a telefon v čitelném tvaru; golden
      výstupy změněné jen v těchto hodnotách.
- [x] Detail osoby: telefon osoby i kontaktů čitelně.
- [x] Import výpisů páruje protiúčet i na IBAN uložený ve sloupci čísla
      účtu; QR platba a ISDOC beze změny výstupu.
- [x] `vendor/bin/phpunit --filter` na dotčené testy (World*, Person*,
      Contact*, Address*, BankAccount*, PersonApplier*, DocumentApplier*,
      PrintTwigExtension*, *Print*, PartnerResolver*, Parser*,
      CzIbanCalculator*, Spayd*, IsdocWriter*, Setup*); celá sada lokálně
      na konci.
- [x] `dataset-verify` nad třemi sadami korpusu a demo sadou po
      přeseedování (volné zdroje dat): kontroly `ok`, snímek deníku beze
      změny.
- [x] Dokumentace, help, `CLAUDE.md`, `**Stav:**`, index tasků.
- [x] **Žádná normalizace existujících dat** — to je
      `tasks/value-rules-backfill.md`.

## Pasti

- **Nic nehádat** (D1): normalizace mění jen tvar; IBAN z čísla účtu se
  nedopočítává; neznámá země → obecná pravidla.
- **Snapshoty dokladů, `display_line` a `display_block` adres** se nemění.
- **Chyba D19 blokuje i appliery.** `PersonApplier` ukládá osobu přes
  `PersonDocument`, takže vlastní firma s neplatným IČO by neprošla ani
  importem ze starého Shipardu. Když se to na volném zdroji objeví, je to
  nález do závěrečné zprávy, ne důvod chybu oslabit.
- **Částečný update** (`updateSubRecord()`, formulář jednoho pole):
  normalizovat jen přítomné sloupce, zemi dohledat z uloženého řádku.
- **Formátovač je jen pro zobrazení** — nikdy neukládat jeho výstup.
- **Kopie reálných dat** jen číst (read-only SQL, agregovaně); žádný zápis
  ani `ds-upgrade` nad nimi.

## Odchylky od zadání (2026-10-26)

- **Texty nálezů jsou v knihovně.** `ValueChecks` → `ValueFinding` (kód
  D13, hodnota, česká věta důvodu; `message(vlastník)`) ve
  `world.validations` — `CanonicalValueValidator` je jen skládá se stranou
  dokladu, `PersonValues::check*` bez ní. `base.persons` na `core.exchange`
  záviset nemůže (cyklus), knihovna je jediné společné místo.
- **`BankAccountKey` se přestěhoval do `world.validations`** (stejné API):
  potřebuje ho adresář (D20) i `economy.bank`, které na `core.exchange`
  nezávisí. `BankAccountShape` (D20) je vedle něj — jedna implementace pro
  účty osob i číselník.
- **Země osoby pro applier = virtuální klíč `_country`** v payloadu
  hlavičky (vzor `_importNumber`): `PersonApplier` ho bere z povinného
  `country` canonicalu, side-create z dokladu ze země strany;
  `PersonDocument` ho čte ve `validate()` i `beforeSave()` a před SQL
  odstraní. Pořadí: `_country` → adresy v témže zápisu → uložené adresy
  (`PersonContext`) → země zdroje dat.
- **Adresa bez země:** PSČ jen trim a bez nálezu (nic se nehádá); země
  uloženého řádku platí jen při updatu bez země v payloadu. V kopiích
  reálných dat je adres bez země 0–4 %.
- **Přesun IBAN (D20) jen s oběma sloupci v zápisu** (`account_number`
  i `iban`): formulář je posílá vždy, applier také; částečný payload mění
  jen tvary.
- **Dialog sub-tabulky po uložení s varováním zůstane otevřený a přepne
  se na uložený řádek** (`FormSubTable.handleDialogSaved`): jinak by se po
  Přidat zavřel a žlutý banner by nikdo neviděl; po uložení by jinak
  resetoval na další nový záznam a PSČ by nešlo rovnou opravit. Ověřeno
  headless (4l3j: adresa partnera s PSČ „ABC“ → banner „Uloženo, ale
  zkontroluj“, dialog na uloženém řádku; vlastní firma → 422).
- **Twig filtry přímo v `PrintTwigExtension`** (`src/Core` importuje
  `world.validations` jako už `Feed\FeedCollector` moduly) — bez nového
  mechanismu rozšíření; knihovna je bez DB a vždy na disku. Knihovna
  formátuje běžnou mezerou, filtry ji mění na NBSP jako `money`.
- **Párování bez fallbacku „část před lomítkem“** (D23): účet uložený bez
  kódu banky se nenapáruje (v kopiích reálných dat ≤ 8 účtů partnerů na
  zdroj, vlastní účty žádný). **FIO parser** proto staví odkaz na vlastní
  účet jako `accountId/bankId`, jinak IBAN, jinak holé číslo (dřív jen
  `accountId`). Integrační fixtura `StatementImportTest` nese účty s kódem
  banky; dřívější selhání `testPartnerMatchedByCounterpartyAccount`
  zmizelo.
- **`ValueRules::ibanFromAccountNumber()`** nahrazuje `CzIbanCalculator`
  bez požadavku na mod 11 (stejný výstup jako dřív; QR platba a ISDOC
  beze změny). SPAYD ověřuje BIC přísněji (`[A-Z]{6}` na začátku) —
  stávající testy beze změny.
- **Průvodce nastavením** (`SetupController::existsInCodebook`) páruje
  účty osob s číselníkem přes `BankAccountKey` z obou sloupců (dřív přesná
  shoda řetězců).
- **Grep zápisů do pěti tabulek:** normalizaci neobchází nic kromě
  generického CRUD (`src/Api/Controller/CrudController.php`), které
  obchází třídy dokumentů u všech tabulek (stejně jako lock providery) —
  mimo task. Ostatní přímé zápisy mění jen stav, `valid_to`, `source_*`,
  `order_pos` nebo `is_default`.
- **Zlaté výstupy tisku** se změnily jen v IBAN v platbě a PSČ ve větvi
  bez `display_block` (`DocPrintTemplatesTest`); fixtury `PrintData`
  beze změny (nesou čitelný tvar, který formátovač nemění).
- **Nezměněno (mimo D21):** `DocsHeadsFormBase::renderPersonSnapshot`,
  `BankAccountsLookup`, `BankAccountsViewer`, `BankTransactionsViewer`
  ukazují uložený tvar. EPO XML (`FilingXmlValidator`, `EpoXmlFormat`,
  `FilingComposer::taxNumberDigits`) jsou formát podání, ne platnost —
  nechány; `VatPlaceDerivation::normalize` a `IsdocReader` jen detekce
  prefixu DIČ; `PersonsRegistryController::personExistsInDb` hledá IČO
  přesně (mimo task).

## Ověření (2026-10-26)

- `vendor/bin/phpunit`: 10 816 testů OK (jediné varování a skip jsou
  z dřívějška v `IsdocReaderTest`); integrační `StatementImportTest` na
  4l3j 10/10 (tiskové integrační testy tam přeskakují — DS nemá
  registraci k DPH, kryje je `DocPrintTemplatesTest` nad fixturami).
- `npm run build` OK; headless smoke dialogu adresy (viz odchylky).
- HTTP smoke na 4l3j: adresa partnera s PSČ „ABC“ → 201 + `zip_invalid`
  ve `warnings`; adresa vlastní firmy → 422 `zip_invalid`; účet partnera
  s IBAN v čísle účtu → uloženo `19-2000145399/0800` +
  `CZ6508000000192000145399`; detail osoby ukazuje `+420 111 222 333`.
  Testovací řádky smazány, osoba vrácena do V pořádku.
- `dataset-seed` + `dataset-verify` po přeseedování: plátce (ob9b),
  neplátce (r1lf), spolek (wp66) — všech šest kontrol `ok`, rozdíl 0,
  snímek deníku beze změny. Demo sada „IT servis“ (8yft, bez `expected/`)
  přeseedována bez nálezu hodnot — jen dřívější varování o dvou
  zahraničních firmách bez IČO a nabídce osnovy.
