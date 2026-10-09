# Zakázky — řádky předpisu se řídí stavem zakázky

**Stav:** naplánováno — oprava fáze 2 (#110 D22, D24)

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
