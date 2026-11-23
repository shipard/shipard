# Edit Forms — Cookbook

Krátké, samostatné vzory pro psaní editačních formulářů. Každý recept ukazuje
jednu věc — copy-paste, uprav, hotovo.

Pro plnou referenci (doc states, lookup, polymorfní dispatch, recalculate flow,
header info, API endpointy) viz [`edit-forms.md`](./edit-forms.md).

---

## Mentální model

```
tab → section → column → element
                       ↳ inline → element
```

- **tab** — záložka v horní liště formuláře (typy: `fields` / `subtable` / `attachments`)
- **section** — vizuální karta s pozadím a volitelným titulkem; v tabu typu `fields`
- **column** — vertikální dráha uvnitř sekce; sekce mívá 1–3 sloupce vedle sebe
- **element** — konkrétní pole (`input`, `select`, `date`, `separator`, …) uvnitř sloupce
- **inline** — skupina elementů v jedné řádce uvnitř sloupce (víc inputů vedle sebe)

---

## Kdy JSONC, kdy PHP?

| Použij JSONC | Použij PHP `TableForm` |
|---|---|
| Layout je statický, žádné podmínky | Pole se mění podle hodnoty jiného pole |
| Žádná business logika | Potřebuješ `recalculate()`, options z DB, `buildHeaderInfo()` |
| Typický číselník nebo sub-záznam | Hlavní entita s rozvětvenou logikou |
| Soubor: `modules/{skupina}/{modul}/forms/{table}.jsonc` | Třída + registrace v `module.jsonc` `forms[]` |

Když nestačí JSONC, nepřepisuj — vytvoř PHP třídu. `JsoncFormLoader` aktivně odmítá staré nebo nekonzistentní zápisy s konkrétní chybovou hláškou.

---

# JSONC recepty

## 1. Minimální formulář

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
                    "columns": [
                        {
                            "elements": [
                                {"type": "input", "column": "name", "required": true},
                                {"type": "input", "column": "email", "inputType": "email"}
                            ]
                        }
                    ]
                }
            ]
        }
    ]
}
```

Uložení: `modules/{skupina}/{modul}/forms/{table}.jsonc`. Labely se vezmou z `TableDefinition` (sloupec `name` → text z `name` v JSONC tabulky). Když je tab jen jeden, tab bar se nezobrazí.

---

## 2. Sekce s titulkem

```jsonc
"sections": [
    {
        "title": "Adresát",
        "columns": [
            {
                "elements": [
                    {"type": "select", "column": "address_type"},
                    {"type": "input", "column": "name"}
                ]
            }
        ]
    }
]
```

Sekce = karta s pozadím a jemnou hranou. Titulek se vykreslí malými verzálkami vlevo nahoře. Vynechaný `title` (nebo `null`) → bez titulku, čistá karta.

---

## 3. Více sekcí pod sebou

```jsonc
"sections": [
    {"title": "Adresát", "columns": [ /* … */ ]},
    {"title": "Adresa",  "columns": [ /* … */ ]},
    {"title": "Platnost","columns": [ /* … */ ]}
]
```

Sekce jsou vždy stackované vertikálně. Žádné „sekce vedle sebe" — pořadí v poli = pořadí na obrazovce.

---

## 4. Dva sloupce vedle sebe

```jsonc
{
    "title": "Adresa",
    "columns": [
        {
            "elements": [
                {"type": "input", "column": "street"},
                {"type": "input", "column": "house_number"}
            ]
        },
        {
            "elements": [
                {"type": "input", "column": "city"},
                {"type": "input", "column": "zip"}
            ]
        }
    ]
}
```

Dvě položky v `columns` → dva vertikální sloupce o stejné šířce (50/50). Na úzkém viewportu (<700 px) se automaticky složí pod sebe. Stejně tak funguje 3 a více sloupců.

---

## 5. Inline — víc polí v jedné řádce

```jsonc
{
    "type": "inline",
    "elements": [
        {"type": "input", "column": "house_number"},
        {"type": "input", "column": "orientation_number"}
    ]
}
```

Pro spárované hodnoty: popisné/orientační číslo, DUZP/DPPD, šířka/výška, datumy platnosti. Label prvního pole slouží jako velký label řádky vlevo, ostatní pole dostanou mini-label vedle inputu.

Uvnitř `inline.elements` jen `input` a `select`. **`lookup` ne** (vyhodí `InvalidArgumentException`).

---

## 6. Separator

```jsonc
{"type": "separator"},
{"type": "separator", "label": "Validity"}
```

Vodorovná čára přes celou šířku sloupce; volitelný text uprostřed. Když jsou všechna pole za separátorem ve stejném sloupci skrytá, separátor se automaticky skryje. Nemusíš to řešit ručně.

---

## 7. Typy inputů

UI komponenta se odvozuje z DB typu sloupce. Override přes `inputType`:

```jsonc
{"type": "input", "column": "email",      "inputType": "email"},
{"type": "input", "column": "phone",      "inputType": "tel"},
{"type": "input", "column": "web",        "inputType": "url"},
{"type": "input", "column": "valid_from", "inputType": "date"},
{"type": "input", "column": "valid_to",   "inputType": "date"},
{"type": "input", "column": "note",       "inputType": "textarea"},
{"type": "input", "column": "order_pos",  "inputType": "number"},
{"type": "input", "column": "is_active",  "inputType": "checkbox"}
```

Povolené hodnoty: `text`, `email`, `tel`, `url`, `password`, `number`, `date`, `datetime`, `time`, `textarea`, `checkbox`. Neplatná hodnota → `InvalidArgumentException` při loadu.

Pro enumy a malé číselníky:

```jsonc
{"type": "select", "column": "address_type"}
```

`options` se auto-resolují z `cfgItem` sloupce — nepíšeš je.

---

## 8. Vlastnosti polí

```jsonc
{
    "type": "input",
    "column": "name",
    "required": true,
    "readOnly": false,
    "hidden": false,
    "placeholder": "Jan Novák",
    "hint": "Jméno a příjmení"
}
```

`required` přidá hvězdičku k labelu (validace běží i na serveru). Bez explicitní
hodnoty se odvozuje ze schématu: u `select` `!nullable` sloupce (prázdná možnost
„nevybráno" tak existuje jen u nullable polí), u ostatních typů `!nullable &&
default === null`. `hidden` schová pole CSS-displej-none (zůstává v DOM, posílá se do save).

---

## 9. Více tabů

```jsonc
"tabs": [
    {"id": "basic",   "label": "Základní údaje", "sections": [ /* … */ ]},
    {"id": "detail",  "label": "Detail",         "sections": [ /* … */ ]},
    {"id": "history", "label": "Historie",       "sections": [ /* … */ ]}
]
```

Pořadí v poli = pořadí v tab baru. Klient otevírá první tab; když validace selže na neaktivním tabu, automaticky přepne na ten s chybou.

---

## 10. Subtable tab — child tabulka

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

`formId` ukazuje na `id` v registraci druhého formuláře v `module.jsonc` → `forms[]`. Vykreslí toolbar (Přidat / Upravit / Smazat) a tabulku řádků; klik na řádek otevře vnořený `FormDialog`.

Pro nový záznam (rodič nemá ID) je subtable tab disabled s hláškou „Nejprve uložte záznam". Po uložení rodiče se odemkne.

---

## 11. Attachments tab

```jsonc
{
    "id": "attachments",
    "label": "Přílohy",
    "type": "attachments",
    "tableId": 110
}
```

`tableId` je číselný `tableId` z JSONC definice tabulky (`base_persons_persons.jsonc`). Panel pro přidávání/mazání souborů přes `core_attachments`.

---

## 12. Vícejazyčné labely

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
                {
                    "title": "Validity",
                    "title:cs": "Platnost",
                    "title:en": "Validity",
                    "columns": [ /* … */ ]
                }
            ]
        }
    ]
}
```

Holé pole (bez `:lang` suffixu) je **povinný fallback** — server ho použije, když požadovaný jazyk chybí. Podporují to: `title`, `titleNew`, `tabs[].label`, `sections[].title`, `separator.label`.

Labely a placeholders u inputů se typicky berou z TableDefinition, takže tam vícejazyčnost dělat nemusíš (řeší ji `name:cs` / `name:en` ve sloupcích tabulky).

---

## 13. Velikost modalu

Velikost modalu se neřídí per-formulář. Všechny top-level modaly mají 1200×900, vnořené se automaticky zmenšují přes depth-shrink (viz `docs/edit-forms.md` kap. 9).

---

# PHP recepty

## 14. Skelet `TableForm` subclass

```php
<?php
declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

class PersonsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $basic = $this->tab('basic', 'Základní údaje')
            ->section()
                ->col()
                    ->input('full_name', required: true)
                    ->input('email', inputType: 'email')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Osoba',
            titleNew: 'Nová osoba',
            tabs: [$basic],
        );
    }
}
```

Registrace v `module.jsonc`:

```jsonc
"forms": [
    {
        "table": "base_persons_persons",
        "class": "Shipard\\Module\\Base\\Persons\\PersonsForm"
    }
]
```

---

## 15. TabBuilder — pořadí scopů

Builder má **třípatrový stavový stroj**: `tab → section → col → [inline] → elements`. Skipnutí úrovně → `LogicException`.

```php
$tab = $this->tab('basic', 'Label')
    ->section()                  // otevře sekci bez titulku
        ->col()                  // otevře sloupec
            ->input('name')      // element jde do sloupce
    ->section('Druhá sekce')     // ukončí předchozí, otevře novou
        ->col()
            ->input('other')
    ->build();                   // auto-close otevřených scopů
```

Nemusíš ručně zavírat sekci/sloupec — nová `section()` / `col()` ukončí předchozí. Jen `inline()` se musí explicitně zavřít přes `endInline()` (jinak další element půjde do něj).

---

## 16. Více sloupců v sekci

```php
->section(title: 'Adresa')
    ->col()                              // levý sloupec
        ->input('street')
        ->input('house_number')
    ->col()                              // pravý sloupec
        ->input('city')
        ->input('zip')
```

---

## 17. Inline v PHP

```php
->section()
    ->col()
        ->inline()
            ->date('date_tax', label: 'DUZP')
            ->date('date_tax_duty', label: 'DPPD')
        ->endInline()
        ->input('note')                  // už mimo inline, vlastní řádka
```

Shortcut když nepotřebuješ vlastní labely:

```php
->col()->inlineFields('house_number', 'orientation_number')
```

---

## 18. Dedikované element metody

| Metoda | Pro DB typ |
|---|---|
| `input(col, inputType: ...)` | char, varchar — `inputType: text/email/tel/url/password` |
| `textarea(col)` | text, longtext |
| `date(col)`, `datetime(col)`, `time(col)` | date, datetime, time |
| `number(col)` | int, bigint, numeric, float |
| `checkbox(col)` | boolean |
| `select(col, options: ...)` | enumInt, enumString |
| `multiselect(col, options: ...)` | json — výběr více hodnot z cfgItem číselníku, hodnota = list; Document dělá `json_encode` v `beforeSave()` |
| `separator(label: ...)` | — |
| `lookup(col, table: ...)` | FK na velkou tabulku (viz reference kap. 22) |
| `content(blocks: [...])`, `component(name: ...)` | obsah jen ke čtení z bloků detailu (nápověda `hint`, text, hláška, vlastnosti — texty přes `formText()`) / pojmenovaná Svelte komponenta. HTML se neposílá — `html()` zanikl (#150 D82) |

```php
->col()
    ->input('full_name', required: true)
    ->select('person_type', options: $opts, triggers: 'reload')
    ->date('birth_date')
    ->number('payment_term_days')
    ->checkbox('is_own')
    ->textarea('note')
    ->separator(label: 'Adresa')
```

Společné pojmenované parametry: `label`, `required`, `readOnly`, `hidden`, `placeholder`, `hint`, `triggers`.

---

## 19. Podmíněné skrytí sekce

```php
$personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));
$isPerson   = $personType === PersonType::Person;
$isCompany  = $personType === PersonType::Company;

$tab = $this->tab('basic', 'Základní údaje')
    ->section()
        ->col()
            ->select('person_type', options: $opts, triggers: 'reload', required: true)
            ->input('full_name', required: $isCompany, hidden: $isPerson)

    ->section(title: 'Identifikace firmy', hidden: $isPerson)
        ->col()
            ->inline()
                ->input('company_id')
                ->input('tax_id')
            ->endInline()

    ->section(title: 'Jméno', hidden: $isCompany)
        ->col()
            ->input('first_name', required: $isPerson)
            ->input('last_name', required: $isPerson)
    ->build();
```

Pro reaktivní změnu z klienta:

1. řídící pole má `triggers: 'reload'`
2. implementuj `recalculate(string $changedColumn, array $data): RecalculateResult` v PHP třídě, která rebuildne FormDefinition s novými `hidden` flagy

Detaily v referenci kap. 7.

---

## 20. Subtable a attachments helpery

```php
$contacts  = $this->subtableTab('contacts', 'Kontakty',
    'base_persons_contacts', 'person', 'base.persons.contacts');

$addresses = $this->subtableTab('addresses', 'Adresy',
    'base_persons_addresses', 'person', 'base.persons.addresses');

$attachments = $this->attachmentsTab();   // tableId z aktuální TableDefinition

return new FormDefinition(
    table: $this->table,
    title: 'Osoba',
    titleNew: 'Nová osoba',
    tabs: [$basic, $contacts, $addresses, $attachments, $settings],
);
```

Signatura subtable helperu:

```php
$this->subtableTab(
    string $id, string $label,
    string $table, string $foreignKey,
    ?string $formId = null, ?string $sort = null, ?string $icon = null,
): FormTab;
```

`formId` ukazuje na `id` v registraci formuláře child tabulky. Když je `null`, použije se výchozí formulář dle priority (PHP třída → JSONC → AutoFormBuilder).

---

## 21. Select v sub-formu filtrovaný hodnotou z hlavičky

Sub-form řádku (např. `docs_core_rows`) často potřebuje options závislé na
parent záznamu — třeba pohyb řádku (`operation`) povolený jen pro `doc_type`
hlavičky. Žádný extra endpoint není potřeba: sub-form má v datech FK na
hlavičku (`doc_head` z prefillu `defaults[doc_head]`), takže si kontext
načte server-side při každém buildu.

```php
public function buildFormDefinition(array $data, bool $isNew): FormDefinition
{
    $headContext = $this->loadHeadContext($data['doc_head'] ?? null);   // SELECT hlavičky
    $operationOptions = $this->buildOperationOptions($headContext);     // filtr + řazení dle cfgItem

    $tab = $this->tab('basic', 'Řádek')
        ->section()
            ->col()
                ->select('operation', options: $operationOptions, required: true)
    // ...
}
```

Vzor: `DocRowsForm::buildOperationOptions()` — filtruje cfgItem
`docs.core.rowOperations` podle `docTypes[{doc_type}]` a řadí dle
`docTypes[{doc_type}].order`.

**Default pro nový záznam odvozený z kontextu** se NEDĚLÁ mutací `$data`
v `buildFormDefinition` — ta ovlivní jen podmíněné renderování, do response
`data` se nepropíše. Override `applyNewRecordDefaults(array &$data)`
(hook `TableForm`), který FormController volá pro GET /meta bez id:

```php
public function applyNewRecordDefaults(array &$data): void
{
    if (!empty($data['operation'])) {
        return;
    }
    $options = $this->buildOperationOptions(
        $this->loadHeadContext($data['doc_head'] ?? null),
    );
    if ($options !== []) {
        $data['operation'] = $options[0]['value'];   // nejnižší order
    }
}
```

Hlavička dokladu (`DocsHeadsFormBase`) hook používá pro vše, co má uživatel
vidět předvyplněné hned po otevření modalu: `issue_date` = dnes,
`vat_registration` = první platná k datu (jen u dokladu s DPH),
`bank_account` = výchozí účet (jen typy, které účet nesou). Řádek dokladu
(`DocRowsForm`) stejnou cestou předvybírá pohyb, množství a `vat_code`
včetně dopočtu `vat_pct`, položka (`ItemsForm`) druh a jednotku.

**Výchozí hodnoty dokladu a jeho řádku nejsou v hooku** — hook je bere ze
společných tříd `Defaults\DocHeadDefaults` / `DocRowDefaults` (docs.core),
které volá i pokyn výměnného formátu (#166 D40, `docs/document-system.md`
§ 12c). Rozdíl mezi typy dokladu (účetní doklad bez DPH, pokladní doklad
Hotově) je atribut typu v `docs.core.docTypes`, ne podtřída formuláře.
Novou výchozí hodnotu dokladu přidej do společné třídy — jinak se formulář
a pokyn rozejdou a zastaví to `DocumentFormInstructionInvariantTest`.

Pozor na schéma defaulty: `FormController` je do `$data` vloží **před**
hookem, takže „když neprefillnuto" přes `isset()` nefunguje (`payment_method`
je vždy 1 ze schématu). Rozhoduj podle hodnoty (mimo povolené → default),
ne podle přítomnosti klíče. `vat_mode = 0` je platná hodnota — guardy přes
`isset`/`(int)`, nikdy `empty()`.

`applyClientDefaults()` v `buildFormDefinition` je naopak **jen pro
renderování**: běží nad kopií dat při každém buildu (GET meta i recalculate,
kde hook neběží) a řídí `hidden`/options. Proto se hodnoty, které ovlivňují
gating i to, co uživatel vidí (`vat_mode = 0` účetního dokladu,
`payment_method = 0` pokladního), vyskytují na obou místech — u dokladu je
obě berou z téhož pravidla (`DocHeadDefaults::vatMode()` /
`paymentMethod()`), jinde se duplikují, nepřesouvají. Default, který stojí
**jen** v `applyClientDefaults`, je mrtvý (issue #24 A).

---

# Časté chyby

- **`lookup` v `inline`** — vyhodí `InvalidArgumentException` v konstruktoru `FormElement`. Inline povoluje jen `input` a `select`. Pro lookup udělej vlastní řádku v sloupci.
- **JSONC zápis vs. wire formát** — v JSONC piš `camelCase` (`titleNew`, `inputType`, `foreignKey`, `formId`, `tableId`, `readOnly`). Loader převede na `snake_case` pro frontend.
- **Šířka labelu** — synchronizuje se per-sloupec (CSS Grid `fit-content(40%) minmax(0, 1fr)` — nejvýš 40 % sloupce, delší popisek se zalomí), **ne** napříč celou sekcí. Dva vedlejší sloupce v jedné sekci mohou mít různě široké label-dráhy. To je záměr.
- **`separator` `hidden`** — nenastavuj ručně. `autoHideSeparators` v `TabBuilder::build()` ho skryje, pokud jsou všechny elementy za ním ve stejném sloupci skryté.
- **Pořadí scopů PHP builderu** — `tab() → section() → col() → element` (nebo `inline()`). Skipnutí úrovně → `LogicException`. `endInline()` je jediný explicitní close, který musíš volat.
- **`subtable` a `attachments` jsou typy tabu, ne elementu** — v JSONC `"type": "subtable"` patří na úroveň tabu, nikoliv dovnitř `sections[].columns[].elements[]`. Loader starý zápis (`element.type: "subtable"`) odmítne s konkrétní hláškou.
- **AutoFormBuilder fallback** — když pro tabulku neexistuje ani PHP třída, ani JSONC, vygeneruje se default formulář z TableDefinition (jeden tab, jedna sekce, jeden sloupec, všechna ne-systémová pole). Hodí se pro hrubý prototyp; pro reálné použití napiš JSONC.

---

# Vzory v projektu

Reálné formuláře, ze kterých můžeš vycházet:

| Soubor | Co ukazuje |
|---|---|
| `modules/base/persons/forms/base_persons_contacts.jsonc` | nejjednodušší JSONC, jeden sloupec, separator |
| `modules/base/persons/forms/base_persons_addresses.jsonc` | sekce s titulky, dva sloupce, inline, druhý tab |
| `modules/base/persons/forms/base_persons_bank_accounts.jsonc` | sekce + inline pro datumy |
| `modules/base/persons/src/PersonsForm.php` | PHP `TableForm` s podmíněnými sekcemi, recalculate, header info, subtable taby |
