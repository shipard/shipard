# Task: Obecné spodní taby vieweru

**Stav:** hotovo — 2026-09-29 (3 commity), API smoke i ruční proklik (faktury přijaté, Spisovna se šanonem) OK

## Cíl

Spodní lišta záložek ve vieweru je dnes napevno vázaná na číselné řady
(`TableViewer::getNumberSeries()`, meta `numberSeries`,
`filter[number_series]`, při vytvoření záznamu se do formuláře vkládá
`number_series`). Zobecnit ji na **libovolné záložky definované viewerem**:
viewer určí seznam záložek, jejich význam pro filtr i výchozí hodnoty
nového záznamu.

Důvody:

- Majetek potřebuje záložky podle druhu (`docs/assets.md` D26,
  `tasks/assets-phase1.md`).
- `RegistryDocumentsViewer` už dnes mechanismus číselných řad zneužívá pro
  šanony (sentinely `0` = Vše, `-1` = Nezařazené v `filter[number_series]`)
  a při „Přidat“ z šanonu dostává formulář nesmyslné `number_series`.
- Starý Shipard měl spodní taby v mnoha viewerech; budou potřeba i jinde.

## Návaznost

- `tasks/viewer-number-series-tabs.md` — současná implementace (hotovo).
- `docs/frontend.md` § „Spodní lišta — číselné řady“ a popis
  `GET /_ui/viewer/{id}/meta` / `rows`.
- Odemyká `tasks/assets-phase1.md` (viewer karet majetku).

## Před implementací přečti

- `src/Core/Viewer/TableViewer.php` (`getNumberSeries`, `getNewRecordDefaults`)
- `src/Api/Controller/ViewerController.php` (`meta()`, `rows()`)
- `modules/docs/core/src/DocsHeadsViewer.php` (`$scopedDocType`,
  `getNumberSeries`, filtr `number_series` v `selectRows`)
- `modules/base/registry/src/RegistryDocumentsViewer.php` (šanony přes
  `getNumberSeries`, sentinely)
- `frontend/src/components/viewer/Viewer.svelte` (`activeSeriesId`,
  `fetchMeta`, `fetchRowsExplicit`, `handleToolbarAction('create')`, lišta)

## Kontrakt

### Backend

Nová metoda v `TableViewer` (nahrazuje `getNumberSeries()`):

```php
/**
 * Bottom tab bar of the row list. Empty list = no bar.
 *
 * Each tab: id (string|int, opaque to the frontend — sent back as
 * filter[bottomTab]), label (localized), optional newRecordDefaults
 * merged over getNewRecordDefaults() when a record is created while
 * the tab is active.
 *
 * @return list<array{id: string|int, label: string, newRecordDefaults?: array<string, mixed>}>
 */
public function getBottomTabs(): array { return []; }

/** Tab pre-selected when the viewer opens; null = first tab. */
public function getDefaultBottomTab(): string|int|null { return null; }
```

- Filtr: `filter[bottomTab]=<id>` → do `selectRows()` jako
  `{id: 'bottomTab', value: …}`. Hodnotu interpretuje výhradně viewer.
- Meta: klíč `bottomTabs` (`{tabs: [...], default: id|null}`), klíč
  `numberSeries` **zaniká** (jediný konzument je `Viewer.svelte`).
- `getNumberSeries()` zaniká; `DocsHeadsViewer` a `RegistryDocumentsViewer`
  přejdou na `getBottomTabs()`.

### Frontend (`Viewer.svelte`)

- `activeSeriesId` → `activeBottomTab`; default `meta.bottomTabs.default`
  ?? první tab ?? `null`.
- Lišta se vykreslí, když je tabů víc než jeden (beze změny pravidla).
- `fetchRowsExplicit` posílá `filter[bottomTab]`.
- „Přidat“: `formDefaultData = {...meta.newRecordDefaults,
  ...activeTab.newRecordDefaults}` — žádné napevno zadrátované
  `number_series`.
- Reset při přepnutí vieweru jako dnes.

## Migrace konzumentů

1. **`DocsHeadsViewer`** — `getBottomTabs()` vrací řady `{id, label: name,
   newRecordDefaults: {number_series: id}}` (stejný dotaz jako dnes:
   `$scopedDocType`, `docState = 40`, `ORDER BY name`); `selectRows`
   čte `bottomTab` místo `number_series`. Chování pro uživatele beze změny.
2. **`RegistryDocumentsViewer`** — záložky Vše (`all`), šanony (`id`),
   Nezařazené (`unfiled`); sentinely `0` / `-1` nahradí stringové id.
   Záložka šanonu nese `newRecordDefaults: {binder: id}` (B4).

## Mimo rozsah

- Počty záznamů na záložkách, persistence aktivní záložky, více lišt
  v jednom vieweru.
- Viewer majetku — ten přidá `tasks/assets-phase1.md`.

## Testy

- Unit: `DocsHeadsViewer::getBottomTabs()` (scoped / cross-type → `[]`,
  `newRecordDefaults`), filtr `bottomTab` v `selectRows` obou viewerů
  (včetně `all` / `unfiled` u registru).
- `vendor/bin/phpunit --filter 'Viewer'`, pak celá sada;
  `cd frontend && npm run build`.
- Ruční proklik na ukázkovém DS: faktury přijaté (záložky řad, „Přidat“
  předvyplní řadu), Spisovna (Vše / šanon / Nezařazené, „Přidat“ ze
  šanonu předvyplní šanon).

## Dokumentace

- `docs/frontend.md`: přepsat § „Spodní lišta — číselné řady“ na obecné
  spodní taby, v popisu `meta` a `rows` nahradit `numberSeries` /
  `filter[number_series]`.
- `tasks/viewer-number-series-tabs.md`: poznámka pod hlavičkou, že
  mechanismus zobecnil tento task.
- `tasks/README.md`: řádek do sekce „Frontend — shell, navigace, viewery“,
  **Stav** tohoto tasku + `python3 scripts/tasks-index.py`.

## Hotovo když

- [x] `getBottomTabs()` / `getDefaultBottomTab()` v `TableViewer`, meta
      `bottomTabs`, `getNumberSeries()` a meta `numberSeries` odstraněné
- [x] oba konzumenti migrovaní, `grep -rn 'number_series' frontend/src`
      nenajde viewerovou logiku
- [x] testy zelené, frontend build projde, API smoke na ukázkovém DS
      (meta, rows s `filter[bottomTab]`, `defaults[binder]` koerce)
- [ ] ruční proklik v prohlížeči (faktury přijaté, Spisovna se šanonem)
- [x] dokumentace a Stav aktualizované

## Rozhodnutí k designu (potvrzena 2026-09-29)

- **B1** API `getBottomTabs()` + `getDefaultBottomTab()`, filtr
  `bottomTab`, meta `bottomTabs`; `getNumberSeries()` a `numberSeries`
  se ruší bez zpětné kompatibility (interní kontrakt, jeden klient).
- **B2** Id záložky je pro frontend neprůhledné (string i int), význam
  zná jen viewer — žádné sdílené sentinely.
- **B3** Výchozí hodnoty nového záznamu nese záložka
  (`newRecordDefaults`), frontend je slije přes výchozí hodnoty vieweru.
- **B4** Spisovna: „Přidat“ ze záložky šanonu předvyplní šanon (dnes
  dostává formulář `number_series`, který ignoruje).
