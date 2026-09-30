# Task: Kontaktní osoba strany dokladu — `contact.name` ve výměnném formátu

**Stav:** hotovo — 3 commity 2026-09-30; D6 na `lh6x-l`: `ds-upgrade` propsal profil v4.5.0, reanalýza zasažené zprávy prošla (status 2, `customer.contact.name` v `canonical_json`, název firmy zůstal v `name`); náhled ověřen buildem a daty, vizuální proklik modalu zbývá

**Cíl:** AI analýza faktury, na které je u strany dokladu uvedena kontaktní
osoba, přestane končit `schema_error`. Kanonický formát dokladu dostane pole
`contact.name`, prompt ho umí vyplnit, ISDOC ho načte a review modal ho
zobrazí.

Související: `ai_analyzer` issue #1 (tolerantní validace výstupu — systémová
ochrana proti improvizovaným klíčům, řeší se zvlášť v jiném repu).

## Před implementací přečti

- `docs/exchange-format.md` §6 (Party object, ~ř. 434) a pro srovnání
  `docs/exchange-format-persons.md` — hlavičkový `contact` (ř. ~110) vs.
  sub-objekt `contacts[]` (§7)
- `modules/core/mail/docs/ai-prompts.md` — workflow ladění promptu,
  „Customization guidelines“, changelog
- `tests/Unit/Module/Core/Mail/ProfileSchemaDriftTest.php` a
  `tests/Unit/Module/Core/Exchange/Schema/SchemaDriftTest.php` — co hlídají

## Kontext — diagnostika

AI analýza zprávy s jednou PDF fakturou (zahraniční dodavatel, EUR, reverse
charge) selhala dvakrát po sobě, na promptu v4.3.0 i v4.4.0, se stejnou
chybou:

```
[schema_error] output does not match schema: Additional properties are not allowed ('name' was unexpected) at ['document', 'extracted_json', 'customer', 'contact']
```

V bloku odběratele je na faktuře nad názvem firmy jméno kontaktní osoby.
Model ho vrátil jako `customer.contact.name`, ale `$defs.Contact` povoluje
jen `email` / `phone` / `web` a má `additionalProperties: false`. Analyzer
proto odmítl celý výstup (neopakovatelná chyba, zpráva ve stavu 70).
Kanonický formát pro kontaktní osobu nemá žádné místo, prompt ukazuje jen
ukázku (`contact: {email, phone}`, `customer: null`), samotné schéma modelu
neposílá. Obecný zákaz „nepřidávej klíče“ nestačí.

ISDOC má `Contact/Name` (vedle `Telephone`, `ElectronicMail`), `IsdocReader`
ho dnes zahazuje.

## Rozhodnutí k designu (potvrzená)

- ✓ **D1 — umístění:** `contact.name` (string | null) v objektu `Contact`
  strany dokladu. Odpovídá ISDOC `Contact/Name` a tomu, co model sám dělá.
  Sémantika u dokladu: `contact` = kontakt k dokladu (osoba + její kanály).
  Liší se od formátu Osob, kde je `contact` hlavičkový kanál firmy a jména
  osob žijí v `contacts[]` — v `docs/exchange-format.md` výslovně uvést.
- ✓ **D2′ — co s hodnotou:** zobrazí se v review modalu
  (`DocumentExchangePreview.svelte`) u strany dokladu a zůstává v návrhu
  (`canonical_json`). **Snapshoty dokladu se nemění** — u běžného applu je
  staví `DocDocument::buildSnapshots()` z Osoby / vlastní firmy
  v databázi, ne z kanonického formátu; import-mód
  (`DocumentApplier::buildImportPartnerSnapshot`) také beze změny.
- ✓ **D3 — ISDOC:** `IsdocReader::mapParty` mapuje `Contact/Name` →
  `contact.name`.
- ✓ **D4 — export:** `DocumentExporter` beze změny (stranu skládá z Osoby,
  která jméno kontaktu v hlavičce nemá).
- ✓ **D5 — prompt `czech_general` v4.5.0** (změna schématu = minor):
  ukázka `supplier.contact` s `name`; pravidlo, že jméno kontaktní osoby
  (Vyřizuje, Kontaktní osoba, Attn, jméno uvedené nad názvem firmy) patří do
  `contact.name`, nikdy do `name` strany, pokud je na dokladu název firmy.
- ✓ **D6 — ověření na dev zdroji:** `ds-upgrade` (propíše profil) a „Znova
  analyzovat“ u zasažené zprávy (zdroj a zpráva jsou v chatu, mimo repo).
  Očekávání: analýza projde, jméno je vidět v review modalu. Mutace na
  zdroji s režimem *reálná kopie* → pouští člověk nebo po jeho schválení.

## Co je potřeba udělat

### Commit 1 — formát a ISDOC (D1, D3)

Schéma je ve **třech ručně synchronizovaných kopiích** — ve všech přidat do
`Contact.properties` jako první klíč
`"name": { "type": ["string", "null"] }`:

1. `modules/core/exchange/schemas/shpd.docs.document.v1.jsonc` (`"Contact"`, ~ř. 284)
2. `modules/core/exchange/schemas/shpd.docs.document.v1.json` (`"Contact"`, ~ř. 186)
3. `modules/core/mail/profiles/czech_general.jsonc` — inline kopie v
   `output_schema…extracted_json.oneOf[0].$defs.Contact` (~ř. 690)

ISDOC — `modules/core/exchange/src/Isdoc/IsdocReader.php` `mapParty()`
(~ř. 275): do `contact` přidat `'name' => $this->text($party, 'Contact', 'Name')`
(jako první klíč). `text()` jde po cestě přímých potomků, s `PartyName/Name`
se nesplete.

Test: `tests/Fixtures/Exchange/isdoc/invoice_full.isdoc` — do `<Contact>`
dodavatele (~ř. 40) přidat `<Name>` s fiktivním jménem (ISDOC pořadí:
`Name`, `Telephone`, `ElectronicMail`). `IsdocReaderTest::testFullInvoice`
(~ř. 157) doplnit aserci na `$supplier['contact']['name']`;
`testMinimalInvoice` ověřit, že bez `Contact/Name` je hodnota `null`.

Docs: `docs/exchange-format.md` §6 — ukázka `contact` (~ř. 461) s `name`
a komentářem; krátký odstavec o sémantice (D1) a o tom, že se hodnota
nepropisuje do Osoby ani do snapshotu dokladu (D2′). Komentář u `Contact`
v `.jsonc` schématu.

Ověření: `php -l` na změněných PHP, `vendor/bin/phpunit --filter 'IsdocReaderTest|SchemaDriftTest|ProfileSchemaDriftTest|SchemaValidatorTest'`.

### Commit 2 — náhled (D2′)

`frontend/src/components/exchange/DocumentExchangePreview.svelte` (~ř. 529):
blok `shpd-exchange__party-contact` se zobrazí i když je vyplněné jen
`party.contact?.name`; jméno jako první `<span>` s popiskem
`{t('exchange.preview.field.contactName')}: <strong>…</strong>` (vzor
`companyId` o pár řádků výš). Nový klíč do `frontend/src/i18n/cs.js`
(`'Kontakt'`) a `en.js` (`'Contact'`) vedle ostatních
`exchange.preview.field.*` (~ř. 418).

Ověření: `cd frontend && npm run check:i18n && timeout 90 npm run build`.

### Commit 3 — prompt v4.5.0 a uzavření (D5)

`modules/core/mail/profiles/czech_general.jsonc`:

- `prompt_version` → `v4.5.0`; v `prompt_template` nahradit obě zmínky
  `v4.4.0` (pravidlo `source.promptVersion` a ukázka `source`).
- Ukázka: `"contact": {\"email\": \"...\", \"phone\": \"...\"}` →
  `{\"name\": \"Jan Novák\", \"email\": \"...\", \"phone\": \"...\"}`
  (v JSON řetězci escapované uvozovky).
- PRAVIDLA — nový bod za pravidlo o identifikátorech, znění zhruba:
  „Jméno kontaktní osoby strany (Vyřizuje, Kontaktní osoba, Attn, jméno
  uvedené nad názvem firmy) patří do \"contact\".name — NIKDY do \"name\"
  strany, pokud je na dokladu uveden název firmy. U fyzické osoby bez názvu
  firmy je její jméno v \"name\".“

Testy s verzí: `ProfileSchemaDriftTest::testProfileMetadata` (ř. 89) a
`AIAnalyzerProvisionerTest` (ř. 177) → `v4.5.0`; v `ProfileSchemaDriftTest`
k stale-kontrolám (ř. 113 / 139) přidat `v4.4.0`.

Docs `modules/core/mail/docs/ai-prompts.md`: nadpis „Default prompt
(v4.4.0)“ (ř. 27) a zmínka verze (ř. 61) → v4.5.0; příklad bumpu v
„Customization guidelines“ (ř. 175) posunout; changelog `### v4.5.0
(datum)` nad v4.4.0 — důvod (diagnostika výše, bez identifikace zdroje),
změna schématu, pravidlo, ukázka, odkaz na tento task a `ai_analyzer` #1.

`**Stav:**` tohoto tasku → `hotovo` (případně `částečně — zbývá D6`,
pokud ověření na dev zdroji ještě neproběhlo) + `python3 scripts/tasks-index.py`.

Ověření: `vendor/bin/phpunit --filter 'ProfileSchemaDriftTest|AIAnalyzerProvisionerTest|AiProfileReloadCommandTest|DsUpgradeCommandTest|AIProfileDocumentTest'`,
na konci celá sada lokálně.

### Ověření na dev zdroji (D6, člověk)

1. `vendor/bin/shpd-ds ds-upgrade` v adresáři dev zdroje → profil
   `czech_general` v `core_mail_ai_profiles` má `prompt_version = v4.5.0`.
2. U zasažené zprávy „Znova analyzovat“ → nový řádek
   `core_mail_message_analyses` se `status = 2`, v `canonical_json`
   je `customer.contact.name`.
3. Review modal návrhu ukazuje u odběratele „Kontakt: …“.

**Výsledek (2026-09-30, `lh6x-l`):** po `ds-upgrade` profil v4.5.0 (řádek
v DB nese `Contact.name` i nové pravidlo); zpráva vrácena do fronty
(`analysis_state` 10, `needs_reanalysis`), analyzer ji vzal do 3 minut,
nová analýza `status = 2`, `prompt_version = v4.5.0`, jistota 0,95, bez
`_validationError`. V `canonical_json` je `customer.contact.name` a
název firmy zůstal v `customer.name`. Dodavatel kontakt nemá, blok se
u něj nevykreslí. Build obsahuje popisek „Kontakt“; vizuální kontrola
modalu v prohlížeči neproběhla.

## Mimo rozsah

- Přenos jména kontaktu do snapshotů dokladu (varianta D2″) — případně
  později, pokud se ukáže, že účetním chybí.
- Zakládání záznamů v `base_persons_contacts` a propis do Osoby
  (`PartyResolver`, `PersonApplier`) — duplicity, jméno často patří
  vlastní straně.
- `DocumentExporter` (D4), formát Osob `shpd.persons.person.v1`.
- Tolerantní validace v analyzeru (`ai_analyzer` #1), ukládání surového
  výstupu a tokenů u selhání (kontrakt `/failed`), srozumitelné chybové
  hlášky na došlé poště — samostatně.

## Pasti

- **Tři kopie schématu.** Zapomenutá kopie shodí `SchemaDriftTest`
  (`.jsonc` vs `.json`) nebo `ProfileSchemaDriftTest` (canonical vs
  inline v profilu). Změna jen v profilu by zase nechala server-side
  validaci canonicalu (`SchemaLoader` čte `.json`) odmítat `contact.name`
  → návrh by skončil s `_validationError`.
- **`prompt_template` je jeden dlouhý JSON řetězec** uvnitř JSONC —
  uvozovky escapované (`\"`), nové řádky `\n`. Editovat přes Python
  (`io.open(..., encoding='utf-8')`) s `assert s.count(old) == 1` před
  každou náhradou; po úpravě ověřit, že profil parsuje (`ProfileSchemaDriftTest`).
- **Verze promptu** je v šabloně třikrát (pole + dvakrát v textu);
  `ProfileSchemaDriftTest` počítá `substr_count(... prompt_version) == 2`.
- **Do zdroje se profil propíše jen při vyšší verzi šablony** (`ds-upgrade`)
  — bez bumpu se změna schématu na DS neprojeví.
- **Fyzická osoba jako strana:** pravidlo nesmí vytlačit jméno z `name`,
  když strana nemá název firmy (účtenky, OSVČ) — proto dovětek v pravidle.
- **Snapshoty nesahat** (D2′) — `DocDocument::buildSnapshots()` ani
  `DocumentApplier::buildImportPartnerSnapshot()` se nemění.
- **JS:** v Svelte kódu jen ASCII uvozovky; české texty patří do `i18n/*.js`.
- **Fixture a docs:** jen fiktivní jména (`Jan Novák`), žádná data z
  diagnostiky.

## Hotovo když

- [x] `contact.name` ve všech třech kopiích schématu; `SchemaDriftTest`
      i `ProfileSchemaDriftTest` zelené.
- [x] `IsdocReader` mapuje `Contact/Name`; test na full i minimal fixture.
- [x] Review modal zobrazuje „Kontakt: …“; `check:i18n` a build prošly.
- [x] Profil `czech_general` v4.5.0 s ukázkou a pravidlem D5; testy verzí
      aktualizované.
- [x] Docs (`exchange-format.md` §6, `ai-prompts.md` changelog, komentář
      ve schématu) aktualizované.
- [x] D6: zasažená zpráva na dev zdroji po reanalýze projde, jméno je v modalu.
