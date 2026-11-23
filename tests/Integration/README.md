# Integrační testy

Integrační testy procházejí proti reálné databázi a file storage dané
data-source. Nespouštějí se automaticky s `vendor/bin/phpunit` — je potřeba
explicitně vybrat testsuite:

```bash
SHIPARD_INTEGRATION_DS_PATH=/opt/shipard/data-sources/abcd-efgh-ijkl-mnop \
  vendor/bin/phpunit --testsuite Integration
```

## Předpoklady

1. **Dev DS** s aplikovaným aktuálním schématem:

   ```bash
   cd /opt/shipard/data-sources/<id> && bin/shpd-ds ds-upgrade
   ```

2. **`_mail_router` uživatel + default schránka** vytvořené (provisioning běží
   automaticky v `ds-upgrade` od Fáze 2a).

3. **Prostředí promazané** od předchozích běhů — testy si po sobě uklízí, ale
   při pádu zůstávají orphan data. V takovém případě:

   ```sql
   DELETE FROM core_mail_incoming_idempotency;
   DELETE FROM core_attachments_files WHERE table_id = 303;
   DELETE FROM core_mail_incoming_messages WHERE subject LIKE 'IT:%';
   ```

## Testy

- `Dataset/DatasetRoundTripTest.php` — datová sada (`docs/datasets.md`):
  syntetická sada s prefixem `IT-DS` → seed v merge režimu (bez resetu) →
  export vložených záznamů → porovnání + invarianty (zaúčtování, lineage,
  `att:` remapa, přílohy). Uklízí podle přirozených klíčů; přeskočí se bez
  aktivní řady `invni` nebo bez vlastní firmy.

- `Dataset/DatasetProvisionSeedTest.php` — provisioning seedu (#103 D22/D23):
  sada s daty v roce 2001 a jedním kódem nabídky osnovy → `FiscalYearsSeeder`
  založí rok 2001 (14 měsíců), `AccountingItemsSeeder` položku; druhý běh
  idempotentní. Uklízí podle `date_begin` a kódu; přeskočí se, když rok 2001
  existuje nebo už na DS jsou všechny kódy nabídky.

- `Exchange/DocumentRoundTripMatrixTest.php` — pojistka ukládání dokladů
  (#166 etapa 1, `tasks/doc-saving-safety-net.md`): hlídá invariant „co
  uloží `DocDocument`, to výměnný formát přenese beze změny“. Doklad A
  vznikne přes `TableGateway` cestou formuláře (Koncept → V pořádku) →
  `DocumentExporter` → v exportu jen jiné číslo se `sequenceNumber: null` →
  `DocumentApplier::apply` → doklad B → porovnání **všech** sloupců hlavičky,
  řádků a rekapitulace podle definic tabulek. Vyloučené sloupce (identita,
  audit, původ) jsou v `RoundTrip/RoundTripColumns::EXCLUDED` s důvodem —
  další se nepřidávají bez schválení.

  Případy jsou v `RoundTrip/RoundTripCases` s očekáváním `holds`, nebo
  `gap` = **známá mezera** s přesným rozdílem (kód chyby apply, nebo seznam
  `sloupec → [A, B]`). Jak číst selhání:

  - „mezera `<id>` je zavřená — přepni na `holds`“ — rozdíl zmizel; přepni
    případ na `RoundTripCase::holds()` a odškrtni mezeru v tasku;
  - „mezera `<id>` se změnila“ — rozdíl je jiný než zapsaný, výpis nese obě
    verze; buď regrese, nebo záměrná změna (pak přepiš očekávání podle běhu);
  - selhání případu `holds` — formát přestal doklad přenášet. Applier ani
    export se neopravují výjimkou v testu; nový rozdíl je nález.

  Fixtury (partneři, položky, středisko) mají prefix `IT-RT`, kódy DPH
  a sazby se berou z konfigurace zdroje. Test uklízí podle prefixu před
  během i po něm a vrací čítače řad; běží jen nad zdrojem s třídou dat
  `free`, vlastní firmou a registrací DPH, jednotlivé případy se přeskočí
  bez řady typu, pokladny nebo dimenze.

  Po zavedení režimu pokyn a výslovného módu zaokrouhlení (#166 etapa 3)
  matice žádnou mezeru nemá — nová mezera je nález k nahlášení.

- `Exchange/DocumentInstructionPayloadTest.php` — případy „payload →
  uložený doklad“ pro režim pokyn (#166 D24,
  `tasks/doc-apply-instruction-mode.md` U8): tvary, které export nevyrobí,
  a matice je proto nepokryje — doklad Bez DPH s řádky `vat` bez kódu,
  tuzemské místo plnění u dodavatele s DIČ jiného státu, řádek bez kódu
  podle cílového stavu (422 `validation_failed` i týž nález v náhledu),
  opravný doklad bez důvodu v importu, převzatá rekapitulace s kódy a bez
  nich, neznámý kód, ceny s daní při `fromBase` v pokynu a v návrhu,
  `importNumber` v návrhu; výchozí hodnoty pokynu (#166 D40–D50:
  `_resolve.defaults`, hodnota z payloadu vyhrává, výslovné `null`,
  importní mód, jeden nález na pole). Payload vzniká exportem konceptu
  pořízeného cestou formuláře a úpravou zkoumaného pole, u výchozích hodnot
  ručně (minimální pokyn). Prostředí, fixtury a úklid sdílí s maticí
  (`RoundTrip/RoundTripEnvironment`).
- `Exchange/DocumentFormInstructionInvariantTest.php` — invariant
  „minimální pokyn = doklad z formuláře bez zásahu uživatele“ (#166 U10):
  doklad cestou formuláře přes skutečný `FormController`
  (`RoundTrip/FormPath` — otevření nového záznamu, výběr partnera
  a položky, uložení, potvrzení) proti pokynu `{docType, partner, rows:
  [{item}]}`, shoda ve všech porovnávaných sloupcích; faktura vydaná
  a přijatá, pokladní a účetní doklad. Selhání = výchozí hodnota žije mimo
  `Defaults\DocHeadDefaults` / `DocRowDefaults`.

- `Mail/MailEndpointTest.php` — procedurální pokrytí `MailController::receiveIncoming`
  s reálnou DB + file storage:
  - happy path (raw_source + 2 přílohy)
  - idempotent replay
  - neznámá schránka
  - chybějící povinná pole (422)
  - default mailbox fallback
  - neplatný token / session → 401/403

Testy volají controller přímo (nejsou skutečně HTTP), takže nevyžadují běžící
server. Middleware vrstva (auth, rate limit) je mimo scope — pro ně existují
samostatné unit testy.

## Přidání dalšího integračního testu

1. Nová třída v `tests/Integration/`, rozšiřuje `IntegrationTestCase`.
2. Použij `$this->db` a `$this->dsPath` — jsou nastavené v `setUp`.
3. V `setUp` / `tearDown` si ukliď po sobě — v případě pádu stačí výše uvedené
   DELETE statementy.
