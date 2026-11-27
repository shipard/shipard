# Modul: Budovy (facility.buildings)

Doplněk pro správu budovy (#174). Fáze 1 je evidence **nájemních smluv
s místy**: druhy smluv s typem a číslováním, smlouva s nájemcem, dobou
platnosti a jedním nebo více místy z číselníku míst (`base.places`).
Fáze 2 je **fakturace smluv**: smlouvy jsou zdroj periodické fakturace
(`economy.periodicInvoicing`, #175) s předpisem na druhu a smlouvě, řádky
předpisu a šablonou řádků na druhu. Napojení na Místa přijde v další fázi
(viz [Co přijde](#co-přijde)).

Modul je **doplněk** (`"kind": "addon"`, `docs/modules.md` §9): není součástí
`install.base`, jádro o něm neví a ve zdroji dat se zapíná

```bash
shpd-ds ds-module enable facility.buildings
```

Vypnutí (`ds-module disable`) skryje sekce a viewery; tabulky i data
zůstávají a po novém zapnutí jsou zpět. Uživatelská nápověda:
[`help/budovy/`](../../../help/budovy/smlouvy.md).

## Závislosti

- `core.system`, `core.attachments`
- `base.persons` — nájemce
- `base.places` — místa smlouvy (`PlacesLookup` s filtrem `types`, `PlaceTree`)
- `economy.periodicInvoicing` — engine fakturace: rozhraní zdroje, společný
  základ řádku předpisu (`Rows\*`), záložka Fakturace, akce záznamu
- `docs.core`, `economy.codebooks` — řady dokladů, typy dokladů, střediska,
  bankovní účty

Doplněk tak předpokládá zdroj dat s účetními moduly (`install.base` je má).

## Tabulky

| Tabulka | Popis |
|---|---|
| [facility_buildings_contract_kinds](tables/facility_buildings_contract_kinds.jsonc) | Druhy smluv — typ, kód, vzorec čísla, restart čítače; archivní stavy |
| [facility_buildings_number_counters](tables/facility_buildings_number_counters.jsonc) | Čítače čísel smluv per druh a rok (rok `NULL` = průběžně) |
| [facility_buildings_contracts](tables/facility_buildings_contracts.jsonc) | Smlouvy — druh, typ, číslo, název, nájemce, zahájení, ukončení; `exchangeKey` = `number` |
| [facility_buildings_contract_places](tables/facility_buildings_contract_places.jsonc) | Místa smlouvy — child tabulka smlouvy (`dataKey` `places`), pořadí `order_pos` |
| [facility_buildings_contract_rows](tables/facility_buildings_contract_rows.jsonc) | Řádky předpisu smlouvy (tvar #175 U2) — **sub-tabulka**, ne child tabulka |
| [facility_buildings_contract_kind_rows](tables/facility_buildings_contract_kind_rows.jsonc) | Šablona řádků na druhu — tvar řádku předpisu bez platnosti |
| [facility_buildings_contract_periods](tables/facility_buildings_contract_periods.jsonc) | Evidence vyfakturovaných období smlouvy (tvar předepisuje engine), unikátní smlouva × začátek období |

Druh navíc nese výchozí hodnoty fakturace (`inv_doc_type`,
`inv_number_series`, `inv_due_days`, `inv_timing`, `inv_vat_mode`,
`inv_payment_method`, `inv_bank_account`), smlouva `inv_periodicity`,
`inv_from`, `inv_doc_text`, `currency`, `payment_reference`, `cost_center`
a přepisy těch sedmi (prázdné = z druhu). Definice sloupců jsou shodné se
Zakázkami — hlídá `ContractInvoicingModelTest`.

## Typy smluv

Typ je systémový (cfgItem `facility.buildings.contractTypes`), druh je
uživatelský číselník, který typ nese. **Kód se nikdy neptá na klíč typu** —
jen na vlastnosti přes `ContractTypes`:

| Vlastnost | Význam |
|---|---|
| `icon` | ikona řádku seznamu a druhu (`frontend/src/icons.js`, klíče `contract-*`) |
| `places` | `required` (místa povinná při potvrzení) / `optional` / `none` (pole skryté, místa jsou chyba) |
| `placeTypes` | povolené typy míst (`base.places.types`); `null` = libovolné |
| `seedCode` | kód výchozího druhu; typ bez něj provisioner nezakládá |
| `order` | pořadí v nabídce |

| Klíč | Název | Místa | Povolené typy míst | Kód |
|---|---|---|---|---|
| `office` | Kancelář | povinná | místnost, část budovy | KA |
| `flat` | Byt | povinná | část budovy, místnost | BY |
| `retail` | Obchodní prostor | povinná | část budovy, místnost, venkovní plocha | OP |
| `storage` | Sklad | povinná | místnost, část budovy, venkovní plocha | SK |
| `parking` | Parkování | povinná | místo, venkovní plocha | PA |
| `virtualOffice` | Virtuální kancelář | povinná | budova, areál | VK |
| `other` | Ostatní | nepovinná | libovolné | OS |

## Druh smlouvy a číslování

`ContractKindDocument`: název, typ, poznámka a číslování — kód (`%C`),
vzorec čísla a restart čítače (`continuous` / `yearly`).

- Vzorec hlídá `NumberPattern::validate()` (`%C` vyžaduje kód); pořadí
  `%3`–`%6` je povinné a roční restart vyžaduje rok (`%y` / `%Y`) — číslo
  smlouvy je unikátní v celém zdroji dat. Kód druhu je unikátní mezi druhy
  mimo koš.
- Typ jde měnit jen v Konceptu a jen dokud druh nepoužívá žádná smlouva.
- Druh, který má smlouvu mimo koš, nejde do koše; archivovat jde vždy.
- **Výchozí druhy** zakládá `ContractKindsProvisioner` přes bod rozšíření
  `provisioners` (#174 D28): jeden druh na typ se `seedCode`, vzorec
  `%C-%Y-%4`, restart ročně. Druh pozná podle `system_code` (= klíč typu)
  v jakémkoli stavu — existující nepřepisuje ani nevrací. Běží v každém
  `ds-upgrade`, i pod `skipProvisioning` a po `ds-reset`.

Číslo přidělí smlouvě přechod z Konceptu do V pořádku: `SequenceCounter`
nad `facility_buildings_number_counters`, čítač per **druh a kalendářní rok
zahájení** (u `continuous` bez roku). `%y` / `%Y` je rok zahájení, ne rok
potvrzení. Převzaté číslo zůstává a čítač se nečerpá.

## Smlouva

`ContractDocument` — vlastní sada stavů `facility.buildings.docStates`
(Koncept, V pořádku, V opravě, Ukončená, Zrušená, Smazaná), přechody přes
dokument (`stateTransitionsRunDocumentHooks`).

| Kdy | Pravidlo | Kód chyby |
|---|---|---|
| vždy | název a druh jsou povinné | `required` |
| vždy | druh jde měnit jen v Konceptu; typ se denormalizuje z druhu | `kindLocked` |
| vždy | ukončení ≥ zahájení | `invalid_range` |
| vždy | místo jen povoleného typu | `place_type` |
| vždy | žádné místo dvakrát, žádné v koši | `duplicate`, `invalid_state` |
| vždy | typ `none` nemá žádná místa | `not_allowed` |
| potvrzení | nájemce, zahájení, u typu `required` aspoň jedno místo | `required` |

Chyby míst jdou na `places.<index>.place` se jménem místa — prvek
`multilookup` je ukáže u pole Místa.

**Překryv míst je varování, ne chyba** (`overlap`, D7): místo, které je
v jiné smlouvě V pořádku nebo V opravě s překrývající se dobou. Prázdné
ukončení = doba neurčitá na obou stranách; koncepty, ukončené, zrušené
a smazané se nepočítají; jeden nález na místo. Varování putuje v úspěšné
odpovědi uložení (`warnings[]`) a uložení ani potvrzení nezastaví.

Přechod do Ukončená / Zrušená doplní prázdné ukončení dneškem. Fyzicky
smazat jde jen koncept (i z koše); s ním odejdou vazby míst a řádky
předpisu.

## Fakturace smluv (fáze 2, #174 D29–D37)

Smlouva se fakturuje enginem periodické fakturace
([README enginu](../../economy/periodicInvoicing/README.md)); doplněk dodává
předpis, řádky a zdroj. Engine o doplňku neví.

### Předpis

Efektivní hodnoty skládá jen `InvoicingSettingsResolver` (smlouva → druh →
výchozí hodnota enginu). **Periodicita je nepovinná** (D36) — smlouva bez
ní se nefakturuje a nic z fakturace se u ní nevyžaduje.

| Kdy | Pravidlo | Kód chyby |
|---|---|---|
| vždy | známá periodicita, typ dokladu, okamžik fakturace; splatnost ≥ 0 | `invalid` |
| vždy | řada dokladů existuje a patří efektivnímu typu dokladu | `not_found`, `series_type_mismatch` |
| vždy | prázdné `inv_from` se doplní zahájením; dřív než zahájení být nesmí | `before_start` |
| vždy | prázdná měna se doplní domácí měnou zdroje dat | — |
| potvrzení s periodicitou | `inv_from`, efektivní typ dokladu a řada dokladů | `required` |
| potvrzení s periodicitou | aspoň jeden řádek předpisu | `_form` / `rows_required` |
| potvrzení s periodicitou | platnost každého řádku uvnitř platnosti smlouvy | `_form` / `row_outside_contract` |

Doplnění `inv_from` je ve `validate()`, ne v `beforeSave()` — validace běží
dřív a potvrzení by jinak spadlo na povinné pole. Druh hlídá u svých
výchozích hodnot jen tvar (`ContractKindDocument`), povinné nejsou.

### Řádky a šablona

Řádky předpisu (`ContractRowDocument`, `ContractRowsForm`) i řádky šablony
na druhu (`ContractKindRowDocument`, `ContractKindRowsForm`) dědí ze
společného základu enginu `Rows\AbstractPrescriptionRow*` a dodávají jen
rodiče. Řádek smlouvy navíc hlídá platnost uvnitř platnosti smlouvy
(`outside_contract` na `valid_from` / `valid_to`); tutéž kontrolu dělá
smlouva při potvrzení, protože její platnost se může změnit později.

**Šablona řádků (D32)** se do smlouvy zkopíruje, když jde o **vložení nebo
změnu druhu**, smlouva je Koncept a nemá žádné řádky. Podmínka „nemá
řádky“ sama nestačí — uživatel, který řádky smaže a smlouvu uloží, je
znovu dostat nesmí. `afterPersist()` originál nedostává, proto si dokument
vložení / změnu druhu poznamená v `beforeSave()`; kopie je jeden
`INSERT … SELECT` v transakci uložení (`copyTemplateRows()`), pořadí
šablony, platnost prázdná. Šablona nese i výchozí kód DPH řádků (D33) —
typ smlouvy žádnou takovou vlastnost nemá.

**Dva mechanismy na jednom formuláři:** místa jsou `multilookup` nad child
tabulkou (ukládá je uložení smlouvy), řádky sub-tabulka (ukládá je dialog
řádku) — uložení hlavičky na řádky nesahá.

**Zámky** (`documentLockProviders`, potomci
`Rows\AbstractPrescriptionRowLockProvider`): řádky smlouvy ve stavu
s `readOnly` (`ContractRowLockProvider`) a šablona druhu ve stavu
s `readOnly` sady `core.system.docStatesArchive`
(`ContractKindRowLockProvider`) jsou jen ke čtení — úprava přes V opravě.
Jádro providery vynucuje i na generickém CRUD.

### Zdroj fakturace

`ContractInvoicingSource` (id `buildingContracts`, registrace
`periodicInvoicingSources`): kandidáti jsou smlouvy **V pořádku**
s periodicitou a `inv_from`; záznam je řádek smlouvy 1 : 1 (`customer` =
nájemce, `date_end` = ukončení — období začínající po něm se nevystaví);
řádky `order_pos`, `id`; dimenze dokladu jen `costCenter`; sekce badge
upozornění `buildings`. Text dokladu jako u zakázek (prázdná šablona =
název a `{období}`), proměnná pro místa není (D37).

Akce Vystavit dlužná období / Přegenerovat / Obnovit jsou `recordActions`
nad `facility_buildings_contracts` se třídami enginu (globální id
`facility.buildings.issueDue` / `.regenerate` / `.restore`); záložku
Fakturace zapojuje `ContractsViewer` přes `InvoicingDetailTab` jen u smlouvy
s periodicitou.

```bash
shpd-ds periodic-invoicing-run --source=buildingContracts [--record=<číslo smlouvy>] [--dry-run]
```

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [ContractTypes.php](src/ContractTypes.php) | Jediné čtení cfgItem typů smluv — vlastnosti typu |
| [ContractKindDocument.php](src/ContractKindDocument.php) | Druh smlouvy — vzorec čísla, zámek typu, koš použitého druhu |
| [ContractKindsProvisioner.php](src/ContractKindsProvisioner.php) | Výchozí druhy (`ModuleProvisioner`) |
| [ContractDocument.php](src/ContractDocument.php) | Smlouva — validace, místa podle typu, překryv, číslo, stavy; fakturační předpis a kopie šablony řádků |
| [ContractRowDocument.php](src/ContractRowDocument.php) / [ContractRowsForm.php](src/ContractRowsForm.php) | Řádek předpisu smlouvy — platnost uvnitř platnosti smlouvy; sub-formulář tabu Řádky |
| [ContractKindRowDocument.php](src/ContractKindRowDocument.php) / [ContractKindRowsForm.php](src/ContractKindRowsForm.php) | Řádek šablony na druhu (bez platnosti); sub-formulář tabu Šablona řádků |
| [ContractRowLockProvider.php](src/ContractRowLockProvider.php) / [ContractKindRowLockProvider.php](src/ContractKindRowLockProvider.php) | Zámek řádků podle stavu smlouvy / šablony podle stavu druhu |
| [ContractInvoicingSource.php](src/ContractInvoicingSource.php) | Zdroj periodické fakturace `buildingContracts` |
| [ContractsLookup.php](src/ContractsLookup.php) | Lookup smluv (číslo, název, nájemce) pro odkazy z dalších agend |
| [ContractKindsViewer.php](src/ContractKindsViewer.php) / [ContractKindsForm.php](src/ContractKindsForm.php) | Druhy smluv v Nastavení → Budovy; ukázka čísla podle vzorce; výchozí hodnoty fakturace a tab Šablona řádků |
| [ContractsViewer.php](src/ContractsViewer.php) | Smlouvy v sekci Budovy — filtry druh a typ, bez dolních tabů, detail s tabulkou míst a záložkou Fakturace |
| [ContractsForm.php](src/ContractsForm.php) | Formulář smlouvy — pole Místa (`multilookup`) podle typu druhu, sekce Fakturace a přepisy hodnot druhu, tab Řádky |
| [BuildingsViewerBase.php](src/BuildingsViewerBase.php) | Společný základ viewerů (texty z katalogu, typy, stavy) |

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `facility.buildings.contractTypes` | [config/contractTypes.jsonc](config/contractTypes.jsonc) | Typy smluv a jejich vlastnosti |
| `facility.buildings.docStates` | [config/docStates.jsonc](config/docStates.jsonc) | Stavy smlouvy (sada jako u zakázek, texty v rodě smlouvy) |
| `facility.buildings.resetScopes` | [config/resetScopes.jsonc](config/resetScopes.jsonc) | Restart čítače: průběžně / ročně |
| `facility.buildings.viewerLabels` | [config/viewerLabels.jsonc](config/viewerLabels.jsonc) | Texty viewerů |
| `facility.buildings.formTexts` | [config/formTexts.jsonc](config/formTexts.jsonc) | Texty prvků formulářů |

Vlastní sekce: `navSections` `buildings` (sidebar, mezi Zakázkami
a Majetkem) a `settingsSections` `buildings` (Nastavení).

## Testy

- `tests/Unit/Module/Facility/Buildings/` — typy, druh, provisioner,
  smlouva, formuláře, viewery a deklarace modulu (včetně pravidla, že jádro
  doplněk nejmenuje).
  Fáze 2: `ContractInvoicingModelTest` (šablona, D36, zámky, tvar
  tabulek), `ContractInvoicingSourceTest` (zdroj a běh enginu se dvěma
  skutečnými zdroji — Zakázky + Smlouvy), `ContractInvoicingUiTest`
  (formuláře, záložka Fakturace).
- `tests/Integration/Facility/ContractGatewayTest.php`
  a `ContractInvoicingGatewayTest.php` — smlouva přes skutečný
  `TableGateway` (místa, číslo; kopie šablony `INSERT … SELECT`, potvrzení
  s periodicitou, zámky řádků a šablony); běží jen nad volným zdrojem dat
  se zapnutým doplňkem (`SHIPARD_INTEGRATION_DS_PATH`), jinak se přeskočí.

## Co přijde

- **Fáze 3 — napojení na Místa** (#174 D8): záložka Smlouvy v detailu místa
  (`detailExtensions`) a štítek obsazenosti ve stromu míst (`rowDecorators`).
- Později (#174 D38): přehled rezervací, jistota, zálohy na služby
  a vyúčtování, měřiče, indexace nájmu, import smluv, demo data Budov.

Známé omezení: odznak sekce Budovy se nerozsvítí, když má check
upozornění fakturace víc než tři aktivní nálezy a ty patří zároveň
zakázkám i smlouvám — skupinová karta feedu pak sekci nenese
([README enginu](../../economy/periodicInvoicing/README.md) → Upozornění).
