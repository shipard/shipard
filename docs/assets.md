# Shipard — Majetek (`economy.assets`)

> **Designový dokument.** **Stav:** D1–D26 rozhodnuto; oblast 1 má PRD
> (`tasks/assets-phase1.md`), další oblasti se rozpadají postupně (§7).
> **Datum:** 2026-09-29 · **Milník:** M4 (blokátor migrace) ·
> **Issue:** #83

Nový modul evidence majetku, daňových a účetních odpisů a jejich
zaúčtování. Nahrazuje `e10pro.property` starého Shipardu — **ne jako
přepis**, ale jako model očištěný od ručních operací a nedostatků, které
rozbor starého modulu a jeho dat odhalil (§2, §3).

---

## 1. Cíl a hranice

**Cíl:** firma vede v novém Shipardu kompletní evidenci majetku
(dlouhodobého i drobného), systém sám spočítá daňové i účetní odpisy,
sám je zaúčtuje a účetní okruh evidence vždy souhlasí s deníkem.

**Mimo rozsah:** kniha jízd (ve starém Shipardu vazba `useVehicleLogbook`
→ `e10pro.vlb`) — v praxi se nepoužívala a do nového se nepřenáší (D1).

**Invarianty modulu** (co nesmí nikdy přestat platit):

1. Součet zaúčtovaných účetních odpisů majetku za období = obraty
   odpisových účtů v deníku s vazbou na tento majetek (D4, D9).
2. Zůstatková cena se nikde neukládá jako zdroj pravdy — je vždy odvozená
   z událostí (D3).
3. Potvrzená nebo zaúčtovaná událost se nemění; oprava je nová událost
   nebo storno přes doklad (D3).
4. Daňová pravidla nejsou v kódu enginu, ale v konfiguraci země (D6).

---

## 2. Rozbor starého modulu `e10pro.property`

### 2.1 Tabulky

| Tabulka | Obsah | Poznámka |
|---|---|---|
| `property` | karta majetku: typ, inv. č., druh (0 evidovaný, 1 DHM, 2 DNM, 3 neodepisovaný, 4 leasing), způsob sledování (jednotlivá věc / množstevní karta / soubor), odpisová skupina + daňová metoda (AR/AZ), účetní metoda (AS/AC/AV/AM) + délka, účetní skupina (`debsGroup` → účty), cizí majetek + vlastník, datum pořízení / vyřazení, pořizovací cena | `useDepreciation`, `intangibleProperty` jsou mrtvé sloupce |
| `deps` | hodnotová historie: `depsPart` 0 = pohyb společný oběma okruhům, 1 = daňový odpis, 2 = účetní odpis; `rowType` 1 zařazení, 2 TZ, 4 snížení, 99 odpis, 110 přerušení, 120 vyřazení; `depreciation` / `usedDepreciation` / `balance` | zůstatky se po uložení přepisují do řádků (`resetBalances`) |
| `operations` + `states` | předání / vrácení z užívání, zápůjčka, výdej / příjem (množstevní karta) na osobu, středisko, místo; `states` = denormalizovaný „kdo to má teď“ | místa = `e10.base.places` |
| `propertyAccessory` | příslušenství (M:N majetek → majetek) | |
| `types`, `groups` | typy majetku (+ skupiny vlastností `propgroups`), skupiny typů | `types.debsAccountId*` mrtvé |
| `depreciation` | starší varianta odpisů | mimo seznam tabulek modulu, přesto na ni odkazuje formulář |

Kolem: vlastnosti per typ (`e10_base_properties`), štítky (`clsf`),
přílohy, vazba ze síťových zařízení (`mac.lan`).

### 2.2 Odpisový engine

- Plán se počítá **za běhu**; potvrzené odpisy jsou řádky `deps`, zbytek
  plánu se dopočítá do nulové zůstatkové ceny. Potvrzení = ruční kliknutí
  na řádek plánu, per majetek, per období.
- Daňová pravidla v cfg `amortizationPeriod` s platností od–do: sazby
  `cr1/crn/cri` (rovnoměrný), koeficienty `cz1/czn/czi` (zrychlený), NIM
  po měsících, skupina X s ruční délkou, historické „+10/15/20 %“.
  Zaokrouhlení `ceil`, strop zůstatkem; období < 12 měsíců se krátí poměrem.
- Účetní metody: AS (= daňový), AC (časový), AZ (legacy). **AV a AM jsou
  v číselníku, engine je neumí.**
- Počitadlo let = počet zapsaných odpisů (vynechaný rok funguje jako
  přerušení); `rowType` 110 engine neošetřuje.
- Odložená daň: sazby DPPO natvrdo do roku 2016, pak 19 % — **od 2024
  chybně**.
- Poloviční odpis v roce vyřazení (ZDP) ani mimořádné odpisy §30a engine
  neřeší. `AddDeprecationsWizard` volá neexistující metodu (mrtvý kód).

### 2.3 Účtování

- `property` je sloupec hlavičky dokladu, řádku i deníku.
- Operace 1090050–52 (pořízení na přijaté faktuře), 1090060 (prodej),
  1090070–73 (zařazení / vyřazení / odpis / TZ na účetním dokladu).
  Účty přes `accountSrc: property` → účetní skupina majetku
  (`debsAccPropId*`: majetek, pořízení, TZ, odpisy MD/DAL, nákup, prodej).
- **Odpisy modul neúčtuje.** Účetní doklad s aktivitou `prp*` pořizuje
  uživatel ručně; report „Účtování“ spočítá, co se účtovat mělo, a porovná
  s hlavní knihou (013+021+022, 073+081+082). Úbytky účtuje 541100 natvrdo.
- Volba „Sledovat náklady na majetek“ (`usePropertyExpenses`): majetek na
  hlavičce přijaté faktury / pokladního dokladu / řádku účetního dokladu
  → propis do deníku → tab Účtování na kartě.
- `propertyDepsMethod` na účetním období (ročně / měsíčně / manuálně /
  neodepisuje se) — v datech se nedodržuje (§3.2).
- Přiznání DPPO čte odpisy po odpisových skupinách (`taxDepsTaxCI`).

### 2.4 Přehledy

Přehled (seznam + filtry, export), Odpisy (sumárně, zůstatky, karty 1–3,
odložená daň, daňové odpisy, přírůstky, úbytky; seskupení po typu /
odpisové skupině / účtu), Neodepisovaný majetek, Inventarizace, Účtování
(+ kontrola proti HK), Karta majetku (tisk), Inventář místa.

---

## 3. Data — co se reálně používá

Zdroje starého Shipardu `689089`, `732084`, `332718`, `426748`, `205976`
(pět zdrojů s majetkem; `219124` majetek nemá) + importované protějšky
na testovacím serveru. Stav k 2026-09-29.

### 3.1 Rozsah používaných funkcí

| Oblast | Nález |
|---|---|
| Druh | převažuje evidovaný (drobný), pak DHM; DNM jen NIM (5 karet); neodepisovaný ~16; leasing jen smazaný |
| Sledování | soubor používán (`689089` ~28 karet); **množstevní karta nikde** |
| Daňové odpisy | rovnoměrné sk. 1–5 i **zrychlené** (`332718` 9 ze 14 DHM, `689089` 3); NIM; X a „+10/15/20 %“ nikde |
| Účetní odpisy | AC (v letech i měsících) a AS; AV / AM nikde |
| Pohyby | téměř výhradně předání do užívání (osoba + středisko); místo 3×; zápůjčka 1× |
| TZ / snížení hodnoty | TZ ve 4 zdrojích, snížení hodnoty 23 řádků (`689089`) |
| Náklady na majetek | `332718` 146 přijatých faktur (5–6 let, 1–4 karty ročně, účty 5xx + úroky), `426748` 21 |
| Ostatní | příslušenství 2, vlastnosti ~190 hodnot, štítky ~300 karet, přílohy ~400, vazby síťových zařízení 19 |

### 3.2 Konzistence

- **Evidence vs. hlavní kniha:** v letech, kdy deník nese vazbu na majetek
  (typicky od 2017–2018), souhlasí účetní odpisy evidence s MD 551 na
  korunu — **do cca 2022–2023**. V posledních letech (2023–2025) má všech
  pět zdrojů odpisy **jen v hlavní knize** (evidence chybí úplně nebo
  o 15–30 % méně). Ruční vedení evidence souběžně s účtováním v praxi
  nefunguje → D4, D9.
- **Historie před systémem:** odpisy od 1992 existují jen v evidenci;
  deník je buď nemá, nebo je nese bez vazby na majetek (měsíční odpisy
  2013–2015 u `689089`, rok 2019 u `332718`).
- **Celé koruny:** daňové odpisy nejsou vždy celé (14/350, 3/22, 1/41,
  1/13 řádků) — rozpor se ZDP.
- **Přerušení** se vyskytuje ve čtyřech podobách: řádek 110, odpis
  s nulovou částkou, chybějící rok v řadě, neuplatněný odpis
  (`usedDepreciation = 0`) se sníženou zůstatkovou cenou (96 řádků
  `689089` hlavně 1992–2011, jednotky u `332718`).
- **Pořizovací cena** na kartě nesouhlasí s částkou zařazení u ~5 % karet.
- Nastavení „měsíční odpisy“ na období se nedodržuje (účtuje se ročně).
- Stavy řádků odpisů používají i 9000 (archiv) a 9800 (smazané plány
  s budoucími daty).

### 3.3 Stav v novém Shipardu po importu

Import vazbu na majetek dnes **zahazuje** (vlna C: D2, D9 —
`design-import-row-operations.md`):

- řádky pořízení na přijatých fakturách → `purchase.asset` s přímým
  účtem (042 / 501), bez vazby;
- majetkové účetní doklady (1090070–73) → `acc.record` s účty dohledanými
  ze starého deníku, bez vazby (desítky řádků per zdroj);
- hlavičková vazba „náklady na majetek“ → ztracena.

Pozor při kontrolách: většina řádků 02x/08x na importovaných účetních
dokladech jsou počáteční / konečné stavy (`ocpOpen` / `ocpClose`), ne
majetkové operace.

---

## 4. Rozhodnutí

### D1 — Rozsah: vše kromě knihy jízd (ROZHODNUTO)

Modul pokryje celý rozsah starého včetně množstevních karet, souborů,
pohybů, míst, vlastností, příslušenství a odložené daně. Kniha jízd se
nepřenáší. Oblasti se navrhují a implementují **postupně** (§7);
nepoužívané funkce (množstevní karta, AV/AM, skupina X) jdou na konec.

### D2 — Modul `economy.assets` (ROZHODNUTO)

ID modulu `economy.assets`, tabulky `economy_assets_*`, anglicky „asset“
(navazuje na existující operaci `purchase.asset`). UI česky „Majetek“.

### D3 — Hodnotová historie = neměnný ledger událostí (ROZHODNUTO)

Každá změna hodnoty je událost (zařazení, TZ, snížení, odpis, přerušení,
vyřazení) s okruhem (oba / daňový / účetní). Plán odpisů je výpočet,
zůstatky se odvozují, nic se nedenormalizuje do řádků. Potvrzená událost
je neměnná; zaúčtovanou chrání zámek dokladu a období (#55 D24).

### D4 — Zaúčtování generuje systém (ROZHODNUTO)

Akce „Zaúčtovat odpisy za období“ vytvoří účetní doklad (`cmnbkp`)
s řádky nových řádkových operací (`asset.activation`, `asset.improvement`,
`asset.reduction`, `asset.depreciation`, `asset.disposal`) a vazbou na
majetek na řádku i v deníku (extension z modulu). Stejně zařazení,
TZ a vyřazení. Ruční doklad + kontrolní report odpadá; kontrola proti
deníku zůstává jako invariant (§1).

### D5 — Účty z malého číselníku „účetní skupina majetku“ (ROZHODNUTO)

Číselník `economy_assets_accounting_groups`: účet majetku (02x/01x),
pořízení (04x), TZ, oprávky (07x/08x), odpisy (55x), zůstatková cena při
vyřazení (54x), případně prodej. Karta odkazuje na skupinu; typ majetku
může nést výchozí skupinu. Nahrazuje `debsGroup` + natvrdo zadrátované
541100.

### D6 — Daňová pravidla jako konfigurace země, engine čistý (ROZHODNUTO)

Odpisové skupiny, sazby, koeficienty, délky a platnosti žijí
v konfiguraci země (`world.cz`), verzované platností. Engine je čistá
funkce (události + pravidla → plán), bez DB, se zlatými testy. Návrh
počítá s dalšími státy EU: rozhraní pravidel per země, žádné
`if (cz)` v enginu, okruhy daňový / účetní obecně (ne „ZDP“). Další země
se neimplementují.

**Zlatý test:** pro každý importovaný majetek musí plán nového enginu
reprodukovat potvrzené daňové odpisy starého (odchylky vysvětlené v D10,
D11).

### D7 — Přerušení a poloviční odpis při vyřazení (ROZHODNUTO)

Přerušení je explicitní událost (odpis se neuplatní, zůstatková cena
se nesnižuje, doba odpisování se prodlužuje). V roce vyřazení engine
nabídne polovinu ročního daňového odpisu dle pravidel země. Mimořádné
odpisy (§30a) — viz §8.

### D8 — Import: karty + historie + zpětné navázání dokladů (ROZHODNUTO)

Import přenese karty, typy, skupiny, hodnotovou historii, pohyby,
příslušenství, vlastnosti, štítky a přílohy. **Už importované doklady se
nereimportují** — vazba na majetek se doplní backfillem (řádky
`purchase.asset`, majetkové `acc.record`, hlavičky s náklady na majetek)
přes mapu starý doklad → nový doklad a pozici řádku.

### D9 — Zdroj pravdy při importu (ROZHODNUTO)

- Daňový okruh: z evidence (v deníku není).
- Účetní okruh: z evidence do posledního roku, kdy deník nese odpisy
  s vazbou na majetek; od té doby **z deníku** (odpisy chybějící
  v evidenci se dopočtou z deníkových řádků s vazbou).
- Rozdíly import vypíše v reportu per majetek a rok; potichu je neopravuje.

### D10 — Celé koruny (ROZHODNUTO)

Nové daňové odpisy se zaokrouhlují dle pravidel země (CZ: nahoru na celé
koruny) a jinak nejdou uložit. Importované necelé hodnoty zůstanou jako
historický fakt s příznakem.

### D11 — Import přerušení (ROZHODNUTO)

Řádek 110, odpis s nulovou částkou a chybějící rok v řadě se importují
jako událost přerušení (částky se tím nemění). Historické „neuplatněné“
odpisy se sníženou zůstatkovou cenou (`usedDepreciation = 0`) se
**přebírají doslova s příznakem** „neuplatněno“ — daňové zůstatky musí
sedět se starým systémem; podklad pro přiznání bere uplatněnou částku.

### D12 — Četnost účetních odpisů (ROZHODNUTO)

Nastavení zdroje dat (ročně / měsíčně), engine umí obojí; daňové odpisy
vždy ročně (dle pravidel země). Nahrazuje `propertyDepsMethod`.

### D13 — Pořizovací cena z událostí (ROZHODNUTO)

U dlouhodobého majetku je vstupní cena součet zařazení + TZ − snížení;
pole na kartě není. Evidovaný (drobný) majetek cenu na kartě má.

### D14 — Vazba na pořizovací doklad (ROZHODNUTO)

Řádek `purchase.asset` nese kartu majetku. Z pořízení na 042 systém
nabídne zařazení (událost + zaúčtování dle D4); z řádku do nákladů (501)
lze založit kartu evidovaného majetku.

### D15 — Majetek jako analytická dimenze (ROZHODNUTO)

Majetek lze přiřadit řádku (s výchozí hodnotou z hlavičky) přijaté
faktury, pokladního a účetního dokladu; vazba se propisuje do deníku.
Karta majetku ukazuje náklady a výnosy z deníku. Import doplní zpětně
(D8). Otevřené: nový Shipard zatím nemá ani střediska na dokladech —
zvážit společný mechanismus analytických dimenzí (středisko, majetek,
později zakázka) místo jednorázového sloupce.

### D16 — Počáteční stav rozepsaného majetku (ROZHODNUTO)

Firma přecházející z jiného systému (nebo nabývající majetek s navazujícím
odpisováním, např. vkladem či přeměnou) nemá v Shipardu historii. Místo
ní se zadá **událost „počáteční stav“** k **začátku účetního období**
(přechod v průběhu roku se neřeší: odpisy za rok se dogenerují v Shipardu
a případně ručně zkorigují) — souhrn historie,
ze kterého engine pokračuje stejně, jako kdyby historii znal:

- **daňový okruh:** vstupní cena (vč. zvýšení TZ), oprávky → zůstatková
  cena, skupina a metoda (nelze měnit), **počet let již uplatněných
  odpisů** (rozhoduje o sazbě / koeficientu), příznak zvýšené vstupní ceny,
  datum původního zařazení;
- **účetní okruh:** vstupní cena, oprávky, metoda, zbývající doba
  odpisování (nebo původní délka + uplynulé měsíce).

Engine hodnoty ověří proti pravidlům země (např. oprávky vs. počet let
a sazby) a nesoulad hlásí varováním, neblokuje — historie mohla obsahovat
přerušení nebo TZ. Původní karty se přikládají jako příloha.

Návaznost na účetnictví: řádky počátečních stavů účtů majetku a oprávek
nesou majetek (D15) → invariant §1 platí od prvního dne; kontrolní
přehled porovná součty počátečních stavů karet per účetní skupina se
zůstatky účtů. Hromadné zadání tabulkou (import souboru) pro desítky
karet; stejný formát může použít i import ze starého Shipardu.

### D17 — Historie před startem starého Shipardu (ROZHODNUTO)

Data ukazují, jak se do starého Shipardu „naskakovalo“ během odepisování:
**zpětným zadáním celé roční historie** (`689089`: 12 karet s řadou od
1992, start systému 2013; `332718`: 3 karty, 1 rok zpět). Souhrnný
počáteční stav se nepoužíval. Historické řádky mají často
`usedDepreciation = 0` — nejspíš „uplatněno v předchozím systému“, ne
skutečné přerušení; příznak z D11 proto pojmenovat neutrálně („uplatněná
částka neevidována“).

Návrh: import přebírá historii doslova (D9, D11), událost počátečního
stavu (D16) automaticky nevytváří. Karty, u nichž zlatý test (D6) neumí
z historie reprodukovat pokračování (neúplná historie, chybný počet let),
import vypíše; uživatel je v novém Shipardu převede na počáteční stav
ručně nebo tabulkou.

### D18–D26 — Oblast 1: karta, typy, účetní skupiny (ROZHODNUTO)

PRD: `tasks/assets-phase1.md`.

- **D18** Tabulky `economy_assets_assets`, `_types`, `_type_groups`,
  `_accounting_groups` (tableId 450–453).
- **D19** Druhy: drobný (evidovaný), dlouhodobý hmotný, dlouhodobý
  nehmotný, neodepisovaný dlouhodobý. Leasing jako druh zaniká (→ D20).
- **D20** Cizí majetek = příznak + vlastník (osoba); pokrývá leasing,
  pronájem, zápůjčku od třetí strany.
- **D21** Způsob sledování (jednotlivá věc / soubor / množstevní karta)
  jako sloupec hned; chování ve fázi 8.
- **D22** Inventární číslo: text ≤ 20 znaků, unikátní v DS; prázdné se
  přidělí při potvrzení jako prefix dle druhu (nastavitelný, výchozí
  `MA`) + 4 místa; importovaná čísla zůstávají.
- **D23** Stavy archivní sady; V archívu = vyřazeno. Karta nese datum
  pořízení a vyřazení, cenu jen drobný majetek (D13); u dlouhodobého
  majetku data převezmou události (fáze 2).
- **D24** Účetní skupina: účet majetku, pořízení, oprávek, odpisů,
  zůstatkové ceny při vyřazení; povinná u dlouhodobého; seed 7 skupin
  podle osnovy (jen DS bez `skipProvisioning`).
- **D25** Typ nese skupinu typů, výchozí druh a účetní skupinu; na kartě
  nepovinný; bez seedu.
- **D26** Sekce navigace a Nastavení **Majetek**, přílohy karty, help
  stránky.

---

## 5. Doménový model (návrh)

Ilustrativní — konkrétní sloupce se zamknou v PRD jednotlivých oblastí.

| Tabulka | Obsah |
|---|---|
| `economy_assets_assets` | karta: inv. číslo, název, typ, druh, způsob sledování, účetní skupina (D5), daňové nastavení (kód skupiny a metody z konfigurace země), účetní metoda + délka, cizí majetek + vlastník, data pořízení / vyřazení, cena jen u evidovaného (D13), stav |
| `economy_assets_types`, `…_type_groups` | typy a skupiny typů; výchozí druh, účetní skupina, schéma vlastností |
| `economy_assets_accounting_groups` | D5 |
| `economy_assets_events` | D3: majetek, druh události, okruh, datum, období od–do, částka, stav (návrh / potvrzeno / zaúčtováno), vazba na doklad a řádek |
| `economy_assets_custody` | pohyby: předání / vrácení / zápůjčka; množstevní příjem / výdej |
| `economy_assets_accessories` | příslušenství |
| extensions | sloupec `asset` na `docs_core_rows` a `economy_accounting_journal` (D4, D15) |

**Vlastnosti per typ** — kandidát na strukturovaná pole
(`structured-fields.md`): sada se mění podle typu, hodnoty jsou opis, ne
zdroj výpočtu. Ověřit v oblasti §7, zda se podle nich filtruje.

**Engine** (`DepreciationPlanner`): vstup = události majetku + nastavení
karty + pravidla země; výstup = plán per okruh (období, výpočet,
částka, zůstatek, zdroj řádku: potvrzeno / plán). Bez DB, bez UI.

---

## 6. Import (kontrakt pro `old_shipard`)

Pořadí dle `ai-workflow.md`: nejdřív nový Shipard (modul, applier,
extensions), pak runner. Kroky:

1. číselníky (typy, skupiny, účetní skupiny z `e10doc_debs_groups`),
2. karty (+ vlastnosti, štítky, přílohy, příslušenství),
3. hodnotová historie dle D9–D11 (mapování `depsPart` / `rowType` /
   stavů na události),
4. pohyby,
5. backfill vazeb na doklady (D8, D15),
6. kontrola: plán nového enginu vs. staré potvrzené odpisy (D6), účetní
   okruh vs. deník per rok (invariant §1).

---

## 7. Oblasti a pořadí

Probírají se jedna po druhé; každá má vlastní PRD.

1. Karta, typy, účetní skupiny, stavy (základ) — `tasks/assets-phase1.md`
2. Ledger událostí + engine + pravidla CZ (D3, D6, D7, D10)
3. Zaúčtování (D4) + řádkové operace + extension deníku
4. Vazba na doklady: pořízení (D14), analytická dimenze (D15)
5. Přehledy: karta, odpisy, přírůstky / úbytky, kontrola proti deníku,
   podklad pro DPPO
6. Import (D8, D9, D11) + backfill
7. Pohyby, příslušenství, vlastnosti, místa, inventarizace
8. Soubory a množstevní karty, odložená daň, zbytek (AV/AM, X)

---

## 8. Otevřené otázky

- Mimořádné odpisy §30a (2020–2021) — podporovat v pravidlech CZ?
  V datech zatím nenalezeny.
- Místa: vlastní číselník v modulu, nebo obecný číselník míst (využijí
  ho i jiné moduly)?
- Čísla karet (inv. č.): číselná řada per druh, nebo volný text s návrhem?
- D15: společný mechanismus analytických dimenzí.
- Podklad pro přiznání DPPO: vazba odpisových skupin na řádky přiznání
  (ve starém `taxDepsTaxCI`) — patří do konfigurace země.
