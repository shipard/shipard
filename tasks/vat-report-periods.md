# Task: Instance daňových tvrzení (`economy_vat_report_periods`) místo období DPH

**Stav:** hotovo — implementace 2026-09-03 (5 commitů: tabulka + eventy jádra, přiřazení + on-demand, reporty nad instancemi, cron + zrušení `vat_periods`, docs); E2E na dev DS prošlo; invarianta KH ⊂ přiznání ověřená 2026-11-17 na kopii reálných dat čtvrtletního plátce `e8w1-i` místo alfy (vypuštěna). Uzavřeno s #55.
**Issue:** #55 — komentář „Revize návrhu po fázi 1", rozhodnutí D7–D13
**Návaznost:** po `tasks/vat-rename.md` (staví na `economy.vat`, `cs_/rs_period_kind`).
Import instancí a reimport řeší `old_shipard: …/tasks/30-vat-report-periods-import.md`
— nasazení této (nové) strany předchází staré straně.

## Kontext

Mřížka `economy_codebooks_vat_periods` (jedna, dle periodicity přiznání) neumí
rozdílné frekvence KH/RS, změny periodicity v čase ani vznik/zánik plátcovství
uprostřed období — a picker i výběr dokladů na ní stojí. Nahrazujeme ji
**instancemi tvrzení**: per typ výstupu jeden záznam s rozsahem s denní
přesností, uživatelsky editovatelný. Pravda je v datech, ne v kalendářním
odhadu. Detailní odůvodnění a rozhodnutí D7–D13 v issue #55.

Před implementací **přečti**:

- issue #55 vč. komentáře s revizí (gh issue view 55 --repo shipard/shpd --comments)
- `docs/table-definitions.md`, `docs/modules.md` — definice tabulky, provisioner
- `docs/document-system.md` + `modules/docs/core/src/DocDocument.php` — kde se
  dnes přiřazuje `vat_period` při uložení; `documentEventHandlers`
  (`docs/docs-mvp.md`) — mechanismus pro logiku economy.vat nad doklady
- `modules/economy/vat/src/VatDocumentSelection.php`, `Reports/*` — dnešní
  selekce a parametry reportů
- `src/Core/Reports/DbVatPeriodProvider.php`, `VatPeriodRange.php` — picker
- `docs/ds-setup.md` (seed období v setup wizardu), `docs/cli.md`,
  `docs/services.md` (cron)
- `modules/economy/codebooks/tables/economy_codebooks_vat_periods.*` — rušená
  tabulka a její dosavadní odkazy (grep `vat_periods`)

## Co vznikne

### 1. Tabulka `economy_vat_report_periods` (modul `economy.vat`)

Sloupce: `vat_registration` (FK), `report_type` (enum `return` / `cs` / `rs`),
`name`, `date_begin`, `date_end`, `locked` (default 0), standardní docStates.
Indexy: (registrace, typ, date_begin). Tabulka je editovatelná uživatelem
(FormEditor + viewer dle konvencí, česky „Daňová tvrzení").

**Validace při uložení:** v rámci (registrace, typ) se rozsahy nesmí překrývat
(tvrdá chyba); díra mezi sousedními instancemi = varování, ne chyba.

**Guardy zrušení/smazání:** nelze zrušit instanci (a) zamčenou, (b) na kterou
odkazuje podání (tabulky podání zatím neexistují — guard připrav jako bod
rozšíření, komentář), (c) s přiřazenými doklady (`vat_period`/`cs_period`/
`rs_period`) — uživatel musí nejdřív doklady přepřiřadit (typicky: založí
správné instance a spustí přepočet, doklady se chytí jich).

**Zámek:** sloupec existuje pro všechny typy; vynucení (gate mutací dokladů)
je Fáze 4 dle issue #55 — v této fázi bez vynucení.

### 2. Sloupce na `docs_core_heads` (D13)

`cs_period`, `rs_period` — FK na instance, NULL = doklad do daného hlášení
nespadá. Spolu se stávajícím `vat_period` (nově FK na instanci typu `return`)
jde o trojici „momentální zařazení" — skutečná historie bude v podáních.
Ručně editovatelné (přesun dokladu mezi měsíci KH); že FK míří na instanci
správného typu, hlídá Document vrstva při uložení.

### 3. Save-time přiřazení (D8, D9)

Logika v `economy.vat` (documentEventHandler / hook uložení — mechanismus
zvol dle toho, kde dnes probíhá přiřazení `vat_period`, a přesuň/rozšiř
konzistentně; docs.core nesmí záviset na economy.vat):

- `vat_period`: instance typu `return` téže registrace, jejíž rozsah obsahuje
  daňové datum dokladu (zachovej dnešní pravidlo výběru data).
- `cs_period` / `rs_period`: jen má-li doklad ≥1 řádek `vat_recap`, jehož
  mapování (`economy.vat.reports.cz`) má `kh` resp. `sh` ≠ null; instance
  typu `cs`/`rs`, jejíž rozsah obsahuje **clamped efektivní datum** =
  `COALESCE(vat_dppd, vat_duzp)` oříznuté do `[date_begin, date_end]`
  instance `vat_period` dokladu. Jinak NULL.
- **On-demand koncept (D9):** chybí-li odpovídající instance, vytvoří se
  koncept dle `tax/cs/rs_period_kind` registrace + alert (vzor měkkých chyb).
- **Přepočet:** změna `date_begin`/`date_end` instance spustí přepočet
  přiřazení dotčených dokladů (dávka: doklady instance + doklady, jejichž
  datum nově spadá do rozsahu). Ruční editace pole na dokladu se přepočtem
  nepřepisuje, dokud se doklad znovu neuloží — dokumentuj.
- Import mód: přiřazení běží stejně (žádná výjimka) — věrnost zajišťují
  reálné rozsahy importovaných instancí (D12).

### 4. Selekce a reporty (D11)

- `VatDocumentSelection`: `WHERE h.<xx>_period = <instance>` dle typu reportu
  — join na periody a clamp v SQL odchází (pravidlo žije v přiřazení).
- Parametry builderů: `--period=<id instance>` (registrace se odvodí
  z instance); UI picker = instance příslušného typu, filtrované rokem
  (rok = pomocný parametr roletky, default aktuální). `VatPeriodRange` /
  `DbVatPeriodProvider` přepiš na instance (`DbReportPeriodProvider`).
- Křížová kontrola proti deníku beze změny principu (stejný výběr dokladů
  na obou stranách).

### 5. Generování a rušení `vat_periods`

- **Cron (denně):** pro aktivní registrace zajisti existenci instancí všech
  tří typů pokrývajících dnešek a zítřek (dle defaultů periodicit). Zapoj
  do standardního cron mechanismu (respektuje stavy DS).
- **Setup wizard:** při založení registrace seed instancí běžného období
  (náhrada dnešního seedu `vat_periods` v ds-setup).
- **Zrušení `economy_codebooks_vat_periods`:** tabulka, provisioner seed,
  viewer/editor, všechny odkazy (grep `vat_periods` a `vat_period_kind`
  mimo registraci). `DsUpgrade`: drop tabulky; migrovaná data řeší ds-reset
  + re-import (task 30), jiné DS s daty neexistují (ověř hosting DS).
- `docs_core_heads.vat_period` FK cíl se mění — ověř, jak provisioner
  zachází se změnou reference (pre-produkce: drop+add přijatelné).

### 6. Testy

- Přiřazení: clamp (DPPD mimo rozsah vlastního přiznání), membership dle
  mapování (doklad bez KH kódů → `cs_period` NULL), on-demand vznik konceptu,
  import mód.
- **Partition invarianta:** sjednocení dokladů měsíčních `cs` instancí
  čtvrtletí = doklady `return` instance, beze zbytku a bez průniku.
- Validace překryvů, guardy zrušení, přepočet po změně rozsahu.
- Selekce: DP3/KH/RS nad instancemi; degenerovaný případ (KH rozsah =
  rozsah přiznání) dává stejná čísla jako dřívější containment.

### 7. Dokumentace

`modules/economy/vat/docs/README.md` (model instancí, přiřazení, invarianta),
úpravy `docs/ds-setup.md`, `docs/reports.md` §14, tabulkové md soubory.

## Mimo scope

- Tabulky podání a snapshoty (Fáze 2 — podání se věší na instance).
- Vynucení zámku (Fáze 4), OSS, parametr registrace v UI reportů.
- Stará strana importu a reimport (task 30).

## Commity

1. Tabulka + validace + guardy + provisioner.
2. Sloupce heads + save-time přiřazení + on-demand + přepočet.
3. Selekce + parametry reportů + picker/provider.
4. Cron + setup seed + zrušení `vat_periods`.
5. Testy doplňkové + dokumentace.

## Hotovo když

- [x] Testy zelené vč. partition invarianty a degenerovaného případu
      (`VatPeriodAssignerTest`, `VatPeriodValidatorTest`).
- [x] Registrace → seed instancí, doklad se při uložení přiřadí do všech
      relevantních instancí, reporty běží nad `--period` — ověřeno na dev DS
      4l3j přes `ds-upgrade` + skript nad `TableGateway` (čistý `ds-create`
      neběžel, cesta je totožná).
- [x] `grep -rn "vat_periods"` nevrací nic mimo tasks/ a archiv.
- [x] Picker KH na čtvrtletním plátci nabízí měsíce (instance), DP3 čtvrtletí
      — pokryto `testReturnAndCsReportsUseInstancesOfTheirOwnType`;
      UI v používání na dev serveru.
- [x] Invarianta na reálných datech čtvrtletního plátce (původně qrce na alfě
      po re-importu tasku 30; alfa vypuštěna 2026-11-17): na kopii `e8w1-i`
      leží za 2023–2026 všech 2 162 dokladů z KH ve čtvrtletí svého přiznání,
      žádný doklad KH není bez přiznání a každý měsíc KH spadá celý do jednoho
      čtvrtletí. Shoda řádků s podaným přiznáním → #158 (Q1 i Q2/2026 hlásí
      `imported_row_mismatch`).
