# Task: Doklady — import-mód čísla (Fáze 05b)

## Kontext

Fáze 05 importuje faktury, ale čísla dokladů a counter číselných řad nejsou
ve stavu vhodném pro **ostrý provoz po migraci** (nové doklady musí navázat
na importovaná čísla). Aktuální `DocsRunner`:

- **Vydané faktury (invno):** vkládá jako koncept (10), v `afterApplied`
  povýší 10→20 (to spustí `assignDocumentNumber` → vygeneruje číslo z counteru
  a **posune counter podle pořadí importu**) + doplní vlastní `bank_account`,
  pak PATCHne `doc_number` zpět na původní. Číslo je sice správné, ale
  **counter je rozházený**.
- **Přijaté faktury (invni):** vkládá rovnou na 20, ale `assignDocumentNumber`
  se nespustí (oldState=newState=20) → `doc_number` zůstane prázdný →
  placeholder `!{id}`.

Nový Shipard dostává **import-mód** (samostatný task
`nov_shipard:tasks/docs-import-number-mode.md`): klient pošle
`applyOptions.importNumber = {docNumber, sequenceNumber}`, `DocDocument`
zapíše číslo+sekvenci verbatim, přeskočí `assignDocumentNumber`/placeholder a
synchronizuje counter přes `GREATEST`. Pro vlastní bank účet vydaných faktur
přidává `applyOptions.importOwnBankAccount`.

**Cíl Fáze 05b:** přepsat `DocsRunner` na import-mód:
1. **Parser pořadového čísla** z původního `docNumber` (řízený formulí řady).
2. Posílat `applyOptions.importNumber` (obě směry) a `importOwnBankAccount`
   (invno).
3. **Sjednotit** obě směry faktur na vložení rovnou na cílový stav (20),
   **odstranit** dosavadní `afterApplied` (povýšení 10→20 i PATCH `doc_number`).

**Prerekvizita:** import-mód v novém Shipardu musí být nasazen dřív (jinak
`applyOptions.importNumber` applier ignoruje a chování zůstane staré).

**Osoby (`PersonsRunner`) se NEMĚNÍ** — už posílají `personId` ze starého
`id` (`createPersonId: true`). Staré kódy osob se zachovávají.

## Před implementací přečti

- **`modules/imports/newShipard/libs/runners/DocsRunner.php`** — aktuální
  stav (buildCanonical, afterApplied, resolveOwnBankAccount, finalDocState,
  insertDocState). Tohle přepisujeme.
- **`modules/e10doc/core/tables/heads.php`** — metoda `makeDocNumber`
  (cca řádek 1852). Zdroj pravdy pro strukturu `docNumber`. Tokeny:
  - `%D` = `docIdCode` (z `e10.docs.types.<docType>.docIdCode`)
  - `%r` = mark fiskálního roku (`e10doc_base_fiscalyears.mark`)
  - `%Y` = rok 4-místně, `%y` = 2-místně, `%M` = měsíc 2-místně
  - `%C` = `docKeyId` (z `e10.docs.dbCounters.<docType>.<dbCounter>.docKeyId`,
    default `"1"`)
  - `%B` = cashBox id, `%A` = myBankAccount id, `%W` = warehouse id
    (u faktur se typicky nevyskytují)
  - `%2`–`%5` = počítadlo, zero-padded na 2–5 míst
  - Formule: `cfgItem('e10.options.docNumbers.<docType>')` → fallback
    `cfgItem('e10.docs.types.<docType>.docNumber')` → fallback `%D%r%C%4`.
- **`nov_shipard:tasks/docs-import-number-mode.md`** — kontrakt import-módu
  (co očekává v `applyOptions.importNumber` / `importOwnBankAccount`).

## Co implementovat

### 1. Parser pořadového čísla z `docNumber`

Nová metoda `DocsRunner::parseSequenceNumber(array $oldRow): ?int`.

Strategie — **řízená formulí** (replikuje prefix část `makeDocNumber`):

1. Zjisti formuli řady pro `docType`:
   ```php
   $formula = $this->app()->cfgItem('e10.options.docNumbers.' . $docType, '')
       ?: $this->app()->cfgItem('e10.docs.types.' . $docType . '.docNumber', '')
       ?: '%D%r%C%4';
   ```
2. Najdi counter token (`%2`–`%6`). Když chybí → fallback (krok 6).
3. Rozděl formuli na `prefix | counterToken | suffix`.
4. Vyhodnoť `prefix` a `suffix` nahrazením tokenů konkrétními hodnotami
   daného dokladu (viz `evaluateNumberTokens` níže) → konkrétní stringy.
5. Z `docNumber` odřízni vyhodnocený prefix zleva a suffix zprava; zbytek
   je počítadlo:
   ```php
   $core = (string) $docNumber;
   if ($prefix !== '' && str_starts_with($core, $prefix))
       $core = substr($core, strlen($prefix));
   if ($suffix !== '' && str_ends_with($core, $suffix))
       $core = substr($core, 0, -strlen($suffix));
   // $core by mělo být jen číslice (případně s leading zeros)
   if (ctype_digit($core) && $core !== '')
       return (int) $core;   // intval zahodí leading zeros i případné přetečení šířky
   ```
6. **Fallback** (formula bez counter tokenu, prefix se neshodl, nebo `$core`
   nejsou čisté číslice): vezmi poslední souvislou skupinu číslic z `docNumber`
   přes regex, s warningem:
   ```php
   if (preg_match('/(\d+)(?!.*\d)/', (string) $docNumber, $m))
   {
       $this->warn("doc {$oldRow['ndx']}: sequence parsed via fallback "
           . "(trailing digits) from docNumber '{$docNumber}' → {$m[1]}");
       return (int) $m[1];
   }
   $this->warn("doc {$oldRow['ndx']}: cannot parse sequence from docNumber "
       . "'{$docNumber}', skipping number import");
   return null;
   ```

**`evaluateNumberTokens(string $pattern, array $oldRow): string`** — replikuje
relevantní část `makeDocNumber`:

```php
private function evaluateNumberTokens(string $pattern, array $oldRow): string
{
    $docType = (string) ($oldRow['docType'] ?? '');
    $dateAcc = $oldRow['dateAccounting'] ?? null;
    $dt = $dateAcc instanceof \DateTimeInterface
        ? $dateAcc
        : (is_string($dateAcc) && $dateAcc !== '' ? new \DateTime($dateAcc) : null);

    $docIdCode = (string) $this->app()->cfgItem('e10.docs.types.' . $docType . '.docIdCode', '');

    $dbCounter = (int) ($oldRow['dbCounter'] ?? 0);
    $dbCntrId = (string) $this->app()->cfgItem(
        'e10.docs.dbCounters.' . $docType . '.' . $dbCounter . '.docKeyId', '1');

    $mark = '';
    $fyNdx = (int) ($oldRow['fiscalYear'] ?? 0);
    if ($fyNdx > 0)
    {
        $r = $this->db()->query('SELECT [mark] FROM [e10doc_base_fiscalyears] WHERE [ndx] = %i', $fyNdx)->fetch();
        if ($r !== null)
            $mark = (string) ($r['mark'] ?? '');
    }

    $rep = [
        '%D' => $docIdCode,
        '%r' => $mark,
        '%C' => $dbCntrId,
        '%Y' => $dt ? $dt->format('Y') : '',
        '%y' => $dt ? $dt->format('y') : '',
        '%M' => $dt ? $dt->format('m') : '',
        // %B/%A/%W u faktur typicky nejsou; pokud se objeví, ponecháme je
        // nevyhodnocené (prefix se pak neshodne → fallback). Lze doplnit
        // později, pokud nějaká řada faktur používá %A (bank account id).
    ];
    return strtr($pattern, $rep);
}
```

**Pozn.:** counter token z formule (`%4`) říká jen šířku paddingu — parser ji
nepotřebuje (`intval` zvládne libovolnou šířku i přetečení). Důležité je
oříznout prefix/suffix.

### 2. `buildCanonical` — import-mód

Úpravy v `buildCanonical`:

- **`targetDocState`:** vkládat obě směry rovnou na cílový stav. Odstranit
  `insertDocState()` rozlišení (invno už nejde přes koncept). Pro invno ale
  potřebujeme vlastní bank účet (viz níže) — když není dohledatelný, spadnout
  na koncept 10 + warning.

  ```php
  $oldDocType = (string) ($oldRow['docType'] ?? '');
  $sequence   = $this->parseSequenceNumber($oldRow);
  $docNumber  = $this->emptyToNull($oldRow['docNumber'] ?? null);

  $ownBankId = null;
  $targetState = $this->finalDocState();   // 20 (nebo --target-state=10)
  if ($oldDocType === 'invno' && $targetState >= 20)
  {
      $ownBankId = $this->resolveOwnBankAccount($oldRow);
      if ($ownBankId === null)
      {
          $this->warn("doc {$oldNdx}: own bank account unresolved (myBankAccount); "
              . "importing issued invoice as draft (docState 10)");
          $targetState = 10;
      }
  }
  ```

- **`applyOptions`** — přidat `importNumber` (když máme číslo i sekvenci) a
  `importOwnBankAccount`:

  ```php
  $applyOptions = [
      'targetDocState'        => $targetState,
      'autoCreateMode'        => 'safe',
      'createMissingEntities' => true,
      'rejectOnIssues'        => ['error'],
  ];

  // Import-mód čísla — jen když máme obojí. Bez sekvence (parser selhal na
  // 10/koncept) applier vygeneruje číslo standardně (koncept stejně číslo nemá).
  if ($docNumber !== null && $sequence !== null && $targetState >= 20)
  {
      $applyOptions['importNumber'] = [
          'docNumber'      => $docNumber,
          'sequenceNumber' => $sequence,
      ];
  }
  if ($ownBankId !== null)
      $applyOptions['importOwnBankAccount'] = $ownBankId;
  ```

- **`docNumber` na top-levelu canonical** (→ `partner_doc_number`): nechat
  jak je u **přijatých** faktur (číslo dodavatele uložené ve starém
  `docNumber` je naše evidenční — pozn. starý Shipard nemá zvlášť dodavatelovo
  číslo, takže `partner_doc_number` u přijatých je naše číslo; to je stávající
  chování, neměníme). U **vydaných** faktur je `docNumber` naše číslo a jde
  teď přes `importNumber.docNumber` do `doc_number` — top-level `docNumber`
  (→ partner_doc_number) ponech, applier ho uloží do partner_doc_number jako
  dosud (u vydané faktury to je referenční, neškodí). *Pozn.: pokud chceš u
  vydaných faktur partner_doc_number prázdné, lze top-level `docNumber` u
  invno vynechat — viz Otevřený bod 3.*

### 3. Odstranit `afterApplied`

Celá metoda `afterApplied` (povýšení 10→20 + PATCH `doc_number`) se ruší —
import-mód řeší číslo i counter na straně applieru, a faktury se vkládají
rovnou na cílový stav. Odstranit i pomocné `tryPatch` (pokud se nepoužívá
jinde) a ponechat `resolveOwnBankAccount` (přesunuto do `buildCanonical`).

`NEW_HEADS_TABLE` konstanta zůstane jen pokud ji používá něco dalšího; jinak
odstranit.

### 4. Bez dalších změn

- `loadParty`, `loadRows`, parser bank účtu, VAT code mapping, country/currency
  helpery — beze změny.
- `run()` pre-flight check vlastní firmy — beze změny.

## Hotovo když

1. **`parseSequenceNumber`** vrací pořadí z `docNumber` řízené formulí řady;
   fallback na trailing digits (s warningem) a null (s warningem) když nelze.
2. **`evaluateNumberTokens`** správně vyhodnotí `%D %r %C %Y %y %M` pro daný
   doklad (docIdCode z cfg, mark z fiscalyears, docKeyId z dbCounter, datum z
   dateAccounting).
3. **`buildCanonical`** posílá `applyOptions.importNumber` (obě směry, na
   stavu 20) a `importOwnBankAccount` (invno).
4. **Vydané faktury** se vkládají rovnou na 20 s vlastním účtem; bez
   dohledatelného účtu jako koncept (10) + warning.
5. **`afterApplied` odstraněn** — žádné post-apply povýšení ani PATCH čísla.
6. **Idempotence** zachována (LocalIdMap skip), counter sync na straně
   applieru je idempotentní (GREATEST).
7. **Smoke test** na DS `68908901448295` (po nasazení import-módu v novém
   Shipardu):
   - `docs --from=2024-01-01 --to=2024-12-31 --limit=20 -v`:
     - přijaté i vydané faktury mají **původní** `doc_number` (žádné `!…`
       placeholdery, žádná přečíslovaná z counteru),
     - `sequence_number` odpovídá pořadí z původního čísla,
   - po importu série: counter `docs_core_number_counters` per (řada, rok)
     sedí na nejvyšším importovaném pořadí; **nový doklad** vytvořený v UI
     dostane další číslo v řadě (naváže),
   - druhý běh `docs …` → idempotence (skip), counter beze změny.
8. **Parser ověřen na reálných číslech** — projít log, zkontrolovat, kolik
   dokladů spadlo na fallback / null (signál, že formula evaluace nesedí na
   nějaký formát řady).

## Doporučené pořadí implementace

1. **`evaluateNumberTokens` + `parseSequenceNumber`** — izolované, jednotkově
   ověřitelné (vezmi pár reálných `docNumber` z DS a ověř, že parser vrací
   správné pořadí). Nejdřív bez zapojení do buildCanonical.
2. **`buildCanonical`** — `importNumber`, `importOwnBankAccount`, `targetState`
   logika.
3. **Odstranit `afterApplied`** + úklid (`tryPatch`, `insertDocState`).
4. **Smoke `--limit=5 -v`** na malém vzorku — ověřit čísla + sekvence v UI.
5. **Plný běh** za období + ověření navázání nového dokladu + idempotence.

## Otevřené body / rozhodnutí

### 1. Formáty řad k ověření parseru

Parser je řízený formulí, takže by měl zvládnout libovolný formát z
`makeDocNumber`. Riziko: řada používá token, který `evaluateNumberTokens`
nezná (`%B`/`%A`/`%W`) → prefix se neshodne → fallback na trailing digits.
Při smoke testu sleduj počet fallbacků v logu. Pokud nějaká řada faktur
používá `%A` (bank account id v čísle), doplň jeho vyhodnocení (id z
`e10doc_base_bankaccounts` přes `myBankAccount`).

### 2. Counter sync se děje na straně applieru

`DocsRunner` neřeší counter — pošle `sequenceNumber`, applier udělá
`GREATEST` sync. Díry v původním číslování (smazané doklady) jsou tím
ošetřeny: counter doběhne na skutečné maximum bez ohledu na pořadí/počet
importovaných dokladů.

### 3. `partner_doc_number` u vydaných faktur

U vydaných faktur je `docNumber` naše číslo a jde do `doc_number` přes
`importNumber`. Top-level `canonical.docNumber` ho ale pošle i do
`partner_doc_number` (stávající mapování applieru). To je referenčně neškodné,
ale sémanticky je `partner_doc_number` "číslo od partnera". Pokud to vadí,
u invno top-level `docNumber` vynech (pošli `null`) — `doc_number` se naplní
z `importNumber`, `partner_doc_number` zůstane prázdné. **Rozhodni při
implementaci podle toho, jak se partner_doc_number zobrazuje u vydaných
faktur v UI.** (U přijatých faktur `docNumber → partner_doc_number` ponech.)

### 4. Vydané faktury bez vlastního účtu

Když `myBankAccount` není dohledatelný přes LocalIdMap (Fáze 02 bank-accounts
ho nenaimportovala, nebo doklad účet nemá), vydaná faktura se vloží jako
koncept (10) bez čísla. To je výjimečný případ — uživatel doplní účet a
potvrdí ručně. Alternativa (uvolnit validaci vlastního účtu v import módu)
je mimo scope; vydaná faktura bez účtu je oprávněně neúplná.

### 5. `--target-state=10` (testovací)

Při `--target-state=10` jdou všechny faktury jako koncepty → `importNumber`
se neposílá (koncept číslo nemá), counter se nemění. To je pro testování
v pořádku (čísla se přidělí až při ručním potvrzení). Produkční import jede
bez tohoto flagu (cílový stav 20).
