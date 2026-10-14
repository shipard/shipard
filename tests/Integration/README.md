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
