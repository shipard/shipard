# F7a — proklikání datových sad (E2E průchod UI)

**Stav:** částečně — implementace hotová 2026-10-23 (kroky 1–5, 5 commitů): proklikání nad `cz-vat-payer`, `cz-non-payer` i `cz-association` dává `failed` kvůli nálezům N1–N3 a N6 (oddíl Nálezy, neopravují se bez schválení); zbývá schválení oprav v #103 (D-čísla) a poté běh se stavem `ok`

## Kontext / Cíl

F3 (`dataset-verify`) ověřuje zaúčtování a DPH nad sadami korpusu, ale
návrhy používá **přímo přes `MessageProposalApplier`** s `autoCreateMode`
liberal. Cestu, kterou jde účetní — karta na dashboardu → Kontrola →
rozhodnutí o referencích → Vystavit a uzavřít — tak nepokrývá: frontend,
REST endpoint `POST /_mail/messages/{ndx}/apply` ani režim strict
s `_resolve` od klienta.

**Cíl:** jedním příkazem (a jednou akcí ve vývojářském dashboardu)
přeseedovat zdroj dat sady, proklikat v prohlížeči všechny karty návrhů
jako účetní a výsledek ověřit stejnými kontrolami jako F3
(`dataset-verify --no-apply`). Výsledek musí sedět s F3; každý rozdíl je
nález v Shipardu nebo v sadě (#103 D85 — doladění).

Proklikání je v **Playwright Test** (D89), ne ve video-runneru. Video-runner
a jeho `check` zůstávají beze změny.

## Návaznost

- **#103** D85–D90 (komentář 2026-10-23), D52 (soubory v checkoutu), D55
  (evaluační zdroj dat sady), D58–D60 (úlohy dashboardu, `DevActionGuard`),
  D64 (`keepOnReset` uživatelů), D11 (nahrazená část)
- **#48** — `data-testid` a zkratka `@` (D11–D14), video-runner
- F3: `tasks/testing-phase3.md`, `docs/test-corpus.md` § 17
- F6b: `tasks/testing-phase6b.md` (úlohy, panel sady, `--progress-file`)

## Před implementací přečti

- `docs/test-corpus.md` § 16–18 (sady, `dataset-verify`, evaluace a úlohy)
- `docs/frontend.md` § 9 → `data-testid`; `docs/dashboard.md` (feed, akce
  karet, jednoklik vs. kontrola)
- `frontend/src/api/exchange.js` (`applyMessage` — tvar těla, safe / strict),
  `frontend/src/components/dashboard/Dashboard.svelte`, `Feed.svelte`,
  `FeedCard.svelte`, `frontend/src/components/exchange/DocumentExchangePreviewModal.svelte`
  (`canApply`, `review-apply-final`), `DocumentExchangePreview.svelte`
  (`decideForPath`, dialog rozhodnutí reference)
- `modules/core/mail/src/MessageProposalApplier.php` (`autoCreateMode`),
  `modules/core/mail/src/Feed/MailSuggestionsSource.php` (akce karty)
- `src/Command/DataSource/DatasetVerifyCommand.php` (`--no-apply`, `--json`),
  `DatasetSeedCommand.php`, `AiEvalCommand.php` (`--progress-file`, SIGTERM)
- `src/Api/SessionService.php`, `src/Command/DataSource/UserCreateCommand.php`,
  `UserSetAdminCommand.php`
- `src/Core/DevJobs/*`, `src/Api/Controller/DevEvalController.php`
  (úlohy `reseed` / `verify` / `eval`), `src/Core/DevUpdate/UpdateEnvironment.php`
  (`findTool`, pevný `PATH`)
- `tools/video-runner/src/config.mjs`, `browser.mjs` (kanál `chromium`,
  session v `storageState`)

## Rozhodnutí

Zamčená v #103: D86 (F7a / F7b), D87 (co proklikání ověří), D88
(spouštění), D89 (Playwright Test). Potvrzená 2026-10-23 a zapsaná
v #103:

- **D91 — Jeden příkaz `shpd-ds dataset-e2e`,** úloha dashboardu je jeho
  obal (vzor `ai-eval` + `--progress-file`). Žádná orchestrace kroků
  zvlášť v PHP controlleru a zvlášť v CLI.
- **D92 — Přihlášení přes krátkodobou session, bez hesla.** Příkaz založí
  vyhrazeného uživatele `e2e` (administrátor), pokud chybí — přežije
  přeseedování díky `keepOnReset` (D64). Před během mu vytvoří session
  (`SessionService::createSession`), token zapíše jen do `storageState`
  souboru `0600` v adresáři běhu a po běhu session zneplatní a soubor
  smaže. Token nikdy na stdout, v argumentech, v logu ani v reportu.
- **D93 — Rozhodnutí v kontrole jako F3.** Nerozhodnutou referenci
  (dodavatel, položka, …) proklikání rozhodne **Vytvořit**, pokud ho
  kontrola nabízí. Když ho nenabízí, karta selže s cestou reference
  (`supplier`, `rows[3].item`). Jiné hodnoty návrhu proklikání nemění —
  účetní návrh přijme.
- **D94 — Pořadí jako uživatel:** karty shora tak, jak je seřadí server;
  po každém použití čekat na toast a pokračovat další kartou. F3 jde
  v pořadí `received_at` — rozdíl ve výsledku způsobený pořadím je nález.
- **D95 — Co je selhání:** selhaná karta (timeout, chyba v kontrole,
  neaktivní Vystavit a uzavřít), chyba v prohlížeči (`pageerror`,
  `console.error`), odpověď API 5xx a jakákoli kontrola
  `dataset-verify --no-apply`, která není `ok`. Odpovědi 4xx se jen
  zapisují do reportu.
- **D96 — Výsledky do adresáře běhu, ne do repozitáře.** Proklikání je
  deterministické; repozitář drží výsledky evaluací kvůli porovnání
  v čase (D8), tady není co porovnávat. Dashboard ukáže souhrn a nabídne
  trace selhaných karet ke stažení; HTML report Playwrightu zůstane
  v adresáři běhu.

## 1. Testidy (frontend)

Rozšířit zlatou cestu S1 (`docs/frontend.md` § 9) jen o to, co proklikání
potřebuje — podle konvence D11–D12 (odvodit ze serverových id, kde jsou):

- karta feedu nese vedle `data-testid="feed-card"` atribut
  `data-card-id` (id karty ze serveru), aby test poznal, která karta
  zmizela;
- v kontrole: řádek reference podle cesty `review-ref:{path}` a jeho
  akce **Vytvořit** `review-ref-create:{path}` (případně v dialogu
  rozhodnutí — podle toho, kde volba skutečně je);
- stav, že kontrola dál čeká na rozhodnutí (`canApply` false), musí jít
  zjistit bez textu — např. `data-can-apply` na `review-apply-final`
  nebo stačí `disabled`; rozhodne implementace, zdokumentovat.

Texty se v testu nepoužívají.

## 2. E2E projekt `tests/e2e/`

- `package.json` s `@playwright/test` ve stejné verzi jako `playwright`
  ve video-runneru, `package-lock.json` commitnutý, `node_modules`
  v `.gitignore`.
- `playwright.config.mjs`: jeden projekt, Chromium s kanálem `chromium`
  (stejná instalace prohlížeče jako video-runner), locale `cs-CZ`,
  `trace: 'retain-on-failure'`, reportéry `json` (soubor) a `html`
  (adresář) do výstupního adresáře z prostředí, `workers: 1`,
  žádné opakování (`retries: 0`) — selhání je nález, ne nestabilita.
- Test `dataset-walkthrough.spec.mjs`, vstupy jen z prostředí:
  `SHPD_E2E_APP_URL` (adresa aplikace zdroje dat), `SHPD_E2E_STORAGE_STATE`,
  `SHPD_E2E_OUTPUT_DIR`, `SHPD_E2E_EXPECTED_CARDS` (počet zpráv sady
  s otevřeným návrhem).
- Průběh: dashboard → dokud je na něm karta návrhu zprávy: Kontrola →
  rozhodnout nerozhodnuté reference (D93) → Vystavit a uzavřít → toast →
  znovu načtený feed. Každá karta je `test.step` s id karty, aby report
  i trace ukázaly, kde selhala. Po selhání karty test pokračuje další
  kartou (selhání se sbírají, na konci jedno vyhodnocení).
- Sběr chyb prohlížeče a odpovědí API ≥ 400 (D95) do přílohy testu.
- Na konci: počet použitých karet = `SHPD_E2E_EXPECTED_CARDS`, na
  dashboardu nezbyla žádná karta návrhu.

## 3. Příkaz `shpd-ds dataset-e2e`

`shpd-ds dataset-e2e <dir|zip> [--no-reseed] [--base-url <url>]
[--output-dir <adresář>] [--json] [--progress-file <soubor>]`

- **Preflight:** režim `development`, třída zdroje dat `free` (fail-closed
  jako `ds-delete` / `DevActionGuard`), sada ze seznamu sad v checkoutu,
  `node` a `npx` přes `UpdateEnvironment::findTool` (pevný `PATH`),
  `tests/e2e/node_modules` existuje. Chybějící Node, závislosti nebo
  prohlížeč → odmítnutí s přesnou větou, co spustit
  (`cd tests/e2e && npm ci && npx playwright install chromium`).
- **Kroky:** přeseedování (`dataset-seed --yes`, ne s `--no-reseed`) →
  uživatel a session (D92) → `npx playwright test` jako podproces s
  prostředím z § 2 → zneplatnění session a smazání `storageState`
  (i při chybě a při SIGTERM) → `dataset-verify --no-apply --json` →
  report.
- **Adresa aplikace:** `<base-url>/<ds-id>/app/` (`docs/frontend.md` →
  Dev mód). `--base-url` výchozí `http://127.0.0.1`; úloha dashboardu
  předá origin požadavku.
- **Report** (`--json` na stdout, jinak čitelný výpis): karty použité /
  selhané (id karty, zpráva sady, krok, důvod), chyby prohlížeče, odpovědi
  API ≥ 400, souhrn `dataset-verify` (stav per kontrola), cesty k HTML
  reportu a trace souborům, výsledný stav `ok` / `failed` (D95). Exit 0
  jen při `ok`, 1 při nálezech, 2 při chybě preflightu.
- **Průběh** (`--progress-file`): JSON řádky `started` / `step`
  (přeseedování, proklikání, ověření) / `card` (id, výsledek) /
  `finished` / `error` — stejný tvar a zpracování jako u `ai-eval`.
- SIGTERM: ukončit Playwright, uklidit session, exit 130.
- Výchozí `--output-dir`: nový adresář v systémovém temp, cesta ve
  výstupu. Úloha dashboardu dává adresář úlohy.

Unit testy: preflight (třída, režim, chybějící nástroje), sestavení
reportu z JSON reportu Playwrightu a z JSON `dataset-verify`, stav
`ok` / `failed`, úklid session při výjimce. Token se v reportu ani
výstupu neobjeví (test).

## 4. Úloha a akce ve vývojářském dashboardu

- Nový typ úlohy `e2e` (`JobRecord::TYPES`), jeden krok =
  `dataset-e2e <sada> --json --progress-file … --output-dir <adresář úlohy>
  --base-url <origin požadavku>`, zrušitelná. Jedna aktivní úloha na zdroj
  dat platí beze změny.
- Akce **Proklikat** v panelu sady vedle Přeseedovat / Ověřit (stejný
  zdroj dat sady, D55), přes `DevActionGuard`. Když chybí Node nebo
  závislosti E2E, akce je neaktivní s nápovědou (stejný zdroj jako
  preflight — endpoint vrátí důvod, frontend ho jen ukáže).
- Výsledek úlohy: souhrn z reportu (karty, chyby, kontroly
  `dataset-verify`), u selhaných karet stažení trace (`trace.zip`,
  read-only endpoint nad adresářem úlohy, jen soubory, které report
  jmenuje — žádné libovolné cesty).
- Po proklikání je zdroj dat „s použitými doklady“ — panel to ukazuje
  už dnes; evaluace před během žádá přeseedování jako dosud.
- Texty v `src/dev/i18n/` (cs + en), `npm run check:i18n`.

## 5. Dokumentace

- `docs/test-corpus.md` nový § 19 „Proklikání (F7a)“: co ověřuje proti
  F3, D91–D96, příprava (`npm ci`, `npx playwright install chromium`,
  Node v systémovém `PATH`), jak číst report a trace, pasti.
- `docs/cli.md` + řádek v `CLAUDE.md` → `shpd-ds`: `dataset-e2e`.
- `docs/frontend.md` § 9: nové testidy a `data-card-id`.
- `docs/dev/dev-daily.md`: proklikání sady z dashboardu i z CLI.

## Kroky

1. Testidy (§ 1) → commit `feat(frontend): testidy kontroly návrhu a id karty feedu pro proklikání (#103 F7a)`
2. `tests/e2e/` (§ 2), ověřený ručně proti naseedovanému zdroji dat
   s ručně připraveným `storageState` → commit
   `feat(e2e): projekt Playwright Test a proklikání sady (#103 D87, D89)`
3. `shpd-ds dataset-e2e` + unit testy (§ 3) → commit
   `feat(dataset): dataset-e2e — přeseedování, session, proklikání a dataset-verify (#103 D91–D95)`
4. Úloha `e2e` a akce v dashboardu (§ 4) → commit
   `feat(dev): proklikání sady z vývojářského dashboardu (#103 D88, D96)`
5. Dokumentace, `**Stav:**`, `python3 scripts/tasks-index.py` → commit
   `docs: proklikání datových sad (#103 F7a)`

## Hotovo když

- [x] `shpd-ds dataset-e2e` doběhne nad `cz-vat-payer`, `cz-non-payer`
      a `cz-association` na jejich zdrojích dat sady a vydá report.
- [x] Nálezy z prvních běhů jsou sepsané v oddílu **Nálezy** tohoto tasku
      (karta, krok, příčina) — **neopravují se v tomto tasku** bez
      schválení v chatu; opravy dostanou D-čísla v #103 (jako D78–D84).
- [ ] Bez nálezů (nebo po schválených opravách) dává proklikání u všech
      tří sad stav `ok` a `dataset-verify --no-apply` kontroly 1–6 `ok`
      — **čeká na schválení oprav N1–N3 a N6** (2026-10-23 všechny tři
      sady `failed`, viz tabulka v Nálezech).
- [x] Token session se neobjeví ve výstupu, reportu, logu úlohy ani
      v `ps` (ověřeno testem a ručně při běhu z dashboardu).
- [x] Proklikání jde spustit z dashboardu, zrušit, a selhaná karta má
      trace ke stažení.
- [x] Preflight bez Node / bez `node_modules` odmítne s přesnou větou;
      akce v dashboardu je neaktivní s nápovědou.
- [x] `vendor/bin/phpunit --filter` na nové testy, `npm test`
      a `npm run build` ve `frontend/`, `npm run check:i18n`,
      `python3 scripts/check-css-tokens.py`.
- [x] Dokumentace podle § 5, `**Stav:**`, index tasků.

## Pasti

- **Proklikání zprávy spotřebuje.** Bez přeseedování druhý běh nemá co
  klikat — proto přeseedování jako výchozí krok, `--no-reseed` jen pro
  ladění.
- **Vývojářský server za nginx:** ověřit, že `http://127.0.0.1` na
  vývojovém stroji opravdu míří na Shipard (výchozí `server_name`);
  když ne, `--base-url`.
- **Node v neinteraktivním shellu** není v `PATH` z nvm (`docs/ai-workflow.md`
  § 4). Úloha dashboardu bere pevný `PATH` jako `dev-update` — Node musí
  být v systémové cestě, jinak preflight odmítne.
- **Prohlížeč patří uživateli, pod kterým běží úloha** (cache Playwrightu
  v jeho domovském adresáři). Instalovat pod stejným uživatelem jako
  PHP-FPM, jinak z CLI projde a z dashboardu ne — preflight to musí
  rozpoznat a říct.
- **`autoCreateMode` liberal (F3) a strict (UI) se mohou lišit** v tom,
  co jde založit. To je smysl proklikání — rozdíl je nález (D93), ne
  důvod přepnout UI na liberal.

## Mimo rozsah

- Vrátit (unapply) a opětovné použití, jednoklik bez kontroly, Zamítnout.
- Videa a jejich správa v dashboardu (F7b), stálé „dnes“ na serveru.
- Demo sada „IT servis“ — nemá karty návrhů k proklikání; přijde s F7b.
- Spouštění v CI.

## Odchylky od zadání (implementace 2026-10-23)

Podrobně `docs/test-corpus.md` § 19 → Odchylky od zadání.

- Akce karty je `review_message` („Zkontrolovat“, `card-action-review`),
  ne `review_extracted`; karty sad jsou všechny v pásmu review.
- Stav kontroly (§ 1): `disabled` na `review-apply-final` je přesně
  `!canApply`; navíc skrytý marker `review-state` s `data-loaded`,
  `data-ai-failed`, `data-pending-save` a `data-pending-paths` — test
  tak ohlásí blokující cestu i bez odznaku (N1). `review-ref-create:{path}`
  je v panelu popoveru (`ResolveDecisionPanel` dostává `path`).
- § 2: `trace: 'off'` v configu a ruční `startChunk` / `stopChunk` per
  karta (jinak jeden trace na celý test); spec navíc čte volitelný
  `SHPD_E2E_PROGRESS_FILE` a `card` události píše sám.
- § 3: Playwright se spouští `node …/@playwright/test/cli.js`, ne `npx`
  (preflight chce `node`, `cli.js` a prohlížeč; `npx` jen v nápovědě);
  zrušení = SIGINT Playwrightu, po grace SIGKILL; přeseedování
  a `dataset-verify` ve stejném procesu, jen Playwright je podproces;
  uživatel `e2e` je `is_system = 1`; `createSession` dostal volitelný
  `ttlSeconds`; kontrola `skipped` = selhání (D95 doslova).
- § 4: `canE2e` nesdílí `blockers` panelu (ty gatují evaluaci — použitý
  zdroj dat proklikání nevadí, přeseedování je jeho první krok);
  `e2eBlocker` je věta z téhož preflightu. Origin požadavku nově
  `Request::getOrigin()`.

## Nálezy

První běh specu (krok 2) nad `r1lf` (`cz-non-payer`, 16 karet, ručně
připravený `storageState`) 2026-10-23 — 2 karty použité, 14 selhaných.
**Neopravuje se v tomto tasku** (D85 — opravy dostanou D-čísla v #103):

- **N1 — bankovní účet dodavatele bez odznaku.** Po založení dodavatele
  z kontroly („+ Vytvořit novou osobu“ → formulář → Uložit →
  `useExisting:<id>`) nemá nová osoba bankovní účet (formulář ho
  z `createPayload` nenese) a `_resolve.supplierBank` zůstane `canCreate`
  s `createPayload {person: <nová osoba>, iban, account_number, bic}`.
  Náhled pro bankovní účet strany **žádný odznak nekreslí**, ale
  `canApply` rozhodnutí vyžaduje → Vystavit a uzavřít i Použít zůstanou
  neaktivní bez cesty, jak to v UI změnit. Padlo na tom 14 z 16 karet
  (všechny s bankovním účtem dodavatele; prošly jen 0901 a 0902 bez
  účtu). Totéž potká účetní u každé faktury nového dodavatele
  s účtem. F3 (liberal) účet založí při apply — rozdíl strict × liberal
  přesně podle D93. Krok: `apply/disabled`, cesta `supplierBank`.
- **N2 — JS chyba po rozhodnutí z popoveru.** `TypeError: Cannot read
  properties of null (reading 'table')` v `DocumentExchangePreview.svelte`
  (`entityTable={decisionOpen.table}` u `ResolveDecisionPanel`):
  `handleDecide` nastaví `decisionOpen = null` a getter propu se ještě
  vyhodnotí. Uživatel nic nevidí, pro proklikání je to `pageerror`
  (D95). 7× za běh.
- **N3 — předkontrola registru vrací 502.** Při otevření kontroly
  s nenapárovanou stranou s IČO běží `GET /persons/registry?q=<IČO>`;
  pro fiktivní IČO identit korpusu (ARES odpoví 404) vrátí endpoint
  `502 REGISTRY_INVALID_RESPONSE` „Registry search rejected query …: no
  error details provided“ místo 404 / prázdného výsledku. 6× za běh,
  podle D95 selhání (5xx). ARES je z vývojového stroje dosažitelný.
- **N4 — šum, vyřešeno ve specu.** `GET /_app/avatar` → 404 u uživatele
  bez avataru; prohlížeč to loguje jako `console.error` „Failed to load
  resource“. Spec tyto ozvěny odpovědí nepočítá jako chybu prohlížeče
  (odpověď už eviduje posluchač `response`; 4xx se jen zapisuje).
- **N5 — pořadí (D94).** Server řadí karty nejnovější první, takže
  dvojice jedné identity jde `0V02` → `0V01`: dodavatel vzniká z dat
  varianty 02 a varianta 01 ho pak najde. F3 jde opačně. Zatím bez
  dopadu na výsledek (kvůli N1 se k ověření většina karet nedostala).

Běhy `shpd-ds dataset-e2e` (celý příkaz, krok 3) nad třemi sadami
2026-10-23 — všechny `failed`, exit 1, stejné N1–N3 a nově N6:

| Sada | Zdroj dat | Karty použité / očekávané | N1 | N2 | N3 | Kontroly `dataset-verify` |
|------|-----------|---------------------------|----|----|----|---------------------------|
| `cz-non-payer` | `r1lf` | 2 / 16 | 14 | 8 | 6 | věrnost apply rozdíl (4, N6), kurzy přeskočeno (bez dokladu v cizí měně), deník rozdíl (23), ostatní ok |
| `cz-vat-payer` | `ob9b` | 4 / 20 | 16 | 10 | 6 | věrnost apply rozdíl (8, N6), deník rozdíl (41), kurzy ok, ostatní ok |
| `cz-association` | `wp66` | 2 / 16 | 14 | 8 | 6 | věrnost apply rozdíl (4, N6), kurzy přeskočeno, deník rozdíl (23), ostatní ok |

Prošly jen zprávy bez bankovního účtu dodavatele (`0901` / `0902`,
u plátce i `0801` / `0802`); rozdíly deníku jsou důsledek nepoužitých
karet, ne nález.

- **N6 — založení osoby z kontroly nepřenese adresu.** „+ Vytvořit novou
  osobu“ otevře formulář s předvyplněním z `createPayload`, ale vnořené
  `addresses` odejdou v dotazu na metadata formuláře jako
  `defaults[addresses]=[object Object]` (`GET /_ui/form/base_persons_persons/meta`),
  takže nová osoba vznikne bez adresy a země. Doklad vystavený přes
  kontrolu pak má `supplier.address` i `supplier.country` null —
  kontrola **Věrnost apply** hlásí rozdíl u každého takového dokladu
  (2 pole × doklad). F3 (liberal) osobu založí z celého payloadu
  i s adresou. Krok: `verify`, kontrola `applyFidelity`.
