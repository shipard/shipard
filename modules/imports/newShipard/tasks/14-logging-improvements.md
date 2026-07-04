# 14 — Logging: úrovně, `.err` soubor, `--quiet`, recap a úklid při resetu

## Kontext

Import velkých dat produkuje dlouhé logy, kde per-řádkové `✓` řádky (jednou na
záznam — při desítkách tisíc dokladů 95 % objemu) pohřbí desítky `!`/`✗` řádků,
které je při ladění potřeba najít.

Dobrá zpráva: sémantika už na call sites existuje — `ImportRunner` má helpery
`info()/ok()/warn()/err()/debug()` (45/8/34/31/35 míst) — jen ji `Logger`
zahazuje: všechno teče do jediného `line()` (tee konzole + jeden `.log`
soubor). Úrovně tedy lze zavést centrálně, bez zásahu do stovek volání
v runnerech.

## Návaznost

- `log/` v DS rootu **není výhradně náš** — `src/CLI/Application.php` má
  (zakomentovaný) dibi profiler do téhož adresáře. Úklid logů proto smí mazat
  **jen** soubory tohoto modulu (glob `import-*.log` / `import-*.err`), nikdy
  celý adresář.
- Sémantika `reset` / `--reset` (Fáze 06) se rozšiřuje o úklid logů.
- `AttachmentImporter` (Fáze 07a) je přímý uživatel `Logger::line()` s ručním
  `'! '` prefixem — převede se na úrovně.

## Před implementací přečti

- `libs/Logger.php` — celý (je krátký)
- `libs/ImportRunner.php` — helpery `info/ok/warn/err/debug` (ř. ~40–50)
- `libs/ImportApp.php` — konstrukce Loggeru (ř. ~85), parsování argů, dispatch
  subkomand, zpracování `--reset` a subkomandy `reset`, návratové hodnoty
- `libs/AttachmentImporter.php` — přímé použití loggeru (ruční `'! '` prefix)
- Hlavní smyčky runnerů: `PersonsRunner`, `ItemsRunner`, `DocsRunner`,
  `BankStatementsRunner`, `MailRunner` — kam přijde `tick()`; a jejich
  `Done …` summary řádky (grep `"Done "`)
- Jak `Shipard\CLI\Application` propaguje návratovou hodnotu cliAction
  do exit code (kvůli D6)

## Scope

**V rozsahu:** úrovně v Loggeru, `.err` soubor, `--quiet`, progress ticky,
recap chyb, exit code sémantika, úklid logů při resetu.

**Mimo rozsah:**

- Rotace/retence logů (`--keep-logs=N`) — neřeší se; úklid jen přes reset.
- Konfigurovatelný interval ticků — natvrdo 500.
- Debug do `.log` bez `--verbose` — zůstává současné chování (debug jen
  s verbose, i v souboru).
- Celkový počet záznamů v progress ticku (COUNT dopředu) — nice-to-have,
  neřeší se; tick zobrazuje průběžné počítadlo.

## Co implementovat

1. **`Logger` — úrovně a politika výstupu.**
   - Úrovně: `debug | info | ok | warn | err | summary`.
   - Konstruktor: cesta `.log` souboru (jako dnes) + režim konzole
     (`normal | quiet | verbose`).
   - API: interní `log(string $level, string $text)` + veřejné metody per
     úroveň. `line()` zachovat jako alias `info` (BC), `block()` beze změny
     signatury.
   - **Prefixy (`✓`, `!`, `✗`, `[debug]`) renderuje nově výhradně Logger**
     podle úrovně — helpery v `ImportRunner` je přestanou přidávat. Pozor na
     dvojité prefixy v přechodném stavu; změnit obojí v jednom kroku.
   - Konzole: `normal` = vše kromě debug; `quiet` = jen warn/err/summary
     (+ progress, viz bod 3); `verbose` = vše. `block()` (payload dumpy —
     kontext chyb) jde na konzoli vždy.
   - `.log` soubor: vše kromě debug; s `verbose` i debug. `block()` ano.
   - **`.err` soubor:** stejný basename s příponou `.err`
     (`import-YYYYmmdd-His.err`), obsahuje jen warn + err (s časovými
     značkami). Otevírá se **lazy při prvním warn/err** — čistý běh žádný
     `.err` nezakládá. `block()` do `.err` nejde (má zůstat skenovatelný).
     Best-effort jako `.log`.
   - **Recap:** Logger sbírá warn/err řádky do paměti (cap 100 + čítače nad
     cap). Metoda `printRecap()`: `⚠ N errors, M warnings` + prvních ~20
     řádků + `full list: <cesta k .err>`. Bez posbíraných řádků nevypíše nic.

2. **`ImportRunner`** — helpery předávají úroveň (bez prefixů); nově:
   - `summary(string $msg)` — závěrečné `Done …` řádky všech runnerů přepnout
     z `info()` na `summary()` (viditelné i v quiet).
   - `tick(string $phase, int $processed, array $stats): void` — v quiet
     režimu každých 500 zpracovaných záznamů vypíše
     `[docs] 12500 zpracováno (created=…, skipped=…, failed=…)` (klíče stats
     dle runneru); mimo quiet no-op. Modulo řeší tick sám, volá se na konci
     každé iterace. Zapojit do hlavních smyček: persons, items, docs,
     bank-statements, mail (číselníky ne — malé objemy).

3. **`ImportApp`:**
   - Nový arg `--quiet`; `--quiet` + `--verbose` současně → chybová hláška
     a konec (FAILURE).
   - Režim konzole předat do konstrukce Loggeru.
   - Na konci běhu (všechny subkomandy, u `all` za všemi fázemi):
     `printRecap()` + `close()`.
   - Help text doplnit o `--quiet`.

4. **`AttachmentImporter`** (a případní další přímí volatelé `line()`,
   ověřit grepem) — převést na úrovňové metody, odstranit ruční `'! '`
   prefix ze sprintf.

5. **Exit code (D6).** Nejdřív ověřit, jak `Shipard\CLI\Application`
   převádí návratovou hodnotu cliAction na exit code procesu. Cíl:
   `0` = čistý běh, `1` = tvrdý fail, `2` = doběhlo, ale padly err úrovně
   (typicky s `--continue-on-error`). Zdroj pravdy o výskytu chyb = čítače
   v Loggeru (recap data). Pokud CLI vrstva umí jen bool, mapování zajistit
   v `ImportApp` (např. `exit(2)` po recapu). Ověřit, že to nerozbije
   orchestraci `all` (AllRunner mezi fázemi pracuje s bool — chyby řádků
   nesmí shodit navazující fáze, mění se jen finální exit code procesu).

6. **Úklid logů při resetu (D7).** Při subkomandě `reset` i při `--reset`
   se **před otevřením aktuálního log souboru** smažou soubory dle globu
   `log/import-*.log` a `log/import-*.err` v DS rootu. Nikdy ne celý
   adresář (viz Návaznost). Pořadí je kritické — úklid nesmí smáznout právě
   otevíraný soubor běžícího importu; provést před konstrukcí Loggeru,
   případně vyloučit aktuální basename. Vazba na `--dry-run`: úklid logů
   kopíruje podmínku mazání mapy (pokud dry-run mapu nemaže, nemaže ani
   logy — ověřit současné chování `--reset` × `--dry-run` a zachovat
   konzistenci).

## Hotovo když

- Velký import v defaultu: konzole jako dnes (s prefixy beze změny vzhledu)
  + recap na konci; `.err` obsahuje přesně warn+err řádky běhu.
- `--quiet`: konzole = progress každých 500 záznamů + warn/err + `Done …`
  summary + recap; `.log` přitom obsahuje plný záznam jako v defaultu.
- `--quiet --verbose` → chyba, běh nezačne.
- `--verbose`: debug na konzoli i v `.log`.
- Čistý běh nezaloží `.err` soubor.
- `reset` i `all --reset` smažou staré `import-*.log`/`.err`; log právě
  spuštěného běhu přežije; cizí soubory v `log/` zůstávají netknuté.
- Exit code: 0 čistý / 1 tvrdý fail / 2 doběhlo s chybami; `all` s failnutými
  řádky v jedné fázi doběhne všechny fáze a vrátí 2.
- Žádné dvojité prefixy (`! !`, `✗ ✗`) nikde ve výstupu.
- Smoke test na reálném importu (docs nebo mail, tisíce záznamů) s uměle
  vyvolanou chybou: default, `--quiet`, `--verbose`, reset úklid.

## Doporučené pořadí

1. `Logger` — úrovně, politika konzole/souborů, `.err` lazy, recap;
   přesun prefixů do Loggeru + úprava `ImportRunner` helperů a
   `AttachmentImporter` (jeden krok, ať nejsou dvojité prefixy).
   **Commit 1** (`feat: import log levels + .err file + error recap`).
2. `--quiet` + `summary()` + `tick()` v runnerech + help text.
   **Commit 2** (`feat: import --quiet mode with progress ticks`).
3. Exit code sémantika (po ověření CLI vrstvy) + úklid logů při resetu.
   **Commit 3** (`feat: import exit codes + log cleanup on reset`).
4. Smoke test dle „Hotovo když".

## Rozhodnutí ✓

- **D1:** Úrovně (`debug/info/ok/warn/err/summary`) zavádí Logger centrálně;
  helpery `ImportRunner` je jen předávají; prefixy renderuje Logger. ✓
- **D2:** `.err` soubor vedle `.log` (stejný basename), warn+err, lazy
  otevření při prvním záznamu. ✓
- **D3:** `--quiet` filtruje **jen konzoli** (warn/err/summary/progress);
  soubory vždy plné dle matice; `--quiet`+`--verbose` = chyba. ✓
- **D4:** Progress tick v quiet každých 500 záznamů, natvrdo (bez flagu);
  `Done …` řádky jako `summary`, viditelné vždy. ✓
- **D5:** Recap chyb na konci běhu (cap 100 v paměti, výpis ~20 + odkaz na
  `.err`); u `all` na úplném konci. ✓
- **D6:** Exit code 0/1/2 (čistý / tvrdý fail / doběhlo s chybami); mapování
  dle možností CLI vrstvy, ověřit předem. ✓
- **D7:** `reset` a `--reset` promažou `log/import-*.log` + `.err` globem
  (nikdy celý adresář — `log/` sdílí i dibi profiler); před otevřením
  aktuálního logu; debug zůstává jen s `--verbose`. ✓

## Otevřené body

- Rotace/retence logů mimo reset (`--keep-logs=N` / cron `find -mtime`) —
  otevřít, až bude `log/` růst i v běžném provozu bez resetů.
- Progress s celkovým počtem (`12500/48213`) — vyžaduje COUNT dopředu;
  zvážit, až bude tick v praxi používaný.
