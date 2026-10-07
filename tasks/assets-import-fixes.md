# Majetek — opravy po prvním ostrém importu

**Stav:** hotovo — 2026-10-07, čtyři commity; runner navazuje (řádky bez účtu, nulové účetní odpisy v mezerách)

> PRD pro jednu Claude Code session (4 commity). Design: `docs/assets.md`
> §4 D44, D73–D86, §5.6, §5.7; issue #83.

## Kontext

První ostrý import majetku (2026-10-07) na migrovaných zdrojích prošel:
počty karet, doplnění karty na doklady i kontrola proti deníku sedí, zbylé
rozdíly jsou ze starých dat. Ověření ale ukázalo čtyři věci, které je
potřeba opravit v novém Shipardu (D83–D86):

1. Časová účetní metoda po uplynutí doby odepíše celý zůstatek najednou
   (D44) — u budovy se zhodnoceními z let 2014–2020 by plán 2026 odepsal
   přes 10 mil. v jednom roce. Je to správně podle pravidla, ale uživatel
   o tom musí vědět a dobu na kartě prodloužit (D83).
2. Kontrola evidence × deník za rok, který v deníku ještě nemá počáteční
   stavy (následující účetní rok), hlásí falešné chyby u všech zdrojů;
   `assets-import-verify` ho kontroluje taky a účetní okruh × deník
   porovnává i v letech, kdy starý deník kartu nenesl (D85).
3. Doplnění karty na doklady neumí řádky s prázdným `account` — řádky
   faktur a pokladních dokladů s účtem z položky nebo kategorie; runner je
   dnes vynechává (desítky řádků per zdroj, D86).
4. Mezery v účetním okruhu (D84) řeší runner nulovými odpisy — tady jen
   ověřit, že nulový účetní odpis importu projde a plán pak běží.

## Cíl

1. Varování plánu „doba odpisování uplynula“ + výpis v ověření (D83).
2. Kontrola a ověření bez falešných nálezů za neotevřený rok (D85).
3. Párování řádků bez účtu v `doc-links` (D86).
4. Test nulového účetního odpisu v importu (D84), dokumentace.

## Před implementací přečti

- `docs/assets.md` §4 D44, D73–D86, §5.2, §5.6, §5.7
- `modules/economy/assets/src/Depreciation/Method/AccTimeMethod.php`,
  `Depreciation/CircuitWalker.php`, `Depreciation/PlanMessage.php`,
  `Depreciation/PlanMessageTexts.php`, `config/planMessages.jsonc`
- `modules/economy/assets/src/AssetJournalCheck.php` (`accounts()`),
  `Reports/JournalCheckBuilder.php`
- `modules/economy/assets/src/Import/AssetImportVerifier.php`,
  `Import/AssetDocLinkService.php` (`match()`, `shapeIssues()`)

## Scope

**Uvnitř:** vše v Cíli.
**Mimo:** runner (navazující task ve starém Shipardu); sazba pro zvýšenou
vstupní cenu u majetku zařazeného před 1999 (otevřená otázka §8);
ruční opravy dat zdrojů.

## 1. Uplynulá účetní doba (D83)

`AccTimeMethod`: počítá-li období, které začíná po posledním měsíci doby
odpisování (`end`), a zůstatek je kladný, vrátí plán vedle částky
**varování** `accPeriodElapsed` (parametry: konec doby, zůstatek). Text
v `planMessages.jsonc` (cs/en), např. „Doba účetního odpisování skončila
{date}; zůstatek {amount} se odepíše v jednom období. Je-li to chyba,
prodluž dobu na kartě.“ Varování platí jen pro **plánované** (nepotvrzené)
období; potvrzené odpisy po konci doby ho nedávají.

`assets-import-verify`: nová sekce `accPeriodElapsed` — karty, jejichž plán
nese toto varování (karta, konec doby, částka nejbližšího plánovaného
období). Do exit kódu se nepočítá (je to upozornění, ne rozdíl importu),
v souhrnu má vlastní počet.

Ověřit, že prodloužení doby na kartě (Opravit → delší `acc_months` →
V pořádku) plán přepočítá a varování zmizí — test na plánovači.

Testy: karta zařazená před 30 lety se zhodnocením, doba 360 měsíců →
plán příštího roku = celý zůstatek + varování; s dobou 600 měsíců
rovnoměrně bez varování.

## 2. Rok bez počátečních stavů (D85)

`AssetJournalCheck::accounts()`: má-li otevírací období roku (měsíce před
běžnými) v deníku **nula řádků** na všech účtech účetních skupin
a evidence k začátku roku stav má, účty majetku, pořízení a oprávek se
neporovnávají (rozdíl 0, sloupec deníku prázdný / `null` podle toho, co
`ReportResult` snese) a výsledek nese příznak. `JournalCheckBuilder` k tomu
přidá jedno varování `assets.journalCheck.noOpeningBalances`: „Rok {rok}
nemá v deníku počáteční stavy — účty majetku, pořízení a oprávek se
porovnají až po otevření roku.“ Účty odpisů a ZC (obratem) beze změny.

`AssetImportVerifier`:
- kontrola evidence × deník po letech **jen do aktuálního účetního roku**
  (podle dnešního data, ne posledního založeného roku);
- účetní okruh × deník po kartách a letech **od prvního roku**, kdy deník
  nese dimenzi `asset` na některém účtu odpisů; dřívější roky se neporovnají
  a souhrn uvede, od kterého roku se porovnává.

Testy: rok s prázdným otevíracím obdobím → `warnings` místo `errors`;
rok s otevíracím obdobím beze změny; verifier vynechá budoucí rok a roky
před vazbou.

## 3. Řádky bez účtu v `doc-links` (D86)

`account` v řádku payloadu je nepovinný (nebo `null`). Bez účtu jsou
kandidáti řádky dokladu s `account IS NULL`, `vat_base_dom` = `amount` na
haléř a stranou, je-li uvedená; víc kandidátů rozhodne `orderHint`, jinak
`ambiguous`. Ostatní pravidla beze změny (celý doklad, nebo nic; přegenerování
deníku s pojistkou obratů). Deník dostane dimenzi z řádku — ověřit testem
na řádku s účtem z kategorie operace (účet řeší až předpis).

Testy: faktura s řádkem bez účtu (položka) a řádkem s účtem; dva řádky
bez účtu se stejnou částkou → `orderHint`; deník po doplnění nese kartu
u řádku z kategorie a obraty se nezměnily.

## 4. Nulový účetní odpis v importu (D84), dokumentace

- Test applieru: karta s účetními odpisy 2015–2017, 2018 nulový (`amount`
  0, poznámka), 2019–2020 → uloží se, plán bez `missingPeriod`, varování
  `mismatch` u nulového roku je v pořádku. Pokud import nulový odpis
  odmítá, uvolnit jen pro původ `import` a zapsat do §5.7.
- `docs/assets.md` §5.7 (D83–D86, odchylky), `docs/exchange-format.md`
  (`doc-links` bez účtu), `docs/cli.md` (`assets-import-verify` — nová
  sekce a rozsah let), nápověda k plánu karty (varování D83), Stav tasku.

## Hotovo když

- [x] Plán karty s uplynulou účetní dobou nese varování, ověření ho vypíše,
      delší doba ho odstraní.
- [x] Kontrola za rok bez počátečních stavů vrací `warnings` s jedním
      varováním; `assets-import-verify` končí aktuálním rokem a účetní okruh
      porovnává od prvního roku s vazbou.
- [x] `doc-links` páruje řádky bez účtu podle částky a `orderHint`.
- [x] Nulový účetní odpis importem projde a odblokuje plán (import ho
      neodmítal ani dřív — jen testy a §5.7).
- [x] Ověření na dev zdroji s ostrým importem (čtení): `assets-import-verify
      --json` na `btpg-p` vypíše budovu s uplynulou dobou a nehlásí rok
      2027; na `p4zq-s` exit 0; `e8w1-i` exit 1 jen kvůli třem známým
      rozdílům −1 Kč zlatého testu (rozhodnuto 2026-10-07 nechat bez
      tolerance). Výsledek níže.
- [x] PHPUnit (úzké filtry) zelené, dokumentace aktualizovaná.

## Výsledek ověření (2026-10-07, jen čtení)

| zdroj | před | po |
|---|---|---|
| `btpg-p` | 46 účetních rozdílů (2012–2017), kontrola 2017–2027 vše `errors` | účetní okruh od 2017 (první rok s vazbou): 9 rozdílů jen 2017; kontrola 2017–2026; **uplynulá doba: 1 karta** (budova, doba do 10/2022, plán 2026 přes 10 mil.); exit 1 (rozdíly ze starých dat) |
| `e8w1-i` | 11 účetních rozdílů (2012–2017), 2027 `errors` (14× `accountMismatch`) | účetní okruh od 2018: 0 rozdílů; kontrola 2018–2026 vše `ok`; exit 1 jen kvůli 3 × −1 Kč zlatého testu jedné karty (2013–2015) |
| `p4zq-s` | 2027 `errors` → exit 1 | kontrola 2017–2026 (2026 `warnings`), **exit 0** |

Report Kontrola evidence × deník za 2027 na `p4zq-s`: stav `warnings`,
jediná zpráva `noOpeningBalances`, účty se stavem bez hodnoty deníku.

## Doporučené pořadí commitů

1. D83 — varování plánu + sekce v ověření.
2. D85 — kontrola a ověření bez neotevřeného roku a let bez vazby.
3. D86 — `doc-links` bez účtu.
4. D84 test, dokumentace, stav tasku.
