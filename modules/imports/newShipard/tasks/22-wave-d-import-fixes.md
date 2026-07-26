# 22 — Vlna D: fallback parsování čísel, FX mapování, enumerace reziduí

> **Design:** `docs/design-import-wave-d.md` v nov_shipard (D12–D15;
> D12 varianta A potvrzena).
> **Závislost:** nejdřív nasadit nov_shipard PRD
> `tasks/docs-wave-d-validation-fx.md` (warning místo erroru + FX
> operace + ds-upgrade).
> **Návaznost:** task 21 (klasifikace řádků), task 19/20 (vzory
> deterministických oprav).

## Scope

### 1. D13 — fallback parsování čísla přes řady docTypu

V cestě `resolveNumberSeriesCode`/`parseSequenceNumber`
(`DocsRunner`): když číslo neprojde formulí řady přilinkované přes
`dbCounter`, zkusit formule **všech ostatních řad téhož docType**:

- právě jedna řada, jejíž prefix/formule číslu odpovídá → použít ji
  a zalogovat warn (`doc {ndx}: number {n} matches series {A}, linked
  to {B} — using {A}`),
- nula nebo více shod → chyba jako dnes.

Pokrývá všech 41 msi chyb (6× sub-0 „Počáteční stavy" — řada ve zdroji
už existuje po INSERTu 2026-07-21 — a 35× čísla saldokonta přilinkovaná
k řadě „Otevření", např. 601910001).

### 2. D12 — mapování kurzových rozdílů (cmnbkp)

V cmnbkp klasifikaci (`buildCmnbkpCanonical`/`loadRows`) před bucket 4
(non-accountable):

- staré operace **1090011** (pohledávky) a **1090012** (závazky) →
  jedna ze čtyř nových operací; **ztráta/zisk se určí ze starého
  deníku** per řádek: join `e10doc_debs_journal` přes `document`
  + částku (moneyDr/moneyCr = |priceAll|) na P&L řádek — účet `563*`
  → Loss, `663*` → Gain; nejednoznačnost → chyba dokladu (režim D3d).
- payload: kladná částka, `partner` (person přes LocalIdMap),
  `paymentReference` = symbol1; bez účtu (kategorie dohledá nová
  strana).
- operace **1090070–72** (28 řádků): v rámci tasku prověřit jejich
  význam a starý deník; pravděpodobně `acc.record` s účtem joinem
  (à la D9) — zdokumentovat rozhodnutí do tohoto souboru.

### 3. D14 — duplicity `(řada, rok, pořadí)`: enumerace po D13

Po implementaci D13 spustit sandbox dry-run přes celý rozsah (postup
z tasku 21) na obou DS a vypsat rezidua duplicit jako páry
`(klíč; old ndx A × old ndx B; docNumber A × B)`. Výstup zapsat do
tohoto souboru k adresnému rozhodnutí (oprava zdroje vs. sufix) —
implementace řešení až po rozhodnutí. Poznámka pro pozdější alfu:
stejný postup je kandidát na kořen tamních 244 `unq_series_seq` chyb.

### 4. D15 — enumerace drobných reziduí

1. **`account_not_found` (15 msi + 2 lefreal):** z logů vypsat chybějící
   účty; ověřit proti zdrojovému rozvrhu (`docState` účtů) a proti
   `AccountsRunner` — importuje i archivní účty rozvrhu? (Podezření na
   vzor „archivní entita se neimportuje" — pokud potvrzeno, oprava
   linkable filtrů v runneru je součástí tasku.)
2. **Nevyrovnané při apply (2 msi + 1 lefreal) + 1 datum (msi):**
   vypsat old ndx + důvod → rozhodnutí oprava zdroje / akceptace
   (zapsat sem).
3. **4 haléřové deníky (lefreal cmnbkp):** per-řádkový diff jednoho
   dokladu (601410041) starý × nový deník → identifikovat zaokrouhlovací
   mechanismus a navrhnout řešení (pravděpodobně follow-up v nov_shipard,
   sem jen diagnóza).

## Oprava dat

Po dokončení obou PRD **třetí plný re-import obou DS** (ds-reset + all)
— zahrne D11 doklady (151 invni), D13 (41), D12 (277 cmnbkp ze stavu 20)
i případné D14/D15 opravy. Akceptaci provedu read-only:

- počty per docType × stav = zdroj (modulo mapování stavů; stav 20 jen
  obhajitelné případy — po D12 očekávám ~0 mimo 1090070–72, pokud
  zůstanou nevyřešené),
- invni/invno sumy po řadách na korunu na obou DS,
- deník 0 nevyrovnaných (mimo D15.3, dokud nerozhodnuto),
- banka beze změny zelená.

## Hotovo když

- [x] D13 fallback: všech 41 msi dokladů se parsuje (dry-run
      2026-07-22), warn s oběma řadami v logu; navíc datový fallback
      %y/%Y/%M pro přelomové doklady (lefreal 4072, 5080).
- [x] D12: kurzové řádky mapované na čtyři operace dle starého deníku;
      dry-run vzorku 2026-07-22 (lefreal doc 719 → fxLossReceivable,
      partner, payment_reference 1300001 ✓; plné pokrytí 268 lefreal
      + 3 msi řádků, 0 nejednoznačných).
- [x] 1090070–72 rozhodnuto a zdokumentováno zde (split na dva
      acc.record z deníku, implementováno; řeší i D15.2 nevyrovnané).
- [x] D14 rezidua enumerována zde (páry old ndx) — bez implementace.
- [x] D15.1 účty enumerovány (fix nepatří do AccountsRunner — archivní
      účty importuje; kandidát shpd fix + 2 účty chybí ve zdroji);
      D15.2 a D15.4 enumerovány zde; D15.3 diagnóza zapsána.

## Dodatek 2026-07-22 — implementace, rozhodnutí a enumerace

Vše ověřeno sandbox dry-runem přes celý rozsah obou DS (kopie DS configu
+ LocalIdMap s vymazanými doc mapováními, `--dry-run --dump-payload
--continue-on-error`, bez zápisů): **msi 0 failed / 32 739 dokladů,
lefreal 2 failed / 3 553** (obě rezidua = vady zdroje, viz D15.5).

### Implementováno v DocsRunner

1. **D13** — `resolveSeriesAndSequence()`: sdílený helper obou call sites
   (standardní + cmnbkp). Kandidátní řady se čtou přímo ze zdrojové
   `e10doc_base_docnumbers` (ne z config cache `_e10doc.docNumbers.json`
   — ta po INSERTu řady „Počáteční stavy" 2026-07-21 nebyla přegenerovaná
   a řadu 0 neobsahuje). Právě 1 shoda → warn s oběma řadami; 0/≥2 →
   chyba jako dřív. Všech 41 msi dokladů parsuje (6× řada 0, 35× řada 1).
2. **D13b (nad rámec zadání)** — datový fallback `%Y/%y/%M` po vzoru
   stávajícího `%r` fallbacku: při neshodě se zkusí `dateIssue` a začátek
   fiskálního roku hlavičky (číslo přelomového dokladu nese rok ražení,
   ne zaúčtování). Řeší lefreal 4072 (602120001, účt. 2020-12-31, vyst.
   2021-01-15) a 5080 (602220001, účt. 2021-12-31, vyst. 2022-01-20).
3. **D12** — `CMNBKP_FX_OP` (1090011/1090012 × loss/gain → čtyři
   `acc.fx*` operace) + `resolveFxDirection()` se třemi vrstvami:
   1. P&L položka deníku (563* MD = loss / 663* DAL = gain, match
      částky) — pozor, starý generátor P&L agregoval per směr,
   2. saldo položka per řádek (311*/321* + částka + symbol1 + person),
   3. doklad bez deníku (storno 4100→30) → směr ze strany řádku
      (MD = gain, DAL = loss dle acc-default docDir).
   Nejednoznačnost = tvrdá chyba dokladu (D3d).
   **Odchylky od textu designu zjištěné z dat:** (a) doprovodné P&L
   řádky nejsou vždy agregát — lefreal je má per FX řádek; (b) msi item
   „Kurzové ztráty" má kód `223`, ne 563* → drop doprovodných řádků jde
   podle operace (1099998/1099999) bez explicitního účtu, ne podle masky
   itemu; (c) FX doklad se pozná prescannem (nenulový FX řádek bez
   `debsAccountId`) a doprovodné P&L řádky se vypouštějí — nová operace
   účtuje obě strany sama; součtová pojistka (Σ doprovodných = Σ FX per
   strana) jinak doklad hlasitě shodí. Ruční kontace s účty na řádcích
   (lefreal 5050) zůstávají beze změny v bucketu 1.
   Pokrytí: lefreal 268/268 FX řádků (144 lossReceivable /
   85 gainReceivable / 20 lossPayable / 19 gainPayable), msi 3×
   lossPayable (+1 nulový řádek v mzdovém dokladu 66755 se přeskakuje).
   Vzorky dle „Hotovo když": lefreal 719 → `acc.fxLossReceivable`,
   kladných 50 806,73, partner pin person 11, `payment_reference`
   1300001 ✓; 721 → gainReceivable; msi 69112/87014/87514 →
   lossPayable (deník MD 563001 / DAL 321001); storno 7121 → 2×
   gainPayable (vrstva 3).
4. **1090070–73 — ROZHODNUTO: split, bez nové operace ve shpd.**
   Prověřením zdroje: řádky jsou účtované **oboustranně jedním řádkem**
   (`debit == credit`, klíč `property`; 214 msi + 29 lefreal nenulových;
   acc-default: 1090070/73 MD 022/DAL 042, 1090071 MD 08x/DAL 02x,
   1090072 MD 551/DAL 08x). Jednostranný import polovinu zápisu ztrácel.
   Nový kód (`CMNBKP_ASSET_PAIR_OPS` + `resolveAssetPairAccounts()`)
   řádek rozpadá na dva `acc.record` řádky s účty ze starého deníku per
   (document, property, částka) — deník má právě pár MD × DAL (vzor D9,
   deník je autorita i nad případným `debsAccountId`). Tím se **řeší
   i všechna tři „nevyrovnaná" rezidua z D15.2** (msi 31892/601830005,
   37605/601830006, lefreal 4696/602030002) — nebyly to vady zdroje,
   ale právě jednostranně importované 1090070/72 řádky.
5. Porovnání částek proti deníku přes `ABS(...) < 0.005` místo `= %f`
   (double vs DECIMAL se u částek mimo přesnou binární reprezentaci
   mine — msi 78408, částka 578 428,93).

### D14 — enumerace duplicit `(docType|řada|rok|sekvence)` — bez implementace řešení

Klíč roku = kalendářní rok `dateAccounting` (jak přesně odvozuje rok
`unq_series_seq` nová strana, ukáže až ostrý re-import). Třídy:

- **A — dvojí číslování ve zdroji (D13 doklady):** druhý doklad se
  stejným číslem byl ve zdroji přilinkovaný k řadě Otevření/Uzavření
  a po D13 fallbacku se parsuje do řady 1 — čísla jsou ve zdroji
  skutečně dvakrát (msi 40 klíčů = 8 roků × 5, lefreal 24). Rozhodnout:
  oprava čísel ve zdroji vs. import se sufixem.
- **B — pravé duplicity řad faktur** (invno/invni/cmnbkp, stejná čísla
  dvakrát): msi 10 klíčů (odpovídají 15 chybám `unq_series_seq`
  z ostrého běhu 2026-07-21), lefreal 2 klíče.
- **C — přelomové roky:** msi `invni 22110007 × 22210007` (různá čísla,
  stejný účetní rok), lefreal `602020001 × 602120001` — kolize nastane
  jen pokud nová strana odvozuje rok klíče z účetního data (ne z čísla).

msi (50 klíčů):
- `(cmnbkp|1|2013|1; old ndx 620 × 4400; docNumber 601310001 × 601310001)`
- `(cmnbkp|1|2013|2; old ndx 621 × 4401; docNumber 601310002 × 601310002)`
- `(cmnbkp|1|2013|3; old ndx 622 × 4402; docNumber 601310003 × 601310003)`
- `(cmnbkp|1|2013|4; old ndx 623 × 4403; docNumber 601310004 × 601310004)`
- `(cmnbkp|1|2013|5; old ndx 624 × 4404; docNumber 601310005 × 601310005)`
- `(cmnbkp|1|2014|1; old ndx 3665 × 7832; docNumber 601410001 × 601410001)`
- `(cmnbkp|1|2014|2; old ndx 3666 × 7833; docNumber 601410002 × 601410002)`
- `(cmnbkp|1|2014|3; old ndx 3667 × 7834; docNumber 601410003 × 601410003)`
- `(cmnbkp|1|2014|4; old ndx 3668 × 7835; docNumber 601410004 × 601410004)`
- `(cmnbkp|1|2014|5; old ndx 3669 × 7836; docNumber 601410005 × 601410005)`
- `(cmnbkp|1|2015|1; old ndx 7014 × 13047; docNumber 601510001 × 601510001)`
- `(cmnbkp|1|2015|2; old ndx 7216 × 13048; docNumber 601510002 × 601510002)`
- `(cmnbkp|1|2015|3; old ndx 7228 × 13049; docNumber 601510003 × 601510003)`
- `(cmnbkp|1|2015|4; old ndx 7337 × 13050; docNumber 601510004 × 601510004)`
- `(cmnbkp|1|2015|5; old ndx 7338 × 13051; docNumber 601510005 × 601510005)`
- `(cmnbkp|1|2016|1; old ndx 11670 × 20037; docNumber 601610001 × 601610001)`
- `(cmnbkp|1|2016|2; old ndx 11671 × 20038; docNumber 601610002 × 601610002)`
- `(cmnbkp|1|2016|3; old ndx 11680 × 20039; docNumber 601610003 × 601610003)`
- `(cmnbkp|1|2016|4; old ndx 11681 × 20040; docNumber 601610004 × 601610004)`
- `(cmnbkp|1|2016|5; old ndx 11683 × 20041; docNumber 601610005 × 601610005)`
- `(cmnbkp|1|2017|1; old ndx 19469 × 28910; docNumber 601710001 × 601710001)`
- `(cmnbkp|1|2017|2; old ndx 19488 × 28911; docNumber 601710002 × 601710002)`
- `(cmnbkp|1|2017|3; old ndx 19495 × 28912; docNumber 601710003 × 601710003)`
- `(cmnbkp|1|2017|4; old ndx 19496 × 28913; docNumber 601710004 × 601710004)`
- `(cmnbkp|1|2017|5; old ndx 19497 × 28914; docNumber 601710005 × 601710005)`
- `(cmnbkp|1|2018|1; old ndx 27472 × 37697; docNumber 601810001 × 601810001)`
- `(cmnbkp|1|2018|2; old ndx 27483 × 37698; docNumber 601810002 × 601810002)`
- `(cmnbkp|1|2018|3; old ndx 27539 × 37699; docNumber 601810003 × 601810003)`
- `(cmnbkp|1|2018|4; old ndx 27568 × 37700; docNumber 601810004 × 601810004)`
- `(cmnbkp|1|2018|5; old ndx 27625 × 37701; docNumber 601810005 × 601810005)`
- `(invno|1|2018|663; old ndx 30087 × 30118; docNumber 11810663 × 11810663)`
- `(cmnbkp|1|2019|1; old ndx 35682 × 44865; docNumber 601910001 × 601910001)`
- `(cmnbkp|1|2019|2; old ndx 35750 × 44866; docNumber 601910002 × 601910002)`
- `(cmnbkp|1|2019|3; old ndx 35756 × 44867; docNumber 601910003 × 601910003)`
- `(cmnbkp|1|2019|4; old ndx 35763 × 44868; docNumber 601910004 × 601910004)`
- `(cmnbkp|1|2019|5; old ndx 35780 × 44869; docNumber 601910005 × 601910005)`
- `(invni|1|2021|7; old ndx 49613 × 55675; docNumber 22110007 × 22210007)`
- `(invno|1|2021|784; old ndx 52734 × 52735; docNumber 12110784 × 12110784)`
- `(invno|1|2021|871; old ndx 52750 × 52751; docNumber 12110871 × 12110871)`
- `(invno|1|2021|859; old ndx 52781 × 52784; docNumber 12110859 × 12110859)`
- `(invno|1|2021|857; old ndx 52787 × 52789; docNumber 12110857 × 12110857)`
- `(invno|1|2021|854; old ndx 52804 × 52805; docNumber 12110854 × 12110854)`
- `(invni|1|2022|8; old ndx 55690 × 55733; docNumber 22210008 × 22210008)`
- `(invno|1|2023|1391; old ndx 69034 × 69035; docNumber 12311391 × 12311391)`
- `(cmnbkp|1|2023|307; old ndx 69181 × 69182; docNumber 602310307 × 602310307)`
- `(invno|1|2024|337; old ndx 70513 × 70534; docNumber 12410337 × 12410337)`
- `(invni|1|2024|200; old ndx 71247 × 71248; docNumber 22410200 × 22410200)`
- `(invno|1|2024|421; old ndx 71140 × 71147; docNumber 12410421 × 12410421)`
- `(invno|1|2024|431; old ndx 71159 × 71166; docNumber 12410431 × 12410431)`
- `(invno|1|2024|440; old ndx 71161 × 71163; docNumber 12410440 × 12410440)`

lefreal (28 klíčů):
- `(cmnbkp|1|2013|3; old ndx 667 × 913; docNumber 601310003 × 601310003)`
- `(cmnbkp|1|2013|4; old ndx 668 × 914; docNumber 601310004 × 601310004)`
- `(cmnbkp|1|2013|5; old ndx 669 × 915; docNumber 601310005 × 601310005)`
- `(cmnbkp|8|2013|12; old ndx 813 × 814; docNumber 601380012 × 601380012)`
- `(cmnbkp|1|2013|1; old ndx 664 × 911; docNumber 601310001 × 601310001)`
- `(cmnbkp|1|2013|2; old ndx 665 × 912; docNumber 601310002 × 601310002)`
- `(cmnbkp|1|2014|1; old ndx 666 × 1743; docNumber 601410001 × 601410001)`
- `(cmnbkp|1|2014|5; old ndx 958 × 1747; docNumber 601410005 × 601410005)`
- `(cmnbkp|1|2014|2; old ndx 764 × 1744; docNumber 601410002 × 601410002)`
- `(cmnbkp|1|2014|3; old ndx 901 × 1745; docNumber 601410003 × 601410003)`
- `(cmnbkp|1|2014|4; old ndx 951 × 1746; docNumber 601410004 × 601410004)`
- `(cmnbkp|1|2015|6; old ndx 1525 × 2480; docNumber 601510006 × 601510006)`
- `(cmnbkp|1|2015|7; old ndx 1526 × 2481; docNumber 601510007 × 601510007)`
- `(cmnbkp|1|2015|8; old ndx 1551 × 2482; docNumber 601510008 × 601510008)`
- `(cmnbkp|1|2015|9; old ndx 1552 × 2483; docNumber 601510009 × 601510009)`
- `(cmnbkp|1|2015|10; old ndx 1553 × 2484; docNumber 601510010 × 601510010)`
- `(cmnbkp|1|2016|1; old ndx 2000 × 3000; docNumber 601610001 × 601610001)`
- `(cmnbkp|1|2016|2; old ndx 2001 × 3001; docNumber 601610002 × 601610002)`
- `(cmnbkp|1|2016|3; old ndx 2050 × 3002; docNumber 601610003 × 601610003)`
- `(cmnbkp|1|2016|4; old ndx 2069 × 3003; docNumber 601610004 × 601610004)`
- `(cmnbkp|1|2016|5; old ndx 2129 × 3004; docNumber 601610005 × 601610005)`
- `(cmnbkp|1|2017|1; old ndx 2534 × 3350; docNumber 601710001 × 601710001)`
- `(cmnbkp|1|2017|4; old ndx 2657 × 3353; docNumber 601710004 × 601710004)`
- `(cmnbkp|1|2017|2; old ndx 2650 × 3351; docNumber 601710002 × 601710002)`
- `(cmnbkp|1|2017|3; old ndx 2649 × 3352; docNumber 601710003 × 601710003)`
- `(cmnbkp|1|2017|5; old ndx 2658 × 3354; docNumber 601710005 × 601710005)`
- `(cmnbkp|2|2020|1; old ndx 3764 × 4072; docNumber 602020001 × 602120001)`
- `(cmnbkp|2|2021|4; old ndx 4206 × 4320; docNumber 602120004 × 602120004)`

### D15 — enumerace

1. **`account_not_found` (15 msi + 1 lefreal v ostrém běhu 2026-07-21):**
   - msi, chybějící účty **221101–221105, 231001** (doklady 1639, 1797,
     2707, 3015, 3172, 4405, 5024): účty ve zdrojovém rozvrhu existují
     s `docState 9000` (V archívu). `AccountsRunner` je importuje
     (filtr jen `!= 9800`; LocalIdMap `accountingAccount` má všech 839
     účtů) — hypotéza „archivní entita se neimportuje" tedy pro runner
     **neplatí**. Účet nenachází až účtování na nové straně →
     **fix patří do shpd** (lookup rozvrhu v AccountingEngine ignoruje
     archivní účty, doc_state 70). Kandidát na follow-up PRD.
   - msi, účet **343901** (doklady 1642–1646, 1916, 2198, 2561) a
     lefreal, účet **343100** (doklad 628): ve zdrojovém rozvrhu
     **neexistují vůbec** → vada zdroje. Rozhodnout: doplnit účty do
     zdrojového rozvrhu vs. akceptovat fail.
2. **Nevyrovnané při apply: vyřešeno** — všechny 3 doklady byly
   jednostranně importované majetkové řádky 1090070/72 (viz bod 4
   implementace). **1 datum:** msi invni 51367 (22110178) nemá
   `issueDate` (validace `dates.issueDate required`) → rozhodnout:
   doplnit datum ve zdroji vs. akceptace.
3. **Haléřové deníky (4× lefreal cmnbkp) — diagnóza:** doklady v cizí
   měně. Starý deník dorovnával kurzový zaokrouhlovací zbytek do řádku,
   nová strana počítá CZK per řádek nezávisle. Doklad 1134 (601410041),
   kurz ≈ 27,905: řádek 4 637,20 → per-row 129 401,07, starý deník
   **129 401,06** (dorovnáno, aby Σ MD = 214 277,75 = DAL); zbylé řádky
   sedí. Nová strana tak vyjde o 0,01 jinak → nevyrovnaný deník.
   **Follow-up do shpd:** vyrovnávací mechanismus přepočtu měny per
   doklad (distribuce zbytku do posledního řádku), sem jen diagnóza.
4. **Nová třída „Peníze na cestě" (stav 20):** lefreal 4791, 5691,
   5692, 6634 + msi 57119 — řádky 1099998 bez itemu a bez účtu
   (u lefreal se zápornými částkami) + 1099999 s účtem 701xxx.
   Jediná zbylá non-accountable rezidua po D12 + splitu majetku.
   K rozhodnutí: D9-style join účtů z deníku (pozor na záporné částky
   a strany) vs. akceptace stavu 20.
5. **Lefreal parse-rezidua (2 doklady):**
   - 2964 (`cmnbkp 601780008`): číslo nese rok **17**, ale doklad je
     celý v 2018 (vystaveno i účtováno 2018-01-31, FY 7 = 2018) →
     vada zdroje; rozhodnout přečíslování (601880008) vs. akceptace.
   - 633 (`invni 210001`, 2012): legacy formát čísla (nesedí na formuli
     `%D%r%C%4` žádné řady) → oprava zdroje vs. akceptace.

### Stav akceptace

**Rozhodnuto 2026-07-22 (všech šest bodů):** D14-A přečíslování ve
zdroji (skripty připraveny, 35 msi + 25 lefreal), D14-B import-sufix,
D15.1b INSERT účtů, D15.2/D15.5 UPDATE zdroje, D15.4 join.

### K implementaci po rozhodnutí (D14-B sufix, D15.4 join)

**D14-B — sufix pravých duplicit.** V `DocsRunner` per běh držet množinu
viděných klíčů `(docType, řada, rok, sekvence)`; při druhém výskytu
(vyšší old ndx — pořadí zpracování keysetu to zaručuje):
`docNumber .= '-2'` a **`sequenceNumber = null`** (unikátní index
`unq_series_seq` NULL nekoliduje; číslo je mimo formuli, do čítače se
nesynchronizuje) + warn s oběma old ndx. Týká se reziduí po přečíslování
třídy A: msi 15 klíčů (602310307; invni 22210008, 22410200, 22511204;
invno 11810663, 12110784, 12110854, 12110857, 12110859, 12110871,
12311391, 12410337, 12410421, 12410431, 12410440), lefreal 3 klíče
(601380012; 602120004; 602220001 — pár s D13b přelomovým 5080).

**D15.4 — „Peníze na cestě“ join.** V cmnbkp bucketu 4 před parkováním:
D9-style join řádku na `e10doc_debs_journal` přes `document` + |částka|
(ABS < 0.005) — právě jedna položka → `acc.record` s účtem a stranou
z deníku (pozor: záporné částky lefreal — strana z deníku, částka
kladná); nejednoznačnost → stávající parkování na stavu 20 + warn
(ne chyba). Pokrývá lefreal 4791, 5691, 5692, 6634 + msi 57119.

### Zbývající předpoklady třetího re-importu

1. SQL skripty ve zdrojích (připravil Claude v chatu 2026-07-22):
   D14-A přečíslování (35 msi + 25 lefreal), účty 343901 (msi)
   a 343100 (lefreal), dateIssue 51367 (msi), přečíslování 2964
   → 601880011 a 633 → 21210001 (lefreal).
2. nov_shipard `tasks/accounting-archived-accounts-linkable.md`
   (účtování na archivní účty — 7 msi dokladů).
3. Tento dodatek (sufix + join).

### Dodatek 2026-07-22 v2 — rezidua třetího re-importu

Třetí běh: msi 3 chyby / lefreal 170 — vše = **FX doklady** selhávají
na save validaci vyrovnanosti cmnbkp (řádek bez strany, obě strany
účtuje předpis). Fix na nové straně:
`tasks/docs-fx-self-balancing-validation.md`. K tomu dvě doplňující
položky zde:

1. **Deklarované součty po vypuštění doprovodných řádků:** FX doklady
   posílají staré `totals` včetně vypuštěných P&L řádků (deklarovaná
   2× částka → `totals_mismatch` warning). Po vypuštění řádků
   v `buildCmnbkpCanonical` odečíst jejich částky z deklarovaných
   součtů (resp. součty přepočíst z odesílaných řádků).
2. **`ambiguous-header` (6 msi dokladů: old ndx 203, 419, 727, 1043,
   1346 + 1× řada 119):** apply vrací 422 `unresolved_required` —
   resolve našel víc kandidátů hlavičky. Enumerovat kandidáty
   (preview endpoint / `_resolve` payload), určit příčinu
   nejednoznačnosti a navrhnout řešení (užší matching či userAction
   v payloadu). Zapsat sem.

Po obou fixech stačí **plain re-run docs fáze** na obou DS (selhané
doklady v LocalIdMap nejsou) — bez ds-resetu.

## Dodatek 2026-07-22 (2) — implementace sufixu + změna D15.4

### D14-B sufix — implementováno v DocsRunner

`$seenSeriesKeys` (klíč `docType|řada|rok(dateAccounting)|seq` → první old
ndx + počet) + `applySeriesDedup()` volaný z `resolveSeriesAndSequence()`
(jediný seam obou call sites): n-tý výskyt klíče → `docNumber .= '-n'`,
`sequenceNumber = null`, warn s oběma old ndx. Množina žije per běh —
korektní pro plný re-import z čisté mapy (přeskočené doklady klíč
neregistrují).

**Vyžaduje shpd task `tasks/docs-import-number-null-sequence.md`**
(spustit na druhém vývojářském serveru): dnešní
`DocumentApplier`/`DocDocument::applyImportNumber` explicitní null
přetypují na 0 a spadnou do normálního přidělení čísla z čítače — sufix
by se zahodil. DB je připravená (`sequence_number` nullable, UNIQUE
s NULL nekoliduje), chybí jen průchod null až do INSERTu + přeskočení
bumpu čítače.

### D15.4 — ZMĚNA ROZHODNUTÍ: join → storno se necapuje

Všech 5 dokladů „Peníze na cestě" (lefreal 4791, 5691, 5692, 6634; msi
57119) je **storno (4100 → 30) s prázdným deníkem** — rozhodnutý D9-style
join nemá k čemu joinovat. Místo něj (odsouhlaseno 2026-07-22): cap
non-accountable dokladů na stav 20 se zúžil z `targetState > 20` na
`targetState > 30` — storno nic neúčtuje a validace účtů/vyrovnanosti
(`AccountingDocument::validateBalance`) běží až při stavu 40, takže
řádky bez účtu projdou. Join se neimplementoval (nulové pokrytí).

### Ověření (sandbox dry-run přes celý rozsah, 2026-07-22)

Zdrojové SQL opravy (D14-A přečíslování, 343901/343100, dateIssue 51367,
2964 → 601880011, 633 → 21210001) už byly v obou DS aplikované — dry-run
to potvrzuje:

- **msi: 0 failed / 32 739**; přesně 15 sufix warnů = reziduální třída B
  (11810663, 12110784/854/857/859/871, 12311391, 12410337/421/431/440,
  22210007, 22210008, 22410200, 602310307; predikce dodatku uváděla navíc
  22511204 — v datech se neobjevil, a naopak 22210007 ano); D13 warnů
  po přečíslování zbývá 12; stav 20 non-accountable: 0 (57119 → 30).
- **lefreal: 0 failed / 3 553**; 3 sufix warny (601380012-2, 602120004-2,
  602120001-2 — predikce uváděla 602220001, reálný pár je
  602020001 × 602120001 přes účetní rok 2020); dřívější parse-rezidua
  2964/633 po přečíslování zdrojů parsují; stav 20: 0 (4791/5691/5692/
  6634 → 30).
- Payload sufixu: `importNumber {docNumber: '11810663-2',
  sequenceNumber: null}`, `numberSeriesCode` zachován.

### Zbývá před třetím re-importem

1. Nasadit shpd task `docs-import-number-null-sequence.md` (druhý
   server) — bez něj sufixované doklady dostanou nové číslo z čítače.
2. shpd task archivních účtů (`accounting-archived-accounts-linkable.md`)
   — 7 msi dokladů.

## Dodatek 2026-07-22 (3) — implementace položek z v2

### 1. Totals po vypuštění doprovodných řádků — implementováno

`loadCmnbkpRows()` akumuluje částky vypuštěných P&L řádků do
`droppedTotal`; `buildCmnbkpCanonical()` o ně ponižuje deklarované
`totalBase`/`totalAmount` (`totalsLessDropped()`, null zůstává null).
Ověřeno na datech: staré cmnbkp totals = Σ obou stran (719:
101 613,46 = 2× 50 806,73). Sandbox dry-run payloady po fixu: 719 →
50 806,73; msi 69112 → 4 815,15 (= vypočtená varianta serveru z logu
třetího běhu); storno 7121 → 305,50. Regresní plné dry-runy: msi
0 failed / 32 739, lefreal 0 failed / 3 553, warny beze změny.

### 2. ambiguous-header — příčina a řešení

Příčina NENÍ v matchingu: hlavičkoví partneři 6 dokladů **nejsou
v LocalIdMap**, takže faktura nemá `_resolve` pin (pinování už
existuje, DocsRunner L732–737) a Party fragment bez identifikátorů je
pro applier (matchStrategy=identifiersOnly) nerozhodnutelný → 422
`unresolved_required`. Dvě třídy:

- **FO bez `firstName` (celé jméno v `lastName`)** — PersonsRunner je
  skipoval (validační gate). Na dokladech: Zhang Bo (481; 5 z 6
  ambiguous dokladů 203/419/727/1043/1346 + dalších 5 dokladů),
  JUDr. Růžička (221, 1), Beltrám (1748, 2), Kalhová (1763, 2).
  Celkem má msi takových osob **59** (většina bez dokladů — dosud
  tiše chyběly v číselníku osob); lefreal 0.
- **Smazané firmy (9800) referencované doklady:** Amazonia ccc s.r.o.
  (1705) — 6. ambiguous doklad invno 40342 (11911084); SECOMP PC Plus
  CZ (1312, 7 invni dokladů) — **jeho doklady prošly** (supplier
  fragment nese IČO → identifikátorový match), undelete je jen pro
  úplnost číselníku.

Řešení (rozhodnuto 2026-07-22: oprava zdroje + trvalý fallback):

1. **PersonsRunner fallback (implementováno):** FO s chybějícím
   `firstName`/`lastName` se už neskipuje — chybějící pole se
   deterministicky odvodí z `fullName` (vedoucí titulové tokeny
   `Ing./MUDr./…` se přeskočí; ≥2 tokeny: první = jméno, zbytek =
   příjmení; 1 token: obojí) + warn. Skip zůstává jen pro prázdný
   fullName. Dry-run msi: 59 osob s warnem `derived from fullName`,
   0 failed; lefreal beze změny.
2. **Oprava zdroje msi:** `tasks/22-fix-source-msi-persons.sql` —
   jména 4 FO na dokladech (⚠ před spuštěním doplnit TODO křestní
   jména; Zhang Bo navržen jako Bo/Zhang — potvrdit) + undelete 1312
   a 1705 do archivu (9000).

### Postup k ostrému běhu

1. Aplikovat `22-fix-source-msi-persons.sql` (po doplnění TODO jmen).
2. Re-run `persons` fáze na msi (doplní chybějící osoby do LocalIdMap;
   idempotentní — existující skipne).
3. Po nasazení shpd `docs-fx-self-balancing-validation.md` (druhý
   server) plain re-run `docs` fáze na obou DS (bez ds-resetu) —
   selhané/skipnuté doklady v mapě nejsou. Očekávání: 6 ambiguous
   dokladů projde (partner pin), FX doklady projdou bez
   `totals_mismatch`, 3 msi + 170 lefreal FX chyb zmizí.
