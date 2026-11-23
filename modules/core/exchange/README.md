# core.exchange

Backend modul implementující **kanonické výměnné formáty** pro Shipard
entity — JSON struktury, které lze validovat, vizualizovat, ukládat
do DB, importovat z cizích zdrojů a exportovat.

| Formát | Specifikace | Stav |
|---|---|---|
| `shpd.docs.document.v1` — doklady | [docs/exchange-format.md](../../../docs/exchange-format.md) | Fáze 3b hotová |
| `shpd.persons.person.v1` — osoby | [docs/exchange-format-persons.md](../../../docs/exchange-format-persons.md) | Fáze 1 hotová |
| `shpd.items.item.v1` — položky | [docs/exchange-format-items.md](../../../docs/exchange-format-items.md) | Fáze 1 hotová |

Modul je čistě servisní vrstva — žádné vlastní tabulky, žádné viewers
ani formy. Vystavuje 9 REST endpointů (po třech pro každý formát).

**Ukládání dokladů — kam se podívat podle vrstvy** (#166 D56):

| Vrstva | Dokument |
|---|---|
| doklad — pole, výchozí hodnoty, validace, výpočet, číslování | [docs/document-system.md](../../../docs/document-system.md) (§ 12c), [docs/vat-calculation.md](../../../docs/vat-calculation.md) |
| formát a ukládací API (pokyn) — reference, chybějící × `null`, importní mód, idempotence, REST, „Doklad skriptem“ | [docs/exchange-format.md](../../../docs/exchange-format.md) |
| interpretace (návrh z pošty a ISDOC) — hledání podle údajů, DPH přijatých dokladů, volby uživatele, zakládání, učení, `/interpret` | [docs/document-interpretation.md](../../../docs/document-interpretation.md) |

## Architektura

```
ExchangeController                                  (src/Api/Controller/)
  ├─→ DocumentApplier                               (Document/)
  │    ├─→ SchemaValidator                          (Schema/)         ← opis/json-schema
  │    ├─→ PartyResolver, ItemResolver, UnitResolver,
  │    │   VatCodeResolver, BankAccountResolver     (Resolve/)
  │    ├─→ DocumentValidator                        (Document/)
  │    └─→ TransactionlessTableGateway              (Common/)
  │         └─→ DocDocument                         (modules/docs/core/src/)
  │
  ├─→ PersonApplier                                 (Person/)
  │    ├─→ SchemaValidator                          (Schema/)
  │    ├─→ PersonResolver                           (Person/)
  │    │    ├─→ PartyResolver (s personType filtrem) (Resolve/)
  │    │    ├─→ AddressResolver, ContactResolver,
  │    │    │   BankAccountResolver                  (Resolve/)
  │    │    └─→ closingExisting enumerator (fullSync)
  │    ├─→ PersonValidator                          (Person/)
  │    └─→ TransactionlessTableGateway              (Common/)
  │         └─→ PersonDocument                      (modules/base/persons/src/)
  │
  └─→ ItemApplier                                   (Item/)
       ├─→ SchemaValidator                          (Schema/)
       ├─→ ItemFlowResolver                         (Item/)
       │    ├─→ ItemResolver (supplierPersonId=null) (Resolve/)
       │    ├─→ KindResolver                         (Resolve/)
       │    ├─→ UnitResolver                         (Resolve/)
       │    ├─→ SupplierCodesResolver                (Resolve/)
       │    │    └─→ PartyResolver (personType=company)
       │    └─→ code_conflict probe
       ├─→ ItemValidator                            (Item/)
       ├─→ PartyToPersonCanonical                   (Common/)
       │    └─→ PersonApplier (supplier autocreate)
       └─→ TransactionlessTableGateway              (Common/)
            ├─→ ItemDocument                         (modules/economy/items/src/)
            └─→ ItemKindDocument (side-create)       (modules/economy/items/src/)
```

Sdílené třídy v `Common/`: `ApplyResult` (response shape, `savedId` generic
pole — controller mapuje na `savedDocId` / `savedPersonId` / `savedItemId`
v JSON response), `TransactionlessTableGateway`, a `PartyToPersonCanonical`
(statický Party → Person canonical mapper; používá ItemApplier pro supplier
autocreate flow).

Applier vlastní outer transakci (MariaDB nedoporučuje nested `START TRANSACTION`).
Side-creates → save → sub-collections → lineage update probíhají atomicky
pod jedním `$db->begin/commit`.

## REST API

Devět endpointů: tři pro doklady, tři pro osoby, tři pro položky:

| Method | Path | Účel |
|---|---|---|
| POST | `/api/v1/_exchange/docs/document/validate` | Statická validace dokladu |
| POST | `/api/v1/_exchange/docs/document/preview` | Validate + resolve referencí (no DB writes) |
| POST | `/api/v1/_exchange/docs/document/apply` | Validate + resolve + reconcile + uložit |
| POST | `/api/v1/_exchange/persons/person/validate` | Statická validace osoby |
| POST | `/api/v1/_exchange/persons/person/preview` | Validate + resolve + closingExisting (no DB writes) |
| POST | `/api/v1/_exchange/persons/person/apply` | Validate + resolve + reconcile + uložit |
| POST | `/api/v1/_exchange/items/item/validate` | Statická validace položky |
| POST | `/api/v1/_exchange/items/item/preview` | Validate + resolve (kind/unit/supplierCodes/code_conflict probe) |
| POST | `/api/v1/_exchange/items/item/apply` | Validate + resolve + reconcile + uložit |

### Error response shape

```json
{
  "success": false,
  "error": {
    "code": "unresolved_required",
    "message": "Reference „supplier“ vyžaduje rozhodnutí (userAction).",
    "details": {
      "canonical": { /* enriched canonical s _resolve.issues */ }
    }
  }
}
```

Kódy chyb:

| Kód | HTTP | Kdy |
|---|---|---|
| `schema_invalid` | 400 | JSON neprošel JSON Schema |
| `validation_failed` | 422 | Doc/PersonValidator nahlásil error |
| `unresolved_required` | 422 | Reference vyžaduje rozhodnutí (`userAction`) |
| `conflict` | 409 | Neplatná `userAction` nebo cíl už neexistuje |
| `person_exists` | 409 | Person flow: `createOnly` + matched header |
| `person_id_conflict` | 409 | Person flow: `personId` koliduje s jinou osobou |
| `item_exists` | 409 | Item flow: `createOnly` + matched header |
| `code_conflict` | 409 | Item flow: `code` v payloadu koliduje s jinou položkou (defence-in-depth) |
| `internal_error` | 500 | Interní chyba (rollback proběhl) |

## Curl příklady

> V dev módu (server.json `mode=development`) jde proxy přes prefix
> `/<ds-id>/` a Host musí být IP adresa. V produkci se DS resolvuje
> podle Host hlavičky.

### `/validate` — minimální payload

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -H 'Host: 127.0.0.1' \
  -d '{"format":"shpd.docs.document","formatVersion":"1.0","docType":"invoiceReceived","dates":{"issueDate":"2026-04-15"},"supplier":{"name":"X"},"rows":[{"rowKind":"item"}]}' \
  http://127.0.0.1/<ds-id>/api/v1/_exchange/docs/document/validate
```

Odpověď: `{"success":true,"data":{"canonical": {...enriched...}, "savedDocId":null}}`

> **Dva režimy** (#166, `docs/exchange-format.md` § 3, § 5 „Reference na
> záznamy“): REST je **pokyn** — každý odkaz na záznam je reference
> (`"customer": "@F00001"`, `"item": "@KONZ"`), nic se nehledá podle jména
> a nic nezakládá; minimální payload je v `docs/exchange-format.md` § 11
> „Doklad skriptem“. Příklady níže s hledáním podle údajů, `_resolve`
> a zakládáním partnera jsou režim **návrh** — vyžádá si ho
> `applyOptions.interpret: true` (`docs/document-interpretation.md`).

### `/preview` — vrátí `_resolve` s navrženými matches

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -H 'Host: 127.0.0.1' \
  -d @tests/Fixtures/Exchange/invoiceReceived_happy.json \
  http://127.0.0.1/<ds-id>/api/v1/_exchange/docs/document/preview
```

V odpovědi je `data.canonical._resolve.supplier.status` jeden ze
`matched | ambiguous | canCreate | notFound`. Klient na základě toho
vyplní `_resolve.supplier.userAction` (jednu z: `useExisting:<id>`,
`create`, `skip`) a pošle do `/apply`.

### `/apply` v režimu návrh — uložení dokladu s vytvořením neexistujícího partnera

```bash
# Pošli canonical, ve kterém pro každou neresolved referenci je
# vyplněn _resolve.<klíč>.userAction. Pro draft (docState=10) stačí
# Applieru jen vlastní firma (is_own=1) v base_persons_persons.

curl -X POST \
  -H 'Content-Type: application/json' \
  -H 'Host: 127.0.0.1' \
  -d '{
    "format":"shpd.docs.document",
    "formatVersion":"1.0",
    "source":{"kind":"manual","extractedAt":"2026-05-14T10:30:00Z"},
    "docType":"invoiceReceived",
    "docNumber":"SUPP-2026-001",
    "selfParty":"customer",
    "supplier":{
      "name":"New Vendor s.r.o.","country":"CZ","companyId":"12349999",
      "bankAccount":{"iban":"CZ65...","accountNumber":"123/0100","currency":"CZK"}
    },
    "dates":{"issueDate":"2026-04-15","accountingDate":"2026-04-15","taxPointDate":"2026-04-15"},
    "currency":"CZK",
    "vat":{"mode":"fromBase","place":"domestic","registrationCountry":"CZ"},
    "payment":{"method":"bankTransfer"},
    "rows":[{
      "rowKind":"item","orderPos":1,
      "item":{"name":"Konzultace","supplierCode":"KONZ-1"},
      "unit":"h","quantity":10,"unitPrice":1000,"totalPrice":10000,
      "priceCalcMode":"fromUnitPrice",
      "vat":{"code":"cz-110","pct":21}
    }],
    "_resolve":{
      "supplier":{"userAction":"create"},
      "supplierBank":{"userAction":"create"},
      "rows":[{"item":{"userAction":"create"}}]
    },
    "applyOptions":{"targetDocState":10,"interpret":true}
  }' \
  http://127.0.0.1/<ds-id>/api/v1/_exchange/docs/document/apply
```

V odpovědi:
- `data.savedDocId` — id nového záznamu v `docs_core_heads`
- `data.canonical._resolve.supplier.status = "matched"` s nově přiřazeným `matchedId`
- `data.canonical._resolve.summary.status = "ok"` (pokud žádné `notFound`)

## Curl příklady — Person flow

Plná spec a popis polí: [docs/exchange-format-persons.md](../../../docs/exchange-format-persons.md).

### `/validate` — minimální payload (firma)

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -H 'Host: 127.0.0.1' \
  -d '{
    "format":"shpd.persons.person","formatVersion":"1.0",
    "personType":"company","country":"cz",
    "companyId":"12345678",
    "name":{"fullName":"Acme s.r.o."}
  }' \
  http://127.0.0.1/<ds-id>/api/v1/_exchange/persons/person/validate
```

### `/preview` — fullSync re-import z ARES

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -H 'Host: 127.0.0.1' \
  -d @tests/Fixtures/Exchange/persons/company_fullSync.json \
  http://127.0.0.1/<ds-id>/api/v1/_exchange/persons/person/preview
```

V odpovědi `data.canonical._resolve` obsahuje:
- `header.status` = `matched` / `canCreate` / `ambiguous`
- `addresses[]`, `bankAccounts[]`, `contacts[]` per-index resolve
- `closingExisting` — sub-záznamy, které applier uzavře (`valid_to = today`)
  při `fullSync`
- Adresy Provozovna (typ 3) a Zařízení (typ 4) matched podle `placeRegId`
  mají `authoritativeRefresh: true` — applier přepíše jejich pole i pod
  `mergeStrategy: mergeAdd` (registr je zdroj pravdy).

### `/apply` — vytvoření nové firmy z importu

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -H 'Host: 127.0.0.1' \
  -d '{
    "format":"shpd.persons.person","formatVersion":"1.0",
    "source":{"kind":"import.ares","registryRef":"12345678"},
    "personType":"company","country":"cz",
    "companyId":"12345678","taxId":"CZ12345678","vatId":"CZ12345678",
    "name":{"fullName":"Acme s.r.o."},
    "contact":{"email":"info@acme.cz","phone":"+420 100 200 300"},
    "addresses":[
      {"addressType":1,"name":"Sídlo","isStandardized":true,
       "street":"Karlova","houseNumber":"15","city":"Praha",
       "zip":"11000","country":"cz","registryCode":"21794160",
       "displayLine":"Karlova 15, 110 00 Praha 1"}
    ],
    "bankAccounts":[
      {"accountNumber":"1234567890/0100","iban":"CZ65...","currency":"CZK"}
    ],
    "applyOptions":{"mergeStrategy":"createOnly","targetDocState":40}
  }' \
  http://127.0.0.1/<ds-id>/api/v1/_exchange/persons/person/apply
```

V odpovědi:
- `data.savedPersonId` — id nové osoby v `base_persons_persons`
- `data.canonical._resolve.summary.status = "applied"`
- `data.canonical._resolve.header.status = "matched"` s `matchedId = savedPersonId`
- Sub-záznamy mají vyplněné `matchedId` (po insertu)
- Sloupce `source_kind`, `source_ref`, `source_imported_at` v
  `base_persons_persons` vyplněné (lineage); UI editace bez `source.kind`
  v payloadu lineage zachová.

### MergeStrategy — kdy co použít

| Strategy | Chování při matched headeru |
|---|---|
| `createOnly` | Reject s 409 `person_exists` |
| `updateHeader` | Přepsat hlavičku, sub-kolekce netknout |
| `mergeAdd` *(default)* | Hlavička: doplnit prázdná DB pole. Sub: matched → nech (kromě authoritativeRefresh → overwrite); canCreate → insert |
| `fullSync` | Hlavička: overwrite celá. Sub: matched → update; canCreate → insert; existující missing v payloadu → `valid_to = today` (closing per `address_type`) |

## Curl příklady — Item flow

Plná spec a popis polí: [docs/exchange-format-items.md](../../../docs/exchange-format-items.md).

### `/validate` — minimální payload

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -H 'Host: 127.0.0.1' \
  -d '{
    "format":"shpd.items.item","formatVersion":"1.0",
    "name":"Konzultace IT","unit":"h",
    "kind":{"code":"service"}
  }' \
  http://127.0.0.1/<ds-id>/api/v1/_exchange/items/item/validate
```

### `/preview` — náhled s `_resolve` (kind/unit/code_conflict)

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -H 'Host: 127.0.0.1' \
  -d @tests/Fixtures/Exchange/items/service_create_happy.json \
  http://127.0.0.1/<ds-id>/api/v1/_exchange/items/item/preview
```

V odpovědi `data.canonical._resolve`:
- `header.status` = `matched` / `canCreate` / `ambiguous` (přes ourCode → ean → sku → name)
- `kind.status` + `matchedBy` (`system_code` / `name` / `itemTypeFallback`); warning `kind_inferred_from_itemType` v `issues[]` pokud byl použit fallback
- `unit.status` (`matched` / `notFound`); warning `unit_unknown` v `issues[]` pokud nematchne (applier fallback na `pcs`)
- `supplierCodes[]` per-index s `supplier` ResolveResult + sub-status `matched` / `canCreate` / `skipped`

### `/apply` — vytvoření nové položky s autocreate kind + supplier mapping

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -H 'Host: 127.0.0.1' \
  -d '{
    "format":"shpd.items.item","formatVersion":"1.0",
    "source":{"kind":"import.oldShipard","registryRef":"12345"},
    "code":"KONZ-001","name":"Konzultace IT",
    "kind":{"code":"service"},
    "salesPriceNoVat":1500,"unit":"h",
    "supplierCodes":[
      {"supplier":{"companyId":"12345678"},
       "supplierCode":"VEN-KONZ-1","supplierName":"Konzultace IT (Acme)"}
    ],
    "applyOptions":{"mergeStrategy":"createOnly","targetDocState":40}
  }' \
  http://127.0.0.1/<ds-id>/api/v1/_exchange/items/item/apply
```

V odpovědi:
- `data.savedItemId` — id nové položky v `economy_items`
- `data.canonical._resolve.summary.status = "applied"`
- `data.canonical._resolve.header.status = "matched"` s `itemId = savedItemId`
- `data.canonical._resolve.kind.kindId`, `unit.unitId` — resolved FK ids
- `supplierCodes[0].status = "canCreate"` → mapping byl INSERT IGNORE-d
  v rámci apply transakce; `supplierCode` a `supplierName` echo z payloadu
- Sloupce `source_kind`, `source_ref`, `source_imported_at` v
  `economy_items` vyplněné (lineage); UI editace bez `source.kind` v payloadu
  lineage zachová.

### MergeStrategy — items specifika

| Strategy | Chování při matched headeru |
|---|---|
| `createOnly` | Reject s 409 `item_exists` |
| `updateHeader` | Přepsat hlavičku, supplierCodes netknout |
| `mergeAdd` *(default)* | Hlavička: doplnit prázdná DB pole (description, sku, ean, salesPriceNoVat, …). supplierCodes: existující ponechat, chybějící INSERT IGNORE |
| `fullSync` | Hlavička: overwrite všech pole (kromě `code`). supplierCodes: stejně jako mergeAdd — **žádný closing**, tabulka nemá `valid_to` ani `docState` (spec §6.4) |

## docType mapping

Canonical používá popisné názvy (`invoiceReceived`, `invoiceIssued`).
Applier je při transformu mapuje na interní krátké kódy v cfgItem
`docs.core.docTypes` (`invni`, `invno`). Krátký kód lze poslat i přímo.

## Currency case

Canonical: uppercase ISO 4217 (`"CZK"`, `"EUR"`). Interní sloupec
`docs_core_heads.doc_currency` je lowercase (`"czk"`). Mapování dělá
Applier v transform fázi — resolvery a validátory pracují s canonical
formátem.

## Stav (M1–M4 — Fáze 1)

- ✅ DB schema: `economy_items` (sku/ean), `economy_items_supplier_codes`,
  `docs_core_heads` (partner_doc_number + lineage skupina), cfgItem
  `docs.core.sourceKinds`
- ✅ JSON Schema (`shpd.docs.document.v1.{json,jsonc}`) + opis/json-schema validátor
- ✅ 5 resolverů: Party, Item, Unit, VatCode, BankAccount
- ✅ DocumentApplier s plnou pipeline §10 (validate → resolve → reconcile → side-creates → transform → save → lineage)
- ✅ REST endpointy `/validate`, `/preview`, `/apply`
- ✅ PHPUnit pokrytí (51 unit testů + 5 integration testů)

## Limity Fáze 1

- **Fuzzy matching** pro `PartyResolver` step 4 je jen `LIKE %name%` —
  Levenshtein/trigram score přijde v iteraci.
- **Schema kompilace JSONC → JSON** je ruční. Drift hlídá
  `SchemaDriftTest`. CLI příkaz `shpd-server compile-schemas` je
  follow-up.
- **Attachment re-link** z `extracted_doc` na nový doc_head je SQL UPDATE
  v Applieru s `TODO` — `AttachmentService` API pro re-link přijde,
  až bude víc volajících.
- **Update existujícího dokladu** přes `/apply` není v Fázi 1; jen create.
- **Batch apply** (víc dokladů v jednom requestu) není v Fázi 1.

## Stav (Fáze 2)

- ✅ AI analyzer napojen — profile `czech_invoices` `v2.0.0` instruuje
  AI k produkci canonical `shpd.docs.document.v1` v poli
  `extracted_documents[].extracted_json`.
- ✅ `AnalysisController::result` validuje AI výstup proti canonical
  schématu; invalid → `status = 70 (ai_failed)` s wrapperem
  `{_validationError, _validationIssues, _rawOutput}`. AI se neretryje
  na malformed payload — uživatel řeší přes UI / reanalyze.
- ✅ `applyExtracted` (UI "Použít") deleguje na `DocumentApplier::apply`
  s `applyOptions = {autoCreateMode: "safe", targetDocState: 10}`.
  Statusy: ai_failed → 422, already-applied → 200 idempotent, partially-
  applied (target_row_ndx set, status≠40) → recovery via status update.
- ✅ `applyOptions.autoCreateMode` se 3 režimy (strict/safe/liberal).
  Per-tabulka safety guard: Party `company_id`, Item `name`,
  BankAccount `iban` ∨ `account_number`.
- ✅ Idempotent re-apply: applier i controller umí — applier přes
  `source.extractedDoc + target_row_ndx + status=40` lookup, controller
  navíc přes recovery cestu (target_row_ndx set, status pending).
- ✅ Lineage rozdělená: applier zapíše `target_*` v rámci své transakce,
  status update + auto-transition zprávy běží přes
  `ExtractedDocumentDocument` hooks v separátní controller transakci.
- ✅ `reanalyze` rozšířen — superseduje i `STATUS_AI_FAILED`.
- ✅ `ProfileSchemaDriftTest` hlídá schéma dokladu vložené v profilu pošty
  (`output_schema` → `document.…extracted_json.oneOf[0]`) — od #166 D63 je
  to **papírová podoba** (`Schema\PaperSchema` nad značkami `x-paper`), ne
  doslovná kopie; změna schématu profilu vyžaduje novou verzi promptu (D65).

## Stav (Fáze 3a)

- ✅ Frontend náhled canonical dokumentu — modal `DocumentExchangePreviewModal`
  se split-view (PDF přílohy vlevo + canonical vizualizace vpravo).
- ✅ `POST /_mail/extracted-documents/{ndx}/preview` — read-only enrichment
  přes `DocumentApplier::preview()`. `ai_failed` (status=70) → vrátí wrapper.
- ✅ `AttachmentController::download?inline=1` — inline disposition jen pro
  `application/pdf` + `image/*` (XSS prevence v same-origin iframe).
- ✅ 3 Exchange Svelte komponenty: `PdfViewerPanel`,
  `DocumentExchangePreview` (read-only badges), `DocumentExchangePreviewModal`.
- ✅ `EntityPicker.svelte` jako standalone univerzální search-and-pick (3a:
  postaven a otestovaný; produkční použití přijde v 3b).
- ✅ Modal `width="full"` (95vw × 95vh) + mobile `<768px` tab switcher
  (PDF / Náhled).
- ✅ Klik "Detail" otevře nový preview modal, JSON dump (Phase 1 placeholder)
  odstraněn z `ViewerDetail`.

## Stav (Fáze 3b)

- ✅ Interaktivní status badges v Preview — non-matched badge je `<button>`,
  klik otevře `Popover` s `ResolveDecisionPanel` (Vytvořit / Vybrat
  existujícího / Přeskočit). EntityPicker se mountuje z panelu pro
  "Vybrat existujícího".
- ✅ `userActions` flat map (`{path: action}`) — `'supplier'`,
  `'customer'`, `'supplierBank'`, `'rows[N].item'`. Akumulace v
  Preview komponentě, propagace přes `onUserActionsChange` callback.
- ✅ Decided badge states (`matchedDecided` / `canCreateDecided` / `skipped`)
  s outline-based visual indikátorem (lépe čitelné v 18px badge než
  kombinované glyfy).
- ✅ Tlačítko "Použít" disabled dokud zůstávají nerozhodnuté non-matched
  reference (kromě unit/vatCode — applier má fallbacky).
- ✅ Backend `applyExtracted` přijímá flat `_resolve` v body, expanduje
  na nested `_resolve.{path}.userAction` pro applier. `expandUserActions`
  + `mergeUserActions` helpery silently skip neznámé / non-string hodnoty.
- ✅ `autoCreateMode` derivace per request body: `{}` = `safe` (CLI / pre-3b),
  `{_resolve: ...}` (s nebo bez entries) = `strict` (3b client),
  `applyOptions.autoCreateMode` explicit override.
- ✅ Race condition handling — `unresolved_required` z applieru po preview
  (DB state se změnil) → localized alert, modal zůstává otevřený.
- ✅ Nested popover/modal — click-outside na popover ignoruje target
  uvnitř `.shpd-modal` (EntityPicker portal-mounted do body).
- ✅ `Popover.svelte` univerzální floating panel s viewport-flip a Escape close.

## Navazující fáze

- **Fáze 3c** (volitelně, později) — edit canonical hodnot před apply
  (oprava IČO, doplnění data). Aktuální 3b interakce eliminují většinu
  potřeby; zbytek se řeší přes form-editor po apply (Koncept doklad →
  standard FormEditor).
- Drobnosti k zvážení: multi-field OR search v EntityPicker (`code` OR
  `name` pro Item), bulk decisions ("vytvoř všechny canCreate"),
  person-scoped filter pro BankAccount picker.

## Stav (Persons Fáze 1)

- ✅ DB schema: sub-tabulky `base_persons_addresses`, `_contacts`,
  `_bank_accounts` dostaly `docState` + `docStateMain` + index
  `idx_doc_state` (oprava latentního bugu v existujícím
  `BankAccountResolver` filtru).
- ✅ DB schema: `base_persons_persons` lineage skupina —
  `source_kind` (varchar 40, cfgItem `base.persons.sourceKinds`),
  `source_ref` (varchar 60), `source_imported_at` (datetime).
- ✅ cfgItem `base.persons.sourceKinds` se 7 klíči (manual, aiExtraction,
  import.ares, import.rpo, import.handelsregister, import.shipardRegistry,
  import.csv).
- ✅ JSON Schema `shpd.persons.person.v1.{json,jsonc}` + drift test.
- ✅ Sdílený `Common/ApplyResult` a `Common/TransactionlessTableGateway`
  (refactor z `Document/` namespace, generic `savedId` pole; controller
  mapuje na `savedDocId`/`savedPersonId` v JSON response).
- ✅ `PartyResolver` rozšířen o optional `?PersonType` filter parametr —
  zpětně kompatibilní s doc flow (default `null`).
- ✅ Nové resolvery: `AddressResolver` (placeRegId → registryCode →
  displayLine priority, `authoritativeRefresh = true` pro IČP/IČZ match),
  `ContactResolver` ((name, email) → (name) priority),
  `PersonResolver` (orchestrátor header + sub-kolekce + closingExisting
  pro fullSync).
- ✅ `PersonValidator` — polymorfismus per `personType`, `placeRegType`/Id
  per `addressType in [3,4]`, `iban` OR `accountNumber`,
  `is_own + targetDocState=40` vyžaduje `companyId`.
- ✅ `PersonApplier` s plnou pipeline §11 (validate → resolve → reconcile
  → header upsert → sub-collection insert/update/close → lineage).
- ✅ REST endpointy `/api/v1/_exchange/persons/person/{validate,preview,apply}`.
- ✅ PHPUnit pokrytí: 69 unit testů (PersonValidator 17, AddressResolver 13,
  ContactResolver 7, PersonResolver 10, PersonApplier 12, ResolveResult drift)
  + 6 integration testů (per fixture).

## Limity Persons Fáze 1

- **Frontend UI** (modal náhled + popover `userAction`, analogie doc
  flow Fáze 3) — samostatný task.
- **PersonExporter** (DB → canonical) — Persons Fáze 3, potřeba pro
  registry sync a export mezi Shipard DS.
- **Batch apply** (víc osob v jednom requestu, CSV import) — Persons Fáze 3.
- **Country filter v `PartyResolver` name probe** — krok 4
  (`docs/document-interpretation.md` § 5.1)
  je follow-up (Phase 1 jen LIKE %name%).
- **Batch `divisionCode → world_divisions.id` lookup** — Phase 1
  per-adresa round-trip; pro CSV bulk import bude bottleneck.
- **ARES / RPO / Handelsregister adaptér** (HTTP klient + mapping
  registr-JSON → canonical) — Persons Fáze 2.

## Stav (Items Fáze 1)

- ✅ DB schema: `economy_items` lineage skupina — `source_kind`
  (varchar 40, cfgItem `economy.items.sourceKinds`), `source_ref`
  (varchar 60), `source_imported_at` (datetime).
- ✅ cfgItem `economy.items.sourceKinds` s 5 klíči (manual, aiExtraction,
  import.oldShipard, import.csv, import.supplierCatalog).
- ✅ JSON Schema `shpd.items.item.v1.{json,jsonc}` + drift test
  (data provider stávajícího `SchemaDriftTest`).
- ✅ Nové resolvery: `KindResolver` (4 strategie — `system_code` → `name`
  exact match → `canCreate` (pokud `name` + miss) → `itemTypeFallback`
  → `notFound`; `FALLBACK_KIND_BY_ITEM_TYPE` jako PHP konstanta proti
  driftu se `ItemKindsProvisioner` seedem), `SupplierCodesResolver`
  (volá `PartyResolver` s `personType=company`, lookup `(person,
  supplier_code)` na `economy_items_supplier_codes`).
- ✅ Sdílený `Common/PartyToPersonCanonical` — statický Party → Person
  canonical mapper. Používá ho jen ItemApplier (supplier autocreate);
  DocumentApplier má vlastní zkrácenou cestu přes
  `PartyResolver::buildPersonCreatePayload()`.
- ✅ `ItemValidator` — `name` / `unit` required, `salesPriceNoVat ≥ 0`,
  `kind` alespoň jeden hint, `code` pattern `/^\S{1,25}$/`, `sku`/`ean`
  délka, `targetDocState` enum.
- ✅ `ItemFlowResolver` orchestruje header (deleguje na existující
  `ItemResolver` s `supplierPersonId=null`) + kind + unit + supplierCodes;
  emit warningy `kind_inferred_from_itemType`, `unit_unknown`,
  `supplier_unknown`; explicit `code_conflict` probe (defence-in-depth
  pro budoucí změny ItemResolveru).
- ✅ `ItemApplier` s plnou pipeline §10 (validate → resolve → reconcile
  → side-create kind → header upsert → supplierCodes INSERT IGNORE /
  autocreate partner → lineage update); `code` v payloadu = explicit pass,
  `code` null/missing = drop key → `ItemDocument::beforeSave` generuje hex;
  `item_type` se nepředává — `ItemDocument` denormalizuje z `item_kind`.
- ✅ REST endpointy `/api/v1/_exchange/items/item/{validate,preview,apply}`
  + Router prefix `item:` + DI wiring v `public/index.php`.
- ✅ PHPUnit pokrytí: 75 unit testů (KindResolver 14, ItemValidator 26,
  SupplierCodesResolver 10, PartyToPersonCanonical 7, ItemFlowResolver
  12, ItemApplier 16 + 5 schema validator + 7 controller + 5 router)
  a 8 integration testů (per fixture v `tests/Integration/Exchange/Items/`).

## Limity Items Fáze 1

- **Frontend UI** (modal náhled + popover `userAction`, analogie doc
  Fáze 3b) — Items Fáze 3, mimo scope.
- **`ItemExporter`** (DB → canonical) — Items Fáze 2, pro registry sync
  s katalogy dodavatelů.
- **Batch apply** (víc položek v jednom requestu, CSV import) —
  Items Fáze 4.
- **Exchange formát pro `economy_items_kinds`** (`shpd.items.kind.v1`) —
  pokud bude potřeba sdílet katalogy druhů. Items Fáze 1 řeší ad-hoc
  `canCreate` v rámci apply pipeline.
- **Country / division filter** pro header `name` LIKE search — analogicky
  persons Phase 1 limit.
- **Sjednocení doc + items flow** (jeden code path pro vytváření partnerů
  přes `PersonApplier`) — Phase 2 follow-up. DocumentApplier dnes píše
  do `base_persons_persons` přímo přes `PartyResolver::buildPersonCreatePayload()`
  + `personsGateway`, nevolá PersonApplier. Items flow naopak vždy
  deleguje na PersonApplier.
- **Lineage v `economy_items_supplier_codes`** — Phase 1 sub-tabulka
  lineage tracking nemá (sledování je na parent `economy_items`).
  Přidá se, pokud bude troubleshooting per-mapping častý.

## Související

- [docs/exchange-format.md](../../../docs/exchange-format.md) — kanonická specifikace dokladů a ukládací API (pokyn)
- [docs/document-interpretation.md](../../../docs/document-interpretation.md) — interpretace papírové podoby dokladu (návrh z pošty a ISDOC)
- [docs/exchange-format-persons.md](../../../docs/exchange-format-persons.md) — kanonická specifikace osob
- [docs/exchange-format-items.md](../../../docs/exchange-format-items.md) — kanonická specifikace položek
- [tests/Fixtures/Exchange/](../../../tests/Fixtures/Exchange/) — fixture JSON soubory (doc + `persons/` + `items/`)
- [tests/Integration/Exchange/](../../../tests/Integration/Exchange/) — E2E testy
