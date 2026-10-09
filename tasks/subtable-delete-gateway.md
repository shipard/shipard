# Sub-tabulky — mazání řádku přes TableGateway

**Stav:** naplánováno — #113 bod 2 (rychlá oprava před přestavbou CRUD); S1 potvrzené 2026-10-09

> PRD pro jednu Claude Code session (1 commit). Kontext: issue #113
> (generické CRUD obchází Document lifecycle). Navazuje na
> `tasks/subtable-phase3.md` (endpoint `/move` s guardem rodiče).

## Kontext

`FormSubTable.svelte` maže řádek sub-tabulky generickým
`DELETE /{table}/{id}` (`CrudController::delete`) — ten smaže řádek
přímým SQL a Document lifecycle nevolá. U řádků dokladu se proto nespustí
`DocRowsDocument::afterDelete` (přepočet hlavičky): **po smazání řádku
faktury zůstanou součty hlavičky zastaralé, dokud uživatel neklikne
Uložit** (ověřeno v UI 2026-10-09). Totéž platí pro všechny sub-tabulky
s `afterDelete` / `beforeDelete` (`VatRecapDocument`, řádky zakázky
a jejich zámek přes provider jen díky `guardLock` v CRUD) a pro handlery
`beforeDelete`.

Celkové řešení (CRUD jako adaptér nad `TableGateway`) je #113. Tahle
oprava jen převede **mazání ze sub-tabulky formuláře** na endpoint
formuláře, který jde přes gateway — stejně jako už jde přesun (`/move`).

## Cíl

Smazání řádku v sub-tabulce formuláře jde přes
`TableGateway::deleteDocument` dětské tabulky: zámky, `beforeDelete`,
události, `afterDelete` (přepočet hlavičky dokladu) — a s guardem
read-only rodiče jako `/move`.

## Před implementací přečti

- `docs/edit-forms.md` §15 (sub-tabulky, 15.5 `/move` — guard rodiče,
  příslušnost řádku k rodiči)
- `src/Api/Controller/FormController.php` — `subtableMove`,
  `resolveSubtableContext`, `guardParentWritable`, konstrukce
  `TableGateway` v `save`
- `src/Core/Document/TableGateway.php` — `deleteDocument`
- `src/Api/Router.php` — route `…/subtable/{tabId}/{parentId}/move`
- `frontend/src/components/form/FormSubTable.svelte` — `confirmDelete`
- `modules/docs/core/src/DocRowsDocument.php` (`afterDelete`)

## Úpravy

- **Endpoint** `POST /_ui/form/{table}/subtable/{tabId}/{parentId}/delete`,
  tělo `{ "id": 4711 }` → `FormController::subtableDelete()`:
  1. `resolveSubtableContext()` jako výpis a `/move`; špatné tělo → 400
     `BAD_REQUEST`.
  2. Rodič v read-only doc state → 422 `DOCUMENT_READONLY`
     (`guardParentWritable`, stejná hláška jako save a `/move`).
  3. Řádek musí patřit rodiči (FK) → jinak 404 `RECORD_NOT_FOUND`.
  4. `TableGateway` dětské tabulky (stejná konstrukce jako v `save` —
     registry, child tables, config, dispatcher, docStates) →
     `deleteDocument($id)`. Zámek (`DocumentLockRegistry::DOMAIN_CODE`)
     → 422 `DOCUMENT_LOCKED` se stejným tvarem jako u CRUD; jiná chyba →
     422 / 500 podle `DocumentResult`.
  5. Odpověď `{ success: true, data: null }`.
  Read-only DS odmítá `ReadOnlyPolicy` (POST pod `/_ui/form/` — ověř, že
  je mezi zápisovými cestami).
- **Router**: route vedle `/move`.
- **Frontend** `FormSubTable.svelte`: `confirmDelete` volá nový endpoint
  (`parentTable`, `tabId`, `parentId` už komponenta má); chybové hlášky
  přes `translateError` jako dnes. Po úspěchu `fetchRows()` + `onChanged`
  — rodič si přenačte už přepočtenou hlavičku.
- Generické `DELETE /{table}/{id}` se **nemění** (řeší #113).

## Dokumentace

- `docs/edit-forms.md` §15 — nová podkapitola „Mazání řádku —
  `/delete`“ (kontrakt jako u `/move`, proč přes gateway).
- `docs/rest-api.md` — jen pokud vyjmenovává `/_ui/form/…` endpointy.
- #113: komentář, že bod 2 je vyřešený pro UI.

## Testy

- `FormController::subtableDelete`: smazání řádku dokladu spustí
  přepočet hlavičky (součty po smazání sedí); rodič read-only → 422
  `DOCUMENT_READONLY`; řádek cizího rodiče → 404; zámek providera → 422
  `DOCUMENT_LOCKED`; špatné tělo → 400.
- Router: nová route.
- `vendor/bin/phpunit --filter 'FormController|Subtable|DocRows'`, pak
  celá sada; `cd frontend && npm run build && npm run check:i18n`.

## Hotovo když

Na ukázkovém zdroji (`4l3j-z0bz-kz39-echj`, režim volný):

- Smazání řádku faktury v Konceptu přepočítá součty hlavičky hned, bez
  Uložit.
- U potvrzené faktury (V pořádku) sub-tabulka mazání nenabízí; přímé
  volání endpointu → 422 `DOCUMENT_READONLY`.
- Řádek periodické zakázky jde smazat v Konceptu a V opravě; u zakázky
  V pořádku endpoint odmítne.
- Celá sada PHPUnit zelená.

## Rozhodnutí k designu (potvrzená)

- ✓ **S1 — Rychlá oprava zvlášť od #113:** jen mazání ze sub-tabulky
  formuláře, přes endpoint formuláře po vzoru `/move`; generické CRUD
  (vč. `DELETE /{table}/{id}` pro integrace) zůstává na #113.
