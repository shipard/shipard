# Normalizace a validace hodnot canonicalu — fáze 1

**Stav:** hotovo — implementováno 2026-10-26 v 5 commitech (knihovna, normalizátor a validátor s napojením a stropem pásma, resolvery, porovnávač a `valueFindings`, dokumentace); odchylky níže; evaluace tří sad × 3 (2026-10-26) splnila D17


## Kontext / Cíl

Tvar údajů stran dokladu dnes určuje model nebo dodavatel ISDOC. Prompt
správně říká „identifikátory přebírej beze změny“, takže do canonicalu
přichází tiskový tvar: PSČ s mezerou, IBAN po čtveřicích, telefon s
mezerami a bez předvolby. Sjednocuje ho jen porovnávač evaluace
(`ValueShapes`, s poznámkou „do dokončení #118“), produkce ne. Platnost se
nekontroluje nikde.

| Podklad | Co ukazuje |
|---------|-----------|
| závěrečný běh #103 (2026-10-26, 156 volání) | shoda až po tvarech: telefon 101×, IBAN 28×, PSČ 31×; IBAN dokladu 0701 špatně přečtený v 1 ze 3 opakování v každé sadě (chybná délka i kontrolní číslice), číslo účtu na dokladu přečtené správně |
| resolvery | `PartyResolver` a `BankAccountResolver` jen ořežou mezery a párují přesnou shodou |
| kopie reálných dat (jen agregované tvary) | telefony osob skoro všechny v národním tvaru bez předvolby; IBAN partnerů většinou ve sloupci čísla účtu (sloupec IBAN skoro prázdný); u několika IČO chybí vedoucí nuly; ~1 % PSČ s mezerou. Kontrolní součet IČO prošel u 1 873 z 1 874 českých a u všech 25 slovenských; 8místné české DIČ je vždy `CZ` + IČO |
| rozesetá normalizace | `ContentTagResolver::normalizeCompanyId`, `BankAccountDocument` (regex IBANu), párování bankovních pohybů (`Import\PartnerResolver`), `ParserUtils::mod11`, `ValueShapes`, v Pythonu `invoicegen/checks.py` |

**Cíl fáze 1:** jedna knihovna pravidel hodnot per stát (`world.validations`),
normalizace a validace canonicalu na vstupu (AI, ISDOC) i v applieru,
oprava IBAN ↔ číslo účtu z redundance na dokladu, nálezy v náhledu a strop
pásma jistoty, resolvery párující normalizované tvary. Porovnávač evaluace
na tutéž knihovnu deleguje.

Mimo fázi 1 (#118 D9, samostatný task později): normalizace při uložení
osob, adres, kontaktů a bankovních účtů, jednorázová normalizace
existujících dat a převod zbylých míst (`BankAccountDocument`, párování
pohybů, `ParserUtils::mod11`). Ověření v registrech (ARES, VIES, zveřejněné
účty) je #142.

## Návaznost

- **#118** D1–D10 (komentář 2026-10-26), D11–D17 rozhodnuté v chatu
  2026-10-26 (oddíl Rozhodnutí níže)
- **#103** D2 (normalizace kódem), D15 (tvary hodnot), D85 (doladění sad),
  závěrečný běh 2026-10-26
- vzor pravidel per stát: `world.assets` (`TaxRulesRegistry`)

## Před implementací přečti

- `docs/exchange-format.md` § 6 (Party), § 8.1 (`PartyResolver`), § 8.5
  (`BankAccountResolver`), § 9 (`_resolve`, audit bloky, Issue codes),
  § 10 (apply pipeline)
- `docs/test-corpus.md` § 10 (rovnocenné tvary), § 11 (tvary hodnot),
  § 18 (porovnávač, `matchNormalized`, výsledky běhu)
- `modules/core/mail/docs/ai-analysis.md` → „Zápis výsledku běhu“
- `modules/world/assets/README.md`, `modules/world/assets/src/TaxRulesRegistry.php`,
  `modules/world/base/config/countries.jsonc` (`phonePrefixes`)
- kód vstupů: `AnalysisResultWriter::validateCanonical()`,
  `Isdoc\IsdocDocumentCollector::collect()`, `DocumentApplier::validate()`,
  `preview()`, `apply()`, `DocumentValidator`
- pásmo: `AnalysisConfidenceResolver` (`capBandByRowCoverage()`,
  `bandForAnalysis()`), `Feed\MailSuggestionsSource` (volá strop přímo)
- párování: `Resolve\PartyResolver` (`resolve()`, `diffSelfPartyIdentity()`),
  `Resolve\BankAccountResolver`, `Enrich\ContentTagResolver`,
  `Enrich\RowEnrichmentPipeline::supplierCompanyId()`,
  `Enrich\ContentTagRuleCaptureHandler`, `MessagePartnerWriter::resolvePerson()`,
  `RegistryApplier::resolvePartner()`
- evaluace: `Compare\ValueShapes`, `Compare\CanonicalComparator`,
  `Eval\EvalRunner`, `tests/corpus/projection.jsonc` (`shapes`)
- `tools/invoice-generator/invoicegen/checks.py`, `tests/test_checks.py`
- `help/posta/kontrola-vytezeni.md`, `docs/help-authoring.md`

## Rozhodnutí

Závazné znění D1–D10 je v komentáři #118; zde zkráceně.

- **D1 — Dva kroky.** Normalizace sjednocuje tvar (mezery, velikost písmen,
  vedoucí nuly, předvolba) mlčky a idempotentně, obsah nemění. Validace
  kontroluje platnost a vyrábí nálezy, hodnotu nemění. Výjimkou je D4.
- **D2 — Kde.** AI: `validateCanonical()` po schématu, před obohacením.
  ISDOC: `IsdocDocumentCollector` po schématu, před dedupem identitou.
  `DocumentApplier` (`/validate`, `/preview`, `/apply`) po schématu,
  idempotentně, včetně importního módu — data ze starého Shipardu se při
  importu normalizují. Syrová hodnota zůstává v `analysis_json`.
- **D3 — Nevalidní hodnota** zůstává; warning v `_resolve.issues`, strop
  pásma na `review`, apply neblokuje.
- **D4 — Oprava z redundance.** IBAN a tuzemské číslo účtu (cz, sk): jeden
  validní a druhý ne → nevalidní se dopočte z validního; warning, strop
  `review`, záznam v auditním bloku. Oba validní a nesedí → jen warning.
- **D5 — Pravidla per stát** podle vzoru `world.assets`: obecná pravidla,
  rozhraní země, registr země → třída. Nová země nemění jádro.
- **D6 — Jedna implementace.** `ValueShapes` deleguje na knihovnu;
  `matchNormalized` u sdílených tvarů má klesnout k nule. Případy
  z `checks.py` ve sdíleném fixture pro PHPUnit i `unittest`.
- **D7 — První vlna:** PSČ (cz, sk); IČO (cz, sk, kontrolní součet);
  DIČ / VAT ID (tvar podle prefixu; cz 8místné = `CZ` + IČO; de kontrolní
  číslice); tuzemské číslo účtu (cz, sk, mod 11); IBAN (mod 97); BIC;
  telefon; e-mail; web; VS, KS, SS; IČO strany ostatní pošty a dokumentů
  registru (přes `PartyResolver`, D16).
- **D8 — Telefon s předvolbou** podle země strany.
- **D9 — Data v adresáři:** resolvery porovnávají normalizované tvary na
  obou stranách; uložení a migrace jsou fáze 2.
- **D10 — Ověření online** mimo #118: #142.
- **D11 — Modul `world.validations`** („Validace a normalizace údajů per
  stát“, závisí na `world.base`): obecná pravidla, rozhraní země, registr.
  Bez tabulek, bez DB a bez `ConfigRuntime` — čistá knihovna, použitelná
  i v preflightu sad a v unit testech. Normalizátor a validátor
  **canonicalu** (znají cesty v canonicalu) jsou v `core.exchange`.
- **D12 — Telefon.** `00` → `+`; `(0)` v mezinárodním zápisu pryč; národní
  číslo: zahodit národní předčíslí `0` (sk, de; cz ho nemá) a doplnit
  předvolbu země strany. Předvolba je konstanta třídy země; strážce testem
  hlídá shodu s `world.base.countries` (`phonePrefixes`). Země bez třídy →
  jen odstranit mezery a interpunkci (předvolbu nedoplňovat — bez znalosti
  národního předčíslí by vznikla chybná čísla). Telefon, e-mail a web
  nálezy nevyrábí.
- **D13 — Kódy nálezů** (všechny `warning`, path = cesta pole):
  `company_id_invalid`, `vat_id_invalid`, `vat_id_company_id_mismatch`,
  `zip_invalid` (cz, sk), `account_number_invalid`, `iban_invalid`,
  `bic_invalid`, `iban_account_mismatch`, `bank_account_repaired`. Počítá je
  čistý validátor volaný z `DocumentValidator` — běží v `/validate`,
  `/preview` i `/apply`.
- **D14 — Auditní blok opravy** `_resolve.valueRepairs: [{path, from, to,
  basis}]` (`basis`: `accountNumber` | `iban`). Zapisuje ho normalizátor
  (idempotentně — opakovaný běh nic nepřidá, existující záznamy zachová),
  validátor z každého záznamu dělá `bank_account_repaired`.
- **D15 — Strop pásma.** `ready` → `review`, když validace canonicalu vrátí
  nález z D13 nebo canonical nese `valueRepairs`. Platí ve **všech** místech
  výpočtu pásma (dnes `bandForAnalysis()` i feed, který volá
  `capBandByRowCoverage()` přímo) — sjednotit do jedné metody stropu.
- **D16 — Resolvery.** `PartyResolver` normalizuje vstup a IČO hledá i ve
  tvaru bez vedoucích nul (stará data); `diffSelfPartyIdentity()`
  porovnává normalizované tvary. `BankAccountResolver` porovnává účty
  osoby (a vlastní účty v číselníku) v PHP přes klíč účtu: normalizovaný
  IBAN, u tuzemského čísla cz/sk dopočtený IBAN — z obou sloupců, protože
  `account_number` u starých dat nese IBAN. Pravidlo štítku hledá
  normalizované IČO i ve starém tvaru; `ContentTagResolver::normalizeCompanyId`
  nahradí knihovna. Ostatní pošta (`MessagePartnerWriter`) a registr
  (`RegistryApplier`) párují přes `PartyResolver`, takže je to pokryje.
- **D17 — Ověření.** Cílené testy nad fixture, round-trip ISDOC
  a `CorpusExpectedTest`; evaluace (pouští člověk) má ukázat
  `matchNormalized` u telefonu, IBANu a PSČ na nule, IBAN dokladu 0701
  opravený s nálezem a shodu polí aspoň jako baseline 2026-10-26. Aby to šlo
  ověřit, výsledek volání v evaluaci nese nálezy hodnot (§ 4).

## 1. Knihovna `world.validations` (D5, D11, D12)

Nový modul `modules/world/validations/` (`module.jsonc`, `README.md`,
`src/`), vzor `world.assets`.

**Obecná pravidla (bez země)**

| Hodnota | Normalizace | Validace |
|---------|-------------|----------|
| země | malými, trim | — |
| IBAN | velkými, bez mezer | `^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$` + ISO 7064 MOD 97-10; u země s třídou i délka |
| BIC | velkými, bez mezer | `^[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$` |
| VAT ID | velkými, bez mezer, teček a pomlček | prefix státu + `[0-9A-Z]{2,13}`; prefix s třídou → pravidlo třídy |
| e-mail | trim, malými | — |
| web | malými, bez schématu a koncového lomítka | — |
| telefon | D12 | — |
| VS, KS, SS | bez mezer | — |
| platební reference | bez mezer, velkými | — |

**Rozhraní země** (název nechám na implementaci, např. `CountryValueRules`):
PSČ, IČO, číslo za prefixem DIČ, tuzemské číslo účtu (parse, normalizace,
mod 11, převod na IBAN a zpět), telefon (předvolba, národní předčíslí),
délka IBAN. Registr `forCountry(?string $country)` jako
`TaxRulesRegistry`; neznámá země nebo `null` → jen obecná pravidla.

| Země | PSČ | IČO | DIČ | Účet / IBAN | Telefon |
|------|-----|-----|-----|-------------|---------|
| cz | 5 číslic bez mezer | 8 číslic (doplnit vedoucí nuly), kontrolní součet | `CZ` + 8–10 číslic; bez prefixu doplnit `CZ`; 8místné musí být `CZ` + IČO (9–10místné = fyzická osoba, vazba neplatí) | `[předčíslí-]číslo/kód` bez vedoucích nul, mod 11 předčíslí i čísla; BBAN = kód 4 + předčíslí 6 + číslo 10; IBAN 24 znaků | `+420`, bez národního předčíslí |
| sk | 5 číslic | 8 číslic, stejný kontrolní součet jako cz | `SK` + 10 číslic | stejný tvar a mod 11 jako cz, IBAN `SK`, 24 znaků | `+421`, předčíslí `0` |
| de | 5 číslic | jak na dokladu (trim) | `DE` + 9 číslic, kontrolní číslice ISO 7064 MOD 11,10 (`checks.py::de_vat_check_digit`) | IBAN 22 znaků | `+49`, předčíslí `0` |

Tuzemský účet bez kontextu země (strana bez země, IBAN chybí) se
rozpozná tvarem `…/kód banky` a validuje mod 11 — pravidlo je pro cz i sk
stejné; země je potřeba jen pro převod na IBAN.

**Sdílený fixture** `tests/Fixtures/World/value-cases.jsonc`: případy
normalizace (vstup → výstup per hodnota a země) a validace (platné /
neplatné) — převzít z `tools/invoice-generator/tests/test_checks.py`
a doplnit: tiskové tvary z korpusu (PSČ s mezerou, IBAN po čtveřicích,
telefon s mezerami), IČO bez vedoucí nuly, IBAN 0701 s vloženými nulami,
DIČ bez prefixu, `00420…`, `+49 (0)30 …`, slovenské číslo s `0`. PHPUnit
test knihovny i `test_checks.py` čtou tentýž soubor (Python jen pro
kontroly, které `checks.py` umí: IČO, mod 11, IBAN, DE DIČ, tvar DIČ).

Strážce: předvolba každé třídy země = `phonePrefixes[0]` v
`world.base.countries`.

## 2. Normalizace a validace canonicalu (D1–D4, D13–D15)

V `core.exchange` (např. `src/Values/`):

**Normalizátor canonicalu** — čistá funkce `array → array`, idempotentní:

- strany `supplier`, `customer`, `balanceParty`: `country`, `companyId`,
  `taxId`, `vatId`, `address.zip`, `address.country`, `contact.email`,
  `contact.phone`, `contact.web`, `bankAccount.accountNumber`,
  `bankAccount.iban`, `bankAccount.bic`;
- `payment.paymentReference`, `payment.specificSymbol`,
  `payment.constantSymbol`; totéž na řádcích;
- země strany = `party.country ?? party.address.country`; adresa může mít
  vlastní zemi (PSČ podle ní); účet podle prefixu IBAN, jinak země strany;
- oprava D4 na každém `bankAccount` s oběma hodnotami, kde lze určit cz
  nebo sk; záznam do `_resolve.valueRepairs` (D14);
- `null` a chybějící klíče nechat, jak jsou; nic nedoplňovat, co na
  dokladu není (chybějící číslo účtu se z IBANu nedopočítává).

**Validátor canonicalu** — čistá funkce `canonical → list<issue>` (D13),
české zprávy s hodnotou a důvodem (např. „IBAN dodavatele nemá platnou
kontrolní číslici“); z `valueRepairs` warning `bank_account_repaired`
s původní a novou hodnotou. Volá ho `DocumentValidator::validate()`.

**Napojení (D2)**

1. `AnalysisResultWriter::validateCanonical()` — docs větev, po schématu,
   **před** `enrichAtResult()` (pravidlo štítku podle IČO dodavatele).
2. `IsdocDocumentCollector::collect()` — po `passesSchema()` v obou
   větvích (samostatná příloha, embedded), **před** dedupem identitou.
3. `DocumentApplier::validate()`, `preview()`, `apply()` — po schématu,
   před `DocumentValidator`; náhled vrací normalizovaný canonical.

**Pásmo (D15)** — jedna metoda stropu v `AnalysisConfidenceResolver`
(pokrytí řádků + nálezy hodnot), kterou volají `bandForAnalysis()` i feed.

## 3. Resolvery (D16)

- `PartyResolver::resolve()`: vstup přes knihovnu (země strany, jinak
  obecná pravidla); IČO z číslic hledat i bez vedoucích nul;
  `diffSelfPartyIdentity()` nad normalizovanými tvary.
- `BankAccountResolver`: účty osoby (u vlastního účtu číselník) načíst
  a porovnat v PHP přes klíč účtu (D16); stejný klíč pro hodnotu ze sloupce
  `iban` i `account_number`.
- `ContentTagResolver` / `ContentTagRuleCaptureHandler` /
  `RowEnrichmentPipeline::supplierCompanyId()`: IČO přes knihovnu; vyhledání
  pravidla i ve starém tvaru (bez vedoucích nul); tabulku pravidel
  nepřepisovat (migrace je fáze 2).
- Testy: IBAN uložený ve sloupci čísla účtu se napáruje na IBAN i na
  tuzemské číslo z dokladu; IČO bez vedoucí nuly v osobách se napáruje;
  zahraniční strana beze změny chování; ostatní pošta a registr párují
  přes `PartyResolver` (stávající testy dál projdou).

## 4. Porovnávač a evaluace (D6, D17)

- `ValueShapes`: sdílené tvary (`country`, `zip` a `companyId` byCountry,
  `vatId`, `iban`, `bic`, `accountNumber`, `phone`, `email`, `web`,
  `symbol`, `reference`) delegují na knihovnu; země z rodičovského objektu
  jako dnes u byCountry. Sémantika porovnání (`EquivalentForms`, tolerance,
  volný text) beze změny.
- Strážce korpusu: normalizátor canonicalu je nad každým očekáváním
  (`tests/corpus/cz/expected/**`) identita a validátor nevrátí žádný nález.
- `EvalRunner`: výsledek volání nese `valueFindings: [{path, code}]` —
  nálezy validátoru nad validovaným canonicalem včetně oprav; report CLI je
  vypíše u dokladu. Metriky a `metricsVersion` beze změny.

## 5. Dokumentace

- `modules/world/validations/README.md` — pravidla, tabulka zemí, jak
  přidat zemi.
- `docs/exchange-format.md` — nový oddíl „Normalizace a validace hodnot“
  (kde běží, pořadí, D4), § 9 audit blok `valueRepairs` a kódy D13
  v tabulce Issue codes, § 8.1 a § 8.5 párování normalizovaných tvarů.
- `docs/test-corpus.md` § 11 — tvary implementuje produkční knihovna
  (věta „do dokončení #118“ pryč); § 18 — `matchNormalized` jako hlídač
  regrese, `valueFindings`.
- `help/posta/kontrola-vytezeni.md` → „Na co narazíš“: Shipard sjednotí
  zápis (PSČ, IBAN, telefon) a zkontroluje IČO, DIČ, číslo účtu a IBAN;
  IBAN opravený podle čísla účtu; návrh s takovým upozorněním jde vždy ke
  kontrole. Názvy ověřit ve zdroji popisků; `python3 scripts/help-index.py`.
- `CLAUDE.md` → Kódové konvence, jedna věta: tvar a platnost IČO, DIČ,
  účtů, IBAN, PSČ a telefonu řeší `world.validations`, ne vlastní regex.

## Kroky

1. Knihovna, fixture, Python test → commit
   `feat(world): pravidla hodnot per stát world.validations (#118 D5, D11, D12)`
2. Normalizátor a validátor canonicalu, napojení, oprava, pásmo → commit
   `feat(exchange): normalizace a validace hodnot canonicalu, oprava IBAN podle čísla účtu (#118 D1–D4, D13–D15)`
3. Resolvery → commit
   `feat(exchange): párování osob, účtů a pravidel štítků přes normalizované tvary (#118 D16)`
4. Porovnávač, strážce, `valueFindings` → commit
   `test(eval): porovnávač na pravidlech world.validations, nálezy hodnot u volání (#118 D6, D17)`
5. Dokumentace, help, `**Stav:**`, `python3 scripts/tasks-index.py` → commit
   `docs: normalizace a validace hodnot (#118)`

## Hotovo když

- [x] Knihovna: testy nad fixture projdou v PHP i Pythonu; strážce předvolby.
- [x] Normalizátor: testy každé cesty, idempotence (dvojí běh = jeden),
      oprava v obou směrech, nesoulad dvou validních hodnot, strana bez
      země, adresa s jinou zemí než strana.
- [x] Napojení: `validateCanonical()` normalizuje před obohacením (pravidlo
      štítku se najde i u IČO s mezerou); dedup ISDOC sloučí kandidáty
      s DIČ zapsaným s mezerou; `/preview` vrací normalizovaný canonical
      a nálezy D13.
- [x] Návrh s nálezem nebo opravou nikdy není `ready` (detail zprávy i feed).
- [x] Resolvery podle § 3, stávající testy párování beze změny výsledku.
- [x] Strážce korpusu: normalizace očekávání je identita, validace bez nálezů.
- [x] `vendor/bin/phpunit --filter` na dotčené testy (World*, Values*,
      DocumentValidator*, DocumentApplier*, AnalysisResultWriter*,
      AnalysisConfidence*, IsdocDocumentCollector*, IsdocCorpusRoundTrip*,
      PartyResolver*, BankAccountResolver*, ContentTag*, MessagePartnerWriter*,
      RegistryApplier*, CanonicalComparator*, ValueShapes*, Eval*,
      CorpusExpected*); `python3 -m unittest` generátoru; celá sada lokálně
      na konci.
- [x] `dataset-verify` nad třemi sadami korpusu a demo sadou „IT servis“
      (volné zdroje dat po přeseedování): kontroly `ok`, snímek deníku beze
      změny — změna snímku je nález, ne důvod ho přeschválit.
- [x] Dokumentace, help, `CLAUDE.md`, `**Stav:**`, index tasků.
- [x] **Žádná evaluace v tomto tasku** — běh tří sad × 3 pouští člověk
      z dashboardu; očekávání v D17.

## Pasti

- **Pořadí.** Normalizace až po kontrole schématu (canonical má zaručenou
  strukturu), ale před obohacením a dedupem — obojí páruje podle IČO / DIČ.
- **Nic nehádat.** Neznámá země → jen obecná pravidla; zahraniční IČO jen
  trim; předvolbu doplnit jen u země s třídou; chybějící údaj nedopočítávat
  (jedinou výjimkou je oprava D4 nevalidní hodnoty).
- **`valueRepairs` musí přežít `preview` a `apply`.** Applier `_resolve`
  skládá znovu (`withResolve()`); ověř, jak dnes přežívá `contentTag`,
  a zachovej blok stejně. Opakovaná normalizace v applieru ho nesmí
  zdvojit ani smazat.
- **`analysis_json` zůstává syrový** — je to forenzní záznam výstupu modelu.
- **Importní mód** normalizuje záměrně (D2); ověřování importu ze starého
  Shipardu může hlásit rozdíl tvaru — očekávané, ne regrese.
- **Historické výsledky `tests/eval/` a zmrazená fixtura
  `tests/Fixtures/Eval/cz-vat-payer-2026-10-16/`** beze změny; testy nad
  nimi musí dál projít.
- **Evaluační zdroje dat nepoužívat.** Tvary v kopiích reálných dat jen
  číst (read-only SQL, agregovaně) — žádný zápis ani `ds-upgrade` nad nimi.
- **Mod 11:** předčíslí a číslo se kontrolují zvlášť, prázdné předčíslí je
  platné; váhy `1, 2, 4, 8, 5, 10, 9, 7, 3, 6` od pravého kraje
  (`checks.py::_mod11`).

## Odchylky od zadání (2026-10-26)

- **Registr země vrací vždy pravidla** — stát bez třídy dostane
  `GenericValueRules` (jen obecná pravidla, `isGeneric()`), ne `null`;
  volající nemají větev pro chybějící pravidla (vzor
  `AccountingOnlyTaxRules`).
- **Platný cz / sk IBAN = mod 97 + délka 24 + mod 11 čísla účtu
  uvnitř.** IBAN s platnou kontrolní číslicí, ale neplatným číslem účtu,
  je nevalidní; oprava D4 má navíc pojistku „dopočtená hodnota musí sama
  projít validací“ — happy fixture testů (`invoiceReceived_happy.json`)
  má neplatné součty IČO, účtu i IBAN a bez pojistky by se „opravila“ na
  jiný neplatný tvar.
- **`taxId` se normalizuje (cz / sk jako DIČ), ale nevaliduje** — D13 pro
  něj kód nemá a u cz by nález zdvojil `vat_id_invalid`.
- **Validátor kontroluje tvary po `normalizeShapes()`**, ne syrový vstup:
  strop pásma běží nad persistovaným `canonical_json` a návrhy z doby před
  nasazením by jinak ve feedu dostaly `iban_invalid` za mezery.
- **Prefix DIČ se doplňuje jen u cz** (`completeVatId`); sk a de ne.
- **Klíč pravidel štítků** zůstává v dosavadním tvaru (alfanumerické
  znaky velkými); země strany jen doplní vedoucí nuly IČO před ním.
- **Demo sada „IT servis“** nesla slovenské IČO s chybnou kontrolní
  číslicí (`52398417` → `52398412` ve 14 souborech), jinak by každý
  `dataset-verify` demo sady hlásil `company_id_invalid`.
- **`CzIbanCalculator`** (tisk QR platby) je třetí implementace IBAN —
  patří do fáze 2 vedle `BankAccountDocument`, `Import\PartnerResolver`
  a `ParserUtils::mod11`.
- **Demo sada „IT servis“ nemá `expected/`** — `dataset-verify` ji odmítá
  v preflightu; ověřeno přeseedováním (apply přes applier s normalizací,
  3 dřívější varování o zahraničních firmách bez IČO, žádný nález hodnot).
  Tři sady korpusu: `dataset-verify` exit 0, všech šest kontrol `ok`,
  snímek deníku beze změny (ob9b, r1lf, wp66, 2026-10-26).
- **`capBandByRowCoverage()` zanikla** — jediný strop je
  `AnalysisConfidenceResolver::capBand()` (pokrytí řádků + nálezy hodnot).

## Ověření D17 (2026-10-26)

Tři sady × 3, prompt v4.11.0, Sonnet 5.5, 156 volání, ~5,6 USD;
výsledky `tests/eval/<sada>/2026-10-26-baseline-2.json`, srovnání
s ranním během `2026-10-26-baseline.json` (před #118).

| Sada | Shoda polí | Čisté doklady |
|------|-----------|---------------|
| plátce | 99,93 % (ráno 99,1 %, bez selhaného volání 99,97 %) | 88,3 % (90 %) |
| neplátce | 99,99 % (99,99 %) | 97,9 % (97,9 %) |
| spolek | 99,97 % (99,92 %) | 95,8 % (87,5 %) |

- `matchNormalized`: telefon 101 → 0, IBAN 28 → 0, PSČ 31 → 0; zbývá
  jen `totals.totalRounding` (0 ≡ `null`, sémantika porovnání).
- `valueFindings`: jediný nález ve 156 voláních — `bank_account_repaired`
  u 0701 (plátce, 1 ze 3), opravený IBAN = očekávání. V ostatních sadách
  model IBAN 0701 tentokrát přečetl správně. Žádný falešný nález.
- Odchylky nesouvisí s #118: E4 (0602 `supplyKind`, čeká na #123),
  0301 `totals.totalAmount` 4 725,28 místo 4 725,00 v 2 ze 3 u plátce
  (model vzal součet rekapitulace před zaokrouhlením; nové, normalizátor
  součty nemení), 0802 obec s „CA“ 1/3, výjimka štítku řádku 1002
  (ISDOC) 1/3.
