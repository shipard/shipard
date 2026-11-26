# Evidence tržeb — fáze 2: tržby a pokusy, napojení na doklady, opakování cronem (#171)

**Stav:** naplánováno — rozhodnutí #171 D4–D8, D13, D18–D25 a upřesnění U5–U12 (schválená 2026-11-26), připraveno k implementaci

> Task pro Claude Code (6 commitů). Design: issue #171 (EET 2.0), komentář
> s D18–D25. Staví na fázi 1 (`tasks/sales-registration-phase1.md` —
> přečti hlavně implementační poznámky) a na bodech rozšíření z #172.
> Fáze 3 = Kontaktní platba (D8b, D8c), záložka a štítek na dokladu —
> **tady ne**. Tady vzniká první skutečná tržba z dokladu.

## Kontext

Fáze 1 umí jednu zprávu poslat: `SalesRegistrationSender::send($registration,
SalesMessage)` → `SendResult` (stav `confirmed` / `verified` / `rejected` /
`retryLater` / `unknown` / `dropped`, problém, kódy, varování, XML tam
i zpět, `transactionId`, doba, prostředí, testovací identita). Nic
neukládá ani neopakuje. UUID zprávy dává odesílač (`SalesMessage::$uuid`),
aby šel pokus zapsat před odesláním.

Fáze 2 přidá **žurnál**: tržba (závazek s pevnými daty) a pokus (jedno
odeslání), vznik tržeb z přechodu dokladu, okamžité odeslání, opakování
cronem, záchrannou síť, agendu Evidence tržeb a upozornění.

**Metodika finanční správy** „Popis položek datové zprávy s příklady jejich
vyplnění“, verze 1.0 z 5. 10. 2026 (eet.gov.cz → Pro vývojáře → Dokumenty
ke stažení), ze které vychází D18–D20: částka vždy v Kč (cizí měna podle
obecných pravidel přepočtu pro základ daně), skutečně přijatá částka
včetně daní, hotovost zaokrouhlená na koruny zaokrouhleně, karta na
haléře; čas tržby = přijetí platby (nebo dřívější platební příkaz),
u vratky čas vrácení peněz, opakování drží původní čas; storno má vlastní
pořadové číslo; `prvni_zaslani` je `true` jen u prvního pokusu, který
zprávu skutečně odeslal; vrácení platby, která do evidence nešla, se
neeviduje.

## Rozhodnutí (#171)

- **D4** — pokladní zařízení = číselná řada: `id_pokl` = kód řady
  (`docs_core_number_series.code`), `porad_cis` podle D19. Evidenční
  jednotka na řadě (D21), jinak výchozí jednotka registrace.
- **D5** — dvě tabulky po vzoru fronty pošty: **tržba** (doklad, jednotka,
  `id_pokl`, `porad_cis`, `dat_trzby`, částka, stav, kód potvrzení) a
  **pokus** (UUID, čas, podepsané XML tam i zpět, `transactionId`, výsledek,
  varování). Data tržby se po vzniku nemění; opakování = nový pokus s novým
  UUID a `prvni_zaslani = false`.
- **D6** — rozdílové zasílání: při přechodu dokladu do **V pořádku** (40)
  nebo **Storno** (30) se spočítá rozdíl mezi částkou, která do evidence
  spadá (v Storno = 0), a součtem **všech** dosavadních tržeb dokladu
  (bez ohledu na jejich stav). Nenulový rozdíl = nová tržba. Storno =
  záporná tržba, oprava = rozdíl.
- **D7** — handler `stateChanged` tržbu vytvoří a hned zkusí odeslat
  (limity knihovny 2,5 s požadavek / 2 s spojení). Neúspěch převezme cron
  (slot `minute`) s backoffem; po 48 h se nepřestává. Záchranná síť:
  periodická kontrola dokladů, které do evidence spadají a tržbu nemají
  nebo ji mají v jiné výši — `stateChanged` běží po commitu a výjimky
  polyká; rozdílový výpočet je idempotentní, takže chybějící tržbu i dorovná.
- **D8a** — kdy doklad do evidence spadá automaticky:
  - prodejka (`cashreg`) hotově (0) nebo kartou (2) — vždy;
  - faktura vydaná (`invno`) hotově nebo kartou — vždy;
  - pokladní doklad (`cash`) příjem (`cash_dir` 1) — řádky s operací
    `sale.*`, `payment.receivable`, `advance.received`; mimo evidenci
    `transfer.in` a `acc.entry` (a vše ostatní). Částka = součet těchto řádků.
  - způsob úhrady Platební bránou (5) do evidence nespadá (D15).
- **D8d** — doklad vystavený před začátkem platnosti registrace do evidence
  nevstoupí ani pozdější opravou nebo stornem.
- **D8e** — importní mód evidenci nespouští; doklad založený pokynem přes
  API rovnou V pořádku ji spustí (je to skutečná tržba).
- **D13** — upozornění: nepotvrzená tržba (> 1 h varování, > 48 h chyba).
  Záložka na dokladu a štítek ve vieweru dokladů = fáze 3.
- **D18** — částka tržby = `total_amount_dom` dokladu (už obsahuje
  zaokrouhlení — `DocDocument::applyTotalRounding`); u pokladního dokladu
  součet vyhovujících řádků v domácí měně. Cizí měna: kurz dokladu.
  Registrace typu `cz.eet2` vyžaduje domácí měnu zdroje dat CZK.
- **D19** — `porad_cis`: první tržba dokladu = číslo dokladu (`doc_number`),
  každá další tržba téhož dokladu = číslo dokladu + `-2`, `-3`, … Maska
  `serialNumber` typu (25 znaků). Storno se posílá vždy, i při vrácení
  peněz bezkontaktně.
- **D20** — `dat_trzby` = `doc_state_changed_at` daného přechodu. Záchranná
  síť bere tentýž čas z dokladu, ne čas, kdy chybějící tržbu našla.
  Opakování drží původní čas (je v tržbě).
- **D21** — evidenční jednotka na číselné řadě: extension sloupec doplňku na
  `docs_core_number_series` (mechanismus `extensions/`, vzor
  `modules/economy/accounting/extensions/docs_core_heads.jsonc`), ve
  formuláři řady záložka přes `formTabs`. Kód řady (`varchar(40)`) musí
  při přiřazení jednotky projít maskou `cashRegisterId` typu (20 znaků,
  omezená sada) — chyba ve formuláři, ne až při odeslání.
- **D22** — výběr registrace: registrace ve stavu V pořádku, jejíž typ
  odpovídá zemi registrace DPH dokladu (`vat_registration` →
  `economy_codebooks_vat_registrations`; bez ní domácí země zdroje dat)
  a která platí k `dat_trzby`. Víc kandidátů = tržba nevznikne, upozornění.
- **D23** — pravidla D8a jsou data typu ve `world.cz.salesRegistrationTypes`
  (typ dokladu, směr, způsoby úhrady, u pokladního dokladu operace řádků);
  vyhodnocuje je obecný kód doplňku. Nový způsob úhrady = úprava dat.
- **D24** — jeden odesílatel: tržbu si před odesláním zamkne atomický
  `UPDATE … WHERE id = ? AND state = 'pending'` (vzor `core_mail_outbox`,
  `claimed_at`, obnova po 10 min); handler i cron jdou stejnou cestou.
- **D25** — ve fázi 2 agenda Evidence tržeb (seznam s filtry, detail
  s pokusy) a upozornění na nepotvrzené tržby.

## Upřesnění tohoto tasku (schválená 2026-11-26, zapsaná v #171)

- **U5 — stavy tržby a opakování.**

  ```
  pending ──claim──▶ sending ──▶ confirmed   (POK; povinnost splněna)
     ▲                  │   ├──▶ verified    (registrace v ověřovacím režimu; konečný)
     │                  │   ├──▶ dropped     (pojistka serveru; konečný, bez upozornění)
     │                  │   └──▶ failed      (odmítnuto serverem / neplatná zpráva)
     └── backoff ───────┘                       │
         (retryLater, unknown, problém na naší   └─ akce Odeslat znovu → pending
          straně: chybí certifikát, klíč,
          testovací identita, interní chyba)
  ```

  Backoff 1 min → 5 min → 15 min → 1 h, dál po hodině bez konce (D7).
  `sending` starší 10 min se vrací na `pending` (pád procesu). `dropped` je
  konečný záměrně: kopie reálných dat na vývojářském serveru nesmí nic
  poslat a nesmí ani zahltit upozorněními; produkční server bez sekce
  pojistky posílá (`off`). Problémy na naší straně (zpráva vůbec neodešla)
  se opakují, protože je odstraní nastavení (nahrání certifikátu).
- **U6 — jistič.** Handler neposílá hned, když poslední pokus téže
  registrace v posledních 60 s skončil `retryLater` nebo `unknown` — při
  výpadku služby by jinak každé uložení prodejky čekalo 2,5 s. Tržba zůstane
  `pending`, převezme ji cron.
- **U7 — žádný dvojí rozdíl.** Unikátní index (doklad, pořadí tržby
  dokladu); výpočet rozdílu a zápis tržby v jedné transakci se zámkem
  dokladu (`SELECT … FOR UPDATE` nad tržbami dokladu nebo `GET_LOCK`).
  Handler a záchranná síť ve stejnou chvíli tak nevytvoří dvě tržby.
- **U8 — začátek povinnosti.** D8d se řídí `valid_from` registrace.
  Registrace v **ostrém** režimu nesmí začínat před `validFrom` typu
  (`cz.eet2`: 2027-01-01) — chyba ve formuláři registrace. Playground a
  ověřovací režim smí začít dřív, takže evidenci jde vyzkoušet už teď.
- **U9 — doklad, který tržbu mít má, ale nejde ji založit** (chybí jednotka
  na řadě i výchozí jednotka, neplatný kód řady, víc registrací, `porad_cis`
  nebo částka mimo masku typu): tržba nevznikne, upozornění. Po opravě
  nastavení ji dorovná záchranná síť s původním časem (D20).
- **U10 — časová zóna.** `dat_trzby` jde ve zprávě s časovou zónou místa
  tržby. Zjisti, v jaké zóně se ukládá `doc_state_changed_at`; pokud zónu
  místa nic neurčuje, přidej do dat typu `timeZone` (`cz.eet2`:
  `Europe/Prague`).
- **U11 — importní mód v handleru.** Marker `_importNumber` odstraní
  `DocDocument::beforeSave()`, handler ho nevidí; `oldState = 0` mají
  import i doklad z API (D8e je rozlišuje). Handler i záchranná síť
  vynechají doklady se `source_kind` `import.*` (trvalý údaj — záchranná
  síť by jinak dovezený doklad v okně našla) a ověř, že importní cesty
  `source_kind` nastavují vždy. Kdyby to nešlo spolehlivě, dej handlerům
  v jádře obecnou informaci o importním módu (nejmenší čistá změna,
  popsat v poznámkách).
- **U12 — agenda a akce.** Viewer tržeb v sekci sidebaru Prodej
  (`navSection: "sales"`), detail tržby s pokusy a odkazem na doklad. Akce
  záznamu **Odeslat znovu** nad tržbou ve stavu `failed` nebo `pending`
  (→ `pending`, `next_attempt` = teď, hned pokus); oprávnění jako úprava
  dokladů, ne jen správce. Doklad s tržbou nejde smazat (`beforeDelete`).

## Před implementací přečti

- issue #171 (celé, včetně komentářů), `tasks/sales-registration-phase1.md`
  (implementační poznámky), specifikace
  `modules/economy/salesRegistration/docs/sales-registration.md`
- `docs/modules.md` kap. 5: `extensions`, `formTabs`, `documentEventHandlers`
  (sémantika `beforeSave` / `stateChanged` / `beforeDelete`), `commands`
  a `cronJobs`, `recordActions`, `alertChecks`, `navSection` na vieweru
- `modules/core/mail/tables/core_mail_outbox.md` a
  `src/Core/Mail/MailOutboxService.php` — vzor fronty, claimu, obnovy
  a backoffu; `core_mail_outbox_log` jako vzor pokusů
- `modules/docs/core/tables/docs_core_heads.md` (`doc_state_changed_at`,
  `totals`, `payment`), `docs_core_rows.jsonc` (`operation`, sloupce
  v domácí měně), `config/docTypes.jsonc`, `paymentMethods.jsonc`,
  `cashDirections.jsonc`, `rowOperations.jsonc`, `sourceKinds.jsonc`
- `modules/economy/accounting/extensions/docs_core_heads.jsonc` a
  `DocsHeadsEventHandler` — vzor extension sloupce a handleru na
  `docs_core_heads`
- `modules/world/cz/config/salesRegistrationTypes.jsonc`,
  `SalesRegistrationTypes` / `SalesRegistrationType` v doplňku
- `src/Core/Server/CronProvisioner.php` (sloty), `docs/alerts.md`

## 1. Data (D4, D5, D21, D23, U8, U10)

- Doplněk dostane závislost `docs.core` (komentář v `module.jsonc` to
  ohlašuje).
- Tabulka **tržeb** `economy_sales_registration_sales` (název ověř proti
  konvenci; id nové tabulky přes `next-table-id`):
  - pevná data: `doc_head` (→ `docs_core_heads`), `doc_sequence` (pořadí
    tržby dokladu 1, 2, …), `registration`, `unit` (→ jednotky),
    `unit_id` (hodnota `id_jednotky` v době vzniku), `cash_register_id`,
    `serial_number`, `sold_at`, `amount` (`numeric(15,2)`, se znaménkem),
    `currency`, `created`;
  - stav: `state` (U5), `attempt_count`, `next_attempt`, `claimed_at`,
    `confirmation_code`, `confirmed_at`, `environment`, `test` (potvrzení
    z playgroundu nebo testovací identitou), `last_problem`, `last_error`;
  - indexy: unikátní (`doc_head`, `doc_sequence`) (U7), (`state`,
    `next_attempt`), `registration`, `sold_at`.
- Tabulka **pokusů** `economy_sales_registration_attempts`: `sale`,
  `uuid`, `started_at`, `first_sending`, `verification`, `environment`,
  `test_identity`, `status`, `sent`, `problem`, `error_code`, `error_text`,
  `warnings` (JSON), `transaction_id`, `request_body`, `response_body`
  (`mediumtext`), `duration_ms`. Pokus se zapíše **před** odesláním
  (UUID, `started_at`, stav `sending`) a doplní po něm.
- `first_sending` = tržba ještě nemá pokus se `sent = true`. Pokusy, které
  nic neodeslaly (pojistka, problém na naší straně), se do toho nepočítají.
- Extension sloupec `sales_registration_unit` na `docs_core_number_series`
  (→ jednotky, nullable) + `formTabs` záložka v formuláři řady (výběr
  jednotky z registrací V pořádku). Kontrola D21 v `beforeSave` handleru
  řady — při přiřazení jednotky i při změně kódu řady s jednotkou; řada
  s jednotkou musí mít kód. Chyba se musí ukázat ve formuláři (zjisti, jak
  handler vrací validační chybu místo výjimky → 500; když to nejde, vlastní
  validace přes dostupný bod a poznámka).
- Data typu `cz.eet2` (D23): pravidla D8a, případně `timeZone` (U10).
  Tvar navrhni tak, aby šel číst obecně, např.:

  ```jsonc
  "rules": [
      {"docTypes": ["cashreg", "invno"], "paymentMethods": [0, 2]},
      {"docTypes": ["cash"], "cashDir": 1,
       "rowOperations": ["sale.*", "payment.receivable", "advance.received"]}
  ]
  ```

  Komentář v souboru vysvětlí, proč brána (5) chybí (D15) a proč
  `transfer.in` / `acc.entry` (D8a).
- Registrace: `cz.eet2` vyžaduje domácí měnu zdroje dat CZK (D18) a ostrý
  režim nezačíná před `validFrom` typu (U8) — obojí chyba ve formuláři.

## 2. Vyhodnocení dokladu (D8a, D8d, D8e, D18, D19, D22, U9, U11)

Čistá služba bez sítě a bez zápisu, dobře pokrytá testy:

- vstup: hlavička + řádky dokladu, čas tržby; výstup: „nespadá“ / „spadá,
  částka X, registrace, jednotka, `id_pokl`“ / „spadá, ale nejde založit
  (důvod)“;
- pravidla z dat typu (D23); `sale.*` = každá operace začínající `sale.`;
- částka (D18): pro pravidlo nad hlavičkou `total_amount_dom`; pro pravidlo
  nad řádky součet vyhovujících řádků v domácí měně (zjisti, který sloupec
  nese celkovou částku řádku v domácí měně i u řádků bez DPH); když
  vyhovují všechny řádky s nenulovou částkou, `total_amount_dom` (zahrne
  zaokrouhlení);
- stav Storno (30) → částka 0; Koncept a V opravě se nevyhodnocují;
- D8d: `issue_date` < `valid_from` registrace → nespadá nikdy;
- D22: registrace podle země registrace DPH dokladu a platnosti k času
  tržby; víc kandidátů = „nejde založit“;
- jednotka: z řady (D21), jinak výchozí jednotka registrace; `id_pokl` =
  kód řady; obojí přes masky typu;
- D19: `porad_cis` pro pořadí `n` (1 = číslo dokladu, jinak `-n`), maska
  typu;
- U11: `source_kind` `import.*` → nespadá;
- místo, kam fáze 3 přidá Kontaktní platbu (D8b) — jen ho nezavřít.

## 3. Tržby z dokladů (D6, D7, D20, U7)

- `documentEventHandlers` na `docs_core_heads`: `stateChanged` (nový stav
  40 nebo 30) a `beforeDelete` (doklad s tržbou nejde smazat).
- Rozdíl (D6) v transakci se zámkem (U7): částka podle vyhodnocení − součet
  všech tržeb dokladu; nenulový → nová tržba `pending` s `sold_at` =
  `doc_state_changed_at` (D20), pořadí `n` = počet tržeb + 1. Duplicita
  na unikátním indexu = přepočítat (už ji vytvořil souběžný proces).
- Po zápisu handler hned zkusí odeslat (krok 4), pokud nebrání jistič (U6).
- Výjimky handler zaloguje (uložení dokladu nesmí selhat) — chybějící
  tržbu dorovná záchranná síť.

## 4. Odeslání a opakování (D5, D7, D24, U5, U6)

- Služba fronty (jediná cesta pro handler, cron i akci): claim (D24),
  pokus před odesláním, `SalesRegistrationSender::send()` s daty tržby
  (opakování = nové UUID, `firstSending` podle kroku 1, `soldAt`
  z tržby), zápis výsledku do pokusu a stavu tržby podle U5, backoff.
- Obnova `sending` > 10 min → `pending` (bez zvýšení `attempt_count`, jako
  pošta).
- Příkazy doplňku (`commands`) a `cronJobs`:
  - `sales-registration-run` — slot `minute`: due tržby (`pending`,
    `next_attempt` ≤ teď) + obnova; limit počtu na běh;
  - `sales-registration-reconcile` — slot `five-minutes`: záchranná síť
    (D7) nad doklady ve stavu 40 a 30 se `doc_state_changed_at` za
    posledních 7 dní (index `idx_doc_state_changed`), stejný výpočet
    rozdílu jako handler; `--dry-run` vypíše, co by vzniklo, bez zápisu.
- Log jako ve fázi 1: id, stavy, kódy, doby — bez těl zpráv a klíčů.

## 5. Agenda, akce, upozornění (D13, D25, U9, U12)

- Viewer **Evidence tržeb** (`navSection: "sales"`): doklad, čas tržby,
  částka, `porad_cis`, jednotka, stav, kód potvrzení (zkrácený), štítek
  playgroundu / testu; filtry stav, registrace, jednotka, období. Detail:
  data tržby, odkaz na doklad, pokusy (čas, prostředí, výsledek, kódy,
  varování, `transactionId`, doba). Celá XML v detailu nejsou (zůstávají
  v tabulce pokusů pro dohledání).
- Akce záznamu **Odeslat znovu** (U12).
- Upozornění (id malými písmeny, vzor fáze 1):
  - `economy.salesregistration.sale_unconfirmed` — warning, tržba
    `pending` / `sending` / `failed` starší než 1 h;
  - `economy.salesregistration.sale_overdue` — error, starší než 48 h;
  - `economy.salesregistration.sale_failed` — error, tržba `failed`;
  - `economy.salesregistration.doc_unregistrable` — error, doklad, který
    do evidence spadá a tržbu založit nejde (U9; stejné vyhodnocení jako
    záchranná síť, okno 7 dní).
  `dropped` a `verified` upozornění nedávají (U5).

## 6. Dokumentace

- Specifikace u modulu: nové kapitoly (data, vyhodnocení, rozdíly, fronta
  a stavy, záchranná síť, agenda, upozornění); kapitola „Co není ověřené“.
- Nápověda `help/evidence-trzeb/`: nová stránka, kdy doklad do evidence
  spadá, jak se posílá a opakuje, co znamenají stavy a upozornění, jednotka
  na řadě, storno a oprava dokladu; metodika D15 (Kartou × Platební
  bránou).
- README modulu, roadmapa a features, `**Stav:**` tohoto tasku.

## Hotovo když

- [ ] prodejka hotově a kartou, faktura vydaná hotově a kartou a pokladní
      doklad příjem s vyhovujícími řádky dají po přechodu do V pořádku
      tržbu se správnou částkou (včetně zaokrouhlení a cizí měny);
      převodem, bránou, pokladní doklad výdej a řádky `transfer.in` /
      `acc.entry` tržbu nedají
- [ ] storno dá zápornou tržbu `-2`, oprava rozdíl, oprava beze změny
      částky nic; součet tržeb dokladu = částka, která do evidence spadá
- [ ] doklad vystavený před `valid_from` registrace nic nedá ani stornem;
      import (`source_kind` `import.*`) nic nedá, doklad z API V pořádku ano
- [ ] výběr registrace podle země a platnosti; víc kandidátů = upozornění
- [ ] jednotka na řadě se ukládá ze záložky formuláře řady; neplatný kód
      řady dá chybu ve formuláři
- [ ] handler a záchranná síť souběžně nevytvoří dvě tržby (test)
- [ ] stavy a backoff podle U5 pokryté testy nad výsledky enginu; jistič
      (U6); obnova `sending`
- [ ] záchranná síť dorovná chybějící tržbu s původním časem
- [ ] agenda, detail s pokusy, akce Odeslat znovu, čtyři upozornění
- [ ] na volném zdroji dat s pojistkou `playground` (předanou kontextu jako
      ve fázi 1) a registrací v režimu playground: prodejka → `confirmed`
      s kódem `-ff`, storno → druhá tržba `confirmed`; bez sekce pojistky
      → `dropped`; výsledek v poznámkách
- [ ] dokumentace, nápověda, README, roadmapa a features
- [ ] celá sada PHPUnit lokálně zelená

## Implementační poznámky

(doplní Claude Code)

### Zbývá

- Ruční proklik v prohlížeči s pojistkou `playground` v `server.json`
  (člověk): agenda, detail, Odeslat znovu, záložka řady.
- Nasazení: `ds-upgrade` zdrojů dat s doplňkem; pojistka na testovacím
  serveru nastavuje člověk.
