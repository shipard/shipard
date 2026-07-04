# 15 — Saldokonto v `all`: nastavení jako fáze + závěrečný vzdálený matching

## Kontext

Kompletní import dnes vyžaduje tři ruční kroky:

1. `… --reset --import accbal-settings` (nastavení saldokont),
2. `… all` (vlastní import),
3. na cílovém serveru `shpd-ds accbal-match --all` (spárování úhrad).

Cíl: jeden příkaz `… all --reset` udělá všechno — nastavení saldokont ve
správném místě pipeline (před doklady) a spárování jako závěrečný krok
„na dálku" přes nový API endpoint.

Dva odemykače:

- **Idempotence `accbal-settings`** — runner dnes nemá žádnou (žádná
  LocalIdMap), opakované spuštění `all` by duplikovalo skupiny. Pozor:
  „skip když tabulka neprázdná" nefunguje — clearing provisioner vždy
  zakládá skupinu `unmatched_payments`. Správná granularita je **per
  skupina podle `code`** (runner si unikátnost kódů v JSONu už vynucuje;
  `CrudClient::findOneBy('economy_accbal_balances', 'code', …)` existuje
  a filter whitelist na nové straně ho podporuje — používá ho clearing guard).
- **Endpoint `POST /_accbal/match`** na nové straně — viz
  `nov_shipard:tasks/accbal-match-endpoint.md` (**prerekvizita** fáze Match).

## Návaznost

- **Prerekvizita:** `nov_shipard:tasks/accbal-match-endpoint.md` — bez něj
  fáze Match nemá co volat (a nginx `fastcgi_read_timeout` z něj kryje dlouhé
  běhy).
- Task 12 (`accbal-settings`) — mění se jeho „mimo `all`" rozhodnutí; docblock
  runneru a README modulu se aktualizují.
- README modulu (`modules/imports/newShipard/README.md`) — Rychlý start krok 4
  se zjednoduší na jeden příkaz; tabulka subkomand a pořadí `all` se aktualizují.

## Před implementací přečti

- `libs/runners/AllRunner.php` — pole `$phases`, `assertClearingInfrastructure()`
  (vzor read-only GET checku), `printSummary()`
- `libs/runners/AccbalSettingsRunner.php` — `run()` (validace `--dump`/`--import`),
  `doImport()`, `createBalance()`, `createAccount()`, stats
- `libs/CrudClient.php` — `findOneBy()`
- `libs/HttpClient.php` — kde se aplikuje `target.timeout` na curl request
  (kvůli per-call overridu)
- `libs/ImportApp.php` — parsování argů, help text
- `README.md` modulu — sekce Rychlý start (krok 4), Subkomandy, Nastavení
  saldokont

## Scope

**V rozsahu:** `accbal-settings` jako fáze `all` (za číselníky), idempotence
per skupina, závěrečná fáze Match přes API, per-call HTTP timeout, opt-out
flagy, help + README.

**Mimo rozsah:**

- Změny `--dump` režimu a samostatného spouštění `accbal-settings` (zůstává,
  vč. `--file`).
- Update existujících skupin podle změněného JSONu (skip podle kódu změny
  uvnitř skupiny nepřepisuje — ruční doladění JSONu dál znamená `ds-reset`
  cílového DS; zůstává zdokumentované omezení).
- Destruktivní matcher operace přes API (unmatch/rematch) — jen CLI na cíli.

## Co implementovat

1. **`AccbalSettingsRunner` — veřejný vstup bez flagů.** Refactor: `run()`
   jen validuje `--dump` XOR `--import` a deleguje na veřejné `runDump()` /
   `runImport()`. `AllRunner` volá přímo `runImport()` — žádná simulace
   `--import` argumentu. `--file` override čte `filePath()` v obou cestách
   beze změny.

2. **Idempotence per skupina.** V `doImport()` před `createBalance()`:
   `findOneBy('economy_accbal_balances', 'code', $g['code'])` — existuje →
   `info("skupina '{$g['code']}' už na cíli existuje — přeskakuji (vč. účtů)")`,
   `skipped++` ve stats, **nevytváří se ani skupina, ani její účty**. Check je
   read-only → běží i v dry-run. `HttpException` z checku ošetřit po vzoru
   clearing guardu (hláška o filter whitelistu). Docblock runneru
   aktualizovat (idempotentní per kód skupiny; už není „jen čistý DS").

3. **`AllRunner` — fáze `Accbal settings`.** Do `$phases` za `All codebooks`
   (účty importuje `AccountsRunner` první, FK reference existují):

   ```php
   ['Accbal settings', fn() => (new AccbalSettingsRunner($this->context))->runImport()],
   ```

   Opt-out `--skip-accbal-settings` → fáze se přeskočí s info hláškou.
   Výsledné pořadí: codebooks → **accbal-settings** → persons → items →
   docs → bank-statements → mail → **match**.

4. **`AllRunner` — závěrečná fáze `Match`.** Po `Mail` (privátní metoda, ne
   runner — je to jedno HTTP volání):
   - `POST /_accbal/match`, body `{"scope": "all", "dryRun": <isDryRun()>}`
     — v dry-run vrátí endpoint read-only **plán** (konzistentní s filozofií
     clearing guardu: nácvik odhalí problémy).
   - Per-call timeout **600 s** (viz bod 5).
   - Úspěch → `summary` řádky z agregátu: kandidátů / spárováno (či plán
     v dry-run) / Σ částka / skipped důvody.
   - `HttpException` → **`warn`**, ne fail: importovaná data jsou v pořádku;
     hláška poradí ruční fallback `shpd-ds accbal-match --all` na cílovém
     serveru. Fáze Match nikdy nemění návratovou hodnotu `all`.
   - Match běží i když předchozí fáze měly chybové řádky (spáruje, co se
     naimportovalo); přeskočí se jen s `--skip-match`.

5. **`HttpClient` — per-call timeout.** Volitelný parametr timeoutu na
   příslušné request metodě (default `null` = `target.timeout` z configu).
   Globální config nechat být (strop 300 s je pro běžné requesty správný);
   fáze Match předá 600. Ověřené časování matcheru: nízké desítky sekund →
   velká rezerva.

6. **`ImportApp`** — help text: popis `all` (nové pořadí vč. accbal-settings
   a match), flagy `--skip-accbal-settings` a `--skip-match`.

7. **README modulu:**
   - **Rychlý start krok 4** — jediný příkaz `shpd-ds-import all` (příp.
     `all --reset`); samostatný krok `accbal-settings --import` zmizí
     (zmínit jen `--dump` workflow a `--file` override).
   - **Tabulka subkomand** — `accbal-settings`: už ne „mimo `all`", ale
     „součást `all`; samostatně pro `--dump` / vlastní `--file`".
   - **Pořadí `all`** všude aktualizovat (vč. sekce Pošta, kde je řetězec
     vypsaný) na: codebooks → accbal-settings → persons → items → docs →
     bank-statements → mail → match.
   - **Nastavení saldokont** — doplnit idempotenci per kód skupiny; omezení
     „změny v existující skupině = ds-reset" zůstává.
   - **Historie fází** — přidat Fázi 15.

## Hotovo když

- Na resetnutém DS: jediný příkaz `… all --reset` provede nastavení saldokont
  (za číselníky), kompletní import a na konci vypíše souhrn párování
  z endpointu — bez ručního kroku na cílovém serveru.
- Druhé spuštění `all` (bez resetu) žádné duplicitní skupiny nevytvoří —
  existující kódy se přeskočí s info hláškou a `skipped` ve stats.
- `all --dry-run` vypíše plán párování (endpoint s `dryRun: true`), nic nemění.
- `--skip-accbal-settings` a `--skip-match` fáze vynechají s info hláškou.
- Nedostupný/chybějící endpoint → warn s fallback instrukcí, `all` tím
  nefailne; warn se objeví v recap/`.err` (Fáze 14).
- Samostatné `accbal-settings --dump` / `--import` / `--file` fungují beze změny.
- Help text i README odpovídají (pořadí, flagy, idempotence).
- Smoke test na `ns-alpha`: `all --reset --quiet` end-to-end, porovnat souhrn
  párování s ručním `shpd-ds accbal-match --all` (mělo by hlásit 0 nových —
  vše už spárované).

## Doporučené pořadí

1. `AccbalSettingsRunner` refactor + idempotence check (**commit 1**,
   `feat: accbal-settings idempotent per group code + public runImport`).
2. Fáze v `AllRunner` (settings + match) + `HttpClient` per-call timeout +
   flagy + help (**commit 2**, `feat: accbal settings & remote matching in all
   pipeline`). Vyžaduje nasazený endpoint na cílové straně.
3. README (**commit 3**, `docs: …`).
4. Smoke test dle „Hotovo když".

## Rozhodnutí ✓

- **D1:** `accbal-settings` jako fáze `all` hned za číselníky (účty už
  existují); vstup přes veřejné `runImport()`, ne simulaci flagů; opt-out
  `--skip-accbal-settings`; samostatné použití beze změny. ✓
- **D2:** Idempotence per skupina podle `code` (findOneBy před create;
  existuje → skip skupiny vč. účtů). „Skip při neprázdné tabulce" vyloučen —
  provisioner vždy zakládá `unmatched_payments`. Změny uvnitř existující
  skupiny = dál `ds-reset` (zdokumentované omezení). ✓
- **D4:** Závěrečná fáze Match: `POST /_accbal/match` scope all, v dry-run
  `dryRun: true` (plán); selhání = warn + fallback instrukce, nikdy nefailí
  `all`; opt-out `--skip-match`; per-call timeout 600 s (běh ověřeně
  v nízkých desítkách sekund). ✓
- **D5:** Dva tasky — endpoint v nov_shipard
  (`tasks/accbal-match-endpoint.md`, prerekvizita), integrace zde; README
  modulu součástí tohoto tasku. ✓

## Otevřené body

- Automatický trigger matcheru po běžné ingestion transakcí (mimo import) —
  saldokonto roadmapa, tímto taskem nedotčeno.
- Update existujících saldo skupin podle změněného JSONu (místo ds-reset) —
  otevřít, až bude ladění JSONu častější než dosud.
