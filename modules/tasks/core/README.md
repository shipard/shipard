# Modul: Úkoly (tasks.core)

Modul přidává do aplikace top-level sekci „Úkoly" — sdílený to-do list
s prioritami, termínem a jednoduchým stavovým automatem. Viewer se chová
jako ostatní entity (Osoby, Faktury), editace probíhá v modálním formuláři.

## Závislosti

- `core.system`

## Tabulky

| Tabulka | Popis |
|---|---|
| `tasks_core_tasks` | Záznamy úkolů — titulek, popis, priorita, termín, autor |

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| `src/TaskDocument.php` | Validace (povinný `title` do 200 znaků, priority enum, formát `due_date`) |
| `src/TasksForm.php` | Editační formulář — `title`, `description`, `priority`, `due_date` |
| `src/TasksViewer.php` | Viewer s view-groups aktivní/archív/koš, JOIN na `core_system_users` pro autora, indikace po termínu |

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `tasks.core.docStatesTasks` | `config/docStatesTasks.jsonc` | Stavový automat (Nový/V práci/Pozastaveno/Hotovo/Smazáno) |
| `tasks.core.priorities` | `config/priorities.jsonc` | Číselník priorit pro `enumString` sloupec `priority` |

## Stavový automat

```
 Nový (10) ──▶ V práci (20) ──▶ Hotovo (40)    [tab Archív]
   │             ▲     ▲             │
   │             │     │             │
   ▼             ▼     │             ▼
 Pozastaveno (30) ◀────┴──▶ Smazáno (90)        [tab Koš]
```

`Hotovo` / `Smazáno` jsou `readOnly = 1`. Stav `Hotovo` má `viewGroup: archive` —
splněné úkoly automaticky mizí z tabu Aktivní a najdou se v tabu Archív.
Z archívu i koše vede zpět vždy přechod do stavu `V práci`. Nově vytvořený
úkol startuje ve stavu `Nový` (default `docState = 10`).

## Autor (`created_by`)

Sloupec `created_by` má `system: true`, takže se nikdy nevyplňuje z těla
požadavku. `FormController::save()` ho automaticky doplní z aktivního
`AuthContext` při INSERT (jen pokud je uživatel autentizovaný a sloupec
v tabulce existuje). Modifikace existujícího úkolu autora nemění.

## Rozšíření

- **Nová priorita** — přidat klíč do `config/priorities.jsonc`
  (`name`, `order`, `spanClass`); klient ji uvidí automaticky po
  `vendor/bin/shpd-ds ds-upgrade`.
- **Další stav** — přidat klíč do `config/docStatesTasks.jsonc` včetně
  `goto[]` přechodů od/do něj a případně přepsat `rowStateClass()` v
  `TasksViewer` pro styl t2 badge.
