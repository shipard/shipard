# Design system — Přehled detailu neopakuje hlavičku

**Stav:** hotovo — 2026-10-26, #140 (D26, D27); odchylky od auditu v §6, kontrakt zelený na `lh6x…` a `9gk5…`

## Kontext / Cíl

Část #140 zastřešující issue #133. Po kontraktu detailu (#136) má každý
detail hlavičku — titulek, podtitul, štítky. Tab Přehled ale u většiny
viewerů tytéž údaje vypisuje znovu: číselníky mají v Přehledu Název
a Kód, Zakázky Číslo a Název, Majetek Inventární číslo a Název, bankovní
pohyb Stav, který je už štítkem. D13 říká, že hlavička neopakuje
kontext; D26 přidává opačný směr.

**Cíl:**

1. Z Přehledu všech detailů odstranit položky, které opakují hlavičku
   (D26).
2. Kontrola v integračním kontraktu detailu, aby se opakování nevrátilo
   (D2).
3. Spisovna: přílohy dokumentu na konec prvního tabu přes sdílenou sekci,
   vlastní tab Přílohy pryč (D27).
4. Pravidlo do `docs/design-system.md` §12.

**Mimo rozsah:**

- ostatní taby detailu (Zaúčtování, Plán, Původ…) — D26 je o Přehledu;
- detail dokladu (obsah `document`): karta strany s názvem partnera je
  součást návrhu z pilotu (#135, D16), ne opakování; číslo dokladu
  v mřížce údajů není;
- vzhled seznamu vlastností a sekcí (#137);
- nové údaje v Přehledu — mění se jen to, co se odstraní.

## Návaznost

- **#133** — D2, D13, D19, D21, D26, D27
- **#136** — builder detailu, `ViewerDetailContractTest`
  (`tasks/design-system-detail-contract.md`, `…-contract-rollout.md`)
- **#137** — sdílené prvky a vizuální audit detailů; navazuje na tento task

## Před implementací přečti

- `docs/design-system.md` §12 Detail záznamu
- `src/Core/Viewer/TableViewer.php` — `renderDetail()`, `detailHeader()`,
  `propertiesTab()` (prázdné skupiny filtruje)
- `tests/Integration/Api/ViewerDetailContractTest.php`
- `modules/base/registry/src/RegistryDocumentsViewer.php` — `detailTabs()`,
  `buildAttachmentsTab()`, `detailHeader()`
- viewery z tabulky v §1

## Rozhodnutí (potvrzená v #133)

- ✓ **D26** Přehled neopakuje hlavičku: co je v hlavičce detailu
  (titulek, údaje podtitulku, štítky), se v Přehledu znovu nevypisuje;
  skupina vlastností, která tím zůstane prázdná, se nezobrazí. Hlídá to
  kontraktní test.
- ✓ **D27** Spisovna: přílohy dokumentu na konec prvního tabu (Obsah)
  přes sdílenou sekci (`detailAttachments: "overview"`), vlastní tab
  Přílohy zaniká.

## 1. Nálezy auditu

Audit prošel Přehled pěti nejnovějších záznamů každého vieweru na všech
vývojových zdrojích dat a porovnal položky s hlavičkou. Položky
k odstranění (popisek → co v hlavičce opakuje):

| Viewer | Odstranit z Přehledu |
|---|---|
| `base.registry.binders` | Název → titulek |
| `base.registry.documents` | Druh → štítek; Partner, Šanon → podtitul (+ D27, §3) |
| `core.ai.backends` | Název → titulek |
| `core.ai.functions` | Funkce → titulek; ID → podtitul |
| `core.alerts.alerts` | Titulek → titulek |
| `core.mail.aiProfiles` | Název → titulek |
| `core.mail.mailboxes` | Název → titulek; Kód schránky → podtitul |
| `core.mail.preprocessRules` | Klíč pravidla → titulek |
| `core.system.users` | Jméno → titulek; Login → podtitul (bez jména je login titulkem, §2) |
| `core.units` | Název → titulek; Zkratka, Systémový kód → podtitul |
| `docs.core.numberSeries` | Název → titulek |
| `economy.accbal.balanceAccounts` | Číslo účtu → titulek |
| `economy.accbal.balances` | Název → titulek; Zkrácený název jen když se liší (§2) |
| `economy.accbal.cases` | Partner → titulek; Saldokonto, Variabilní symbol, Období → podtitul |
| `economy.accbal.ledger` | Partner → titulek; Účet → podtitul; Text jen když není titulkem (§2) |
| `economy.accounting.accounts` | Název → titulek; Číslo účtu → podtitul; Název zkrácený jen když se liší (§2) |
| `economy.accounting.journal` | Text → titulek; Účet, Datum → podtitul |
| `economy.assets.accountingGroups` | Název → titulek; Kód → podtitul |
| `economy.assets.assets` | Název → titulek; Inventární číslo → podtitul |
| `economy.assets.typeGroups`, `economy.assets.types` | Název → titulek |
| `economy.bank.statements` | Číslo výpisu → titulek; Bankovní účet, Období → podtitul; Rekonciliace → štítek |
| `economy.bank.transactions` | Datum transakce, Partner, Název protistrany → podtitul; Stav → štítek |
| `economy.codebooks.bankAccounts`, `.cashDesks`, `.costCenters` | Název → titulek; Kód → podtitul |
| `economy.codebooks.fiscalYears`, `.vatRegistrations` | Název → titulek |
| `economy.items.kinds` | Název → titulek |
| `economy.workOrders.heads` | Název → titulek; Číslo → podtitul; Stav → štítek |
| `economy.workOrders.kinds`, `.numberSeries` | Název → titulek |

Bez nálezu: Osoby, Položky, Úkoly, Došlá pošta, konverzace, období DPH,
doklady.

**Viewery bez dat na vývojových zdrojích** — projít kódem podle stejného
pravidla: `core.exchange.tagRules`, `core.mail.senderRules`,
`core.mail.senders`, `core.mail.sent`, `core.prints.texts`,
`economy.codebooks.paymentTerminals`, `.transports`, `.warehouses`
(stejný vzor jako ostatní číselníky), `economy.exchangeRates.lists`,
`economy.vat.deductionCoefficients`, `economy.vat.filings`.

Shoda hodnot, která **není** opakováním (jiný údaj se stejnou hodnotou),
zůstává: Typ položky u druhů položek (druh se často jmenuje jako typ),
Předpisy / Úhrady / Zůstatek u pohybu saldokonta (součty případu
s jediným pohybem = částka pohybu), Pořadí u bankovních účtů (kód je
pořadové číslo). Tyto shody jdou do výjimek kontroly (§4).

## 2. Podmíněné položky

- **Náhradní titulek.** Když hlavička vezme titulek z náhradního údaje
  (pohyb saldokonta bez partnera → text; uživatel bez jména → login),
  ten údaj v Přehledu chybí jen v tom případě. Viewer rozhoduje ze
  stejného záznamu jako `detailHeader()` — logiku výběru titulku dej do
  jedné privátní metody, kterou volají obě místa.
- **Zkrácený název** (účty, saldokonta, případně další s `short_name`)
  se ukáže, jen když se liší od názvu.
- Ostatní položky z §1 se odstraňují bez podmínky.

## 3. Spisovna (D27)

- `modules/base/registry/module.jsonc`: `detailAttachments` dokumentů
  `"content"` → `"overview"`.
- `RegistryDocumentsViewer`: tab Přílohy a `buildAttachmentsTab()` pryč
  (dotaz na přílohy zaniká, dodá je builder); taby Obsah a Původ zůstávají.
  Klíč `attachments` v konfiguraci popisků tabů Spisovny smazat, pokud ho
  nic jiného nepoužívá.
- Přílohy se tak kreslí na konci tabu Obsah sdílenou sekcí (D21) a Odeslaná
  pošta za nimi (D22).
- `ViewerDetailContractTest::testRecordAttachmentsAppearInDetail` Spisovnu
  dál nepřeskakuje — u záznamu s přílohou ji musí najít.

## 4. Kontrola v integračním kontraktu

Nový test v `tests/Integration/Api/ViewerDetailContractTest.php`:

- pro každý tabulkový viewer **pět nejnovějších záznamů**
  (`ORDER BY id DESC LIMIT 5`);
- projde obsah prvního tabu rekurzivně (`properties` → `groups[].items`,
  `composite` → `blocks`); obsah `document` přeskočí (mimo rozsah);
- položka **opakuje hlavičku**, když se její hodnota (bez okrajových
  mezer, bez HTML) rovná titulku, jedné části podtitulku (dělené ` · `)
  nebo popisku štítku, anebo když se část podtitulku rovná „popisek
  hodnota“ / „popisek: hodnota“ (bez ohledu na velikost písmen —
  `Termín 1. 3.`, `Kód osoby 123`);
- chyba je jen **soustavná** shoda: dvojice (viewer, popisek položky,
  část hlavičky) se shoduje ve všech vzorkových záznamech, kde položka
  je, a takových záznamů jsou aspoň dva. Ojedinělá shoda (kód náhodou
  stejný jako název) test neshodí;
- výjimky `private const OVERVIEW_REPEAT_ALLOWED` — viewer id → popisky
  položek, každá s komentářem proč (§1, poslední odstavec). Výjimka,
  která na žádném zdroji dat nepomohla, test neshazuje (data se liší);
- hláška chyby: viewer, popisek, část hlavičky — **bez hodnot** (zdroj
  dat může nést reálná data).

Porovnávací logiku dej do statické metody testu a pokryj ji unit testem
s ručně sestaveným detailem (`tests/Unit/…/OverviewRepeatCheckTest.php`
nebo jako `@dataProvider` v kontraktu) — shoda titulku, části podtitulku,
„popisek hodnota“, štítku, ojedinělá vs. soustavná shoda, výjimka.

## 5. Dokumentace

- `docs/design-system.md` §12, část Hlavička / Obsah: pravidlo D26
  (co je v hlavičce, v Přehledu není; zkrácený název jen když se liší;
  náhradní titulek), výjimka pro kartu strany dokladu, kontrola
  v kontraktu. U příloh: Spisovna v prvním tabu (D27); vlastní tab
  Přílohy už má jen Majetek.
- `docs/frontend.md` §7: u `detailAttachments` aktualizovat příklady
  (`content` mají Došlá a Odeslaná pošta).

## 6. Odchylky od auditu (po implementaci)

- `core.units`: **Systémový kód** zůstává — hlavička ho nenese, audit ho
  chytil jen přes shodu seedu (`m`, `kg`, `l`); výjimka kontroly.
- `core.system.users`: bez jména dával `displayPattern`
  `{full_name} ({login})` titulek „(login)“ — hlavička je v PHP
  (`UsersViewer::detailHeader()`), deklarace pryč; Login je v hlavičce vždy,
  Jméno i Login z Přehledu bez podmínky.
- `core.mail.sent`: navíc **Komu** (bez adres jen „—“, s adresami část
  podtitulku) a **Vytvořeno** (podtitul).
- `economy.bank.transactions` **Částka** a `economy.accbal.ledger`
  **Částka {měna}**: hlavička částku nese (titulek se znaménkem, podtitul
  s měnou), audit je minul kvůli formátu; přepočet v domácí měně a kurz
  zůstávají jen u cizí měny.
- `economy.bank.statements` **Rekonciliace** podmíněně — jen bez štítku
  (Nezkontrolováno); `economy.bank.transactions` **Název protistrany**
  podmíněně — jen vedle partnera (bez partnera je v podtitulku).
- `economy.assets.assets`, `economy.assets.types`: **Zkrácený název** jen
  když se liší (audit neuváděl).
- `economy.vat.filings`, `core.exchange.tagRules`: beze změny — složený
  titulek (`03/2026 — Řádné 1`, `IČO → štítek`) není „částí“ hlavičky
  a u importovaných podání druh v názvu není.
- Výjimka navíc: `economy.accounting.journal` **Splatnost** — u bankovních
  a pokladních zápisů rovná datu zápisu (reálná kopie, 5 z 5).
- Mimo D26, opraveno zvlášť (commit 1b): pět viewerů (AI připojení,
  profily AI, schránky, pravidla předzpracování a odesílatelů) nevybíralo
  v `loadDetailRecord()` `docState`, štítek stavu se nikdy nekreslil;
  profil AI nevybíral ani `id`.
- Spisovnu s přílohou žádný vývojový zdroj dat nemá — sdílenou sekci
  u Spisovny kontrakt neověřil, jen v prohlížeči.

## Commity

1. `test(core): kontrola opakování hlavičky v Přehledu (#140)` — logika
   a unit test, integrační test zatím s výjimkami pro všechny nálezy §1
   (seznam `PENDING`, jen ubývá — stejný vzor jako `NOT_CONVERTED` v #136)
1b. `fix(core): štítek stavu v detailu AI připojení a nastavení pošty (#136, #140)`
2. `refactor: Přehled bez údajů z hlavičky — číselníky, AI, pošta, uživatelé, jednotky (#140)`
3. `refactor: Přehled bez údajů z hlavičky — saldokonto, účetnictví, banka, majetek, zakázky (#140)`
4. `feat(base.registry): přílohy dokumentu v tabu Obsah, Přehled bez opakování (#140)`
5. `docs: Přehled neopakuje hlavičku (#140)` — `PENDING` prázdný a pryč,
   `**Stav:**` tasku, `python3 scripts/tasks-index.py`

## Ověření

```bash
vendor/bin/phpunit --filter 'OverviewRepeat|DetailContractTest'
vendor/bin/phpunit --filter '<testy upravených viewerů>'
SHIPARD_INTEGRATION_DS_PATH=… vendor/bin/phpunit --testsuite Integration --filter ViewerDetailContractTest
```

Integrační kontrakt pusť na více vývojových zdrojích dat (různá data =
různé shody), aspoň na jednom s demo sadou #131.

V prohlížeči: číselník (pokladna, středisko), zakázka, karta majetku,
bankovní výpis a pohyb, případ a pohyb saldokonta, řádek deníku, uživatel —
Přehled bez údajů z hlavičky, žádná prázdná skupina; dokument Spisovny
s přílohou — přílohy na konci tabu Obsah, tab Přílohy není.

## Hotovo když

- [x] položky z §1 a viewery bez dat prošlé a upravené, podmíněné položky §2
- [x] Spisovna podle D27
- [x] kontrola v kontraktu se zelenou na vývojových zdrojích dat, `PENDING` zrušený
- [x] `docs/design-system.md` §12, `docs/frontend.md`
- [x] `**Stav:**` aktualizovaný, `tasks-index.py`

## Pasti

- **Neodstraňovat údaj, který hlavička nemá.** Audit porovnával hodnoty;
  před smazáním položky ověř v `detailHeader()` / deklaraci, že ji hlavička
  opravdu nese (u všech záznamů, ne jen u vzorku).
- **Prázdná skupina** — `propertiesTab()` prázdné skupiny filtruje, ručně
  skládané skupiny (mimo `propertiesTab()`) ne; zkontrolovat.
- **Testy viewerů** často ověřují položky Přehledu podle popisku —
  upravit očekávání, ne obcházet.
- **Hodnota s jednotkou nebo formátem** (částka s měnou, datum) se
  s podtitulem shoduje jen ve stejném formátu — kontrola pracuje
  s naformátovanými hodnotami, jak je pošle backend.
- **Data zdroje se v hláškách neobjevují** — jen viewer, popisek a část
  hlavičky.
