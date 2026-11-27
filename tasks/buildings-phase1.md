# Doplněk Budovy — fáze 1: Smlouvy s místy (#174 D1–D27)

**Stav:** částečně — implementace hotová 2026-11-27 (commity 0–4, ověření doplňku D26 naostro); zbývá ruční kontrola pole Místa podle #176 D7 a rozhodnutí o varování překryvu při potvrzení (formulář se zavře i s varováním — viz Ověření D26 → Otevřené); design #174 D16–D28, předpoklad `tasks/form-multilookup.md` (#176) hotov 2026-11-26

> Task pro Claude Code (5 commitů — 0 až 4). Design: issue #174. První doplněk
> v hlavním repozitáři (`kind: addon`, #170). Fáze 1 = evidence smluv bez
> fakturace (D16); fakturace (řádky předpisu, šablona řádků, `inv_*`,
> evidence období) je fáze 2 nad #175, napojení na Místa fáze 3.
> **Předpoklad:** hotový prvek `multilookup` (`tasks/form-multilookup.md`).

## Kontext

- **Doplňky** (#170, `docs/modules.md` §9): `"kind": "addon"`, zapnutí
  `shpd-ds ds-module enable <id>`, vlastní sekce přes `navSections` /
  `settingsSections` (§5 → Vlastní sekce modulu), jádro o doplňku neví
  (`ModuleKindRules`). Rozcestník `modules/README.md` generuje
  `php scripts/modules-index.php`. Žádný doplněk zatím neexistuje.
- **Vzory v jádru:**
  - typ × druh — `base.places` (`PlaceTypes` čte cfgItem jen přes vlastnosti,
    `PlaceKindDocument`: zámek typu po potvrzení, použitý druh nejde do koše,
    `PlaceKindsProvisioner` = seed druhů podle `system_code`);
  - evidence se stavy Ukončeno / Zrušeno, číslo při potvrzení přes
    `SequenceCounter` nad vlastní `SequenceStorage` — `economy.workOrders`
    (`WorkOrderDocument`, `config/docStates.jsonc`,
    `economy_work_orders_number_counters`);
  - `NumberPattern` (`%C`, `%y` / `%Y`, `%3`–`%6`), `NumberContext`.
- **Místa** (`docs/places.md`): typy `site`, `building`, `section`, `floor`,
  `room`, `outdoor`, `spot`; `PlacesLookup` má filtry `childType`
  a `exclude`, cestu místa dává `PlaceTree::pathText()`.
- **Výběr více záznamů** (#176, `docs/edit-forms.md` §4.7b po dokončení
  `tasks/form-multilookup.md`): prvek `multilookup` nad `childTables`
  rodiče, filtr jako u `lookup`.

## Rozhodnutí (#174)

Celý seznam je v issue #174; pro fázi 1 platí hlavně:

- **D1** — jeden doplněk `facility.buildings` „Budovy“, tabulky
  `facility_buildings_*`, vlastní sekce sidebaru a Nastavení Budovy;
  závislosti `base.places`, `base.persons` (a `economy.periodicInvoicing`
  až ve fázi 2 — #175 D2).
- **D4, D5** — typ × druh; bez dolních tabů, filtr druh / typ.
- **D6, D7** — místa na hlavičce přes child tabulku; překryv = varování.
- **D13** — virtuální kancelář má místo (budova, areál).
- **D15** — vzorec čísla na druhu, čítač per druh (a rok).
- **D16** — fáze 1 bez řádků a bez nastavení fakturace.
- **D17** — typy smluv (cfgItem `facility.buildings.contractTypes`):

  | klíč | název | `places` | `placeTypes` |
  |---|---|---|---|
  | `office` | Kancelář | `required` | `room`, `section` |
  | `flat` | Byt | `required` | `section`, `room` |
  | `retail` | Obchodní prostor | `required` | `section`, `room`, `outdoor` |
  | `storage` | Sklad | `required` | `room`, `section`, `outdoor` |
  | `parking` | Parkování | `required` | `spot`, `outdoor` |
  | `virtualOffice` | Virtuální kancelář | `required` | `building`, `site` |
  | `other` | Ostatní | `optional` | `null` (libovolné) |

  Vlastnosti: `icon`, `places` (`required` / `optional` / `none`),
  `placeTypes`, `order`. Kód se nikdy neptá na klíč typu.
- **D18** — druh: název, typ, poznámka, kód, vzorec čísla, restart
  (`continuous` / `yearly`); typ po potvrzení jen ke čtení; použitý druh
  nejde do koše, archivovat jde; čítač per druh a **kalendářní rok
  zahájení** (scope = rok jako int, u `continuous` NULL).
- **D19** — provisioner založí jeden druh na typ (kódy KA, BY, OP, SK, PA,
  VK, OS), vzorec `%C-%Y-%4`, restart ročně.
- **D20** — smlouva: druh, typ (denormalizovaný), číslo, pořadí, rok
  čítače, název, nájemce `customer`, zahájení, ukončení, interní poznámka,
  stavy; místa v `facility_buildings_contract_places` (`dataKey` `places`);
  `exchangeKey` = `number`.
- **D21** — stavy jako zakázky; číslo při potvrzení; Ukončeno / Zrušeno
  doplní ukončení; mazat jen koncept.
- **D22** — validace (níže v krocích).
- **D23** — filtr `types` v `PlacesLookup`.
- **D24, D25** — formulář, prohlížeč a detail (níže).
- **D26** — `ds-module enable` / `disable` naostro na volném zdroji dat.
- **D27** — ikony v `icons.js` pod obecnými názvy; nápověda
  `help/budovy/smlouvy.md`.
- **D28** — výchozí data modulu přes bod rozšíření jádra: pole
  `provisioners` v `module.jsonc` + rozhraní `ModuleProvisioner`;
  `ds-upgrade` volá provisionery aktivních modulů vždy (i pod
  `skipProvisioning`). Doplněno 2026-11-27 — `PlaceKindsProvisioner` je
  v `DsUpgradeCommand` zapojený napevno, což doplněk použít nesmí.

## Před implementací přečti

- `docs/modules.md` §1 (druhy modulů), §5 (vlastní sekce, `settingsItems`,
  `clearSettingsOnReset`), §9 (doplňky)
- `docs/places.md` (typy, druhy, `PlacesLookup`, `PlaceTree`)
- `docs/work-orders.md` §5.2–5.4 (druh, číslo, stavy)
- `docs/document-system.md` §6 (child tabulky), §8b (`trackStateChange`)
- `docs/edit-forms.md` §4.7b (`multilookup`), `docs/frontend.md` (detail,
  řádek seznamu), `docs/design-system.md` §4, §12
- `docs/help-authoring.md`

## Kroky

### Commit 0 — jádro: provisionery modulů (D28)

- `ModuleDefinition`: pole `provisioners` `[{class, order?}]`; rozhraní
  `Shipard\Core\Module\ModuleProvisioner` a kontext
  `ModuleProvisionContext` (spojení, konfigurace zdroje dat, kompilát
  v jazyce zdroje dat).
- `DsUpgradeCommand::provisionModules()` — mimo bránu `skipProvisioning`,
  jen aktivní moduly, pořadí modulů a `order`; výjimka = chyba `ds-upgrade`.
- `ModuleProvisionerRules` nad stromem (namespace modulu, rozhraní,
  konstruktor bez argumentů).
- Testy: tvar deklarace, pravidla, `ds-upgrade` s fixture modulem
  `test.provisioners` (běh pod `skipProvisioning`, neaktivní modul,
  selhání). `docs/modules.md` kap. 5 a §9, `CLAUDE.md`.

### Commit 1 — `base.places`: filtr typů v lookupu (D23)

- `PlacesLookup::FILTER_TYPES = 'types'` v `getAllowedFilterKeys()`;
  hodnota = seznam typů míst (pole, nebo čárkami oddělený řetězec z query
  stringu); prázdné = bez omezení. Filtr se kombinuje s `childType`
  a `exclude`.
- Test v `PlacesLookupTest`; `docs/places.md` (odstavec o filtrech).

### Commit 2 — modul a model

- `modules/facility/buildings/module.jsonc`: `"kind": "addon"`,
  závislosti, `navSections` `[{id: "buildings", name:cs: "Budovy", icon,
  order}]` (pořadí vedle Zakázek), `settingsSections` stejně, cfgItemy,
  tabulky, `documentClasses`, `lookups` (kvůli budoucímu odkazu na
  smlouvu stačí `ContractsLookup` nad číslem a názvem). `tableId`
  přes `php bin/shpd-server next-table-id`.
- cfgItemy: `facility.buildings.contractTypes` (D17), `…docStates`
  (kopie sady stavů zakázek s vlastními texty — Ukončeno = „Ukončená“,
  podle rodu smlouvy), `…resetScopes`, `…viewerLabels`, `…formTexts`.
- `ContractTypes` — jediné čtení typů (`places()`, `placeTypes()`,
  `icon()`, `options()`, `isUnknown()`), vzor `PlaceTypes`.
- Tabulky:
  - `facility_buildings_contract_kinds` — `name`, `type` (enumString
    z `contractTypes`), `notice`, `number_code`, `number_pattern`,
    `reset_scope`, `system_code` (seed), `docState` / `docStateMain`
    (archivní stavy);
  - `facility_buildings_number_counters` — `kind`, `year` (nullable),
    `last_assigned`; unikátní `kind` × `year`;
  - `facility_buildings_contracts` — `kind`, `type`, `number` (unikátní,
    nullable do potvrzení), `sequence_number`, `number_year`, `title`,
    `customer` (`base_persons_persons`), `date_start`, `date_end`,
    `internal_note`, stavy; `childTables` `[{table:
    "facility_buildings_contract_places", foreignKey: "contract", dataKey:
    "places"}]`; `exchangeKey` `number`;
  - `facility_buildings_contract_places` — `contract`, `place`
    (`base_places_places`), `order_pos`; index `place` (dotazy překryvu
    a fáze 3).
- `ContractKindDocument` — vzorec přes `NumberPattern::validate()`
  (`%C` vyžaduje kód), typ po opuštění Konceptu jen ke čtení, do koše
  jen nepoužitý druh (smlouva mimo koš), přechody stavů přes dokument.
- `ContractKindsProvisioner` — D19, druh se pozná podle `system_code`
  (= klíč typu), existující se nemění; deklarovaný v `provisioners`
  modulu (D28), takže běží v `ds-upgrade` i pod `skipProvisioning`.
- `ContractDocument`:
  - druh povinný; `type` vždy z druhu; druh jen v Konceptu měnitelný;
  - `date_end ≥ date_start`; místa: duplicita, místo v koši, typ místa
    mimo `placeTypes`, místa u typu `none` → chyba na `places.N.place`
    se jménem místa;
  - při přechodu do V pořádku navíc nájemce, zahájení a místa u typu
    `required`;
  - **překryv (D7, D22)** — varování: místo je ve smlouvě ve stavu
    V pořádku nebo V opravě (mimo tuto) s překrývající se dobou
    (`NULL` ukončení = neomezeno); zpráva „Kancelář 201 je i ve smlouvě
    KA-2027-0003 (od 01.01.2027)“ — jeden nález na místo;
  - číslo při `0 / 10 → 40` přes `SequenceCounter` + vlastní
    `SequenceStorage` (scope = rok zahájení u `yearly`, jinak NULL;
    `NumberContext` s rokem zahájení); importované číslo zůstává;
  - `trackStateChange()`; Ukončeno / Zrušeno doplní `date_end` (dnešek),
    chybí-li; smazat (90 → fyzicky) jde jen koncept.
- `php scripts/modules-index.php`; test, že `facility.buildings` je
  doplněk a `install.base` ho nepotáhne (`ModuleKindRules`).
- Testy: typy (vlastnosti, neznámý typ), druh (vzorec, zámek typu, koš
  použitého druhu), provisioner (idempotence), smlouva (validace
  v Konceptu i při potvrzení, místa podle typu, překryv včetně
  neomezeného ukončení a stavu V opravě, číslo per druh a rok,
  `continuous`, ukončení při Ukončeno).

### Commit 3 — Nastavení, formulář, prohlížeč, detail

- **Druhy smluv** v Nastavení → Budovy: viewer (ikona typu, kód, vzorec,
  ukázka čísla „KA-2027-0001“ přes `NumberPattern`) a formulář (typ jako
  select jen v Konceptu, vzorec s nápovědou placeholderů).
- **`ContractsForm`** (D24): hlavička — druh (select živých druhů, jen
  v Konceptu, `triggers: reload`), název, nájemce (`lookup` osob),
  zahájení, ukončení, **Místa** (`multilookup` nad `base_places_places`,
  filtr `types` z `placeTypes` typu druhu; u `places: none` skryté,
  u `required` hvězdička), interní poznámka; tab Přílohy. Přepočet po
  změně druhu přestaví filtr; místa, která novému typu nevyhovují,
  nemaže (ohlásí je validace).
- **`ContractsViewer`** (D25), `navSection: "buildings"`:
  - řádek — titulek název (bez názvu nájemce), podtitul číslo; `t2`:
    nájemce, místa (první dvě krátkým názvem + „+N“), platnost
    „od 01.01.2027“ / „01.01.2027 – 31.12.2028“, štítek stavu přes
    `rowStateTag()`; ikona řádku podle typu;
  - filtry druh a typ, hledání v čísle, názvu, nájemci a názvech míst
    (přes `SearchCondition`); **žádné dolní taby**;
  - detail přes builder: hlavička z deklarace, Přehled (druh, typ,
    nájemce, platnost, interní poznámka), blok **Místa** jako `table`
    (místo, umístění = cesta z `PlaceTree`) s `open_detail` na viewer míst;
    přílohy na konci Přehledu; Přehled neopakuje hlavičku (#140 D26).
- Ikony v `frontend/src/icons.js` podle významu (např. `contract`,
  `contractOffice`, `contractFlat`, `contractRetail`, `contractStorage`,
  `contractParking`, `contractVirtualOffice`) — obecné názvy, žádné
  „buildings“ v jádru.
- Texty jen z katalogů (`viewerLabels`, `formTexts`, popisky sloupců
  z definic tabulek) — `ViewerSourceTextsTest`, `FormTextsCatalogTest`.
- Testy: kontrakt detailu (`ViewerDetailContractTest` vč. „Přehled
  neopakuje hlavičku“), řádek seznamu, formulář (filtr podle typu,
  skryté pole u `none`), `npm run build`, CSS kontroly.

### Commit 4 — dokumentace, ověření doplňku

> Stránky nápovědy šly už s commitem 3: `scripts/help-index.py` vyžaduje
> řádek katalogu pro každý viewer s `navSection`, jinak pre-commit
> zastaví commit s viewerem smluv.

- `modules/facility/buildings/README.md` (co modul dělá, tabulky, typy
  a jejich vlastnosti, číslování, validace, co přijde ve fázi 2 a 3).
- `help/budovy/smlouvy.md` (založení smlouvy, druhy, místa a povolené
  typy, číslo, stavy, varování o překryvu) a `help/budovy/nastaveni.md`
  (druhy smluv, vzorec čísla); `modules:` ve front matter jen pokud ho
  `scripts/help-index.py` už zná — jinak bez něj (filtr nápovědy podle
  modulů je otevřený bod #170). `help/co-shipard-umi.md`,
  `help/co-dnes-nejde.md` (fakturace smluv zatím ne), `python3
  scripts/help-index.py`.
- `docs/features.md` — oddíl Doplňky: Smlouvy (fáze 1) hotové.
- **Ověření D26** na volném zdroji dat (ne `realCopy`): `shpd-ds
  ds-module list` → `enable facility.buildings` → sekce Budovy, druhy
  v Nastavení, smlouva s místy, potvrzení, číslo, varování překryvu →
  `disable` → sekce zmizí, tabulky zůstanou → znovu `enable` → data
  zpět. Výsledek do #174; `tasks/addon-modules.md` doplnit, že ověření
  `enable` / `disable` proběhlo.
- `**Stav:**`, `python3 scripts/tasks-index.py`.

## Hotovo když

- [x] `facility.buildings` je doplněk, bez zapnutí ho žádný zdroj dat
      nemá a `install.base` na něm nezávisí
- [x] po `enable` jsou v Nastavení → Budovy výchozí druhy (D19)
      a v sidebaru sekce Budovy se Smlouvami
- [x] smlouvu jde založit, přidat jí víc míst jen povolených typů, potvrdit
      (číslo podle druhu a roku zahájení), opravit, ukončit a zrušit
- [ ] překryv míst ukáže varování, uložení nezastaví — **částečně**:
      varování se ukáže po Uložit, při potvrzení tlačítkem V pořádku se
      formulář zavře i s ním (viz Ověření D26 → Otevřené)
- [x] detail ukazuje místa s proklikem, seznam má filtry druh a typ, žádné
      dolní taby
- [ ] pole Místa ručně podle #176 D7 (první skutečné použití `multilookup`):
      hledání, šipky / Enter, Esc zavře jen nabídku, Backspace v prázdném
      vstupu odebere poslední štítek, vybraná místa se v nabídce
      nenabízejí, jen ke čtení (V pořádku) bez křížků, zalamování na
      telefonu, chyba z uložení u pole, dirty stav a Zrušit bez uložení
- [x] `disable` / `enable` ověřené naostro (D26)
- [x] nápověda, README modulu, `docs/features.md`, `**Stav:**`

## Ověření D26 (2026-11-27)

Volný zdroj dat `r3ow-2` založený jen pro ověření (`ds-create`, třída
`free`, bez datové sady; místa a nájemce doplněné pro scénář).

- `ds-module list` nabízí `facility.buildings` mezi doplňky; bez zapnutí
  není v navigaci ani v Nastavení nic z doplňku.
- `ds-module enable facility.buildings` — `ds-upgrade` ve stejném procesu
  založil 4 tabulky a provisioner 7 výchozích druhů
  (`[CREATE] contract kinds — created: 7, existing: 0`).
- Aplikace v prohlížeči (Playwright, uživatel `e2e`): sekce **Budovy**
  se **Smlouvami** v sidebaru, **Druhy smluv** v Nastavení → Budovy
  s ukázkou čísla. Smlouva typu Kancelář: nabídka pole Místa jen místnosti
  (podlaží, budova ani parkovací stání se nenabízejí), vybraná místa
  z nabídky mizí; potvrzení přidělilo `KA-2027-0001`, druhá smlouva
  `KA-2027-0002`. Typ Parkování nabízí jen stání a venkovní plochu. Po
  změně druhu zůstává nevyhovující místo ve formuláři a uložení ho ohlásí
  u pole Místa; potvrzení bez nájemce hlásí chybu u pole. Hledání podle
  čísla místnosti smlouvu najde, detail ukazuje tabulku Místa s umístěním
  a klik na místo otevře jeho detail.
- Varování o překryvu: po **Uložit** žlutý banner „Uloženo, ale
  zkontroluj: Kancelář 202 je i ve smlouvě KA-2027-0001 (od 01.01.2027)“,
  uložení ani potvrzení nezastaví.
- `ds-module disable` — sekce Budovy zmizela ze sidebaru i z Nastavení,
  tabulky a data zůstaly (7 druhů, 3 smlouvy, 4 vazby míst, 1 čítač).
- Druhé `enable` — nic se nezakládalo
  (`contract kinds — created: 0, existing: 7`), sekce i data zpět.
- `tests/Integration/Facility/ContractGatewayTest` a
  `ViewerDetailContractTest` nad tímto zdrojem prošly (seedovaný druh se
  jmenuje jako jeho typ — výjimka `OVERVIEW_REPEAT_ALLOWED` jako u druhů
  míst).

### Otevřené

- **Varování při potvrzení není vidět.** Tlačítko V pořádku má
  `closeForm`, `FormEditor` po úspěšném přechodu stavu formulář zavře
  i s bannerem varování — obecné chování jádra, stejně mizí varování
  ostatních agend (bankovní spojení dodavatele u přijaté faktury). Úprava
  (formulář s varováním po přechodu stavu nezavírat, jako dialog
  sub-tabulky) je změna jádra s dopadem na ostatní agendy — čeká na
  rozhodnutí. Nápověda zatím radí dát nejdřív Uložit.
- **Ruční kontrola pole Místa podle #176 D7** (klávesnice, telefon,
  dirty stav) — dělá člověk.
- Upřesnění proti zadání: název smlouvy je povinný (D22 ho nevyjmenovává,
  řádek seznamu by bez něj neměl titulek); vzorec druhu s ročním restartem
  musí obsahovat rok a kód druhu je unikátní (číslo smlouvy je unikátní);
  seedové kódy druhů jsou vlastnost typu (`seedCode`), ne mapa v kódu;
  fyzicky smazat jde koncept i z koše.

## Pasti

- **Jádro o doplňku neví.** Nic v `src/`, `frontend/` (kromě obecných
  ikon) ani v modulech jádra nesmí jmenovat `facility.buildings` ani jeho
  tabulky. Filtr `types` v `PlacesLookup` je obecný (D23).
- **Druh se mění jen v Konceptu** — číslo, rok čítače a typ jsou
  odvozené od druhu; změna druhu potvrzené smlouvy by rozbila číslování.
- **Překryv počítá jen živé smlouvy** (V pořádku, V opravě) a vynechá
  samu sebe; koncepty, ukončené, zrušené a smazané ne. Ukončení `NULL`
  = neomezeno na obou stranách porovnání.
- **Varování ≠ chyba.** Překryv nesmí zablokovat uložení ani potvrzení
  (sdílená místa, přechod nájemců).
- **Provisioner pod `skipProvisioning`** — výchozí druhy musí vzniknout
  i v datové sadě a po `ds-reset`; zapojení přes `provisioners` modulu
  (D28), nikdy metodou v `DsUpgradeCommand`.
- **Ověření jen na volném zdroji dat** — zdroje `realCopy` se
  `ds-module enable` nedotýkat (zápis do `main.json` i `ds-upgrade` jsou
  mutace).
