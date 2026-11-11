# Task: Podání DPH — XML pro EPO (DPHDP3 / DPHKH1 / DPHSHV), hlavička, PDF opis (M1 Fáze 3) — #55

**Stav:** částečně — implementace hotová 2026-09-10 (commity 1–7), nálezy prvního běhu
zlatého testu opravené 2026-09-11 (F3-3 číselník zemí, F3-4 nula ≡ chybějící, F3-5 akce
Načíst hlavičku z profilu, F3-6 (a) v porovnávači; F3-1 je chyba importu v `old_shipard`,
F3-2 zaniklo se strukturou). **Zlatý test DP3 01–04/2026 sedí** až na prohozená jména
oprávněné osoby v profilu (data). **Zlatý test KH 01/2026 sedí** až na známou výjimku F3-7
(2026-11-11, po reimportu a #77). Zbývá ruční proklik UI a nasazení na alfě
**Issue:** #55 (Fáze 3), návaznost D19 (#74 hotovo), D20 (přílohy na podání), D17 (zaokrouhlení)
**Návaznost:** staví na Fázi 2 (`tasks/vat-filings.md` — snapshot `economy_vat_filings`
+ výstupní řádky `_return_rows` / `_cs_rows` / `_rs_rows`), na strukturovaných polích
(`tasks/structured-fields.md` — hook `Document::structuredSchemaFor`, profil podatele
`filing_profile` na registraci), na `core.attachments` (`AttachmentService`) a na PDF
renderu (`src/Core/Render/RenderClient`, #34). Zámek instance a zaúčtování = F4.

## Cíl

Z podaného snapshotu vyrobit **soubor pro EPO** přesně podle oficiální struktury
Finanční správy (`Pisemnost` / `DPHDP3` / `DPHKH1` / `DPHSHV`), s hlavičkou
(Věta D + Věta P) sestavenou z profilu podatele a údajů podání, validovaný proti XSD,
uložený jako příloha podání spolu s PDF opisem. Zlatý test: XML za 01–04/2026 zdroje
689089 se **atributově rovná** skutečně podaným souborům ze starého Shipardu.

**Zdroj pravdy pro strukturu** není starý Shipard, ale oficiální popis:
- DPHDP3: https://adisspr.mfcr.cz/dpr/adis/idpr_pub/epo2_info/popis_struktury_detail.faces?zkratka=DPHDP3
  (verze 03.01.03 z 9. 3. 2026; XSD `https://adisspr.mfcr.cz/adis/jepo/schema/dphdp3_epo2.xsd`)
- DPHKH1: `…popis_struktury_detail.faces?zkratka=DPHKH1` (XSD `dphkh1_epo2.xsd`)
- DPHSHV: `…popis_struktury_detail.faces?zkratka=DPHSHV` (XSD `dphshv_epo2.xsd`)
- Číselníky: `https://mojedane.gov.cz/pmd/dokumentace/ciselniky/ukazka/{ufo,pracufo,okec,zeme}`

XSD soubory se **uloží do repa** (`modules/economy/vat/xsd/`, s poznámkou verze + data
stažení) a testy proti nim validují; bez sítě v CI.

Před implementací **přečti**:

- issue #55 komentáře D14–D22 a „Fáze 2 — hotovo"; `docs/vat-calculation.md` (proč jsou
  hodnoty v `_filed` sloupcích to, co se podává)
- `modules/economy/vat/docs/README.md` § Podání; `tables/economy_vat_filings.jsonc`
  (`header` json, `result`), `_filing_return_rows` (`base_filed`, `tax_full_filed`,
  `tax_reduced_filed`, `is_computed`), `_filing_cs_rows` (sekce, `row_kind`, pásma),
  `_filing_rs_rows`; `src/FilingDocument.php` (lifecycle 10/40/90, guardy),
  `FilingComposer.php`, `FilingRounding.php`, `FilingsForm.php`, `FilingsViewer.php`,
  CLI `vat-filing-compose`
- `docs/structured-fields.md` + `src/Core/StructuredFields/*`;
  `modules/economy/vat/config/filingProfileCz.jsonc`, `taxOffices.jsonc`,
  `taxOfficeBranches.jsonc`; `economy_codebooks_vat_registrations.filing_profile`
- `modules/core/attachments/src/AttachmentService.php` (`upload`, `listAttachments`,
  `getFilePath`), `docs/attachments.md`
- `src/Core/Render/RenderClient.php`, `RenderProfile`, `PdfOptions`; `docs/pdf-rendering.md`
  (Gotenberg, soubor `index.html`)
- `config/vat-reports-cz.jsonc` — `dp3Rows` (27 řádků, které zdroje zatím potřebovaly),
  `reportTypes`
- starý Shipard **není dostupný** z tohoto serveru; sémantika převzata níže (§ Reference)

## Rozhodnutí (X1–X9)

- **X1 — Writer per typ, čistý, nad snapshotem.** `src/Xml/Dp3XmlWriter`, `Kh1XmlWriter`,
  `ShvXmlWriter` (společný `EpoXmlWriter` pro `Pisemnost`, `nazevSW="Shipard"`,
  `verzeSW` z `Version`, escaping, formát dat `DD.MM.RRRR`, čísel bez oddělovačů).
  Vstup: hlavička podání (`header` + odvozené), výstupní řádky, instance, registrace.
  Výstup: `string`. Žádný dotaz do dokladů — **XML se generuje výhradně z persistovaného
  snapshotu**, proto je deterministické a po změně dokladů stále vydá to, co bylo podáno.
- **X2 — Hlavička jako strukturované pole per typ (D19, #74 I2).** `FilingDocument::
  structuredSchemaFor('header', $data)` vrací podle `report_type`:
  `economy.vat.filingHeaderCzDp3` / `…CzKh1` / `…CzShv`. Schéma = pole Věty P (převzatá
  z profilu podatele) + editovatelná pole Věty D, která nejsou odvozená (`typ_platce`,
  `c_okec`, `trans`, `kod_zo`, `d_por_dod` u DP3; u KH `c_okec`; u SHV dle popisu).
  Při sestavení (`FilingComposer`) se `header` **předvyplní** z `filing_profile` registrace
  a z instance; uživatel edituje v tabu „Hlavička" jen ve stavu 10; ve 40 immutable
  (guard F2 už platí na celý záznam).
- **X3 — Odvozená pole Věty D se needitují, počítají se při generování:**
  `dokument`/`k_uladis` konstanty; `dapdph_forma` z `filing_kind` (`regular` → B,
  `corrective` → O, `supplementary` → D, `corrective` navazující na `supplementary` → E;
  KH `khdph_forma` B/O/N, SHV `shvdph_forma` dle popisu); `d_zjist` z `date_found`;
  `d_poddp` = `date_issue` (při „Podat" se přepíše na `date_filed`); `rok`/`mesic`/`ctvrt`
  z instance (`ctvrt` jen u čtvrtletní); `zdobd_od/do` jen když instance nepokrývá celé
  období (částečné období — dnes nevzniká, ponechat prázdné); `dic` = číselná část
  `vat_id` registrace.
- **X4 — Mapování řádků → atributy je konfigurace, ne kód:** `config/vat-xml-cz.jsonc`
  sekce `dp3` (řádek → věta + atributy `base`/`full`/`reduced`, viz § Reference — **všech**
  56 řádků, i ty, které `dp3Rows` dnes nemapuje), `kh1` (sekce → věta + atributy),
  `shv`. Writer neví o konkrétních řádcích nic. Nulová hodnota → atribut se vynechá
  (jako starý `addVItem`); u součtových řádků 46/62/63 se vypíše i nula.
- **X5 — Validace před generováním (blokující, s field-level chybami na `header.*`):**
  povinná pole podle typu subjektu (`typ_ds` P → `zkrobchjm`; F → `prijmeni`+`jmeno`),
  `c_ufo`, `c_pracufo` (existence v číselníku), `dic`, `typ_platce`, `c_okec` u DP3;
  délky/masky z oficiálního popisu (`psc` 5 znaků CZ, `c_telef` 14, `zast_kod` regex);
  `d_zjist` povinné pro D/E/N; hodnoty řádků v mezích (14 číslic, záporné jen kde
  popis dovoluje). Po vygenerování **XSD validace** (`DOMDocument::schemaValidate`) —
  selhání = chyba, XML se neuloží.
- **X6 — Soubory jako přílohy podání (D20):** `DPHDP3-<dic>-<rok>-<MM|Qn>[-<kind>].xml`,
  `…-opis.pdf`, `…-obsah.pdf`. Generují se akcí „Vytvořit soubory" ve stavu 10 (lze
  opakovat — starší se smažou, snapshot je týž) a **automaticky při přechodu na 40**
  (`stateTransitionsRunDocumentHooks`), pokud chybí. Ve 40 přílohy immutable (guard).
  Uložení přes `AttachmentService::upload` (tableId podání), `metadata.kind` =
  `epo-xml` / `epo-preview` / `epo-content`.
- **X7 — PDF opis a obsah přes `RenderClient`:** HTML šablony `src/Xml/templates/`
  (opis = formulářový přehled řádků s hlavičkou; obsah = seznam dokladů z
  `_filing_items` per řádek/sekce — obdoba starého „Obsah přiznání DPH"). Render přes
  Gotenberg (`index.html`), profil A4. Když render selže, XML se přesto uloží a přechod
  na 40 projde s warningem — **XML je povinný, PDF ne**.
- **X8 — CLI `shpd-ds vat-filing-files --filing=<id> [--out=<dir>] [--xml-only]`** —
  generuje do adresáře bez uložení přílohy; pro E2E a zlatý test.
- **X9 — Zlatý test = atributová shoda se skutečně podanými XML.** Porovnání per věta
  a atribut, ignorují se `nazevSW`, `verzeSW`, `d_poddp`, `sest_*`, `c_telef`, `email`
  (kontaktní údaje se mohly změnit) a pořadí atributů. Podané soubory zdroje 689089 za
  01–04/2026 (DP3) a 01/2026 (KH) dodá David do `tests/Fixtures/vat-xml/689089/`
  (bez osobních dat nad rámec toho, co je v XML nutné; DIČ firmy je veřejný údaj).
  Canonical porovnávač (`EpoXmlDiff`) je součástí tasku, ne ad-hoc skript.

## Rozhodnutí z rozboru XSD (X10–X16)

Doplněno 2026-09-10 po rozboru stažených schémat — popis struktury v § Reference
sedí na `dphdp3_epo2.xsd` doslova (79 atributů vět 1–6 = bijekce s mapováním),
u kontrolního a souhrnného hlášení ale schéma žádá víc, než snapshot Fáze 2 nese.

- **X10 — Povinné atributy KH, které snapshot nemá, jsou konstanty z configu.**
  `VetaA4` vyžaduje `kod_rezim_pl` + `zdph_44`, `VetaB2` `pomer` + `zdph_44`.
  Zvláštní režimy (§ 89, § 90), oprava u nedobytné pohledávky (§ 46) a poměrný
  nárok (§ 75) jsou mimo M1, takže se vypisují `"0"` / `"N"` / `"N"` ze sekce
  `constants` v `vat-xml-cz.jsonc`; až agenda vznikne, nahradí konstantu sloupec
  ve snapshotu. Naopak `kod_pred_pl` (povinný v A1 i B1) ve snapshotu **je** —
  chybějící hodnota je blokující chyba validace, ne tichá nula.
- **X11 — Věta C se počítá při sestavení, ne ve writeru.** Není to součet řádků
  hlášení, ale kontrolní hodnoty proti přiznání (`obrat23`, `obrat5`, `pln23`,
  `pln5`, `pln_rez_pren`, `rez_pren23`, `rez_pren5` = ř. 1, 2, 40, 41, 25, 10, 11;
  `celk_zd_a2` = Σ ř. 3, 4, 5, 6, 9, 12, 13). Composer je spočítá z
  `economy_vat_filing_items` (mají materializovaný `dp3_row`) do
  `result.cs.vetaC` — stejný vzor jako `result.return.coefficient` u ř. 52.
  Writer čte snapshot, mapování řádek → atribut je v configu.
- **X12 — A1/B1 vykazují DUZP, ostatní sekce DPPD.** Jména atributů v XSD
  (`duzp` u A1/B1, `dppd` u A2/A4/B2) jsou samy tím rozhodnutím.
  `ControlStatementCalculator` dnes plní `vat_dppd` s fallbackem na `vat_duzp`
  pro všechny sekce — u A1/B1 se to obrací (DUZP, fallback DPPD). Je to oprava
  věcné chyby z Fáze 2, platí i pro živý report; shodu konstanty kalkulátoru
  s atributy configu hlídá test.
- **X13 — SHV rozpadá DIČ na `k_stat` + `c_vat`.** Snapshot drží celé
  `partner_vat_id`, schéma chce kód státu a číselnou část zvlášť. `k_pln_eu` je
  přímo náš `kod` (0 zboží, 3 služby); hodnoty 1 (přemístění obchodního majetku)
  a 2 (třístranný obchod) číselník zná, ale dnes na ně nemapuje žádný kód DPH —
  ověřit na datech zdroje 689089 při zlatém testu.
- **X14 — Následné SH se podává jako plný obsah, storno řádky ne.** Popis
  struktury opravuje řádky souhrnného hlášení stornem (`k_storno`, `VetaS`);
  Shipard podává následné SH jako celý obsah znovu (model `subsequent` z D16).
  Omezení je vědomé, pojmenované v `docs/README.md`; storno logika je samostatný
  task (potřebuje diff proti předchozímu podání, který u SH nemáme).
- **X15 — Sloupec `header` musí přestat být `system`.** `FormController::
  filterWritableFields()` systémové sloupce i jejich virtuální pole zahazuje
  a `TableDefinition::getStructuredColumns()` vidí jen sloupce se statickým
  `schema` — bez úpravy by se `header.*` tiše neuložilo. Definice dostane
  `"schema": "economy.vat.filingHeaderCzDp3"` (statický default, hook ho per typ
  přebije) a `system` zmizí. Immutabilitu po podání drží `FROZEN_COLUMNS`,
  generické CRUD strukturovaný sloupec odmítá samo (400 `STRUCTURED_COLUMN`).
- **X16 — Guard příloh se musí do `core.attachments` teprve přidat.**
  `AttachmentController::delete` volá `softDelete()` bez jakéhokoli hooku, takže
  varianta „ověřit, zda má hook" z X6 padla: součástí commitu 5 je rozhraní
  guardu + jeho registrace v `module.jsonc` a implementace pro podání.

Poznámky k X5: XSD **neenumeruje** hodnoty jednoznakových kódů (`dapdph_forma`,
`typ_platce`, `khdph_forma`, `shvies_forma`, `zdph_44`, `pomer`, `kod_rezim_pl`
jsou jen `maxLength=1`), takže XSD validace je tenká síť a skutečnou pojistkou
jsou validace podání a číselníky. `dokument` a `k_uladis` jsou naopak `fixed`
(`DP3`/`KH1`/`SHV`, `DPH`). `verzePis` je volný string — hodnota `01.02` žije
v configu per písemnost.

## Scope

### 1. Konfigurace a schémata

- `config/vat-xml-cz.jsonc` (X4) — `dp3`, `kh1`, `shv` mapy; test úplnosti: každý řádek
  z `dp3Rows` má XML mapu; každý atribut mapy existuje v XSD (parsovat XSD v testu).
- `config/filingHeaderCzDp3.jsonc`, `filingHeaderCzKh1.jsonc`, `filingHeaderCzShv.jsonc`
  (X2) — skupiny „Daňový subjekt" (Věta P, shodná pole s `filingProfileCz`), „Podání"
  (editovatelná pole Věty D). `version: "2026"`. Číselníky pro `typ_platce`
  (P/I/S/N/R/D), `zast_kod`, `zast_typ`, `typ_ds`, `trans`, `kod_zo` jako cfgItems.
- `modules/economy/vat/xsd/dphdp3_epo2.xsd`, `dphkh1_epo2.xsd`, `dphshv_epo2.xsd` +
  `README.md` (verze, datum, URL).

### 2. Hlavička (X2, X3)

- `FilingDocument::structuredSchemaFor` podle `report_type`.
- `FilingComposer`: při sestavení `header` = merge(`filing_profile` registrace →
  pole Věty P, defaulty Věty D: `typ_platce` P, `trans` A/N podle výsledku, `c_okec`
  z profilu). Přepočet konceptu hlavičku **nepřepíše** (uživatelské úpravy zůstávají);
  explicitní akce „Načíst z profilu" ji obnoví.
- `FilingsForm`: tab „Hlavička" = `structuredFieldElements('header', …)`, jen 10.
- `FilingsViewer::renderDetail`: záložka Hlavička přes `StructuredFieldRenderer`.

### 3. Writery (X1, X4)

- `src/Xml/EpoXmlWriter` (obálka, DOMDocument, formátování), `Dp3XmlWriter`,
  `Kh1XmlWriter`, `ShvXmlWriter`; vstupní DTO `FilingXmlInput` (hlavička po X3,
  řádky, instance, registrace) sestavené z DB v `FilingFilesService`.
- DP3: Věta D, P, 1–6 z `_return_rows` (`base_filed`, `tax_full_filed`,
  `tax_reduced_filed`); ř. 52: `odp_uprav_kf` = `tax_full_filed` řádku 52 (tam F2 ukládá
  krácený nárok × koeficient, `FilingRounding` ř. 97), `koef_p20_nov` = zálohový koeficient
  v % (2 des.) — composer ho dnes má (`DeductionCoefficientResolver`, ř. 212), ale
  neukládá: **doplnit do `result.return.coefficient`** při sestavení, writer ho čte odtud
  (snapshot, ne živý resolver).
  Věta R: řádky textové přílohy z `note` u dodatečného (`kod_sekce` D) — max 72 znaků
  na řádek, zalamovat.
- KH1: Věta D (`khdph_forma`, `d_zjist`, `mesic`/`ctvrt`, `rok`, `zdobd_*`), P, A1–A5,
  B1–B3 z `_cs_rows` (detail → jedna věta na řádek; agregát A5/B3 → jedna věta),
  Věta C součty. Čísla s 2 desetinnými místy.
- SHV: Věta D, P, věty řádků z `_rs_rows` (`k_stat`, `c_vat`, `k_pln_eu`, `pln_pocet`,
  `pln_hodnota` — názvy ověřit v popisu), celé Kč.

### 4. Soubory a lifecycle (X5–X7)

- `FilingFilesService::generate(int $filingId, bool $xmlOnly = false): FilingFilesResult`
  — validace X5 → XML → XSD → PDF (opis, obsah) → přílohy; ve 10 nejdřív smaže
  předchozí `epo-*` přílohy. Výjimka `FilingXmlValidationException` s `ValidationError[]`.
- `FilingDocument`: hook přechodu 10→40 volá `generate` když chybí `epo-xml`;
  guard immutability příloh ve 40 (`AttachmentService` — ověřit, zda má hook na
  delete/rename per záznam; jinak přidat `AttachmentGuard` rozhraní v `core.attachments`).
- Akce ve `FilingsViewer`: „Vytvořit soubory", „Stáhnout XML" (přes attachments endpoint),
  „Načíst hlavičku z profilu".
- PDF šablony (X7): `templates/dp3-preview.html.php`, `dp3-content.html.php`,
  `kh1-preview…`, `shv-preview…`; render `RenderClient`, výsledek jako příloha.

### 5. CLI (X8) a porovnávač (X9)

- `vat-filing-files --filing=<id> --out=<dir> [--xml-only]`.
- `src/Xml/EpoXmlDiff::compare(string $a, string $b, array $ignoreAttrs): array` —
  rozdíly per věta/atribut; CLI `vat-filing-xml-diff <a.xml> <b.xml>`.

### 6. Testy

- Writery: fixture snapshot → XML → shoda s očekávaným řetězcem (golden files ve
  `tests/Fixtures/vat-xml/synthetic/`); prázdné hodnoty vynechány, součtové řádky s 0
  přítomny; `dapdph_forma` B/O/D/E podle druhu a předchůdce; `d_zjist` jen u D/E/N;
  Věta R zalamování 72 znaků; `koef_p20_nov` formát `12.34`.
- XSD: každé vygenerované XML v testech projde `schemaValidate`; záměrně vadná hlavička
  (prázdné `dic`) → `FilingXmlValidationException` s `header.dic`.
- Konfigurace: úplnost `dp3Rows` × `dp3` mapa; atributy mapy ⊆ atributům XSD.
- Hlavička: `structuredSchemaFor` per typ; composer předvyplní z profilu; přepočet
  nepřepíše úpravy; ve 40 editace odmítnuta.
- Soubory: `generate` uloží 3 přílohy s `metadata.kind`; opakování ve 10 nahradí;
  přechod 10→40 bez XML ho vytvoří; render selhání → XML uložen + warning.
- **Zlatý test** (integrační, dev DS `btpg-p`): `vat-filing-files` pro řádná DP3 01–04/2026
  a KH 01/2026 → `EpoXmlDiff` proti `tests/Fixtures/vat-xml/689089/*.xml` = 0 rozdílů
  mimo ignorované atributy.

### 7. Dokumentace

`modules/economy/vat/docs/README.md` — sekce „XML pro EPO (Fáze 3)": zdroje pravdy,
konfigurace mapování, hlavička/profil, soubory, co je a není deterministické;
`xsd/README.md`; help formuláře podání (tab Hlavička, akce). `tasks/README.md`,
`docs/README.md` neaktualizuj (David).

## Reference — DPHDP3 mapování řádků (z oficiálního popisu 03.01.03)

Věta 1 (`base` / `tax`): 1 `obrat23`/`dan23`; 2 `obrat5`/`dan5`; 3 `p_zb23`/`dan_pzb23`;
4 `p_zb5`/`dan_pzb5`; 5 `p_sl23_e`/`dan_psl23_e`; 6 `p_sl5_e`/`dan_psl5_e`;
7 `dov_zb23`/`dan_dzb23`; 8 `dov_zb5`/`dan_dzb5`; 9 `p_dop_nrg`/`dan_pdop_nrg`;
10 `rez_pren23`/`dan_rpren23`; 11 `rez_pren5`/`dan_rpren5`; 12 `p_sl23_z`/`dan_psl23_z`;
13 `p_sl5_z`/`dan_psl5_z`; 14 `opr_dane_zd`/`opr_dane_dan`.
Věta 2 (`base`): 20 `dod_zb`; 21 `pln_sluzby`; 22 `pln_vyvoz`; 23 `dod_dop_nrg`;
24 `pln_zaslani`; 25 `pln_rez_pren`; 26 `pln_ost`.
Věta 3 (`base`): 30 `tri_pozb`; 31 `tri_dozb`; 32 `dov_osv`; 33 `opr_verit`; 34 `opr_dluz`.
Věta 4 (`base` / `full` / `reduced`): 40 `pln23`/`odp_tuz23_nar`/`odp_tuz23`;
41 `pln5`/`odp_tuz5_nar`/`odp_tuz5`; 42 `dov_cu`/`odp_cu_nar`/`odp_cu`;
43 `nar_zdp23`/`od_zdp23`/`odkr_zdp23`; 44 `nar_zdp5`/`od_zdp5`/`odkr_zdp5`;
45 —/`odp_rez_nar`/`odp_rezim`; 46 —/`odp_sum_nar`/`odp_sum_kr`;
47 `nar_maj`/`od_maj`/`odkr_maj`; 48 `kor_odp_zd`/`kor_odp_plne`/`kor_odp_krac`.
Věta 5: 50 `plnosv_kf`; 51 `pln_nkf` (s nárokem) / `plnosv_nkf` (bez nároku);
52 `koef_p20_nov` (koef %, 2 des.) / `odp_uprav_kf` (odpočet);
53 `koef_p20_vypor` (%) / `vypor_odp` (změna odpočtu).
Věta 6 (`tax`): 60 `uprav_odp`; 61 `dan_vrac`; 62 `dan_zocelk`; 63 `odp_zocelk`;
64 `dano_da`; 65 `dano_no`; 66 `dano`.
Věta D: `dokument`=DP3, `k_uladis`=DPH, `dapdph_forma` [BODE], `d_zjist`, `typ_platce`
[PISNRD], `trans` [AN], `c_okec`, `d_poddp`, `rok`, `mesic`|`ctvrt`, `zdobd_od`,
`zdobd_do`, `kod_zo` [QM], `d_por_dod`.
Věta P: `c_ufo`, `c_pracufo`, `typ_ds` [FP], `dic`, `zkrobchjm` | `prijmeni`+`jmeno`+
`titul`, `ulice`, `c_pop`, `c_orient`, `naz_obce`, `psc`, `stat`, `c_telef`, `email`,
`sest_prijmeni`, `sest_jmeno`, `sest_telef`, `zast_typ`, `zast_kod`, `zast_nazev`,
`zast_ic`, `zast_prijmeni`, `zast_jmeno`, `zast_dat_nar`, `zast_ev_cislo`,
`opr_prijmeni`, `opr_jmeno`, `opr_postaveni`.
Věta R: `kod_sekce` [OD], `poradi`, `t_prilohy` (72).

Starý Shipard (jen sémantika): `verzePis="01.02"` u všech tří; hodnoty ř. 1–66 vždy
z řádkově zaokrouhlených hodnot (= naše `_filed`); atribut s nulou vynechán kromě
součtových; dodatečné = rozdíly, ř. 66; KH A5/B3 agregát, ostatní sekce po dokladech,
Věta C součty; SHV řádek per (stát, DIČ, kód plnění).

## Mimo scope

- Odeslání do EPO / datové schránky (ručně; lifecycle „Podat" zůstává ruční).
- Import starých podání (D21, `old_shipard` task 35).
- Částečné zdaňovací období (`zdobd_*`) — pole se generuje, instance ho dnes nevytvoří.
- Storno řádky následného souhrnného hlášení (`k_storno`, `VetaS`) — X14.
- Odpověď na výzvu správce daně u KH (`c_jed_vyzvy`, `vyzva_odp`), sekce A.3
  (investiční zlato), obecná příloha v base64 (`Prilohy`/`ObecnaPriloha`).
- OSS, DPPO, jiné země.
- Zámek instance, zaúčtování (F4).

## Commity

1. XSD + `vat-xml-cz.jsonc` + testy úplnosti/atributů.
2. Schémata hlavičky + `structuredSchemaFor` + předvyplnění v composeru + formulář/detail.
3. `EpoXmlWriter` + `Dp3XmlWriter` + validace X5 + XSD validace + testy (golden synthetic).
4. `Kh1XmlWriter` + `ShvXmlWriter` + testy.
5. `FilingFilesService` + přílohy + přechod 10→40 + guard + akce vieweru + CLI.
6. PDF opis/obsah šablony + render.
7. `EpoXmlDiff` + zlatý test + dokumentace.

## Hotovo když

- [x] Testy zelené; každé vygenerované XML v testech validní proti XSD z repa.
      (5 700 unit testů, 9 integračních nad 4l3j vč. reálného Gotenbergu.)
- [x] Dev DS 4l3j: tab Hlavička se kreslí se 35 poli a českými popisky, „Vytvořit
      soubory" uloží XML + 2 PDF jako přílohy, po podání je guard zamkne, vadná
      hlavička vrátí 422 s chybami na `header.*`. Ověřeno přes API a integrační
      testy; **ruční proklik v prohlížeči zbývá**.
- [ ] **Zlatý test:** čeká na podané soubory zdroje 689089
      (`tests/Fixtures/vat-xml/689089/`, viz README v adresáři). Harness hotový
      a ověřený proti vygenerovanému souboru — `GoldenFilingXmlTest` se zatím
      přeskakuje.
- [x] XML vygenerované z podání ve 40 je bajtově stejné při opakovaném generování
      (determinismus ze snapshotu).

## Zbývá

- Ruční proklik v prohlížeči (tab Hlavička, akce Vytvořit soubory, Načíst hlavičku
  z profilu, Přílohy).
- Zlatý test KH: oprava importu `partner_doc_number` v `old_shipard` (F3-1) + #77
  (`kh_mode`) → reimport `btpg-p` → přepočet konceptů → nový běh. Souhrnné hlášení
  zdroj 689089 nepodává, třístranný obchod (`k_pln_eu` = 2) zůstává neověřený.
- Opravit prohozená jména oprávněné osoby v profilu `btpg-p` a načíst hlavičku
  u konceptů #6–#10 (data, ne kód).
- Nasazení na alfu: `ds-upgrade` (nové cfgItems vč. `world.cz.epoCountries`, jinak
  generování skončí na chybějícím `valueScale` / chybě `header.stat`) a kontrola, že
  render služba běží.

## Zlatý test — první běh (2026-09-11, dev DS `btpg-p`, podání #6–#10 z opraveného profilu)

Porovnání `vat-filing-files --xml-only` proti podaným XML zdroje 689089 (fixtury lokálně,
gitignorované). Hodnoty ř. 62/63/64 sedí ve všech čtyřech DP3; rozdíly:

**Chyby k opravě (nová strana) — stav 2026-09-11:**
- **F3-1 KH `c_evid_dd` v sekcích B — není chyba kódu, ale importu.**
  `ControlStatementCalculator` u přijatých sekcí (A2, B1–B3) bere `partner_doc_number`
  a test to hlídá. Na `btpg-p` má ale všech 8 850 přijatých faktur `partner_doc_number`
  totožné s naším `doc_number`: import (`old_shipard` `DocsRunner`, kanonické `docNumber`)
  posílá staré `docNumber`, zatímco starý KH psal do `c_evid_dd` sloupec `heads.docId`
  („Ev. číslo dokladu"). Podané hodnoty B2 sedí na `payment_reference` (VS) u 16 ze 17
  řádků, na `partner_doc_number` ani jednou. **Oprava v `old_shipard`** (`docId` →
  `partner_doc_number` u přijatých dokladů, David) + reimport `btpg-p`; v shpd beze změny.
  Totéž platí pro A2 a B1.
- **F3-2 KH hlavička bez `id_dats` — zaniklo.** Aktuální popis struktury DPHKH1
  (03.01.14 z 9. 3. 2026) i XSD v repu mají ve větě P 30 atributů a `id_dats` mezi nimi
  není; podané KH ho nese ze starší verze struktury. Přidání do schématu by shodilo
  `testHeaderSchemaFieldsMatchVetaP` i XSD validaci. Zlatý test ho **ignoruje**
  (`GoldenFilingXmlTest::comparisonOptions()`), profil pole dál nese.
- **F3-3 `stat` jako kód — hotovo.** Nový cfgItem `world.cz.epoCountries` generovaný
  z exportu číselníku Země (`modules/world/cz/data/zeme.txt`, `scripts/epo-countries.py`,
  250 zemí platných k 2026-09-11); `VatXmlMapping::countryName()` překládá pole
  z `header.countryNameFields`, kód bez názvu = chyba `header.stat`. Popis struktury
  chce `naz_zeme_c25`, což je v číselníku **velkými písmeny** („ČESKÁ REPUBLIKA");
  starý Shipard psal „Česká republika" (= `naz_zeme_c60`). Držíme se popisu, zlatý test
  `stat` ignoruje (rozdíl jen ve velikosti písmen). `world.base.countries` nestačí
  („Česko"); každý jeho kód má u úřadu název (test `EpoCountriesTest`).
- **F3-4 nulové atributy — hotovo.** `EpoXmlDiff` zahazuje číselné nuly na obou stranách
  před porovnáním (i celé věty, kterým nic nezůstane); nula proti jiné hodnotě je pořád
  rozdíl.
- **F3-5 akce „Načíst hlavičku z profilu" — hotovo.** `FilingComposer::resetHeader()`,
  `POST /_vat/filing-header-from-profile`, akce v detailu konceptu s potvrzením (přepíše
  i ruční úpravy), help Podání DPH § Hlavička podání.

**K rozhodnutí (David):**
- **F3-6 krácený odpočet vs. podané** — podaná DP3 mají všechen odpočet ve sloupci „v plné výši"
  (ř. 40 `odp_tuz23_nar` 106 109, ř. 46 153 772, bez ř. 52); my dle D13 dělíme na plný
  105 624 + krácený 485, koef 100 % → ř. 52 = 485, ř. 63 = 153 772 **stejně**. Nové je
  konzistentní s § 76 (firma má ř. 50 ≠ 0, koeficient ≥ 95 % → 100 %); staré vše sléváno
  do plného. Volby: (a) nechat nové a zlatý test porovnávat ř. 40/46 jako součet
  plný + krácený a ř. 52 ignorovat, (b) při koef = 100 % krácený sloučit do plného jako
  starý. Doporučení: **(a)** — D13 byl vědomý.
- **F3-7 KH B2/B3 u čtyř dokladů — vyřešeno (2026-09-11):** tři jsou doklady s ručním
  zařazením do detailu ve starém Shipardu (`vatCS = 1`, opakované FPB 4 356 / 4 590 Kč) →
  chybějící vlastnost, **#77** (`kh_mode` na hlavičce + import z `vatCS`). Čtvrtý je FPB
  s nulovou částkou a prázdným DIČ, kterou starý do B2 dal chybně — nový správně
  nevykazuje; známá výjimka zlatého testu. **Uzavřeno 2026-11-11:** po #77 a reimportu
  `btpg-p` má zlatý test KH 01/2026 jediný rozdíl — tuto výjimku; tolerance se pro ni
  vědomě nezavádí, test proto formálně neprochází.
- **F3-6 — rozhodnuto (a), hotovo:** nechat krácený sloupec + ř. 52; zlatý test porovnává
  ř. 40–48 jako součet „plný + krácený" proti podanému plnému sloupci (`foldAttributes`
  v `EpoXmlDiff`, odvozené z mapovací konfigurace) a ř. 52 / `koef_p20_nov` ignoruje.
  Koeficienty lze nastavit v Nastavení → koeficienty odpočtu (D13).

**Data profilu (ne kód):** `opr_jmeno`/`opr_prijmeni` zadáno v profilu prohozeně.
Po opravě profilu použít na konceptech #6–#10 akci **Načíst hlavičku z profilu**.

## Zlatý test — druhý běh (2026-09-11, po opravách F3-3…F3-6)

Zlatý test bere i koncept, když podané podání za období není (rekonstrukce na `btpg-p`
jsou koncepty schválně — jdou přepočítat), a hlásí rozdíly všech souborů najednou.

- **DP3 01–04/2026:** jediný rozdíl `opr_jmeno`/`opr_prijmeni` (prohozená data profilu).
  Řádky vět 1–6 včetně sloučeného odpočtu sedí ve všech čtyřech měsících.
- **KH 01/2026:** A2 (2 řádky), B1 (1) a B2 se liší jen v `c_evid_dd` — F3-1 (import);
  tři řádky B2 navíc v podaném = #77; jeden nulový doklad = známá výjimka. Uzavře se
  po reimportu a #77.
