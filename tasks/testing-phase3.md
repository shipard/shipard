# Testování F3: ověření zaúčtování a DPH nad datovou sadou

**Stav:** částečně — implementace hotová 2026-10-15 (nálezy A–E opravené, D33); kontroly 1–5 `ok` u plátce a neplátce, snímky schválené s výhradou účetní revize (#123); zbývá snímek spolku (čeká na #122) a alfa

## Kontext / Cíl

F2 (`tasks/testing-phase2.md`) dala tři datové sady (`tests/datasets/cz/`),
ve kterých je každý doklad korpusu došlá zpráva s ideální analýzou
a otevřeným návrhem. F3 je **účetní polovina** testování (#103 D3): vezme
naseedovaný zdroj dat, použije všechny návrhy jako potvrzené doklady
a ověří, že:

1. hotový doklad odpovídá návrhu (věrnost apply),
2. Použít nezaložilo žádnou položku a každý řádek má účet,
3. přiznání DPH a kontrolní hlášení odpovídají očekávání spočtenému
   nezávisle na Shipardu (D28),
4. náklady dokladů odpovídají očekávání — u neplátce včetně DPH (#97),
5. účetní deník odpovídá schválenému snímku (D29).

Výsledkem je jeden příkaz `shpd-ds dataset-verify` (D26) s lidským
i JSON reportem.

## Návaznost

- **#103** D26–D30 (oddíl na konci); D3 (dvě poloviny), D5 (nezávislý výpočet)
- **`tasks/ds-setup-12-accounting-items-tag-coverage.md`** — musí být hotový
  dřív: bez něj Použít u `goods.stock` a ve spolku zakládá položky podle řádků
- **#121** kurzy — do vyřešení se doklady v cizí měně přeskočí (D30)
- `tasks/testing-phase1.md`, `tasks/testing-phase2.md`, `docs/test-corpus.md`
- F4 (evaluace AI) použije porovnávač z tohoto tasku

## Před implementací přečti

- `docs/test-corpus.md`, `docs/datasets.md`
- `modules/core/mail/src/MessageProposalApplier.php` — sdílené jádro Použít
  (REST i MCP jedou přes ni)
- `docs/exchange-format.md` → `applyOptions` (`targetDocState`,
  `autoCreateMode`, `createMissingEntities`, `rejectOnIssues`), §8.4
- `modules/core/exchange/src/Export/DocumentExporter.php`
- `docs/cli.md` → `report-run`, `report-diff`; `docs/reports.md` §7.4, §13
- `modules/economy/vat/config/vat-reports-cz.jsonc` — řádky přiznání
- `src/Command/DataSource/DatasetSeedCommand.php` — vzor příkazu nad sadou

## Rozsah

**V rozsahu:** příkaz `dataset-verify`; porovnávač canonicalu v PHP;
očekávání DPH a nákladů per doklad (generátor korpusu); schválený snímek
deníku; ověření na třech dev zdrojích dat; dokumentace.

**Mimo rozsah:** evaluace AI (F4); kurzy (#121); registr sad a založení
zdroje dat ze sady jedním příkazem (F5); UI (F6); slovenské profily.

## Příkaz `dataset-verify` (D26)

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds dataset-verify tests/datasets/cz/vat-payer            # apply + kontroly + report
shpd-ds dataset-verify tests/datasets/cz/vat-payer --no-apply # jen kontroly
shpd-ds dataset-verify … --json                               # report jako JSON
shpd-ds dataset-verify … --write-ledger-snapshot=deník.json   # zapsat aktuální snímek deníku
```

**Preflight (nic se nemění, při chybě exit 2):**

- server v režimu `production` → odmítnout (příkaz mutuje);
- `country` manifestu = země zdroje dat;
- každá zpráva ve zdroji dat má `messageId` ze sady a každý doklad má
  `source_message` na zprávu ze sady — jinak odmítnout. Zdroj dat
  s jakýmikoli jinými daty (ostrá data, ruční pokusy) příkaz nepustí,
  žádný `--force`.

**Apply** (bez `--no-apply`): každá zpráva s otevřeným návrhem →
`MessageProposalApplier::apply()` s `applyOptions`:
`targetDocState` 40, `autoCreateMode` `liberal`, `createMissingEntities`
`true`, `rejectOnIssues` `["error"]`. Zprávy v pořadí `receivedAt`
(`0V01` založí dodavatele, `0V02` ho najde). Doklad v cizí měně se
přeskočí s důvodem „kurz — #121“ (D30). Selhání apply = výsledek kontroly,
ne pád příkazu.

**Kontroly** běží nad všemi doklady ze sady (i z dřívějšího běhu), takže
druhý běh bez otevřených návrhů dá stejný výsledek.

**Výstup:** per kontrola stav `ok` / `rozdíl` / `přeskočeno` (s důvodem)
a seznam rozdílů; na konci souhrn. `--json` = stejný obsah strojově.
Exit: 0 vše v pořádku (přeskočené nevadí), 1 rozdíly, 2 chyba vstupu
nebo odmítnutý preflight.

## Kontrola 1 — věrnost apply (porovnávač, D27)

Export potvrzeného dokladu (`DocumentExporter` → canonical) ≟ `canonicalJson`
použitého návrhu na polích papírové projekce.

**Porovnávač** (`modules/core/exchange/src/Compare/`, PHP, sdílí ho F4):

- čte projekci ze sady — build sady kopíruje `tests/corpus/projection.jsonc`
  do `<sada>/expected/projection.jsonc`, sada je soběstačná;
- před porovnáním normalizuje obě strany podle tvarů hodnot (D15,
  regulární výrazy a pravidla v projekci);
- rovnocenné tvary: `null` ≡ chybějící klíč; `totalRounding` 0 ≡ `null`;
  prázdné pole ≡ `null`;
- čísla s tolerancí 0,005; řádky po pozicích;
- výstup: seznam rozdílů `{cesta, očekáváno, skutečnost}`.

**Výjimky věrnosti:** pole, která apply mění záměrně (např. vlastní číslo
dokladu z řady vs. číslo dodavatele), jdou do seznamu výjimek v kódu
kontroly — každá s důvodem a odkazem na místo v kódu, které změnu dělá.
**Výjimku nepřidávej bez schválení:** sepiš kandidáty a zastav se
s otázkou, chyba v applieru se nesmí schovat do výjimky.

## Kontrola 2 — položky a účty

- počet položek (`economy_items`) před apply = po apply — Použít nezaložilo
  žádnou položku (díky `ds-setup-12`);
- každý řádek potvrzeného dokladu má položku a účet.

## Kontrola 3 — přiznání DPH a kontrolní hlášení (D28, jen plátce)

**Mapování** (schválené 2026-10-14) v `tests/corpus/cz/vat-reporting.jsonc`,
klíč = varianta korpusu; generátor z něj a z očekávání spočítá
**očekávání per doklad**:

| Varianta | Přiznání (řádky) | KH |
|----------|------------------|----|
| `domestic-goods`, `domestic-rounding` | 40 | B.2 nad 10 000 Kč vč. DPH, jinak B.3 |
| `domestic-two-rates`, `long-document` | 40 (21 %), 41 (12 %) | B.2 / B.3 |
| `domestic-reverse-charge` | 10 + 43 | B.1 |
| `eu-services` | 5 + 43 | A.2 |
| `eu-goods` | 3 + 43 | A.2 |
| `third-country-services` | 12 + 43 | A.2 |
| `receipt` | 40 | B.3 |
| `domestic-non-payer-supplier` | — | — |

Snížená sazba u samovyměření jde na sudé řádky (4, 6, 11, 13, 44) — v korpusu
první vlny se nevyskytuje.

`tests/datasets/cz/vat-payer/expected/vat.json` — pole záznamů per zpráva:
`messageId`, období (`YYYY-MM` podle DUZP), řádky přiznání `{řádek: {base, tax}}`,
oddíl KH a jeho údaje (DIČ dodavatele, evidenční číslo dokladu, DUZP,
základy a daně podle sazeb; u B.3 jen součty). `dataset-verify` sečte
záznamy použitých dokladů po obdobích (přeskočené vynechá) a porovná
s `economy.vat.returnLive` a `controlStatementLive` pro každé období,
do kterého data spadají (instance období najde sám).

**Rozdíl mezi očekáváním a Shipardem se nikdy neřeší úpravou očekávání
bez schválení.** Pokud Shipard přiřadí doklad do jiného období (DUZP vs.
datum přijetí) nebo jiného řádku, zastav se a popiš rozdíl.

Neplátce a spolek: `expected/vat.json` je prázdné pole; kontrola ověří, že
zdroj dat nemá žádné období DPH s daty a že se nepohnul žádný účet DPH (343).

## Kontrola 4 — náklady dokladů (nezávislý výpočet)

`expected/costs.json` — per zpráva očekávaný náklad = součet MD na účtech
třídy 5 z dokladu, **bez zaokrouhlení** (548100 / 549100 zaokrouhlovací
položky a 648100 / 649100):

| Profil | Doklad | Náklad |
|--------|--------|--------|
| plátce | s nárokem na odpočet (tuzemsko, přenesení, EU, třetí země, účtenka) | součet základů |
| plátce | dodavatel neplátce | celková částka |
| neplátce, spolek | vše | celková částka včetně DPH (i DPH dodavatele z EU) |

Kontrola porovná per doklad s deníkem. Tohle je přesně #97.

## Kontrola 5 — deník proti schválenému snímku (D29)

Účty určuje nastavení Shipardu (položky → účty), ne zákon, proto
schválený snímek místo nezávislého výpočtu.

- `--write-ledger-snapshot=<soubor>` zapíše `economy.accounting.generalLedger`
  (rok a měsíce, do kterých data spadají, analyticky) jako `ReportResult` JSON;
- schválený snímek žije v základu profilu
  `tests/corpus/cz/profiles/<profil>/approved/general-ledger.json` a build ho
  kopíruje do `<sada>/expected/`;
- kontrola porovná přes logiku `report-diff`; chybějící snímek = `přeskočeno`
  s důvodem, ne rozdíl.

**Schválení je lidský krok:** implementace snímky vyrobí, vypíše souhrn
(obraty po účtech) a **zastaví se** — snímek se commitne až po odsouhlasení.

## Generátor korpusu (Python)

- `tests/corpus/cz/vat-reporting.jsonc` (mapování výše) a výpočet
  `expected/vat.json` a `expected/costs.json` per sada — částky v `Decimal`,
  ze stejných vstupů jako očekávaný canonical (D5);
- kopie `projection.jsonc` a schváleného snímku do `<sada>/expected/`;
- `CorpusExpectedTest` (nebo nový strážce) ověří, že `expected/` sady
  odpovídá korpusu a mapování pokrývá všechny varianty.

## Kroky (commity)

1. Porovnávač (PHP) + unit testy (normalizace, rovnocenné tvary, tolerance,
   řádky).
2. Generátor: mapování DPH, `expected/vat.json`, `expected/costs.json`,
   kopie projekce; přestavení sad.
3. `dataset-verify`: preflight, apply, kontroly 1–4, report; unit testy
   preflightu a agregace.
4. Kontrola 5 a `--write-ledger-snapshot`.
5. Ověření na dev zdrojích dat (níže), snímky ke schválení — **zastavit se**.
6. Po schválení: snímky do základů profilů, přestavení sad, dokumentace.

## Hotovo když

- [x] `ds-setup-12` hotový (2026-10-14)
- [x] unit testy porovnávače, generátoru a příkazu zelené (úzké `--filter`,
      `python3 -m unittest`) — 2026-10-14
- [ ] tři dev zdroje dat z `CLAUDE.local.md` (režim volný) znovu naseedované;
      `dataset-verify` na každém: kontroly 1–4 `ok`, přeskočené jen doklady
      v cizí měně (6 u plátce) — 2026-10-14 reseed `ob9b` / `ab5v` / `wp66`:
      kontroly 1, 3, 4 `ok`, přeskočeno 6 / 2 / 2; kontrola 2 má jediný
      rozdíl, účtenka 0902 hrazená hotově (nález E níže)
- [x] druhý běh (`--no-apply` i bez něj) dá stejný výsledek — 2026-10-14
- [x] preflight odmítne zdroj dat s daty mimo sadu (ověřeno na `vlm9`
      s cizími zprávami a doklady, exit 2) a server v režimu `production`
      (unit test) — 2026-10-14
- [ ] schválené snímky deníku v základech profilů, kontrola 5 `ok` — čeká
      na rozhodnutí nálezu E (snímek s chybovou maskou nejde schválit)
- [x] výjimky věrnosti schválené (2026-10-14) a zdokumentované
      (`ApplyFidelityCheck::EXCEPTIONS*`, `docs/test-corpus.md` § 17)
- [x] `docs/test-corpus.md`: `dataset-verify`, kontroly, očekávání DPH
      a nákladů, schvalování snímku; `docs/cli.md`: `dataset-verify`
- [x] hlavička tasku, `python3 scripts/tasks-index.py`

## Pasti

- **Očekávání se neupravuje podle Shipardu.** Rozdíl je nález — buď chyba
  Shipardu (issue), nebo chyba očekávání (oprava generátoru se schválením).
- **Preflight je pojistka proti ostrým datům** — žádný `--force`, žádné
  obcházení.
- **Přeskočené doklady** (cizí měna) se nesmí započítat do očekávání DPH
  a nákladů — agregace jde jen přes použité doklady.
- **Zaokrouhlení** do nákladu nepatří (kontrola 4), v deníku ano (kontrola 5).
- **Doklady z dřívějšího běhu** — kontroly je musí zahrnout, ne jen ty
  z aktuálního apply.

## Odchylky při implementaci

- `createMissingEntities` a `rejectOnIssues` `DocumentApplier` nečte (chyba
  blokuje vždy, varování nikdy) a `MessageProposalApplier` je nepředává —
  příkaz posílá jen `targetDocState` 40 a `autoCreateMode` liberal.
- `expected/vat.json` nese i doklady v cizí měně (v měně dokladu), aby bylo
  mapování úplné; vynechává je až verifikátor (D30).
- Řádky přiznání určuje sazba, ne jen varianta (`regimes` v mapování):
  účtenka 0902 s 12 % jde i na řádek 41.
- `_resolve` z exportu porovnat nejde — štítek per řádek ověřuje kontrola 2
  přes položku; kontrola 1 jde přes adaptér export → projekce
  (`SavedDocumentProjection`), který ze zdroje dat doplní vlastní stranu,
  text řádku, jednotku a papírovou podobu samovyměření / dokladu bez DPH.
- Výjimky věrnosti jsou podmíněné dokladem a zdrojem dat
  (`ApplyFidelityCheck::exceptionsFor`): účtenka bez splatnosti / VS,
  doklad bez DPH, zdroj dat bez agendy DPH (#97). Dvě z nich (splatnost
  a VS účtenky) přibyly po schválení seznamu — stejná povaha jako
  schválené DUZP (výchozí hodnoty `DocDocument`).
- Strážce očekávání sad je nová třída `DatasetExpectationsTest`, ne
  rozšíření `CorpusExpectedTest`.

## Nálezy z ověření (2026-10-14)

První běh odhalil čtyři chyby applieru — opravené v rámci tasku, žádná
neskončila ve výjimce:

- **A** — `supplier.bankAccount` se samými `null` (účtenka) byl brán jako
  reference → `unresolved_required`; `BankAccountResolver::hasIdentifier`.
- **B** — faktura od tuzemského neplátce u plátce končila
  `vat_code_unknown`; `vat.mode` none → řádky bez sazby dostanou `cz-112`.
- **C** — dodavatel založený apply neměl adresu (`PartyResolver` fáze 1);
  payload nese `addresses`, side-create zapíše sídlo.
- **D** — pravidlo IČO → štítek přebilo LLM blok včetně `rowExceptions`
  („Cestovné lektora“ → položka školení); výjimky řádků zůstávají.
- **E** — účtenka hrazená hotově (`payment.method` cash, 0902) šla
  v účetním předpisu na účet pokladny (`accountSrc: cashDesk`); bez
  pokladny na hlavičce deník nesl masku `211???`. Opraveno (D33,
  `79dd289e`): applier doplní výchozí pokladnu zdroje dat (info
  `cash_desk_defaulted`), sady nesou `setup/cash_desks.jsonc` (pokladna
  POK na 211100).

## Revize snímků deníku (2026-10-15)

- **Plátce a neplátce** schválené **s výhradou účetní revize** (#123).
  Otevřená otázka pro revizi: pohoštění (`people.catering`) jde na
  513900 Náklady na reprezentaci s odpočtem DPH — praxe u pracovního
  oběda s obchodním partnerem, šedá zóna.
- **Spolek** neschválen: účetní předpisy mají masky pro podnikatelskou
  osnovu, v osnově NNO jde zaokrouhlení na 548 Manka a škody a 648
  Zúčtování fondů (a další kategorie na účty, které v NNO nejsou) — #122.

## Rozhodnutí k designu (potvrzená)

- ✓ **D26 — Jeden příkaz `dataset-verify`** (apply + kontroly + report,
  `--no-apply`), preflight odmítne zdroj dat s daty mimo sadu.
- ✓ **D27 — Porovnávač v PHP** nad papírovou projekcí s tvary hodnot
  a rovnocennými tvary; sdílí ho F4. Ve F3 hlídá věrnost apply.
- ✓ **D28 — Očekávání DPH nezávisle na Shipardu:** mapování varianta →
  řádky přiznání a oddíl KH (schválené), částky spočítá generátor.
- ✓ **D29 — Deník proti schválenému snímku** per profil; náklady per
  doklad nezávisle (u neplátce včetně DPH, #97).
- ✓ **D30 — Doklady v cizí měně se do #121 přeskočí** s důvodem.
- ✓ **D33 — Výchozí pokladna pro doklad placený hotově.** Přijatý doklad
  s `payment.method` cash bez pokladny dostane při Použít výchozí
  pokladnu zdroje dat; bez ní zůstane prázdná jako dřív.
