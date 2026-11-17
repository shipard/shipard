# Task: `economy.taxes` — Fáze 0+1: mapování DPH výstupů + živé přiznání / KH / SH

**Stav:** hotovo — uzavřeno 2026-11-17 s #55; ověřeno na kopii reálných dat na dev serveru (zlatý test DP3 01–04/2026 a KH 01/2026 zdroje 689089), ověřování na alfě vypuštěno; souhrnné hlášení a rozdíly proti historii dalších zdrojů řeší #158
**Issue:** #55 (M1 — DPH), rozhodnutí D1–D6

## Kontext

M1 staví DPH výstupy nad existujícími zdrojovými daty (`docs_core_vat_recap`,
`docs_core_heads.vat_*`, `economy_codebooks_vat_registrations` / `vat_periods`).
Dle D1 se živý obsah přiznání, kontrolního hlášení a souhrnného hlášení
**počítá on-demand**, nic se nepersistuje — persistence přijde až s Podáním
(Fáze 2). Živé výstupy jsou **reporty** v doméně `report` (docs/reports.md,
D1/D2 z issue #42): tři nové report buildery nad existující infrastrukturou
(`ReportResult`, `ReportsPage`, REST, MCP). Doména `filing` zůstává pro Fázi 2+.

Tato fáze nepřidává **žádné databázové tabulky** ani migrace. Vzniká modul
`economy.taxes`, mapovací konfigurace `vat_code → výstupy` (jádro Fáze 0,
tabulka níže je závazná) a výpočetní vrstva + report buildery (Fáze 1).

Před implementací **přečti**:

- GitHub issue #55 — `gh issue view 55 --repo shipard/shpd` (rozhodnutí D1–D6)
- `docs/reports.md` — doména report, `ReportResult`, buildery, registrace
  v `module.jsonc`, parametry reportů, `ReportsPage`/`PeriodPicker`
- `docs/modules.md` — modulový systém, cfgItem, kompilace configu
- `modules/world/vat/config/vat-cz.jsonc` + `VatRateResolver` — kódy DPH,
  kategorie sazeb, reverse páry (`reverseVatCode`)
- `modules/docs/core/tables/docs_core_vat_recap.md` a definice
  `docs_core_heads.jsonc` (pole `vat_registration`, `vat_period`, `vat_duzp`,
  `vat_dppd`, `partner_doc_number`, snapshoty partnera — skutečné názvy
  sloupců ověř v definicích, ne v tomto PRD)
- `modules/economy/codebooks/tables/economy_codebooks_vat_registrations.md`
  a `..._vat_periods.md`
- `docs/accounting.md` — 343 analytiky per kód DPH (křížová kontrola)
- old_shipard: `modules/e10doc/taxes/VatCS/VatCSEngine.php` (referenční
  logika sekcí KH — limit, DIČ, PDP kódy) a `VatRS/VatRSEngine.php` (SH)

## Co vznikne

### 1. Modul `modules/economy/taxes/`

`module.jsonc` se závislostmi (`world.vat`, `economy.codebooks`, `docs.core`,
`economy.accounting` kvůli křížové kontrole). Registruje cfgItem config
a report buildery dle vzoru existujících modulů.

### 2. Mapovací konfigurace `config/vat-reports-cz.jsonc`

cfgItem (návrh klíče: `economy.taxes.reports.cz`). Per-country soubor, jeden
záznam pro **každý** kód z `world.vat.cz` — i vyloučení je explicitní
(`null`), aby test úplnosti odlišil „záměrně mimo" od „zapomenuto".

Tvar záznamu (finální podobu klíčů doladí implementace, sémantika je závazná):

```jsonc
"cz-110": { "dp3": {"row": 40, "col": "full"}, "kh": {"group": "B2B3"}, "sh": null },
"cz-115": { "dp3": {"row": 43, "col": "full"}, "kh": {"group": "B1", "kodPredPl": 4}, "sh": null },
"cz-120": { "dp3": {"row": 1},                 "kh": {"group": "A4A5"}, "sh": null },
"cz-201": { "dp3": {"row": 20},                "kh": null, "sh": {"kod": 0} },
"cz-112": { "dp3": null,                       "kh": null, "sh": null }
```

- `dp3.col` (`full` / `reduced`) jen u odpočtových řádků 40/41/43/44 —
  sloupce „V plné výši" / „Krácený odpočet". Krácené kódy se zatím jen
  **vykazují** do sloupce, koeficient/vypořádání je mimo scope M1.
- `kh.group`: `A1`, `A2`, `A4A5`, `B1`, `B2B3` — rozpad A4/A5 a B2/B3 řeší
  engine per doklad (pravidla níže), config nese jen skupinu.
- `sh.kod`: kód plnění souhrnného hlášení (0 zboží, 3 služby).

#### Závazná mapovací tabulka (všech 51 kódů)

| kód | DP3 řádek / sloupec | KH | SH |
|---|---|---|---|
| cz-110 | 40 / full | B2B3 | — |
| cz-111 | 41 / full | B2B3 | — |
| cz-301 | 41 / full | B2B3 | — |
| cz-302 | 41 / full | B2B3 | — |
| cz-112 | — | — | — |
| cz-115 | 43 / full | B1, kod 4 | — |
| cz-116 | 44 / full | B1, kod 4 | — |
| cz-340 | 44 / full | B1, kod 4 | — |
| cz-117 | 43 / full | B1, kod 5 | — |
| cz-118 | 40 / reduced | B2B3 | — |
| cz-119 | 41 / reduced | B2B3 | — |
| cz-341 | 41 / reduced | B2B3 | — |
| cz-342 | 41 / reduced | B2B3 | — |
| cz-120 | 1 | A4A5 | — |
| cz-121 | 2 | A4A5 | — |
| cz-310 | 2 | A4A5 | — |
| cz-311 | 2 | A4A5 | — |
| cz-122 | — | — | — |
| cz-123 | 50 | — | — |
| cz-150 | 25 | A1, kod 4 | — |
| cz-151 | 25 | A1, kod 4 | — |
| cz-350 | 25 | A1, kod 4 | — |
| cz-152 | 25 | A1, kod 5 | — |
| cz-203 | 10 | — | — |
| cz-204 | 11 | — | — |
| cz-370 | 11 | — | — |
| cz-201 | 20 | — | kod 0 |
| cz-202 | 21 | — | kod 3 |
| cz-205 | 3 | A2 | — |
| cz-206 | 4 | A2 | — |
| cz-360 | 4 | A2 | — |
| cz-361 | 4 | A2 | — |
| cz-207 | 5 | A2 | — |
| cz-208 | 6 | A2 | — |
| cz-362 | 6 | A2 | — |
| cz-363 | 6 | A2 | — |
| cz-215 | 43 / full | — | — |
| cz-216 | 44 / full | — | — |
| cz-390 | 44 / full | — | — |
| cz-391 | 44 / full | — | — |
| cz-217 | 43 / full | — | — |
| cz-218 | 44 / full | — | — |
| cz-392 | 44 / full | — | — |
| cz-393 | 44 / full | — | — |
| cz-401 | 22 | — | — |
| cz-405 | 7 | — | — |
| cz-406 | 8 | — | — |
| cz-460 | 8 | — | — |
| cz-461 | 8 | — | — |
| cz-407 | 12 | A2 | — |
| cz-408 | 13 | A2 | — |
| cz-462 | 13 | A2 | — |
| cz-463 | 13 | A2 | — |
| cz-415 | 43 / full | — | — |
| cz-416 | 44 / full | — | — |
| cz-490 | 44 / full | — | — |
| cz-491 | 44 / full | — | — |
| cz-417 | 43 / full | — | — |
| cz-418 | 44 / full | — | — |
| cz-492 | 44 / full | — | — |
| cz-493 | 44 / full | — | — |

Poznámky k tabulce (odůvodnění, aby budoucí čtenář nemusel rekonstruovat):

- Tuzemské PDP páry (cz-203/204/370, ř. 10/11) v KH **nejsou** — jejich daň
  nese sekce B1 z odpočtové strany. EU/dovoz odpočty (cz-215…, cz-415…,
  ř. 43/44 bez PDP kódu) v KH nejsou — daňovou stranu nese A2 z párových kódů.
- Dovoz zboží ř. 7/8 (cz-405/406/460/461) v KH není (daň vyměřená v celním
  režimu); „ostatní" ř. 12/13 do A2 patří.
- DP3 sloupec u výstupů: základ i daň dle řádku formuláře; ř. 20–22, 25, 50
  jen základ (hodnota).

### 3. Výpočetní vrstva `src/…` dle konvence modulu

- **`VatOutputsMapping`** — resolver configu: pro `(country, vatCode)` vrátí
  mapování, výjimka pro neznámý kód.
- **Společný výběr dokladů** (jedna třída, používají ji všechny tři
  kalkulátory): vstup `(vat_registration, dateBegin, dateEnd)` → doklady
  s `docState = 40`, jejichž **`vat_period` spadá do intervalu** (join přes
  date containment na `vat_periods` — D5; nikdy ne přes DUZP přímo)
  + jejich řádky `vat_recap`. Domácí měna.
- **`VatReturnCalculator`** (DPHDP3): sumace base/tax per (řádek, sloupec)
  z mapování; dopočítané řádky 46 (součet odpočtů) a 62–66 (daň na výstupu,
  odpočet, **vlastní daň / nadměrný odpočet** — operativní stav DPH).
  Plná přesnost, zaokrouhlování na celé Kč až věc XML (Fáze 3).
- **`ControlStatementCalculator`** (DPHKH1), pravidla per doklad:
  - `A1` / `B1`: detailní řádek per doklad s `kodPredPl`; ev. číslo = vlastní
    číslo dokladu (A1) / `partner_doc_number` (B1).
  - `A4A5` → **A4** když |celková částka dokladu vč. daně| > 10 000 Kč
    **a** partner má CZ DIČ; jinak **A5** (agregát, jeden součtový řádek
    per sazbové pásmo). Ev. číslo A4 = vlastní číslo dokladu.
  - `B2B3` → **B2** když |celková částka dokladu vč. daně| > 10 000 Kč;
    jinak **B3** (agregát). Ev. číslo B2 = `partner_doc_number`.
  - Sazbová pásma KH (sloupce 1/2/3) z `world.vat` kategorie kódu:
    `standard` → 1, `reduced` + `reduced1` → 2, `reduced2` → 3.
  - Datum DPPD = `vat_dppd`, fallback `vat_duzp`.
  - Měkké chyby dle vzoru deníku (do meta výsledku, nepadat): chybějící
    DIČ u B2, chybějící `partner_doc_number` u B1/B2.
- **`EcSalesListCalculator`** (DPHSHV): agregace per (kód plnění,
  DIČ odběratele) — počet plnění + hodnota; chybějící DIČ = měkká chyba.
- **Křížová kontrola proti deníku**: součty daně per kód DPH z `vat_recap`
  výběru vs. 343 analytiky deníku za stejný výběr dokladů; rozdíly do meta
  `ReportResult` (viewer je zobrazí jako varování).

### 4. Report buildery (doména `report`)

Tři buildery registrované dle docs/reports.md: `vat-return-live`,
`vat-control-statement-live`, `vat-ec-sales-live`. Parametry: registrace DPH
+ interval (výběr období nabídnout z `vat_periods` — měsíce/kvartály
i sloučené kvartály pro KH/SH; použij/rozšiř existující vzor `PeriodPicker`
minimálním zásahem). Výstup `ReportResult` — mezisoučty počítá engine, UI nic
nedopočítává. Hlavička return reportu nese výsledek ř. 64/65 (operativní stav)
a stav křížové kontroly.

### 5. Testy

- **Úplnost mapování** (vzor `VatAnalyticsCompletenessTest`): každý kód
  z `world.vat.cz` má záznam v mapování a naopak; žádný záznam bez kódu.
- **Cross-check proti `vatReturnRow`**: dokud pole ve `vat-cz.jsonc`
  existuje, DP3 řádek v mapování se s ním musí shodovat (chrání před
  překlepem při přepisu). Odstranění `vatReturnRow` z `world.vat` je
  samostatný úklid po M1, ne součást této fáze.
- **Unit testy** rozpadu sekcí KH (limit vč. daně, hranice 10 000,00; CZ/ne-CZ
  DIČ; PDP kódy; sazbová pásma) a dopočtů DP3 (46, 62–66) na syntetických
  datech.
- PHPUnit s úzkým `--filter`.

### 6. Dokumentace

`modules/economy/taxes/docs/README.md` — architektura modulu, odkaz na
issue #55, mapovací principy, hranice report vs. filing. `tasks/README.md`
a `docs/README.md` neaktualizuj (dělá David).

## Mimo scope této fáze

- Tabulky Podání, snapshoty, opravné/dodatečné, XML, PDF opis, zámek období
  (Fáze 2–4 dle issue #55).
- Per-doklad override zařazení do KH (staré pole `vatCS` 0–3 na dokladu),
  oprava dle § 44 (insolvence) v A4, investiční zlato (A3), ř. 45/47,
  výpočet kráticího koeficientu.
- Jakékoli změny `world.vat` a DB schémat.

## Commity (po logických krocích)

1. Modul `economy.taxes` + `vat-reports-cz.jsonc` + test úplnosti a cross-check.
2. `VatOutputsMapping` + rozpad sekcí KH + unit testy.
3. Výběr dokladů + tři kalkulátory + křížová kontrola + testy.
4. Report buildery + registrace + UI období.
5. Dokumentace modulu.

## Hotovo když

- [x] Testy zelené (úplnost, cross-check, sekce KH, dopočty DP3).
- [x] `bin/shpd-ds ds-upgrade` projde (rebuild kompilovaného configu).
- [x] Na kopii reálných dat na dev serveru (zdroj 689089; původně alfa,
      vypuštěno 2026-11-17) sedí živé přiznání na přiznání skutečně podané ze
      starého Shipardu řádek po řádku (DP3 01–04/2026, #55 Fáze 2) a KH na
      podané XML (KH 01/2026 až na známou výjimku F3-7, `vat-filing-xml.md`).
      SH proti podanému XML zatím neověřené → #158.
- [x] Křížová kontrola proti deníku hlásí nulové rozdíly (zdroj 689089,
      DP3 01–04/2026). Rozdíly proti historii dalších zdrojů → #158.
- [x] V UI lze zvolit registraci + období a přečíst všechny tři živé výstupy
      včetně operativního stavu DPH (ř. 64/65) — v používání na dev serveru.
