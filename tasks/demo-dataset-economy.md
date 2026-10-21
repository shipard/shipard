# Demo sada „IT servis“ — ekonomika příběhu a převody EUR → CZK

**Stav:** hotovo — implementováno 2026-10-21 (D25–D28), ověřeno na čerstvém zdroji dat, ruční průchod v aplikaci dělá Anna

## Kontext / Cíl

Po fázi 2a (`tasks/demo-dataset-phase2a-bank.md`) se ukázalo, že ekonomika
příběhu „IT servis“ nesedí:

- **Chyba generátoru:** u faktur v EUR bere řádek z položky cenu z katalogu
  v CZK bez přepočtu (`expand.py`, `_item_row` → `item.price`). Monitor za
  7 490 Kč je na faktuře slovenskému odběrateli za 7 490 EUR, hodina práce
  německému odběrateli za 1 250 EUR. Zahraniční tržby jsou tím přes 60 %
  všech tržeb a na eurovém účtu se nastřádalo 52 100 EUR.
- **Skutečná ekonomika je ztrátová.** Po opravě přepočtu vychází výnosy
  za období zhruba 1,3 mil. proti nákladům 1,77 mil. (mzdy 1,13 mil.,
  dodavatelé 0,63 mil.). Výsledovka by ukázala ztrátu asi 0,5 mil.
  Chyba přepočtu to dnes zakrývá.
- **Korunový účet** platí všechny náklady, eura se nikdy nepřevedou —
  BU1 klesá a drží ho jen počáteční zůstatek 1,25 mil.

Sada je pro web a screenshoty, takže má ukazovat zdravou malou firmu.

## Návaznost

- **#131** — D1–D24 (komentáře v issue), tento task D25–D28
- `tasks/demo-dataset-phase1.md`, `tasks/demo-dataset-phase2a-bank.md` —
  generátor, příběh, banka, Odchylky
- `docs/bank.md` § 6.2 (`transfer.in` / `transfer.out`, kategorie
  `cash.transit`), § 6.3 (účet peněz na cestě)

## Před implementací přečti

- `demo/datasets/stories/it-servis/` (story, partners, items)
- `tools/invoice-generator/invoicegen/expand.py`, `bank.py`, `story.py`
- `docs/bank.md` § 6.2, § 6.3; `docs/accbal.md` (které skupiny sledují
  účet peněz na cestě — podle zjištění z fáze 2a žádná)

## Rozsah

**V rozsahu:**

- oprava přepočtu ceny položky na měnu faktury (D27)
- nové částky v příběhu podle cílů D25
- převody EUR → CZK mezi vlastními účty (D26)
- zahraniční dodavatel služby s fakturami v EUR placenými z BU2 (D28)
- souhrn ekonomiky z generátoru (kontrola cílů)
- přegenerovaná sada, testy, ověření

**Mimo rozsah:**

- zakázky (fáze 2b) — paušály zůstávají v `billing` příběhu; 2b je
  převezme i s novými částkami
- kurzové rozdíly (převod i platby jedou kurzem z příběhu)
- mzdy po zaměstnancích, DPH platby (D22)

## Návrh

### D27 — Přepočet ceny položky na měnu faktury

Cena položky v `items.jsonc` je v CZK. Na faktuře v cizí měně je jednotková
cena = cena v CZK ÷ kurz měsíce vystavení (`rates`), zaokrouhleno na celé
eurocenty (`ROUND_HALF_UP`). Platí pro paušály bez vlastní ceny,
jednorázové řádky (hardware, hodiny) i cokoli dalšího, co bere cenu
z katalogu. Pravidlo s vlastní `price` u odběratele v cizí měně je cena
v měně faktury (jako dnes) — v komentáři příběhu to výslovně napsat.

Loader / validace: faktura v cizí měně, jejíž řádek bere cenu z katalogu
bez kurzu, je chyba (žádný tichý fallback na kurz 1).

### D25 — Cíle ekonomiky příběhu

Konkrétní částky navrhne implementace; příběh musí splnit:

- **Výsledek za období kladný, zisk 8–15 % výnosů** (podle Výsledovky,
  sloupec Od počátku roku k měsíci kotvy).
- **Jednotlivé měsíce smí být ve ztrátě** (měsíc s odměnami, nákup
  hardwaru), nejvýš 2–3 měsíce z deseti.
- **Zahraniční odběratelé 10–20 % tržeb.** Paušál německého odběratele
  snížit z 1 250 EUR na úroveň srovnatelnou s největšími tuzemskými
  klienty (řádově 600–700 EUR měsíčně).
- **Tuzemské paušály** realistické pro správu IT malých firem; přírůstek
  tržeb hlavně odsud (zvýšení paušálů, případně víc jednorázové práce).
- **Hardware se prodává s maržou:** prodej zboží za období ≥ nákup od
  velkoobchodu × 1,15 (dnes se nakupuje víc, než prodá).
- **Mzdy** beze změny, pokud cíle jde splnit tržbami; jinak navrhnout
  v Odchylkách.
- **Počáteční zůstatek BU1** nejvýš 300 tisíc; zůstatek BU1 nesmí klesnout
  pod nulu (validace z 2a zůstává).
- Saldokonto má dál co ukázat: chování plateb odběratelů (pozdě, částečně,
  neuhrazeno, nespárované) se nemění.

Komentář v `story.jsonc` „příběh je na bance ztrátový“ nahradit popisem
ekonomiky (výnosy, náklady, zisk v %, podíl zahraničí).

### D26 — Převody EUR → CZK

Pravidelný převod mezi vlastními účty: z BU2 odejde částka v EUR
(`operation: transfer.out`), na BU1 přijde v CZK (`transfer.in`) téhož dne.

- Pravidlo v příběhu (`bank.transfers`): čtvrtletně, nebo když zůstatek
  BU2 přesáhne hranici — navrhne implementace; na BU2 má zůstat provozní
  rezerva, ne nula.
- Částka v CZK = částka v EUR × kurz transakce na BU2 (kurz měsíce
  z `rates`), aby účet peněz na cestě vyšel nulový. Kurzový rozdíl banky
  se nemodeluje.
- Protiúčet = náš druhý účet; operace výslovně v sadě (exportér ji
  vypíše, výchozí `payment.*` ne — jako `fee.out`).
- Ověř, že protiúčet vlastního účtu nedohledá partnera (nebo že partner
  nevadí) a že obě strany zaúčtuje mikroengine bez chyby.

### D28 — Zahraniční dodavatel v EUR

Nový dodavatel služby ze země EU (např. cloudová záloha nebo monitoring
jako předplatné), měsíční faktura řádově 150–250 EUR:

- přijatá faktura v EUR s přenesením daňové povinnosti (daň přiznává
  příjemce — `reverseCharge`, pár řádků podle `docs/exchange-format.md`
  § 8.4); kurz měsíce z `rates`;
- jako ostatní přijaté faktury: zpracovaná zpráva s PDF + navázaný doklad
  (D9), PDF ze šablon `demo/invoices/` — text faktury v angličtině nebo
  němčině podle země dodavatele;
- úhrada **z BU2**, kurz transakce = kurz hrazené faktury (jako U1),
  VS = číslo faktury dodavatele, protiúčet = IBAN dodavatele;
- identita: vymyšlený název, VAT ID ověřené ve VIES jako neplatné, doména
  `.example`, telefon s předčíslím `000` (pravidla z `partners.jsonc`).

Platby dodavateli z eurového účtu sníží potřebu převodů (D26), ale
nenahradí je — eurové tržby zůstanou vyšší než eurové náklady.

### Souhrn ekonomiky

`build.py` po sestavení vypíše krátký souhrn: výnosy, náklady, výsledek
a zisk v %, měsíce ve ztrátě, podíl zahraničních tržeb, nákup a prodej
zboží, nejnižší zůstatek BU1 a BU2. Výpočet ze stejných dat, ze kterých
vznikají soubory (ne čtením výstupu). Cíle D25 hlídá unit test nad
výchozí kotvou.

## Kroky (commity)

1. **Přepočet ceny (D27)** v generátoru + validace + unit testy (EUR
   faktura s katalogovou položkou, zaokrouhlení, chybějící kurz = chyba).
2. **Souhrn ekonomiky** v `build.py` + test cílů D25 (zatím padá).
3. **Převody (D26) a dodavatel v EUR (D28)** v generátoru + unit testy
   (párování transfer.out / transfer.in, součty výpisů, peníze na cestě
   nulové, úhrada EUR faktury z BU2 bez rezidua v CZK).
4. **Nové částky v příběhu** — test cílů projde; sada přegenerovaná;
   ověření; `demo/datasets/README.md`, `**Stav:**`,
   `python3 scripts/tasks-index.py`.

## Ověření

Čerstvý volný zdroj dat, `dataset-seed`, `dataset-dump`, `diff -r` jako
v 2a (jen `manifest.created`), a dál:

- Výsledovka, Od počátku roku k měsíci kotvy: zisk v pásmu D25 — čísla do
  Odchylek; Přehled firmy ukazuje totéž;
- žádná transakce s chybou účtování; převody zaúčtované na peníze
  na cestě, účet peněz na cestě nulový;
- přijaté faktury zahraničního dodavatele: přenesení daňové povinnosti
  zaúčtované (daň na vstupu i výstupu), úhrady z BU2 uzavřely případy
  na Závazcích bez rezidua v CZK; v náhledu Přiznání DPH za měsíc kotvy
  se plnění objeví na řádcích pro přijetí služby z EU (zapsat, co bylo
  vidět — přiznání se nepodává);
- zůstatky 221100 / 221200 = konečné zůstatky posledních výpisů;
- saldokonto: stejné kategorie případů jako v 2a (uzavřené, částečně,
  po splatnosti, záměrně nespárované) — počty do Odchylek.

Ověřovací zdroj dat po kontrole smaž, nebo ho nech Anně a zapiš prefix.

## Pasti

- **Kurz převodu.** CZK strana převodu musí být přesně EUR × kurz BU2
  transakce (zaokrouhlení na haléře ve stejném směru jako mikroengine),
  jinak zůstane haléřový zůstatek na penězích na cestě.
- **Posun kotvy** nesmí rozbít cíle: test cílů běží nad výchozí kotvou,
  pro posunutou kotvu stačí validace zůstatků.
- **Změna řady souborů.** Nové částky změní skoro všechny doklady i PDF
  přijatých faktur (pokud se mění dodavatelé); to je očekávané. PDF cache
  přerenderuje jen změněné.
- **Fiktivní data** platí dál (žádné reálné subjekty, telefony `000`).

## Hotovo když

- [x] faktury v EUR mají ceny katalogových položek přepočtené kurzem
- [x] souhrn ekonomiky z `build.py` splňuje D25, test cílů projde
- [x] převody EUR → CZK ve výpisech obou účtů, peníze na cestě nulové
- [x] faktury zahraničního dodavatele v EUR s přenesením daňové povinnosti,
      uhrazené z BU2, saldo bez rezidua
- [x] počáteční zůstatek BU1 ≤ 300 tisíc, zůstatky nikdy pod nulou
- [x] druhý běh `build.py` nic nezmění; unit testy generátoru a dotčené
      PHPUnit testy projdou
- [x] ověření podle oddílu Ověření, výsledky v Odchylkách
- [x] `**Stav:**` aktualizovaný, `python3 scripts/tasks-index.py --check`
      a `python3 scripts/check-sensitive.py` projdou

## Odchylky a zjištění při implementaci (2026-10-21)

- **Jen generátor a příběh, PHP beze změny.** Vše, co sada potřebuje
  (`transfer.in` / `transfer.out` → 261100, samovyměření cz-217 s párem
  cz-207, kurz na transakci), aplikace už uměla; generátor se jen naučil
  tvar dumpu: `RecapLine.is_reverse_pair` → `vatRecap[].isReversePair`,
  `doccalc.SELF_ASSESSED_CODES` (daň na řádku i v rekapitulaci
  informativně, celkem = základ, pár mimo součty hlavičky).
- **Převody (D26)** jako pravidlo `bank.transfers` s měsíční kontrolou
  a hranicí, ne pevné čtvrtletí: k 25. dni se z BU2 převede zůstatek nad
  rezervou 1 000 EUR dolů na stovky, nejméně 1 000 EUR → 6 převodů
  (únor–květen, červenec, září; 1 000–2 300 EUR). CZK strana =
  `round(EUR × kurz, 2)` jako `amount_dom` v PHP; protiúčet = náš druhý
  účet, partner po seedu null (vlastní osoba v sadě bankovní spojení nemá).
- **Dodavatel z EU (D28)** nizozemský (`noordwolk`, cloudová záloha
  + monitoring 188 EUR měsíčně, 5. den, splatnost 14 dní, placeno včas
  z BU2 kurzem faktury) — angličtina místo němčiny, aby šla použít
  korpusová šablona `en.html` (kopie v `demo/invoices/templates/`);
  její hodnoty (`build_values`, `LABELS`, `COUNTRY_NAMES`) se přesunuly
  ze skriptu `tests/corpus/cz/build.py` do `invoicegen/corpusprint.py`
  (HTML korpusu beze změny — 52 buněk se stejným hashem). Formát příběhu:
  dodavatel `language` / `currency` / `vatTreatment`, řádky
  `supplyKind: services` a `vatPct` 21 = sazba samovyměření (papír tiskne
  0 % + klauzuli). Jen služby (cz-217); zboží z EU sada neumí.
- **Částky (D25)** se mzdami beze změny: paušály domácích odběratelů
  zhruba dvojnásobné (4 800 – 27 500 Kč, „Hotel Modrý Jelen“ 25 000 →
  27 500 od června), německý paušál 650 EUR, každý měsíc faktura za
  8–16 h práce v rotaci odběratelů, prodeje 2 × NTB v dubnu, červenci
  a září, velkoobchod nakupuje v dubnu, červnu, červenci a září (NTB 1–2,
  switch, monitory, Wi-Fi AP, kabely). Souhrn: výnosy 2 089 121, náklady
  1 819 275 (mzdy 1 127 934, dodavatelé 679 250, pokladna 10 570, poplatky
  1 521), zisk +269 846 = **12,9 %**; ztrátový červen (odměny, −72 tis.)
  a srpen (−3 tis.); zahraničí **17,0 %**; zboží prodej 317 801 / nákup
  217 738 (1,46×); BU1 300 000 → minimum 275 316 → 600 313; BU2 0 → 2 867
  EUR. Cíle hlídá `tests/test_economy.py`; posun kotvy jen validace
  zůstatků (převody závisí na zůstatku k datu).
- **Souhrn ekonomiky** = `invoicegen/economy.py` ze `Records` (doklady
  stav 40 kurzem dokladu, mzdy strana MD, poplatky z výpisů); Výsledovka
  v aplikaci se liší jen o haléřová zaokrouhlení prodejek (548 / 648),
  která souhrn nemodeluje.
- **Seed varuje** u 10 faktur dodavatele z EU `rows_recap_mismatch`
  a `vat_recap_inconsistent` — kontrola `DocumentApplier` sčítá i párový
  řádek samovyměření a u `noPayTax` čeká základ + daň = celkem; doklad se
  uloží správně (DocDocument rekapitulaci přepočítá), dump je bajtově
  shodný. Kandidát na drobnou opravu kontroly v PHP (mimo tento task).
- **Ověření** (čerstvý volný DS `lsx3-g…`, ponechán k ručnímu průchodu):
  `diff -r` jen `manifest.created`; deník 1 356 řádků, MD = D, 0 chybových;
  234 transakcí ve stavu účtování 1; partner null jen u 27 mezd, 9
  poplatků a 12 stran převodů; 261100 obrat MD = D = 225 813 (zůstatek 0),
  261300 a 331 / 336 / 342 na nule, 261200 −44 770 = 3 záměrně nespárované
  příjmy; samovyměření 343217 MD 9 797,37 = 343207 D (10 dokladů, deník
  `518206 MD / 343217 MD / 343207 D / 321100 D` v CZK kurzem faktury);
  saldokonto Pohledávky 129 případů — 112 uzavřených, 2 částečně, 14 po
  splatnosti, 1 před splatností (EUR, platí se pozdě až po kotvě);
  Závazky 58 uzavřených (10 v EUR bez rezidua) + 1 se splatností za kotvou;
  Nespárované platby 3 s partnerem, případ s prázdným klíčem 0 z 54 pohybů;
  Přijaté zálohy 1 otevřená; 221100 = 600 312,81 = closing BU1 10/2026,
  221200 = 2 867,25 EUR = closing BU2. Výsledovka `report-run
  economy.accounting.profitLoss --fiscal-year 2026 --month-from 10
  --month-to 10`, sloupec Od počátku roku: náklady 1 819 276,56, výnosy
  2 089 127,49 → +269 850,93. Přiznání DPH živě 10/2026 (`report-run
  economy.vat.returnLive --period 19`): ř. 5 základ 4 600,36 / daň 966,08
  (přijetí služby z EU), ř. 43 totéž jako odpočet, ř. 21 služby do EU
  37 194,40, „Součty daně souhlasí s účetním deníkem“, vlastní daň
  24 951,99 — přiznání se nepodává.

## Rozhodnutí k designu (potvrzená, #131)

- ✓ **D25** — ekonomika příběhu: zisk 8–15 % výnosů za období, ztrátové
  nejvýš 2–3 měsíce, zahraničí 10–20 % tržeb (německý paušál řádově
  600–700 EUR), hardware s maržou, počáteční zůstatek BU1 ≤ 300 tisíc
- ✓ **D26** — pravidelné převody EUR → CZK mezi vlastními účty
  (`transfer.out` / `transfer.in`), kurzem z příběhu
- ✓ **D27** — oprava generátoru: cena katalogové položky na faktuře v cizí
  měně = CZK ÷ kurz měsíce vystavení
- ✓ **D28** — zahraniční dodavatel služby (EU), měsíční faktury v EUR
  s přenesením daňové povinnosti, hrazené z BU2 kurzem faktury
