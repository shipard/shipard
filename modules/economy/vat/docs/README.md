# Modul `economy.vat` — daňové výstupy

Živé výstupy DPH počítané **on-demand** z potvrzených dokladů (Fáze 0+1
milníku M1, `tasks/taxes-phase01.md`, D1–D6), **instance daňových tvrzení**,
do kterých se doklady zařazují při uložení (revize po fázi 1,
`tasks/vat-report-periods.md`, D7–D13) a **podání** — persistovaný snapshot
toho, co se za období podalo (Fáze 2, `tasks/vat-filings.md`, D14–D22).
Vše v issue #55.

## Co modul dělá

Tři reporty v doméně `report` (docs/reports.md), registrované
v `config/reports.jsonc` s `periodSource: "vatPeriod"` a `vatReportType`
(`return` / `cs` / `rs`) — parametr běhu je `period` = id instance tvrzení
odpovídajícího typu:

| Report | Výstup |
|---|---|
| `economy.vat.returnLive` | Přiznání k DPH (DPHDP3) — řádky formuláře + dopočty 46/62–65, operativní stav (ř. 64/65) a křížová kontrola proti deníku v messages |
| `economy.vat.controlStatementLive` | Kontrolní hlášení (DPHKH1) — sekce A1/A2/A4/A5/B1/B2/B3, detailní řádky s ev. číslem / DIČ / DPPD, agregáty A5/B3; ruční zařazení dokladu přes `cs_mode` (níže) |
| `economy.vat.recapitulativeStatementLive` | Souhrnné hlášení (DPHSHV) — agregace per (kód plnění, DIČ odběratele) |

Nic se nepersistuje (D1) — persistence přijde až s Podáním (Fáze 2,
doména `filing`).

### Ruční zařazení do kontrolního hlášení (`cs_mode`, #77)

Rozpad A4/A5 a B2/B3 je automatika podle limitu 10 000 Kč vč. daně; praxe
potřebuje přepis — opakovaná či dílčí plnění pod limitem, která jako celek
patří do detailu, nebo doklad, který do hlášení nepatří vůbec. Sloupec
`docs_core_heads.cs_mode` (extension tohoto modulu vedle `cs_period`,
cfgItem `economy.vat.controlStatementModes`, hodnoty 1:1 se starým
`heads.vatCS`): 0 automaticky · 1 vždy jednotlivě (A4/B2) · 2 vždy souhrnně
(A5/B3) · 3 nevykazovat. Čte ho `VatDocumentSelection`, rozhoduje
`ControlStatementCalculator` — režim 1 vynutí detail i bez CZ DIČ (A4 pak
nese měkkou chybu `missingVatId`, podání soubor nevygeneruje), režim 3
vyřadí doklad **včetně pevných sekcí A1/A2/B1** (tak to dělal starý Shipard,
import musí být věrný); v přiznání doklad zůstává, snapshot podání má
`kh_section = NULL`. Pole je ve formulářích FVB/FPB, ostatní doklady
zůstávají na 0. Zadání: `tasks/vat-cs-mode.md`.

## Mapovací konfigurace

`config/vat-reports-cz.jsonc` (cfgItem `economy.vat.reports.cz`) mapuje
**každý** kód `world.vat.cz` na výstupy: `dp3 {row, col?}`, `kh {group,
kodPredPl?}`, `sh {kod}` — i vyloučení je explicitní `null`. Princip
shodný s rozhodnutím #8 účtování: `world.vat` zůstává legislativní vrstva
bez výkaznických konvencí (D3), výkaznictví per country žije tady.
Úplnost vůči číselníku a shodu s `vatReturnRow` hlídá
`VatReportsMappingCompletenessTest`. Sekce `dp3Rows` nese lokalizované
popisky řádků přiznání pro živý report. Sekce `reportTypes` nese zákonný
počátek typu výstupu (`cs.validFrom = 2016-01-01`, #58 — viz §Instance);
neznámý typ nebo špatné datum je chyba konstruktoru `VatOutputsMapping`.

Po změně configu je potřeba `vendor/bin/shpd-ds ds-upgrade` (rekompilace
cfgItem); samotné deklarace reportů se čtou z modulu za requestu.

## Instance daňových tvrzení (D7–D13)

Tabulka `economy_vat_report_periods` (`tables/*.md`): per registrace DPH
a typ (`return` přiznání / `cs` kontrolní hlášení / `rs` souhrnné hlášení)
jeden záznam s rozsahem s denní přesností, uživatelsky editovatelný
(viewer **Daňová tvrzení** v sekci účetnictví, `ReportPeriodsViewer` +
`ReportPeriodsForm`). Nahrazuje kalendářní mřížku období DPH: rozdílné
frekvence KH/SH, změny periodicity i vznik/zánik plátcovství uprostřed
období jsou jen jiné rozsahy v datech; historii dodá import reálných
rozsahů podaných tvrzení (task 30 ve starém Shipardu).

**Pravidla instance** (`ReportPeriodDocument`): bez překryvu v rámci
(registrace, typ) — tvrdá chyba; díra k sousedům = varování. Zrušení
(přechod do 90 přes `stateTransitionsRunDocumentHooks`) i tvrdé smazání
blokuje zámek a přiřazené doklady; guard na podání je připravený bod
rozšíření. Zámek (`locked`) se zatím nevynucuje (Fáze 4).

**Zařazení dokladu** — sloupce `vat_period` / `cs_period` / `rs_period`
na `docs_core_heads` (extension `extensions/docs_core_heads.jsonc`; docs.core
na economy.vat nezávisí). Plní `DocsHeadsVatPeriodHandler` jako
`beforeSave` documentEventHandler (uvnitř save transakce, s recapem už
spočítaným) pravidlem `VatPeriodAssigner`:

- `vat_period` = instance `return` registrace dokladu obsahující **DUZP**;
- `cs_period` / `rs_period` jen má-li recap aspoň jeden kód s mapováním
  `kh` resp. `sh` ≠ null; instance `cs`/`rs` obsahující **clamped efektivní
  datum** = `COALESCE(vat_dppd, vat_duzp)` oříznuté do rozsahu instance
  přiznání dokladu. Jinak NULL (= doklad do hlášení nespadá).
- **Invarianta**: sjednocení dokladů měsíčních `cs` instancí čtvrtletí =
  doklady `return` instance, beze zbytku a bez průniku
  (`VatPeriodAssignerTest::testMonthlyCsInstancesPartitionQuarterlyReturn`).
- **Ruční přesun**: pole změněné v payloadu oproti původnímu řádku handler
  respektuje (ověří existenci, typ a registraci instance); nedotčené pole
  přepočítá. Ruční hodnota tedy drží, dokud doklad neuloží někdo s jinou
  hodnotou v payloadu — formulář posílá aktuální hodnotu, takže běžné
  uložení formulářem ji **přepočítá pravidlem** (limit bez markeru
  „ručně upraveno").
- Import mód žádnou výjimku nemá — věrnost dávají reálné rozsahy
  importovaných instancí (D12).

**On-demand koncepty (D9)**: chybí-li při uložení instance pro datum,
`ReportPeriodsProvisioner` založí koncept (docState 10) dle periodicity
registrace (`tax/cs/rs_period_kind` — D10: už jen defaulty generátoru),
oříznutý do platnosti registrace a o sousední instance. Alert check
`economy.vat.draft_report_periods` (15 min) nabídne koncepty ke kontrole.
Seed běžného období: `VatRegistrationSeedHandler` (`afterSave` na
registraci) a denní cron `shpd-ds vat-periods-ensure` (+ `ds-upgrade`)
zakládají instance pokrývající dnešek a zítřek ve stavu V pořádku.
Dopředu se negeneruje nic.

**Přepočet po změně rozsahu** (`VatPeriodRecalculator`, `afterPersist`
instance, atomicky): dávka = doklady registrace, které na instanci míří
nebo jejichž DUZP / efektivní datum spadá do nového rozsahu. Přepíše se
jen **nekonzistentní** ukazatel (NULL, nebo instance, která datum dokladu
pro svůj typ neobsahuje); konzistentní ukazatel jinam zůstane — tím
přežije ruční přesun do instance, kam datum dokladu spadá. Instance se
při přepočtu nezakládají (find-only), doklad s NULL se dorovná při svém
příštím uložení.

**Zákonný počátek typu výstupu** (`reportTypes.{type}.validFrom` v mapovacím
configu, #58): kontrolní hlášení existuje od 1. 1. 2016 (§ 101c ZDPH),
přiznání a souhrnné hlášení omezení nemají. Je to národní pravidlo
výkaznictví, proto žije ve `vat-reports-cz.jsonc`, ne v názvosloví typů
instancí. Čtyři místa, kde se uplatní:

- **přiřazení** (`VatPeriodAssigner`): clamped efektivní datum před
  `validFrom` ⇒ `cs_period` NULL a lookup se pro ten typ **nevolá** —
  žádný on-demand koncept, žádný alert. Bez mapování žádné omezení;
- **generátor** (`ReportPeriodsProvisioner`, `$validFromByType`): `create()`
  před počátkem vrací null, dolní mez kandidáta = pozdější z platnosti
  registrace a počátku typu. Cesty bez kompilovaného configu (cron na DS
  bez `compiled.*.json`) předají prázdné pole — generují jen dnešek/zítřek;
- **přepočet** (`VatPeriodRecalculator`): ukazatel na instanci typu
  s počátkem, jehož doklad má efektivní datum před ním, je nekonzistentní
  → NULL i když instance datum obsahuje. Přepočet běží jen při změně
  rozsahu nebo stavu instance, guard přiřazených dokladů před ním — na DS
  bez reimportu se anachronická instance zruší až po úpravě jejího
  rozsahu (ta doklady odpojí). Reimport (`ds-reset`) problém řeší úplně;
- **import** (task 30 runner) se nemění — přenáší jen reálné reporty,
  před 2016 žádné KH neexistují.

Konec platnosti (`validTo`) záměrně neexistuje — bez použití.

## Koeficient odpočtu (D13, #59)

Krácený nárok na odpočet (kódy s `dp3.col = "reduced"`, v ČR cz-118/119/
341/342) se v DP3 vykazuje ve sloupci „Krácený odpočet" ř. 40–45 a do nároku
ř. 63 vstupuje přes **ř. 52 = Σ krácený × zálohový koeficient**.

**Model** — tabulka `economy_vat_deduction_coefficients` (`tables/*.md`):
koeficient patří na **registraci DPH × kalendářní rok**, ne na fiskální
období (vypořádací období je podle § 76 ZDPH vždy kalendářní rok) ani na
DS (registrací může být víc). Konstrukce směrnice 2006/112/ES čl. 173–175
(odpočitatelný podíl, předběžný podíl z minulého roku, roční vyrovnání) —
obecná pro EU, národní je jen mapování na řádky (`vat-reports-cz.jsonc`).
Per rok dvě hodnoty: `coefficient_provisional` (zálohový, § 76 odst. 6)
a `coefficient_settled` (vypořádací). Spravuje se v Nastavení → Účetnictví
→ **Koeficienty odpočtu DPH** (`DeductionCoefficientsViewer` / `Form`,
gate `VatAgendaNavGate`); `DeductionCoefficientDocument` hlídá interval
⟨0; 1⟩, přesnost na setiny (`coefficient_precision`) a duplicitu živých
záznamů (DB index není unique — smazaný záznam 90 nesmí blokovat nový).

**Resolver** — `DeductionCoefficientResolver::provisional($regId, $year)`
je jediná autorita: explicitní zálohový roku → vypořádací roku N−1 →
`1.0000` (`source: default` = plný nárok, stav firmy bez osvobozených
plnění). Jen záznamy ve stavu 40. Rok bez záznamu je legitimní stav, ne
chyba (D13d) — `VatReturnLiveBuilder` to řekne ve zprávách
(`vatReturn.deductionCoefficient` + `…Default`), ale jen když v dokladech
instance je nenulový krácený nárok (nešumět). Rok = rok `date_begin`
instance z `VatPeriodRange`.

**Kalkulátor** — `VatReturnCalculator::calculate($docs, $coefficient)`:
computed 52 ve sloupci `taxFull` (základ 0), 63 = 46 + 52 + 53 + 60.
Krácený sloupec ř. 40–45 zůstává vykázaný. Starý Shipard krácený sloupec
nikdy nevyplňoval, import nic nepřenáší; default 1,00 reprodukuje podaná
tvrzení (ověřeno na zdroji 689089, 01–04/2026: shoda ř. 64 až na
zaokrouhlení řádků na Kč).

**Mimo scope:** ř. 53 (roční vypořádání — vstup `coefficient_settled`
je připravený) a ř. 60 (úprava odpočtu).

## Podání (Fáze 2, D14–D22)

Živý report je vždy přepočtený a nemá lifecycle. **Podání** je jeho opak:
persistovaný snapshot obsahu instance tvrzení v okamžiku sestavení, který
se po podání nemění. Po Fázi 2 má firma trvalý záznam „co jsme za období
podali“ a Fáze 3 nad ním jen generuje XML.

**Kotva je instance tvrzení** (D14): `economy_vat_filings.report_period`,
z ní plyne typ, registrace i rozsah období. Proto instanci s nezrušeným
podáním nelze zrušit ani smazat a instanci s **podaným** podáním nelze
změnit rozsah (`ReportPeriodDocument`) — podaný obsah odpovídá rozsahu,
ve kterém se sestavil. Opravný postup je nové podání jiného druhu, ne
editace rozsahu.

### Dvě úrovně snapshotu (D15)

| Tabulka | Co drží |
|---|---|
| `economy_vat_filing_items` | **dokladová úroveň, vždy úplná** — per (podání, doklad, řádek rekapitulace) základ/daň, DIČ protistrany, ev. číslo, DPPD a **materializovaný výsledek mapování** (řádek/sloupec DP3, skupina a sekce KH, kód plnění SH) |
| `economy_vat_filing_return_rows` | řádky DPHDP3: přesná i podaná hodnota |
| `economy_vat_filing_cs_rows` | řádky DPHKH1 (detaily A1/A2/A4/B1/B2 + agregáty A5/B3) |
| `economy_vat_filing_rs_rows` | řádky DPHSHV per (kód plnění, DIČ) |

Dokladová úroveň je to, co dělá podání ověřitelným: umožňuje **rozdíly
mezi podáními po dokladech** a výklad obsahu i po pozdější změně
mapovacího configu. Items jsou úplné i u dodatečného přiznání, které
v řádcích vykazuje jen rozdíly — jinak by nebylo proti čemu diffovat.

### Druhy podání (D16)

Povolené druhy per typ výstupu žijí v mapovacím configu země
(`vat-reports-cz.jsonc` → `reportTypes.{type}.filingKinds`), názvosloví
v `config/filingKinds.jsonc`:

| Typ | Druhy | Po lhůtě |
|---|---|---|
| `return` (DPHDP3) | řádné, opravné, **dodatečné** | dodatečné = rozdíly + ř. 66 (§ 141 DŘ) |
| `cs` (DPHKH1) | řádné, opravné, **následné** | následné = plný obsah znovu, povinné datum zjištění (§ 101f ZDPH) |
| `rs` (DPHSHV) | řádné, **následné** | následné = plný obsah znovu |

Dodatečné a následné se u jednoho typu vylučují — hlídá to test úplnosti,
ne PHP. `FilingDocument` vynucuje pořadí: řádné jen dokud za instanci není
nic podané, ostatní naopak jen po podaném podání, a v instanci smí být
nejvýš **jeden živý koncept**.

### Zaokrouhlení je pravidlo podání, ne reportu (D17)

Živý report zůstává přesný, snapshot nese obě hodnoty vedle sebe.
`FilingRounding` je čistá třída s pravidlem převzatým ze starého
`VatReturnReport` a ověřeným proti podaným XML:

- každý řádek DP3 se zaokrouhlí **samostatně** na celé Kč;
- dopočty **46 / 62 / 63 se počítají ze zaokrouhlených řádků**, ne
  zaokrouhlením přesného součtu — součet zaokrouhlených se od
  zaokrouhleného součtu běžně liší o jednotky Kč a úřad čeká první
  variantu (to je celý smysl pravidla);
- ř. 52 = zaokrouhlený krácený nárok × zálohový koeficient;
- ř. 64/65 = 62 − 63 podle znaménka.

Kontrolní hlášení se podává na haléře (`roundingUnit: 0.01`), proto
u něj sloupce `_filed` vůbec neexistují. Souhrnné hlášení zaokrouhluje
**nahoru** (`ceil`, věrně dle starého `VatRSReport`) — zadání Fáze 2
uvádělo `round()`, rozhodl referenční kód a podaná XML.

**Dodatečné přiznání** vykazuje rozdíl proti poslední známé daňové
povinnosti, tedy proti **kumulativnímu** podanému stavu: řádné a opravné
podání je plná náhrada, dodatečné se přičítá k základu, ze kterého
vzniklo (`FilingComposer::cumulativeFiledRows`). Diffovat proti hodnotám
jednoho předchozího podání by u druhého dodatečného v řadě dalo nesmysl,
protože to samo nese jen deltu. Ř. 64/65 jsou u dodatečného nulové,
změnu povinnosti nese **ř. 66**.

### Sestavení — `FilingComposer`

Jediná autorita, která zapisuje do items a výstupních řádků. Jde
**stejnou cestou jako živý report**: doklady z `VatDocumentSelection`,
výpočet týmiž čistými kalkulátory, sekce KH z `sectionForCode()` téhož
enginu. Žádná druhá výpočetní větev; snapshot přidává jen zaokrouhlení
a materializaci mapování.

Rozdíl je v přísnosti: **kód DPH bez mapování je tvrdá chyba** sestavení
(živý report ho jen hlásí) — z podání nesmí nic tiše vypadnout, takže
výjimka rollbackne celé uložení.

Sestavení je idempotentní (DELETE + INSERT celého podání), takže
„Přepočítat“ nevyrábí duplicity. **Transakci vlastní volající** —
composer běží i z `FilingDocument::afterPersist`, tedy uvnitř save
transakce, a MariaDB nemá vnořené transakce (vzor `VatPeriodRecalculator`).

Volání: `afterPersist` nového konceptu i změny, která mění obsah
snapshotu; akce **Přepočítat** (`POST /_vat/filing-compose`); CLI
`shpd-ds vat-filing-compose --period=<id> [--kind=…] [--date-found=…]`
nebo `--recompute=<id podání>`.

### Lifecycle bez EPO (D18)

cfgItem `economy.vat.docStatesFilings`: **10 Sestaveno** → 40 | 90,
**40 Podáno** (`readOnly`, bez dalších přechodů), **90 Zrušeno**.
Odeslání na Finanční správu dělá člověk; systém drží záznam.

Přechod do Podáno doplní `date_filed` (dnes, když je prázdné) a vyžaduje
sestavený snapshot (`result` není NULL). **Podané podání je immutable** —
změnit smí jen `note`, smazat se nedá; zrušené podání je zmrazené stejně.
Oprava se podává jako nové podání jiného druhu, ne editací. Instance se
při podání **nezamyká** — `locked` a jeho vynucení zůstává Fáze 4.

Uložení může být částečné (API pošle jen to, co mění), takže Document
hodnoty, které payload neposlal, bere z uloženého řádku — jinak by
částečná aktualizace podaného podání vypadala jako koncept.

### Profil podatele (D19, #74)

Údaje **věty P** podání (kdo podává, komu a kdo výstup sestavil) se nedají
odvodit z dokladů ani z registrace — zadává je uživatel jednou na
**registraci k DPH**, ne u každého podání. Nejsou to relační data (sada polí
je dána formulářem finanční správy a bude se měnit), takže jde
o **strukturované pole se schématem** (issue #74,
[docs/structured-fields.md](../../../../docs/structured-fields.md)):

| | |
|---|---|
| Sloupec | `economy_codebooks_vat_registrations.filing_profile` — přináší ho [extension](../extensions/economy_codebooks_vat_registrations.jsonc) tohoto modulu, protože `economy.codebooks` na `economy.vat` nezávisí |
| Schéma | [`config/filingProfileCz.jsonc`](../config/filingProfileCz.jsonc), verze `2026` |
| Skupiny | daňový subjekt · finanční úřad · adresa · kontakt · oprávněná osoba · sestavil · podepisující osoba |
| Číselníky | `filingSubjectTypes`, `filingSignatoryTypes`, `filingSignatoryCodes` (tento modul) + `world.cz.taxOffices`, `world.cz.taxOfficeBranches`, `world.base.countries` |
| UI | Registrace DPH → záložka **Podací údaje**; stejná záložka v detailu vieweru |

Sada polí a délky jsou přenesené ze starých properties
(`e10doc/taxes` → `VatReturnProperties::loadProperties`) a **Fáze 3 je ověří
proti aktuálnímu XSD** daňového portálu; do té doby profil jen sbírá data.
Povinný je jen typ subjektu, a to teprve v neprázdném profilu — registraci
k DPH jde uložit bez podacích údajů.

**Hlavička podání** (`economy_vat_filings.header`, Fáze 3) je strukturované
pole se schématem **per typ tvrzení** — vybírá ho `FilingHeaderSchema::
forReportType()`, na který delegují `FilingDocument::structuredSchemaFor()`
i `FilingsForm`. Composer ji při sestavení předvyplní z profilu podatele,
z vlastní firmy a z registrace (DIČ); **přepočet ji nepřepisuje**, protože
ruční úpravy jsou jediná část snapshotu, kterou zadává člověk. Obnovu
z profilu dělá zvláštní akce **Načíst hlavičku z profilu** v detailu
konceptu (`FilingComposer::resetHeader()`, `POST
/_vat/filing-header-from-profile`) — přepíše celou hlavičku včetně ručních
úprav, proto se UI ptá. Podáním zmrzne jako zbytek snapshotu.

### UI (D20)

Viewer **Podání DPH** (Účtárna, za Daňovými tvrzeními): řádek nese podaný
výsledek, detail přehled + výstupní řádky (podané vedle přesných) +
zprávy z okamžiku sestavení + **Rozdíly** proti `previous_filing` po
dokladech (podle `doc_head` + `vat_code` + `vat_pct`). Detail instance
v Daňových tvrzeních nese seznam podání a akci **Sestavit podání**.
Hlavičky živých reportů hlásí poslední podání a jeho podanou daňovou
povinnost — vidět rozdíl proti živému výpočtu je celý smysl.

Přílohy jdou přes `core.attachments` s `table_id` = 443: soubory pro
daňový portál plní Fáze 3 (viz níže), ručně nahrané doklady o podání
zůstávají na uživateli.

## XML pro EPO (Fáze 3)

Z podaného snapshotu se vyrábí **soubor pro daňový portál** podle oficiální
struktury Finanční správy: `Pisemnost` → `DPHDP3` / `DPHKH1` / `DPHSHV`.

### Zdroje pravdy

| Co | Kde |
|---|---|
| Struktura písemnosti | `xsd/*.xsd` — schémata stažená z adisspr.mfcr.cz, viz `xsd/README.md` (verze, datum, MD5) |
| Řádek/sekce → věta a atribut | `config/vat-xml-cz.jsonc` (cfgItem `economy.vat.xml.cz`) |
| Hodnoty | **jen** snapshot podání (`economy_vat_filings` + řádkové tabulky) |
| Sada polí hlavičky | `config/filingHeaderCz{Dp3,Kh1,Shv}.jsonc` |
| Název státu (`stat`) | `world.cz.epoCountries` — číselník Země daňového portálu, generovaný z exportu (`modules/world/cz/data/README.md`); hlavička drží ISO kód, XML dostane `naz_zeme_c25` (`header.countryNameFields`) |

Writery neznají jediné číslo řádku ani jméno atributu — všechno je
v configu, takže nové vydání formuláře je změna konfigurace, ne kódu.
`VatXmlMappingCompletenessTest` drží invariant „mapované ∪ vědomě
nemapované = atributy schématu" a hlídá i počet desetinných míst, kódy
forem, sekce hlášení a pole hlavičky proti větě P.

### Co je deterministické a proč

Vstupem generátoru je `FilingXmlInput` — **celý obsah podání jako
hodnota**, načtený ze snapshotu (`FilingXmlInputLoader`). Doklady,
koeficient odpočtu ani registrace se znovu nevyhodnocují, takže podané
tvrzení vydá při opakovaném generování týž soubor i po jejich pozdější
změně. Proto je ve snapshotu i zálohový koeficient ř. 52
(`result.return.coefficient`) a DIČ v hlavičce.

Jediná výjimka je rozsah instance tvrzení, ze kterého plyne zdaňovací
období — ten instanci s podaným tvrzením zmrazuje `ReportPeriodDocument`.

### Odvozená pole hlavičky

Věta P se opíše z hlavičky, věta D se z větší části dopočítá
(`FilingHeaderResolver`): konstanty `dokument` / `k_uladis`, kód formy
z druhu podání a jeho předchůdce (řádné B, opravné O, dodatečné D,
opravné dodatečné E; u KH navíc následné N), datum podání, rok a měsíc
nebo čtvrtletí z rozsahu instance. Rozsah, který nepokrývá celý měsíc ani
čtvrtletí, se navíc vypíše jako `zdobd_od` / `zdobd_do`.

Kontrolní ani souhrnné hlášení nemá ve větě D **žádné** needvozené pole —
proto mají tenčí schéma hlavičky než přiznání.

Stát podatele drží hlavička jako ISO kód (`enumString` nad
`world.base.countries`), formulář ale chce **název z číselníku Země**
Finanční správy — a ten má jiné tvary než běžný český název („ČESKÁ
REPUBLIKA", ne „Česko"). Překlad dělá `VatXmlMapping::countryName()`
z cfgItem `world.cz.epoCountries`; kód bez názvu ohlásí validace jako
chybu pole `header.stat`, writer by ho odmítl výjimkou.

### Kontrolní hlášení: co snapshot nenese

Schéma vyžaduje pár atributů, na které M1 nemá agendu — vypisují se jako
konstanty z configu (`sections.*.constants`): `kod_rezim_pl` = 0 (zvláštní
režimy § 89 a § 90), `zdph_44` = N (oprava u nedobytné pohledávky § 46),
`pomer` = N (poměrný nárok § 75). Až agenda vznikne, nahradí konstantu
sloupec ve snapshotu.

Sekce **A.1 a B.1 vykazují DUZP**, ostatní DPPD — jméno atributu ve schématu
(`duzp` vs. `dppd`) je samo tím rozhodnutím a `ControlStatementCalculator`
je s ním v souladu (hlídá test).

**Věta C** není součet řádků hlášení, ale kontrola proti přiznání: bere
základy řádků přiznání spočítané nad toutéž dokladovou úrovní, které
composer ukládá do `result.cs.dp3Base`.

### Soubory a lifecycle

`FilingFilesService` skládá cestu **validace → XML → kontrola proti XSD →
PDF → přílohy**:

- `FilingXmlValidator` hlásí to, co schéma neumí (obchodní jméno u PO,
  jména u FO, masky, datum zjištění u forem D/E/N, neúplné řádky hlášení)
  jako **field-level chyby** na `header.*`, takže je formulář ukáže u pole;
- `EpoXsdValidator` je poslední pojistka: co neprojde schématem, se neuloží;
- **XML je povinné, PDF ne** — selhání renderu je warning.

Ve stavu Sestaveno lze generování opakovat (starší sada se nahradí), do
stavu Podáno se soubory dogenerují automaticky při přechodu. Hlavičku,
kterou by portál odmítl, ohlásí `FilingDocument::validate()` **už při
přechodu** — po podání je pozdě, opravit by šlo jen novým podáním.

Soubory podaného tvrzení jsou zamčené (`FilingAttachmentGuard`, registrace
`attachmentGuards` v module.jsonc); ručně nahrané přílohy — třeba potvrzení
o přijetí — zůstávají plně v rukou uživatele.

### CLI

```bash
# soubory jako přílohy podání (totéž co akce „Vytvořit soubory")
vendor/bin/shpd-ds vat-filing-files --filing=42

# jen do adresáře, bez zápisu do DB (E2E, zlatý test)
vendor/bin/shpd-ds vat-filing-files --filing=42 --out=/tmp/epo --xml-only

# porovnání s tím, co se doopravdy podalo
vendor/bin/shpd-ds vat-filing-xml-diff podano.xml /tmp/epo/DPHDP3-…xml

# import starého podání z původního XML (přílohy + Podáno); --dry-run jen porovná
vendor/bin/shpd-ds vat-filing-import --period=140 --type=return --xml=podano.xml --attach=opis.pdf
```

`EpoXmlDiff` porovnává **věty a atributy po normalizaci**: pořadí atributů,
pořadí řádků v sekci ani zápis čísla (`210` vs. `210.00`) rozdíl nedělají,
jiná hodnota a chybějící či přebývající řádek ano. **Nulový atribut je
totéž co chybějící** — EPO bere nevyplněnou hodnotu jako nulu a starý
Shipard nuly u známých řádků vypisoval, nový je vynechává. Volitelné
sloučení atributů (`foldAttributes`, cíl → zdroje) sečte před porovnáním
třeba plný a krácený sloupec odpočtu. Zlatý test (`GoldenFilingXmlTest`)
na něm staví — porovnává vygenerované soubory s podanými, které leží
v `tests/Fixtures/vat-xml/689089/`; jeho tolerance proti starému podání
(sloučený odpočet, bez ř. 52, bez `id_dats`) popisuje README fixtur.

## Zámek instance a kontrola zůstatků (Fáze 4a, D23–D27, D31)

Po podání musí být obsah období **neměnný**. Zámek žije na instanci
tvrzení (`economy_vat_report_periods.locked` + `locked_at` / `locked_by`),
vynucuje ho obecný mechanismus lock providerů v jádru
(`documentLockProviders`, `docs/document-system.md` §16).

### Co je zamčené (D23)

`VatPeriodLockProvider` (registrace v `module.jsonc` pro `docs_core_heads`):
doklad je zamčený, když má **neprázdnou rekapitulaci DPH v původním nebo
novém stavu** a **kterýkoli** ze tří ukazatelů (`vat_period` / `cs_period` /
`rs_period`, původní i nový) míří na zamčenou instanci. „Kterýkoli" kvůli
měsíčnímu KH čtvrtletního plátce; „podle obsahu DPH" kvůli pokladním
převodům bez DPH — ty patří pod zámek fiskálního měsíce.

- Původní strana = uložený řádek + `docs_core_vat_recap`. Nová strana =
  `DocDocument::willHaveVatRecap()` (čisté pravidlo zrcadlící
  `buildVatRecapitulation` / `useDeclaredRecap`) a ukazatele stejným
  pravidlem jako `DocsHeadsVatPeriodHandler` (ruční přepis, jinak
  `VatPeriodAssigner::compute`), ale s **find-only** lookupem — validace
  nikdy nezaloží koncept instance.
- Blokuje se každý zápis vč. přechodů 40→80/30/90, tvrdé smazání, vznik
  nového dokladu do zamčeného rozsahu, ruční přesun ukazatele do/ze zamčené
  instance a přeúčtování. Přílohy zůstávají volné. Bez registrace, DUZP nebo
  rekapitulace je doklad volný.
- Import mód (`_importNumber`) providery nevolá (D26) — `DocsHeadsVatPeriodHandler`
  přiřadí importovaný doklad i do zamčené instance.

### Lifecycle zámku (D25)

- Akce **Uzamknout** / **Odemknout** (s potvrzením) v detailu instance
  (`ReportPeriodsViewer`), **Uzamknout tvrzení** v detailu podaného podání
  (`FilingsViewer`) — „jeden klik po podání"; přechodový dialog volitelná
  pole neumí, checkbox u 10→40 proto není. Endpoint
  `POST /_vat/report-period-lock {periodId, locked}` ukládá přes
  `TableGateway` + `ReportPeriodDocument` (stejné guardy jako formulář).
  Checkbox `locked` ve formuláři instance funguje také.
- `ReportPeriodDocument`: zamčená instance povolí jen přepnutí `locked`
  a `name`; změna rozsahu, stavu, registrace nebo typu = chyba `locked`.
  Zrušit ji nelze; sestavit nad ní podání **lze**. `locked_at`/`locked_by`
  stampuje `LockStamp` z `CurrentUser` (strojový kontext → NULL, UI
  „Uzamčeno (import)").
- „Podáno" samo nezamyká (D18). Alert `economy.vat.filed_unlocked_periods`
  (denně): podané řádné přiznání starší 3 dnů bez zámku.
- `VatPeriodRecalculator` (D26): plán změn ukazatelů se ověří proti
  `locked` dotčených instancí (odkud i kam); kolize = `DomainException`
  s výčtem dokladů → uložení sousední instance se odroluje.

### Kontrola zůstatků 343 (D31)

`ClosedPeriodBalanceService`: pro instanci `return` s podaným podáním
Σ deníku na `343%` (mimo 343801/343802) přes doklady instance
(`vat_period`, `docState != 90`) per analytika; nenulové (|Σ| > 0,005) =
nález. Konzumenti: alert `economy.vat.closed_period_balance` (denně,
finding per instance × účet, akce otevřít tvrzení), sekce **Zůstatky DPH**
v detailu instance, varování `FiscalMonthDocument` při zamykání měsíce
(instance končící v měsíci). Do F4b (`tasks/vat-filing-accounting.md`)
hlásí všechny podané instance s DPH — to je očekávané; F4b přidá do
množiny účetní doklady podání (`acc_document`) a kontrolu „zhasne".

Extension `docs_core_heads` dostala indexy `idx_vat_period` /
`idx_cs_period` / `idx_rs_period`.

## Zaúčtování přiznání (Fáze 4b, D28–D31)

Podané přiznání dostane **účetní doklad per podání** (`cmnbkp`,
`economy_vat_filings.acc_document`), který vynuluje analytiky 343 a zaúčtuje
závazek / pohledávku vůči správci daně. Starý Shipard měl jeden doklad na
report a přepisoval ho; tady se nic nepřepisuje — součet dokladů za instanci
je vždy poslední podaná pravda:

- **řádné** podání účtuje plný obsah; **opravné** i **dodatečné** jen
  **rozdíl proti kumulativnímu podanému stavu** (D28) — dokladové řádky
  a přesné hodnoty snapshotu jsou vždy plný obsah, kumulativní podaný stav
  skládá `FilingSnapshotLoader::cumulativeFiledRows()` (sdílené s composerem);
- **obsah dokladu** (`Accounting\VatReturnAccountingBuilder`, čistý, D29):
  1. per kód DPH delta daně (vstupní kód DAL, výstupní MD, záporná delta
     otočí stranu; účet z předpisu `cat: vat` / konvence `343{NNN}`),
  2. **saldo řádek** ze změny *podané* daňové povinnosti — závazek
     z kumulativního stavu po tomto podání minus před ním (řádné = ř. 64/65,
     opravné = rozdíl vs. kumulativní stav, dodatečné = ř. 66); kladná →
     DAL `vat.payable` 343801 (splatnost +25 d), záporná → MD `vat.receivable`
     343802 (+60 d); partner = **správce daně** registrace (D30,
     `tax_office_person`), VS = DIČ bez prefixu země, SS = `705` + RRRRMM
     konce období, KS 1148 (`reportTypes.return.accounting`),
  3. **neuplatnitelná část krácených kódů** (přesně: krácený sloupec ř. 46 −
     ř. 52, delta proti předchozímu) na `vat.nondeductible` 548 — starý systém
     ji nechával v zaokrouhlení,
  4. zbytek do Σ MD = Σ DAL na `rounding.cost` / `rounding.revenue`
     s tolerancí 0,5 Kč × počet řádků DP3 s daní; větší = chyba (rozjetý
     snapshot / mapování);
- chybějící účet (analytika, saldo, 548/648) = **chyba, doklad nevzniká** —
  uživatel účet doplní a akci spustí znovu (odchylka od zadání, které chtělo
  doklad bez saldo řádku: nevyrovnaný koncept nikomu nepomůže);
- **explicitní akce Zaúčtovat** (D31) v detailu podaného podání typu
  přiznání (`FilingsViewer`, `POST /_vat/filing-account`, CLI
  `vat-filing-account`); doklad vzniká jako **koncept** — uživatel ho
  zkontroluje a uzavře, deník vznikne až tím. Žádné automatické účtování
  při podání.

`Accounting\VatReturnAccountingService` načte snapshot, předchozí podaný
stav, registraci a účty (`AccountMaskResolver` nad předpisem), nechá builder
sestavit plán a v **jedné transakci** (`TransactionlessTableGateway`)
založí doklad s řádky `acc.record` (`price_calc_mode = 1`, saldo pole per
řádek) a zapíše `acc_document` + záznam `vatReturn.accounted` (a varování
builderu) do `messages` podání — jediná povolená změna zmrazených zpráv
(`FilingDocument`). Idempotence: živý `acc_document` (mimo Storno/Smazáno)
→ `ALREADY_ACCOUNTED`, po stornu dokladu vznikne nový a FK se přepíše.
Řadu dokladu určuje volitelný parametr vrstvy C
`economy.vat.filingAccountingSeries`; bez něj jen jediná aktivní řada
`cmnbkp`, při více řadách služba odmítne s pokynem klíč nastavit (žádný
tichý výběr první řady). Doklad nemá registraci ani rekapitulaci, takže
ho **zámek instance nechytá**; zámek fiskálního měsíce (`accounting_date`
= konec období) ano → `SAVE_FAILED` s důvodem — pořadí je zaúčtovat, pak
zamknout měsíc (varování při zamykání měsíce z F4a to hlídá).

`ClosedPeriodBalanceService` počítá vedle dokladů instance i účetní doklady
jejích podaných podání (`acc_document`, `docState != 90`); po uzavření
dokladu přiznání je zůstatek 343 (mimo 801/802) nula — a znovu nenulový,
když se DPH po podání změní, dokud nevznikne dodatečné podání a jeho
zaúčtování. Kód DPH mimo přiznání (`dp3_row` NULL) do vypořádání nevstupuje.

Správce daně je ručně vybraná osoba na registraci (formulář, i ve stavu
V pořádku bez „Opravit" — `getReadOnlyEditableColumns`, `docs/edit-forms.md`
kap. 26) nebo import přes `POST /_vat/registration-tax-office`. Bez něj
doklad vznikne se saldo řádkem bez partnera a varováním ve zprávách podání.

Zlatý test (dev DS `btpg-p`, zdroj 689089, DP3 01–04/2026 přes
`vat-filing-account --dry-run` nad koncepty podání): viz
`tasks/vat-filing-accounting.md` → Hotovo když.

## Import starých podání (D21, D32–D39)

Podaná podání ze starého Shipardu se importují jako **plnohodnotná podání
s `origin = imported`** (`economy_vat_filings.origin`, cfgItem
`economy.vat.filingOrigins`): bez nich nemá dodatečné podání za období
podané ve starém systému proti čemu diffovat, kontrola zůstatků 343 nemá
`acc_document` a zamčené instance nemají v UI žádné podání.

**Snapshot = composer nad dnešními doklady, podané hodnoty z původního
XML (D33).** `Import\FilingImportService::import()` v jedné transakci:

1. přečte XML (`Import\EpoXmlDocument` — jediný parser souboru pro EPO na
   vstupu, bez sítě a bez DTD), typ písemnosti musí sedět s instancí;
2. založí koncept přes `TransactionlessTableGateway` — Document sestaví
   snapshot composerem (dodatečné jako diff proti kumulativnímu stavu, který
   už tvoří dřívější importovaná podání);
3. u přiznání přepíše `*_filed` sloupce `filing_return_rows` hodnotami
   z XML (`Import\Dp3XmlReader` = `vat-xml-cz.jsonc` `rows` obráceně,
   chybějící atribut = nula); rozdíl proti zaokrouhleným přesným (D17) →
   `imported_row_mismatch {row, composed, filed}`, atributy hodnotových vět
   mimo mapování → `imported_row_unmapped`; `result.return.row62–66` se
   obnoví z přepsaných hodnot;
4. hlavičku předvyplněnou z profilu přepíše větou D/P v mezích dnešního
   schématu (název státu → ISO kód přes `VatXmlMapping::countryCode()`,
   A/N → boolean, datum → ISO); pole, které dnešní schéma nepustí,
   zůstane z profilu a zapíše se `imported_header_invalid` — jinak by
   formulář odmítl i editaci poznámky; forma vs. druh podání →
   `imported_kind_mismatch`;
5. u KH/SH řádky **nepřepisuje** — writer nad čerstvým snapshotem (bez
   validace a XSD) proti XML porovná `Import\EpoXmlLineComparer` podle klíče
   z mapování (sekce + ev. číslo + DIČ + kód) → `imported_line_mismatch
   {section, key, field, composed, filed, kind}`;
6. připojí zprávy za composerovy (`imported_without_xml`,
   `imported_order_irregular`, `imported_legacy {filingNdx, reportNdx}`,
   `imported_acc_document_missing`) a commitne (`--dry-run` = rollback).

Podání zůstane v 10, runner nahraje původní soubory přes
`POST /_attachments/upload` (`table_id 443`) a zavolá
**`POST /_vat/filing-import-finish`**: přílohy bez druhu dostanou
`metadata.kind` (`epo-xml` u XML, jinak `epo-imported`) — od podání je
chrání `FilingAttachmentGuard` — a podání se převede do 40 částečným
payloadem s `imported_files`. Idempotentní; přerušený běh runner dokončí
podle `DRAFT_EXISTS.details` (id konceptu + legacy).

`FilingDocument` pro `imported`: dodaný `name` se neskládá, `acc_document`
smí už na koncept (jen živý cmnbkp; chybějící = varování, FK prázdné —
D37), pořadí druhů se nevynucuje (`isOrderIrregular` → zpráva), přechod
do Podáno **nevaliduje XML ani negeneruje soubory**;
`FilingFilesService::generate()` importované podání odmítne (`build()`
zůstává pro `vat-filing-xml-diff`, D39). Viewer: čip **Import**, v přehledu
„Původ" s počtem rozdílů, záložka **Rozdíly importu**; Přepočítat, Načíst
hlavičku a Vytvořit soubory se nenabízejí, Zaúčtovat ano.

Odmítnutí bez zápisu: `PERIOD_NOT_FOUND` (i jiný typ instance),
`INVALID_KIND`, `DATE_FOUND_REQUIRED`, `PREVIOUS_FILING_MISSING`
(dodatečné bez podaného základu — diff by neměl proti čemu vzniknout
a další řetěz by ho bral jako plný stav), `DRAFT_EXISTS`, `XML_UNREADABLE`,
`XML_TYPE_MISMATCH`. CLI `vat-filing-import` (`docs/cli.md`) je obálka pro
ruční doplnění jednoho podání včetně příloh a finish. Ověření na
`btpg-p` (D39) po `old_shipard` task 37: `tasks/vat-filings-import.md`.

## Architektura

```
src/
├── VatOutputsMapping.php                  # resolver cfgItem; neznámý kód = výjimka
├── VatDocumentSelection.php               # heads (docState 40) WHERE <xx>_period = instance
│                                          #   + recap + DIČ ze snapshotů
├── VatReturnCalculator.php                # DP3: sumace per (řádek, sloupec) + dopočty (vč. ř. 52)
├── DeductionCoefficientDocument.php       # koeficient odpočtu per registrace × rok: validace
├── DeductionCoefficientResolver.php       # zálohový koeficient roku (explicitní → loňský vypořádací → 1,00)
├── DeductionCoefficientsViewer.php / DeductionCoefficientsForm.php
├── ControlStatementCalculator.php         # KH (CS): rozpad sekcí, limit 10 000, pásma, měkké chyby
├── RecapitulativeStatementCalculator.php  # SH (RS): agregace (kod, DIČ) → počet + hodnota
├── VatJournalCrossCheck.php               # recap tax_dom vs 343 analytiky deníku
├── ReportPeriodDocument.php               # instance: validace, guardy, přepočet po změně rozsahu
├── ReportPeriodsViewer.php / ReportPeriodsForm.php
├── ReportPeriodLookup.php                 # rozhraní „instance pokrývající datum" pro assigner
├── ReportPeriodsProvisioner.php           # find/create (koncept), seed, cron; čistý kandidát
├── VatPeriodAssigner.php                  # pravidlo zařazení (DUZP, clamped DPPD, membership)
├── VatPeriodRecalculator.php              # dávkový přepočet po změně rozsahu
├── DocsHeadsVatPeriodHandler.php          # beforeSave handler na docs_core_heads
├── VatRegistrationSeedHandler.php         # afterSave handler na registraci
├── FilingDocument.php                     # podání: druhy, pořadí, lifecycle, immutabilita, import mód (origin)
├── FilingRounding.php                     # podané hodnoty (čistá třída): řádky na Kč, dopočty, diff
├── FilingComposer.php                     # snapshot: items + výstupní řádky + result/messages
├── FilingSnapshotLoader.php               # čtení snapshotu + kumulativní podaný stav (composer, zaúčtování)
├── FilingsViewer.php / FilingsForm.php    # viewer Podání DPH (vč. rozdílů, akce Zaúčtovat) a formulář
├── VatFilingController.php                # POST /_vat/filing-compose, -files, -account, -import, -import-finish, report-period-lock, registration-tax-office
├── ClosedPeriodBalanceService.php         # zůstatky 343 podaných instancí (doklady instance + doklady podání)
├── Accounting/                            # zaúčtování přiznání (Fáze 4b)
│   ├── VatReturnAccountingBuilder.php     #   čistý builder řádků (delta per kód, saldo, krácení, zaokrouhlení)
│   ├── VatReturnAccountingInput.php / …Plan.php / …Result.php
│   └── VatReturnAccountingService.php     #   DB + TableGateway: cmnbkp koncept + acc_document v jedné transakci
├── FilingHeaderSchema.php                 # výběr schématu hlavičky per typ + předvyplnění
├── FilingAttachmentGuard.php              # soubory podaného tvrzení jsou zamčené
├── Import/                                # import starých podání (D21, D32–D39)
│   ├── FilingImportService.php            #   založení + override z XML + finish v jedné transakci
│   ├── FilingImportRequest.php / …Result.php / …Exception.php
│   ├── EpoXmlDocument.php                 #   parser souboru pro EPO (věty → mapy atributů)
│   ├── Dp3XmlReader.php + Dp3XmlData.php  #   mapování rows obráceně → podané hodnoty řádků
│   └── EpoXmlLineComparer.php             #   řádky KH/SH podle klíče z mapování
├── Xml/                                   # XML pro EPO (Fáze 3) — viz výše
│   ├── VatXmlMapping.php                  #   resolver mapování per písemnost
│   ├── FilingXmlInput.php / …Loader.php   #   obsah podání jako hodnota (ze snapshotu)
│   ├── FilingPeriod.php                   #   měsíc / čtvrtletí / částečné období
│   ├── FilingHeaderResolver.php           #   věta D a P (odvozená pole)
│   ├── EpoXmlWriter.php + Dp3/Kh1/Shv     #   generátory per písemnost
│   ├── EpoXmlFormat.php                   #   datum, čísla, DIČ, zalomení textu
│   ├── FilingXmlValidator.php             #   co schéma neumí (field-level chyby)
│   ├── EpoXsdValidator.php                #   validace proti xsd/ v repozitáři
│   ├── FilingFilesService.php + Factory   #   soubory → přílohy, lifecycle
│   ├── FilingPdfService.php + templates/  #   opis a obsah přes RenderClient
│   └── EpoXmlDiff.php                     #   porovnání po větách (zlatý test)
├── Checks/DraftReportPeriodsCheck.php     # alert: koncepty instancí
└── Reports/
    ├── VatReportSupport.php               # sdílené kusy builderů (kompozice)
    └── Vat*LiveBuilder.php                # tenké ReportBuilder adaptéry
```

Kalkulátory jsou **čisté** (vstup = pole z `VatDocumentSelection`) a testují
se na syntetických datech bez DB. Referenční logika sekcí KH a dopočtů DP3
pochází ze starého Shipardu (`modules/e10doc/taxes`), detaily pravidel jsou
v docblocích kalkulátorů a v zadání.

## Hranice report vs. filing

Živé výstupy jsou **reporty** — vždy přepočtené, bez lifecycle. **Podání**
je doména `filing`: snapshot s lifecyclem, druhy podání a zaokrouhlením
(Fáze 2) plus soubory pro daňový portál (Fáze 3, viz níže). Zámek instance,
vynucení proti změnám dokladů (Fáze 4a) a zaúčtování přiznání (Fáze 4b)
jsou popsané výše.

## Mimo scope

Odeslání na portál a do datové schránky (podává člověk), import rozpracovaných starých podání (bere se jen podané, D32),
storno řádky následného souhrnného hlášení (X14), odpověď na výzvu u KH,
sekce A.3 (investiční zlato), rozdíly mezi
dvěma libovolnými podáními (jen proti `previous_filing`), oprava dle § 44
v A4, investiční zlato (A3), ř. 45/47/53/60, OSS a registrace jako
samostatný parametr reportů (přijde s OSS / více DIČ).
