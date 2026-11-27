# Modul: Budovy (facility.buildings)

Doplněk pro správu budovy (#174). Fáze 1 je evidence **nájemních smluv
s místy**: druhy smluv s typem a číslováním, smlouva s nájemcem, dobou
platnosti a jedním nebo více místy z číselníku míst (`base.places`).
Fakturace smluv a napojení na Místa přijdou v dalších fázích (viz
[Co přijde](#co-přijde)).

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

## Tabulky

| Tabulka | Popis |
|---|---|
| [facility_buildings_contract_kinds](tables/facility_buildings_contract_kinds.jsonc) | Druhy smluv — typ, kód, vzorec čísla, restart čítače; archivní stavy |
| [facility_buildings_number_counters](tables/facility_buildings_number_counters.jsonc) | Čítače čísel smluv per druh a rok (rok `NULL` = průběžně) |
| [facility_buildings_contracts](tables/facility_buildings_contracts.jsonc) | Smlouvy — druh, typ, číslo, název, nájemce, zahájení, ukončení; `exchangeKey` = `number` |
| [facility_buildings_contract_places](tables/facility_buildings_contract_places.jsonc) | Místa smlouvy — child tabulka smlouvy (`dataKey` `places`), pořadí `order_pos` |

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
smazat jde jen koncept (i z koše); s ním odejdou vazby míst.

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [ContractTypes.php](src/ContractTypes.php) | Jediné čtení cfgItem typů smluv — vlastnosti typu |
| [ContractKindDocument.php](src/ContractKindDocument.php) | Druh smlouvy — vzorec čísla, zámek typu, koš použitého druhu |
| [ContractKindsProvisioner.php](src/ContractKindsProvisioner.php) | Výchozí druhy (`ModuleProvisioner`) |
| [ContractDocument.php](src/ContractDocument.php) | Smlouva — validace, místa podle typu, překryv, číslo, stavy |
| [ContractsLookup.php](src/ContractsLookup.php) | Lookup smluv (číslo, název, nájemce) pro odkazy z dalších agend |
| [ContractKindsViewer.php](src/ContractKindsViewer.php) / [ContractKindsForm.php](src/ContractKindsForm.php) | Druhy smluv v Nastavení → Budovy; ukázka čísla podle vzorce |
| [ContractsViewer.php](src/ContractsViewer.php) | Smlouvy v sekci Budovy — filtry druh a typ, bez dolních tabů, detail s tabulkou míst |
| [ContractsForm.php](src/ContractsForm.php) | Formulář smlouvy — pole Místa (`multilookup`) podle typu druhu |
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
- `tests/Integration/Facility/ContractGatewayTest.php` — smlouva přes
  skutečný `TableGateway`; běží jen nad volným zdrojem dat se zapnutým
  doplňkem (`SHIPARD_INTEGRATION_DS_PATH`), jinak se přeskočí.

## Co přijde

- **Fáze 2 — fakturace** (#174 D16, zdroj periodické fakturace #175):
  nastavení fakturace na druhu a smlouvě, řádky předpisu a šablona řádků
  na druhu, evidence období, záložka Fakturace; měna, VS a středisko.
- **Fáze 3 — napojení na Místa** (#174 D8): záložka Smlouvy v detailu místa
  (`detailExtensions`) a štítek obsazenosti ve stromu míst (`rowDecorators`).
- Později: přehled rezervací, jistota, zálohy na služby a vyúčtování,
  měřiče, indexace nájmu, import smluv.
