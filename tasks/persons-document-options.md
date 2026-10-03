# Osoba: jazyk dokumentů, spojování příloh, příloha „odeslat s dokladem“

**Stav:** naplánováno — rozhodnutí #94 D1–D11 zamčená 2026-10-03

> PRD pro Claude Code (4 commity). Design: issue #94, komentář
> „Rozhodnutí (zamčeno)“ (D1–D11). Navazuje na tisky #90 (D5, D8, D10, D11)
> a `tasks/prints-phase1.md` / `prints-phase2.md`. Samotné odeslání e-mailem
> je #90 fáze 4 — tento task připraví data a jazyk, nic neodesílá.

## Kontext

Tisky dnes tisknou ve výchozím jazyce zdroje dat; osoba jazyk nemá
(`PrintLanguageResolver` — jediné místo, kam měl jazyk partnera přibýt).
Pro odesílání dokladů (#90 fáze 4) chybí dvě další věci: volba osoby, zda
přílohy dokladu spojit do PDF dokladu, a určení, které přílohy se s dokladem
vůbec posílají.

Klíčové principy z #94:

- **Jazyk na osobě je výjimka, ne povinný údaj (D1).** Ve starém systému je
  jazyk vyplněný jen u malé části osob, kde automatika podle země nevyhovuje.
  Prázdný jazyk = automaticky podle země.
- **Jazyk osoby se čte živě (D4)** — partner si řekne o jiný jazyk, na osobě
  se změní a doklad se pošle znovu bez zásahu do dokladu. Do snapshotu strany
  nepatří; je to vědomá výjimka z #90 D5. Země pro odvození se ale bere ze
  snapshotu strany (adresa dokladu).
- **Jazyky dokumentů ≠ jazyky tisku (D3).** Na osobě lze nastavit `cs`, `en`,
  `sk`, `de`; tisk má katalogy jen pro `cs`, `en`. Jazyk bez katalogu se
  tiskne anglicky, dokud katalog nepřibude.

## Před implementací přečti

- Issue #94 — tělo a komentář s rozhodnutími D1–D11
- `docs/prints.md` §1–§3 (běh, `PrintRunner`, jazyk), §5 Překlady, §6 REST
- `src/Core/Prints/` — `PrintLanguageResolver`, `PrintRunner`,
  `PrintRunnerFactory`, `PrintBuilder`, `PrintDefinition` (`audience`)
- `modules/docs/core/src/Prints/DocPrintBuilder.php`, `DocPrintContext.php`
  (`partner()`, `tradeDir()`, `decodeSnapshot()`); `CashDocPrintBuilder`
  a `CashRegisterPrintBuilder` z něj dědí
- `modules/docs/core/src/PersonSnapshotBuilder.php` — tvar snapshotu
  (`address.country`)
- `src/Core/Config/DataSourceConfig.php` — `getCountry()`,
  `getDefaultLanguage()`
- `modules/world/base/module.jsonc`, `config/countries.jsonc` (`languages` —
  první položka = hlavní jazyk), `config/languages.jsonc`
- `modules/base/persons/tables/base_persons_persons.jsonc`,
  `src/PersonsForm.php`, `src/PersonDocument.php`
- `modules/docs/core/extensions/base_persons_persons.jsonc` — vzor extension
  (`payment_term_days`)
- `docs/attachments.md`, `modules/core/attachments/` (`AttachmentService`,
  `AttachmentGuard`), `src/Api/Controller/AttachmentController.php`,
  `frontend/src/components/form/AttachmentPanel.svelte`,
  `frontend/src/components/viewer/AttachmentGrid.svelte`,
  `TableForm::attachmentsTab()`
- `docs/exchange-format-persons.md`,
  `modules/core/exchange/schemas/shpd.persons.person.v1.jsonc`,
  `modules/core/exchange/src/Person/` (`PersonApplier`, `PersonValidator`),
  `modules/core/exchange/src/Export/PersonExporter.php`
- `docs/table-definitions.md` (bezpečné změny), `docs/edit-forms-cookbook.md`
- Nápověda: `help/osoby/zalozeni-osoby.md`,
  `help/faktury-vydane/tisk-faktury.md`, `help/co-dnes-nejde.md`;
  `docs/help-authoring.md`

## Scope

**Uvnitř:**

- cfgItem jazyků dokumentů, sloupec `language` na osobě, extension
  `send_attachments_merged`, sloupec `send_with_record` na přílohách.
- Služba odvození jazyka dokumentu (D2) a její napojení na tisk.
- Formulář osoby — sekce Dokumenty (D8).
- Výměnný formát osob (`shpd.persons.person.v1`, nový blok `documents`) —
  applier, validátor, exportér.
- Příznak přílohy v API (upload, PATCH, seznam) a v UI.
- Dokumentace a nápověda.

**Mimo:**

- Odeslání dokladu e-mailem, spojení PDF (`appendPdfs`), zmrazená kopie —
  #90 fáze 4. Tento task jen popíše kontrakt v `docs/prints.md` (D5, D6, D11).
- Katalogy tisku `sk`, `de` a popisky číselníků v dalších jazycích (dnes
  `compiled.{cs,en}.json`) — samostatný task (#94 D10).
- Exportní strana importu ze starého Shipardu (D9) — `old_shipard`,
  samostatný task. Tady jen nová pole schématu osob a upload s příznakem.
- MCP nástroje osob, viewer osob, HeaderInfo — beze změny.

## 1. Datový model (D1, D3, D5, D6, D7)

### 1.1 Jazyky dokumentů — cfgItem `world.base.documentLanguages`

Nový soubor `modules/world/base/config/documentLanguages.jsonc`, registrace
v `world.base` `config[]`. Pořadí = pořadí v nabídce:

```jsonc
{
    "cs": { "name": "Czech",   "name:cs": "čeština",   "name:en": "Czech" },
    "en": { "name": "English", "name:cs": "angličtina", "name:en": "English" },
    "sk": { "name": "Slovak",  "name:cs": "slovenština", "name:en": "Slovak" },
    "de": { "name": "German",  "name:cs": "němčina",   "name:en": "German" }
}
```

Jediný zdroj pravdy pro sloupec, formulář, validaci a službu z §2. `en` musí
být v seznamu vždy (fallback D2) — hlídá test.

### 1.2 `base_persons_persons.language` (D1, D7)

Přímý sloupec `base.persons`, skupina `contact` (nebo nová skupina
`documents` — dle `columnGroups`):

- `enumString`, `length: 2`, `nullable: true`, bez defaultu,
  `cfgItem: "world.base.documentLanguages"`.
- `name:cs` „Jazyk dokumentů“, `name:en` „Document language“.
- `PersonDocument`: `''` → `null`; hodnota mimo cfgItem = validační chyba
  na poli `language`.

### 1.3 Extension `send_attachments_merged` (D5, D7)

Do `modules/docs/core/extensions/base_persons_persons.jsonc` (vedle
`payment_term_days`): `boolean`, `default: 0`, `name:cs` „Přílohy dokladu
připojit do PDF dokladu“, `name:en` „Merge document attachments into the
document PDF“. Komentář v souboru: konzument je odeslání dokladu (#90
fáze 4), pravidla D6 + D11.

### 1.4 `core_attachments_files.send_with_record` (D6)

`boolean`, `default: 0`, `name:cs` „Odeslat se záznamem“,
`name:en` „Send with record“. Generický název — příloha patří obecnému
záznamu; v UI dokladů se popisuje „Odeslat s dokladem“.

- Nová příloha (upload, `copyTo`, mail, registry) má `0`, pokud volající
  výslovně neřekne jinak (§4).
- Smazaná příloha (`is_deleted`) se neposílá bez ohledu na příznak — to je
  kontrakt pro fázi 4, zapsat do `docs/attachments.md`.

Po změnách `ds-upgrade` na dev DS (jen ADD COLUMN).

## 2. Jazyk dokumentu (D2, D3, D4)

### 2.1 `DocumentLanguageResolver` — čistá služba

`src/Core/I18n/DocumentLanguageResolver.php` (obecná — použije ji i
odeslání e-mailu ve fázi 4). Bez databáze, vstupy:

```php
public function __construct(
    array $documentLanguages,   // cfgItem world.base.documentLanguages (klíče)
    array $countries,           // cfgItem world.base.countries
    string $ownCountry,         // DataSourceConfig::getCountry()
) {}

public function resolve(?string $personLanguage, ?string $partyCountry): string
```

Pořadí (D2, kroky 2–5; krok 1 = výslovný parametr řeší volající):

1. `$personLanguage` mezi jazyky dokumentů → ten.
2. `$partyCountry` známá (klíč v `countries`) → její **hlavní** jazyk
   (`languages[0]`); je-li mezi jazyky dokumentů → ten, jinak `en`.
3. Země chybí nebo je neznámá → hlavní jazyk `$ownCountry` (je-li mezi
   jazyky dokumentů, jinak `en`).

Neplatná hodnota jazyka osoby (např. ze staršího importu) se ignoruje,
jako by nebyla vyplněná. Země se porovnává malými písmeny.

`defaultLanguage` zdroje dat se pro jazyk dokumentu **nepoužívá** (fallback
`en` při chybějícím klíči by tiskl tuzemcům anglicky). Je to změna chování
oproti fázi 1: interní tisk (Kontace) zdroje s `defaultLanguage: en` a českou
zemí půjde česky — uvést v `docs/prints.md`.

### 2.2 Strana tisku — `PrintPartyProvider`

Runner musí znát jazyk **před** buildem (translator a `ConfigRuntime` jsou
per jazyk). Nové volitelné rozhraní v `src/Core/Prints/`:

```php
interface PrintPartyProvider
{
    /** Partner záznamu pro volbu jazyka; null = záznam partnera nemá. */
    public function printParty(array $record, DataSourceConnection $db, ConfigRuntime $config): ?PrintParty;
}

final readonly class PrintParty
{
    public function __construct(
        public ?string $personLanguage,  // živě z osoby (D4)
        public ?string $country,         // ze snapshotu strany (D4)
    ) {}
}
```

- Implementuje `DocPrintBuilder` (dědí pokladní doklad a prodejka):
  partner = `head.partner`; `personLanguage` = **živé**
  `base_persons_persons.language` partnera; `country` =
  `address.country` z partnerského snapshotu podle `tradeDir`
  (výstup → `customer_snapshot`, vstup → `supplier_snapshot`; logika
  shodná s `DocPrintContext::partner()` — sdílet, neduplikovat). Bez
  partnera nebo bez partnerského snapshotu → `null`.
- `ConfigRuntime` pro tento krok stačí v libovolném kompilovaném jazyce
  (čte jen klíče cfgItemů) — runner ho vezme pro záložní jazyk tisku.

### 2.3 `PrintLanguageResolver` — jazyk tisku

Přepis (třída zůstává jediným místem volby jazyka tisku):

- `LANGUAGES` = jazyky tisku (katalogy) `['cs', 'en']` beze změny —
  dál z ní čte `PrintDeclarationsTest`.
- Výslovný parametr (REST `language`, CLI `--language`) se validuje proti
  jazykům tisku → mimo = `InvalidArgumentException` (400), jako dnes.
- Bez parametru: `audience: internal` → strana se nehledá; jinak
  `PrintPartyProvider::printParty()`, je-li builder implementuje.
  Výsledek `DocumentLanguageResolver::resolve($party?->personLanguage,
  $party?->country)`.
- Jazyk dokumentu mimo jazyky tisku (`sk`, `de`) → `en` (D3). Do
  `PrintBuildResult::messages` (měkké hlášení, hlavička
  `X-Print-Messages`) přidat informaci, že požadovaný jazyk tisk zatím nemá
  — bez názvu partnera.
- `PrintRunnerFactory`: konstruktor resolveru dostane `getCountry()`
  a cfgItemy místo `getDefaultLanguage()`.
- `PrintRunner::run()`: builder vytvořit před volbou jazyka (dnes až po ní).

`renderData()` (CLI `--data`) se nemění — jazyk je z obálky.

## 3. Formulář osoby (D8)

`PersonsForm`, tab **Nastavení**, nová sekce **Dokumenty** za
„Obchodní podmínky“:

- `select` `language` — options z cfgItem + prázdná volba
  „Automaticky (podle země)“ / „Automatic (by country)“ (prázdná = `null`).
  Popisek přes cfgItem / lokalizaci, ne natvrdo v PHP, pokud to `select`
  umožňuje — jinak dle stávajícího vzoru `PersonsForm`.
- `checkbox` `send_attachments_merged` — jen je-li sloupec v definici
  tabulky (extension `docs.core` aktivní); vzor `payment_term_days`.

## 4. Příznak přílohy v API a UI (D6)

### 4.1 API

- `GET /_attachments` — položky nesou `send_with_record` (bool).
- `PATCH /_attachments/{id}` — nové volitelné pole `send_with_record`
  (bool), samostatně i spolu s rename/order. `AttachmentService`:
  nová metoda `setSendWithRecord(int $id, bool $value)`; průchod
  `assertChangeAllowed()` s novou operací `AttachmentGuard::OPERATION_SEND_FLAG`
  (`FilingAttachmentGuard` ji odmítne svou výchozí větví — v pořádku).
- `POST /_attachments/upload` — volitelné pole `send_with_record` (`1`/`0`);
  cesta pro import ze starého Shipardu (D9). Bez pole `0`.
- Zámek dokladu (`documentLockProviders`) příznak **neblokuje** — není to
  obsah dokladu; typicky se nastavuje u dokladu ve stavu V pořádku těsně
  před odesláním.

### 4.2 UI

- Přepínač se zobrazí jen tam, kde to formulář povolí:
  `TableForm::attachmentsTab(..., sendFlag: true)` → `FormTab` JSON
  `send_flag: true` → prop `sendFlag` v `AttachmentPanel`.
  `DocsHeadsFormBase` předá hodnotu z nového hooku
  `attachmentsSendable(): bool` (default `false`);
  `IssuedInvoiceFormBase` vrací `true` (faktura vydaná, zálohová faktura).
- `AttachmentPanel`: u každé přílohy přepínač „Odeslat s dokladem“
  (ikona z `icons.js`, pojmenovaná podle významu, např. `iconSendWith`),
  stav viditelný i když je vypnutý jen jako značka. **Ověř, kdy je panel
  `disabled`** — pokud u dokladu V pořádku, přepínač musí zůstat aktivní
  (příznak není obsah dokladu); upload a rename se tím nemění.
- Read-only detail (`AttachmentGrid`): u příloh s příznakem značka
  „Odeslat s dokladem“, bez přepínání.
- i18n klíče v `cs.js` i `en.js`; `npm run check:i18n`.

## 5. Výměnný formát osob (D9)

Nový volitelný blok na nejvyšší úrovni `shpd.persons.person.v1`:

```jsonc
"documents": {
    "type": ["object", "null"],
    "additionalProperties": false,
    "properties": {
        "language":              { "type": ["string", "null"], "pattern": "^[a-z]{2}$" },
        "sendAttachmentsMerged": { "type": ["boolean", "null"] }
    }
}
```

- `PersonValidator`: `language` mimo jazyky dokumentů = chyba.
- `PersonApplier`: **blok chybí → sloupce se nemění** (obnova z registru
  ani starší payload nesmí vynulovat ruční nastavení); blok je → zapíše
  obě hodnoty (`null` = smazat jazyk). `sendAttachmentsMerged` jen když
  sloupec existuje; jinak ignorovat s poznámkou ve výsledku apply.
- `PersonExporter`: blok vždy, `formatVersion` `1.1`.
- Pokud `dataset-dump` / `dataset-seed` (`docs/datasets.md`) přenáší osoby
  nebo přílohy, nová pole projdou round-tripem; jinak nic.
- `docs/exchange-format-persons.md` — blok, pravidlo „chybí = nemění“.

Import ze starého Shipardu (D9, mimo tento task): jazyk 1:1 bez
normalizace, spojování 1:1; přílohy k odeslání jen u příloh vlastního
dokladu (upload s `send_with_record=1`), vazby na přílohy jiných záznamů
se neimportují a report importu je sečte.

## 6. Kontrakt pro odeslání (#90 fáze 4) — jen dokumentace

Do `docs/prints.md` (nová podsekce „Volby osoby pro odeslání“) a
`docs/attachments.md`:

- Posílají se přílohy záznamu s `send_with_record = 1` a `is_deleted = 0`,
  v pořadí `att_order` (D6).
- `send_attachments_merged = 1` (osoba partnera, živě): PDF přílohy se
  připojí za PDF dokladu (`appendPdfs`), ostatní soubory jdou do e-mailu
  samostatně, bez konverze (D11). `0` = všechny přílohy samostatně.
- Jazyk e-mailu = jazyk dokumentu z `DocumentLanguageResolver`
  (texty #90 D9 per jazyk).

## Testy

- `DocumentLanguageResolverTest` — tabulka: výslovný jazyk osoby; CZ → `cs`;
  SK → `sk`; AT → `de`; CH → `de`; BE → `en`; LU → `en`; IE → `en`;
  FR → `en`; neznámá země / `null` → hlavní jazyk vlastní země; vlastní
  země s nepodporovaným jazykem → `en`; neplatný jazyk osoby ignorován;
  velká písmena země. Test, že `en` je v cfgItemu.
- `PrintLanguageResolverTest` / `PrintRunnerTest` — parametr má přednost;
  parametr mimo jazyky tisku → 400; `internal` ignoruje stranu; `sk`/`de`
  osoby → `en` + měkké hlášení; builder bez `PrintPartyProvider`.
- `DocPrintBuilder` party — výstup i vstup (`tradeDir`), bez partnera,
  bez snapshotu, živý jazyk osoby (změna na osobě se projeví bez zásahu do
  dokladu).
- `PersonDocument` — validace `language`, `''` → `null`.
- `PersonApplier` / `PersonValidator` / `PersonExporter` — blok chybí =
  beze změny, blok `null` hodnoty, neplatný jazyk, round-trip.
- `AttachmentService` / `AttachmentController` — PATCH příznaku, upload
  s příznakem, seznam vrací pole, guard dostane `OPERATION_SEND_FLAG`.
- `PrintDeclarationsTest` beze změny musí projít.

Přes vzdálený most jen úzké `--filter`; celá sada lokálně na konci.

## Task breakdown

### Commit 1 — Datový model (§1)

cfgItem `world.base.documentLanguages`, sloupec `language`, extension
`send_attachments_merged`, sloupec `send_with_record`, validace v
`PersonDocument`, testy validace. `ds-upgrade` na dev DS. `docs/attachments.md`
§2 (sloupec), `modules/base/persons` dokumentace tabulky
(`base_persons_persons.md`), pokud popisuje sloupce.

### Commit 2 — Jazyk dokumentu a tisk (§2)

`DocumentLanguageResolver`, `PrintParty` + `PrintPartyProvider`, implementace
v `DocPrintBuilder`, přepis `PrintLanguageResolver`, `PrintRunner`,
`PrintRunnerFactory`, testy. `docs/prints.md` §3 (pravidlo D2–D4, změna
oproti `defaultLanguage`). Nápověda `help/faktury-vydane/tisk-faktury.md`
(jazyk podle odběratele, slovenština a němčina zatím anglicky)
a `help/co-dnes-nejde.md` — ve stejném commitu, `python3 scripts/help-index.py`.

### Commit 3 — Formulář osoby a výměnný formát (§3, §5)

`PersonsForm` sekce Dokumenty, schéma, validátor, applier, exportér, testy,
`docs/exchange-format-persons.md`. Nápověda `help/osoby/zalozeni-osoby.md`
(sekce Dokumenty: kdy jazyk nastavit — jen když automatika podle země
nevyhovuje; spojování příloh — zatím se jen ukládá, odesílání chybí).

### Commit 4 — Příznak přílohy (§4, §6)

API, `AttachmentService`, guard operace, `TableForm::attachmentsTab()`,
`DocsHeadsFormBase` hook, `IssuedInvoiceFormBase`, `AttachmentPanel`,
`AttachmentGrid`, ikona, i18n, testy, `npm run build`. `docs/attachments.md`
§4 (API), `docs/prints.md` kontrakt §6, `docs/edit-forms.md` (parametr
`sendFlag` u tabu příloh). Nápověda: `help/faktury-vydane/` (označení
přílohy k odeslání) a `help/co-dnes-nejde.md` (odeslání e-mailem zatím
chybí — příznak a volba se jen ukládají). **`**Stav:**` tohoto tasku**
+ `python3 scripts/tasks-index.py`.

## Hotovo když

- [ ] `ds-upgrade` na dev DS přidal tři sloupce, nic jiného.
- [ ] Osoba bez jazyka: CZ adresa → česky; SK a AT adresa → jazyk
      dokumentu `sk` / `de`, tisk zatím anglicky s měkkým hlášením;
      BE / LU / FR adresa → anglicky.
- [ ] Nastavení jazyka `en` na osobě změní tisk **existující** faktury
      V pořádku bez úpravy dokladu.
- [ ] `?language=cs` přebije jazyk osoby; `?language=sk` → 400.
- [ ] Kontace tiskne v hlavním jazyce vlastní země bez ohledu na partnera.
- [ ] Formulář osoby: sekce Dokumenty, volba Automaticky ukládá `null`.
- [ ] Exchange: payload bez bloku `documents` jazyk osoby nezmění;
      export obsahuje blok, `formatVersion` 1.1.
- [ ] Přepínač „Odeslat s dokladem“ funguje u faktury vydané i zálohové
      ve stavu Koncept i V pořádku, u faktury přijaté a u osob není.
- [ ] Upload se `send_with_record=1` uloží příznak.
- [ ] `docs/prints.md`, `docs/attachments.md`, `docs/exchange-format-persons.md`,
      `docs/edit-forms.md` a nápověda aktualizované; `help-index.py`
      a `tasks-index.py --check` prochází.
- [ ] Cílené testy zelené, celá sada zelená lokálně, `npm run build`
      a `npm run check:i18n` bez chyb.

## Rozhodnutí k designu (potvrzená)

Z issue #94:

- ✓ D1 Jazyk na osobě = výslovné přepsání, prázdný = automaticky.
- ✓ D2 Odvození: parametr → jazyk osoby → hlavní jazyk země strany
  (mimo jazyky dokumentů → `en`) → hlavní jazyk vlastní země DS.
- ✓ D3 Jazyky dokumentů `cs`, `en`, `sk`, `de`; jazyky tisku = katalogy;
  bez katalogu `en`; parametr se validuje proti jazykům tisku.
- ✓ D4 Jazyk osoby živě, ne ve snapshotu; země ze snapshotu strany.
- ✓ D5 Spojování příloh = volba osoby, výchozí ne.
- ✓ D6 Které přílohy se posílají = příznak na příloze.
- ✓ D7 `language` v `base.persons`, `send_attachments_merged` extension
  z `docs.core`.
- ✓ D8 Formulář: tab Nastavení, sekce Dokumenty.
- ✓ D9 Import 1:1; přílohy jen vlastního dokladu.
- ✓ D10 Rozsah dle Scope; `sk`/`de` katalogy samostatně.
- ✓ D11 Spojují se jen PDF, ostatní přílohy samostatně.

Upřesnění tohoto zadání (nad rámec issue, k potvrzení při review):

- Služba `DocumentLanguageResolver` v `src/Core/I18n/`, strana přes
  volitelné `PrintPartyProvider` na builderu.
- Přepínač přílohy jen ve formulářích, které o to požádají — zatím faktura
  vydaná a zálohová faktura (`IssuedInvoiceFormBase`).
- Zámek dokladu příznak přílohy neblokuje.
- `PersonApplier`: chybějící blok `documents` = beze změny.
