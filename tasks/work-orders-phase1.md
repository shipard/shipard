# Zakázky Fáze 1 — evidence, číselné řady, dimenze zakázka

**Stav:** naplánováno — #110 D14–D18, D22, D23; P1–P5 potvrzené 2026-10-08

> PRD pro jednu Claude Code session (5 commitů). Design:
> `docs/work-orders.md` §4 (D14–D18, D20–D23), §5.1–5.4, §5.6; issue #110.
> Navazuje na `tasks/dimensions-core.md` (standardní dimenze jádra)
> a `tasks/number-series-engine.md` (`src/Core/Numbering/`).

## Kontext

Periodická fakturace (fáze 2) stojí na evidenci zakázek. Tahle fáze
založí modul `economy.workOrders` se všemi čtyřmi typy zakázek, druhy,
číselnými řadami a nadřazenou zakázkou, přidá zakázku jako třetí
standardní dimenzi deníku a detail zakázky s jejím deníkem. Fakturační
pole hlavičky, řádky předpisu a evidence období přijdou až ve fázi 2 —
tady zakázka nic nevystavuje.

## Cíl

1. Modul `economy.workOrders` v `install.base`: druhy zakázek s typem
   (D14) a číselné řady zakázek nad společným enginem čísel (D17).
2. Zakázka: hlavička všech typů, vlastní stavy s Ukončeno a Zrušeno
   (D18), číslo při potvrzení, nadřazená zakázka (D15), validace podle
   typu.
3. Dimenze `workOrder` na dokladech a v deníku (D20, D23) včetně pole
   v nastavení *Dimenze na dokladech* a lookupu.
4. Detail zakázky: Přehled (nadřazená, podzakázky, zákazník) a Deník.

## Před implementací přečti

- `docs/work-orders.md` §4 (D14–D18, D20–D23), §5.1–5.4, §5.6, §5.7
- `docs/accounting.md` § Dimenze deníku (standardní dimenze,
  `exchangeKey`, nastavení `economy.accounting.dimension.<id>`)
- `docs/doc-states.md` (vlastní sada stavů, `viewGroup`, `mainState`)
- `docs/modules.md`, `docs/table-definitions.md` (nové tabulky;
  `tableId` z `shpd-server next-table-id`, `docs/cli.md`)
- `docs/app-settings.md` (`settingsItems`, sekce Nastavení),
  `modules/install/base/config/settingsSections.jsonc`, `navSections.jsonc`
- `src/Core/Numbering/` (`NumberPattern`, `SequenceCounter`,
  `SequenceStorage`, `NumberContext`) a jeho použití v
  `modules/docs/core/src/DocDocument.php` a `NumberSeriesDocument.php`;
  `modules/economy/codebooks/src/FiscalYearLookup.php`
- vzor modulu s evidencí, nastavením a detailem: `modules/economy/assets/`
  (`module.jsonc`, `AssetDocument`, `AssetsViewer` — detail s taby,
  `AssetJournalService` — řádky deníku karty)
- vzor lookupu: `modules/economy/codebooks/src/CostCentersLookup.php`
- `modules/economy/accounting/module.jsonc` (`journalDimensions`,
  `settingsPages.accountingDimensions`),
  `tests/Unit/Module/Economy/Accounting/StandardDimensionsTest.php`
- `docs/help-authoring.md` před psaním do `help/`

## Scope

**Uvnitř:** vše v Cíli.

**Mimo:** fakturační pole hlavičky (periodicita, fakturovat od, text
dokladu, přepisy z druhu), výchozí hodnoty fakturace na druhu, řádky
zakázky, evidence období, záložka Fakturace (vše fáze 2); výměnný formát
zakázky a doplnění zakázky na doklady (`tasks/work-orders-import.md`,
P5); projektové funkce — cena, předmět dodávky, termíny, přehled stavu
se sčítáním podzakázek, uzavření (D19); povinnost nadřazené zakázky na
řadě (D15 „časem“); zakázka v datových sadách (dump ji vynechá
s varováním, jako majetek).

## 1. Modul a druhy zakázek (D14)

- Modul `economy.workOrders`, závislosti `core.system`, `base.persons`,
  `economy.codebooks`, `docs.core`, `economy.accounting`. Přidat do
  závislostí `install.base`.
- **`economy_work_orders_kinds`** (archivní stavy): `name`, `type`
  (enum `economy.workOrders.types`: `periodic`, `project`, `overhead`,
  `internal` — názvy cs: periodická, externí jednorázová, interní
  průběžná, interní jednorázová), `notice`.
- **Typ je po prvním potvrzení druhu jen ke čtení** (mění, co zakázky
  druhu mají); formulář ho po založení nenabízí k editaci, dokument to
  hlídá.
- cfgItem `economy.workOrders.types` nese per typ příznaky, ze kterých
  čte validace i formulář: `external` (zákazník, měna, VS),
  `oneOff` (smí mít nadřazenou zakázku), `invoicing: "periodic" | null`.
  Žádné `if type === …` rozesetá po kódu.

## 2. Číselné řady zakázek (D17)

- **`economy_work_orders_number_series`** (archivní stavy): `kind`
  (povinný, po založení jen ke čtení — jako typ dokladu u řad dokladů),
  `name`, `number_code` (`%C`), `number_pattern` (výchozí `%C%y%4`),
  `reset_scope` (`none` / `fiscal_year`, výchozí `fiscal_year`),
  `valid_from`, `valid_to`, `notice`.
- **`economy_work_orders_number_counters`** — `number_series`,
  `fiscal_year` (nullable), `last_assigned`; UNIQUE
  (`number_series`, `fiscal_year`). Stejný model jako
  `docs_core_number_counters` (N2).
- Validace vzorce přes `NumberPattern::validate` bez doménových
  placeholderů; navíc vzorec **musí obsahovat pořadí** (`%3`–`%6`), jinak
  by čísla kolidovala (P3).
- Bez seedu: druhy a řady zakládá uživatel nebo import.

## 3. Zakázka — hlavička a stavy (D15, D18, D22)

**`economy_work_orders_heads`** (pole fáze 1; fakturační přidá fáze 2):

| pole | typy | poznámka |
|---|---|---|
| `number_series` | všechny | povinná; určuje druh |
| `kind`, `type` | všechny | denormalizované z řady a druhu při uložení (přepíší, co poslal klient — jako `doc_type` u dokladů) |
| `number`, `sequence_number`, `fiscal_year` | všechny | přidělí potvrzení; `number` UNIQUE (NULL u konceptu) |
| `title` | všechny | povinný |
| `cost_center` | všechny | středisko — výchozí pro doklady zakázky (využije fáze 2) |
| `date_start` | všechny | zahájení; povinné při potvrzení |
| `date_end` | všechny | u periodické „do kdy platí“ (smí být v budoucnu); u ostatních skutečné ukončení — vyplní se při přechodu do Ukončeno / Zrušeno (výchozí dnes, editovatelné) |
| `internal_note` | všechny | interní poznámka (D13) |
| `customer` | externí | osoba; povinná při potvrzení |
| `currency` | externí | výchozí domácí měna |
| `payment_reference` | externí | pevný VS (D11) — fáze 1 jen ukládá |
| `parent` | jednorázové | nadřazená zakázka (D15) |

`displayPattern` tabulky `{number} — {title}` (lookup a štítky dimenze).

**Stavy** — vlastní sada `economy.workOrders.docStates` (P2):

| docState | název | mainState | viewGroup | readOnly | přechody do |
|---|---|---|---|---|---|
| 10 | Koncept | 1 | active | — | 40, 90 |
| 80 | V opravě | 2 | active | — | 40, 70, 30 |
| 40 | V pořádku | 3 | active | 1 | 80, 70, 30 |
| 70 | Ukončeno | 4 | archive | 1 | 80 |
| 30 | Zrušeno | 4 | archive | 1 | 80 |
| 90 | Smazáno | 5 | trash | 1 | 10 |

- Styly: Ukončeno jako `archive`, Zrušeno jako `cancelled`.
- Potvrzená zakázka se do Konceptu nevrací — číslo jí zůstává (žádné
  uvolňování čísla jako u dokladů). Smazat jde jen koncept.

**Číslo** (D17): při přechodu do 40 z 10 přidělí `SequenceCounter`
nad `economy_work_orders_number_counters` / `economy_work_orders_heads`;
rozsah = fiskální rok `date_start` (`FiscalYearLookup::yearIdForDate`)
u `reset_scope = fiscal_year`, jinak NULL. Chybí-li fiskální rok pro
datum zahájení u řady s ročním restartem → chyba potvrzení s odkazem na
účetní roky. Popisek roku `FiscalYearLookup::yearLabel`.

**Validace podle typu** (příznaky z cfgItem, §1):

- externí typy: `customer` povinný při potvrzení; neexterní typy
  `customer`, `currency` a `payment_reference` nemají (formulář je
  neukazuje, dokument je vynuluje);
- `parent` jen u typů s `oneOff`; nadřazená smí být libovolného typu
  kromě periodické (P4), nesmí být ve stavu Smazáno a nesmí vzniknout
  cyklus (kontrola po řetězci předků);
- změna řady na potvrzené zakázce není povolená (druh a číslo by
  přestaly sedět).

**Zákazník interní jednorázové zakázky** (D14 „z nadřazené“): neukládá
se; detail ho ukáže z nejbližší externí zakázky v řetězci předků.

## 4. Dimenze zakázka (D20, D23)

- Sloupec `work_order` (int, nullable,
  `reference: economy_work_orders_heads`) a index `idx_work_order`
  v `docs_core_heads`, `docs_core_rows` a `economy_accounting_journal`.
- Deklarace v `economy.accounting` **mezi** středisko a majetek:
  `id: workOrder`, `rowColumn` / `headColumn` / `journalColumn`
  `work_order`, `table: economy_work_orders_heads`,
  `exchangeKey: number`, názvy cs Zakázka / en Work order / sk Zákazka /
  de Auftrag, `forms.docTypes` `invno`, `invpo`, `invni`, `cash`,
  `cmnbkp`, `head` i `rows`, `enabledBySetting:
  economy.accounting.dimension.workOrder`.
- Pole *Zakázka na dokladech* na stránce *Dimenze na dokladech* mezi
  Středisko a Majetek.
- `WorkOrdersLookup` (registrace v `lookups` modulu): hledá v čísle,
  názvu a jménu zákazníka; nabízí zakázky V pořádku a V opravě; display
  `číslo — název`.
- `StandardDimensionsTest` rozšířit o třetí dimenzi a pořadí
  středisko → zakázka → majetek.

## 5. Navigace, nastavení, viewer a detail

- **Navigace** (P1): nová sekce sidebaru *Zakázky* (`workOrders`,
  `navSections.jsonc` v `install.base`, mezi Prodej a Majetek) s viewerem
  zakázek. **Nastavení**: nová sekce *Zakázky* (`settingsSections.jsonc`)
  s Druhy zakázek a Číselnými řadami zakázek.
- **Viewer zakázek**: taby podle `viewGroup` (aktivní / archiv / koš),
  sloupce číslo, název, zákazník, druh, zahájení, stav; filtr podle druhu
  a typu. Nová zakázka začíná výběrem číselné řady.
- **Detail** (vzor `AssetsViewer`):
  - *Přehled* — hlavička, nadřazená zakázka (odkaz), podzakázky (seznam
    s odkazy), zákazník z nadřazené u interní jednorázové;
  - *Deník* — vlastní řádky deníku s dimenzí zakázky (vzor
    `AssetJournalService`: souhrn po účetních letech, strop řádků, odkaz
    do deníku s filtrem `dim_workOrder=#id`). Sčítání podzakázek ne (D19).

## Uživatelská dokumentace

- `help/zakazky/` (nová složka): *Zakázky* (typy, stavy, nadřazená
  zakázka, číslo), *Nastavení zakázek* (druhy, číselné řady, vzorec),
  *Zakázka na dokladech* (zapnutí v Dimenzích na dokladech, hlavička vs.
  řádek, Deník zakázky, filtr v deníku).
- `help/co-shipard-umi.md`, `help/co-dnes-nejde.md` (periodická fakturace
  zatím ne).
- `python3 scripts/help-index.py`.

## Testy

- Dokumenty: druh (typ jen ke čtení po potvrzení), řada (vzorec bez
  pořadí, druh jen ke čtení), zakázka (validace podle typu, denormalizace
  druhu a typu, číslo při potvrzení přes engine, chybějící fiskální rok,
  cyklus a typ nadřazené, přechody stavů, `date_end` při ukončení).
- `StandardDimensionsTest`, `DimensionFormFieldsTest` — dimenze zakázka.
- `WorkOrdersLookupTest`, služba deníku zakázky, viewer.
- `vendor/bin/phpunit --filter 'WorkOrder|StandardDimensions|DimensionFormFields|JournalDimension'`,
  pak celá sada; `cd frontend && npm run build && npm run check:i18n`,
  pokud se mění frontend.

## Task breakdown

1. **Modul, druhy a číselné řady** — tabulky (`next-table-id`), cfgItem
   typů, dokumenty, formuláře, viewery, sekce Nastavení, `install.base`
   + testy.
2. **Zakázka** — tabulka hlavičky a čítačů, stavy, číslování přes
   engine, validace podle typu, nadřazená zakázka, formulář, viewer,
   sekce navigace + testy.
3. **Dimenze zakázka** — sloupce v jádru, deklarace, pole nastavení,
   lookup + testy.
4. **Detail** — Přehled a Deník + testy.
5. **Dokumentace a stav** — help, `docs/work-orders.md` (§5 skutečnost,
   §7), `docs/accounting.md` (tabulka standardních dimenzí), `CLAUDE.md`,
   Stav tasku + `python3 scripts/tasks-index.py`.

## Hotovo když

Na ukázkovém zdroji (`4l3j-z0bz-kz39-echj`, režim volný):

- `ds-upgrade` založí tabulky modulu a sloupce `work_order`; nic jiného.
- V Nastavení → Zakázky jde založit druh typu externí jednorázová
  a interní jednorázová a k nim řady; řada bez pořadí ve vzorci nejde
  uložit; typ potvrzeného druhu ani druh řady nejdou změnit.
- Nová zakázka po potvrzení dostane číslo podle vzorce, další navazuje,
  v novém účetním roce (řada s ročním restartem) začíná od 1; bez
  zákazníka externí zakázka nejde potvrdit.
- Interní jednorázová zakázka pod externí ukazuje zákazníka nadřazené;
  cyklus nadřazených zakázek je odmítnutý.
- Ukončená a zrušená zakázka jsou v archivu, s vyplněným datem ukončení;
  koncept jde smazat, potvrzená zakázka ne.
- Se zapnutou Zakázkou na dokladech má faktura přijatá pole Zakázka na
  hlavičce i řádcích; po zaúčtování nese deník zakázku, detail zakázky
  ji ukáže v Deníku a odkaz otevře deník s filtrem.
- Celá sada PHPUnit zelená.

## Rozhodnutí k designu (potvrzená)

- ✓ **D14–D18, D20–D23** (#110) — typy na druhu, nadřazená zakázka,
  číselné řady nad společným enginem, Ukončeno a Zrušeno, evidence
  zakázky pro M4, zakázka jako standardní dimenze jádra.
- ✓ **P1 — Navigace a nastavení:** nová sekce sidebaru *Zakázky* (mezi
  Prodej a Majetek) a nová sekce Nastavení *Zakázky*.
- ✓ **P2 — Stavy a přechody** podle tabulky v §3: potvrzená zakázka se do
  Konceptu nevrací (číslo zůstává), smazat jde jen koncept, z Ukončeno
  i Zrušeno vede cesta zpět přes V opravě.
- ✓ **P3 — Číslo:** UNIQUE v celém zdroji; vzorec musí obsahovat pořadí;
  výchozí vzorec `%C%y%4`; roční restart podle fiskálního roku data
  zahájení; zahájení povinné při potvrzení.
- ✓ **P4 — Nadřazená zakázka smí být libovolného typu kromě
  periodické** — náklady jednorázové zakázky se smysluplně sčítají pod
  externí jednorázovou, interní jednorázovou i režijní, pod nájemní
  smlouvou zatím ne.
- ✓ **P5 — Výměnný formát a import zakázek** jako samostatný task
  `tasks/work-orders-import.md` po fázi 2 (nese i fakturační předpis
  a *fakturovat od*, které fáze 1 nemá).
