# Zakázky — řádky předpisu se řídí stavem zakázky

**Stav:** hotovo — 2026-10-09 (1 commit; guard jako lock provider místo hooků
v Document třídě, viz Poznámky k implementaci; `ds-upgrade` jen `4l3j`);
zbývá `ds-upgrade` na ostatních zdrojích a alfě

> PRD pro jednu Claude Code session (1 commit). Opravuje odchylku
> z `tasks/work-orders-phase2.md` (Poznámky k implementaci — řádky
> předpisu jako `independentRows`). Design: `docs/work-orders.md` §5.3,
> §5.4.

## Kontext

Fáze 2 udělala z řádků předpisu nezávislou sub-tabulku
(`subtableTab(..., independentRows: true)` ve `WorkOrdersForm`), aby šla
změnit cena k datu i u zakázky **V pořádku**. To odporuje zásadě, že
potvrzená zakázka je celá jen ke čtení a mění se přes **V opravě**
(stavová sada `economy.workOrders.docStates`, §5.4). Zakázka V opravě se
při běhu přeskočí a její období se vystaví po návratu do V pořádku
(dohánění, D5), takže oprava přes V opravě o fakturu nepřijde.

`independentRows` má navíc vedlejší účinek, se kterým fáze 2 nepočítala:
`FormSubTable.svelte` počítá `listReadOnly = readOnly || independentRows`,
takže seznam řádků **nikdy** nenabízí Přidat, Smazat ani přesun — ani
u konceptu. Řádky dnes z formuláře přidat nejde. A backend stav zakázky
u řádku nehlídá vůbec (`WorkOrderRowDocument` validuje jen tvar řádku),
takže API zapíše řádek i do potvrzené nebo ukončené zakázky.

## Cíl

1. Řádky zakázky se řídí stavem zakázky jako řádky dokladu: v Konceptu
   a V opravě Přidat, Smazat, přesun i úprava; ve stavech s `readOnly`
   (V pořádku, Ukončeno, Zrušeno, Smazáno) jen ke čtení.
2. Backend guard pro uložení i smazání řádku podle stavu zakázky.

## Před implementací přečti

- `docs/work-orders.md` §5.3, §5.4; `tasks/work-orders-phase2.md`
  (Poznámky k implementaci)
- `docs/edit-forms.md` §15 (sub-tabulky, `independentRows`, 15.5 guard
  rodiče u `/move` — `DOCUMENT_READONLY`)
- `modules/economy/workOrders/src/WorkOrdersForm.php` (tab Řádky),
  `WorkOrderRowDocument.php`, `config/docStates.jsonc`,
  `tables/economy_work_orders_rows.jsonc`
- `frontend/src/components/form/FormSubTable.svelte` (`listReadOnly`,
  `dialogReadOnly`) — jen pro pochopení; frontend se nemění

## Úpravy

- **`WorkOrdersForm`**: tab Řádky bez `independentRows` — sub-tabulka
  převezme read-only rodiče (standardní chování jako řádky dokladu).
- **`WorkOrderRowDocument`**: při uložení i smazání řádku načte stav
  zakázky; je-li stav v `economy.workOrders.docStates` označený
  `readOnly`, odmítne zápis — chyba na `work_order`, kód
  `parent_read_only`, hláška „Zakázka je jen ke čtení — řádky uprav přes
  V opravě.“ Stavy ber z cfgItem, ne natvrdo čísla. Platí pro všechny
  cesty (formulář, generické API); přesun řádků už hlídá
  `/subtable/.../move` (`DOCUMENT_READONLY`).
- Komentář v `economy_work_orders_rows.jsonc` (dnes „editovatelná i u
  zakázky V pořádku“) přepsat.
- **Import zakázek** (`tasks/work-orders-import.md`, zatím nenapsaný)
  musí řádky zapsat dřív, než zakázku potvrdí — guard výjimku pro import
  nemá. Poznamenej to do `docs/work-orders.md` §6.

## Dokumentace

- `help/zakazky/periodicka-fakturace.md` — „Změna ceny od data“: zakázku
  dej **V opravě**, starému řádku vyplň Platnost do, přidej nový řádek
  s Platností od, dej **V pořádku**; koncept, který už vznikl se starou
  cenou, **Přegeneruj**. Věta „Řádky jde upravovat i u zakázky
  V pořádku“ pryč. `python3 scripts/help-index.py`.
- `docs/work-orders.md` §5.5 („Hotovo ve fázi 2“ — řádky editovatelné
  i u V pořádku) opravit; §6 poznámka pro import.
- `tasks/work-orders-phase2.md` Poznámky k implementaci — u bodu o
  `independentRows` doplnit „nahrazeno `tasks/work-orders-rows-readonly.md`“.
- `CLAUDE.md` — jen pokud řádek `docs/work-orders.md` editovatelnost řádků
  zmiňuje.

## Testy

- `WorkOrderRowDocument`: uložení a smazání řádku u zakázky v Konceptu
  a V opravě projde; V pořádku, Ukončeno, Zrušeno → `parent_read_only`.
- Definice formuláře zakázky: tab Řádky bez `independent_rows`.
- `vendor/bin/phpunit --filter 'WorkOrderRow|WorkOrdersForm|InvoicingRun'`,
  pak celá sada.

## Hotovo když

Na ukázkovém zdroji (`4l3j-z0bz-kz39-echj`, režim volný):

- U konceptu periodické zakázky jde na záložce Řádky přidat řádek,
  přesunout ho a smazat.
- Po přechodu do **V pořádku** jsou řádky jen ke čtení (bez Přidat,
  dialog řádku jen Zobrazit); po **V opravě** znovu editovatelné.
- Uložení řádku přes API u zakázky V pořádku skončí chybou
  `parent_read_only`.
- Běh periodické fakturace dál funguje (zakázka V opravě přeskočena,
  po potvrzení dovystavena).
- Celá sada PHPUnit zelená.

## Rozhodnutí k designu (potvrzená)

- ✓ **Zakázka V pořádku je jen ke čtení včetně řádků předpisu**; úpravy
  jdou přes V opravě (2026-10-09). Odchylka fáze 2 se ruší.
- ✓ **Guard jako lock provider**, ne hooky v `WorkOrderRowDocument`
  (2026-10-09, viz níže).

## Poznámky k implementaci (2026-10-09)

- **Guard je `WorkOrderRowLockProvider`** (`documentLockProviders` nad
  `economy_work_orders_rows`), ne `validate()` / `beforeDelete()`
  v `WorkOrderRowDocument`, jak stálo v zadání. Důvod: Smazat ze
  sub-tabulky jde přes generické `DELETE /{table}/{id}`
  (`CrudController::delete` maže přímým SQL) a CRUD PUT / PATCH jedou
  jen přes validátor sloupců — Document hooky na těchto cestách neběží
  vůbec. Jediný mechanismus, který jádro vynucuje na gateway *i* CRUD
  update / patch / delete, je zámek záznamu (`docs/document-system.md`
  §16). Kontrakt chyb je tedy standardní: uložení řádku → 422
  `VALIDATION_ERROR` s chybou `_form` / kód `locked` (banner dialogu
  řádku), mazání a CRUD → 422 `DOCUMENT_LOCKED`; kód `parent_read_only`
  neexistuje. Meta řádku `lock` nenese (tabulka řádků nemá docStates) —
  read-only dialog řádku dává sub-tabulka z rodiče.
- **Sloupec `work_order` není pole formuláře řádku** — chyba na něm by se
  v dialogu nevykreslila; lock reason jde do banneru formuláře.
- Důvod zámku: `source` `work_order_state`, title „Zakázka {číslo} je jen
  ke čtení — řádky uprav přes V opravě.“ (smazaný koncept bez čísla →
  název), `params {label, state, docState}`; klient lokalizuje přes
  `lock.source.work_order_state` / `lock.message.work_order_state`
  (cs, en), bez klíče spadne na text ze serveru. Stavy z cfgItem
  `economy.workOrders.docStates` (`WorkOrderDocument::DOC_STATES_CFG_ITEM`);
  bez zkompilovaného cfgItem (DS před `ds-upgrade`) provider nezamyká.
- **Generické CRUD `POST /{table}`** lock providery nevolá (guard jádra
  chce id) — nový řádek do potvrzené zakázky přes CRUD create server
  nehlídá. Platí pro všechny providery, ne jen tento; řešení patří do
  jádra (`CrudController::create` → `guardLock` s `original = null`).
