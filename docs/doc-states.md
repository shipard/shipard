# Shipard — Stavy dokumentů (Doc States)

## 1. Přehled

Stavy dokumentů jsou systém životního cyklu záznamu v databázové tabulce. Každý záznam (dokument) prochází definovanými stavy — od vzniku přes platnost až po archivaci nebo smazání. Systém je konfigurovatelný: každá tabulka může mít vlastní sadu stavů s vlastními přechody, barvami a chováním.

Systém stavů dokumentů se projevuje na dvou místech UI:
- **Prohlížeč (viewer)** — taby nad seznamem záznamů filtrují podle skupiny stavů
- **Editační formulář** — tlačítka ve spodní části formuláře přepínají stav a ukládají dokument

---

## 2. Databázové sloupce

Každá tabulka s podporou stavů dokumentů obsahuje dva systémové sloupce:

```sql
docState     TINYINT NOT NULL DEFAULT 10,  -- aktuální stav dokumentu
docStateMain TINYINT NOT NULL DEFAULT 1,   -- hodnota pro řazení (ORDER BY)
```

### `docState`

Uchovává aktuální stav dokumentu jako číselnou hodnotu (10, 20, 40, …). Hodnoty jsou záměrně řídké (desetiny), aby bylo možné vkládat nové stavy bez přečíslování.

### `docStateMain`

Uchovává hodnotu pro řazení v prohlížeči, odvozenou z konfigurace stavu (`mainState`). Systém ji nastavuje automaticky při každé změně `docState` — uživatel ji nikdy nevidí ani neupravuje.

**Proč je `docStateMain` nutný:**

Pořadí stavů v prohlížeči (Koncept nahoře, pak V pořádku, pak Archív) neodpovídá numerickému pořadí hodnot `docState`. Navíc v dokladech musí mít Storno (docState=30) **stejné** pořadí jako V pořádku (docState=40), aby se v seznamu prolínalo s platnými doklady a řadilo dle čísla dokladu.

Příklad ORDER BY pro prohlížeč dokladů:
```sql
ORDER BY docStateMain ASC, doc_number DESC
```

Tak doklady V pořádku a Stornované budou mít stejný `docStateMain` a seřadí se dle čísla dokladu sestupně.

---

## 3. Deklarace v definici tabulky

V JSONC definici tabulky se přidá pole `docStates`. Samotné sloupce `docState` a `docStateMain` se deklarují v `columns` s příznakem `"system": true` — systémové sloupce jsou spravovány automaticky a nezobrazují se uživateli ve formulářích.

```jsonc
{
    "tableId": 201,
    "name": "Persons",

    // Deklarace systému stavů dokumentů
    "docStates": {
        "stateColumn": "docState",
        "mainColumn": "docStateMain",
        "cfgItem": "core.system.docStatesArchive"
    },

    "columns": [
        // ... ostatní sloupce ...

        // Systémové sloupce — spravovány automaticky, nezobrazují se v UI
        {
            "id": "docState",
            "name": "Document state",
            "type": "tinyint",
            "default": 10,
            "system": true
        },
        {
            "id": "docStateMain",
            "name": "Document state (sort)",
            "type": "tinyint",
            "default": 1,
            "system": true
        }
    ]
}
```

### Pole `docStates`

| Pole | Typ | Povinné | Popis |
|------|-----|---------|-------|
| `stateColumn` | string | Ano | Název sloupce s aktuálním stavem (obvykle `docState`) |
| `mainColumn` | string | Ano | Název sloupce pro řazení (obvykle `docStateMain`) |
| `cfgItem` | string | Ano | Reference na konfigurační položku se stavovým automatem |

### Příznak `system: true` na sloupci

Sloupce označené `"system": true` jsou spravovány backendem — `CrudController` je vyřazuje z normálního zápisu přes `filterWritableFields()`. Výjimkou je `docState`, který lze změnit dedikovanou cestou (viz sekce 10).

---

## 4. Formát cfgItem — definice stavů

Každý stav je objekt s klíčem = číselná hodnota docState (jako string).

```jsonc
{
    "10": {
        // Název stavu — zobrazuje se v UI (badge, tooltip, …)
        "stateName": "Koncept",
        "stateName:cs": "Koncept",
        "stateName:en": "Draft",

        // Text tlačítka, které dokument DO tohoto stavu přepne
        "actionName": "Uložit jako koncept",
        "actionName:cs": "Uložit jako koncept",
        "actionName:en": "Save as draft",

        // CSS identifikátor pro obarvení prvků dle stavu (třída docState_{stateStyle})
        "stateStyle": "concept",

        // Hodnota pro ORDER BY (docStateMain) — nastavuje se automaticky
        "mainState": 1,

        // Skupina pro filtrování v prohlížeči (viz sekce 5)
        "viewGroup": "active",

        // Výčet stavů, do kterých lze přejít z tohoto stavu
        "goto": [40, 70, 90]
    },

    "40": {
        "stateName": "V pořádku",
        "stateName:cs": "V pořádku",
        "stateName:en": "Confirmed",
        "actionName": "V pořádku",
        "actionName:cs": "V pořádku",
        "actionName:en": "Confirm",
        "stateStyle": "done",
        "mainState": 3,
        "viewGroup": "active",
        "readOnly": 1,
        "enablePrint": 1,
        "goto": [80, 70, 90]
    }
}
```

### Přehled polí

| Pole | Typ | Povinné | Popis |
|------|-----|---------|-------|
| `stateName` | string | Ano | Název stavu (výchozí jazyk) |
| `stateName:cs` / `:en` | string | Doporučeno | Jazykové varianty názvu stavu |
| `actionName` | string | Ano | Text tlačítka přechodu do tohoto stavu |
| `actionName:cs` / `:en` | string | Doporučeno | Jazykové varianty textu tlačítka |
| `stateStyle` | string | Ano | CSS identifikátor (viz sekce 6) |
| `mainState` | int | Ano | Hodnota pro `docStateMain` / ORDER BY |
| `viewGroup` | string | Ano | Skupina pro filtrování v prohlížeči: `active`, `archive`, `trash` |
| `goto` | int[] | Ano | Stavy, do kterých lze přejít z tohoto stavu |
| `readOnly` | 0/1 | Ne | Dokument nelze editovat — musí se nejdřív přepnout do V opravě (default: 0) |
| `enablePrint` | 0/1 | Ne | Dokument lze tisknout/exportovat (default: 0) |

---

## 5. Taby prohlížeče — `viewGroup`

Prohlížeč zobrazuje taby, které filtrují záznamy podle skupiny stavů. Systém načte cfgItem tabulky a odvodí seznam hodnot `docState` pro každý tab:

| Tab | Filtr | `viewGroup` hodnoty |
|-----|-------|---------------------|
| **Aktivní** | docState IN (…) | `active` |
| **Archív** | docState IN (…) | `archive` |
| **Koš** | docState IN (…) | `trash` |
| **Vše** | bez filtru | — |

Tab se zobrazí, jen pokud cfgItem obsahuje alespoň jeden stav s danou `viewGroup`. Tab „Vše" je vždy přítomen. Výchozí tab je „Aktivní".

Backend viewer exposes dostupné viewGroups přes `GET /_ui/viewer/{id}/meta` v poli `viewGroups`. Frontend `Viewer.svelte` tab bar zobrazí jen pokud je toto pole neprázdné.

---

## 6. CSS stateStyle — barvy stavů

Každý stav má `stateStyle` identifikátor. Systém přidává CSS třídu ve tvaru `docState_{stateStyle}` na příslušné HTML elementy (řádek v prohlížeči, hlavička formuláře, badge stavu).

| `stateStyle` | Barva | Použití |
|---|---|---|
| `concept` | žlutá | Koncept — nový, nepotvrzený dokument |
| `confirmed` | modrá | K řešení — rozpracovaný stav (došlá pošta, `docStatesIncoming`) |
| `done` | světle zelená | V pořádku — platný, readOnly |
| `edit` | oranžová | V opravě — dočasně odemčen k editaci |
| `archive` | šedá | V archívu — platný, neaktivní |
| `trash` | černá/přeškrtnuté | Smazáno — neplatný, v koši |
| `cancelled` | červená | Storno — zrušený doklad (zachovává číslo dokladu) |

CSS třídy jsou definovány v `ViewerRow.svelte` jako `:global` pravidla (aby se aplikovaly přes Svelte scoping). Výběr přebíjí barvu docState (always wins).

`stateStyle` určuje i **mobilní umístění přechodu ve footeru formuláře** (`FormStateBar`): na mobilu (≤ 768px) jdou do kebab menu (⋮) destruktivní přechody (`archive`/`trash`/`cancelled` — Archivovat, Stornovat, Smazat; v kebabu červeně) a `concept` (Uložit jako koncept — návrat zpět na koncept, pomocná akce; v kebabu neutrálně). Postupové přechody (V pořádku `done`, Opravit `edit`…) zůstávají viditelné vedle Uložit. Na desktopu beze změny — viz `docs/edit-forms.md`, sekce „Toolbar formuláře (FormStateBar)".

### `mobileKebab` — volitelný příznak stavu

Někdy `stateStyle` na rozlišení nestačí, protože stejný styl má napříč typy dokladů různou důležitost: `edit` je u faktur „Opravit" (hlavní akce, viditelná), ale u úkolů „Pozastavit" (vedlejší, patří do kebabu). Stav v docStates JSONC proto může mít volitelný `"mobileKebab": 1` — přechod na takový stav se na mobilu vždy zařadí do kebabu (neutrálně, pokud není zároveň destruktivní), bez ohledu na `stateStyle`. Na desktopu nemá příznak žádný efekt. `DocStateConfig::getAvailableTransitions()` ho propaguje do payloadu přechodu (`mobileKebab: bool`). Po přidání/změně je nutný `vendor/bin/shpd-ds ds-upgrade` (docStates je cfgItem kompilovaná do `compiled.<jazyk>.json`).

---

## 7. Standardní sada stavů — `core.system.docStatesArchive`

Používá se pro většinu tabulek s archivačním modelem (osoby, číselníky, nastavení, …).

| docState | Název | mainState | viewGroup | readOnly | Přechody do |
|----------|-------|-----------|-----------|----------|-------------|
| 10 | Koncept | 1 | active | — | 40, 70, 90 |
| 80 | V opravě | 2 | active | — | 40, 70, 90 |
| 40 | V pořádku | 3 | active | 1 | 80, 70, 90 |
| 70 | V archívu | 4 | archive | 1 | 80 |
| 90 | Smazáno | 5 | trash | 1 | 80 |

Konfigurační soubor: `modules/core/system/config/docStatesArchive.jsonc`

---

## 8. Příklad rozšířené sady — `docs.core.docStates`

Doklady (faktury, objednávky, …) přidávají stav Storno a vynechávají archiv:

| docState | Název | mainState | viewGroup | readOnly | Přechody do |
|----------|-------|-----------|-----------|----------|-------------|
| 10 | Koncept | 1 | active | — | 40, 90 |
| 80 | V opravě | 3 | active | — | 40, 30, 10, 90 |
| 40 | V pořádku | **4** | active | 1 | 80, 30, 90 |
| 30 | Storno | **4** | active | 1 | 80 |
| 90 | Smazáno | 5 | trash | 1 | 80 |

**Klíčový detail:** Storno a V pořádku mají stejný `mainState` (4). V prohlížeči s `ORDER BY docStateMain ASC, doc_number DESC` se tak stornované doklady přirozeně prolínají s platnými a řadí se dle čísla dokladu. Po hodnotě 1 (Koncept) následuje 3 — mainState 2 patřil zrušenému stavu Potvrzeno (20) a díra se záměrně nezaplňuje (žádný backfill `docStateMain`).

**Číslo dokladu a návrat do Konceptu:** číslo z číselné řady se přiděluje při přechodu 10 → 40 (`DocDocument::processStateTransition`). Přechod 80 → 10 (V opravě → Koncept) číslo uvolňuje — smí ho provést jen poslední doklad v řadě, jinak by v sekvenci vznikla díra (`releaseDocumentNumber` padá `DomainException`). Aby UI nenabízelo slepou cestu, hook `Document::filterStateTransitions` (implementace v `DocDocument`) přechod →10 z nabídky vyřadí, není-li doklad poslední v řadě.

---

## 8.1 Došlá pošta — `core.mail.docStatesIncoming`

Workflow stavy došlé zprávy (tabulka `core_mail_incoming_messages`): **Nová → K řešení → Hotovo**, s možností archivace nebo přesunu do koše z kteréhokoli aktivního stavu. Tato sada používá klíče `stateName` / `actionName` (ne `name`).

| docState | Název | mainState | viewGroup | readOnly | Přechody do |
|----------|-------|-----------|-----------|----------|-------------|
| 10 | Nová | 1 | active | — | 20, 40, 80, 90 |
| 20 | K řešení | 2 | active | — | 40, 10, 80, 90 |
| 40 | Hotovo | 3 | active | 1 | 80, 90, 10 |
| 80 | Archiv | 4 | archive | 1 | 10, 90 |
| 90 | Smazáno | 5 | trash | 1 | 10 |

**Stav analýzy je ortogonální a není to `docState`.** Pipeline drží svůj status ve sloupci `analysis_state` (cfgItem `core.mail.analysisStates`: *Bez analýzy* 0, *Ve frontě* 10, *Analyzuje se* 20, *Analyzováno* 30, *Analýza selhala* 70). `IncomingMessagesViewer` z něj vykresluje samostatný badge v řádku. Změna jednoho stavu nesmí implikovat změnu druhého.

**K řešení (20):** editovatelný stav. `POST /result` přepne zprávu 10→20 automaticky, když běh přinesl validní dokumentový návrh a zpráva je stále v Nové.

**Hotovo (40):** zpráva je vyřízená — uživatel návrh použil nebo zamítl (verdikt `resolution` na řádku analýzy). Přechod dělá `MessageProposalApplier` atomicky se zápisem verdiktu; unapply ho vrací (40→20). Vznikl-li doklad, odkaz je v `target_table_id` / `target_row`.

Konfigurační soubor: `modules/core/mail/config/docStatesIncoming.jsonc`

---

## 9. Backend — klíčové třídy a chování

### `DocStatesDefinition` (`src/Core/Document/DocStatesDefinition.php`)

Parsuje `docStates` blok z JSONC definice tabulky. Drží `stateColumn`, `mainColumn`, `cfgItem`. Součást `TableDefinition`.

### `DocStateConfig` (`src/Core/Document/DocStateConfig.php`)

Wrapper nad načteným cfgItem. Klíčové metody:
- `getMainState(int $docState): int` — vrátí hodnotu pro `docStateMain`
- `isReadOnly(int $docState): bool` — zjistí zda stav zakazuje editaci
- `isTransitionAllowed(int $from, int $to): bool` — ověří přechod přes `goto`
- `getViewGroupStates(string $viewGroup): int[]` — vrátí docState hodnoty pro daný viewGroup
- `getAvailableTransitions(int $currentState): array` — vrátí přechody pro API odpověď

### `Document::filterStateTransitions` — per-dokumentové filtrování nabídky

Nabídku z `getAvailableTransitions()` může Document třída zúžit hookem
`filterStateTransitions(array $transitions, array $row): array` (default
pass-through). Aplikuje ho `DocStateTransitionFilter::apply()` v obou
producentech přechodů do UI: `CrudController::docStateOptions()` a
`FormController` (form load i recalculate). Bez registrované Document třídy
nebo bez DB je hook pass-through. Hook jen skrývá slepé cesty v nabídce —
vynucením zůstávají server-side bariéry (validace, výjimky v `beforeSave`).
První uživatel: `DocDocument` vyřazuje přechod →10, není-li doklad poslední
v číselné řadě (viz sekce 8).

### `CrudController` — vynucení stavů

Při každém `create`/`update`/`patch`:
- `filterWritableFields()` přeskočí sloupce s `system: true`
- `initDocState()` (create): nastaví `docState` (default 10) a `docStateMain` z cfgItem
- `processDocState()` (update/patch):
  - Je-li aktuální stav `readOnly` a tělo obsahuje jiné pole než `docState` → `422 DOCUMENT_READONLY`
  - Je-li v těle `docState`: ověří `goto`, nastaví `docStateMain` → `422 INVALID_STATE_TRANSITION` jinak
- Pokud `ConfigRuntime` není k dispozici (config ještě není zkompilovaný), celá doc state logika se přeskočí — degraduje gracefully

### `TableGateway` — persistenční dopočet `docStateMain`

Dopočet `docStateMain` je centralizovaný v persistenční vrstvě: `TableGateway::saveDocument()` po `Document::beforeSave()` odvodí `docStateMain` z cfgItemu (`DocStateConfig::getMainState()`), kdykoli má tabulka `docStates` a payload obsahuje stavový sloupec s nenull hodnotou. To je **jediné místo pravdy** pro `docStateMain` — platí pro každou zápisovou cestu přes Document/Gateway, tedy i pro importní exchange Appliery (`PersonApplier`, `DocumentApplier`, `ItemApplier`) a `StatementImportService`.

`TableGateway` dostává `DocStatesDefinition` konstruktorem; volající ji předává z `TableDefinition->docStates`. Bez `docStates` nebo bez `ConfigRuntime` se dopočet tiše přeskočí (stejná degradace jako v `CrudController`).

Dopočet je idempotentní — na update z plného řádku (merge `existing` + patch) se `docStateMain` přepočítá ze současného `docState`, takže re-sync existujícího záznamu zároveň opraví případně nesprávnou hodnotu.

Dopočet v controllerech (`CrudController::initDocState`/`processDocState`; ruční dopočty v `MailController`/`AnalysisController`/`ChatController`) zůstává jako redundantní pojistka. Přímé `$dibi->insert` cesty přes Gateway nejdou — ty si `docStateMain` řeší samy.

---

## 10. REST API — stavové přechody

### Zjištění dostupných přechodů

```
GET /{table}/{id}/doc-state-options
```

Odpověď:
```json
{
    "success": true,
    "data": {
        "currentState": 40,
        "stateName": "V pořádku",
        "stateStyle": "done",
        "readOnly": true,
        "transitions": [
            {"state": 80, "stateName": "V opravě", "actionName": "Opravit", "stateStyle": "edit"},
            {"state": 70, "stateName": "V archívu", "actionName": "Ukončit platnost", "stateStyle": "archive"},
            {"state": 90, "stateName": "Smazáno", "actionName": "Smazat", "stateStyle": "trash"}
        ]
    }
}
```

Vrácené `transitions` jsou už prohnané hookem `filterStateTransitions`
(viz sekce 9) — nabídka se může u konkrétního záznamu lišit od holého `goto`.

### Provedení přechodu

Přechod se provádí přes standardní `PATCH /{table}/{id}` s jediným polem v těle:

```json
{"docState": 80}
```

Backend ověří přechod, nastaví `docStateMain` a uloží. Pokud je záznam `readOnly` a tělo obsahuje navíc jiná pole, vrátí `422`.

---

## 11. Viewer systém — integrace

`TableViewer` (bázová třída) podporuje viewGroup filtrování, pokud subclass nastaví `$docStatesCfgItem`:

```php
class PersonsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';
}
```

Tím se automaticky:
- `getViewGroups()` vrátí dostupné skupiny (odvozené z cfgItem) → zobrazí tab bar
- `buildViewGroupFilter($cfgItemId, $viewGroup)` generuje SQL `WHERE docState IN (…)` helper pro `selectRows()`

Meta endpoint `GET /_ui/viewer/{id}/meta` vrací pole `viewGroups` (seznam skupin), podle kterého frontend Viewer.svelte rozhodne, zda zobrazit tab bar.

---

## 12. Implementační stav

### Fáze 1 — Databáze a definice tabulek ✅

- `docState`, `docStateMain` sloupce přidány do `base_persons_persons`
- `docStates` deklarace v JSONC definici tabulky
- `DocStatesDefinition` v `TableDefinition`
- `ColumnDefinition` rozšířena o `system: bool`

### Fáze 2 — Backend ✅

- `DocStatesDefinition`, `DocStateConfig` — nové třídy
- `CrudController`: `filterWritableFields` přeskakuje system sloupce, `initDocState` + `processDocState` enforce readOnly a goto
- `GET /{table}/{id}/doc-state-options` — nový endpoint
- `ConfigRuntime` sdílena přes všechny controllery (načítána jednou v `index.php`)

### Fáze 3 — Frontend: prohlížeče ✅

- `TableViewer`: `setConfig()`, `getViewGroups()`, `buildViewGroupFilter()`
- `ViewerRegistry.createViewer()` předává ConfigRuntime
- `ViewerController.meta()` vrací `viewGroups`
- `PersonsViewer`: viewGroup filter, `docStateMain` ORDER BY, `stateStyle` v `renderRow()`
- `Viewer.svelte`: tab bar Aktivní / Archív / Koš / Vše, viewGroup filter v API volání
- `ViewerRow.svelte`: `docState_{stateStyle}` CSS třída na řádku

### Fáze 4 — Frontend: editační formuláře (odloženo)

- Stavová tlačítka ve spodní části formuláře (ze `goto` aktuálního stavu)
- Zamknout formulář je-li `readOnly: 1`, zobrazit tlačítko „Opravit"
- Zobrazit aktuální stav v hlavičce formuláře (badge s barvou `stateStyle`)
