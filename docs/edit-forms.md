# Shipard — Editační formuláře (Edit Forms)

## 1. Přehled a cíle

Editační formuláře jsou klíčovým prvkem UX celého systému. Architektura je:

- **Server-driven** — server definuje obsah, layout i chování formuláře; klient ho jen renderuje
- **Generická na klientovi** — jedna sada Svelte komponent zvládne formuláře pro všechny tabulky
- **Flexibilní na serveru** — jednodušší tabulky = JSONC definice bez PHP kódu; složitější = PHP třída `TableForm`
- **Responzivní** — 4-sloupcový grid systém, řídí layout na různých šířkách obrazovky
- **Integrovaná s doc states** — stavová tlačítka, readOnly formuláře, `closeForm` chování

---

## 2. Architektura — přehled

```
FormEditor.svelte          (frontend — hlavní shell: záhlaví, taby, toolbar)
  ├─ FormTab.svelte        (jeden tab — CSS Grid 4 sloupce)
  │   └─ FormElement.svelte (dynamický renderer elementu)
  └─ FormSubTable.svelte   (sub-editor related tabulky)
       ↕ REST API
FormController             (PHP — meta, save, recalculate)
  ↕
TableForm (abstract)       (PHP — bázová třída; TableDefinition, TabBuilder)
  ↕
PersonsForm                (PHP — konkrétní Form pro base.persons)
  — nebo —
forms/{table}.jsonc        (JSONC — deklarativní definice pro jednoduché formuláře)
  — nebo —
AutoFormBuilder            (PHP — automatická generace z TableDefinition)
```

### Srovnání s Viewer systémem

| Viewer | Form |
|--------|------|
| `TableViewer` (abstraktní) | `TableForm` (abstraktní) |
| `PersonsViewer extends TableViewer` | `PersonsForm extends TableForm` |
| `GET /_ui/viewer/{id}/meta` | `GET /_ui/form/{table}/meta[/{id}]` |
| Registrace v `module.jsonc` → `viewers` | Registrace v `module.jsonc` → `forms` |

---

## 3. FormDefinition — datová struktura

Server vrací `FormDefinition` z endpointu `/_ui/form/{table}/meta`. Klient ji renderuje bez per-formulář JS logiky.

### Kořenová struktura (JSON)

```json
{
    "success": true,
    "formDefinition": {
        "table": "base_persons_persons",
        "title": "Osoba",
        "title_new": "Nová osoba",
        "tabs": [ { "...tab..." } ],
        "doc_states": {
            "currentState": 10,
            "stateName": "Koncept",
            "stateStyle": "concept",
            "read_only": false,
            "transitions": [
                {"state": 40, "actionName": "V pořádku", "stateStyle": "done", "close_form": true}
            ]
        }
    },
    "data": {
        "id": 42,
        "full_name": "Jan Novák",
        "person_type": 1
    }
}
```

**Poznámka:** Všechny klíče jsou snake_case — `title_new`, `doc_states`, `live_summary`, `read_only`, `close_form`, `foreign_key`, `form_id`, `input_type`, `table_id`, `component_name`.

| Pole | Typ | Popis |
|------|-----|-------|
| `table` | string | DB název tabulky |
| `title` | string | Nadpis pro editaci existujícího záznamu |
| `title_new` | string | Nadpis pro nový záznam |
| `tabs` | Tab[] | Seznam tabů (min. 1) |
| `doc_states` | DocStatesInfo \| null | Info o stavech; přítomno i pro nový záznam (výchozí stav 10) |
| `live_summary` | `{label, value}[]` | Volitelný živý pruh součtů nad obsahem formuláře; **přítomno jen když neprázdné**. Sestavuje se z aktuálních dat při loadu i každém recalculate (kapitola 21) |

### Tab — tři typy

Každý tab má `type`: `"fields"` (výchozí), `"subtable"`, nebo `"attachments"`.

#### `type: "fields"` — formulářová pole v sekcích a sloupcích

```json
{
    "id": "basic",
    "label": "Základní údaje",
    "type": "fields",
    "sections": [
        {
            "title": null,
            "columns": [
                {"elements": [
                    {"type": "input", "column": "person_id", "label": "ID", "required": true}
                ]}
            ]
        },
        {
            "title": "Identifikace firmy",
            "columns": [
                {"elements": [{"type": "input", "column": "company_id", "label": "IČO"}]},
                {"elements": [{"type": "input", "column": "tax_id", "label": "DIČ"}]}
            ]
        }
    ]
}
```

- **Sekce** je vizuální karta s pozadím. Volitelný `title` (zobrazí se jako malý nadpis nahoře). `hidden: true` celou sekci skryje.
- **Sloupce** uvnitř sekce jsou vertikální dráhy (1 a více). Šířka labelu se v rámci jednoho sloupce automaticky synchronizuje — CSS Grid `max-content 1fr`.
- **Elementy** žijí ve sloupcích, ne přímo v tabu. Element nemá `cols`; jeho šířka vyplývá z toho, ve kterém sloupci je.

#### `type: "subtable"` — vlastní záložka pro child tabulku

```json
{
    "id": "contacts",
    "label": "Kontakty",
    "type": "subtable",
    "subtable": {
        "table": "base_persons_contacts",
        "foreign_key": "person",
        "form_id": "base.persons.contacts"
    }
}
```

Frontend vykreslí tabulku řádků s toolbarem (Přidat / Upravit / Smazat). Sub-záznamy se otevírají v dalším modalu.

#### `type: "attachments"` — záložka s přílohami

```json
{
    "id": "attachments",
    "label": "Přílohy",
    "type": "attachments",
    "table_id": 110
}
```

Renderuje `AttachmentPanel` napojený na `core_attachments` filtrované podle `table_id` a `recordId` (= parentId).

Formulář má vždy alespoň jeden tab. Je-li tab jen jeden, tab bar se nezobrazí.

---

## 4. Elementy formuláře

Element žije uvnitř sloupce (`section.columns[i].elements[]`). Sám si nediktuje šířku — tu určuje sloupec.

Povolené typy: `input`, `select`, `multiselect`, `separator`, `inline`, `html`, `component`.

### 4.1 `input`

```json
{
    "type": "input",
    "column": "full_name",
    "label": "Celý název",
    "required": true,
    "hidden": false,
    "read_only": false,
    "triggers": "reload",
    "input_type": "text"
}
```

`input_type` určuje typ UI komponenty: `text` (výchozí), `email`, `tel`, `url`, `password`, `number`, `date`, `datetime`, `time`, `textarea`, `checkbox`. Odvozuje se automaticky z DB typu sloupce.

Hodnota je validována v konstruktoru `FormElement` proti whitelistu — neplatný řetězec (např. `datetime-local`) vyhodí `InvalidArgumentException`. Platí pro PHP builder i pro JSONC (`JsoncFormLoader` předává hodnotu do stejného konstruktoru, whitelist se aplikuje automaticky).

| Pole | Výchozí | Popis |
|------|---------|-------|
| `column` | — | ID sloupce v DB |
| `label` | z TableDefinition | Automaticky doplněn z názvu sloupce pokud chybí |
| `required` | odvozeno ze schématu | Hvězdička u labelu. Bez explicitní hodnoty: u `select` `!nullable` sloupce (bez ohledu na default), u ostatních typů `!nullable && default === null`. Explicitní hodnota vždy vyhrává. Platí shodně pro JSONC, `TabBuilder` i `AutoFormBuilder` |
| `hidden` | false | Skryto (`display: none`), pole zůstává v DOM |
| `read_only` | false | Disabled input |
| `triggers` | null | `"reload"` = při změně spustit recalculate |
| `input_type` | derived | Typ UI komponenty |

**Checkbox je výjimka:** vykresluje se přes obě grid kolony (label + input) jako `<label><Checkbox/></label>`, kde text je popis vedle boxu. Externí label se v gridu nevykresluje.

### 4.2 `select`

```json
{
    "type": "select",
    "column": "person_type",
    "label": "Typ osoby",
    "triggers": "reload",
    "options": [
        {"value": 0, "label": "Neurčeno"},
        {"value": 1, "label": "Fyzická osoba"},
        {"value": 2, "label": "Firma"}
    ]
}
```

`options` se generují na serveru z `cfgItem` sloupce.

**Prázdná možnost** (hodnota `NULL`) se vykreslí jen u nepovinného selectu a
nese globální text „nevybráno" (i18n klíč `form.selectEmpty`). Explicitní
`placeholder` elementu má přednost a prázdnou možnost vykreslí i u povinného
selectu (vzor: průvodce nastavením DS s možností „Nerozhodnuto"). `required`
bez explicitní hodnoty se odvozuje jako `!nullable` sloupce **bez podmínky na
default** — prázdná možnost selectu vždy znamená `NULL`, který do NOT NULL
sloupce nejde, a default novému záznamu hodnotu už dodal (`FormController`).
Nullable sloupce (`vat_registration`, `bank_account`, `unit`…) prázdnou možnost
mají; kde se má přesto vyžadovat výběr, stojí explicitní `required: true`
(`vat_code` na řádku dokladu). `Select.svelte` při vazbě normalizuje `''` na
`null` — formulář drží „nic" v obou podobách (`''` z `buildDefaultData` u
nového záznamu, `null` ze serveru u existujícího) a bez normalizace by u
nového záznamu zavřená roletka zůstala prázdná, protože `''` žádné možnosti
neodpovídá. Pravidlo je na jednom místě per zdroj formuláře:
`TabBuilder::select()`, `JsoncFormLoader::deriveRequired()`,
`AutoFormBuilder::buildElement()` (issue #61).

### 4.2b `multiselect`

```json
{
    "type": "multiselect",
    "column": "content_tags",
    "label": "Obsahové štítky",
    "options": [
        {"value": "vehicle.fuel", "label": "Pohonné hmoty"},
        {"value": "it.software", "label": "Software a SaaS"}
    ]
}
```

Výběr více hodnot z pevné nabídky (cfgItem-based číselník). Hodnota je pole
hodnot z `options` (typicky list stringů); sloupec v DB je `type: json` —
Document třída odpovídá za `json_encode` v `beforeSave()` (prázdný výběr →
`NULL`). `options` se auto-resolují z `cfgItem` sloupce stejně jako u `select`.
Frontend vykresluje chips + dropdown se zaškrtávacími položkami
(`MultiselectInput.svelte`). `column` je povinný; do `inline` skupiny
multiselect nelze umístit (stejné pravidlo jako `lookup`). PHP builder:
`TabBuilder::multiselect(column, label:, options:, ...)`.

### 4.3 `separator`

```json
{
    "type": "separator",
    "label": "Jméno osoby",
    "hidden": false
}
```

Horizontální linka s volitelným textem. Pokrývá obě grid kolony (label + input). `hidden` se nastavuje automaticky v `TabBuilder::build()` pokud jsou všechny elementy za separátorem **v daném sloupci** skryté (`autoHideSeparators` — operuje per-column).

### 4.4 `inline`

```json
{
    "type": "inline",
    "elements": [
        {"type": "input", "column": "date_tax", "label": "DUZP", "input_type": "date"},
        {"type": "input", "column": "date_tax_duty", "label": "DPPD", "input_type": "date"}
    ]
}
```

Více polí v jedné řádce. Label prvního pole slouží jako „velký" label řádky (vlevo, v label dráze gridu). Ostatní pole mají vlastní mini-label vedle inputu. Uvnitř `inline.elements` jsou povoleny pouze `input` a `select`.

Na mobilu (≤ 768px) se inline skupina **rozpadne na samostatná pole pod
sebou** — každý prvek skupiny dostane svůj label vedle inputu, jako běžné
pole (mini-labely zanikají). Na desktopu zůstává skupina na jednom řádku
(první prvek velký label vlevo, další mini-labely mezi poli). Řídí
`layout.svelte.js` (`isMobile`) — je to **strukturní** přepnutí markupu
(flex skupina + mini-labely → grid řádky + velké labely), ne jen CSS,
proto JS store místo media query (stejný typ rozhodnutí jako viewer
list/detail ve fázi 2). Label zůstává **vedle** inputu i na mobilu (grid
`max-content 1fr` v `FormColumn` se nemění) — ověřeno na reálném telefonu.

### 4.5 `html`

```json
{ "type": "html", "content": "<p>Poznámka</p>" }
```

Vlastní HTML uvnitř sloupce; rendruje se přes obě kolony.

### 4.6 `component`

```json
{ "type": "component", "component_name": "attachmentsView", "params": {"table_id": 303} }
```

Pojmenovaná Svelte komponenta. Rendruje se přes obě kolony. Frontend
ji řeší přes registr `form/formComponents.js` (klíč = `component_name`);
neznámé jméno se vykreslí jako placeholder `[name]`. Volitelné `params`
(libovolný objekt) komponenta dostane jako prop; kromě toho vždy dostává
`parentId` (ID editovaného záznamu, `null` u nového).

Registrované komponenty:

| `component_name` | Komponenta | Params | Použití |
|---|---|---|---|
| `attachmentsView` | `FormAttachmentsView` | `table_id` (číselné ID tabulky) | Read-only velké náhledy příloh záznamu (AttachmentGrid `mode="full"`); PDF a obrázky řadí před nenáhledovatelné typy, scrolluje uvnitř (výšku udává fill mechanismus, viz Layout). Např. došlá pošta — pravý sloupec tabu Zpráva. |

### 4.7 `lookup`

```json
{
    "type": "lookup",
    "column": "partner",
    "label": "Partner",
    "placeholder": "Hledat partnera…",
    "lookup": {
        "table": "base_persons_persons",
        "filter": null
    }
}
```

Inline combobox pro FK na velkou tabulku (Osoby, Adresy, Položky…). Klient si průběžně dohledává záznamy přes endpoint `GET /_ui/lookup/{table}/search`. Server pre-resolvuje vybrané hodnoty do `dataResolved` v response — žádný extra fetch při otevření formuláře.

Detailně viz [kapitolu 22](#22-lookup-pole). Pravidla:

- `lookup` element **nemůže** být uvnitř `inline` skupiny.
- `select` ponechte pro enumy a malé cfgItem-based číselníky; `lookup` je pro velké tabulky se search-driven UX.

### Zaniklé typy

`group` a `subtable` (jako element uvnitř tabu) byly v novém systému odstraněny. `subtable` je vždy vlastní tab (`type: "subtable"`); pro grupování polí se používají sekce nebo `inline`.

---

## 5. DocStates v FormDefinition

`doc_states` je přítomno vždy pokud tabulka má doc states — i pro nový záznam (výchozí stav 10).

```json
{
    "currentState": 40,
    "stateName": "V pořádku",
    "stateStyle": "done",
    "read_only": true,
    "transitions": [
        {"state": 80, "actionName": "Opravit", "stateStyle": "edit", "close_form": false},
        {"state": 70, "actionName": "Ukončit platnost", "stateStyle": "archive", "close_form": true},
        {"state": 90, "actionName": "Smazat", "stateStyle": "trash", "close_form": true}
    ]
}
```

### `close_form` flag

Každý přechod stavu má `close_form: bool` (výchozí `false`). Definuje se v cfgItem konfigurace stavů (`docStatesArchive.jsonc` apod.).

| `close_form` | Chování po přechodu |
|---|---|
| `false` | Formulář zůstane otevřený, data se reloadnou |
| `true` | Formulář se zavře a vrátí do Vieweru |

Standardní nastavení v `core.system.docStatesArchive`:
- Koncept (10), V opravě (80) → `close_form: 0`
- V pořádku (40), V archívu (70), Smazáno (90) → `close_form: 1`

### `onSaved` neznamená „zavři“

Zavírání formuláře je oddělené od ukládání (commit `355f24a`). Jediný, kdo
rozhoduje o zavření, je `FormEditor` — a to výhradně podle `close_form`
přechodu (volá `onClose({force:true})` jen když `close_form: true`). Callback
`onSaved` znamená pouze „data byla uložena, refreshni se“ — **nikdy** „zavři“.
Volá se po prostém Uložit i po přechodu s `close_form: 0` (typicky Opravit
40 → 80).

Konzumenti `onSaved` (Viewer, TableBrowser, Dashboard) proto v handleru jen
refetchují data a formulář **nezavírají**. `open` přepnou na `false` jen ve svém
`onClose` handleru. Kdo na `onSaved` bezpodmínečně zavře modal, rozbije Opravit
(stav se změní, ale modal zmizí) — a vůči uživateli to vypadá jako bug v
`close_form`, ačkoli `close_form` i `FormEditor` jsou v pořádku.

**Výjimka — `FormSubTable`.** Subtable řádky bez doc states (např. Faktura →
Řádky) mají jedinou akci Uložit (žádné přechody s `close_form`), takže by po
Uložit šly zavřít jen křížkem. Proto `FormSubTable.handleDialogSaved` modal
zavírá, ale **jen když formulář nemá doc states**. `FormDialog` k tomu předává
do `onSaved` druhý argument `{ hasDocStates }`. Subtable se záznamy s doc states
(Osoba → Kontakty/Adresy) se chovají jako hlavní modaly — zůstanou otevřené a
zavření řeší `close_form` / `onClose`.

### Toolbar formuláře (FormStateBar)

- **Tlačítko Uložit** — viditelné pokud `!read_only`. Uloží data, ale formulář nezavře.
- **Přechodová tlačítka** — ze `transitions`. U existujících záznamů nejdříve uloží data, pak přepne stav. Pokud `close_form`, zavře formulář.
- **ReadOnly formulář** — všechna pole disabled, tlačítko Uložit skryto, jen přechodová tlačítka.
- **Mobilní footer (≤ 768px)** — footer zobrazuje Uložit + **postupové** přechody (V pořádku, Opravit… — v daném stavu jich je max pár, takže se vejdou). Do kebab menu (⋮) přes `Popover` (placement `top`, otevírá se nad footerem) jdou: **destruktivní** přechody (`archive`/`trash`/`cancelled` — Archivovat, Stornovat, Smazat; bezpečnost, méně omylů palcem, v kebabu červeně), **`concept`** (Uložit jako koncept — návrat dokladu zpět na koncept, pomocná akce; v kebabu neutrálně) a přechody s příznakem **`mobileKebab`** v docStates (vedlejší akce, jejíž `stateStyle` na rozlišení nestačí — viz `docs/doc-states.md`). Kebab se nerenderuje, pokud není žádný takový přechod. Na desktopu (> 768px) zůstávají všechny přechody jako tlačítka vedle sebe — beze změny. Strukturní přepnutí (tlačítka → kebab) řídí `layout.svelte.js` (`isMobile`), ne CSS. Kebab volá stejný `onTransition`, takže případný `confirm` u přechodu proběhne stejně jako u tlačítka.

---

## 6. Grid systém

Formuláře se vykreslují ve dvou vrstvách CSS Gridu:

### Sekce → sloupce

`FormSection` vytvoří horizontální grid podle počtu sloupců (`section.columns.length`). Vlevo i vpravo stejně široké:

```css
.shpd-form-section__columns {
  display: grid;
  grid-template-columns: repeat(var(--shpd-form-section-cols), 1fr);
  gap: var(--shpd-space-xl);
}
```

Na úzkém viewportu (<700px) se sloupce lámou pod sebe (`grid-template-columns: 1fr`).

### Sloupec → label/input track

`FormColumn` má vlastní dvouwidth grid: `max-content 1fr`. To znamená, že **všechny labely v daném sloupci jsou stejně široké** (podle nejdelšího), inputy zaberou zbytek. `FormFieldRow` emituje DVA sourozence (`<label>` a `<div>`) přímo do tohoto gridu, aby labely sdílely jednu dráhu.

```css
.shpd-form-column {
  display: grid;
  grid-template-columns: max-content 1fr;
  column-gap: var(--shpd-space-md);
  row-gap: var(--shpd-space-sm);
  align-items: baseline;
  align-content: start; /* při natáhnutí sekcí (vyšší soused) drží řádky nahoře */
}
```

Šířka labelu je **per-sloupec** — dva vedlejší sloupce v jedné sekci mohou mít různě široké labely. To je záměr; pokud by bylo třeba synchronizovat napříč sloupci, musel by se použít CSS subgrid.

### Full-span elementy

`separator`, `html`, `component` a `checkbox` se rendují přes obě grid kolony (`grid-column: 1 / -1`). `inline` má vlastní label + flex container, takže do gridu vchází jako dvě běžné kolony.

### Fill sloupce (sloupec jen s komponentami)

Sloupec složený **výhradně z `component` elementů** dostane modifikátor
`.shpd-form-column--fill` (detekce ve `FormColumn`). Chová se jinak než
běžný sloupec:

- **Nediktuje výšku řádku sekce** — komponenta uvnitř (např.
  `FormAttachmentsView`) má scroll container `position: absolute`, takže do
  intrinsické výšky nepřispívá a jen se roztáhne podle dostupné výšky.
- **Tab se roztáhne na celou výšku těla formuláře** — přítomnost
  `--fill` sloupce aktivuje přes `:has()` řetěz
  `.shpd-form-editor__tab-content` → `.shpd-form-tab` →
  `.shpd-form-section` → `.shpd-form-section__columns`
  (`min-height: 100%` / `flex: 1`), takže karta sekce končí u spodní
  hrany scroll containeru. Tělo formuláře pak scrolluje jen tehdy, když
  se nevejdou samotná pole — obsah fill sloupce scrolluje uvnitř sebe.
- Sousedící běžné sloupce drží pole nahoře (`align-content: start`).
- Na mobilu (<768 px, sloupce pod sebou) se výška od souseda odvodit
  nedá — komponenta si řeší pevné scroll okno sama (např.
  `max-height: 60vh` u příloh).

Formuláře bez fill sloupců zůstávají tímto mechanismem nedotčené.
Použití: `IncomingMessagesForm` — tab Zpráva, pravý sloupec s náhledy příloh.

### Vizuál sekce

`FormSection` je „karta" s vlastním pozadím a jemnou hranou:

```css
.shpd-form-section {
  background: var(--shpd-color-bg-secondary);
  border: 1px solid var(--shpd-color-border-subtle);
  border-radius: var(--shpd-radius-md);
  padding: var(--shpd-space-md) var(--shpd-space-lg);
}
```

Volitelný `title` se vykreslí jako malý uppercase nadpis vlevo nahoře sekce.

---

## 7. Recalculate — dynamické přepočítání

Elementy s `"triggers": "reload"` spustí recalculate při změně hodnoty.

### Flow

1. Uživatel změní hodnotu (např. `person_type`)
2. Klient pošle `POST /_ui/form/{table}/recalculate`:
   ```json
   {
       "id": 42,
       "changedColumn": "person_type",
       "data": { "...aktuální data všech polí..." }
   }
   ```
3. Server zavolá `TableForm::recalculate()`, vrátí novou FormDefinition + přepočítaná data.
   Součástí vrácené FormDefinition je i volitelné `live_summary` (živý pruh
   součtů, viz *Živý pruh součtů* v kapitole 21) — na rozdíl od `header_info`,
   které recalculate vrací jako `null`, se sestavuje z aktuálních dat při
   každém volání
4. Klient překreslí formulář. Data ze serveru přitom pokládá přes prázdné
   hodnoty (`''`) pro všechna pole nové FormDefinition — stejně jako při
   prvním načtení (`buildDefaultData`). Recalculate totiž může layout
   rozšířit o pole, která v datech nejsou (změna pohybu řádku přidá saldo
   identitu); `bind:value` na `undefined` by shodil Svelte
   (`props_invalid_value`, komponenty mají fallback hodnotu) a zbytek
   formuláře by zůstal neaktivní.

Recalculate **neukládá** do DB.

### Automatické skrývání separátorů

`TabBuilder::build()` volá `autoHideSeparators()` — separátor se automaticky skryje pokud jsou všechny elementy za ním (do dalšího separátoru) skryté. Vývojář nemusí ručně nastavovat `hidden` na separátory, ale může to udělat explicitně pro přehlednost.

---

## 8. Validace a chybové stavy

Server vrátí při chybě validace `VALIDATION_ERROR` s polem `details[]`. Každá
položka má `{field, code, message}` (wire formát je snake_case; `field` mapuje
`ValidationError::column` z backendu):

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Validation failed",
    "details": [
      {"field": "partner",          "code": "required",              "message": "Partner je povinný"},
      {"field": "vat_registration", "code": "required",              "message": "Registrace DPH je povinná"},
      {"field": "rows",             "code": "no_rows",               "message": "Doklad musí mít alespoň jeden řádek"},
      {"field": "_form",            "code": "no_own_company",        "message": "Není nastavena vlastní firma…"}
    ]
  }
}
```

### Warningy (neblokující)

`ValidationResult::addWarning()` (vlna D) přidá doporučení, které **neblokuje
uložení** — `isValid()` počítá jen errory. Warningy putují v **success**
response obou save cest (uložení i přechod stavu přes gateway) jako
volitelné pole `warnings[]` se stejným tvarem položek jako `details[]`:

```json
{
  "success": true,
  "data": {
    "id": 42,
    "data": { "...": "..." },
    "dataResolved": { "...": "..." },
    "warnings": [
      {"field": "partner_bank", "code": "partner_bank_recommended", "message": "Bankovní spojení dodavatele…"}
    ]
  }
}
```

Klíč chybí, když žádné warningy nejsou. Frontend je zobrazuje ve **žlutém
banneru** nad obsahem formuláře (`FormEditor` → `warnings` state,
`form.warnings.bannerTitle`), vedle červeného validačního banneru — stejná
geometrie, jiné téma, žádné tabové tečky: warning uložení ani přechod stavu
nebrání. `field` odpovídající sloupci formuláře dostane label pole, ostatní
(`_form`, id tabu) jdou holé. Banner drží do dalšího pokusu o uložení —
popisuje stav, který se právě uložil.

Uživatelé: FPB bez bankovního spojení dodavatele
(`partner_bank_recommended`, dřív blokující `partner_bank_required` — hard
požadavek se přesune do budoucího platebního flow); převzatá rekapitulace
DPH proti řádkům dokladu (`rows_recap_mismatch`) a její vnitřní konzistence
(`vat_recap_inconsistent`, `docs/vat-calculation.md` § 5).

### Kontrakt `field`

| Hodnota `field` | Význam | UI chování |
|-----------------|--------|------------|
| Konkrétní `column` formuláře | Field-level chyba | error vedle inputu, tabová tečka, řádek v banneru s prefixem labelu pole |
| Virtuální sloupec strukturovaného pole (`filing_profile.typ_ds`, kap. 25) | Field-level chyba | dtto — pro klienta je to obyčejný `column` |
| `field` = id nějakého tabu (typicky subtable, např. `rows` → tab „Řádky") | Tab-level chyba | banner + tabová tečka na tom tabu + `switchToErrorTab` na něj přeskočí |
| `_form` (konstanta `ValidationError::FIELD_FORM`) | Form-level chyba | jen banner, holá hláška |
| Cokoli jiného (neznámý sloupec, prázdný string) | Fallback na form-level | jako `_form` — jen banner |

Frontend rozlišuje field-level a form-level přes `buildElementMap()`: pokud
`field` odpovídá nějakému sloupci ve formuláři, je field-level; jinak putuje
do `formErrors`. Tím je robustní — backend může používat `_form`, `rows` nebo
cokoli jiného a banner ho odbaví. Z `formErrors` se navíc vyzobnou ty, jejichž
`field` odpovídá id tabu (`errorTabIds`), a aktivují tabovou tečku / přeskok na
ten tab — tak `rows` ukáže na subtable tab „Řádky", aniž by `rows` byl sloupec.
Nové form-level validace by měly používat kanonický marker
`ValidationError::FIELD_FORM`.

### Zobrazení (FormEditor)

Sdílený helper `extractValidationErrors(details)` rozdělí `details[]` na
`fieldErrors` (mapa column → hláška) a `formErrors` (seznam `{message, code}`).
Stejný helper (`applyValidationErrors`) používají **všechny** save/transition
větve — `handleSave`, `handleTransition` pro nový záznam i **oba PUTy** pro
existující záznam (přechod stavu naostro je až druhý PUT s `{docState}`).

- **Banner nad tabbarem** (`__validation-banner`) se ukáže, když je neprázdné
  `fieldErrors` nebo `formErrors`. Obsahuje nadpis (`form.validation.bannerTitle`,
  neutrální „Formulář obsahuje chyby:") a seznam: form-level chyby holé,
  field-level s prefixem labelu pole („Partner: Partner je povinný").
- **Field-level** chyby se navíc zobrazují vedle inputu a aktivují **tabovou
  tečku**; je-li chybné pole na neaktivním tabu, `switchToErrorTab` přepne tab.
- **Tab-level** chyby (form-level chyba, jejíž `field` = id tabu, např. `rows`)
  také aktivují tabovou tečku a přeskok na ten tab. Čistě form-level chyby
  (`_form`, neznámý field) tabové tečky neaktivují — od toho je banner.
- Oba state se vyčistí na začátku každého save/transition pokusu
  (`clearValidationErrors`); po úspěšném save zmizí přirozeně (reload formuláře).

### Sanitizace dat před odesláním

`FormEditor` provádí `sanitizeFormData()` před každým odesláním:
- `select` s numerickými options → převede string na number (HTML `<select>` vždy vrací string)
- `input_type: date/number/datetime...` s prázdnou hodnotou → převede `""` na `null`

---

## 9. Velikost modalu

`FormDialog.svelte` vždy renderuje formulář v Modal komponentě (centrovaný popup nad tmavým overlayem). Velikost top-level modalu se plynule škáluje podle velikosti okna přes CSS `clamp()`:

```
width:  clamp(1200px, 80vw, 1700px)
height: clamp(720px, 88vh, 1100px)
```

- **Spodní mez (1200 × 720)** — co se nikdy nepodleze. Na malých laptopech (1366×768) modal nevyleze mimo viewport, formulář scrolluje.
- **Preferovaná (80vw × 88vh)** — co se vykreslí, když není uplatněna mez. Na FHD (1920×1080) vyjde cca 1536 × 950.
- **Horní mez (1700 × 1100)** — strop. 1700 px je hranice, kde 2–3sloupcový layout sekcí ještě zůstává čitelný (label vlevo, input vpravo nepřeskakují přes půl obrazovky); 1100 px stačí i pro nejdelší formy.

Žádný flag, žádné rozhodování per-formulář — všechny top-level modaly se škálují stejně bez ohledu na počet polí. `clamp()` je předaný z `FormDialog` jako `width` / `height` prop; `Modal.svelte` ho použije beze změny (skládá `calc(${width} − offset)` pro depth-shrink, `clamp()` uvnitř `calc()` je validní CSS).

### Chování modalu

- **Header** — Modal vlastní header s titulkem (`formDef.title` / `formDef.title_new` / `header_info.title`), `FormStateBadge` (přes `headerExtra` snippet) a tlačítkem `×` vpravo nahoře. FormEditor vlastní header nemá.
- **Živý pruh součtů** (`formDef.live_summary`, volitelný) — `FormEditor` ho renderuje mezi tab-barem a validačním bannerem (mimo scrollovaný obsah): páry label/hodnota zarovnané vpravo, hodnoty tabulární číslice, během `recalculating` ztlumený. Na rozdíl od hlavičky se překresluje po každém recalculate (`formDef` se nahrazuje celý). Viz *Živý pruh součtů* v kapitole 21.
- **Body skroluje** — header a `FormStateBar` zůstávají fixní, skroluje pouze tělo formuláře. Pro krátké formuláře (Úkol) zůstává prázdný prostor pod posledním polem — záměrný kompromis pro konzistenci napříč aplikací.
- **Zavření** — `Esc` nebo klik na overlay (mimo kartu modalu) nebo tlačítko `×`. Všechny tři způsoby volají stejný `onClose` callback.
- **Body scroll lock** — modal blokuje scrollování stránky pod sebou.

### Vrstvení modalů (Esc handling a depth shrink)

`Modal.svelte` používá module-level stack otevřených modalů. Slouží dvěma účelům:

**Esc handling** — Esc handler reaguje pouze na modal na vrcholu stacku. Bez tohoto by Esc v subdialogu Kontaktu zavřel současně Kontakt i nadřazenou Osobu (oba modaly poslouchají window keydown). Klik na overlay tento problém nemá — overlay každého modalu zachytí jen kliky na vlastní plochu. Tlačítko `×` je per-modal element. Esc je ale globální event, proto vyžaduje stack.

**Depth-based shrink** — každý modal si při `pushModal()` zjistí svoji hloubku ve stacku (0 = kořenový, 1 = vnořený, atd.). Podle hloubky se `cardStyle` zmenší o 30 px na každé straně (60 px celkem na šířku i výšku). Vnořený modal je tak vycentrovaný a všechny strany rodičovského modalu rovnoměrně vyčnívají — uživatel vidí hierarchii. Funguje pro libovolnou hloubku vnoření (Doklad → Řádek → Položka = depth 2 → položka modal je o 120 px užší/nižší než doklad).

Konkrétní offsety podle hloubky (odecténo od aktuálně vypočtené `clamp()` velikosti na každé straně):

| Depth | Offset / strana | Celá šířka i výška |
|-------|-----------------|----------------------|| 0     | 0 px            | bez změny            |
| 1     | 30 px           | −60 px               |
| 2     | 60 px           | −120 px              |
| 3     | 90 px           | −180 px              |

Shrink je **fixní v px** (30 px / úroveň), ne procentuální — slouží k rozpoznání hierarchie, ne k proporcionálnímu škálování. Aplikuje se na vypočtenou velikost po `clamp()`, takže na širokém monitoru (modal u stropu 1700 px) i na laptopu (modal u spodní meze 1200 px) je vnořený modal vždy o 60 px užší/nižší než jeho rodič.

### Mobilní fullscreen (≤ 768px)

Na mobilu (≤ 768px) je **každý** modál fullscreen — `Modal.svelte` má
`@media` blok, který kartu přepíše na `100vw × 100dvh` (fallback `100vh`),
bez zaoblení a overlay okrajů. Platí univerzálně pro všechny modály
postavené přes `Modal.svelte` (FormDialog, reanalyze, reject, Exchange
preview…); pevné `width`/`height` props i `clamp()` z `FormDialog` jsou
přebity. Inline `cardStyle` má vyšší specificitu než třídní pravidlo,
proto media query používá `!important`.

Specifika na mobilu:
- **Depth-shrink se ruší** — vnořený modál je taky fullscreen a překryje
  rodiče (na fullscreenu není kam vykukovat). Stack, Esc i zavírání
  fungují beze změny — Esc/✕ zavře jen vnořený a vrátí na rodiče.
- **Header summary** (`summary` snippet, ceny u dokladů) se skrývá —
  redundantní s obsahem, hlavička zůstává kompaktní. Titul, badge
  a subtitle zůstávají.
- **Footer tlačítka** na plnou šířku (`flex: 1`) — lepší pro dotyk palcem.

Je to čistě CSS přepnutí vzhledu (karta → fullscreen), bez čtení
`layoutStore.isMobile` — konzistentní se strategií „CSS na vzhled, JS
store jen na chování". Na desktopu (> 768px) beze změny (clamp, pevné
šířky i depth-shrink fungují jako dnes). Vnitřní layout polí (label nad
input) řeší samostatná fáze 3b — kontejner a pole jsou oddělené.

Mechanismus je generický na úrovni `Modal.svelte` — žádný kontext o tom, kdo je rodič/dítě. Funguje pro všechny vnořené modaly (FormSubTable child rows, LookupInput edit/create dialog, budoucí scénáře).

### Detekce neuložených změn (dirty state)

FormEditor sleduje změny dat oproti snapshotu pořízenému při posledním načtení nebo uložení. Stav propaguje do FormDialogu přes `onDirtyChange` callback. Když je formulář dirty a uživatel se ho pokusí zavřít (Esc, klik na overlay, tlačítko `×`), zobrazí se nativní `window.confirm` s textem „Máte neuložené změny. Opravdu chcete zavřít formulář?". Tlačítka Uložit a stavová tlačítka kontrolu obcházejí — ta změny ukládají, ne ztrácejí.

- **ReadOnly formuláře nikdy nejsou dirty** — uživatel nemůže nic změnit.
- **Recalculate NEaktualizuje snapshot** — recalculate neukládá do DB, takže přepočítaná data jsou stále neuložená změna. Po triggeru je formulář dirty (server typicky přepočítá hodnoty) a uživatel musí změnu explicitně uložit. Pokud zavře bez uložení, confirm dialog ho upozorní.
- **`null` vs `''`** — porovnání tyto dvě hodnoty považuje za rovné (server vrací `null` u nullable polí, formulář je interně reprezentuje jako `''`).
- **Subtables** — každá instance FormDialogu (Osoba, Kontakt v Osobě) má vlastní dirty check. Otevřený subdialog Kontaktu sleduje změny svých polí nezávisle na rodičovské Osobě.

### Force close — bypass dirty kontroly

`FormDialog.handleClose` přijímá volitelný parametr `{ force?: boolean }`. Když je `force: true`, dirty kontrola se přeskočí. Používá se po úspěšném save + closeForm v stavovém přechodu (např. „V pořádku" u nového záznamu): FormEditor sám ví, že data jsou uložená, a confirm dialog je nežádoucí.

Důvod existence tohoto mechanismu je timing Svelte reaktivity. Když FormEditor po uspěšném save aktualizuje snapshot, `isDirty` derived state se přepočítá až v dalším mikrotasku. Pokud by FormEditor okamžitě synchronně zavolal `onClose()`, FormDialog by ještě viděl starý `isDirty: true` a zobrazil by zbytečný confirm. `force: true` to obchází bez závislosti na pořadí reaktivních updatů.

Modal komponenta (Esc, klik na overlay, `×`) volá `onClose()` bez parametru — tyto akce **mají** procházet dirty kontrolou. `force: true` posílá pouze FormEditor po vlastním úspěšném uložení.

---

## 10. API endpointy

| Endpoint | Metoda | Popis |
|----------|--------|-------|
| `/_ui/form/{table}/meta` | GET | FormDefinition pro nový záznam |
| `/_ui/form/{table}/meta/{id}` | GET | FormDefinition + data pro existující záznam |
| `/_ui/form/{table}/save` | POST | Uložení nového záznamu |
| `/_ui/form/{table}/save/{id}` | PUT | Uložení existujícího záznamu |
| `/_ui/form/{table}/recalculate` | POST | Přepočítání bez uložení |
| `/_ui/form/{table}/subtable/{tabId}/{parentId}` | GET | Sloupce + vyrenderované řádky sub-tabulky (tab typu `subtable`) — kap. 15 |
| `/_ui/form/{table}/subtable/{tabId}/{parentId}/move` | POST | Přesun řádku sub-tabulky o jednu pozici (`{id, direction: up\|down}`), přečíslování skupiny 1..N — kap. 15.5 |

### Detekce přechodu stavu

`PUT /save/{id}` s tělem obsahujícím **pouze** `docState` se zpracuje jako čistý přechod stavu (přes `applyStateTransition` bez Document lifecycle). Běžné uložení jde přes `TableGateway` + `Document::validate/beforeSave`.

---

## 11. PHP třída `TableForm`

```php
abstract class TableForm
{
    protected string $table;
    protected ?ConfigRuntime $config = null;
    protected ?DataSourceConnection $db = null;
    protected ?TableDefinition $tableDef = null;  // pro auto-label
    protected array $tables = [];                 // všechny tabulky DS (setTables) — default renderer sub-tabulek

    abstract public function buildFormDefinition(array $data, bool $isNew): FormDefinition;

    public function recalculate(string $changedColumn, array $data): RecalculateResult { ... }

    /** Sloupce + buňky sub-tabulky pro tab typu `subtable` — kap. 15. Default z TableDefinition dětské tabulky. */
    public function renderSubtable(FormTab $tab, array $rows, array $parentData): array { ... }

    protected function tab(string $id, string $label, ?string $icon = null): TabBuilder;
    protected function subtableTab(
        string $id, string $label,
        string $table, string $foreignKey,
        ?string $formId = null, ?string $sort = null, ?string $icon = null,
        ?string $orderColumn = null,   // pořadový sloupec → šipky přesunu, řazení orderColumn ASC, id ASC; nekombinovat se $sort
    ): FormTab;
    protected function attachmentsTab(string $id = 'attachments', string $label = 'Přílohy'): FormTab;
}
```

`TableForm` instance vyrábí `FormRegistry::createForm($table, $data, $db, $config)` — pro polymorfní tabulky (`docs_core_heads` přes `doc_type`) `$data` rozhodne o konkrétní subclass. Detaily viz [kapitola 23](#23-polymorfní-dispatch-formulářů-přes-typecolumn). Per-typ rodina formulářů typicky tvoří abstract base (`DocsHeadsFormBase`) se společnou logikou + tenké subclassy, které přepisují virtuální `getFormTitle()` / `getNewFormTitle()` (a do budoucna jednotlivé `buildXxxTab()` metody).

### Auto-label z TableDefinition

`TableForm` dostane `TableDefinition` přes `setTableDef()` před voláním `buildFormDefinition`. Helper `tab()` sestaví mapu `column_id => name` a mapu `column_id => ColumnDefinition` a předá obě `TabBuilder`u (`colLabels`, `colDefs`). Element factory metody pak doplní `label` automaticky z labelů pokud není zadán explicitně; `select()` z definic odvodí `required` (`!nullable`), pokud volající nerozhodl explicitně. Bez `TableDefinition` (unit testy) se `required` selectu neodvozuje a je `false`.

#### Krátký label ve formuláři — `formLabel`

Definice sloupce může mít volitelnou položku `formLabel` (s `:cs` / `:en`
variantami), která slouží jako **krátký popisek inputu v editačním formuláři**.
Když je přítomna, `tab()` ji použije do auto-label mapy místo `name`
(`$col->formLabel ?? $col->name`); plný `name` zůstává pro viewer, detail panel
a browser. Když chybí, fallback na `name` — beze změny pro všechny existující
sloupce (`formLabel` je `?string`, default `null`).

Typický případ: dlouhý oficiální název sloupce nepohodlný v úzké label dráze
formuláře. Např. `vat_duzp` má `name:cs` „Datum uskutečnění zdan. plnění (DUZP)“
(zobrazí se ve vieweru / detailu), ale `formLabel` „DUZP“ (popisek inputu).

```jsonc
{
    "id": "vat_duzp",
    "name:cs": "Datum uskutečnění zdan. plnění (DUZP)",
    "name:en": "Tax point date (DUZP)",
    "formLabel:cs": "DUZP",
    "formLabel:en": "DUZP",
    "type": "date"
}
```

Lokalizace je zadarmo — `ConfigLocalizer::localize()` redukuje `formLabel:cs` /
`formLabel:en` na holé `formLabel` ještě před `ColumnDefinition::fromArray()`,
stejně jako u `name`. Mechanismus platí pro PHP builder i JSONC formy (oba jdou
přes stejnou auto-label mapu) a funguje na desktopu i mobilu — na mobilu se
inline skupina rozpadne na samostatná pole, každé použije svůj (zkrácený) label.
Explicitní `label:` v builderu / JSONC má pořád přednost před `formLabel`
i `name`.

### TabBuilder API — scope management

Builder má **třípatrový stavový stroj**: `tab → section → col → [inline] → elements`. Volání musí být v pořadí; mimo otevřený scope vyhodí `LogicException`. `build()` automaticky uzavře otevřené scopy.

```php
$tab = $this->tab('basic', 'Základní údaje')
    ->section()                              // otevře sekci bez titulku
        ->col()                              // otevře první sloupec
            ->input('person_id', required: true)
            ->select('person_type', options: $opts, triggers: 'reload', required: true)
            ->input('full_name', required: $isCompany, readOnly: $isPerson)
    ->section('Identifikace firmy')          // další sekce s titulkem
        ->col()                              // levý sloupec
            ->input('company_id')
            ->input('tax_id')
        ->col()                              // pravý sloupec
            ->input('vat_id')
            ->input('court_registration')
    ->section('Termíny')
        ->col()
            ->inline()                       // víc polí v řádce
                ->date('date_tax', label: 'DUZP')
                ->date('date_tax_duty', label: 'DPPD')
            ->endInline()
    ->build();
```

`input()` je generická a přijímá `inputType` (text varianty `null/text/email/tel/url/password`); pro ostatní DB typy jsou dedikované metody — sebedokumentující a typově bezpečné.

| Metoda | `inputType` | DB typ |
|--------|-------------|--------|
| `input` | text varianty | `char`, `varchar` |
| `textarea` | `textarea` | `text`, `longtext` |
| `date` | `date` | `date` |
| `datetime` | `datetime` | `datetime` |
| `time` | `time` | `time` |
| `number` | `number` | `int`/`bigint`/`numeric`/`float` |
| `checkbox` | `checkbox` | `boolean` |
| `select` | — | `enumInt`, `enumString` |
| `multiselect` | — | `json` (list hodnot) |

```php
// Element factory metody (musí být uvnitř otevřeného col())
$col->input(string $column, ?string $label = null, bool $required = false,
    ?string $triggers = null, bool $readOnly = false, bool $hidden = false,
    ?string $placeholder = null, ?string $hint = null, ?string $inputType = null): static;

$col->textarea(string $column, ?string $label = null, ...): static;
$col->date($column, ...);  $col->datetime($column, ...);  $col->time($column, ...);
$col->number($column, ...);  $col->checkbox($column, ...);

$col->select(string $column, ?string $label = null, ?array $options = null,
    ?string $triggers = null, ?bool $required = null, bool $readOnly = false,
    bool $hidden = false, ?string $hint = null, ?string $placeholder = null): static;
    // required null = odvodit ze sloupce (!nullable); placeholder = text prázdné možnosti

$col->multiselect(string $column, ?string $label = null, ?array $options = null,
    ?string $triggers = null, bool $required = false, ...): static;

$col->separator(?string $label = null, bool $hidden = false): static;
$col->html(string $content): static;
$col->component(string $name, ?array $params = null): static;

// Inline
$col->inline(): static;        // otevři inline; následné input()/select() jdou do něj
$col->endInline(): static;     // ukonči
$col->inlineFields(string ...$columns): static;  // shortcut: inline + N×input

// Závěr
$tab->build(): FormTab;        // auto-close inline → col → section
```

### Subtable a attachments taby

Tyto taby se nepostavují přes builder, ale přes helpery na `TableForm`:

```php
$contacts = $this->subtableTab('contacts', 'Kontakty',
    'base_persons_contacts', 'person', 'base.persons.contacts');

$attachments = $this->attachmentsTab();   // bere tableId z aktuální TableDefinition
```

### Auto-hide separátorů (per-column)

`autoHideSeparators` se spouští v `build()` per sloupec: separátor je automaticky skryt, pokud jsou všechny elementy za ním v daném sloupci skryté (do dalšího separátoru). Vývojář může `hidden: true` na separátoru zapnout explicitně, ale ručně to není potřeba — typický conditional pattern (skrytí celé sekce závisí na `person_type`) funguje automaticky.

---

## 12. Deklarativní JSONC definice

Pro jednoduché formuláře bez business logiky.

**Umístění:** `modules/{skupina}/{modul}/forms/{table}.jsonc`

JSONC source používá **camelCase** klíče (`titleNew`, `readOnly`, `inputType`, `tableId`, `foreignKey`, `formId`). Loader je mapuje na snake_case wire formát při serializaci.

```jsonc
{
    "title": "Kontakt",
    "titleNew": "Nový kontakt",
    "tabs": [
        {
            "id": "basic",
            "label": "Kontakt",
            "sections": [
                {
                    "title": null,
                    "columns": [
                        {
                            "elements": [
                                {"type": "input", "column": "name", "required": true},
                                {"type": "input", "column": "email", "inputType": "email"},
                                {"type": "input", "column": "phone", "inputType": "tel"},
                                {"type": "separator"},
                                {"type": "input", "column": "valid_from", "inputType": "date"},
                                {"type": "input", "column": "valid_to", "inputType": "date"}
                            ]
                        }
                    ]
                }
            ]
        }
    ]
}
```

Labely a typy inputů se doplní z TableDefinition pokud chybí. `options` u `select` se auto-resolují z `cfgItem` sloupce.

**`"optional": true`** na elementu: pokud tabulka na daném DS sloupec nemá,
loader element vynechá (místo pole bez labelu a typu). Používá se pro
**extension sloupce volitelných modulů** — `accounting_account` na pokladně
(extension `economy.accounting`) a na bankovním spojení + `ebanking_id`
(extension `economy.bank`). Bez `optional` se element postaví vždy.

### Taby s `type: subtable` / `attachments` v JSONC

```jsonc
{
    "id": "contacts",
    "label": "Kontakty",
    "type": "subtable",
    "subtable": {
        "table": "base_persons_contacts",
        "foreignKey": "person",
        "formId": "base.persons.contacts"
    }
}
```

```jsonc
{ "id": "attachments", "label": "Přílohy", "type": "attachments", "tableId": 110 }
```

### Vícejazyčnost v JSONC

`title`, `titleNew`, `tabs[].label` a inline `label` u `separator` elementů podporují jazykové varianty `:cs` / `:en`. `JsoncFormLoader::load()` aplikuje `ConfigLocalizer::localize($data, $language)` rekurzivně, takže `field:lang` varianty se redukují na holé `field` podle požadovaného jazyka. Holé pole (bez `:lang` suffixu) je povinný fallback.

```jsonc
{
    "title": "Kontakt",
    "title:cs": "Kontakt",
    "title:en": "Contact",
    "tabs": [
        {
            "id": "basic",
            "label": "Kontakt",
            "label:cs": "Kontakt",
            "label:en": "Contact",
            "sections": [
                {"columns": [{"elements": [
                    {"type": "input", "column": "name", "required": true},
                    {"type": "separator", "label": "Adresa", "label:cs": "Adresa", "label:en": "Address"}
                ]}]}
            ]
        }
    ]
}
```

`AutoFormBuilder` (fallback pro tabulky bez vlastního `forms/{table}.jsonc`) generuje pro každou skupinu sloupců jeden tab s jednou sekcí, jedním sloupcem a všemi poli. Label syntetického „General" tabu se čte z cfgItem `core.system.formDefaults.generalTabLabel.name`.

### Detekce starého formátu

`JsoncFormLoader` aktivně odmítá legacy konstrukce a vyhodí `RuntimeException` s odkazem na konkrétní místo:

- `tab.elements[]` přímo (bez `sections`)
- `element.cols` (šířka teď určuje sloupec sekce)
- `element.type: "group"` (zrušeno; použij sekce nebo inline)
- `element.type: "subtable"` (subtable je teď vlastní tab)

### Priorita výběru formuláře

1. PHP třída registrovaná v `module.jsonc` → `forms[].class`
2. JSONC soubor `forms/{table}.jsonc` v adresáři modulu
3. Automatická generace z `TableDefinition` (AutoFormBuilder)

---

## 13. Registrace v `module.jsonc`

```jsonc
{
    "forms": [
        {
            "table": "base_persons_persons",
            "class": "Shipard\\Module\\Base\\Persons\\PersonsForm"
        },
        {
            "table": "base_persons_contacts",
            "id": "base.persons.contacts"
            // bez "class" → hledá se forms/base_persons_contacts.jsonc
        }
    ]
}
```

`id` umožňuje odkazovat na formulář jako `form_id` v `subtable` elementu.

Pro polymorfní tabulky (jeden physický řádek může reprezentovat víc logických typů, typicky `docs_core_heads` s `doc_type`) místo prostého `{table, class}` použijte zápis `{table, typeColumn, classes, defaultClass}`. Detailně viz [kapitola 23](#23-polymorfní-dispatch-formulářů-přes-typecolumn).

---

## 14. Document lifecycle ve FormController

`FormController::save` prochází přes `TableGateway`, který volá:
1. `Document::validate()` — business validace (povinná pole, podmínky dle typu)
2. `Document::beforeSave()` — transformace (generování `person_id`, dopočítání `full_name`)
3. INSERT/UPDATE
4. `Document::afterSave()`

`Document` dostane přístup k DB přes `setDb()` volaný z `TableGateway` — lze použít pro generování unikátních kódů apod.

`DocumentRegistry` se načítá z `module.jsonc` → `documentClasses` přes `DocumentLoader` a musí být předán jako parametr funkci `dispatch()` v `index.php`.

---

## 15. Sub-tabulky (FormSubTable)

Tab typu `subtable` zobrazuje child tabulku rodičovského záznamu — řádky
dokladu, Kontakty / Adresy / Bankovní účty osoby, měsíce účetního roku.
Jeden univerzální mechanismus (issue #53, fáze 1 —
`tasks/subtable-phase1.md`; fáze 2 dialog řádku, fáze 3 přesun řádků).

- Sub-záznamy se ukládají **okamžitě** při potvrzení dialogu řádku (ne s hlavním formulářem)
- Pro **nový záznam** (rodič nemá ID) jsou taby se sub-tabulkami disabled s informací „Nejprve uložte záznam"
- Po uložení rodiče se `currentId` aktualizuje a sub-tabulky se odemknou

### 15.1 Endpoint `GET /_ui/form/{parentTable}/subtable/{tabId}/{parentId}`

Sloupce i obsah buněk definuje **server**; klient nezná FK, enumy ani formát
částek (stejný princip jako grid vieweru, `docs/viewer-grid.md` §3). Tvar
specifikace sloupců je shodný s `TableViewer::getGridColumns()`, aby šla
sub-tabulka později povýšit na plný grid bez přepisu kontraktu.

```jsonc
{
  "success": true,
  "data": {
    "columns": [
      { "id": "order_pos",   "label": "#",            "align": "right", "width": 44 },
      { "id": "description", "label": "Popis",        "grow": true },
      { "id": "quantity",    "label": "Množství",     "align": "right" },
      { "id": "unit",        "label": "Jednotka" },
      { "id": "vat_total",   "label": "Celkem s DPH", "align": "right" }
    ],
    "rows": [
      { "id": 4711, "cells": { "order_pos": "1", "description": "Ukázková položka",
        "quantity": "2", "unit": "ks", "vat_total": "2 420,00" } },
      { "id": 4712, "cells": { "order_pos": "2",
        "description": { "text": "Textový řádek", "class": "muted" } } },
      { "id": 4713, "cells": { "description": "Archivovaná adresa" }, "stateStyle": "archive" }
    ],
    "order_column": null
  }
}
```

- `columns[]` — `{id, label, align?: 'right', grow?: true, width?: px}`.
- `rows[]` — `{id, cells, stateStyle?}`. `cells` = mapa `columnId → string |
  {text, class?}` (span formát gridu bez badge; `class` ze slovníku `muted`,
  `bold`, `amount`, `primary`, `success`, `warning`, `danger`). Chybějící klíč
  = prázdná buňka — nikdy „0,00" za NULL. `stateStyle` nesou řádky dětských
  tabulek s docStates; frontend dá na `<tr>` globální třídu
  `docState_{style}` (archiv tlumený, koš škrtnutý, `styles/base.css`).
- `order_column` — pořadový sloupec z deklarace tabu (`subtableTab(...,
  orderColumn: 'order_pos')`), jinak `null`. Plní ho controller, ne
  renderer. Non-null → frontend ukáže šipky přesunu (kap. 15.5).
- **Řazení** — tab s `orderColumn` řadí **vždy** `orderColumn ASC, id ASC`
  (stejné pořadí vidí endpoint přesunu; `FormTab` zakazuje kombinaci se
  `sort`). Jinak `sort` ze `subtableTab()` (`col:dir[,col:dir]`, syntaxe
  jako `?sort=` CRUD endpointu), default `order_pos:asc`, má-li dětská
  tabulka ten sloupec, jinak `id:asc`; `id ASC` je vždy tiebreaker. `sort`
  i `orderColumn` jsou serverová konfigurace formu, ne vstup uživatele —
  neznámý / sensitive sloupec nebo špatný směr je 500.
- **Controller** `FormController::subtable()` — sdílené
  `resolveSubtableContext()` (i pro `/move`): guard rodiče i dětské tabulky
  (`TableAccessGuard::guardTable`), rodič se načte jako v `meta({id})`
  (`stripSensitive`, dekódování JSON sloupců), `FormRegistry::createForm()`
  + `setTableDef()` + `setTables()`, `buildFormDefinition($data, false)` →
  tab typu `subtable` s daným id; FK i `orderColumn` musí být sloupce dětské
  tabulky (jinak 500). Pak řádky `WHERE fk = parentId`, `stripSensitive`
  per řádek, `renderSubtable()`. Rodič bez PHP form třídy (JSONC / auto
  form) nebo neznámý tab → 404 `SUBTABLE_NOT_FOUND`.
  Routa: `Router::resolveFormRoute` → `Route('form', 'subtable', $table,
  $parentId, key: $tabId)` (`Route::$key` = textový identifikátor v cestě).
  `ReadOnlyPolicy`: `form.subtable` Allow (čtení), `form.subtableMove` Deny403.

### 15.2 `TableForm::renderSubtable(FormTab $tab, array $rows, array $parentData)`

Renderer žije na **rodičovském** formu, protože sloupce závisí na kontextu
rodiče (doklad bez DPH nemá DPH sloupce; `$parentData` = data rodiče).
Override rozhoduje podle `$tab->id` a pro ostatní taby volá
`parent::renderSubtable()`.

**Default** (formuláře bez overridu — dnes Účetní roky → měsíce — ho
dostanou zdarma; období DPH už sub-tabulka není, instance tvrzení řeší
issue #55):

- sloupce: prvních 6 (`TableForm::SUBTABLE_DEFAULT_MAX_COLUMNS`) sloupců
  dětské `TableDefinition` (z `setTables()`) bez PK / autoIncrement, FK na
  rodiče, `system`, `sensitive`, `json`, stavových sloupců docStates
  a technických `created` / `modified` / `order_pos`; label `formLabel ??
  name` (TableLoader ho už lokalizoval); číselné typy bez cfgItem /
  reference `align: right`; první textový sloupec `grow`;
- buňky (`defaultSubtableCell()`): cfgItem → `name` položky; boolean →
  Ano / Ne z cfgItem `core.system.formDefaults` (`booleanYes`,
  `booleanNo` — po přidání nutný `ds-upgrade`); numeric → dle `scale`;
  date → `d.m.Y`; datetime → `d.m.Y H:i`; `reference` → surové id (default
  má být levný, bez dotazů — pojmenované FK řeší override); null / `''` →
  buňka chybí;
- `stateStyle` přes `subtableRowStateStyle()` u tabulek s docStates
  (`DocStateConfig::getState()['stateStyle']`).

**Sdílené helpery pro overridy:** `subtableLabel($table, $column,
$fallback)` (lokalizovaný label sloupce dětské tabulky), `subtableColumnSpec()`,
`defaultSubtableCell()`, `cfgItemLabel()`, `booleanLabels()`,
`subtableRowStateStyle()`. Formátování čísel / částek / dat výhradně přes
`SubtableCellFormatter` (`money`, `number`, `trimmedNumber`, `price`, `date`,
`dateTime`, `boolean`) — je to jediné sdílené místo, další privátní
`formatMoney()` nepřidávat (sjednocení stávajících viz `tasks/TODO.md`).

**Existující overridy:**

| Form | Tab | Sloupce |
|------|-----|---------|
| `DocsHeadsFormBase` (`renderItemRows`) | `rows` | Položková sada: # · Popis · Množství · Jednotka · Cena/jednotka · [Základ DPH · DPH % · DPH · Celkem s DPH] podle `vat_mode` rodiče, bez DPH místo toho [Cena celkem]. Textový řádek (`row_kind = 0`) jen popis se `class: muted`, žádné číselné buňky. `#` = `order_pos`, při 0 pořadí v seznamu. Zkratky jednotek jedním `IN` dotazem. Částky bez měny (je v hlavičce). |
| `AccountingDocsForm` (`renderContationRows` z base) | `rows` | Kontační sada: # · Pohyb · Účet · Popis · Strana · Částka. Všech osm operací s vlajkou `rowSide` je v `docs.core.rowOperations` povoleno výhradně pro `cmnbkp`, a ten má vlastní form třídu — sada je jednoznačná z hlavičky, per-řádkové rozhodování není potřeba. Strana jen u `rowSide: 1`; Účet jen u řádků s přímým účtem (saldokontní operace mají účet z předpisu). |
| `DocsHeadsFormBase` (`renderRecapRows`) | `recap` | Převzatá rekapitulace DPH (`vat_recap_source = 1`): # · Kód DPH · DPH % · Základ · Daň · Celkem, u cizí měny navíc trojice v domácí měně (ke čtení — dopočítá je uložení z kurzu). Částky, které do součtů hlavičky nevstupují (`sum_*` z definice kódu — typicky oddaňovací pár samovyměření), jsou tlumené. **Tab má dvě podoby:** u přepočítané rekapitulace zůstává HTML přehled (editovat vypočtené nemá smysl), u převzaté je to tahle sub-tabulka nad `docs_core_vat_recap` s formem `docs.core.vatRecap`. Rozhoduje **uložený** `vat_recap_source` (`storedRecapSource()`), ne hodnota přepínače: endpoint `/subtable/{tab}/{id}` staví taby z uloženého záznamu, takže předčasně přepnutý tab by hlásil `SUBTABLE_NOT_FOUND` — a startovní převzatá rekapitulace vzniká kopií přepočítané až při uložení. Viz `docs/vat-calculation.md` § 5. |
| `PersonsForm` (`renderPersonChildRows`) | `contacts` / `addresses` / `bank_accounts` | Název · Funkce · E-mail · Telefon · Poznámka / Typ adresy · Název · Ulice (+ č. p./č. o.) · Obec · PSČ · Země (cfgItem `world.base.countries`) / Název účtu · Číslo účtu · IBAN · BIC/SWIFT · Měna · Zdroj. Všechny tři tabulky mají docStates → archivované řádky tlumené, **ne skryté** (uživatel je potřebuje najít a odarchivovat). |

### 15.3 Frontend — `FormSubTable.svelte`

- Props: `element` (= `tab.subtable`), `tabId`, `parentTable`, `parentId`,
  `disabled`, `readOnly`, `onChanged`. `FormEditor` → `FormTab` → sub-tabulka;
  `parentTable` a `tabId` skládají cestu endpointu.
- **`disabled` vs `readOnly`** — `FormEditor` posílá obojí zvlášť:
  `isReadOnly = readOnly || doc_states.read_only`, `isDisabled = saving ||
  recalculating || isReadOnly`. `disabled` = akce dočasně vypnuté (rodič se
  ukládá), ikony nepřeskakují; `readOnly` = bez Přidat / Smazat, u řádku jen
  Zobrazit (`iconPreview`), dvojklik = Upravit / Zobrazit.
- **Read-only dialog řádku:** `FormDialog readOnly` → `FormEditor readOnly`
  (pole vypnutá, `isDirty` vždy false → Esc / křížek bez dotazu) →
  `FormStateBar readOnly` (bez Uložit i přechodů; bez jediné akce se lišta
  nerenderuje). Nad formulářem `notice` „Záznam je jen pro čtení…".
  `LookupInput` při `disabled` neotevírá vyhledávání ani edit / create.
  `readOnly` na `FormStateBar` je jiná věc než `docStates.read_only` — to
  přechody (Opravit…) naopak nechává.
- **Reload rodiče po změně řádku:** `onChanged` → `FormEditor.handleSubtableChanged()`
  → `loadForm(table, currentId, {keepTab: true})`, ale jen když rodič nemá
  neuložené změny — server mohl přepočítat odvozené hodnoty (součty dokladu,
  `DocRowsDocument::recomputeHeader`) a reload by rozeditovanou hlavičku
  zahodil; při dirty stavu se hodnoty obnoví po Uložit.
- **Mazání:** `ui/ConfirmDialog.svelte` místo `window.confirm`. Enter =
  potvrdit (po otevření má fokus potvrzovací tlačítko, žádný globální
  listener), Esc = zrušit (Modal přes stack zavře jen vršek). Karta 480 px
  s `Modal fixedSize` (mimo depth-shrink vnořených modalů). Ostatní výskyty
  `window.confirm` viz `tasks/TODO.md`; `FormDialog.handleClose` řeší fáze 2.
- **Filtr:** od 11 řádků (`FILTER_THRESHOLD = 10`) `Input` vpravo
  v toolbaru; klientsky přes texty všech buněk bez diakritiky
  (`foldDiacritics` z `utils/paletteMatch.js`), reset při změně `parentId`,
  stav „Filtru neodpovídá žádný záznam". Serverové hledání není v plánu.
- Tabulka: `<colgroup>` (`width` px / `grow` rovný podíl %), sticky
  hlavička (`border-collapse: separate`), zarovnání přes `--num` třídy,
  barva textu na `<table>` (ne na `td`), aby ji globální `.docState_*` na
  `<tr>` přebila.
- **Šipky přesunu ▲ ▼** (`iconMoveUp` / `iconMoveDown`) před Upravit /
  Smazat, jen když přišlo `order_column`, rodič není read-only a filtr
  nemá zadaný text (zúžený seznam: soused ve filtru není soused v pořadí;
  prázdný filtr nad 10 řádky nevadí). První řádek ▲ a poslední ▼ disabled,
  během přesunu obě. Klik → `POST …/move` → jeden refetch (server
  přečísloval skupinu, mění se i sloupec `#`; lokální přeuspořádání by
  ukázalo stará čísla). Rodič se nepřenačítá — součty na pořadí nezávisí.
- `data-testid`: `subtable`, `subtable-add`, `subtable-filter`, `subtable-row`
  (+ `data-row-id`), `subtable-row-up`, `subtable-row-down`,
  `subtable-row-edit`, `subtable-row-delete`, `subtable-row-view`;
  ConfirmDialog `confirm-dialog`, `confirm-ok`, `confirm-cancel`.
### 15.4 Dialog sub-záznamu — tlačítka a navigace (fáze 2)

Dialog řádku je běžný `FormDialog`, který od `FormSubTable` dostává navíc
`navigation` a `onSaveAndContinue`. Top-level dialogy (Osoba, Faktura
z prohlížeče) tyto props neposílají a vypadají jako dřív.

**Tlačítka ve `FormStateBar`** (props `isNew`, `onSaveAndContinue`):

| Záznam | Tlačítka | Po uložení |
|--------|----------|------------|
| nový sub-záznam | **Přidat** (primary, = `onSave`) + **Přidat a pokračovat** (secondary; na mobilu v kebabu) | Přidat: `onSaved` s `wasNew: true` → sub-tabulka dialog zavře (i u forem s doc states). Přidat a pokračovat: `FormEditor.handleSaveAndContinue()` → `saveRecord()` → `resetToNew()` (meta nového záznamu s `defaultData`, nový snapshot, titulek `title_new`, fokus prvního pole mimo lookup) → `onSaveAndContinue(record)` → sub-tabulka jen refetchne řádky a zavolá `onChanged`. Záměrně nejde přes `onSaved`, které by dialog zavřelo. |
| nový top-level záznam | **Uložit** jako dřív | beze změny |
| existující bez doc states (řádky dokladu, měsíce) | **Uložit** | `onSaved` → sub-tabulka zavře (`!hasDocStates`) |
| existující s doc states (Adresy, Kontakty, Účty) | **Uložit** + přechody stavů | dialog zůstává otevřený, zavření řeší `close_form` / křížek — beze změny |

Pravidlo zavření v `FormSubTable.handleDialogSaved`: `!info.hasDocStates ||
info.wasNew`. `wasNew` počítá `FormDialog` z vlastního `recordId == null`
(zůstává null po celou dobu dialogu nového záznamu).

Ukládání v `FormEditor` je rozdělené na sdílené `saveRecord()` (POST / PUT,
zobrazí validační i ostatní chyby, vrátí záznam nebo `null`) a obálky
`handleSave()` / `handleSaveAndContinue()`. Při chybě uložení se nic
neresetuje — formulář zůstane s chybami.

**Navigace Předchozí / Další** (`FormDialog.navigation = {index, count,
onPrev, onNext}`): vlastní ji `FormSubTable`, protože má seřazený seznam
řádků — index se hledá v **nefiltrovaném** `rows` (filtr je jen pro
tabulku), `onPrev` / `onNext` mění `editRecordId`, `FormEditor` formulář
přenačte přes svůj `$effect` na `recordId`. Šipky `‹ 2 / 12 ›` jsou
v hlavičce modalu v novém slotu `Modal.headerNav` (mezi `summary` a `×`,
na mobilu viditelné), na krajích disabled, u nového záznamu (`index = -1`)
se nerenderují. Fungují i v read-only režimu (prohlížení). Klávesy
**Alt+← / Alt+→** přes `Modal.onKeydown` — Modal ho volá jen pro kartu na
vrcholu stacku (stejné pravidlo jako Esc); `FormDialog` klávesu ignoruje,
když je fokus v textovém poli (na macOS Alt+šipka skáče po slovech), jinak
`preventDefault` proti „zpět" v historii prohlížeče.

**Neuložené změny:** zavření (křížek, Esc, klik mimo) i Předchozí / Další
sdílejí `FormDialog.guardDirty(then)` — bez dirty rovnou `then()`, s dirty
`ConfirmDialog` „Neuložené změny" s tlačítky **Zahodit** (→ `then()`) a
**Zůstat**. `window.confirm` ve `FormDialog` zmizel. Přechod se nikdy
neukládá automaticky.

**Zavírání:** žádné tlačítko Zavřít — ani v top-level, ani v sub-dialozích;
zavírá křížek, Esc a klik mimo modal.

`data-testid`: `form-save`, `form-save-continue`, `form-nav-prev`,
`form-nav-pos`, `form-nav-next`, `unsaved-dialog`.

### 15.5 Pořadí řádků — `orderColumn`, endpoint `/move`, automatické `order_pos` (fáze 3)

Sub-tabulka nad tabulkou s pořadovým sloupcem (dnes jen řádky dokladu,
`docs_core_rows.order_pos`) ho deklaruje v `subtableTab(..., orderColumn:
'order_pos')`. `FormTab` validuje neprázdný řetězec a zakazuje kombinaci se
`sort`; existenci sloupce ověří controller (registr tabulek `FormTab` nemá).
Osoby (Kontakty, Adresy, Účty) pořadí nemají → beze změny.

**`POST /_ui/form/{parentTable}/subtable/{tabId}/{parentId}/move`**, tělo
`{ "id": 4711, "direction": "up" | "down" }` → `FormController::subtableMove()`:

1. `resolveSubtableContext()` jako u výpisu; tab bez `orderColumn` → 400
   `SUBTABLE_NOT_ORDERED`; špatné tělo → 400 `BAD_REQUEST`.
2. Rodič v read-only doc state → 422 `DOCUMENT_READONLY` se stejnou hláškou
   jako `save` („Document is read-only in state N."), aby frontend nemapoval
   dvě varianty. Read-only DS odmítá `ReadOnlyPolicy` (fail-closed).
3. Transakce: `SELECT id, order_col FROM child WHERE fk = ? ORDER BY
   order_col ASC, id ASC FOR UPDATE`, řádek mimo rodiče → 404 + rollback;
   přečíslování **celé skupiny 1..N** (řádky přidané sub-formulářem měly
   historicky všechny 0 — prohození dvou nul by nic nezměnilo), prohození
   se sousedem (na kraji no-op, přečíslování se přesto provede), `UPDATE`
   jen u řádků, kde se hodnota liší, commit.
4. Odpověď `{ success: true, data: { order: [ids v novém pořadí] } }`.

Zapisuje přímo přes DB, ne přes Document hooky — přesun úmyslně **nespouští**
přepočet hlavičky (`DocRowsDocument::afterSave`), součty ani rekapitulace na
pořadí nezávisí. Souběh: zámek řádků skupiny; souběžný insert bez zámku může
dostat duplicitní pořadí, další přesun ho srovná.

**Automatické `order_pos`:** `DocRowsDocument::beforeSave()` doplní novému
řádku (`$originalData === null`) bez pořadí (`<= 0`) `MAX(order_pos) + 1`
v rámci `doc_head`. Explicitní kladné `order_pos` (importy, výměnný formát)
zůstává; update se nedotýká. Ruční pole „Pořadí" z `DocRowsForm` zaniklo
(oba layouty). Generický mechanismus pro jiné tabulky se zatím nedělá — až
bude druhá tabulka s pořadím, přesune se do `Document` podle `orderColumn`.

Konzumenti pořadí (`DocsHeadsViewer::buildDetailRows`, `AccountingEngine`)
čtou `ORDER BY order_pos` (viewer s `id` tiebreakerem) — přečíslování jen
zpevňuje pořadí, obsah nemění.

---

## 16. Svelte komponenty

| Komponenta | Popis |
|------------|-------|
| `Modal.svelte` (ui/) | Generický modal: header s titulkem a `×`, tělo, overlay, body scroll lock, modal stack pro Esc handling. Volitelný `headerExtra` snippet pro badge, `headerNav` (šipky navigace před `×`), `width` a `height` props, `fixedSize` (vyjme kartu z depth-shrinku — malé dialogy), `onKeydown` (klávesy jen pro kartu na vrcholu stacku). |
| `ConfirmDialog.svelte` (ui/) | Potvrzovací dialog nad Modal (480 px, `fixedSize`): `title`, `message`, `confirmLabel`, `variant` primary/danger, `busy`, `onConfirm` / `onCancel`; Enter = potvrdit (fokus na tlačítku), Esc = zrušit. Náhrada `window.confirm` — kap. 15.3 |
| `FormDialog.svelte` | Orchestrátor — Modal s škálující se velikostí (clamp 1200–1700 px), poskytuje header (titulek + badge + subtitle + summary + šipky navigace), drží dirty stav, `guardDirty()` s `ConfirmDialog` Zahodit / Zůstat při zavření i Předchozí / Další. Meta načítá FormEditor uvnitř. Props `readOnly`, `notice`, `navigation`, `onSaveAndContinue`; `onSaved` info nese `hasDocStates` + `wasNew` — kap. 15.4 |
| `FormEditor.svelte` | Hlavní shell: tab bar, obsah, toolbar (header je v Modal). Sleduje dirty stav (snapshot vs aktuální data), propaguje titulek/doc_states/dirty zpět do FormDialog přes callbacky `onFormLoaded` a `onDirtyChange`. Prop `readOnly`; `isReadOnly` / `isDisabled` zvlášť pro sub-tabulky; `handleSubtableChanged()` tichý reload rodiče; `saveRecord()` + `handleSave()` / `handleSaveAndContinue()` (`resetToNew()`, fokus prvního pole) |
| `FormTab.svelte` | Jeden tab — vykreslí sekce / subtable / attachments podle `tab.type`; sub-tabulce předává `tabId`, `parentTable`, `readOnly`, `onSubtableChanged` |
| `FormSection.svelte` | Karta s pozadím a volitelným titulkem; horizontální grid pro N sloupců |
| `FormColumn.svelte` | Sloupec se sdílenou auto-šířkou labelů (`max-content 1fr`) |
| `FormFieldRow.svelte` | Wrapper jedné label+input dvojice — emituje DVA sourozence do FormColumn gridu |
| `FormInline.svelte` | Inline skupina (víc polí v jedné řádce); první pole použije label řádky, ostatní mají mini-labely |
| `FormElement.svelte` | Renderer elementu — switch podle `type` + delegace na UI komponenty |
| `FormSubTable.svelte` | Sub-tabulka: sloupce a řádky ze serveru (`/subtable`), Přidat / Upravit / Smazat (ConfirmDialog) nebo Zobrazit v read-only, klientský filtr od 11 řádků — kap. 15 |
| `FormStateBar.svelte` | Spodní toolbar: Uložit + přechodová tlačítka; `readOnly` skryje obojí, bez jediné akce se nerenderuje. `isNew` + `onSaveAndContinue` → Přidat + Přidat a pokračovat (na mobilu v kebabu; kebab má generické položky `{label, danger, run}`) |
| `FormStateBadge.svelte` | Badge stavu v záhlaví Modalu |

### UI komponenty (`components/ui/`)

`Input.svelte`, `TextArea.svelte`, `NumberInput.svelte`, `DateInput.svelte`, `Select.svelte` jsou „bezlabelové" — renderují pouze input a error hlášku. Label dodá obalující `FormFieldRow` / `FormInline`. Komponenty mají prop `id`, který se navazuje na `<label for>`.

`Checkbox.svelte` je výjimka: jeho interní `<label>` slouží jako UX text vedle boxu (např. „Plátce DPH"), takže prop `label` zůstává.

---

## 17. PHP třídy a soubory

### `src/Core/Form/`
| Třída | Popis |
|-------|-------|
| `TableForm` | Abstraktní bázová třída; auto-label z TableDefinition; `setTables()` + default `renderSubtable()` sub-tabulek (kap. 15) |
| `SubtableCellFormatter` | Sdílené formátování buněk sub-tabulek: `money`, `number`, `trimmedNumber`, `price`, `date`, `dateTime`, `boolean` |
| `TabBuilder` | Fluent builder; autoHideSeparators |
| `FormDefinition` | Datová třída; `toArray()` → snake_case JSON |
| `FormTab` | Datová třída |
| `FormElement` | Datová třída; `input_type`, `hidden`, `triggers` |
| `FormRegistry` | Registr PHP tříd formulářů; podporuje per-table polymorfismus přes `typeColumn` + `classes` + `defaultClass` (`createForm($table, $data, ...)`) — viz kap. 23 |
| `FormController` | HTTP controller; volá `createForm($table, $data, ...)` ve všech metodách (`resolveFormDefinition`, `recalculate`, `enrichHeaderInfo`, `resolveSubtableContext` pro `subtable` / `subtableMove`) |
| `AutoFormBuilder` | Generuje FormDefinition z TableDefinition |
| `JsoncFormLoader` | Načítá JSONC formy |
| `RecalculateResult` | Výsledek recalculate |

### `src/Api/`
| Soubor | Popis |
|--------|-------|
| `FormLoader.php` | Načte FormRegistry z modulů; `mergeForms()` slévá per-table registrace přes moduly (paralela `DocumentLoader::mergeDocumentClasses`) |
| `DocumentLoader.php` | Načte DocumentRegistry z modulů |

---

## 18. `closeForm` v definici stavů dokumentů

V cfgItem konfigurace stavů (např. `docStatesArchive.jsonc`) lze u každého stavu nastavit:

```jsonc
"40": {
    "stateName": "V pořádku",
    "closeForm": 1,
    "goto": [80, 70, 90]
}
```

`closeForm: 1` → po přechodu do tohoto stavu se formulář zavře. `closeForm: 0` (výchozí) → formulář zůstane otevřený.

`DocStateConfig::getAvailableTransitions()` vrátí `close_form: bool` u každého přechodu v API odpovědi.

---

## 19. Implementační poznámky a pastče

### `dispatch()` a sdílené proměnné v `index.php`

Funkce `dispatch()` je globální PHP funkce — nemá přístup k lokálním proměnným z `try` bloku. Každý kontext musí být explicitně předán jako parametr i do signatury funkce.

```php
// Špatně — $documentRegistry existuje v try bloku, ale dispatch() ho nevidí
dispatch($route, ..., $formRegistry);

// Správně
dispatch($route, ..., $formRegistry, $documentRegistry);
function dispatch(..., ?DocumentRegistry $documentRegistry = null): Response { ... }
```

### `{#each}` klíče ve Svelte 5

`Math.random()` jako klíč způsobuje destrukci komponent při každém re-renderu. Pro elementy bez unikátního ID:
```js
{#each tab.elements as element, i (element.column ?? `${element.type}-${i}`)}
```

### `Select` a callback props ve Svelte 5

Svelte 5 komponenty nepředávají DOM eventy automaticky. `onchange` musí být explicitní prop v interface + předán na interní `<select>`.

### Reaktivní `$effect` a leaky dependencies přes async helpery

Svelte 5 `$effect` sleduje reaktivní reads v synchronní části svého těla — včetně readů uvnitř volných funkcí (až do prvního `await`). Snadno to vede ke skrytým závislostem, které nečekaně spouštějí re-runy efektu.

Konkrétním případem byl reload po Uložit ve `FormEditor.svelte`. Původní efekt:

```js
$effect(() => {
  const tbl = table;
  const id = recordId;
  currentId = id;
  loadForm(tbl, id);   // čte `defaultData` před prvním await
});
```

`loadForm` synchronně kontroluje `if (id == null && defaultData && Object.keys(defaultData).length > 0)` před `await get(path)`. Pro nový záznam (`id == null`) se `defaultData` přečte — a tím se stane sledovaným depem efektu. Po Uložit rodič (Viewer) typicky resetuje `formDefaultData = {}` (nová object reference), efekt se znovu spustí, přepíše `currentId` zpět na `recordId` (stále `null`) a spustí paralelní `loadForm(table, null)`. Ten dorazí jako poslední a přepisuje data čerstvě uloženého záznamu prázdným formulářem.

U existujících záznamů bug nenastane: `id == null` je `false`, AND short-circuit přes `id == null` `defaultData` v podmínce vůbec nečte — dep se nezaloží.

Fix — `untrack` ohraničí, co se nemá trackovat:

```js
import { untrack } from 'svelte';

$effect(() => {
  const tbl = table;
  const id = recordId;
  // Reload jen na změnu (table, recordId). Reads uvnitř loadForm
  // (defaultData prop) se nesmí stát skrytými dependencies.
  untrack(() => {
    currentId = id;
    loadForm(tbl, id);
  });
});
```

Obecné pravidlo: pokud `$effect` volá async helper, který před prvním `await` čte další reaktivní stav neuvedený na dependency listu efektu, obal volání do `untrack(() => ...)`. Nebo helper přestavte tak, aby reaktivní reads byly až za prvním `await` (anti-pattern, lepší je explicitní `untrack`).

Současně — `Viewer.handleFormSaved` zase nesmí resetovat `formTable` / `formDefaultData` při každém save, formulář může zůstat otevřený. Reset patří do `handleFormClose`.

### Validace `enumInt` v `InputValidator`

cfgItem je mapa klíčů `{"0": {...}, "1": {...}}`. Správná validace:
```php
array_key_exists((string) $value, $cfgData)  // ✓
in_array($value, $cfgData)                    // ✗ hodnoty jsou objekty, ne skalary
```

### Business logika vs. DB constraints

`InputValidator` validuje pouze DB constraints. Sloupce vyplňované automaticky v `beforeSave` musí být v DB `nullable` — jinak validátor odmítne data dřív než se `beforeSave` zavolá.

### `Document` má přístup k DB

`TableGateway` volá `$doc->setDb($this->db)` před každým hookem. Document subclassy mohou používat `$this->db` pro vlastní dotazy (generování unikátních kódů, `person_id` apod.).

### Datum/čas z Dibi

Dibi vrací DATE a DATETIME sloupce jako `Dibi\DateTime` objekty. `DataSourceConnection::fetchRow/fetchAll` je normalizuje na stringy: DATE → `"YYYY-MM-DD"`, DATETIME → `"YYYY-MM-DDTHH:MM:SS"`.

### Envelope konvence

Všechny API odpovědi mají tvar `{ success, data, meta? }`. Data jsou vždy v `res.data`, nikdy přímo v `res`. Např. `res.data.formDefinition`, ne `res.formDefinition`.

---

## 20. Historie / Migrace

Layout systém byl v PR „new-forms-01" kompletně přepracován:

- **Pryč:** `cols: 1..4` na elementu, `type: "group"`, `type: "subtable"` jako element uvnitř tabu, label uvnitř UI komponent.
- **Přibylo:** `FormSection`, `FormColumn` jako explicitní vrstvy mezi tabem a elementy. `FormFieldRow` a `FormInline` pro label-vně vykreslování. Subtable a attachments jsou vlastní typy tabu.
- **Vizuál:** Sekce mají kartové pozadí (`--shpd-color-bg-secondary`) a jemnou hranu (`--shpd-color-border-subtle`). Labely vlevo s auto-šířkou v rámci sloupce (CSS Grid `max-content 1fr`).
- **Builder:** `TabBuilder` má scope management `section() → col() → elementy`. Bez `addInput`/`addSelect` prefixu; metody se jmenují podle widgetu (`input`, `select`, `date`, `textarea`, `checkbox`, …).
- **JSONC:** stará struktura `tabs[].elements[]` s `cols` čísly je odmítnuta `JsoncFormLoader`em s konkrétní hláškou.
- **`fullSize` flag odstraněn.** Všechny modaly mají jednotnou velikost, vnořené přes existující depth-shrink mechanismus (kap. 9). Žádný flag v JSONC ani PHP, žádný `full_size` ve wire formátu. Následně velikost top-level modalu přešla z pevných 1200×900 na `clamp(1200px, 80vw, 1700px)` × `clamp(720px, 88vh, 1100px)` — na větších monitorech se modal roztahuje, méně se scrolluje.

Žádná backward compatibility — staré formy bylo nutné mechanicky portovat.

---

## 21. Hlavička formuláře (HeaderInfo)

Editační modal má strukturovanou hlavičku se čtyřmi volitelnými prvky: **ikona** vlevo, **titulek** uprostřed nahoře, **subtitle s identifikátory** pod titulkem (na něj inline kotví stavový badge), a **souhrn** vpravo (typicky totals u dokladů). Vše je server-driven přes `FormHeaderInfo`.

```
┌──────────────────────────────────────────────────────────────────────────┐
│ ┌──┐ Beta Software, a.s.                  Bez DPH:    10 000,00      [×] │
│ │📄│ [Koncept] Přijatá faktura · 2024-0001  DPH:       2 100,00           │
│ └──┘                                       Celkem CZK: 12 100,00          │
├──────────────────────────────────────────────────────────────────────────┤
│ [Hlavička] [Řádky] [Rekapitulace DPH] [Poznámky] [Přílohy] [Nastavení]    │
```

Persons forma má jen 2 prvky (ikona + title + subtitle s badge), bez souhrnu vpravo:

```
┌────────────────────────────────────────────────────────────────────┐
│ ┌──┐ Beta Software, a.s.                                       [×] │
│ │🏢│ [Koncept] IČO 68253848 · Kód osoby TEST-0098                  │
│ └──┘                                                                │
├────────────────────────────────────────────────────────────────────┤
```

A formy bez `buildHeaderInfo()` override (typicky JSONC sub-formuláře jako Kontakt nebo Adresa) si zachovávají původní jednořádkový layout — badge vpravo vedle titulky, žádný subtitle, žádná ikona, žádný souhrn:

```
┌───────────────────────────────────────────────────────────────┐
│ Kontakt                                       [Koncept]   [×] │
├───────────────────────────────────────────────────────────────┤
```

### Kdy se zobrazuje

- **Existující záznam** (`GET /meta/{id}`) — pokud `TableForm::buildHeaderInfo()` vrátí non-null
- **Nový záznam** (`GET /meta`) — `header_info: null`, modal zobrazí jen `title_new`
- **Recalculate** (`POST /recalculate`) — `header_info: null`, klient ignoruje (hlavička neodráží neuložené změny)
- **Save** — server nevrací `header_info` přímo v save response. Klient po úspěšném save volá `loadForm()` (přes meta endpoint), čímž se header aktualizuje na novou uloženou hodnotu

### Živý pruh součtů (`live_summary`) — protějšek pro neuložený stav

`header_info` je záměrně **uložený stav** — recalculate ho vrací `null`
a klient drží hodnotu z loadu. Pro hodnoty, které mají odrážet **právě
editovaná, neuložená data**, má `FormDefinition` volitelné `live_summary`:
`list<array{label, value}>` (stejný tvar jako `header_info.summary`),
konstruktorový parametr `liveSummary` za `headerInfo`. `toArray()` klíč
emituje **jen když je neprázdný** — výstup ostatních formulářů se nemění.

- **Kdo ho plní:** `buildFormDefinition()` z aktuálních `$data` — běží při
  loadu (meta) i při každém recalculate, takže pruh je živý bez dalšího
  kódu. Nic se necachuje.
- **Kde se renderuje:** `FormEditor.svelte` mezi tab-barem a validačním
  bannerem (mimo scrollovaný obsah), zarovnaný vpravo jako `summary`
  hlavičky, hodnoty `tabular-nums`, během `recalculating` ztlumený.
  `savedHeaderInfo` se nedotýká.
- **První uživatel:** `DocRowsForm` — Základ · DPH · Celkem <měna> řádku
  dokladu (#71) z `vat_base` / `vat_amount` / `vat_total`, které
  `applyLiveCalculation` přepočítává při každém recalculate přes sdílený
  `DocRowCalculator` (tentýž kód jako save cesta `DocDocument`). Bez DPH
  na hlavičce jen Celkem; textový řádek, kontační řádek a řádek bez
  vypočteného celkem → prázdné pole, klient nic nerenderuje. Formát
  částek přes `SubtableCellFormatter::money()`.
- **Mimo rozsah zatím:** živé součty hlavičky dokladu — `summary`
  v `header_info` zůstává uložený stav.

```json
{
  "live_summary": [
    { "label": "Základ",     "value": "1 500,00" },
    { "label": "DPH",        "value": "315,00" },
    { "label": "Celkem CZK", "value": "1 815,00" }
  ]
}
```

### Struktura

`FormHeaderInfo` (`src/Core/Form/FormHeaderInfo.php`):

```php
final class FormHeaderInfo
{
    public function __construct(
        public readonly string $title,
        /** @var list<array{label: string, value: string}> */
        public readonly array $info = [],
        public readonly ?string $icon = null,
        /** @var list<array{label: string, value: string}> */
        public readonly array $summary = [],
    ) {}
}
```

Wire formát (`header_info` klíč ve `FormDefinition.toArray()`, vždy přítomný — `null` nebo objekt; uvnitř jsou všechny čtyři klíče vždy přítomné, defaulty jsou prázdná hodnota):

```json
{
  "header_info": {
    "title": "Beta Software, a.s.",
    "info": [
      { "label": "",      "value": "Přijatá faktura" },
      { "label": "Číslo", "value": "2024-0001" }
    ],
    "icon": "invoice-in",
    "summary": [
      { "label": "Bez DPH", "value": "10 000,00" },
      { "label": "DPH",     "value": "2 100,00" },
      { "label": "Celkem CZK", "value": "12 100,00" }
    ]
  }
}
```

| Pole | Typ | Význam |
|------|-----|--------|
| `title` | string | Hlavní titulek nahoře (přepíše generický formDef.title) |
| `info` | list | Identifikátory zobrazené v subtitle řádku spojené `·` |
| `icon` | string\|null | Klíč ikony z `icons.js::iconMap` (např. `"company"`, `"user"`, `"invoice-in"`). Frontend překládá přes `resolveIcon()`. `null` = bez ikony. |
| `summary` | list | Pravý blok (label/value páry). Renderuje se v 2-sloupcovém gridu (label vpravo zarovnaný, value tučný, tabular-nums). Prázdné pole = bez bloku. |

### Override v PHP

Subclass `TableForm` přepíše virtuální metodu `buildHeaderInfo()`. Persons:

```php
public function buildHeaderInfo(array $data): ?FormHeaderInfo
{
    $fullName = trim((string) ($data['full_name'] ?? ''));
    if ($fullName === '') {
        return null;
    }
    $personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));

    $info = [];
    $icon = null;
    if ($personType === PersonType::Company) {
        $icon = 'company';
        $companyId = trim((string) ($data['company_id'] ?? ''));
        if ($companyId !== '') {
            $info[] = ['label' => 'IČO', 'value' => $companyId];
        }
    } elseif ($personType === PersonType::Person) {
        $icon = 'user';
        // ... birth_date formátování
    } else {
        return null;
    }

    $personId = trim((string) ($data['person_id'] ?? ''));
    if ($personId !== '') {
        $info[] = ['label' => 'Kód osoby', 'value' => $personId];
    }

    return new FormHeaderInfo(title: $fullName, info: $info, icon: $icon);
}
```

U dokladů (`docs_core_heads`) žije sdílený `buildHeaderInfo()` přímo v `DocsHeadsFormBase`. Volá tři virtuální hooky a sestaví hlavičku tak, jak má vypadat pro libovolný typ dokladu (FPB, FVB, proforma, dobropis, bankovní výpis, …):

```php
abstract class DocsHeadsFormBase extends TableForm
{
    /** „Přijatá faktura" / „Vydaná faktura" / … — popisek vedle čísla v subtitle. */
    protected function getDocTypeLabel(): string { return 'Doklad'; }

    /** Klíč ikony z icons.js::iconMap (typicky shodný s viewers[].icon). */
    protected function getHeaderIcon(): ?string { return null; }

    /** Kde žije snapshot partnera — 'supplier_snapshot' pro přijaté
     *  (default), 'customer_snapshot' pro vydané. */
    protected function getPartnerSnapshotKey(): string { return 'supplier_snapshot'; }

    public function buildHeaderInfo(array $data): ?FormHeaderInfo
    {
        $partnerName = $this->resolvePartnerName($data);  // snapshot.name → fallback DB SELECT
        if ($partnerName === '') {
            return null;
        }
        $info = [['label' => '', 'value' => $this->getDocTypeLabel()]];
        $docNumber = trim((string) ($data['doc_number'] ?? ''));
        if ($docNumber !== '') {
            $info[] = ['label' => 'Číslo', 'value' => $docNumber];
        }
        return new FormHeaderInfo(
            title: $partnerName,
            info: $info,
            icon: $this->getHeaderIcon(),
            summary: $this->buildHeaderSummary($data),  // Bez DPH / DPH / Celkem CZK
        );
    }
}
```

Subclassy pak deklarují jen rozdíly:

```php
class ReceivedInvoiceForm extends DocsHeadsFormBase  // FPB, doc_type='invni'
{
    protected function getDocTypeLabel(): string { return 'Přijatá faktura'; }
    protected function getHeaderIcon(): ?string  { return 'invoice-in'; }
    // getPartnerSnapshotKey() — default 'supplier_snapshot' je správný.
}

abstract class IssuedInvoiceFormBase extends DocsHeadsFormBase  // docs.core: FVB + FVZ
{
    protected function getPartnerSnapshotKey(): string { return 'customer_snapshot'; }
    // + sdílený buildHeaderTab() / buildExtraTabs() (tab Nastavení)
}

class IssuedInvoiceForm extends IssuedInvoiceFormBase  // FVB, doc_type='invno'
{
    protected function getDocTypeLabel(): string { return 'Vydaná faktura'; }
    protected function getHeaderIcon(): ?string  { return 'invoice'; }
}

class ProformaOutForm extends IssuedInvoiceFormBase  // FVZ, doc_type='invpo'
{
    protected function getDocTypeLabel(): string { return 'Zálohová faktura'; }
    protected function getHeaderIcon(): ?string  { return 'invoice-proforma'; }
}
```

Stejný hook-pattern jako `getFormTitle()` / `getNewFormTitle()` / `buildExtraTabs()`. Společná logika (`buildHeaderInfo`, `resolvePartnerName`, `buildHeaderSummary`) žije v `DocsHeadsFormBase` jako `protected`, takže subclassy můžou kterýkoliv jednotlivý kus override-ovat, pokud potřebují (např. ručně sestavit `summary` z atypických polí pro bankovní výpis).

Pravidla pro implementaci:

- **Vrátit `null`**, pokud nemáme co zobrazit (typicky prázdný hlavní identifikátor) — modal pak zobrazí jen `formDef.title` jako jednořádkovou hlavičku (bez subtitle, bez ikony, bez souhrnu, badge vpravo).
- **Vynechat položky `info` / `summary`** s prázdnou hodnotou — obě pole mohou být prázdná. `info: []` = subtitle řádek je v UI pořád vidět (kvůli badge, který se kotví na něj), ale bez identifikátorů.
- **Prázdný `label` v `info` položce** značí „jen hodnota bez prefixu" — např. u dokladu typ `Přijatá faktura` nepotřebuje „Typ:" labelu, je sebepopisný. Frontend pak vloží do subtitle řádku jen hodnotu bez vedoucí mezery. Používej střízlivě — většina identifikátorů label potřebuje („IČO", „Číslo").
- **Skrýt `summary`**, dokud doklad nemá vypočítané totals (nový záznam → `total_amount === 0`). Předejde nezajímavému „Bez DPH 0,00 · DPH 0,00 · Celkem 0,00".
- **Data jsou z DB** (uložená), ne živá z formuláře — metoda dostává `array $data` z `fetchRow`. Pro snapshoty (`supplier_snapshot.name` apod.) je hodnota stringem JSON; pomocný `decodeSnapshot()` z `DocsHeadsFormBase` ji bezpečně rozparsuje.
- **Lokalizace labelů** (`IČO`, `Bez DPH`, …) je zatím napevno v jazyce modulu; i18n vrstva pro PHP texty se řeší v navazujících taskech.

Default implementace v `TableForm` vrací `null` — JSONC/Auto formuláře a všechny moduly, které neoverrideují, hlavičku nezobrazí.

### Ikony — string klíče, ne FontAwesome instance

`icon` v `FormHeaderInfo` je **string klíč** ze sdíleného registru `frontend/src/icons.js::iconMap` (stejný registr jako sidebar nav, viewer řádky, toolbar). Backend tedy nepředává FA instance, jen pojmenování významu — frontend je přeloží přes `resolveIcon(name, fallback)`. Vzory:

- Persons: `'company'` (firma) / `'user'` (fyzická osoba) — odvozeno z `person_type` stejně jako `PersonsViewer::renderRow()`.
- Doklady: `'invoice-in'` (FPB), v budoucnu `'invoice'` (FVB), `'document'` (generický). Hodnota se typicky shoduje s `viewers[].icon` ve `module.jsonc` daného modulu — jeden klíč pro viewer list i form header.

Nová ikona = záznam v `frontend/src/icons.js` (import z FA + export + `iconMap` mapping). Backend pak hodnotu používá jako neprůhledný string.

### Frontend render

- `Modal.svelte` přijímá čtyři volitelné snippet propy: `subtitle`, `headerExtra` (badge), `iconSlot` a `summary`. Layout:
  - `iconSlot` vlevo, ve fixním 40×40 boxu, vertikálně vystředěný na celou výšku hlavičky.
  - `headerExtra` pozice se mění podle přítomnosti `subtitle`: **bez subtitle** sedí vpravo od titulky (stejně jako dřív, zpětně kompatibilní), **se subtitle** se inline kotví na začátek subtitle řádku — tvoří „kontext záznamu" cluster s identifikátory.
  - `summary` vpravo, mezi headerem a `×`. Kontrakt: snippet musí emitovat páry sourozenců `<label-element><value-element>` — Modal je rozloží do 2-sloupcového gridu (label pravostranně zarovnaný + sekundární barva, value tučný + primární barva + tabular-nums pro slušné zarovnání čísel).
- `FormEditor.svelte` drží `savedHeaderInfo` state, aktualizuje ho jen v `loadForm` (NE v `handleTrigger`/recalculate), a propaguje přes `onFormLoaded` callback.
- `FormDialog.svelte` mapuje `headerInfo` na Modal propy:
  - `iconSnippet` (přes `<Icon icon={resolveIcon(headerInfo.icon)} />`), předán jen pokud `headerInfo.icon` není null
  - `subtitleSnippet` (řádek `"Label1 hodnota1 · Label2 hodnota2 · …"`), předán jen pokud `headerInfo !== null` — tím se zachovává jednořádkový layout pro formy bez override
  - `summarySnippet` (`#each` přes `summary`, emituje `<span>{label}</span><span>{value}</span>` pro každou položku), předán jen pokud `summary.length > 0`
  - `headerExtraSnippet` (FormStateBadge) předán vždy, když máme `currentDocStates`
- Title v Modalu se rozhoduje: `headerInfo.title || formDef.title || t('common.loading')` — strukturovaný title má přednost před generickým „Osoba" / „Faktura" apod.

### Proč ne živá data

Hlavička reflektuje **uložený stav v DB**, ne dirty formData. Důvody:

- Recalculate může změnit `person_type` z Person na Company — title by „blikal" mezi „Jan Novák" a názvem firmy podle rozpracovaného formuláře, ikona by skákala mezi `user` a `company`.
- Uživatel může mít rozpracované špatné jméno; po Cancel by header lhal, že záznam má jiný titulek, než ve skutečnosti.
- Totals (`summary`) by reflektovaly rozpracované řádky, ne zatím uložený stav — což by uživatele mátlo, protože hodnoty v Rekapitulaci DPH tabu by neseděly.
- Server-side `buildHeaderInfo` má jasná pravidla a vstupuje do něj jen schválně načtená data (`SELECT * WHERE id = ?`).

---

## 22. Lookup pole

Inline combobox pro FK na velké tabulky. Klient si průběžně dohledává záznamy přes serverový endpoint — žádné statické `options[]` v `FormDefinition`.

### Kdy použít `lookup` vs `select`

- **`select`** — enumy (`enumInt`/`enumString`), malé cfgItem-based číselníky (jednotky, sazby DPH, typy adres). Options se předají v `FormDefinition` jako pole `{value, label}`.
- **`lookup`** — velké tabulky (Osoby, Adresy, Bankovní účty, Položky…), kde nativní `<select>` nezvládá vyhledávání a stažení tisíců záznamů do payloadu by zpomalilo render.

### Wire formát elementu

```json
{
    "type": "lookup",
    "column": "partner",
    "label": "Partner",
    "placeholder": "Hledat partnera…",
    "required": false,
    "read_only": false,
    "hidden": false,
    "triggers": "reload",
    "lookup": {
        "table": "base_persons_persons",
        "filter": null
    }
}
```

Cascade variant (po vybrání partnera filtruje adresy podle něj):

```json
{
    "type": "lookup",
    "column": "partner_address",
    "lookup": {
        "table": "base_persons_addresses",
        "filter": {"person": 42}
    }
}
```

Pravidla:

- `lookup.table` — DB název cílové tabulky; musí být registrovaná v `LookupRegistry` (jinak endpoint vrátí 404 `LOOKUP_NOT_REGISTERED`).
- `lookup.filter` — server-zapečené páry `{column: scalar}`. Frontend je zkopíruje do query stringu volání jako `filter[col]=val`. Whitelist klíčů je v `TableLookup::getAllowedFilterKeys()`; neznámé klíče controller silently zahodí.
- `lookup` element nelze umístit do `inline` skupiny (validace v `FormElement` konstruktoru, inline povoluje jen `input`/`select`).
- Cascade přes `triggers: 'reload'` — po změně partnera proběhne `recalculate`, server rebuildne FormDefinition s novým filtrem v adresách/bance.

### Endpoint `/_ui/lookup/{table}/search`

```
GET /_ui/lookup/{table}/search?q={term}&limit={n}&filter[col]={val}
```

| Parametr | Default | Limity |
|----------|---------|--------|
| `q` | `""` | Prázdné = první stránka záznamů (browseable) |
| `limit` | 20 | Max 50 (víc se srazí na 50) |
| `filter[<col>]` | — | Whitelist přes `TableLookup::getAllowedFilterKeys()`; ostatní se ignorují |

Response:

```json
{
    "success": true,
    "data": {
        "items": [
            {"id": 42, "primary": "Testování 999", "secondary": "IČO 12345678"},
            {"id": 17, "primary": "Testování 22",  "secondary": "IČO 87654321"}
        ],
        "limit": 20,
        "total": null
    }
}
```

- `items[].id` může být int nebo string (FK na enumString)
- `items[].secondary` může být `null` — frontend pak druhý řádek nevykreslí
- `total` je v MVP vždy `null` (klíč je zachován pro budoucí stránkování)

Chybové kódy: `LOOKUP_NOT_REGISTERED` (404), `TABLE_NOT_FOUND` (404), `BAD_REQUEST` (400 — neplatný limit/parametry), `METHOD_NOT_ALLOWED` (405).

### Endpoint `/_ui/lookup/{table}/resolve`

```
GET /_ui/lookup/{table}/resolve?ids=42,17,3
```

Vrátí display popis pro konkrétní ID. Klient ho typicky nevolá — server pre-resolvuje hodnoty v meta/save/recalculate response. Použití je v okrajových situacích (kdyby `dataResolved` z lokálního stavu zmizel).

Response stejný tvar jako search bez `total`. Neexistující ID se v `items[]` prostě vynechá.

### `dataResolved` v meta/save/recalculate response

`FormController` v každé z metod (`meta`, `save`, `recalculate`, state-transition) sestaví top-level klíč `dataResolved` paralelně k `data`:

```json
{
    "success": true,
    "data": {
        "formDefinition": { ... },
        "data": {
            "partner": 42,
            "partner_address": 17,
            "partner_bank": null
        },
        "dataResolved": {
            "partner":         {"id": 42, "primary": "Testování 999",  "secondary": "IČO 12345678"},
            "partner_address": {"id": 17, "primary": "Hlavní 12, Praha", "secondary": null}
        }
    }
}
```

- `dataResolved` je vždy přítomné (pro nový záznam je `{}`)
- Klíče jsou pouze ty `column` z lookup elementů, kde má `data[column]` ne-null hodnotu a kde resolve uspěl
- camelCase top-level (drží konzistenci s `formDefinition`); uvnitř `formDefinition.tabs[].sections[]` zůstává snake_case

### `TableLookup` třída

Konkrétní lookupy dědí z abstraktní `Shipard\Core\Form\Lookup\TableLookup`:

```php
abstract class TableLookup
{
    /** @return list<LookupItem> */
    abstract public function search(string $q, array $filter, int $limit): array;

    /** @return list<LookupItem> */
    abstract public function resolve(array $ids): array;

    /** @return list<string> Whitelist filter keys (default: žádné) */
    public function getAllowedFilterKeys(): array { return []; }
}
```

Setter trojicí ze základní třídy dostávají instanci `DataSourceConnection`, `?ConfigRuntime`, `?TableDefinition` (volá `LookupRegistry::create()`).

### Registrace v `module.jsonc`

```jsonc
"lookups": [
    {
        "table": "base_persons_persons",
        "class": "Shipard\\Module\\Base\\Persons\\PersonsLookup"
    },
    {
        "table": "base_persons_addresses",
        "class": "Shipard\\Module\\Base\\Persons\\AddressesLookup"
    }
]
```

`LookupLoader` (analogie `FormLoader`) projde všechny moduly a naplní `LookupRegistry` při bootu. Tabulka bez registrace → endpoint vrací 404 `LOOKUP_NOT_REGISTERED`.

### PHP builder API

```php
$this->tab('basic', 'Hlavička')
    ->section()->col()
        ->lookup('partner',
            table: 'base_persons_persons',
            placeholder: 'Hledat partnera…',
            triggers: 'reload',
        )
        ->lookup('partner_address',
            table: 'base_persons_addresses',
            filter: $partnerId !== 0 ? ['person' => $partnerId] : null,
            placeholder: $partnerId !== 0 ? 'Vyberte adresu…' : 'Nejdřív vyberte partnera',
            readOnly: $partnerId === 0,
        )
    ->build();
```

Signatura `TabBuilder::lookup()`:

```php
public function lookup(
    string $column,
    string $table,
    ?array $filter = null,
    ?string $label = null,
    ?string $placeholder = null,
    bool $required = false,
    bool $readOnly = false,
    bool $hidden = false,
    ?string $triggers = null,
    ?string $hint = null,
): static
```

### Deklarativní JSONC

```jsonc
{
    "type": "lookup",
    "column": "partner",
    "lookup": {
        "table": "base_persons_persons"
    }
}
```

Statický `filter` lze v JSONC zapsat taky (`"filter": {"col": "val"}`), ale typicky se filtry generují dynamicky v PHP `recalculate()` — viz `DocsHeadsForm` jako kanonický vzor.

### Cascade přes recalculate — destruktivní reset

Vzor v `DocsHeadsForm`:

1. Uživatel změní partnera v `partner` lookup poli (`triggers: 'reload'`).
2. Frontend pošle `POST /_ui/form/{table}/recalculate` s body `{changedColumn: 'partner', data: {...}}`.
3. Server v `DocsHeadsForm::recalculate()` při `changedColumn === 'partner'` **vždy vynuluje `partner_address` a `partner_bank`** (cascade reset) — dřív vybraná adresa/banka patřily bývalému partnerovi a uživatel je musí vybrat znovu z filtrovaného dropdownu nového partnera. Pokud je zadaný nový partner, dopočítá `due_date` z `payment_term_days` (jen pokud ještě není). Žádný auto-fill na hlavní adresu — výběr je vždy explicitní.
4. Server zavolá `buildFormDefinition` — nový FormDef má `partner_address` lookup s `filter: {person: newPartnerId}` (místo původního null) a placeholder „Vyberte adresu…“ místo „Nejdřív vyberte partnera“.
5. `FormController` sestaví `dataResolved` — chybějí klíče `partner_address`, `partner_bank` (data jsou null), je přítomný `partner`.
6. Frontend v `handleTrigger` **replace** `dataResolved` ze serverové response — staré displaye adresy/banky zmizejí, inputy se vyprázdní (`hasValue` je false).

Klíčem je, že backend `recalculate` u destruktivní cascade explicitně vynuluje závislá pole, a frontend `dataResolved` přepisuje replace strategy (ne merge). Cascade nepotřebuje žádný nový mechanismus — funguje přes existující recalculate flow.

### Cascade přes recalculate — propisující (položka → řádek)

Protipolný vzor v `DocRowsForm`. Změna `item` vždy přepisuje `description`, `unit_price`, `unit` z dat položky (`economy_items`) — platí pro výběr z dropdownu, create nové položky i edit existující (viz `edit_triggers` flag v další sekci). Sémantika: **položka = řádek**, kompletně. Ruční slevy se řeší přes samostatná pole `discount_pct` / `discount_amount`, nikoli přepisem `unit_price` ručně — ten by se při příští změně položky beztak přepisal.

### Frontend chování (`LookupInput.svelte`)

- **Render value (dropdown zavřený):** input zobrazí `resolved.primary`. `resolved.secondary` se uvnitř inputu **nezobrazuje** — sekundární řádek (IČO, Datum narození…) se ukazuje jen u položek v rozevréném dropdownu.
- **Klik / fokus:** otevře dropdown s prázdným `q` (první stránka záznamů). Pokud má hodnotu, do inputu se vyplnil `displayLabel` (vybrané jméno) a `inputEl.select()` v `queueMicrotask` text vyselectuje. Uživatel tak vidí, kdo je vybraný, a první stisk klávesy text přepíše (standardní combobox UX).
- **Psaní:** debounce 300 ms; každý fetch má token — starší odpovědi se zahodí (race protection).
- **Klávesnice:**
  - `↓`/`↑` navigace s wrap-around
  - `Enter` vybírá aktivní položku
  - `Escape` zavírá dropdown
  - `Tab` zavře dropdown a **nechá default chování proběhnout** (`preventDefault` se nevolá) — fokus přejde na další pole formuláře. Položky dropdownu mají `tabindex="-1"`, takže do nich Tab nemůže zabloudit.
  - `Backspace` na prázdném inputu při nastavené hodnotě clearuje.
- **`×` tlačítko:** clear (jen pokud `hasValue && !disabled`, `tabindex="-1"`).
- **Klik mimo:** zavírá dropdown.
- **Klik na položku:** `onmousedown` + `preventDefault` — výběr proběhne před tím, než input ztratí fokus.
- **Disabled state:** input readonly, klávesnice/klik neotevírají dropdown, žádný `×` button.
- **States v dropdownu:** loading (`Načítám…`), error (`Chyba načítání`), empty (`Žádné výsledky`), nebo seznam položek (primary tučný, secondary menším fontem).

### Drilldown ve form komponentách

`FormEditor` drží state `dataResolved` (map column → `{id, primary, secondary}`), propaguje přes `FormTab → FormSection → FormColumn → FormElement` k jednotlivým `LookupInput` instancím. Callback `onResolveChange(column, item)` při výběru / clear aktualizuje keš:

- **Load (meta response):** `dataResolved = res.data.dataResolved ?? {}` — kompletní nahrazení.
- **Recalculate response:** `dataResolved = res.data.dataResolved ?? {}` — **replace**, ne merge. Server vrací autoritativní obraz pro všechna lookup pole v aktuálním form-state; klíče chybějící v response znamenají, že dané pole je null (resp. lookup neresolvoval) a display popis musí v UI zmizet. Bez tohoto by cascade reset (změna partnera → vynulování `partner_address`) zůstal v inputu — hodnota null, ale starý `resolved.primary` v keši.
- **User select:** `dataResolved = { ...dataResolved, [column]: item }` — okamžitý update z odpovědi `LookupInput`.
- **Save response:** explicitně neaktualizuje, ale `handleSave` volá `loadForm(...)`, který načte fresh `dataResolved` ze serveru.

### Edit a Create přes lookup

Lookup pole podporuje inline **edit** vybrané hodnoty a **create** úplně nového záznamu — obojí přes vnořený `FormDialog` z `LookupInput.svelte`. Opt-in přes flagy v lookup definici.

**Wire flagy** (součást `lookup` objektu, snake_case na drátě, camelCase v JSONC/PHP builderu):

| Flag | Default | Co dělá |
|------|---------|--------|
| `edit_form` / `editForm` | `false` | Zapne ikonu tužky vedle `×` u vyplněného pole. Klik otevře `FormDialog` s `recordId = value`. |
| `create_form` / `createForm` | `false` | Zapne tlačítko „+ Vytvořit nový záznam“ v patce dropdownu. Klik otevře `FormDialog` bez `recordId`. |
| `edit_triggers` / `editTriggers` | `false` | Po úspěšném save v **edit** modalu volá `onchange?.()` v rodiči (triggers recalculate). Default vypnuto, viz sémantika níže. |

**PHP builder API:**

```php
->lookup('partner',
    table: 'base_persons_persons',
    placeholder: 'Hledat partnera…',
    triggers: 'reload',
    editForm: true,
    createForm: true,
    // editTriggers nezapínáme — edit partnera nemá měnit řádek
)

->lookup('item',
    table: 'economy_items',
    placeholder: 'Hledat položku…',
    triggers: 'reload',
    editForm: true,
    createForm: true,
    editTriggers: true,  // ← položka = řádek, edit propisuje cenu/popis/jednotku
)
```

**Sémantika edit vs create:**

Obě akce sdílí pipeline v `LookupInput.handleSubDialogSaved`:

1. Přečíst `newId` z `record.id ?? record.data.id`.
2. **Edit (value se nemění, jen detaily):** refetch `/_ui/lookup/{table}/resolve?ids={newId}` → aktualizovat `resolved` → `onResolveChange`. Pokud `lookup.edit_triggers === true`, ještě zavolat `onchange?.()` (= recalculate v rodiči).
3. **Create (nové ID):** `value = newId` → refetch resolve → `onResolveChange` → vždy `onchange?.()`. Mode se přepne na 'edit' a `subDialogRecordId` na `newId`, aby případný další save v té samé modal session nešel cestou create.

Klíčové pro vyhodnocení `edit_triggers`:

- **Bez flagu (Partner):** edit detailů partnera jen aktualizuje display popis v inputu. Recalculate v rodiči se nevolá, takže `DocsHeadsForm::recalculate('partner')` neběží → cascade reset adresy/banky neproběhne. Správně: uživatel editoval partnera, neměnil ho.
- **S flagem (Item):** edit položky triggerne recalculate v rodiči → `DocRowsForm::recalculate('item')` přepiše `description`, `unit_price`, `unit` z aktualizovaných dat položky. Správně: položka určuje řádek.

**LookupInput NEzavírá modal po `onSaved`.** Modal se zavře až přes `onClose` — to znamená transition s `closeForm: 1` (`FormEditor` zavolá `onClose({force: true})`), `×`, Esc nebo overlay click. Tj. po prostém **Uložit** nebo po **Opravit** (40 → 80 s `closeForm: 0`) zůstává vnořený modal otevřený — stejně jako u primárních formulářů otevřených z vieweru. `onSaved` callback běží na pozadí: re-resolvuje display popis a (pokud `edit_triggers`) triggerne recalculate.

**Vnořený `FormDialog` v `LookupInput`:** přímý import (stejný vzor jako `FormSubTable.svelte`). Cyklická závislost `FormDialog → FormEditor → … → LookupInput → FormDialog` Vite zvládá, protože komponenta se instantuje až runtime. Modal-stack depth shrink v `Modal.svelte` (viz kap. 9) automaticky vykreslí vnořený modal o 30 px užší/nižší na každé straně, takže rodič vykřukuje a uživatel vidí hierarchii.

---

## 23. Polymorfní dispatch formulářů přes `typeColumn`

Pro tabulky, kde jeden physický řádek může reprezentovat víc logických typů (typicky `docs_core_heads` s `doc_type`), `FormRegistry` podporuje per-table polymorfismus — jedna tabulka, N PHP tříd, dispatch podle hodnoty diskriminačního sloupce. Mechanismus zrcadlí `DocumentRegistry::getDocument()` 1:1 (typeColumn + `classes` map + `defaultClass`).

### Kdy použít

- **Polymorfní zápis `{table, typeColumn, classes, defaultClass}`** — tabulka má diskriminační sloupec, jednotlivé hodnoty mají různé chování formuláře (titulky, sekce, validace).
- **Prostý zápis `{table, class}`** — jeden typ, jedna třída. Zůstává plně podporovaný a doporučený pro většinu tabulek (Osoby, Položky, Číselné řady, …). Nemá smysl ho zbytečně rozkládat.

### Registrace v `module.jsonc`

Vzor pro per-typ rodinu nad `docs_core_heads`:

```jsonc
// docs.core — vlastní tabulky + default
"forms": [
    {
        "table": "docs_core_heads",
        "typeColumn": "doc_type",
        "defaultClass": "Shipard\\Module\\Docs\\Core\\DocsHeadsForm"
    }
]

// docs.invoicesOut — per-typ subclass
"forms": [
    {
        "table": "docs_core_heads",
        "typeColumn": "doc_type",
        "classes": {
            "invno": "Shipard\\Module\\Docs\\InvoicesOut\\IssuedInvoiceForm"
        }
    }
]

// docs.invoicesIn — per-typ subclass
"forms": [
    {
        "table": "docs_core_heads",
        "typeColumn": "doc_type",
        "classes": {
            "invni": "Shipard\\Module\\Docs\\InvoicesIn\\ReceivedInvoiceForm"
        }
    }
]
```

### Dispatch pravidla

`FormRegistry::createForm($table, $data, $db, $config)` vrací konkrétní instanci `TableForm`:

- Pokud má registrace `typeColumn`: vyhodnotí `$data[$typeColumn]`, výsledek hledá v mapě `classes`. Pokud klíč neexistuje → fallback na `defaultClass`. Pokud neexistuje ani `defaultClass` → `null`.
- Pokud má registrace prostý `class`: vrátí instanci té třídy bez ohledu na `$data`.
- Tabulka bez registrace v `FormRegistry` → fallback na JSONC formulář (`forms/{table}.jsonc`) nebo `AutoFormBuilder` (viz kap. 12).

### `$data` pro nový záznam

Per-typ viewer poskytuje `getNewRecordDefaults()` (např. `{doc_type: 'invno'}`). `FormController` to spojí s column defaults a předá do `createForm($table, $data, ...)`. Dispatch tedy funguje i pro nový záznam otevřený z per-typ vieweru.

Pro nový záznam otevřený z **generického vieweru** (bez hintu `doc_type`) je `$data[$typeColumn]` prázdné → dispatch padá na `defaultClass`. ✓

### Slévání napříč moduly

`FormLoader::mergeForms()` (paralela `DocumentLoader::mergeDocumentClasses()`) slévá per-table:

- `typeColumn` musí být shodný (jinak `LogicException`)
- `classes` mapy se mergují; kolize klíčů s **různými** hodnotami → `LogicException`, **identická** hodnota → idempotentní průchod
- `defaultClass` first-wins (typicky ho registruje base modul, např. `docs.core`)
- `id` first-wins (pro `subtable.form_id` referenci)
- Prostý `class` se ignoruje, pokud cílová registrace má `typeColumn` (smíšený zápis nedává smysl)

### Hierarchie tříd — doporučený vzor

```
TableForm (abstract, core)
    └── DocsHeadsFormBase (abstract, docs.core)
            ├── DocsHeadsForm            (docs.core)        — defaultClass
            ├── ReceivedInvoiceForm      (docs.invoicesIn)  — invni
            ├── IssuedInvoiceFormBase    (abstract, docs.core) — sdílený layout vydaných dokladů
            │       ├── IssuedInvoiceForm (docs.invoicesOut)  — invno
            │       └── ProformaOutForm   (docs.proformasOut) — invpo (#79 D1)
            └── CashDeskFormBase         (abstract, docs.core) — pokladní doklad, prodejka
```

Společná logika žije v base třídě (build tabů, recalculate, options resolvery, HTML renderery). Subclassy přepisují jen tam, kde se chování má lišit — typicky `getFormTitle()` / `getNewFormTitle()` a header-info hooky. Rodina typů se stejným layoutem (FVB + zálohová faktura vydaná) má abstraktní mezistupeň v `docs.core` (`IssuedInvoiceFormBase`, vzor `CashDeskFormBase`), ne kopii `buildHeaderTab()` per modul. Nedaňový typ (`docTypes[].tax_document: false`) řídí base přes `DocsHeadsFormBase::isTaxDocument()` — skrývá DUZP/DPPD, selecty období DPH a `cs_mode`, nepředvyplňuje DUZP; per-typ třída o tom nic neví.

```php
abstract class DocsHeadsFormBase extends TableForm
{
    protected function getFormTitle(): string    { return 'Doklad'; }
    protected function getNewFormTitle(): string { return 'Nový doklad'; }
    // ... shared build* / recalculate / resolve* metody jsou `protected`,
    // aby je subclassy mohly override-ovat
}

class IssuedInvoiceForm extends IssuedInvoiceFormBase
{
    protected function getFormTitle(): string    { return 'Faktura vydaná'; }
    protected function getNewFormTitle(): string { return 'Nová faktura vydaná'; }
}
```

### Vztah k `DocumentRegistry`

Formulářová polymorfizace zrcadlí `DocumentRegistry` 1:1. Když přidáváš nový typ dokladu (proforma faktura, dobropis, bankovní výpis, pokladní doklad, …), typicky přidáváš tři věci společně:

- `documentClasses` entry pro per-typ `Document` třídu (validace, beforeSave)
- `forms` entry pro per-typ `Form` třídu (UI overrides)
- `viewers` entry pro per-typ filtered viewer s `getNewRecordDefaults()`

Symetrie není povinná, ale pro nový typ dokladu je defaultní cesta.

### Invariant: `doc_type` per-záznam fixní po vzniku

`recalculate` v `FormController` předává `$data` do `createForm($table, $data, ...)`, ale `$data` obsahuje aktuální stav formuláře z requestu. Teoreticky by uživatel změnou `doc_type` za běhu mohl forcenout změnu `TableForm` mid-flight. V praxi je `doc_type` v UI **neměnitelné po vytvoření záznamu** (řídí ho výběr číselné řady při kliku „Přidat" v per-typ vieweru). Implicitní invariant: hodnota diskriminačního sloupce je per-záznam fixní.

### Backwards compat

Všechny existující registrace `{table, class}` (`PersonsForm`, `NumberSeriesForm`, `ItemsForm`, JSONC `DocRowsForm`, …) fungují beze změny. Polymorfní mechanismus se týká výhradně PHP `TableForm` subclassů — pro JSONC formuláře (`forms/{table}.jsonc`) typeColumn dispatch neexistuje (deklarativní JSONC nemá motiv per-typ varianty).

### Hook `buildExtraTabs()` — per-typ rozšíření tabů

`DocsHeadsFormBase::buildFormDefinition()` nabízí rozšiřující hook `buildExtraTabs(array $data, bool $isNew): array`, který subclassy mohou přepisovat a vracet extra taby — ty se přidají **na konec** formuláře, za Přílohy. Default v base třídě vrací prázdné pole.

Vzor použití — `ReceivedInvoiceForm` (FPB) přidává tab „Nastavení“ s poli, která se u přijatých faktur nastavují zřídka (registrace DPH, zaokrouhlení DPH, náš bankovní účet, domácí měna readOnly, konstantní symbol):

```php
class ReceivedInvoiceForm extends DocsHeadsFormBase
{
    protected function buildExtraTabs(array $data, bool $isNew): array
    {
        return [$this->buildSettingsTab($data)];
    }

    protected function buildSettingsTab(array $data): FormTab
    {
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $hasVat = $vatMode !== 0;
        $docCurrency = strtolower((string) ($data['doc_currency'] ?? 'czk'));

        return $this->tab('settings', 'Nastavení')
            ->section(title: 'DPH', hidden: !$hasVat)
                ->col()
                    ->select('vat_registration',
                        options: $this->resolveVatRegistrationOptions(),
                        triggers: 'reload',
                    )
                    ->select('vat_rounding_mode',
                        options: $this->resolveCfgItemOptions('docs.core.vatRoundingModes'),
                        hidden: !$hasVat,
                    )
            ->section(title: 'Bankovní spojení')
                ->col()
                    ->select('bank_account',
                        options: $this->resolveBankAccountOptions($docCurrency),
                    )
            ->section(title: 'Měna')
                ->col()
                    ->input('home_currency', readOnly: true)
            ->section(title: 'Ostatní')
                ->col()
                    ->input('constant_symbol')
            ->build();
    }
}
```

Dopořučení:

- Tab Nastavení je umístěn za Přílohami stejně jako u `PersonsForm` — udržuje konzistentní UX napříč aplikací.
- Hlavička FPB (`buildHeaderTab()`) je řazená podle toku práce s došlou fakturou: levý sloupec partner → adresa → způsob platby (+ pokladna při hotovosti) → bankovní účet partnera / IBAN → variabilní a specifický symbol → datumy → číslo dokladu od partnera; pravý sloupec režim DPH, měna a kurz, zaokrouhlení částky, období plnění. Co se mění zřídka (zaokrouhlení DPH, konstantní symbol), patří do tabu Nastavení, ne do hlavičky.
- Hook má stejnou signaturu jako `buildHeaderTab()` (`array $data, bool $isNew`), použitelnou pro větvení podle stavu formuláře (např. skrýt sekce „DPH“ když `vat_mode === 0`).
- Pro extra **subtable** nebo **attachments** taby použij stejný hook — vrací se z něj `list<FormTab>`, který může obsahovat i `$this->subtableTab(...)` nebo `$this->attachmentsTab(...)`.
- `IssuedInvoiceFormBase` (FVB `IssuedInvoiceForm` i FVZ `ProformaOutForm`) hook používá taky: sekce „Měna“ s `home_currency` (readOnly) a sekce „Ostatní“ s `bank_account`, `vat_registration`, `vat_calc_source`, `vat_recap_source`, `total_rounding_mode`, `vat_rounding_mode`, `cs_mode` a `constant_symbol`. Hlavička FVB je užší než u FPB: vlevo odběratel → adresa → způsob platby (+ pokladna při hotovosti) → datumy (vystavení, splatnost, účetní, DUZP); vpravo režim DPH, místo plnění, měna a kurz, variabilní a specifický symbol, období plnění. Bankovní účet odběratele a DPPD se na FVB nezadávají — DPPD odvodí `DocDocument` z DUZP. `bank_account` je přesto při Potvrdit povinný (`IssuedInvoiceDocument::validate()`) a `vat_registration` při `vat_mode != 0`; validační chyba se zobrazí na poli v tabu Nastavení. Strukturu má smysl držet stejnou jako u FPB — další pole (např. připomínkový režim, AI checks) se přidávají jako sekce navrch.
- Generický `DocsHeadsForm` hook nepřepisuje, nemění default `[]`. Může ho zapnout kdykoli bez úpravy base třídy.

## 24. Editovatelná sensitive pole (opt-in whitelist)

Sloupce se `"sensitive": true` v definici tabulky standardně **nelze zapsat
přes generické API** — `TableAccessGuard::rejectSensitiveInput()` vrací
`400 SENSITIVE_COLUMN` v CRUD i form save cestě a `stripSensitive()` je
odstraňuje z každé odpovědi (data, form meta, list).

Form třída může editaci konkrétních sensitive sloupců **explicitně povolit**:

```php
class DataSourcesForm extends TableForm
{
    public function getEditableSensitiveColumns(): array
    {
        return ['mail_token'];
    }
}
```

`FormController::save()` si whitelist vyžádá od registrované form třídy a
předá ho guardu — jen vyjmenované sloupce projdou, ostatní sensitive sloupce
zůstávají blokované. CRUD cesty volají guard bez whitelistu, tam platí plný
zákaz vždy. Bez registrované PHP form třídy (JSONC/Auto formulář) whitelist
neexistuje.

Konvence pro takové pole (viz CLAUDE.md → Citlivá data):

- input startuje **prázdný** — data ho nikdy neobsahují (`stripSensitive`),
- `placeholder: '●●●●●● (zadat pro změnu)'`, typicky `inputType: 'password'`,
- **prázdný submit hodnotu nemění** — Document třída v `beforeSave()`
  prázdnou hodnotu unsetne (vzor `HostingDataSourceDocument`),
- šifrování (`encrypted_text` + `DsSecretCipher`) zůstává odpovědností
  Document třídy, guard ani form s hodnotou nic nedělají.

První uživatel: `mail_token` v `DataSourcesForm` (hosting, ruční backfill
mail tokenů — `tasks/hosting-04-mail-router.md`).

---

## 25. Strukturovaná pole — virtuální sloupce

Sloupec typu `json` s atributem `schema` (issue #74,
[structured-fields.md](structured-fields.md)) se needituje jako JSON: server
ho **zploští na virtuální sloupce** `<sloupec>.<pole>` a formulář nad nimi
staví běžné elementy.

**Kontrakt.** Pro klienta je `filing_profile.typ_ds` obyčejný `column`:

- `data` v odpovědích `meta`, `save` i `recalculate` nese ploché klíče,
  surový sloupec v nich **není**;
- `formData[column]` funguje bez úprav — tečka v klíči je jen znak
  (`FormElement.svelte` klíčuje bracket přístupem, `sanitizeFormData` jde
  přes plochý `Object.entries`);
- validační chyba přijde s `field = "filing_profile.typ_ds"` a klient ji
  přiřadí k inputu přes `buildElementMap()` jako každou jinou (kap. 8);
- `triggers: reload` na virtuálním sloupci funguje také bez úprav.

Oddělovač je **tečka** — stejná notace jako u chyb v řádcích
(`rows.0.unit_price`). Rozhodnutí a jeho ověření napříč klientem jsou
v docblocku `StructuredSchema::PATH_SEPARATOR`; `id` polí schématu proto
tečku nesmí obsahovat.

**Serverová strana.** `FormController` plošťuje na výstupu, zpět je skládá
`TableGateway` — controller do hodnoty nemluví. `filterWritableFields()`
propouští virtuální sloupce jen u sloupců se schématem (a ne u `system`
sloupců); neznámá pole schématu gateway zahodí.

**Kde se elementy berou.** PHP form si je vyžádá helperem, který emituje
`separator` per skupinu schématu a `input`/`select` per pole:

```php
if ($this->hasStructuredColumn('filing_profile')) {
    $tabs[] = $this->tab('filing', 'Podací údaje')
        ->section()->col()
        ->addElements($this->structuredFieldElements('filing_profile', $data))
        ->build();
}
```

`hasStructuredColumn()` je gate pro sloupce, které do tabulky přináší
extension jiného modulu — bez toho modulu se záložka nekreslí.
`TabBuilder::addElements()` vloží hotové `FormElement[]` do otevřeného
sloupce (separátory respektují auto-hide, kap. 11). Tabulky bez form třídy
dostanou sekci automaticky z `AutoFormBuilder`; **deklarativní JSONC formy
strukturovaná pole neumí**.

Vzor: `VatRegistrationsForm` (záložka Podací údaje na registraci k DPH).

---

## 26. Sloupce editovatelné v read-only stavu dokumentu

Stav s `"readOnly": 1` v docStates cfgItem (Podáno, V pořádku, …) zamyká
celý formulář: klient kreslí inputy jako `disabled` a bez tlačítka Uložit,
`FormController::save()` uložení existujícího záznamu odmítne
(`422 DOCUMENT_READONLY`). Některé údaje ale ke zmrazenému záznamu smí
**přibýt** bez „Opravit" — poznámka k podanému podání DPH, správce daně na
potvrzené registraci. Form třída je pustí opt-in whitelistem:

```php
class VatRegistrationsForm extends TableForm
{
    public function getReadOnlyEditableColumns(): array
    {
        return ['tax_office_person'];
    }
}
```

Co se stane:

- **Meta / recalculate**: `doc_states` dostane `editable_columns`
  (`buildDocStatesInfo`), ale jen když je stav read-only **a** záznam
  nedrží zámek (`documentLockProviders`, `docs/document-system.md`
  §16) — zámek je silnější než whitelist.
- **Klient** (`FormEditor`): inputy vyjmenovaných sloupců zůstávají
  aktivní (`unlockedColumns` propadá `FormTab → FormSection → FormColumn →
  FormElement`), `FormStateBar` ukáže Uložit, dirty stav sleduje jen tyto
  sloupce a **uložení pošle jen je**. Přechod stavu existujícího záznamu
  (Opravit, Zrušit, …) dělá před samotným přechodem ještě uložení dat —
  v read-only stavu jen odemčených sloupců a jen když jsou dirty, jinak
  se uložení přeskočí (celý formulář by server odmítl `DOCUMENT_READONLY`
  a tlačítko Opravit by nefungovalo). Externí prop `readOnly` (prohlížení
  řádku sub-tabulky) whitelist přebíjí — nic se neodemyká.
- **Server** (`FormController::save()` → `guardReadOnlyUpdate`): payload
  update na read-only záznam smí obsahovat jen whitelistované sloupce
  (+ `id`, `modified`), jinak `DOCUMENT_READONLY`. Whitelist si controller
  vyžádá od registrované form třídy; bez ní (JSONC/Auto formulář) je
  prázdný a read-only záznam nejde přes formulář změnit vůbec.

Důsledek pro Document třídu: dostane **částečné uložení** (jen
whitelistované sloupce + `id`; `docState` doplní gateway z uloženého
řádku). Povinná pole tedy nesmí validovat jen z payloadu — merge s uloženým
řádkem, vzor `FilingDocument` / `VatRegistrationDocument`
(`$effective = array_merge($current, $data)`). Vlastní business pravidla
(u podání `FROZEN_COLUMNS`) zůstávají v Documentu; whitelist formu je jen
vstupenka přes controller, ne oprávnění cokoli měnit.

Generické REST CRUD (`PATCH /api/v1/{table}/{id}`) whitelist nezná a
read-only stav odmítá vždy; strojové zápisy do read-only záznamu mají
vlastní endpoint (např. `POST /_vat/registration-tax-office`), který jde
rovnou přes `TableGateway`.

První uživatelé: `note` v `FilingsForm` (podání DPH) a `tax_office_person`
ve `VatRegistrationsForm` (#55 D30).
