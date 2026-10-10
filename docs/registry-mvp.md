# Shipard — Spisovna MVP (`base.registry`)

> **Stav: MVP dokončeno (fáze 1–4, 2026-07).** Tento dokument je **design
> record** — zachycuje schválená rozhodnutí a fázování; nemazat, ale
> neaktualizovat. Provozní pravda žije v per-modul dokumentaci:
> [`modules/base/registry/README.md`](../modules/base/registry/README.md)
> a `tables/*.md`. Mimo MVP zbývá samostatný task migrace `wkf.docs`.
>
> **Názvosloví:** V UI a dokumentaci **Spisovna**; identifikátory v kódu
> anglicky — modul `base.registry`, tabulky `base_registry_*`.

## 0. Schválená koncepční rozhodnutí

| # | Rozhodnutí | Stav |
|---|---|---|
| D1 | Princip dispozic — každá zpráva končí v právě jedné dispozici, pošta tenduje k prázdnu | ✅ |
| D2 | Dvouosý model — `docKind` (systémový slovník, řídí metadata a AI) × šanon (uživatelská organizace) | ✅ |
| D3 | Šanony v MVP ploché; subjekt jen přes `partner`; úroveň „spis" odložena | ✅ |
| D4 | UI název **Spisovna**; identifikátory anglicky: modul `base.registry`, tabulky `base_registry_*` | ✅ |
| D5 | Jeden review workflow — `core_mail_extracted_documents` se zobecní o cíl (target), žádná paralelní tabulka | ✅ |
| D6 | Třífázová triáž: deterministika → levná klasifikace → hloubková extrakce; sender rules s učením | ✅ |
| D7 | Šum nikdy tiše: auto-archiv jen dle potvrzených pravidel + digest karta; AI auto-archiv až opt-in | ✅ |
| D8 | Fyzická příloha se při zařazení **kopíruje**; zpráva zůstává netknutá; checksum dedupe hlásí duplicity | ✅ |
| D9 | Metadata: univerzální pole jako sloupce (indexy, alerty), druhově specifická v JSON dle schématu druhu | ✅ |
| D10 | Intake agnostický ke zdroji; ruční pořízení od první fáze | ✅ |

## 1. Úvod a cíle

Do došlé pošty přichází vedle faktur (řeší existující extrakce → doklady)
i **trvalé dokumenty firmy** — smlouvy, pojistky, revizní zprávy, cenové
nabídky, úřední písemnosti — a také šum (reklama, newslettery). Spisovna je
evidence těchto trvalých dokumentů: místo, kam se „věci, které stojí za to"
zařazují do šanonů, s extrahovanými metadaty, souhrnem a časem i hlídáním
expirací.

Cílový pocit: **došlá pošta je transportní log, ne pracovní plocha.** Každá
zpráva skončí v jedné dispozici a uživatel na dashboardu vidí jen to, co
vyžaduje jeho rozhodnutí.

```
došlá zpráva ──► doklad      (faktura → extrakce → docs; existuje)
             ──► dokument    (→ Spisovna; toto MVP, fáze 1–2)
             ──► šum         (→ auto-archiv s digestem; fáze 3)
             ──► k akci      (→ úkol; výhled)
             ──► archiv/koš  (ručně; existuje)
```

Ve starém Shipardu existoval modul `wkf.docs` (Dokumenty) s podobným účelem
v primitivnější podobě — jeho data se budou importovat (viz §10).

### Co MVP dělá

- Nový modul `base.registry`: dokumenty + šanony, viewer, formulář
- Řízený slovník druhů (`docKinds`) s per-druh schématem metadat a
  sémantikou expirace (deklarativně, cfgItem)
- Ruční pořízení (upload) a ruční zařazení z došlé zprávy (kopie příloh)
- AI cesta: rozšířená klasifikace + extrakce dokumentů, zařazení přes
  stávající review workflow `core_mail_extracted_documents` (nový target)
- Dashboard karty pro návrhy zařazení (stejná mašinerie jako u faktur)
- Persistovaný extrahovaný text příloh → fulltext od začátku datového modelu
- Import dat ze starého `wkf.docs` (samostatný task migračního pipeline)

### Co MVP nedělá (vědomě)

- **Verzování dokumentů, per-šanon oprávnění, schvalovací workflow** — plné
  DMS není cíl (starý `wkf.docs` per-složková práva měl; nemigrují se)
- **Spisy / subjekty** (šanon → spis vozidla/zaměstnance) — model to nesmí
  zablokovat, ale nestaví se
- **OCR skenů bez textové vrstvy** — extrakce textu jen tam, kam AI analýza
  dosáhne dnes
- **Datová schránka jako intake** — `source_kind` je na to připravený,
  kanál přijde později
- **Vazby dokumentů** (dodatek → smlouva, nabídka → objednávka) — jen
  lineage sloupce, žádné UI; staré `doclinks` se nemigrují
- **Checklisty šanonů a chybějící dokumenty** — až fáze 4 aparátu, po
  ověření expirací
- **Hromadný backfill historické pošty** — retroaktivní klasifikace je
  samostatné rozhodnutí (objem/náklad), mimo MVP

## 2. Koncepty a terminologie

| Pojem (UI) | EN (kód) | Význam |
|---|---|---|
| Spisovna | registry | Evidence trvalých dokumentů firmy |
| Dokument | document | Jeden záznam Spisovny (smlouva, pojistka…) s metadaty a přílohami |
| Šanon | binder | Uživatelská organizační složka (Pojištění, Auta, BOZP…) |
| Druh dokumentu | docKind | Systémový řízený slovník; určuje schéma metadat, extrakci, expiraci |
| Dispozice | disposition | Kam zpráva patří (doklad / dokument / šum / akce); odvozený koncept, ne nový sloupec |

**Dokument ≠ doklad.** Doklad (`docs_core_heads`) je transakční účetní
entita s číselnou řadou a DPH. Dokument Spisovny je evidenční záznam bez
účetních důsledků — proto je i apply z AI bezpečnější a jednodušší.

**Dvě osy (D2):** AI klasifikuje **druh** (stabilní slovník → stabilní
prompt a output schema). Uživatel organizuje do **šanonů** (plastické,
per DS). AI šanon pouze *navrhuje*; `binder = NULL` je legitimní stav
(„Nezařazené") a viewer ho zobrazuje jako vlastní kbelík.

## 3. Modulová struktura

```
modules/base/registry/
├── module.jsonc
├── README.md
├── config/
│   ├── docKinds.jsonc          ← base.registry.docKinds
│   └── sourceKinds.jsonc       ← base.registry.sourceKinds
├── schemas/
│   └── registry-document-v1.json   ← shpd.registry.document.v1 (fáze 2)
├── tables/
│   ├── base_registry_binders.jsonc    (tableId 427)
│   ├── base_registry_binders.md
│   ├── base_registry_documents.jsonc  (tableId 428)
│   └── base_registry_documents.md
└── src/
    ├── RegistryDocumentDocument.php
    ├── BinderDocument.php
    ├── RegistryDocumentsViewer.php
    ├── BindersViewer.php
    ├── RegistryDocumentsForm.php
    ├── RegistryApplier.php            (fáze 2)
    └── FileFromMessageService.php     (fáze 1 — ruční zařazení)
```

- **Závislosti:** `core.system`, `core.attachments`, `base.persons`,
  `core.mail` (FK `source_message`, akce zařazení, fáze 2 applier).
  Směr závislosti je v pořádku — mail je níž.
- **Namespace:** `Shipard\Module\Base\Registry`.
- **Navigace:** viewer `base.registry.documents` (name:cs „Spisovna")
  v `_top` (navOrder ~35, hned za Došlou poštou); šanony v settings
  (`settingsItems`).
- **`keepOnReset`: žádný.** Šanony i dokumenty jsou migrovaná data ze
  starého `wkf.docs` (§10) — `ds-reset` je maže a obnoví je re-import.
  (Původní úvaha „šanony jako konfigurace" padla právě kvůli migraci.)
- **install.base:** přidat `base.registry` do dependencies.

## 4. cfgItemy

### 4.1 `base.registry.docKinds`

Řízený slovník druhů. Per druh: pole metadat (JSON klíče = **přesné názvy
polí AI output schématu**, žádné přejmenovávání), mapování vybraných polí na
promoted sloupce a sémantika expirace.

```jsonc
{
    // base.registry.docKinds
    //
    // Druhy dokumentů Spisovny. `fields` definují klíče v `metadata` JSON
    // (a zároveň pole extrakčního output schématu — názvy se NIKDY neliší).
    // `promote` mapuje vybraná pole na univerzální sloupce tabulky
    // (kvůli indexům, viewerům a deterministickým alertům).
    // `expiration.warnDaysBefore` čte promoted `valid_to`.

    "contract": {
        "name": "Contract", "name:cs": "Smlouva", "name:en": "Contract",
        "order": 10,
        "fields": ["counterparty", "subject", "signedDate", "contractNumber",
                   "validFrom", "validTo", "noticePeriod"],
        "promote": { "contractNumber": "ref_number",
                     "validFrom": "valid_from", "validTo": "valid_to" },
        "expiration": { "warnDaysBefore": [30, 7] }
    },
    "insurance": {
        "name": "Insurance policy", "name:cs": "Pojistná smlouva", "name:en": "Insurance policy",
        "order": 20,
        "fields": ["insurer", "policyNumber", "insuredSubject",
                   "validFrom", "validTo", "annualPremium", "currency"],
        "promote": { "policyNumber": "ref_number",
                     "validFrom": "valid_from", "validTo": "valid_to" },
        "expiration": { "warnDaysBefore": [30, 7] }
    },
    "quotation": {
        "name": "Quotation", "name:cs": "Cenová nabídka", "name:en": "Quotation",
        "order": 30,
        "fields": ["supplier", "subject", "totalAmount", "currency", "validUntil"],
        "promote": { "validUntil": "valid_to" },
        "expiration": { "warnDaysBefore": [7] }
    },
    "certificate": {
        "name": "Certificate / inspection", "name:cs": "Revize / certifikát",
        "name:en": "Certificate / inspection",
        "order": 40,
        "fields": ["subject", "issuer", "issuedDate", "validTo"],
        "promote": { "validTo": "valid_to" },
        "expiration": { "warnDaysBefore": [30, 7] }
    },
    "official": {
        "name": "Official correspondence", "name:cs": "Úřední písemnost",
        "name:en": "Official correspondence",
        "order": 50,
        "fields": ["authority", "refNumber", "receivedDate", "deadline"],
        "promote": { "refNumber": "ref_number", "deadline": "valid_to" },
        "expiration": { "warnDaysBefore": [14, 3] }
    },
    "other": {
        "name": "Other", "name:cs": "Ostatní", "name:en": "Other",
        "order": 999,
        "fields": [],
        "promote": {},
        "expiration": null
    }
}
```

Pozn.: `valid_to` u úřední písemnosti nese *lhůtu* — expirace pak přirozeně
znamená „blíží se termín". Sémantika promoted sloupce je vždy „datum, po
kterém dokument přestává být v pořádku bez zásahu".

Pozn. 2: Starý `wkf.docs` měl druhy jako per-DS DB řádky
(`wkf_docs_docsKinds`); nový slovník je řízený. Mapování řeší import (§10);
případná potřeba per-DS rozšíření slovníku je otevřený bod (§11).

### 4.2 `base.registry.sourceKinds`

```jsonc
{
    "manual":  { "name": "Manual",            "name:cs": "Ruční pořízení" },
    "mail":    { "name": "E-mail",            "name:cs": "Došlá pošta" },
    "import":  { "name": "Import",            "name:cs": "Import (migrace)" }
    // budoucí: "databox" (datová schránka), "scan"
}
```

### 4.3 Doc states — reuse `core.system.docStatesArchive`

Standardní archivační sada (10 Koncept, 80 V opravě, 40 V pořádku,
70 V archívu, 90 Smazáno) Spisovně sedí — včetně akce stavu 70
(„Ukončit platnost" = expirace/nahrazení). Významy pro Spisovnu:

| docState | Význam ve Spisovně |
|---|---|
| 10 Koncept | Rozpracované ruční pořízení / ruční zařazení z pošty |
| 40 V pořádku | **Zařazeno** — platný dokument (readOnly, edit přes 80) |
| 80 V opravě | Editace metadat / přeřazení |
| 70 V archívu | Ukončená platnost (expirace, nahrazení novějším) |
| 90 Smazáno | Koš |

Žádný nový cfgItem stavů. Kdyby labely („V pořádku" vs. „Zařazeno") v praxi
dřely, vznikne později vlastní `base.registry.docStates` se stejnou
topologií — čistě wording změna. Starý `wkf.docs` používal tutéž archivační
topologii (`e10.base.defaultDocStatesArchive`), takže i stavy se importují
přímočaře.

## 5. Tabulky

### 5.1 `base_registry_binders` (tableId 427)

```jsonc
{
    "tableId": 427,
    "name": "Binders", "name:cs": "Šanony", "name:en": "Binders",
    "displayPattern": "{name}",
    "docStates": {
        "stateColumn": "docState", "mainColumn": "docStateMain",
        "cfgItem": "core.system.docStatesArchive"
    },
    "columns": [
        {"id": "id", "type": "int", "autoIncrement": true, "primaryKey": true},
        {"id": "name", "name:cs": "Název", "type": "varchar", "length": 100, "nullable": false},
        {"id": "icon", "name:cs": "Ikona", "type": "varchar", "length": 30, "nullable": true},
        {"id": "order_pos", "name:cs": "Pořadí", "type": "smallint", "default": 0},
        {"id": "notice", "name:cs": "Poznámka", "type": "varchar", "length": 250, "nullable": true},
        {"id": "docState", "type": "tinyint", "default": 10, "system": true},
        {"id": "docStateMain", "type": "tinyint", "default": 1, "system": true},
        {"id": "created", "type": "datetime", "nullable": false, "system": true}
    ],
    "indexes": [
        {"id": "idx_name", "type": "index",
         "columns": [{"column": "docStateMain"}, {"column": "order_pos"}, {"column": "name"}]}
    ]
}
```

Unikátnost názvu mezi živými šanony vynucuje `BinderDocument::validate`
(vzor `is_own` v PersonDocument), ne DB unique (koš).

### 5.2 `base_registry_documents` (tableId 428)

```jsonc
{
    "tableId": 428,
    "name": "Registry documents", "name:cs": "Dokumenty spisovny",
    "name:en": "Registry documents",
    "displayPattern": "{title}",
    "docStates": {
        "stateColumn": "docState", "mainColumn": "docStateMain",
        "cfgItem": "core.system.docStatesArchive"
    },
    "columnGroups": [
        {"id": "identity", "name:cs": "Identifikace"},
        {"id": "subject",  "name:cs": "Subjekt"},
        {"id": "validity", "name:cs": "Platnost"},
        {"id": "content",  "name:cs": "Obsah"},
        {"id": "source",   "name:cs": "Původ"},
        {"id": "notes",    "name:cs": "Poznámky"}
    ],
    "columns": [
        {"id": "id", "type": "int", "autoIncrement": true, "primaryKey": true},

        // -- identity --
        {"id": "title", "name:cs": "Název", "type": "varchar", "length": 250,
         "nullable": false, "group": "identity"},
        {"id": "doc_kind", "name:cs": "Druh", "type": "enumString", "length": 30,
         "cfgItem": "base.registry.docKinds", "nullable": false, "default": "other",
         "group": "identity"},
        {"id": "binder", "name:cs": "Šanon", "type": "int", "nullable": true,
         "reference": "base_registry_binders", "group": "identity"},
        {"id": "ref_number", "name:cs": "Číslo / značka", "type": "varchar",
         "length": 100, "nullable": true, "group": "identity"},

        // -- subject --
        {"id": "partner", "name:cs": "Partner", "type": "int", "nullable": true,
         "reference": "base_persons_persons", "group": "subject"},

        // -- validity (promoted, plní beforeSave z metadata dle docKinds.promote) --
        {"id": "valid_from", "name:cs": "Platnost od", "type": "date",
         "nullable": true, "group": "validity"},
        {"id": "valid_to", "name:cs": "Platnost do / lhůta", "type": "date",
         "nullable": true, "group": "validity"},

        // -- content --
        {"id": "ai_summary", "name:cs": "Shrnutí (AI)", "type": "text",
         "nullable": true, "group": "content"},
        {"id": "metadata", "name:cs": "Metadata", "type": "json",
         "nullable": true, "group": "content"},
        {"id": "extracted_text", "name:cs": "Extrahovaný text", "type": "mediumtext",
         "nullable": true, "system": true, "group": "content"},

        // -- source --
        {"id": "source_kind", "name:cs": "Zdroj", "type": "enumString", "length": 20,
         "cfgItem": "base.registry.sourceKinds", "nullable": false,
         "default": "manual", "group": "source"},
        {"id": "source_message", "name:cs": "Zdrojová zpráva", "type": "int",
         "nullable": true, "reference": "core_mail_incoming_messages",
         "group": "source"},
        {"id": "extracted_doc", "name:cs": "Extrakce", "type": "int",
         "nullable": true, "reference": "core_mail_extracted_documents",
         "system": true, "group": "source"},

        // -- notes --
        {"id": "notice", "name:cs": "Poznámka", "type": "text",
         "nullable": true, "group": "notes"},

        // -- system --
        {"id": "docState", "type": "tinyint", "default": 10, "system": true},
        {"id": "docStateMain", "type": "tinyint", "default": 1, "system": true},
        {"id": "created", "type": "datetime", "nullable": false, "system": true},
        {"id": "created_by", "type": "int", "nullable": true,
         "reference": "core_system_users", "system": true},
        {"id": "modified", "type": "datetime", "nullable": true, "system": true}
    ],
    "indexes": [
        {"id": "idx_binder", "type": "index",
         "columns": [{"column": "binder"}, {"column": "docStateMain"}]},
        {"id": "idx_kind", "type": "index", "columns": [{"column": "doc_kind"}]},
        {"id": "idx_partner", "type": "index", "columns": [{"column": "partner"}]},
        {"id": "idx_valid_to", "type": "index",
         "columns": [{"column": "valid_to"}, {"column": "docStateMain"}]},
        {"id": "ft_head", "type": "fulltext",
         "columns": [{"column": "title"}, {"column": "ref_number"}, {"column": "ai_summary"}]},
        {"id": "ft_text", "type": "fulltext",
         "columns": [{"column": "extracted_text"}]}
    ]
}
```

Poznámky:

- **`metadata` je zdroj pravdy** druhově specifických polí;
  `RegistryDocumentDocument::beforeSave` z něj dle `docKinds.promote`
  synchronizuje promoted sloupce (a naopak: editace promoted pole ve
  formuláři se propíše do metadata). Jednosměrně jednodušší varianta pro
  fázi 1: formulář edituje promoted sloupce + generický JSON editor
  metadat; sync řeší beforeSave.
- **`extracted_text`** se ve fázi 1 neplní (sloupec připraven). Fáze 2 ho
  plní **na PHP straně** přes `pdftotext` (poppler-utils — na serverech už
  je kvůli thumbnailům) při zařazení; runner analýzy lokální text nemá, přílohy
  posílá modelu binárně. Táž služba jde zavolat i z ruční cesty.
- **Přílohy** standardně přes `core.attachments` (`table_id = 428`).

## 6. Fáze 1 — základ bez AI

### 6.1 Rozsah

Modul, tabulky, cfgItemy, viewer, formulář, šanony, ruční pořízení, ruční
zařazení z došlé zprávy. Žádný zásah do AI pipeline. Fáze má samostatnou
hodnotu a ověří datový model na alfě dřív, než na něj pustíme AI — a
odblokovává import ze starého Shipardu (§10).

### 6.2 Viewer `RegistryDocumentsViewer`

5-slotový layout řádku (vzor `IncomingMessagesViewer`):

- `t1` — title (bold)
- `i1` — `valid_to` relativně; při blízké expiraci warning badge
- `t2` — partner (fallback ref_number)
- `i2` — badge druhu (`docKinds` label, barva dle mapy)
- `t3` — `[šanon]` + první řádek `ai_summary`

**Spodní taby = šanony** (`TableViewer::getBottomTabs()`, id `all` /
šanon / `unfiled`; „Přidat“ ze záložky šanonu předvyplní šanon):
Vše / per šanon / Nezařazené (`binder IS NULL`). Fulltext hledá v `title`,
`ref_number`, `ai_summary` (fáze 2 přidá `extracted_text`).

Detail panel: taby Obsah (metadata dle druhu) / Přílohy / Původ (odkaz na
zdrojovou zprávu).

### 6.3 Formulář `RegistryDocumentsForm`

Taby: **Dokument** (title, druh — trigger recalculate, šanon, partner,
ref_number, platnosti, poznámka; vpravo read-only náhledy příloh, fill vzor
z `IncomingMessagesForm`), **Přílohy** (`AttachmentPanel`, tableId 428),
**Metadata** (druhově specifická pole — fáze 1 může začít generickým
zobrazením JSON, dynamický form dle `docKinds.fields` je nice-to-have).

### 6.4 Ruční zařazení z došlé zprávy

Endpoint `POST /api/v1/_registry/from-message/{messageNdx}`:

1. Validace: zpráva existuje, není v Koši.
2. Vytvoří dokument: `docState=10` (Koncept), `title` = subject,
   `source_kind='mail'`, `source_message`, `partner` = sender match na
   `base_persons_persons` dle e-mailu (pokud jednoznačný), `doc_kind='other'`.
3. **Kopie příloh (D8):** všechny obsahové přílohy zprávy (výběr jako
   `fetchContentAttachments()` — bez raw `.eml`) se fyzicky zkopírují a
   založí jako nové řádky `core_attachments_files` s `table_id=428`.
   Checksum dedupe vůči Spisovně: existuje-li už živý dokument Spisovny se
   stejným checksumem přílohy, vrátí se warning (`DUPLICATE_IN_REGISTRY`)
   — zařazení neblokuje.
4. Zpráva: nastaví se polymorfní vazba `target_table_id`/`target_row`
   (audit) a zpráva přejde do `docState=40` (Hotovo), pokud byla v 10/30.
5. Response: `{id}`; frontend otevře `FormDialog` nad novým záznamem —
   uživatel doplní druh/šanon a uloží do 40 (Zařazeno).

UI vstupní bod: toolbar akce **„Zařadit do Spisovny"** v detailu zprávy
(vedle „Znovu analyzovat"), viditelná mimo Koš.

### 6.5 Hotovo když (rámcově, detail v PRD)

- `ds-upgrade` vytvoří tabulky a cfg; `install.base` DS má modul aktivní
- CRUD dokumentu i šanonu funguje přes UI vč. stavového automatu
- Zařazení z pošty: kopie příloh, dedupe warning, zpráva → Hotovo,
  vazba `target_*` nastavena
- Testy: Document validace, promote sync, FileFromMessageService
  (kopie příloh + transakce), unikátnost názvu šanonu

## 7. Fáze 2 — AI cesta (target `registry`)

### 7.1 Rozšíření `core.mail.extractedDocTypes`

Každý typ dostane `target` (default `docs` pro zpětnou kompatibilitu) a
registry typy `docKind`:

```jsonc
"invoiceReceived": { ..., "target": "docs" },
"creditNote":      { ..., "target": "docs" },
"contract":    { "name:cs": "Smlouva",          "target": "registry", "docKind": "contract",    "enabled": true, "order": 110 },
"insurance":   { "name:cs": "Pojistná smlouva", "target": "registry", "docKind": "insurance",   "enabled": true, "order": 120 },
"quotation":   { "name:cs": "Cenová nabídka",   "target": "registry", "docKind": "quotation",   "enabled": true, "order": 130 },
"certificate": { "name:cs": "Revize/certifikát","target": "registry", "docKind": "certificate", "enabled": true, "order": 140 },
"official":    { "name:cs": "Úřední písemnost", "target": "registry", "docKind": "official",    "enabled": true, "order": 150 }
```

`core.mail.primaryTypes` se rozšíří o tytéž klíče (párování bez translation
tabulky zůstává zachované; `quotation` už existuje — jen se zapne).

### 7.2 Applier seam v `ExtractedDocumentApplier`

Datový model je připravený (`target_table_id`/`target_row_ndx` jsou už dnes
generické). Změny:

- `apply()` po načtení řádku resolvne target z
  `extractedDocTypes[doc_type].target ?? 'docs'`:
  - `docs` → stávající cesta (DocumentApplier, enrichment, `_resolve`
    merge) — **beze změny chování**;
  - `registry` → `RegistryApplier::applyFromExtracted()`.
- `unapply()` větví guard + úklid per target: pro `registry` guard
  „záznam stále `docState=40` a nezměněn od apply (`modified` ≤
  `applied_at`)", úklid = záznam → Koš (90) přes Document flow,
  extracted → 20 (`writeUnapplyTransition` beze změny).
- Registrace targetů **napevno** ve wiringu (vzor D10 u FeedSources —
  žádný plugin registr); deklarativní `module.jsonc` registrace je future.
- Sdílená status mašinerie (`writeStatusTransition`,
  auto-transition zprávy v `ExtractedDocumentDocument::afterPersist`,
  thresholds z profilu, reanalyze/supersede) — **beze změny**.

### 7.3 `RegistryApplier`

Vstup: `extracted_json` dle kontraktu `shpd.registry.document.v1` (viz 7.4).
Kroky (jedna transakce + kopie souborů s úklidem orphanů, vzor mail ingest):

1. Map na řádek: `title`, `doc_kind` (z extractedDocTypes.docKind),
   `metadata` (kindFields 1:1), promoted sync dle `promote`, `ai_summary`,
   `extracted_text` (viz krok 3), `source_kind='mail'`, `source_message`,
   `extracted_doc`, `binder` = návrh (7.5), partner resolve (7.6).
2. Dokument vzniká rovnou v **`docState=40` (Zařazeno)** — na rozdíl od
   dokladů tu není co finalizovat; jednoklik má být jednoklik. Vratnost
   zajišťuje unapply → Koš.
3. Kopie příloh dle `source_attachments` (D8, jako 6.4 krok 3) +
   best-effort extrakce textu kopií přes `pdftotext` do `extracted_text`
   (selhání extrakce apply neblokuje).
4. `target_table_id='base_registry_documents'`, `target_row_ndx`,
   status → applied (spustí auto-transition zprávy).

### 7.4 Kontrakt `shpd.registry.document.v1`

Výstup AI analýzy pro registry typy — **názvy polí = přesně `docKinds.fields`**
(model ani runner nepřejmenovávají; nesoulad = tiché prázdno, ověřená lekce):

```jsonc
{
    "schema": "shpd.registry.document.v1",
    "docType": "insurance",
    "title": "Pojistná smlouva — flotila vozidel",
    "summary": "2–3 věty: co to je, co z toho plyne, na co si dát pozor.",
    "party": { "name": "…", "companyId": "…", "email": "…" },
    "kindFields": { "insurer": "…", "policyNumber": "…", "validFrom": "…",
                     "validTo": "…", "annualPremium": 12345.0, "currency": "czk" },
    "binderSuggestion": "Pojištění"
}
```

Kontrakt je jednodušší než docs exchange — žádné povinné reference, žádný
`_resolve` resolve panel. Nepatří pod `core.exchange` schema machinery;
je to čistý kontrakt výstup modelu ↔ applier (JSON schema v
`modules/base/registry/schemas/`). Tentýž formát s volitelnými rozšířeními
používá import ze starého Shipardu (§10).

### 7.5 Návrh šanonu

Dvouvrstvě, bez LLM při apply:

1. **Historie**: nejčastější šanon dřívějších dokumentů téhož partnera a
   druhu (vzor RowHistoryEnricher — deterministická paměť).
2. **Jméno**: `binderSuggestion` z výstupu modelu se matchne case-insensitive
   na existující živé šanony; **nikdy nezakládá nový šanon** (safe mode
   analogie). Bez matche → `binder=NULL` (Nezařazené).

### 7.6 Partner resolve

Match dle `companyId` (IČO) → e-mail odesílatele → jinak NULL. Žádné
auto-zakládání osob (analogie `autoCreateMode='safe'`). Reuse
`PersonResolver` z `core.exchange`, pokud rozhraní sedne — ověří PRD.

### 7.7 Prompt / profily / triáž

- Klasifikace zprávy (prompt v3): enum `primary_type` rozšířený o registry
  typy; `documents[]` nově smí obsahovat registry extrakce.
- Extrakční profil: buď rozšíření default profilu, nebo druhý profil
  „filing" — rozhodne PRD podle nákladů (velikost promptu vs. dvojí
  volání). Levná triáž malým modelem (D6, plná kaskáda) je fáze 3 —
  fáze 2 smí jet v rámci stávajícího jednoprůchodu.
- Output schema pro kindFields se generuje z `docKinds` (nebo v první
  iteraci udržuje ručně se strojovou kontrolou shody názvů — test).

### 7.8 Dashboard

`MailSuggestionsSource` — karty vznikají ze stejného dotazu; per target se
liší jen prezentace: titulek `{docKind label} — {party.name}`, podtitulek
`platnost do {validTo} · jistota {confidence}` a label primární akce
(„Zařadit" místo „Použít"; action kinds beze změny). Apply fall-through
do resolve modalu se u registry targetů neuplatní (žádné povinné
reference) — chybové stavy jdou přímo do toastu.

## 8. Fáze 3 — šum (outline, detail v samostatném PRD)

- **Nová tabulka `core_mail_sender_rules`** (core.mail; pozor — nesouvisí
  s `core_mail_senders`, což jsou odchozí SMTP transporty): pattern
  (přesný e-mail / doména), dispozice (`archive` při příjmu, nebo výchozí
  `archiveIfOther` — archivovat až po analýze, jen zprávy bez dokladu
  a bez ničeho k vyřízení;
  `tasks/mail-sender-rules-after-analysis.md`), původ (user /
  ai-suggested), potvrzeno, statistiky zásahů.
- **Ingest signály**: sloupec `is_bulk` na zprávě (hlavička
  `List-Unsubscribe` a spol.) plněný mail-routerem/ingestem.
- **Deterministický pre-triage v ingestu**: zpráva matchující potvrzené
  pravidlo → `docState=80` (Archiv) + `analysis_state=0` + inkrement
  digestu. Bez LLM, bez fronty.
- **Digest karta** (info kind): „N zpráv automaticky archivováno dnes
  [Zobrazit] [Vrátit vše]" — jedna karta denně, plná vratnost (D7).
- **Učení**: handler nad akcemi Koš/Archiv počítá opakování per
  odesílatel; od prahu emituje návrhovou kartu „Vždy archivovat poštu od
  X?" → potvrzením vzniká pravidlo. Odesílateli, od kterého už přišel
  doklad nebo dokument, navrhne `archiveIfOther` („Archivovat poštu od X,
  když neobsahuje doklad ani nic k vyřízení?"); potvrzení odklidí i řádky
  Ostatní, které od adresy už čekají. Zprávu, která vyžaduje akci
  (`attention = action` — expirace, výzva k platbě, žádost;
  `tasks/mail-other-attention.md` D6), pravidlo nikdy neodklidí: zůstává
  v sekci K vyřízení. AI klasifikace šumu jen navrhuje;
  auto-archiv čistě z AI až za per-DS opt-in nastavením (settingsPage).
  `archiveIfOther` tuto zásadu D7 neporušuje: uživatel pravidlo potvrzuje
  pro konkrétní adresu (deterministický match), AI jen určí, že ve zprávě
  nic není; vše s auditem, v digestu a vratné.

## 9. Fáze 4 — aparát (outline)

- **Expirace přes `core.alerts`**: `alertChecks` registrace v
  `base.registry/module.jsonc` (vzor `core.mail.outbox_health`) — check čte
  `valid_to` + `docKinds.expiration`, severity dle blízkosti (info 30 d,
  warning 7 d, error po termínu). Alert akce `open_form` na dokument.
  Deterministické — žádné LLM.
- **MCP nástroj `registry_search`** (čtecí tier): fulltext + filtry druh /
  šanon / partner / platnost; tím se Spisovna otevře internímu chatu.
- **Checklisty šanonů** („šanon má obsahovat…") — až po ověření expirací
  v praxi.
- **Plnění `extracted_text` při uploadu** mimo cestu zařazení (reuse
  extraktoru z fáze 2) — zpřístupní fulltext i ručně pořízeným dokumentům.

## 10. Migrace ze starého Shipardu (`wkf.docs`)

Starý modul `wkf.docs` (Dokumenty) je přímý předchůdce: složky
(`wkf_docs_folders`, hierarchické), druhy (`wkf_docs_docsKinds`, per-DS DB
řádky), dokumenty (`wkf_docs_documents` s `title`, `validFrom`/`validTo`,
`folder`, `documentKind`, memo, přílohami). Import je samostatný task
migračního pipeline (`old_shipard: modules/imports/newShipard/`), zařaditelný
po dokončení fáze 1 (nezávislý na fázi 2).

### 10.1 Mapování

| `wkf.docs` (starý) | `base.registry` (nový) | Poznámka |
|---|---|---|
| `wkf_docs_folders` | `base_registry_binders` | **Zploštění hierarchie** (D3): šanon per živá složka; název ze `shortName`/`fullName`, u vnořených volitelně s cestou („Rodič / Dítě") — viz §11 |
| `folders.icon`, `order` | `icon`, `order_pos` | přímo |
| `wkf_docs_docsKinds` | `docKinds` cfgItem | **mapovací tabulka v runneru** (starý kind → nový klíč); bez mapování → `other`; původní název vždy do `metadata.legacyKind` |
| `documents.title` | `title` | přímo |
| `documents.folder` | `binder` | přes mapu složek |
| `documents.validFrom/validTo` | `valid_from`/`valid_to` | přímo — starý model promoted platnosti už měl |
| `documents.text` | `notice` | přímo |
| `documents.documentId` | `metadata.legacyId` | starý string identifikátor |
| `documents.author` (persons ref) | `created_by` + `metadata.legacyAuthor` | `created_by` je user, ne osoba: runner pro autora založí uživatele (`/_exchange/users/user/apply`, #93 D7) a pošle jeho id jako `createdBy`; jméno zůstává v metadatech |
| `documents.dateCreate` | `created` | přímo |
| `documents.docState` | `docState` | stejná archivační topologie |
| přílohy dokumentu | kopie souborů + `core_attachments_files` (`table_id=428`) | vzor mail importu |
| `doclinks` (vazby), klasifikace | **nemigrují** | mimo MVP |
| per-složková práva, `subFolderRightsType` | **nemigrují** | Spisovna nemá per-šanon oprávnění |
| `shipardEmailId`, `analyzeAttachments` | **nemigrují** | intake řeší nová pipeline |

### 10.2 Mechanika

- Import jede přes `shpd.registry.document.v1` (§7.4) rozšířený o volitelný
  blok pro import: `binder` (jméno), `legacy {id, kind, author}`,
  `docState`, `created`; endpoint `POST /api/v1/_registry/import`
  (analogie `/_mail/import`), `source_kind='import'`.
- Pořadí: šanony (malý objem, mapa starý ndx → nový id) → dokumenty
  (keyset pagination, vzor mail/base runnerů) → přílohy.
- Idempotence / re-run: dedupe klíč `metadata.legacyId`
  (fallback starý `ndx`) — re-import po `ds-reset` je čistý; proto šanony
  ani dokumenty **nejsou** v `keepOnReset` (§3).
- Detail (dávkování, `--continue-on-error`, reset subcommand) v PRD
  migračního tasku dle konvencí `AllRunner`.

## 11. Otevřené body

1. **Zpráva → Hotovo při ručním zařazení (6.4 krok 4):** nastavuje se hned
   při vzniku Konceptu — pokud uživatel Koncept zahodí, zpráva zůstane
   Hotovo (vrátí ji ručně). Alternativa (event handler až při 10→40) je
   složitější; navrženo jednodušeji. Potvrdit.
2. **Kopie všech obsahových příloh** při zařazení vs. výběr uživatelem
   (checkboxy). MVP: všechny + mazání ve formuláři.
3. **Dynamický formulář metadat** dle `docKinds.fields` — fáze 1
   generický JSON, plnohodnotný dynamický form možná až s fází 2.
4. **Profil vs. druhý profil** pro registry extrakce (7.7) — rozhodne PRD
   fáze 2 podle velikosti promptu.
5. **Backfill** historické pošty (per DS, ohraničený datem, on-demand
   CLI?) — samostatné rozhodnutí mimo MVP.
6. **Mapování starých druhů → `docKinds`:** před PRD migrace se podívat na
   reálný obsah `wkf_docs_docsKinds` v produkčních DS (alfa) a podle něj
   zvážit doplnění výchozí sady druhů; zbytku dá runner `other` +
   `legacyKind`. Souvisí s otázkou, zda slovník umožnit rozšiřovat per DS.
7. **Zploštění hierarchie složek:** název šanonu ze `shortName` vs. celá
   cesta u vnořených složek — rozhodnout podle reálné hloubky stromů
   v datech.

---

[← README.md](README.md) · [Došlá pošta](../modules/core/mail/docs/documentation.md) ·
[AI analýza](../modules/core/mail/docs/ai-analysis.md) · [Dashboard](dashboard.md) ·
[Přílohy](attachments.md)
