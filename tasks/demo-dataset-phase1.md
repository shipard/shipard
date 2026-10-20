# Demo sada „IT servis“ — fáze 1: generátor a příběh firmy

**Stav:** naplánováno — zadání podle #131 D1–D15, čeká na implementaci

## Kontext / Cíl

Sady v `tests/datasets/cz/` jsou korpus pro AI — vlastní firma a došlé
zprávy s ideální analýzou, žádné hotové doklady. Pro testování vzhledu
a funkčnosti aplikace (přehledy, saldokonto, výsledovka) a pro screenshoty
a videa webu chybí sada s „žitými“ daty plátce DPH.

Fáze 1 přidá **generátor fiktivních sad** nad sdíleným balíkem `invoicegen`
a první sadu `demo/datasets/it-servis/`: malá IT firma, měsíční plátce DPH,
deset měsíců provozu — vydané a přijaté faktury, pokladna, mzdy, spisovna
a pár zpráv k řešení. Sada se generuje z ručně psaného **příběhu firmy**
a needituje se ručně (#131 D5, D6).

Fáze 2 (mimo tento task) rozšíří formát sady o bankovní výpisy, zakázky
a majetek a příběh o ně doplní (#131 D2).

## Návaznost

- **#131** — rozhodnutí D1–D15 (shrnutí na konci)
- **#40** — datové sady; **#48** — videa podle scénáře
- `shpd-web:docs/demo-scenare.md` oddíl *Zadání demo sady* — zadání
  zdroje dat „IT servis“ pro web
- `tasks/demo-invoices.md` — generátor PDF pro video (identity, šablony,
  ověření v ARES); `tasks/testing-phase1.md`, `tasks/testing-phase2.md` —
  korpus a sady z korpusu (vzor zapisovače, snapshot analýzy)
- **#125** — opakovaný VS na saldokontu; proto VS = číslo faktury (D8)
- **#121** — kurzy ČNB; doklady v cizí měně nesou kurz přímo v sadě

## Před implementací přečti

- `docs/datasets.md` celý — struktura sady, `setup/`, obálka spisovny,
  `shpd.mail.incomingMessage.v1`, průběh seedu, konvence JSON
- `docs/test-corpus.md` § 16 — jak sadu staví `invoicegen/dataset.py`,
  byte-shoda s dumpem, čísla v blobech analýz
- `docs/exchange-format.md` § 5 (`docType`, polymorfismus, `cashDesk`,
  `cashRegisterDocument`), § 6 (strany), § 7 (řádky), § 8.4 (kódy DPH),
  § 10 (apply, `importNumber`, `targetDocState`)
- `tools/invoice-generator/README.md` a moduly `dataset.py`, `compute.py`,
  `render.py`, `registries.py`, `checks.py`
- `demo/invoices/README.md`, `demo/datasets/README.md`
- `modules/core/exchange/src/Export/DocumentExporter.php`,
  `PersonExporter.php`, `ItemExporter.php` — **tvar, který dump vypíše**;
  generátor ho musí vyrobit bajtově stejně (D10)
- `modules/core/mail/src/Dataset/MailSeeder.php` — vazba `target` ↔
  `source_message`
- `modules/base/registry/schemas/` — obálka `shpd.dataset.registryDocument.v1`

## Rozsah

**V rozsahu:**

- sdílené části `invoicegen/dataset.py` (zapisovač JSON, slug, snapshot
  analýzy, manifest) použitelné mimo korpus — refaktor beze změny výstupu
  sad korpusu
- formát příběhu firmy a jeho validace
- rozvinutí příběhu na sadu: `setup/`, `persons/`, `items/`, `docs/`,
  `mail/`, `registry/`, `manifest.jsonc`
- PDF přijatých faktur a dokumentů spisovny přes Gotenberg
- vstupní bod `demo/datasets/build.py` s kotevním datem (D7)
- příběh `demo/datasets/stories/it-servis/` a vygenerovaná sada
  `demo/datasets/it-servis/` (commitují se, včetně PDF — D9)
- unit testy generátoru a ověření seed → dump (D10)
- dokumentace

**Mimo rozsah:**

- bankovní výpisy, zakázky, majetek (fáze 2)
- platby faktur jinak než hotovostí přímo na faktuře (viz Pasti — řádky
  úhrad v pokladních dokladech)
- `dateMode: relative` v PHP — posun dat řeší přegenerování (D7)
- sady „Nemovitosti“ a malé zdroje dat pro portál (další příběhy, jiný task)
- úprava videa S1 a dema Hoblinky

## Návrh

### Umístění

```
demo/datasets/
  build.py                       # vstupní bod (D5)
  README.md                      # jak generovat, formát příběhu, jak seedovat
  stories/it-servis/             # příběh firmy — ručně (D6)
    story.jsonc                  # firma, období, kotva, pravidla
    partners.jsonc               # odběratelé a dodavatelé
    items.jsonc                  # položky (služby, hardware, drobné zboží)
    registry.jsonc               # dokumenty spisovny
    mail.jsonc                   # zprávy k řešení
  it-servis/                     # vygenerovaná sada — needitovat
    manifest.jsonc
    setup/ persons/ items/ docs/ mail/ registry/
```

Rozvinutí příběhu žije v balíku `invoicegen` (nový modul, např.
`invoicegen/story.py`), `build.py` je tenký vstupní bod jako
`demo/invoices/build.py`. Dělení souborů příběhu je návrh — drž se
principu „ručně psaná data, žádné vygenerované seznamy dokladů“.

### Příběh firmy (D6, D12)

Příběh popisuje **pravidla**, ne jednotlivé doklady. Generátor je rozvine
deterministicky — žádná náhoda, ani se seedem. Rozmanitost (různé částky,
dny plateb, počty kusů) vzniká z dat příběhu a z pořadí měsíce, ne z RNG.

Obsah příběhu „IT servis“:

- **Vlastní firma** — fiktivní s.r.o., IT servis, měsíční plátce DPH od
  prvního dne prvního měsíce dat; jeden bankovní účet (kód v číselníku),
  výchozí pokladna `POK`. Identita ověřená jako u Hoblinky (Pasti →
  Identity).
- **Období** — deset celých měsíců před měsícem kotvy a měsíc kotvy
  do dne kotvy (D14). Výchozí kotva **2026-10-20** (data leden–říjen 2026).
- **14 odběratelů** — většina tuzemských, 2 slovenští (jazyk `sk`),
  1 německý plátce DPH (jazyk `de`, přenesení daňové povinnosti u služeb).
  Zahraniční fakturují v EUR s kurzem z příběhu (pevný per měsíc, D13).
  Každý odběratel má kontakt s účelem odeslání pro faktury
  (`sendPurposes`, `docs/prints.md` § 9).
- **Vydané faktury** (řádově 120–140):
  - měsíční paušál správy IT (většina odběratelů), čtvrtletní paušál (pár);
  - jednorázové faktury (hardware, práce navíc v hodinách) — v příběhu
    vyjmenované per měsíc;
  - jedna roční zálohová faktura (`proformaIssued`) jednomu odběrateli;
  - minulé měsíce V pořádku, měsíc kotvy zčásti V pořádku a zčásti
    v Konceptu (bez čísla);
  - pár faktur placených hotově s `cashDesk` (D8 ve fázi 1).
- **Přijaté faktury** (6–8 dodavatelů: hosting, licence, telefon
  a internet, nájem kanceláře, energie, velkoobchod s hardwarem) — každá
  jako zpracovaná zpráva s PDF + doklad V pořádku navázaný na zprávu (D9).
- **Zprávy k řešení** — 3–4 zprávy s ideální analýzou a otevřeným návrhem,
  z toho jeden e-mail s lhůtou (expirace domény) tak, aby se na Dashboardu
  ukázal jako K vyřízení (`docs/dashboard.md`, sekce K vyřízení / #105 —
  dohledat, odkud karta lhůtu bere).
- **Pokladna** — prodejky drobného zboží (`cashRegisterDocument`), výdajové
  pokladní doklady (poštovné, drobný nákup; položkové řádky bez partnera).
- **Mzdy** — měsíční interní doklad (`accountingDocument`) se zaúčtováním
  mezd a odvodů; řádky bez partnera.
- **Spisovna** — servisní smlouvy s několika odběrateli (platnost, část
  končí do 60 dnů od kotvy), pojistka, nájemní smlouva kanceláře; PDF
  přílohy v obálce.

### Platby (D8)

Příběh u odběratele nese chování plateb (včas / pozdě o N dní / částečně
/ vůbec) a u faktury případně výjimku. **Fáze 1 z toho do sady promítne
jen platbu hotově** — faktura s `payment.method: cash` a `cashDesk`.
Ostatní zůstanou otevřené; chování se ve fázi 2 promítne do bankovních
výpisů. Model plateb proto drž oddělený od výstupu (funkce „platby
příběhu“ → seznam událostí; fáze 1 je zahodí kromě hotovosti).

Variabilní symbol vydané faktury = číslo faktury (kvůli #125).

### Kotva a data (D7)

- Všechna data v příběhu jsou relativní: měsíc vůči kotvě, den v měsíci,
  splatnost ve dnech, „platí N dní po splatnosti“.
- `build.py --anchor YYYY-MM-DD` kotvu přepíše; bez parametru platí kotva
  z `story.jsonc`.
- `manifest.created` = kotva v 00:00:00Z (deterministické).
- Registrace DPH začíná prvním dnem prvního měsíce dat — sada s jinou
  kotvou je pořád konzistentní.
- Sada v repu je vygenerovaná s výchozí kotvou; posunutá sada se do repa
  necommituje (patří do dočasné složky, `build.py --out`).

### Tvar výstupu

Generátor vyrábí soubory, které `dataset-dump` naseedovaného zdroje dat
vypíše **bajtově stejně** (D10). Prakticky:

- doklady s číslem nesou `applyOptions.importNumber` (`docNumber`,
  `sequenceNumber`) a `numberSeriesCode`; čísla musí odpovídat vzorci řady
  čerstvého zdroje dat (`docTypes.jsonc` → `doc_number_pattern_default`),
  aby po seedu pokračoval čítač a nový doklad v UI dostal další číslo;
- koncepty bez čísla (`targetDocState: 10`, bez `importNumber`);
- `totals` a `vatRecap` spočítané `invoicegen/compute.py` tak, jak je
  vypíše exportér; pořadí souborů a slugy jako `DocumentExporter`
  (`ORDER BY` v `query()`), `PersonExporter`, `ItemExporter`;
- přijatá faktura: `docNumber` = číslo dodavatele, `supplier.bankAccount`;
  zpráva `docState` 40, `analysisState` 30, analýza `status` 2,
  `resolution` 40, `target` = `{table: docs_core_heads, docNumber: <naše
  číslo>}`;
- osoby všech partnerů v `persons/` (seed resolvuje přísně, bez
  autocreate), vlastní firma `isOwn`.

Skutečný tvar ověř nejdřív na malém vzorku (krok 3) — tabulky výše jsou
odhad podle exportérů, autorita je dump.

## Kroky (commity)

1. **Refaktor `invoicegen/dataset.py`.** Vytáhni zapisovač JSON, `slug`,
   stavbu snapshotu analýzy (`dataset_canonical`, `analysis_json`)
   a zápis manifestu tak, aby je šlo volat mimo korpus (bez `Cell`).
   Hotovo když: `python3 tests/corpus/cz/build.py` dá bajtově stejné sady
   (`git status` čistý v `tests/datasets/`), `python3 -m unittest`
   v `tools/invoice-generator` a `--filter CorpusExpectedTest` projdou.
2. **Identity a formát příběhu.** Soubory příběhu „IT servis“, loader
   s validací (povinná pole, kontrolní součty IČO / účtů / IBAN přes
   `checks.py`, jedinečnost kódů, odkazy partner ↔ položka). Ověření identit
   v ARES / VIES přes `registries.py` (jen ručně s přepínačem, síť).
   Unit testy loaderu.
3. **Rozvinutí příběhu — vydané doklady, pokladna, mzdy.** Funkce
   příběh + kotva → seznam dokladů (canonical). Nejdřív **vzorek**: pár
   dokladů každého typu ručně naseeduj do čerstvého volného zdroje dat
   a porovnej s jejich dumpem; podle rozdílů dolaď tvar. Pak celý příběh.
   Unit testy: determinismus (dva běhy = stejný výstup), posun kotvy
   (počet a typy dokladů stejné, data posunutá), součty, číslování.
4. **Přijaté faktury, zprávy, spisovna.** PDF ze šablon `demo/invoices/`
   přes `render.py` (šablona per dodavatel), zprávy zpracované
   i k řešení, obálky spisovny s PDF. Unit testy tvaru zpráv (vazba
   `target`, `att:` odkazy, čísla v blobech jako PHP — `docs/test-corpus.md`
   § 16).
5. **`build.py`, sada, ověření, dokumentace.** Vygeneruj
   `demo/datasets/it-servis/`, ověř podle oddílu Ověření, napiš
   `demo/datasets/README.md` (generátor, formát příběhu, kotva, seed),
   doplň `docs/datasets.md` (odkaz na demo sady), `tools/invoice-generator/README.md`
   (nový modul), řádek v `tasks/README.md` (Testování a datové sady),
   `**Stav:**` tohoto tasku + `python3 scripts/tasks-index.py`.

## Ověření (D10)

Na vývojovém stroji, čerstvý volný zdroj dat:

```bash
php bin/shpd-server ds-create --name "Demo IT servis" --language cs --country cz --no-cron
cd /opt/shipard/data-sources/<id>
shpd-ds dataset-seed /cesta/k/repu/demo/datasets/it-servis -y
shpd-ds dataset-dump /tmp/it-servis-dump --force
diff -r /cesta/k/repu/demo/datasets/it-servis /tmp/it-servis-dump
```

- seed bez chyb, varování jen vysvětlitelná (zapsat do Odchylek);
- `diff` prázdný kromě `manifest.created` a `title` (`docs/datasets.md` § 4)
  — každý další rozdíl je chyba generátoru, ne dumpu;
- v deníku součty MD = D; vydané faktury V pořádku jsou zaúčtované;
- ruční kontrola v aplikaci (zapsat, co bylo vidět): viewer vydaných
  a přijatých faktur, detail přijaté faktury s PDF, saldokonto (otevřené
  a po splatnosti), přehled firmy, Dashboard se zprávami k řešení
  a lhůtou, spisovna s blížící se platností, tisk faktury slovenskému
  a německému odběrateli.

Ověřovací zdroj dat po skončení smaž (`shpd-server ds-delete`).

## Pasti

- **Řádkový partner se do sady nepřenáší.** `DocumentExporter` řádek
  s partnerem vypíše bez něj (jen varování). Úhrada faktury řádkem
  pokladního dokladu (`payment.receivable` s pinem partnera) by se
  naseedovala, ale dump by ji ztratil → D10 neprojde. Proto ve fázi 1
  hotovost jen jako `payment.method: cash` + `cashDesk` přímo na faktuře
  a pokladní doklady jen s položkovými řádky bez partnera. Mzdy (interní
  doklad) taky bez partnera na řádcích.
- **Hlavičkový partner interního dokladu** se taky nepřenáší — mzdový
  doklad ho nemá mít.
- **Identity.** Všechny subjekty fiktivní: IČO s platným kontrolním
  součtem a ověřené jako neexistující v ARES, DIČ / VAT ID zahraničních
  partnerů ověřené ve VIES jako neplatné, čísla účtů s mod 11 a sedícím
  IBAN. Názvy bez zásahu v ARES. E-mailové domény pod `.example`
  (RFC 2606, D15) — žádná doména třetí strany, na rozdíl od dema Hoblinky.
  Datum ověření zapiš do `partners.jsonc` jako komentář.
- **Žádná reálná data.** Sada je veřejná (web, video). Nic se nepřebírá
  z reálných zdrojů dat ani jako „inspirace“ částek.
- **Cizí měna.** Doklady v EUR nesou `exchangeRate` z příběhu; bez kurzu
  by se nepotvrdily (#121).
- **Přenesení daňové povinnosti** u německého odběratele — kód DPH
  a místo plnění podle `docs/exchange-format.md` § 8.4 a `docs/accounting.md`
  (reverse charge); ověř, že doklad padne do správného řádku přiznání
  a souhrnného hlášení.
- **Účetní položky.** Manifest nese `provision.accountingItems: "all"`;
  řádky přijatých faktur a pokladny odkazují na kódy z nabídky — ověř je
  proti `AccountingItemsOffer`, ne odhadem.
- **Číselné řady.** Kódy řad a vzorec čísla ověř na čerstvém zdroji dat
  po `dataset-seed` (vzorek v kroku 3). Pokladní doklady a prodejky mají
  řady vázané na pokladnu.
- **Gotenberg** musí být dostupný (`GOTENBERG_URL`); PDF se commitují —
  hlídej velikost (cíl jednotky MB celkem).
- **Determinismus.** Žádné `datetime.now()`, `random`, pořadí ze
  `set` / `dict` bez řazení. Dva běhy `build.py` = `git status` čistý.

## Hotovo když

- [ ] sady korpusu po refaktoru beze změny (krok 1)
- [ ] příběh „IT servis“ v `demo/datasets/stories/it-servis/` odpovídá
      D12 (počty v Odchylkách, pokud se liší)
- [ ] `python3 demo/datasets/build.py` vygeneruje `demo/datasets/it-servis/`,
      druhý běh nic nezmění; `--anchor` posune data
- [ ] unit testy generátoru projdou (`python3 -m unittest`
      v `tools/invoice-generator`)
- [ ] ověření seed → dump podle oddílu Ověření, ruční kontrola zapsaná
- [ ] dokumentace: `demo/datasets/README.md`, `docs/datasets.md`,
      `tools/invoice-generator/README.md`, řádek v `tasks/README.md`
- [ ] `**Stav:**` aktualizovaný, `python3 scripts/tasks-index.py --check`
      a `python3 scripts/check-sensitive.py` projdou

## Rozhodnutí k designu (potvrzená, #131)

- ✓ **D1** — nová samostatná sada, plátce DPH, fiktivní firma; hotové
  doklady, v poště jen pár zpráv; sady korpusu se nerozšiřují
- ✓ **D2** — dvě fáze; fáze 1 jen s tím, co formát sady umí dnes
- ✓ **D3** — sada vzniká generátorem
- ✓ **D4** — jedna sada pro testování i web
- ✓ **D5** — `demo/datasets/build.py` nad `invoicegen`, výstup
  `demo/datasets/<sada>/`, needituje se ručně
- ✓ **D6** — sada = ručně psaný příběh firmy, deterministické rozvinutí
- ✓ **D7** — data relativní ke kotvě, `--anchor`, sada v repu `fixed`
- ✓ **D8** — platby modelované nezávisle na nosiči; fáze 1 jen hotovost;
  VS = číslo faktury
- ✓ **D9** — přijatá faktura = zpracovaná zpráva s PDF + navázaný doklad;
  PDF se commitují
- ✓ **D10** — unit testy + seed → dump bajtově stejně
- ✓ **D11** — první sada „IT servis“, vlastní nová fiktivní firma
- ✓ **D12** — obsah příběhu (viz oddíl Příběh firmy)
- ✓ **D13** — zahraniční odběratelé fakturují v EUR s kurzem z příběhu
  (bez kurzu by se doklad nepotvrdil, #121)
- ✓ **D14** — období = deset celých měsíců před měsícem kotvy a měsíc
  kotvy do dne kotvy; registrace DPH od prvního dne dat (s výchozí kotvou
  totéž co „leden 2026 → kotva“)
- ✓ **D15** — e-mailové domény fiktivních subjektů pod `.example`
  (RFC 2606), žádná doména třetí strany
