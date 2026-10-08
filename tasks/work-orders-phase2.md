# Zakázky Fáze 2 — periodická fakturace

**Stav:** naplánováno — #110 D2–D7, D10–D12, D24; Q1–Q7 potvrzené 2026-10-08

> PRD pro jednu Claude Code session (6 commitů). Design:
> `docs/work-orders.md` §4 (D2–D7, D10–D12, D24), §5.5; issue #110.
> Navazuje na `tasks/work-orders-phase1.md` (evidence zakázek, dimenze
> zakázka). Úrovně V pořádku a automatické odeslání (D4) jsou fáze 3.

## Kontext

Fáze 1 založila zakázky, ale nic nevystavují. Tahle fáze přidá
periodické zakázce fakturační předpis (hlavička + řádky), evidenci
vyfakturovaných období a denní běh, který chybějící období vystaví jako
koncepty faktur ke kontrole. Doklad vzniká **ve výměnném formátu přes
`DocumentApplier`** — stejnou cestou jako import a došlá pošta, takže
odvození DPH, číselná řada, zaokrouhlení i dimenze fungují bez druhé
implementace (Q1).

Zdroj `689089` (≈95 měsíčních faktur a ≈230 ročních zálohových) je
první ostrý uživatel; jeho přefakturace spotřeby (D7) zatím zůstává
ruční — tahle fáze pro ni připraví rozhraní přispěvatelů (D10).

## Cíl

1. Fakturační předpis periodické zakázky: výchozí hodnoty na druhu,
   přepisy a předpis na hlavičce, řádky zakázky (D3, D11, D12).
2. Evidence období a běh — cron `daily` a CLI s náhledem, dohánění,
   idempotence (D5, D6).
3. Vznik konceptu faktury nebo zálohové faktury přes výměnný formát;
   karta ve feedu ke kontrole (D4 úroveň Koncept).
4. Zastavení smazaným konceptem, Přegenerovat, Obnovit, záložka
   *Fakturace* (D5, D24).
5. Rozhraní přispěvatelů obsahu se stavem *čeká na podklady* (D7, D10)
   — bez jediného skutečného přispěvatele.

## Před implementací přečti

- `docs/work-orders.md` §4 (D2–D7, D10–D12, D24), §5.3, §5.5
- `tasks/work-orders-phase1.md` + jeho Poznámky k implementaci;
  `modules/economy/workOrders/` — `WorkOrderTypes` a `config/types.jsonc`
  (příznak `invoicing`), `WorkOrderDocument`, `KindDocument`,
  `WorkOrdersViewer` (detail s taby), `WorkOrderJournalService`
- `docs/exchange-format.md` — kanonický doklad (§5, §7), `_resolve`
  a `useExisting`, `applyOptions` (§ Apply a doc state), `dimensions`;
  `modules/core/exchange/src/Document/DocumentApplier.php`
- `docs/cli.md` (`cron --slot`, `CronCommand::JOB_ALLOWED_STATES`),
  vzor per-DS příkazu `src/Command/DataSource/VatPeriodsEnsureCommand.php`
- `docs/alerts.md` (§3 JSONC, §4 PHP API, §9 akce) a `alertChecks`
  v `modules/economy/assets/module.jsonc`
- `docs/frontend.md` § Akce detailu (`button`, `dropdown`,
  `open_detail`, `open_viewer`)
- `src/Core/I18n/DocumentLanguageResolver.php` (jazyk dokumentu podle
  partnera — formát `období`)
- `src/Core/Module/ModuleDefinition.php` — vzor registrace providerů
  (`documentLockProviders`)
- `modules/docs/core/config/sourceKinds.jsonc`, `vatModes.jsonc`,
  `paymentMethods.jsonc`

## Scope

**Uvnitř:** vše v Cíli.

**Mimo:** úrovně V pořádku a V pořádku s automatickým odesláním (fáze 3,
Q7); skutečný přispěvatel (přefakturace spotřeby — samostatný task po
dohodě); výměnný formát a import zakázek (`work-orders-import.md`);
poměrné krácení částečného období (Q3); projektové funkce (D19).

## 1. Fakturační předpis (D3, D11, D12)

**Druh** (`economy_work_orders_kinds`, jen periodický typ — formulář pole
ukáže podle příznaku `invoicing` z cfgItem typů): výchozí
- `inv_doc_type` — `invno` / `invpo`;
- `inv_number_series` — řada dokladů; typ řady musí odpovídat
  `inv_doc_type`;
- `inv_due_days` — splatnost ve dnech;
- `inv_timing` — `start` / `end` (fakturace na počátku / konci období);
- `inv_vat_mode` — ceny bez DPH (ze základu) / s DPH (z ceny celkem);
- `inv_payment_method`, `inv_bank_account` — způsob úhrady a vlastní
  bankovní účet (Q5). Účet smí zůstat prázdný na druhu i na zakázce —
  pak ho builder nepošle a doklad dostane výchozí účet jako každý jiný
  doklad.

**Zakázka** (`economy_work_orders_heads`, jen periodická):
- `inv_periodicity` — `month` / `quarter` / `halfyear` / `year`
  (cfgItem `economy.workOrders.periodicities`); povinná při potvrzení;
- `inv_from` — fakturovat od (výchozí = `date_start`), povinné při
  potvrzení;
- `inv_doc_text` — text dokladu s proměnnou `{období}` (Q6);
- přepisy všech hodnot druhu ze seznamu výše se stejnými názvy sloupců;
  **NULL = z druhu**. Efektivní hodnotu počítá jedna služba
  (`InvoicingSettings` nebo obdobně) — formulář ji ukazuje jako
  placeholder, builder ji používá;
- `payment_reference` (pevný VS, fáze 1) se na fakturu propíše, je-li
  vyplněný (D11).

**Řádky** — `economy_work_orders_rows`: `work_order`, `order_pos`,
`item`, `description`, `quantity`, `unit`, `unit_price`, `vat_code`,
`operation` (prázdný = výchozí pohyb cílového dokladu), `valid_from`,
`valid_to`, `contributor` (id registrovaného přispěvatele, NULL = pevný
řádek; pole se ve formuláři ukáže jen, když nějaký přispěvatel
existuje). Child tabulka zakázky (`docs/document-system.md` §6),
editace jako řádky dokladu. Potvrzení periodické zakázky vyžaduje
aspoň jeden řádek.

## 2. Období a sestavení dokladu (D5, D6, D11, D12)

**`economy_work_orders_periods`**: `work_order`, `period_from`,
`period_to`, `state` (`planned` / `waiting` / `issued`), `doc`
(`docs_core_heads`), `content_hash` (otisk vygenerovaného obsahu, §5),
`waiting_since`, `message` (poslední chyba nebo důvod čekání),
`created_at`, `updated_at`. UNIQUE (`work_order`, `period_from`).

**Zastaveno** (D5, D10) se neukládá — je to `issued` období, jehož
doklad je ve stavu Smazáno nebo neexistuje. Obnovení dokladu z koše
období tím samo „odzastaví“.

**Období** (Q3): kalendářní podle periodicity. První = období, které
obsahuje `inv_from`. Období je **splatné**, když jeho den fakturace
(`period_from` u `start`, `period_to` u `end`) ≤ datum běhu. Období
začínající po `date_end` se nevystaví; období přes `date_end` se vystaví
celé (bez krácení).

**Builder** sestaví kanonický `shpd.docs.document.v1`:
- typ dokladu a řada (`applyOptions.numberSeriesCode`) z efektivních
  hodnot; `targetDocState: 10`;
- partner = zákazník (`_resolve` `useExisting:<id>`), měna zakázky;
- `issueDate` = `accountingDate` = `taxPointDate` (DUZP) = den fakturace
  období (D6), `dueDate` = + splatnost; `periodFrom` / `periodTo` =
  období; `docText` viz níže;
- režim DPH (pokud ho kanonický formát nenese, doplnit), způsob
  úhrady, vlastní účet, pevný VS (D11);
- text dokladu: `inv_doc_text` s dosazeným `{období}`; prázdný →
  `{název zakázky} {období}` (Q6);
- `dimensions`: `workOrder` = číslo zakázky, `costCenter` = kód
  střediska zakázky;
- `source.kind` = nový klíč `workOrder` v `docs.core.sourceKinds`
  („Periodická fakturace“);
- řádky: řádky zakázky platné k DUZP (`valid_from` ≤ DUZP ≤ `valid_to`,
  NULL = neomezeno) — položka (`ourCode`), popis, množství, jednotka,
  cena, `vat.code`, pohyb. Žádný platný řádek → období se nevystaví,
  zůstane `planned` se zprávou a ohlásí ho upozornění (§6).

**`{období}`** v jazyce dokumentu podle zákazníka
(`DocumentLanguageResolver`): měsíc „říjen 2026“, čtvrtletí
„3. čtvrtletí 2026“, pololetí „2. pololetí 2026“, rok „2026“; en / sk /
de obdobně (Q6). Alias `{period}`.

## 3. Běh (D5)

- Služba běhu: pro každou periodickou zakázku **V pořádku** s `inv_from`
  založí chybějící splatná období (`planned`) a vystaví všechna
  `planned` a `waiting`. Zakázka V opravě se přeskočí — její období
  vystaví první běh po návratu do V pořádku (dohánění).
- **Atomicita:** založení dokladu a zápis `doc` do období v jedné
  transakci (applier ve vnější transakci, jako u `externalTransaction`
  dokladu; chybí-li to applieru, doplnit). Nikdy doklad bez vazby na
  období — opakovaný běh by vystavil duplikát. Souběh dvou běhů hlídá
  UNIQUE klíč a zámek řádku období.
- **Pojistka dohánění** (Q4): víc než 3 splatná období jedné zakázky
  najednou běh nevystaví — ohlásí upozornění; vystaví je akce *Vystavit
  dlužná období* na zakázce nebo CLI s `--work-order`.
- Chyba jedné zakázky (validace applieru, chybějící řada…) zastaví jen
  ji: období zůstane `planned` se zprávou, běh pokračuje.
- **CLI** `shpd-ds work-orders-invoice-run [--date=YYYY-MM-DD]
  [--work-order=<číslo>] [--dry-run]` — `--date` simuluje datum běhu
  (splatnost), `--dry-run` vypíše období a doklady, které by vznikly, bez
  zápisu. Výstup: počty a seznam (číslo zakázky, období, výsledek).
- **Cron:** job v slotu `daily`, stav DS `active`
  (`CronCommand`, `docs/cli.md`).

## 4. Fakturace na zakázce, Přegenerovat, Obnovit (D24)

- Záložka **Fakturace** v detailu periodické zakázky: období (nejnovější
  nahoře), stav (naplánováno / čeká na podklady / vystaveno / zastaveno),
  doklad (`open_detail`), částka, zpráva; nahoře příští splatné období.
- Akce detailu:
  - *Vystavit dlužná období* (`button`) — běh jen pro tuto zakázku
    (i přes pojistku dohánění);
  - *Přegenerovat* (`dropdown` období s konceptem) — potvrzení „ruční
    úpravy konceptu se ztratí“; doklad si drží id (D24);
  - *Obnovit* (`dropdown` zastavených období) — vazbu na smazaný doklad
    zruší, období vrátí do `planned` a hned vystaví.
- **Přegenerovat v místě** (Q2): `DocumentApplier` dostane volbu
  `applyOptions.replaceConcept: <docId>` — cílový doklad musí být
  Koncept stejného typu; v jedné transakci nahradí hlavičku a řádky
  z kanonického payloadu a zachová id, přílohy a autora. Validace
  a odvození jako u nového dokladu. Je to **obecný mechanismus**, ne
  specialita zakázek: generování a přegenerování dokladů v dalších
  situacích půjde stejnou cestou (výměnný formát → applier), proto patří
  do `docs/exchange-format.md` jako běžná volba apply, ne do modulu
  zakázek.
- **Zásada do dokumentace** (Q1, Q2): do `docs/exchange-format.md`
  (úvod) a `docs/document-system.md` zapsat, že doklady, které Shipard
  generuje sám (periodická fakturace, později faktura ze zakázky, faktura
  ze zálohy apod.), vznikají i se přegenerovávají **výhradně přes
  výměnný formát a `DocumentApplier`** — žádné přímé skládání řádků
  a hlavičky mimo applier. Totéž jedním řádkem do konvencí v `CLAUDE.md`.
  Zapsat i **známé výjimky** (#112): zaúčtování podaného přiznání DPH
  (`VatReturnAccountingService`) a účetní doklady majetku
  (`AssetPostingDocuments`) dnes vznikají přes `TableGateway`;
  sjednotí je samostatný task podle #112. Tenhle task je nemění.

## 5. Přispěvatelé obsahu (D7, D10)

- Rozhraní `InvoiceContributor` (namespace modulu zakázek): `id()`,
  `contribute(kontext)` → výsledek **Ready** (doplněné / upravené řádky
  přispěvatele), **Waiting** (důvod) nebo **Failed** (zpráva). Kontext:
  zakázka, období, její řádky s tímto přispěvatelem, rozpracovaný
  kanonický doklad.
- Registrace v `module.jsonc` klíčem `workOrderInvoiceContributors`
  (`id`, `class`, názvy) — parsování a kompilace po vzoru
  `documentLockProviders`.
- Builder volá přispěvatele jen pro řádky s `contributor`. Úroveň
  Koncept: doklad vznikne hned (řádky přispěvatele s množstvím 0),
  období přejde do `waiting`; další běhy volají přispěvatele znovu a po
  Ready koncept přegenerují — **jen když ho nikdo ručně neupravil**
  (`content_hash` období = otisk obsahu při vzniku); jinak upozornění
  „podklady jsou k dispozici, koncept byl ručně upraven — Přegenerovat“.
- Lhůta čekání (nastavení `economy.workOrders.contributorWaitDays`,
  výchozí 10 dní) → upozornění „období čeká na podklady“.
- Testy s fake přispěvatelem; v produkci žádný registrovaný.

## 6. Upozornění (feed)

- `economy.workOrders.invoices_to_review` (info) — **jeden** souhrnný
  nález „N konceptů z periodické fakturace ke kontrole“ (ne karta na
  doklad — `689089` má ≈95 faktur měsíčně); akce otevře viewer faktur
  s výběrem konceptů ze zdroje Periodická fakturace (chybí-li filtr podle
  `source_kind`, doplnit).
- `economy.workOrders.period_failed` (warning) — per zakázka: období se
  nepodařilo vystavit (zpráva), nebo nemá platný řádek.
- `economy.workOrders.catchup_blocked` (warning) — per zakázka: pojistka
  dohánění (Q4).
- `economy.workOrders.period_waiting` (warning) — per zakázka: čekání na
  podklady déle než lhůta; „podklady jsou, koncept upravený“.

## Uživatelská dokumentace

- `help/zakazky/periodicka-fakturace.md` — předpis (druh vs. zakázka,
  prázdné = z druhu), řádky a platnost (změna ceny k datu), `{období}`,
  pevný VS, kdy faktura vznikne (počátek / konec období, 03:17),
  koncepty ke kontrole, smazání = zastavení, Přegenerovat, Obnovit,
  Vystavit dlužná období.
- `help/zakazky/` ostatní stránky — odkazy; `help/co-dnes-nejde.md`
  (automatické odeslání zatím ne).
- `python3 scripts/help-index.py`.

## Testy

- Období: kalendářní hranice všech periodicit, `inv_from` uprostřed
  období, `start` / `end`, `date_end`, přestupný rok, pojistka dohánění.
- Builder: dědičnost druh → zakázka, řádky k DUZP, `{období}` ve čtyřech
  jazycích, VS, dimenze, zdroj, `invpo`.
- Běh: idempotence (druhý běh nic), zakázka V opravě a dohánění, chyba
  jedné zakázky, atomicita (výjimka applieru → období bez `doc`),
  zastaveno odvozené ze stavu dokladu, `--dry-run` nic nezapíše.
- Applier `replaceConcept`: id zachováno, řádky nahrazeny, odmítnutí
  potvrzeného dokladu a jiného typu.
- Přispěvatelé: Ready / Waiting / Failed, `content_hash` guard, lhůta.
- Upozornění: souhrnný nález, per-zakázka nálezy.
- `vendor/bin/phpunit --filter 'WorkOrder|DocumentApplier|Cron'`, pak
  celá sada; frontend `npm run build && npm run check:i18n`.

## Task breakdown

1. **Předpis** — sloupce druhu a hlavičky, cfgItem periodicit, tabulka
   řádků a formuláře, efektivní hodnoty + testy.
2. **Období a builder** — tabulka období, výpočet období, builder
   kanonického dokladu, `{období}`, zdroj `workOrder` + testy.
3. **Běh** — služba, atomicita, pojistka dohánění, CLI, cron + testy;
   `docs/cli.md`.
4. **Fakturace na zakázce** — záložka, akce, applier `replaceConcept`
   + testy; `docs/exchange-format.md`.
5. **Přispěvatelé a upozornění** — rozhraní, registrace, `waiting`,
   `content_hash`, nastavení lhůty, čtyři alert checky + testy.
6. **Dokumentace a stav** — help, `docs/work-orders.md` (§5.5 skutečnost,
   §7), `CLAUDE.md`, Stav tasku + `python3 scripts/tasks-index.py`.

## Hotovo když

Na ukázkovém zdroji (`4l3j-z0bz-kz39-echj`, režim volný):

- Druh periodický (FV, řada, splatnost 14 dní, fakturace na počátku),
  zakázka měsíční od 1. 8. 2026 se zákazníkem, střediskem, pevným VS
  a dvěma řádky, z nichž jeden má od 1. 10. novou cenu.
- `work-orders-invoice-run --date=2026-10-08 --dry-run` vypíše srpen,
  září a říjen a nic nezapíše; bez `--dry-run` vzniknou tři koncepty FV:
  vystavení = DUZP = 1. den měsíce, období, text s měsícem, VS, zakázka
  a středisko na hlavičce, řádky platné k DUZP (říjen s novou cenou).
  Druhý běh nic nevytvoří.
- Feed ukazuje jednu kartu „3 koncepty z periodické fakturace ke
  kontrole“.
- Smazaný koncept září: běh ho znovu nevystaví, Fakturace ukazuje
  zastaveno; Obnovit vystaví nový koncept.
- Změna ceny a Přegenerovat u října: stejné id dokladu, nové řádky.
- Zakázka V opravě při běhu 1. 11. se přeskočí; po potvrzení se
  listopad dovystaví.
- Zakázka s ukončením 31. 10. listopad nevystaví.
- Čtvrtletní zakázka s fakturací na konci: DUZP = poslední den
  čtvrtletí, koncept vznikne až po něm. Roční zakázka se zálohovou
  fakturou vystaví `invpo`.
- Zakázka s `inv_from` o rok zpět: běh nevystaví nic a ohlásí pojistku
  dohánění; *Vystavit dlužná období* vystaví všechna.
- Celá sada PHPUnit zelená.

## Rozhodnutí k designu (potvrzená)

- ✓ **D2–D7, D10–D12, D24** (#110) — předpis, evidence období, pevné
  datum vystavení, přispěvatelé, VS, `{období}`, Přegenerovat a Obnovit.
- ✓ **Q1 — Doklad vzniká přes výměnný formát** (`DocumentApplier`,
  stejná cesta jako import) — **jednotný postup** pro všechny situace,
  kdy Shipard doklady generuje; odvození DPH, řada, dimenze a validace
  bez druhé implementace.
- ✓ **Q2 — Přegenerovat v místě** přes obecnou volbu applieru
  `replaceConcept` — jednotný postup i pro přegenerování (D24: doklad si
  drží id).
- ✓ **Q3 — Období kalendářní**, první = obsahuje „fakturovat od“, bez
  poměrného krácení; období přes datum ukončení se vystaví celé, období
  začínající po něm ne.
- ✓ **Q4 — Pojistka dohánění:** víc než 3 splatná období jedné zakázky
  najednou běh nevystaví, ohlásí upozornění; vystaví je akce *Vystavit
  dlužná období*.
- ✓ **Q5 — Způsob úhrady a vlastní bankovní účet** jako výchozí hodnoty
  druhu s přepisem na zakázce; prázdný účet = výchozí účet dokladu.
- ✓ **Q6 — `{období}`:** „říjen 2026“, „3. čtvrtletí 2026“,
  „2. pololetí 2026“, „2026“ v jazyce dokumentu podle zákazníka; prázdný
  text dokladu = „{název zakázky} {období}“.
- ✓ **Q7 — Fáze 2 vytváří vždy Koncept**; nastavení úrovně stavu (D4)
  přijde s fází 3.
