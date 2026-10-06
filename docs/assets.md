# Shipard — Majetek (`economy.assets`)

> **Designový dokument.** **Stav:** D1–D82 rozhodnuto;
> oblast 1 (karta, typy, účetní skupiny) **hotová** 2026-09-29
> (`tasks/assets-phase1.md`), oblast 2 **hotová** 2026-09-30 — pravidla
> země a odpisový engine (`tasks/assets-phase2a.md`, §5.1–5.2), události,
> odpisové nastavení karty, plán na kartě a odpisy za období
> (`tasks/assets-phase2b.md`, §5.3), oblast 3 **hotová** 2026-10-01 —
> zaúčtování a dimenze deníku (`tasks/assets-phase3.md`, §5.4), oblast 4
> **hotová** 2026-10-01 — vazba na doklady (`tasks/assets-phase4.md`,
> §5.5), oblast 5 **hotová** 2026-10-01 — přehledy a kontrola evidence
> proti deníku (`tasks/assets-phase5.md`, §5.6); oblast 6 (import)
> naplánována (`tasks/assets-phase6.md`, D73–D82, §6); další oblasti se
> rozpadají postupně (§7).
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

### D27–D45 — Oblast 2: události, engine, pravidla CZ (ROZHODNUTO)

PRD: `tasks/assets-phase2a.md` (pravidla země + engine, bez DB),
`tasks/assets-phase2b.md` (události, nastavení karty, UI, odpisy za období).

- **D27 Rozsah.** Fáze 2 = odpisové nastavení karty, tabulka událostí,
  engine + pravidla CZ, plán odpisů na kartě, ruční události a akce
  „Odpisy za období“. **Nic se neúčtuje** — události zůstanou potvrzené,
  zaúčtování přidá fáze 3. Fáze 2 a 3 jdou do ostrého provozu společně;
  mezi nimi jen testování.
- **D28 Tabulka `economy_assets_events` (454).** Majetek, druh události
  (`opening`, `activation`, `improvement`, `reduction`, `depreciation`,
  `interruption`, `disposal`), okruh (`both` / `tax` / `acc`), datum,
  období od–do, částka, původ (`manual` / `system` / `import`), příznak
  „uplatněná částka neevidována“ (D11). Počáteční stav (D16) je událost
  per okruh s oprávkami, počtem let / měsíců odpisování, příznakem
  zvýšené vstupní ceny a datem původního zařazení. Necelé koruny (D10)
  jen u původu `import`.
- **D29 Stavy událostí.** Koncept → Potvrzeno ↔ V opravě → Smazáno.
  Potvrzenou událost lze opravit či smazat, jen když po ní v témže okruhu
  není potvrzený odpis (historie se rozebírá od konce). Chrání ji i zámek
  účetního měsíce, od fáze 3 zaúčtování.
- **D30 Odpisové nastavení karty.** Daňová metoda (`straight`,
  `accelerated`, `extraordinary`, `time`, `accounting`, `none`), daňová
  skupina / pravidlo (kód z konfigurace země), účetní metoda (`as_tax`,
  `time` + délka v měsících). AV/AM až fáze 8. Nabídku řídí pravidla země.
- **D31 Pravidla země v modulu `world.assets`** (vzor `world.vat`):
  `config/assets-cz.jsonc` (skupiny, sazby, koeficienty, časová
  a mimořádná pravidla s platností, zaokrouhlení, polovina při vyřazení,
  přerušitelné metody) + rozhraní `TaxDepreciationRules`
  a `CzTaxDepreciationRules`. Země z `DataSourceConfig::getCountry()`;
  země bez pravidel nabízí jen `accounting` a `none`.
- **D32 Engine `DepreciationPlanner`** — čistá funkce (nastavení karty,
  události okruhu, pravidla, účetní období) → plán po obdobích
  (potvrzeno / plán, základ, vzorec, částka, oprávky, zůstatek,
  hlášení). Daňový okruh po účetních letech (za posledním založeným
  rokem extrapolace), účetní po letech nebo měsících (D12).
- **D33 „Odpisy za období“** — ve vieweru i na kartě; náhled (karty,
  součty) → potvrzení; idempotentní; daňové za rok, účetní dle D12.
- **D34 Přerušení** jen v daňovém okruhu, událost za účetní rok, jen
  u přerušitelných metod; rok se nezapočítá do pořadí.
- **D35 Vyřazení.** Dialog nabídne polovinu ročního daňového odpisu
  (majetek v evidenci na začátku roku, pravidlo země, výchozí zapnuto);
  účetní odpis do měsíce vyřazení. Poslední odpisy obou okruhů vzniknou
  s vyřazením v jedné transakci.
- **D36 Zaokrouhlení.** Daňové dle země (CZ nahoru na celé koruny),
  účetní nahoru na celé koruny (jako starý systém); poslední odpis
  omezen zůstatkem.
- **D37 Začátek odpisů.** Účetní od měsíce následujícího po zařazení;
  daňový roční za rok zařazení celý (sazba 1. roku); časové a mimořádné
  daňové od měsíce následujícího po zařazení.
- **D38 Karta vs. události.** U dlouhodobého majetku datum pořízení
  (zařazení / počáteční stav) a vyřazení z událostí, jen ke čtení.
  Potvrzené vyřazení přesune kartu do archivu; smazání vyřazení ji vrátí
  do V opravě.
- **D39 UI.** Tab formuláře Odpisy (nastavení + podtabulka událostí),
  akce detailu Zařadit / TZ / Snížení / Přerušit / Vyřadit / Počáteční
  stav, taby detailu Daňové a Účetní odpisy (plán se vzorcem a panelem
  chyb), Nastavení → Majetek: četnost účetních odpisů. Popisky viewerů
  z konfigurace místo PHP.
- **D40 Testy.** Jednotkové testy enginu na anonymizovaných případech ze
  starých dat; plný zlatý test proti starým kartám patří k importu
  (fáze 6).
- **D41 Mimořádné odpisy (§30a) hned.** Časová metoda s rozpisem procent
  od měsíce následujícího po zařazení, nepřerušitelná: skupina 1 —
  12 měsíců / 100 %, skupina 2 — 24 měsíců / 60 % + 40 %, pro majetek
  pořízený 2020–2023; bezemisní vozidla 2024–2028 — 24 měsíců / 60 + 40 %.
  TZ takového majetku se odpisuje samostatně (vlastní karta). V datech
  `901136` ~10 karet (průběh odpovídá měsíčnímu rozpisu).
- **D42 Zvýšené odpisy 1. roku (+10 / 15 / 20 %, §31)** jen jako data
  v konfiguraci CZ (varianty skupin 1–3); v šesti zdrojích se nepoužívají.
- **D43 Sazby podle data zařazení.** Sazby a koeficienty se volí podle
  data prvního zařazení (přechodná ustanovení novel); starý engine je
  volil podle data odpisu. Ověří zlatý test (fáze 6).
- **D44 TZ v účetní časové metodě.** Po TZ se (zůstatková cena + TZ)
  rozpustí do zbývajících měsíců původní doby; starý engine počítal
  (vstupní cena + TZ) / celá doba — plán se u karet s TZ může od starého
  lišit, potvrzená historie ne.
- **D45 `as_tax` při měsíčních účetních odpisech.** Roční daňový odpis
  se rozpustí rovnoměrně do měsíců, kdy je majetek v užívání (nahoru na
  koruny, poslední měsíc dorovná roční částku).

### D46 — Krátké zdaňovací období (ROZHODNUTO)

Je-li zdaňovací období (účetní rok) kratší než 12 měsíců — typicky přechod
na hospodářský rok —, uplatní se u ročních daňových metod jen **polovina
ročního odpisu**, i za jediný měsíc (§26 odst. 7 písm. a) bod 3 ZDP);
období delší než 12 měsíců = plný roční odpis. Pravidlo země
(`shortPeriodHalfYear`), ne kód enginu. Fáze 2a období nekrátila (starý
engine krátil poměrem měsíců — obojí chybně); opraveno prvním commitem
`tasks/assets-phase2b.md`. Stejně se krátí i účetní metoda `as_tax`, aby se
od daňového okruhu nerozešla. Zda platí i pro první zkrácené období nově
založené firmy, ověřit u účetní.

### D47–D56 — Oblast 3: zaúčtování (ROZHODNUTO)

PRD: `tasks/assets-phase3.md`. Vychází z praxe starého Shipardu (odpisy
dávkou 1–4 doklady ročně MD 551 / DAL 08x, zařazení a TZ MD 02x / DAL 042,
vyřazení MD 08x / DAL 02x vstupní cenou, zůstatková cena ručně a zřídka)
a ze vzoru zaúčtování přiznání DPH (#55 D28–D31: `cmnbkp` přes
`TransactionlessTableGateway`, řada z nastavení, vazba zpět v jedné
transakci).

- **D47 Dimenze deníku.** Obecný mechanismus: modul deklaruje
  v `module.jsonc` `journalDimensions` (sloupec řádku dokladu, volitelně
  výchozí hodnota z hlavičky, sloupec deníku). Engine hodnotu zkopíruje
  do řádku deníku a zahrne do klíče seskupení. První dimenze `asset`
  (extension `economy.assets` na `docs_core_rows`
  a `economy_accounting_journal`); středisko a zakázka později stejnou
  cestou. Řeší otevřenou otázku D15; výchozí hodnotu z hlavičky použije
  fáze 4.
- **D48 Řádkové operace** `asset.activation`, `asset.improvement`,
  `asset.reduction`, `asset.depreciation`, `asset.disposal` na `cmnbkp`:
  účet a strana na řádku (jako `acc.record`), vlajka `rowAsset` (karta
  povinná), vlajka `system` (formulář je ručně nenabízí, ruční uložení je
  odmítne).
- **D49 Účty z účetní skupiny karty:**
  zařazení a TZ MD majetek / DAL pořízení; snížení hodnoty MD pořízení /
  DAL majetek (obrácené zařazení); účetní odpis MD odpisy / DAL oprávky;
  vyřazení **čistým zápisem** — MD oprávky / DAL majetek ve výši oprávek
  a MD zůstatková cena (541) / DAL majetek ve výši zůstatkové ceny;
  neodepisovaný majetek celou cenou MD 541 / DAL majetek. Počáteční stav
  a události jen daňového okruhu se neúčtují.
- **D50 Dávka za období.** Účetní běh „Odpisy a zaúčtování za období“
  vytvoří účetní odpisy (jako fáze 2) a zaúčtuje **všechny potvrzené
  nezaúčtované události** s datem v období do **jednoho** dokladu
  k poslednímu dni období. Daňový běh se neúčtuje. Okamžité zaúčtování
  zařazení až na požádání jako nastavení (ne teď).
- **D51 Stav dokladu.** Doklad vzniká rovnou **V pořádku** (potvrzení
  náhledu je rozhodnutí uživatele); deník generuje standardní handler.
- **D52 Vazba a zámek.** Událost nese `doc_head`; „zaúčtováno“ = navázaný
  nestornovaný doklad. Zaúčtovaná událost je zamčená.
- **D53 Ochrana dokladu.** Doklad majetku je pro ruční opravu a storno
  zamčený („spravuje Majetek“). Zaúčtování se ruší z majetku akcí
  „Zrušit zaúčtování období“ — storno dokladu a odpojení událostí; jen
  poslední zaúčtované období a jen v nezamčeném měsíci.
- **D54 Řada dokladů.** Nastavení „Řada účetních dokladů majetku“; nový
  DS dostane řadu „Majetek“ provisionerem; migrovaný převezme řadu `prp`
  (fáze 6). Bez řady se zaúčtování odmítne (žádný tichý výběr).
- **D55 Kontroly.** Náhled vyřadí karty s chybějícím účtem skupiny,
  chybou plánu nebo nezaúčtovaným dřívějším obdobím; zaúčtovat jde jen po
  období bez děr. Kontrola invariantu evidence = deník jako report nebo
  alert ve fázi 5.
- **D56 Zrušené vyřazení** (nález z 2b) smaže systémové odpisy, které
  vyřazení založilo; zaúčtované nejdřív vyžadují zrušení zaúčtování
  období.

### D57–D64 — Oblast 4: vazba na doklady (ROZHODNUTO)

PRD: `tasks/assets-phase4.md`. Ve starém Shipardu volba „Sledovat náklady
na majetek“ (`usePropertyExpenses`) — majetek na hlavičce i řádcích
přijatých faktur, pokladních a účetních dokladů (`332718`: 146 faktur
s majetkem na hlavičce, 224 řádků s majetkem; `689089`: 59 faktur,
přes 250 řádků pořízení na 042 i 501).

- **D57 Úplná účetní skupina** (nález fáze 3). Odepisovaný druh smí mít
  jen skupinu s účtem odpisů a oprávek — jinak karta nejde potvrdit;
  formulář skupiny upozorní na chybějící účty.
- **D58 Ruční událost v existujícím účetním roce** (nález fáze 3). Kromě
  počátečního stavu a importu musí datum události ležet v založeném
  účetním roce; hláška odkáže na počáteční stav.
- **D59 Nastavení „Sledovat náklady na majetek“**
  (`economy.assets.trackExpenses`, výchozí vypnuto). Zapnuté = pole
  Majetek na hlavičce (nový sloupec `asset` přes extension) a na řádcích
  přijatých a vydaných faktur, pokladních a účetních dokladů. Vypnuté =
  pole jen u pořízení majetku a systémových operací. Import převezme
  starou volbu (fáze 6).
- **D60 Výchozí hodnota z hlavičky** — `journalDimensions.asset.headColumn
  = "asset"`; řádek bez karty zdědí kartu hlavičky (engine to umí),
  formulář řádku ji ukáže jako placeholder.
- **D61 Řádek pořízení nese kartu** (D14) — `purchase.asset` má pole
  Majetek, **nepovinné** (AI analýza a import kartu neznají); alert
  „Pořízení majetku bez karty“. Účet řádku určuje význam: 04x =
  dlouhodobý k zařazení, 5xx = drobný do nákladů.
- **D62 Založení karty z řádku** — obecné rozšíření lookupu o výchozí
  hodnoty nového záznamu z rodiče (třída lookupu dostane řádek
  a hlavičku); u majetku název z textu řádku, datum pořízení z účetního
  data, druh podle účtu (04x dlouhodobý hmotný, 5xx drobný), drobnému
  cena ze základu řádku.
- **D63 Zařazení z pořízení** — sekce Pořízení na kartě (navázané řádky
  a součet); u nezařazené dlouhodobé karty s pořízením na 04x akce
  Zařadit předvyplní součet základů v domácí měně a datum posledního
  dokladu (upravitelné; neodpočitatelnou DPH doplní uživatel). Alert
  „Majetek čeká na zařazení“.
- **D64 Tab Náklady a výnosy na kartě** (D15) — řádky deníku s dimenzí
  karty bez operací `asset.*`, po účetních letech a účtech, odkaz na
  doklad, součty po letech. Prodej majetku (operace s nabídkou vyřazení)
  až fáze 7.

### D65–D72 — Oblast 5: přehledy a kontroly (ROZHODNUTO)

PRD: `tasks/assets-phase5.md` (prerekvizita `tasks/reports-export.md`);
implementace a odchylky §5.6.
Starý Shipard měl sestavu odpisů, přehled karet a podklad pro DPPO;
kontrolu proti deníku neměl — nesoulad se hledal ručně (§3.2).

- **D65 Reporty v doméně reportů, zdroj evidence.** Přehledy majetku
  jsou reporty `economy.assets.*` (`docs/reports.md`) v sekci Majetek —
  dostanou UI, deep-link, REST, MCP, CLI, `report-diff` a export.
  Vědomá odchylka od D3 reportů (jen deník): zdrojem je evidence
  (karty, ledger, plány), protože daňové hodnoty v deníku nejsou;
  deník slouží ke kontrole (D67).
- **D66 Pět reportů:** sestava odpisů (rok; daňově i účetně, oprávky
  a ZC na začátku a konci, rozdíl účetní − daňový odpis), přírůstky
  a úbytky (období; zařazení, technická zhodnocení, snížení, vyřazení),
  daňové odpisy pro DPPO (rok; součty po `taxReturnGroup` z pravidel
  země, účetní odpisy a rozdíl), kontrola evidence × deník (D67),
  soupis majetku (stav ke konci období). Seskupení enum parametrem
  `groupBy`, stav k datu = konec zvoleného období.
- **D67 Kontrola proti deníku.** Report po účtech účetních skupin
  (evidence × deník s dimenzí × deník celkem) a po kartách: zaúčtované
  události ≠ deník, pořízení na 04x ≠ zařazení (+ TZ), nezaúčtované
  starší události. Alerty `economy.assets.journal_mismatch` (denně)
  a `economy.assets.acquisition_mismatch` (rozdíl starší 30 dnů);
  varování na kartě.
- **D68 Tisk až s tiskovou doménou.** Tisk karty a sestav (PDF) se
  řeší obecně pro celý systém mimo tuto oblast (roadmap M4,
  `docs/render.md`, `docs/reports.md` §8); majetek ho pak jen použije.
- **D69 Odložená daň** (rozdíl účetní a daňové ZC × sazba) — fáze 8;
  sestava odpisů rozdíl ZC ukazuje.
- **D70 Drill-down** — řádek reportu odkazuje na kartu, kontrolní
  řádky i na doklad zaúčtování.
- **D71 Hromadné načítání** — plány mnoha karet jedním dotazem na typ
  dat (`AssetPlanService::plansFor`), ne po kartách.
- **D72 Export reportů XLSX / CSV** — obecně pro všechny reporty
  (`tasks/reports-export.md`, knihovna OpenSpout): REST `format`,
  tlačítko Export v UI, CLI `report-run --format`; čísla přesně, strany
  MD / D jako sloupce, mezisoučty tučně, list Zprávy. Spouští se před
  fází 5.

### D73–D82 — Oblast 6: import ze starého Shipardu (ROZHODNUTO)

PRD: `tasks/assets-phase6.md` (nový Shipard); runner ve starém Shipardu
následuje (§6). Rozbor dat 2026-10-06 nad pěti zdroji (`689089`, `732084`,
`332718`, `426748`, `205976`): zhruba 145 dlouhodobých a 350 drobných
karet, přes 1 000 řádků a přes 200 hlaviček dokladů s vazbou na majetek.

Co data ukázala:

- **Účetní odpisy už v deníku nového Shipardu jsou** — majetkové účetní
  doklady (staré operace 1090070–73) import rozpadl na dvojice
  `acc.record` s účty ze starého deníku, stav V pořádku; 551 v novém
  deníku sedí se starým na korunu. Chybí jen karta na řádcích.
- Starý deník nese vazbu odpisů na kartu od 2018 (`689089`, `732084`),
  2019 (`205976`), 2020 (`332718`, `426748`); od té doby je 551 s kartou
  ≥ evidence. Účetní odpisy jsou všude jen k 31. 12. (rok vyřazení k datu
  vyřazení), stará volba měsíčně / pololetně se nedodržovala.
- Daňová evidence na konci řady chybí: `732084` 2022–2025, `332718`
  a `426748` 2025, `205976` 2024–2025 neúplně.
- Inventární čísla jsou unikátní, archivovaná dlouhodobá karta má vždy
  řádek vyřazení; bez účetní skupiny je jediná dlouhodobá karta.
- Mapu starý doklad → nový drží jen runner (lokální mapa ID); nový
  Shipard stará ID neukládá.

Rozhodnutí:

- **D73 Účet pořízení v kontrole jen varováním** (nález ověření fáze 5).
  V Kontrole evidence × deník je rozdíl na účtu pořízení (04x) varování,
  ne chyba `accountMismatch` — evidence tam počítá pořízení s kartou,
  takže rozdíl je vždy pořízení bez karty (vč. otevíracího zůstatku)
  nebo zařazení bez navázaného pořízení; na importech by report měl
  trvale stav „errors“. Chyba zůstává u účtů majetku, oprávek, odpisů
  a ZC.
- **D74 Rozsah fáze 6:** typy, skupiny typů, účetní skupiny, karty
  (i drobné, cizí a vyřazené), hodnotová historie, přílohy, karta na
  dokladech (backfill, D8) a nastavení. Pohyby, příslušenství
  a vlastnosti až s fází 7 (dnes nemají kam), štítky až bude mít nový
  Shipard kam je uložit.
- **D75 Formát `shpd.assets.asset.v1`** — karta s vnořenými událostmi;
  klíč = inventární číslo, převezme se beze změny. Stav karty: starý
  4000 → V pořádku, 9000 → V archívu. Opakovaný import karty nahradí jen
  události původu `import`; kartu, na které už vznikly ruční nebo
  systémové události, přeskočí s varováním. Číselníky jdou obecným CRUD
  API jako ostatní číselníky importu.
- **D76 Importované události jsou zaúčtované mimo modul.** Událost
  původu `import` je zaúčtovaná (ve starém systému): zaúčtování z fáze 3
  ji nebere a neblokuje jí další období, evidence v kontrole po účtech ji
  započítá, kontroly (a) a (c) ji neposuzují, zrušení zaúčtování se jí
  netýká. **Bez vazby na doklad** (`doc_head` prázdný) — staré doklady
  účtují vyřazení jinými zápisy než D49 a zrušení zaúčtování nesmí
  sáhnout na importovaný doklad; doklady karty najde deník přes dimenzi.
  Zaúčtování v novém Shipardu začne prvním obdobím po posledním
  importovaném účetním odpisu.
- **D77 Účetní okruh podle D9 počítá runner.** Pro kartu a rok, kde
  starý deník nese 551 s vazbou na kartu, je účetní odpis součet deníku
  (k datu dokladu); jinak z evidence. Rozdíly evidence × deník runner
  vypíše. Pravidlo „deník vyhrává“ je importní, do nového Shipardu
  nepatří — ten dostane hotové události.
- **D78 Chybějící daňové odpisy na konci řady** se neimportují ani
  nedopočítávají — zůstanou plánem; uživatel je po kontrole s podaným
  DPPO potvrdí v novém Shipardu (Odpisy za období, rok po roce). Mezera
  uvnitř řady zůstává přerušením (D11).
- **D79 Účetní skupiny.** Stará skupina dlouhodobého majetku → nová
  účetní skupina (majetek, pořízení, oprávky, odpisy; účet ZC = 541,
  na který šla vyřazení ve starém deníku, jinak výchozí). Starý
  samostatný účet TZ se neimportuje — kde se lišil od účtu pořízení,
  ukáže rozdíl kontrola po účtech. Skupiny drobného majetku (501 / 648)
  se neimportují, drobné karty jsou bez účetní skupiny. Dlouhodobá karta
  bez skupiny se založí jako koncept s varováním (D57).
- **D80 Karta na dokladech (backfill, D8).** Runner pošle pro každý nový
  doklad seznam řádků (účet, strana, částka, karta, starý řádek)
  a kartu hlavičky. Nový Shipard spáruje řádky podle účtu, strany
  a částky (pořadí jako pomocné), nastaví kartu a přegeneruje deník
  dokladu systémovou cestou přes zámky měsíce i DPH (zalogovaně) s
  pojistkou, že se obraty účtů dokladu nezmění. Doklad, kde párování
  není jednoznačné, zůstane beze změny a je ve výsledku. Rozsah: účetní
  doklady, přijaté a vydané faktury, pokladní doklady, hlavičky.
- **D81 Nastavení:** Sledovat náklady na majetek (D59) podle staré volby;
  účetní odpisy ročně (D12; stará volba se ignoruje).
- **D82 Ověření a pořadí.** CLI `assets-import-verify`: zlatý test
  daňového okruhu (engine přepočítá každý importovaný daňový odpis
  z předchozí historie, D6), účetní okruh × deník po kartách a letech
  a Kontrola evidence × deník za každý rok od prvního s vazbou. Přejetí:
  pět zdrojů bez nevysvětleného rozdílu. Pořadí: nový Shipard (applier,
  backfill, ověření) → runner → reimport zdrojů jeden po druhém.

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
| extensions | sloupec `asset` na `docs_core_rows`, `docs_core_heads` a `economy_accounting_journal` (D4, D15, D59) |

**Vlastnosti per typ** — kandidát na strukturovaná pole
(`structured-fields.md`): sada se mění podle typu, hodnoty jsou opis, ne
zdroj výpočtu. Ověřit v oblasti §7, zda se podle nich filtruje.

### 5.1 Pravidla země (`world.assets`) — hotovo

Modul bez tabulek, vzor `world.vat` (D31). **Čísla** jsou v cfgItem
`world.assets.{country}` (`modules/world/assets/config/assets-cz.jsonc`),
**vzorce** v PHP. Popis struktury konfigurace: `modules/world/assets/README.md`.

| Třída (`Shipard\Module\World\Assets\`) | Role |
|---|---|
| `TaxDepreciationRules` | rozhraní — jediné, co engine o státu ví |
| `CzTaxDepreciationRules` | ZDP § 30a, § 31, § 32, § 32a; `validateConfig()` |
| `AccountingOnlyTaxRules` | stát bez pravidel: jen `accounting` / `none` |
| `TaxRulesRegistry::forCountry($config, $country)` | výběr podle `DataSourceConfig::getCountry()` |

Rozhraní dělí metody na čtyři druhy (`methodKind()`):

| Druh | Metody CZ | Výpočet |
|---|---|---|
| `annual` | `straight`, `accelerated` | `annualAmount(TaxYearInput)` — jeden rok; vstup nese počet let s uplatněným odpisem, příznak zvýšené ceny, roky odpisované ze zvýšené ceny a příznaky poloviny (rok vyřazení, krátké období) |
| `monthly` | `extraordinary`, `time` | `scheduleAmount(TaxScheduleInput)` — úsek měsíců rozpisu; `scheduleMonths()` = délka rozpisu |
| `accounting` | `accounting` | daňový odpis = účetní odpisy téže karty |
| `none` | `none` | bez odpisu |

Dál `availableMethods(datum zařazení, nehmotný)`, `rules(metoda, datum)`
(skupiny / pravidla platná pro datum zařazení, D43), `isInterruptible()`,
`allowsHalfYearOnDisposal()`, `allowsShortPeriodHalfYear()` (D46),
`allowsImprovement()` a `round()`.

`round()` nejdřív srovná hodnotu na 4 místa: prosté `ceil(50000 × 5,15 / 100)`
dá kvůli plovoucí čárce 2 576 místo 2 575. Starý engine tuhle korunu
navíc přičítal — zlatý test (fáze 6) může ukázat rozdíly ±1 Kč.

Pravidla CZ, která nejsou vidět z konfigurace:

- **Technické zhodnocení před prvním uplatněným odpisem** (typicky v roce
  zařazení) je součást pořizovací ceny: sazba 1. roku ze zvýšené ceny,
  dál běžná sazba / koeficient — ne sazba pro zvýšenou vstupní cenu.
  Pokyn GFŘ D-59 případ neřeší, vychází se z odborného výkladu.
- **Zrychlený odpis po TZ** počítá roky odpisované ze zvýšené zůstatkové
  ceny (§ 32 odst. 3); starý engine počitadlo nenuloval.
- **Časový odpis NIM po TZ** běží po zbývající dobu, nejméně však
  `monthsIncreased` (§ 32a odst. 6).
- **Poslední úsek měsíčního rozpisu** dorovná zůstatek do 100 % ceny.

### 5.2 Engine (`DepreciationPlanner`) — hotovo

`Shipard\Module\Economy\Assets\Depreciation\` — čistá funkce bez DB a UI
(D32):

```php
$plans = (new DepreciationPlanner())->plan(
    DepreciationSettings::fromArray($card),   // tax_method, tax_rule, acc_method, acc_months, intangible
    array_map(AssetEvent::fromArray(...), $eventRows),
    TaxRulesRegistry::forCountry($config, $dsConfig->getCountry()),
    PeriodCalendar::yearly($fiscalYears),                 // daňový okruh
    PeriodCalendar::monthly($fiscalYears, $fiscalMonths), // účetní: yearly() / monthly() dle D12
    $today,
);
$plans['tax']; $plans['acc'];   // Plan: rows, souhrn, messages
```

- **Vstup.** `AssetEvent::fromArray()` bere sloupce tabulky událostí
  (`event_kind`, `scope`, `event_date`, …) + `confirmed`. Nepotvrzené
  události se ignorují. `PeriodCalendar` dostane založené účetní roky;
  mimo ně dopočítává období po 12 měsících dopředu i dozadu.
- **Výstup.** `Plan` = řádky (`PlanRow`: druh, stav `confirmed` /
  `planned`, období, částka, spočtená hodnota, vzorec, vstupní cena,
  oprávky, zůstatek, hlášení) + souhrn ze **skutečného** (potvrzeného)
  stavu + `currentYearAmount` (odpis roku, do kterého patří `$asOf`,
  včetně plánu). `toArray()` pro API.

**Průchod okruhem** (`CircuitWalker`):

1. Potvrzené události chronologicky; událost `both` patří do obou okruhů.
2. Potvrzený odpis se **nepřepočítává** — převezme se, vedle něj spočtená
   hodnota a vzorec; rozdíl = varování `mismatch`.
3. Před změnou hodnoty (TZ, snížení) a před přerušením se doplní
   **plánované** odpisy období, která skončila dřív — odpis 2023 se nesmí
   počítat ze zhodnocení z roku 2024. Potvrzený odpis, před kterým je
   mezera nebo plánovaný řádek, dostane chybu `missingPeriod`.
4. Za poslední událostí plán pokračuje do nulového zůstatku, do vyřazení,
   nebo do vyčerpání metody. Vyřazení je poslední řádek a odepíše
   zůstatkovou cenu.

**Metody okruhů** (`Depreciation\Method\`):

| Okruh / metoda | Chování |
|---|---|
| daňový, roční | odpis za celé zdaňovací období (rok zařazení celý, D37); účetní rok kratší než 12 měsíců se pravidlům hlásí jako krátké období — CZ polovina ročního odpisu, rok se přitom počítá jako uplatněný; delší než 12 měsíců plný odpis (D46); přerušený rok se nepočítá do let (D34); v roce vyřazení nic, nebo polovina u majetku evidovaného na začátku roku (D35) |
| daňový, měsíční | rozpis podle kalendáře od měsíce po zařazení, součet měsíců se zaokrouhluje jednou za období; do měsíce vyřazení včetně; TZ uprostřed období ho dělí na úseky |
| daňový `accounting` | součet účetních odpisů (i plánovaných), jejichž období v roce končí |
| účetní `time` | měsíčně zůstatek / zbývající měsíce původní doby → TZ se rozpustí do zbytku doby (D44); nahoru na koruny jednou za období (D36); do měsíce vyřazení včetně |
| účetní `as_tax`, roční daňová metoda | roční vzorec nad účetním zůstatkem na začátku roku a vlastním počitadlem let, bez přerušení; krátký účetní rok krátí stejně jako daňový okruh (D46); roční částka se rozpouští do měsíců v užívání, každé období bere podíl ze zbytku, takže poslední měsíc dorovná (D45); zařazení v posledním měsíci roku → celý roční odpis do něj; v roce vyřazení poměrná část podle měsíců v užívání |
| účetní `as_tax`, měsíční daňová metoda | stejný rozpis jako daňový okruh |

Roční součet `as_tax` je tak stejný při měsíční i roční četnosti.
Výjimka: TZ, které roční částku **sníží** pod to, co už měsíce roku
odepsaly (rovnoměrný odpis, TZ ke konci roku — sazba klesne na
„zvýšenou“); odepsané měsíce se nevracejí.

**Počáteční stav** (D16): `units_done` jsou roky u ročních daňových metod
(u zrychleného odpisu se zvýšenou cenou roky odpisované ze zvýšené
zůstatkové ceny), měsíce u měsíčních a účetních metod. Oprávky se
ověřují proti pravidlům (`openingMismatch`); se zvýšenou cenou se
nekontrolují, protože průběh před zhodnocením není znám. Sazby se volí
podle `original_date`.

**Hlášení** — engine vrací kód, závažnost a parametry (`PlanMessage`),
texty drží cfgItem `economy.assets.planMessages` a skládá
`PlanMessageTexts`. Chyba znamená, že plán okruhu není spolehlivý.

| Kód | Závažnost | Kdy |
|---|---|---|
| `mismatch` | varování | potvrzený odpis ≠ spočtený |
| `openingMismatch` | varování | oprávky počátečního stavu neodpovídají pravidlům a počtu období |
| `missingPeriod` | chyba | před potvrzeným odpisem chybí odpis dřívějšího období |
| `dateOutsidePeriod` | chyba | datum odpisu mimo účetní rok konce období, nebo před jeho začátkem |
| `notWholeUnits` | chyba | odpis není zaokrouhlený dle pravidel (mimo původ `import`, D10) |
| `improvementOnSchedule` | chyba | TZ u metody, která ho odpisuje samostatně (§ 30a, D41) |
| `ruleNotValid` | chyba | pravidlo neplatí pro datum zařazení — okruh se nepočítá |
| `settingsInvalid` | chyba | kombinace metod nedává výpočet (`reason`: `asTaxWithoutFormula`, `accountingWithoutAccMethod`, `accMonthsMissing`, `unknownTaxMethod`) |

**Co engine nehlídá** (hlídá validace událostí, §5.3): snížení
hodnoty větší než zůstatková cena (zůstatek vyjde záporný) a události
datované po vyřazení.

### 5.3 Události, karta a odpisy za období — hotovo

`tasks/assets-phase2b.md` (D27–D30, D33–D35, D38, D39, D12, D46).

**Tabulka `economy_assets_events` (454)** dle D28; stavy
`economy.assets.eventStates` (Koncept → Potvrzeno ↔ V opravě → Smazáno,
bez archivu), přechody přes Document lifecycle. Do plánu vstupují jen
potvrzené události (`docState` 40). Události se spravují jen z karty
(`hideFromNavigation`).

| Třída (`Shipard\Module\Economy\Assets\`) | Role |
|---|---|
| `AssetEventDocument` | pravidla per druh; tvarová vždy, kontextová při potvrzení; efekty na kartu v `afterPersist()` |
| `AssetEventLockProvider` | zámek potvrzené události: pozdější potvrzený odpis okruhu (`both` = oba) + zamčený účetní měsíc (`FiscalMonthLookup::lockedMonthForDate()`) |
| `AssetPlanService` | most k enginu: karta + potvrzené události + účetní roky/měsíce + `TaxRulesRegistry` + četnost (`economy.assets.accPeriodicity`, D12) |
| `SystemDepreciationWriter` | přímý INSERT systémových odpisů z plánovaných řádků (původ `system`, stav 40), zámek měsíce kontroluje sám |
| `DepreciationRunService` | „Odpisy za období“: náhled (karty s částkou, vyloučené s důvodem), provedení v transakci se zámkem karet, idempotentní |
| `DepreciationSettingsValidator` | odpisové nastavení karty — sdílí `AssetDocument` (uložení) a `AssetEventDocument` (zařazení k datu) |
| `AssetsDepreciationController` | `GET /_assets/depreciation-run/options`, `GET …/preview`, `POST /_assets/depreciation-run` |

**Pravidla nad rámec PRD** (doplněná při implementaci):

- Historie se rozebírá od konce i pro **nové** události: událost nejde
  potvrdit, když za ní v jejím okruhu je potvrzený odpis (`notAtEnd`);
  pořadí dává `AssetEventDocument::orderKey()` = (datum, pořadí druhu
  v rámci dne, id). Provider chrání jen uložené záznamy.
- Efekty na kartu se řídí vstupem do stavu 40 / jeho opuštěním, ne jen
  smazáním: i Opravit na vyřazení vrátí kartu do V opravě. Zrušené
  vyřazení přitom smaže systémové odpisy k datu vyřazení (D56,
  `SystemDepreciationWriter::removeForDisposal()`); zaúčtované přechod
  odmítnou (`disposalPosted`). Odpisy dřívějších období, které vyřazení
  doplnilo, zůstávají — jdou smazat od konce.
- Vyřazení zakládá **všechny** plánované odpisy plánu s vyřazením (i za
  dřívější neodepsaná období); plán s chybou nebo zamčený měsíc vyřazení
  odmítne (`planHasErrors`, `DomainException` → rollback).
- TZ, snížení a vyřazení vyžadují zařazení, nebo počáteční stav v obou
  okruzích; TZ u metody bez TZ (§ 30a) je chyba už při potvrzení.
- Karta: dlouhodobý majetek nejde ručně do archivu bez potvrzeného
  vyřazení, vyřazená karta se nevrací do V pořádku, karta s potvrzenými
  událostmi se nesmaže a nemění druh; data pořízení a vyřazení z payloadu
  se u dlouhodobého majetku ignorují.
- Bez data zařazení se platnost metody a pravidla k datu **neřeší**
  (`rules(…, null)` / `availableMethods(null, …)` vrací vše) — jinak by
  karta před zařazením nemohla nést mimořádné odpisy 2020–2023 ani
  časové odpisy NIM do 2020; ověří se při potvrzení zařazení /
  počátečního stavu (`methodNotAvailable`, `ruleNotValid`).
- Odpisy za období vylučují navíc kartu s neodepsaným dřívějším obdobím
  (`earlierPeriodMissing`) a zamčený měsíc (`monthLocked`); karta bez
  plánovaného odpisu v období (odepsáno, přerušeno, zařazeno později) se
  vynechá tiše. Kandidát je jen odepisovaná karta ve stavu V pořádku.
- `tax_method` je `varchar`, ne `enumString` — nabídku i názvy řídí
  pravidla země (`TaxDepreciationRules::methodName()`), ne cfgItem.

**UI.** Detail karty: taby Daňové / Účetní odpisy (`composite`: souhrn,
tabulka plánu s `_class` `muted` / `error`, hlášení) a akce podle stavu —
Zařadit / Počáteční stav (daňový, účetní) bez zařazení; jinak Odepsat
(`depreciation_run` s `target.assetId`), TZ, Snížení, Přerušit
(přerušitelná metoda), Vyřadit jako `open_form` s presetem. Formulář
karty: tab Odpisy (nabídka podle data zařazení) a tab Události —
sub-tabulka s nezávislými řádky (`independentRows`, dialog události
i nad kartou V pořádku). `AssetEventsForm` skládá pole podle druhu,
polovina při vyřazení se nabízí jen když smí (D35). Toolbar vieweru
„Odpisy za období“ → `AssetsDepreciationRunDialog`. Popisky
z `economy.assets.viewerLabels`. Nastavení → Majetek → Odpisy: četnost
účetních odpisů (field typ `select`).

### 5.4 Zaúčtování — hotovo

`tasks/assets-phase3.md` (D47–D56). Účetní okruh se účtuje **dávkou za
období**: jeden `cmnbkp` k poslednímu dni období, rovnou V pořádku.

| Třída (`Shipard\Module\Economy\Assets\Posting\`) | Role |
|---|---|
| `AssetPostingBuilder` (+ `AssetPostingInput` / `Row` / `Result`) | čistá třída: události karty + účty účetní skupiny + plán účetního okruhu → řádky `asset.*` (tabulka D49); chybějící účet nebo neúplný plán vyřazení = chyba karty |
| `AssetPostingService` | `preview` / `post` / `unpost` / `lastPosting`; vyhodnocení karet sdílí náhled i zaúčtování |
| `AssetPostingDocuments` | doklad přes `TransactionlessTableGateway` (založení ve stavu 40, storno), kontrola `accounting_state` |
| `AssetPostingDocLockProvider` | zámek dokladu s řádky `asset.*` („spravuje Majetek“, D53) |
| `AssetPostingSeries`, `AssetPostingSeriesProvisioner` | nastavení `economy.assets.accountingSeries` (D54), nabídka řad pro settings stránku, řada „Majetek“ (kód `MA`) |

Mimo `Posting\`: `AssetEventLockProvider` zamyká zaúčtovanou událost
(`doc_head` na živý doklad), `AssetPlanService::postingOf()` dává stav
zaúčtování pro kartu, `AssetsDepreciationController` má routy
`GET /_assets/posting/preview`, `POST /_assets/posting`,
`POST /_assets/posting/cancel`.

**Běh `post(period)`** — jedna transakce: zámek karet → účetní odpisy
období (`SystemDepreciationWriter`, jen karty, které se zaúčtují) →
znovunačtení nezaúčtovaných událostí → builder → doklad → `doc_head`.
Chyba kdekoli (i `accounting_state ≠ 1`) = rollback. Událost s `doc_head`
se neúčtuje znovu; období bez kandidátů doklad nezaloží (`posted: false`).

**Vyloučení v náhledu** (D55): `earlierPeriodUnposted` (nezaúčtovaná
událost před obdobím — má přednost), důvody běhu odpisů `planError` /
`earlierPeriodMissing` / `monthLocked`, `accounting_account_missing`,
`disposalPlanIncomplete`. Překážky celého běhu: `series_missing`,
`monthLocked` (měsíc účetního data dokladu), `documents_unavailable`.

**Zrušení `unpost(period)`** — jen poslední zaúčtované období: storno
dokladů období, `doc_head = NULL`; systémové odpisy zůstávají potvrzené.

**Dimenze deníku** `asset` (D47) — obecný mechanismus v
`docs/accounting.md` §6; `economy.assets` jen deklaruje dimenzi a přidává
sloupce extensions. Na ní stojí invariant §1: Σ MD účtu odpisů karty
v deníku = Σ potvrzených účetních odpisů karty.

**Odchylky od PRD** (potvrzené před implementací, N1–N4, a nálezy z ní):

- **Vnořené transakce.** Účtovací enginy a saldo ledger si otevíraly
  vlastní transakci, což by transakci služby tiše commitlo (MariaDB
  vnořené transakce nemá). Zapisují přes
  `Shipard\Core\Database\NestedTransaction` (savepoint uvnitř cizí
  transakce) — platí i pro importní cesty.
- **Výjimka ze zámku dokladu je v provideru**, ne v
  `Document::isLockExempt()`: ten vypíná všechny providery, kdežto zámek
  fiskálního měsíce má pro službu platit dál. Jediný marker
  `_systemOperations` (povolí řádky `asset.*` i zápis přes zámek) místo
  dvojice `_systemOperations` + `_assetsService`.
- **Dimenze nejdou loaderem**, ale kompilací do cfgItem
  `core.accounting.journalDimensions` (`JournalDimensionSet::fromConfig`).
- **`doc_head` vznikl už s D56** (commit 1) — kontrola `disposalPosted`
  ho potřebuje; sloupec je `system` (formulář ani CRUD ho nepřijmou).
- **Řada „Majetek“ a přiznání DPH.** Druhá řada `cmnbkp` by zrušila
  implicitní volbu řady pro zaúčtování přiznání DPH (jediná aktivní řada).
  `ds-upgrade` proto při založení řady „Majetek“ zafixuje dosavadní
  jedinou řadu do `economy.vat.filingAccountingSeries`.
- **Účetní odpis jedné karty** (detail → Odepsat, účetní okruh) zůstal
  prostým potvrzením — dávka za období je nad všemi kartami; odpis
  zaúčtuje další běh období.
- **Storno jde celým dokladem** (`loadDocument` + stav 30), ne částečným
  payloadem — `DocDocument` částečný payload nevaliduje.
- **Uživatelská stránka** je samostatná (`help/majetek/zauctovani-majetku.md`),
  ne dodatek k odpisům — jedna stránka = jedna úloha.

**UI.** Dialog `AssetsDepreciationRunDialog`: účetní okruh nad všemi
kartami = režim zaúčtování (nové odpisy, události k zaúčtování, souhrn
účtů, Zaúčtovat, odkaz na doklad přes `ViewerDetailModal`, Zrušit
zaúčtování období u posledního zaúčtovaného období). Karta: plán účetních
odpisů ukazuje „Zaúčtováno — doklad …“ / „Čeká na zaúčtování“; seznam:
badge „Nezaúčtováno“.

### 5.5 Vazba na doklady — hotovo

`tasks/assets-phase4.md` (D14, D15, D57–D64). Karta majetku na dokladech:
pořízení na řádku přijaté faktury, náklady a výnosy přes dimenzi deníku.

| Třída (`Shipard\Module\Economy\Assets\`) | Role |
|---|---|
| `AssetAcquisitionService` | pořízení karty: řádky `purchase.asset` potvrzených dokladů s kartou **na řádku**, součet `vat_base_dom`, podklad pro zařazení (řádky na 04x, poslední účetní datum) |
| `AssetJournalService` | náklady a výnosy karty: řádky deníku s dimenzí karty mimo operace `asset.*`, souhrn po účetních letech, strop 200 řádků |
| `AssetsLookup::createDefaults()` | výchozí hodnoty karty zakládané z řádku dokladu (D62) |
| `Checks\PurchaseWithoutAssetCheck` | alert per doklad: potvrzený doklad s řádkem pořízení bez karty |
| `Checks\AwaitingActivationCheck` | alert per karta: dlouhodobá karta s pořízením na 04x bez potvrzeného zařazení / počátečního stavu |

**Dimenze `asset` na dokladech** (D59, D60) — deklarace v `module.jsonc`
(`headColumn: "asset"`, `rowFlag: "rowAsset"`, `forms` s
`enabledBySetting: economy.assets.trackExpenses`); obecný mechanismus
v `docs/accounting.md` § Dimenze deníku. Se zapnutým nastavením
(Nastavení → Majetek → Majetek na dokladech) má pole Majetek hlavička
i řádky `invni`, `invno`, `cash`, `cmnbkp`; řádek bez karty dědí kartu
hlavičky a pole řádku to ukazuje placeholderem. Vypnutí pole jen skryje.

**Pořízení** (D61–D63) — `purchase.asset` má vlajku `rowAsset: "optional"`:
pole karty je na řádku vždy, nepovinné, s `createForm` / `editForm`
/ `createDefaults`. Detail karty: sekce Pořízení v Přehledu (odkaz na
doklad, součet), akce Zařadit s presetem `amount` + `event_date`.

**Náklady a výnosy** (D64) — tab detailu se objeví s prvním řádkem deníku;
akce „Otevřít v deníku“ vede na deník s filtrem `dim_asset = #id` přes
všechny roky.

**Validace** (D57, D58) — `AssetDocument` (`accountingGroupIncomplete`
při potvrzení odepisované karty), `AssetEventDocument`
(`outsideFiscalYear` při potvrzení ruční události kromě počátečního
stavu; původ události bere z uloženého záznamu, ne z payloadu).

**Odchylky od PRD** (rozhodnutí při plánování a nálezy z implementace):

- **Pořízení je věc řádku.** Sekce Pořízení, předvyplnění zařazení i oba
  alerty počítají jen kartu zapsanou na řádku. Řádek `purchase.asset`
  kartu hlavičky **nedědí ani v deníku** — obecně `rowFlag` dimenze:
  řádek operace s touto vlajkou nese hodnotu sám. Vlajka
  `rowAsset: "optional"` proto vznikla už s `headColumn` (commit 3), ne
  až s lookupem.
- **`headColumn` přišel s polem hlavičky** (commit 2) — pole hlavičky bez
  něj nemá kam ukládat; commit 3 zbyl na placeholder a `rowFlag`.
- **Datum pořízení a cena se nové kartě předvyplní jen u drobného
  majetku.** U dlouhodobého je karta nenese (D13, D38) — formulář je má
  jen ke čtení a uložení by je zahodilo; nese je zařazení, které se
  předvyplní z pořízení (D63). Cena drobného se bere z živého základu
  řádku (`vat_base` × kurz hlavičky), ne z `vat_base_dom` — ten zapisuje
  až uložení řádku.
- **Data rodiče pro `createDefaults` jdou Svelte contextem**
  (`form/formContext.js`), ne protahováním props; `ReadOnlyPolicy` už
  měla `lookup` povolený celý.
- **Detail vieweru umí buňku-odkaz** (`columns[].link` + `rows[]._action`)
  a `Viewer.svelte` akci `open_detail` — tabulky detailu dosud odkaz
  neuměly. Přehled karty s pořízením je `composite`.
- **Filtr deníku `dim_asset` bere `#id`** jako přesnou shodu; textové
  hledání v čísle a názvu by z karty chytlo i podobná čísla.
- **Formulář účetní skupiny upozorňuje statickým hintem** u účtu odpisů
  a oprávek (JSONC formulář; dynamické upozornění by chtělo PHP třídu).
- **Nastavení má vlastní stránku** „Majetek na dokladech“, ne stránku
  Odpisy.

**UI.** Formulář řádku dokladu: pole Majetek u pořízení (vždy) a u
ostatních pohybů se zapnutým nastavením; formulář hlavičky: pole Majetek.
Detail karty: sekce Pořízení, tab Náklady a výnosy, akce Otevřít v deníku.
Dashboard: karty alertů v sekci Majetek.

### 5.6 Přehledy a kontrola proti deníku — hotovo

`tasks/assets-phase5.md` (D65–D72). Pět reportů v doméně reportů
(`docs/reports.md`) v sekci Majetek; zdrojem je **evidence** (karty,
události, plány), deník slouží kontrole (D65). Stav „k datu“ = poslední
den zvoleného období.

| Report (`economy.assets.*`) | Období | Parametry | Obsah |
|---|---|---|---|
| `depreciationSchedule` — Sestava odpisů | rok | `groupBy` (účetní skupina / daňová skupina a metoda / typ / nic), `category` (vše / odepisovaný / neodepisovaný) | dlouhodobé karty v evidenci v roce: vstupní cena, oprávky na začátku, odpis roku, oprávky a ZC na konci — daňově i účetně —, rozdíl účetní − daňový odpis |
| `movements` — Přírůstky a úbytky | měsíc–rok | `kind` (vše / přírůstky / úbytky) | potvrzené události období po druhu pohybu, drobný majetek podle dat na kartě; u vyřazení oprávky a ZC; přírůstky a úbytky celkem |
| `taxDepreciationReturn` — Daňové odpisy pro DPPO | rok | — | uplatněné daňové odpisy po skupinách přiznání, účetní odpisy celkem, rozdíl |
| `journalCheck` — Kontrola evidence × deník | rok | — | účty účetních skupin (evidence × deník, obrat s kartou a bez) a nesoulady po kartách |
| `register` — Soupis majetku | měsíc, rok | `groupBy` (typ / druh / účetní skupina), `foreign` (včetně / jen vlastní / jen cizí) | karty v evidenci ke konci období vč. drobného a cizího: datum pořízení, vstupní cena, účetní ZC |

| Třída | Role |
|---|---|
| `AssetPlanService::plansFor()` / `plansOf()` | hromadné plány (D71): karty a potvrzené události jedním dotazem na typ dat, kalendáře období jednou za instanci |
| `Reports\AssetReportSupport` | sdílená vrstva reportů: `AssetPlanService` na běh, data období z `FiscalRange`, karty s názvy skupiny / typu / vlastníka, karty v evidenci v období, události v období, odkazy drill-downu |
| `Reports\CircuitYear`, `Reports\AssetYear` | pohled na plán okruhu za období: oprávky na začátku, uplatněný odpis, stav na konci, příznak plánu |
| `Reports\*Builder` | pět builderů (`ReportBuilder`), bez dědičnosti |
| `AssetJournalCheck` | kontrola evidence × deník — sdílí ji report, alerty a karta |
| `Checks\JournalMismatchCheck`, `Checks\AcquisitionMismatchCheck` | alerty D67 |

**Kdy je karta v přehledu.** Dlouhodobá karta se řídí událostmi: v evidenci
od zařazení nebo počátečního stavu do vyřazení; karta bez zařazení
v přehledech není (pořízení je ještě na účtu pořízení). Drobný
a nezařazený cizí majetek se řídí daty a cenou na kartě. Koncepty
a smazané karty do přehledů nevstupují. V sestavě za rok je karta zařazená
do konce roku a nevyřazená před jeho začátkem; v soupisu k datu karta
vyřazená v ten den už není.

**Hodnoty roku** jsou z plánu — potvrzené události i plán do konce roku.
Neodepsaný rok se pozná ve sloupci Stav („plán“) a souhrnnou zprávou;
chyba plánu karty je zpráva `assets.planError` (`status: errors`). Daňový
odpis je **uplatněný**: odpisy s neevidovanou uplatněnou částkou (D11)
v něm nejsou, oprávky ale snižují.

**Členění pro přiznání** (`taxReturnGroup`) je v konfiguraci pravidel země
(`taxReturnGroups` + `TaxDepreciationRules::taxReturnGroup()`): odpisové
skupiny 1–6, nehmotný majetek do 2020, odpisy podle účetnictví. Mimořádné
odpisy § 30a patří do odpisové skupiny majetku (bezemisní vozidla = 2).

**Kontrola evidence × deník** (`AssetJournalCheck`, invariant §1). Co má
být v deníku, se počítá **z událostí samotných** podle tabulky D49 —
nezávisle na `AssetPostingBuilder` i na plánovači (oprávky při vyřazení =
počáteční oprávky + účetní odpisy, ZC = vstupní cena − oprávky). Kontrola
tak neopakuje kód, který účtuje, a nepotřebuje pravidla země.

| Nesoulad | Co se porovnává | Závažnost |
|---|---|---|
| účet účetní skupiny | evidence (počáteční stavy + **zaúčtované** události) × deník; účty majetku, pořízení a oprávek konečným zůstatkem roku (otevírací období + běžné měsíce), účty odpisů a ZC obratem roku | chyba |
| zápisy bez karty | řádky běžných měsíců roku na účtech skupin bez dimenze `asset` | varování |
| (a) zaúčtování ≠ deník | zaúčtované události karty × řádky deníku `asset.*` s dimenzí karty, po účtech a stranách | chyba |
| (b) pořízení ≠ zařazení | pořízení na 04x s dimenzí karty (mimo `asset.*`) × potvrzená zařazení + TZ − snížení | chyba |
| (c) nezaúčtovaná událost | potvrzená účtovatelná událost bez dokladu s datem do konce předchozího období účetních odpisů | varování |

U účtu pořízení je evidence = pořízení s kartou z deníku − zaúčtovaná
zařazení a TZ; rozdíl proti deníku jsou pak zápisy bez karty.

**Alerty** (denně): `economy.assets.journal_mismatch` (chyba) — nesoulad
(a) ke konci předchozího a aktuálního účetního roku, akce `open_report`
otevře kontrolu za dotčený rok; `economy.assets.acquisition_mismatch`
(varování) — nesoulad (b) trvající déle než 30 dní
(`AssetJournalCheck::ACQUISITION_DAYS`). Karta ukazuje (a) a (b) nahoře
v Přehledu s odkazem na report.

**Drill-down** (D70): název karty → náhled karty (`open_detail`), číslo
dokladu zaúčtování → doklad, účet v kontrole → deník s filtrem účtu
a roku, nesoulad karty → karta ve vieweru (`open_viewer`) a deník
s filtrem účtu a karty.

**Odchylky od PRD** (rozhodnutí při plánování R1–R4 a nálezy
z implementace):

- **Commit navíc v jádru reportů** (`docs/reports.md` §16). UI, CLI ani
  MCP neuměly jiný parametr reportu než `detail`: popisky parametru nese
  deklarace (R4), toolbar, deep-link, `--param` a `params` jsou obecné.
  `ReportRequest` nese zemi, `SubtotalAggregator::groupBy()` seskupuje
  podle klíče, `ReportRow` má `key` (párování v `report-diff`), `link`
  a `cellLinks`.
- **(b) se počítá z potvrzených událostí, ne ze zůstatku 04x** (R1) —
  nezaúčtované zařazení by jinak bylo nesouladem až do ročního běhu.
  Posuzují se jen karty, které pořízení na dokladech mají; zařazení bez
  navázaného pořízení (import, pořízení bez karty) nesoulad (b) není.
- **(a) nejde přes `AssetPostingBuilder`** (R2) — viz výše.
- **Stavové účty zůstatkem, výsledkové obratem** (R3); evidence
  nezahrnuje nezaúčtované události, ty hlásí (c).
- **Plán v sestavě je sloupec Stav + jedna souhrnná zpráva**, ne zpráva
  per karta — v běžném roce by jich bylo tolik co karet.
- **Počáteční stav není přírůstek** — v Přírůstcích a úbytcích není.
- **Pořízení bez zařazení eskaluje**: do 30 dnů informační „Majetek čeká
  na zařazení“, potom varování nesouladu pořízení; `AwaitingActivationCheck`
  proto starší pořízení už nehlásí. V reportu je nezařazená karta
  nesouladem (b) také až po lhůtě.
- **Odkaz řádku na doklad je buňka** (`cellLinks`), ne název řádku —
  název vede vždy na kartu.
- **Akce `open_report`** je nová (`navigationStore.navigateToReport`);
  akce alertu „Majetek čeká na zařazení“ dostala cíl do `target` — karta
  feedu ho na úrovni akce nečetla.
- **Práh 30 dní je konstanta**, ne nastavení.

---

## 6. Import (kontrakt pro `old_shipard`)

Pořadí dle `ai-workflow.md`: nejdřív nový Shipard (`tasks/assets-phase6.md`),
pak runner. Kroky runneru (D73–D82):

1. číselníky obecným CRUD API: skupiny typů, typy, účetní skupiny
   (staré skupiny dlouhodobého majetku, D79),
2. nastavení (D81),
3. karty s hodnotovou historií — `shpd.assets.asset.v1` (D75):
   - `depsPart` 0 → událost obou okruhů podle `rowType` (1 zařazení,
     2 TZ, 4 snížení, 120 vyřazení, 110 přerušení);
   - `depsPart` 1 + `rowType` 99 → daňový odpis (`usedDepreciation = 0`
     → `claim_unrecorded`, D11, D17); mezera uvnitř řady → přerušení,
     chybějící roky na konci se neposílají (D78);
   - účetní odpisy: rok s 551 s vazbou na kartu ve starém deníku → součet
     deníku k datu dokladu, jinak `depsPart` 2 (D77);
   - řádky ve stavu 4000 a 9000, 9800 se vynechávají; všechny události
     původu `import` (D76);
4. přílohy karet (obecný systém příloh),
5. karta na dokladech — po dokladě přes mapu starý → nový doklad (D80),
6. ověření v novém Shipardu: `shpd-ds assets-import-verify` (D82);
   rozdíly evidence × deník z kroku 3 vypisuje runner.

Pohyby, příslušenství, vlastnosti a štítky až s fází 7 (D74).

---

## 7. Oblasti a pořadí

Probírají se jedna po druhé; každá má vlastní PRD.

1. Karta, typy, účetní skupiny, stavy (základ) — **hotovo** 2026-09-29,
   `tasks/assets-phase1.md` (vč. odchylek od PRD: přidělení čísla
   v `afterPersist`, unikátnost přes všechny stavy, prefixy lookupu účtů)
2. Ledger událostí + engine + pravidla CZ (D3, D6, D7, D10, D27–D46) —
   **hotovo** 2026-09-30: pravidla + engine (`tasks/assets-phase2a.md`,
   §5.1–5.2), události, UI a odpisy za období (`tasks/assets-phase2b.md`,
   §5.3)
3. Zaúčtování (D4, D47–D56) + řádkové operace + dimenze deníku —
   **hotovo** 2026-10-01, `tasks/assets-phase3.md` (§5.4 vč. odchylek)
4. Vazba na doklady: pořízení (D14), analytická dimenze (D15), D57–D64 —
   **hotovo** 2026-10-01, `tasks/assets-phase4.md` (§5.5 vč. odchylek)
5. Přehledy: odpisy, přírůstky / úbytky, kontrola proti deníku,
   podklad pro DPPO, soupis (D65–D72) — **hotovo** 2026-10-01,
   `tasks/assets-phase5.md` (§5.6 vč. odchylek; tisk karty až s tiskovou
   doménou, D68)
6. Import (D8, D9, D11, D73–D82) + backfill — **naplánováno**, nový
   Shipard `tasks/assets-phase6.md` (první bod D73 z ověření fáze 5), pak
   runner ve starém Shipardu (§6)
7. Pohyby, příslušenství, vlastnosti, místa, inventarizace, prodej majetku
   (vydaná faktura s nabídkou vyřazení)
8. Soubory a množstevní karty, odložená daň, zbytek (AV/AM, X)

---

## 8. Otevřené otázky

- Místa: vlastní číselník v modulu, nebo obecný číselník míst (využijí
  ho i jiné moduly)?
- Čísla karet (inv. č.): číselná řada per druh, nebo volný text s návrhem?
- Podklad pro přiznání DPPO: součty po `taxReturnGroup` řeší D66;
  mapování na konkrétní řádky tiskopisu (ve starém `taxDepsTaxCI`) až
  s podáním DPPO — patří do konfigurace země.
