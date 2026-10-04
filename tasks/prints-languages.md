# Tisky — jazyky `sk` a `de`, formátování přes intl, zkratky jednotek, přepínač jazyka

**Stav:** částečně — implementace hotová (4 commity); čeká na revizi formulací `sk` / `de` kolegy (sekce „Formulace k revizi“ na konci) a `ds-upgrade` zdrojů dat při nasazení

> PRD pro Claude Code (4 commity). Design: issue #90, komentář
> „Rozhodnutí: jazyky tisku (D29–D33)“. Navazuje na #94 (jazyk osoby,
> `DocumentLanguageResolver`, jazyky dokumentů) a na
> `tasks/prints-phase2.md`.

## Kontext

Od #94 tisk volí jazyk podle osoby a země partnera, ale umí jen `cs`
a `en` — jazyk dokumentu `sk` nebo `de` spadne na `en` s hlášením
`language.unavailable`. Kromě katalogů šablon chybí:

- **Kompilovaná konfigurace v `sk` a `de`.** `DsUpgradeCommand` kompiluje
  napevno `['cs', 'en']`; tisk z ní bere popisky sazeb a poznámek DPH,
  způsobů platby, typů dokladů a stavů účtování. Chybějící jazyková
  varianta se tiše vrátí na `en` (`LocalizedFieldResolver`).
- **Formátování.** `PrintTwigExtension` rozlišuje jen „čeština / ostatní“
  (`isCzech()`); `de` by dostalo anglická čísla. Anglické datum
  `10/2/2026` je pro EU dvojznačné.
- **Jednotky.** Tiskne se `core_units.shortcut` („ks“, „hod“, „měs“) —
  i na dnešních anglických tiscích.
- **UI.** Náhled tisku neumí zvolit jiný jazyk.

## Před implementací přečti

- Issue #90 — D8, D13, D29–D33; issue #94 — D1–D4
- `docs/prints.md` — kapitoly o jazyce, katalozích, filtrech, REST
- `src/Core/Prints/PrintLanguageResolver.php`, `PrintTranslator.php`,
  `PrintCatalogLoader.php`, `Twig/PrintTwigExtension.php`
- `src/Core/I18n/DocumentLanguageResolver.php`, `ConfigLocalizer.php`,
  `LocalizedFieldResolver.php`
- `src/Core/Config/ConfigCompiler.php`,
  `src/Command/DataSource/DsUpgradeCommand.php` (krok „Compile
  configuration“), `ConfigRuntime::load()`
- `modules/world/base/config/documentLanguages.jsonc`
- `modules/core/units/config/unitsSeed.jsonc`, `core_units.jsonc`
- `modules/docs/core/src/Prints/DocPrintContext.php` (načtení jednotek),
  `Blocks/DocRowsBlock.php`, `DocVatCodes.php`, `Blocks/DocPaymentBlock.php`
- `modules/economy/accounting/src/Prints/DocJournalPrintBuilder.php`
  (typy dokladů, stavy účtování)
- `src/Api/Controller/PrintsController.php`, `ViewerController::detail()`
  (akce tisku), `frontend/src/api/prints.js`,
  `components/viewer/PrintPreviewDialog.svelte`
- `tests/Unit/Core/Prints/PrintDeclarationsTest.php` (úplnost katalogů)

## Scope

**Uvnitř:**

- Kompilace konfigurace pro jazyky dokumentů (D29).
- Filtry `money`, `qty`, `pct`, `date` přes `ext-intl` pro `cs`, `sk`,
  `de`, `en`; anglické datum `2 Oct 2026` (D30).
- Tiskové zkratky systémových jednotek (D31).
- Katalogy `sk` a `de` všech šablon tisků, popisky konfigurace `sk`
  a `de` pro vše, co tisk čte (D29).
- `PrintLanguageResolver::LANGUAGES` = `cs`, `en`, `sk`, `de`.
- Seznam formulací k revizi (D32).
- Přepínač jazyka v náhledu, hlavička `Content-Language` (D33).
- Testy, `docs/prints.md`, nápověda.

**Mimo:**

- UI aplikace v `sk` / `de` (frontend zůstává `cs` / `en`).
- Popisky konfigurace, které tisk nepoužívá (doplní se, až budou potřeba).
- Data zdroje v jiných jazycích (názvy pokladen, účtů, dimenzí, texty
  řádků) — tisknou se, jak jsou.
- Další jazyky nad rámec `world.base.documentLanguages`.
- Odeslání e-mailem (fáze 4).

## 1. Kompilace konfigurace (D29)

- `DsUpgradeCommand`: jazyky kompilace = jazyky UI (`cs`, `en`) ∪ klíče
  `world.base.documentLanguages` ze **surových** dat konfigurace (před
  kompilací). Když cfgItem chybí (modul neaktivní), zůstane `cs`, `en`.
  Výpis `Languages:` ve verbose režimu ukáže výsledek.
- `ConfigRuntime::load($dsDir, 'sk')` pak najde `compiled.sk.json`.
  Chybějící soubor (DS bez nového `ds-upgrade`) → stávající chování
  `ConfigRuntime` (ověřit; tisk v takovém jazyce musí skončit čitelnou
  chybou, ne prázdnými popisky).
- `LocalizedFieldResolver` se nemění (fallback `:<jazyk>` → `:en` →
  holé pole); úplnost pro tisk hlídá test (§5).

## 2. Formátování (D30)

`PrintTwigExtension` přestane používat `isCzech()` a `number_format`;
formátuje přes `NumberFormatter` / `IntlDateFormatter` podle jazyka tisku
s **explicitními** vzory a symboly (nespoléhat na výchozí data ICU, ta se
mezi verzemi mění):

| Jazyk | Locale | `money` | `qty` | `pct` | `date` |
|---|---|---|---|---|---|
| `cs` | `cs_CZ` | `1 234,50` | `1,5` | `21 %` | `2. 10. 2026` |
| `sk` | `sk_SK` | `1 234,50` | `1,5` | `21 %` | `2. 10. 2026` |
| `de` | `de_DE` | `1.234,50` | `1,5` | `21 %` | `02.10.2026` |
| `en` | `en_GB` | `1,234.50` | `1.5` | `21%` | `2 Oct 2026` |

- Mezery v `cs` / `sk` / `de` (oddělovač tisíců, před `%`, v datu) jsou
  **nezlomitelné** (U+00A0), jako dnes.
- `money` s kódem měny: `1 234,50 CZK` (měna za číslem, nezlomitelná
  mezera) ve všech jazycích — kód, ne symbol.
- `qty` bez zbytečných nul (max. 4 desetinná místa), `pct` max.
  2 desetinná místa, žádné „-0“.
- Měsíc v `en` jako třípísmenná zkratka (`MMM`, `en_GB`).
- Neznámý jazyk = programátorská chyba (`LogicException`) — jazyk tisku
  je vždy z `LANGUAGES`.

## 3. Zkratky jednotek (D31)

- Nový cfgItem `core.units.printShortcuts` (`modules/core/units/config/printShortcuts.jsonc`):
  klíč `system_code`, hodnota `{"shortcut": "pcs", "shortcut:cs": "ks",
  "shortcut:en": "pcs", "shortcut:sk": "ks", "shortcut:de": "Stk"}` —
  pro všech 18 jednotek ze `unitsSeed.jsonc`.
- `DocPrintContext` načte s jednotkou i `system_code`. `DocRowsBlock`:
  - jednotka se `system_code` a jazyk tisku **jiný než `cs`** → zkratka
    z `core.units.printShortcuts`,
  - jinak (`cs`, nebo jednotka bez `system_code`) → `core_units.shortcut`
    z dat (respektuje úpravy zkratky ve zdroji dat).
- Test: každá jednotka ze seedu má záznam v `printShortcuts` se všemi
  jazyky tisku; `cs` varianta odpovídá `shortcut` seedu.

## 4. Překlady `sk` a `de` (D29, D32)

- **Katalogy šablon** (`messages.jsonc`) — doplnit `sk` a `de` ke všem
  klíčům:
  - `modules/docs/core/prints/_layout/messages.jsonc`
  - `modules/docs/invoicesOut/prints/invoice/messages.jsonc`
  - `modules/docs/proformasOut/prints/proforma/messages.jsonc`
  - `modules/docs/cashDocs/prints/cash/messages.jsonc`
  - `modules/docs/cashRegister/prints/receipt/messages.jsonc`
  - `modules/economy/accounting/prints/docJournal/messages.jsonc`
- **Konfigurace, kterou tisk čte** — doplnit `:sk` a `:de`:
  - `world.vat` — `vatCodes[].print`, `vatCodes[].name` (fallback
    v `DocVatCodes`), `vatNotes[].text` (všechny země, které mají
    `print` / `note`; nejen CZ, pokud je tisk čte),
  - `docs.core.paymentMethods` — `name`,
  - `docs.core.docTypes` — `name` (Kontace),
  - `economy.accounting.accountingStates` — `name` (Kontace),
  - `core.units.printShortcuts` (§3),
  - případné další cfgItemy, které builder tisku čte — dohledat
    `cfgItem(` v `modules/*/src/Prints/` a `src/Core/Prints/`.
- `PrintLanguageResolver::LANGUAGES` = `['cs', 'en', 'sk', 'de']`.
  Hlášení `language.unavailable` zůstává pro budoucí jazyky dokumentů
  bez katalogu (test s fiktivním jazykem).
- **Terminologie:** držet se terminologie daňových zákonů dané země,
  ne doslovného překladu z češtiny. Vodítka:
  - `sk`: „Faktúra – daňový doklad“, „Dátum dodania“ (DUZP),
    „Dátum splatnosti“, „Variabilný / Konštantný / Špecifický symbol“,
    „Prenesenie daňovej povinnosti“, „Pokladničný doklad“ (príjmový /
    výdavkový); název prodejky („Predajka“ / „Pokladničný blok“) určí revize,
  - `de`: „Rechnung“, „Leistungsdatum“ (DUZP), „Fälligkeitsdatum“,
    „Steuerschuldnerschaft des Leistungsempfängers“ (přenesení),
    „Kassenbeleg“ (Einnahme / Ausgabe); název prodejky („Kassenbon“ /
    „Verkaufsbeleg“) určí revize,
  - poznámky DPH k osvobozeným a přeneseným plněním podle jazykových
    verzí čl. 226 směrnice 2006/112/ES.
- **Seznam k revizi (D32):** na konec tohoto tasku sekci
  **„Formulace k revizi“** — tabulka `klíč / cs / sk / de` pro:
  titulky všech variant (`title.*`), větu o nedaňovém dokladu, popisky
  dat (DUZP, splatnost, den přijetí platby), popisky stran a podpisů
  pokladního dokladu, všechny poznámky DPH (`vatNotes`, `vatCodes[].note`),
  vodoznak, popisek „K úhradě“. Ostatní překlady revizi nepotřebují.
  Revizi dělají kolegové; opravy přijdou samostatným commitem a task se
  uzavře až po ní.

## 5. Testy úplnosti

- `PrintDeclarationsTest` (rozšířit): každý katalog každé šablony má
  všechny klíče ve **všech** `PrintLanguageResolver::LANGUAGES`.
- Nový test úplnosti konfigurace pro tisk: zkompilovat konfiguraci
  (nebo číst surová data s `:jazyk`) a pro každý jazyk tisku ověřit, že
  každá položka čtená tiskem (seznam z §4) má **vlastní** variantu
  `:<jazyk>` (`cs` může být holé pole, pokud je výchozí český) — ne
  fallback na `en`.
- Kontrola, že jazyky tisku ⊆ jazyky dokumentů (`world.base.documentLanguages`)
  a že kompilace `ds-upgrade` vyrobí `compiled.<jazyk>.json` pro každý.

## 6. Přepínač jazyka (D33)

- **REST:** `pdf` odpověď nese `Content-Language: <jazyk tisku>`
  (vyřešený, i když nebyl vyžádán); přidat do vystavených hlaviček CORS
  (vedle `X-Print-Messages`). Parametr `language` beze změny.
- **Akce Tisk** (`ViewerController::detail()`): `target.languages` =
  `[{id, label}]` pro jazyky tisku, popisek z `world.base.documentLanguages`
  v jazyce UI. Jeden seznam pro všechny tisky (jazyky tisku jsou globální).
- **`fetchPrintPdf(printId, recordId, language?)`** — volitelný parametr,
  vrací i `language` z `Content-Language`.
- **`PrintPreviewDialog`:** výběr jazyka v liště dialogu (vedle
  Stáhnout). Po otevření se načte bez parametru (jazyk podle #94) a výběr
  se nastaví podle `Content-Language`. Změna výběru načte PDF znovu
  s `language` (stávající ochrana proti závodům `fetchToken`, uvolnit
  předchozí object URL). Název staženého souboru beze změny.
  Na mobilu výběr zůstává dostupný i v režimu „jen Stáhnout“.
- Výběr se nikam neukládá (jazyk osoby se mění na osobě, #94 D1).
- i18n frontendu `cs` / `en`.

## Task breakdown

### Commit 1 — Infrastruktura jazyků

§1 kompilace, §2 filtry přes intl (zatím `cs` / `en`, ale tabulka
vzorů už pro všechny čtyři), §3 zkratky jednotek (`cs` / `en`
v `printShortcuts`), úprava fixture / testů, které čekají `10/2/2026`.

**Hotovo když:** `ds-upgrade` na dev DS vyrobí `compiled.{cs,en,sk,de}.json`;
anglická faktura má `2 Oct 2026` a `pcs`; testy filtrů pro všechny čtyři
jazyky zelené.

### Commit 2 — Překlady `sk` a `de`

§4 katalogy a konfigurace, `LANGUAGES`, §5 testy úplnosti, sekce
„Formulace k revizi“ v tasku.

**Hotovo když:** `print-run … --language=sk` a `--language=de` vyrobí
fakturu, zálohovou fakturu, pokladní doklad, prodejku i Kontaci bez
českých nebo anglických zbytků (kontrola HTML výstupem `--format=html`);
partner se slovenskou adresou dostane bez parametru tisk ve `sk`.

### Commit 3 — Přepínač jazyka

§6 REST, akce, API, dialog; testy controlleru (`Content-Language`,
neplatný `language` 400) a háčku akce (`target.languages`).

**Hotovo když:** v náhledu faktury jde přepnout jazyk a stáhnout PDF
ve zvoleném jazyce; výchozí je jazyk podle osoby / země.

### Commit 4 — Dokumentace a nápověda

- `docs/prints.md`: jazyky tisku a kompilace, tabulka formátů,
  zkratky jednotek, `Content-Language`, přepínač, postup přidání jazyka
  (konfigurace + katalogy + testy úplnosti).
- Nápověda: jazyk tisku a přepínač v `help/faktury-vydane/tisk-faktury.md`
  (odkaz z pokladny a Kontace, je-li tam tisk popsaný).
- Hlavička tohoto tasku: „částečně — čeká na revizi formulací“ +
  `python3 scripts/tasks-index.py`.

## Rozhodnutí k designu

Zamčeno v #90: D29–D33. Upřesnění z PRD:

- Zkratka systémové jednotky v `cs` se bere z dat (úpravy ve zdroji dat
  platí), v ostatních jazycích z konfigurace.
- Formátování s explicitními vzory, ne výchozí data ICU.
- `money` tiskne kód měny, ne symbol, ve všech jazycích.
- Task se uzavře až po revizi formulací kolegy (D32).

## Formulace k revizi

Překlady `sk` a `de` s právní vahou (D32) — navrhla je implementace
(commit 2/4), před uzavřením tasku je reviduje člověk. Zdroj pravdy jsou
katalogy `messages.jsonc` a `modules/world/vat/config/vat-cz.jsonc`;
tabulky jsou jejich výpis ke dni commitu. Opravy přijdou samostatným
commitem.

### Titulky

| Klíč | cs | sk | de |
|---|---|---|---|
| `title.invoiceVatPayer` | Faktura – daňový doklad | Faktúra – daňový doklad | Rechnung |
| `title.invoiceNonVatPayer` | Faktura | Faktúra | Rechnung |
| `title.proforma` | Zálohová faktura | Zálohová faktúra | Proformarechnung |
| `title.cashInTaxDocument` | Příjmový pokladní doklad – daňový doklad | Príjmový pokladničný doklad – daňový doklad | Kasseneinnahmebeleg – Steuerbeleg |
| `title.cashIn` | Příjmový pokladní doklad | Príjmový pokladničný doklad | Kasseneinnahmebeleg |
| `title.cashOut` | Výdajový pokladní doklad | Výdavkový pokladničný doklad | Kassenausgabebeleg |
| `title.cashRegisterVatPayer` | Prodejka – daňový doklad | Predajka – daňový doklad | Verkaufsbeleg – Steuerbeleg |
| `title.cashRegisterNonVatPayer` | Prodejka | Predajka | Verkaufsbeleg |
| `title.cashRegisterRefund` | Prodejka – vratka | Predajka – vratka | Verkaufsbeleg – Erstattung |
| `title.docJournal` | Kontace | Kontácia | Kontierung |

### Věta o nedaňovém dokladu, vodoznak, částka k úhradě

| Klíč | cs | sk | de |
|---|---|---|---|
| `note.nonTaxDocument` | Nejedná se o daňový doklad. | Nie je daňovým dokladom. | Dieses Dokument ist keine Rechnung im umsatzsteuerlichen Sinne. |
| `watermark.cancelled` | STORNO | STORNO | STORNIERT |
| `totals.toPay` | K úhradě | K úhrade | Zahlbetrag |
| `totals.totalIn` | K úhradě v {currency} | K úhrade v {currency} | Zahlbetrag in {currency} |

### Popisky dat

| Klíč | cs | sk | de |
|---|---|---|---|
| `label.issueDate` | Datum vystavení | Dátum vyhotovenia | Ausstellungsdatum |
| `label.taxPointDate` | Datum zdanitelného plnění | Dátum dodania | Leistungsdatum |
| `label.dueDate` | Datum splatnosti | Dátum splatnosti | Fälligkeitsdatum |
| `label.paymentReceivedDate` | Datum přijetí platby | Dátum prijatia platby | Zahlungseingang |

### Strany a podpisy pokladního dokladu

| Klíč | cs | sk | de |
|---|---|---|---|
| `label.supplier` | Dodavatel / přijal | Dodávateľ / prijal | Lieferant / empfangen von |
| `label.customer` | Odběratel / vydal | Odberateľ / vydal | Kunde / gezahlt von |
| `signature.payer` | Podpis | Podpis | Unterschrift |
| `signature.recipient` | Podpis příjemce | Podpis príjemcu | Unterschrift des Empfängers |
| `signature.cashier` | Podpis pokladníka | Podpis pokladníka | Unterschrift des Kassierers |

### Poznámky DPH (`world.vat.cz`)

| Klíč | cs | sk | de |
|---|---|---|---|
| `vatNotes.pdp4` | Daň odvede zákazník | Prenesenie daňovej povinnosti | Steuerschuldnerschaft des Leistungsempfängers |
| `vatNotes.pdp5` | Daň odvede zákazník | Prenesenie daňovej povinnosti | Steuerschuldnerschaft des Leistungsempfängers |
| `vatNotes.eu` | Daň odvede zákazník | Prenesenie daňovej povinnosti | Steuerschuldnerschaft des Leistungsempfängers |

Kódy, které poznámku nesou (`vatCodes[].note`), a jejich tiskový popisek:

| Klíč | cs | sk | de |
|---|---|---|---|
| `cz-150` → `pdp4` | Základní - přenesení daňové povinnosti 4 | Základná - prenesenie daňovej povinnosti 4 | Normalsatz - Steuerschuldnerschaft des Leistungsempfängers 4 |
| `cz-151` → `pdp4` | Snížená - přenesení daňové povinnosti 4 | Znížená - prenesenie daňovej povinnosti 4 | Ermäßigter Satz - Steuerschuldnerschaft des Leistungsempfängers 4 |
| `cz-350` → `pdp4` | První snížená - přenesení daňové povinnosti 4 | Prvá znížená - prenesenie daňovej povinnosti 4 | Erster ermäßigter Satz - Steuerschuldnerschaft des Leistungsempfängers 4 |
| `cz-152` → `pdp5` | Základní - přenesení daňové povinnosti 5 | Základná - prenesenie daňovej povinnosti 5 | Normalsatz - Steuerschuldnerschaft des Leistungsempfängers 5 |
| `cz-201` → `eu` | EU/Zboží | EÚ/Tovar | EU/Waren |
| `cz-202` → `eu` | EU/Služby | EÚ/Služby | EU/Dienstleistungen |

### Navíc: identifikátory stran

| Klíč | cs | sk | de |
|---|---|---|---|
| `label.companyId` | IČ | IČO | Ident.-Nr. |
| `label.taxId` | DIČ | IČ DPH | USt-IdNr. |

### Texty e-mailu s dokladem (fáze 4, `tasks/prints-phase4.md` §5)

Předmět a tělo e-mailu, kterým se doklad odesílá — navrhla je implementace
fáze 4 (klíče `email.*` v `@docs.core/_layout/messages.jsonc`). Tělo je
prostý text: oslovení, co je v příloze, částka k úhradě a splatnost (jen
u platby převodem), pozdrav a název vlastní firmy.

| Klíč | cs | sk | de |
|---|---|---|---|
| `email.subject.document` | {title} — {sender} | {title} — {sender} | {title} — {sender} |
| `email.body.greeting` | Dobrý den, | Dobrý deň, | Guten Tag, |
| `email.body.attached` | v příloze najdete doklad: {title}. | v prílohe nájdete doklad: {title}. | im Anhang finden Sie: {title}. |
| `email.body.amountToPay` | Částka k úhradě: {amount} | Suma na úhradu: {amount} | Zu zahlender Betrag: {amount} |
| `email.body.dueDate` | Datum splatnosti: {date} | Dátum splatnosti: {date} | Fälligkeitsdatum: {date} |
| `email.body.regards` | S pozdravem | S pozdravom | Mit freundlichen Grüßen |

### Na co se při revizi zaměřit

- **Název prodejky:** zvoleno `sk` „Predajka“ a `de` „Verkaufsbeleg“;
  druhé možnosti z PRD jsou „Pokladničný blok“ a „Kassenbon“.
- **„Daňový doklad“ německy:** faktura je jen „Rechnung“ (plátce
  i neplátce), pokladní doklad a prodejka mají dovětek „– Steuerbeleg“.
  Rozhodnout, zda to sjednotit.
- **DPH německy:** popisky používají „MwSt.“ (běžné na dokladech), zákonný
  termín je „USt.“; identifikátor je „USt-IdNr.“.
- **`label.taxId` slovensky a německy** („IČ DPH“, „USt-IdNr.“): pod tím
  popiskem se tiskne české DIČ i u neplátce — stejně jako anglické
  „VAT ID“.
- **`vatNotes.eu`:** stejný text jako u přenesení daňové povinnosti nese
  i dodání zboží do EU (`cz-201`), kde jde o osvobozené plnění. Platí už
  pro češtinu — věcná otázka k čl. 226 směrnice, ne k překladu.
- **Texty e-mailu:** `{title}` je titulek dokladu s číslem v 1. pádě
  („Faktura – daňový doklad 2260011“), proto věta s dvojtečkou místo
  skloňování („posíláme fakturu“). Německy zkontrolovat oslovení a obrat
  „im Anhang finden Sie“.
- **Názvy sazeb německy:** „Normalsatz“, „Ermäßigter Satz“, „Nullsatz“,
  „Steuerfrei“ (všech 61 popisků je v `vat-cz.jsonc`, pole `print:de`).
