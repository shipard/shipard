# F7a — opravy nálezů proklikání a lokální adresa běhu

**Stav:** naplánováno — zadání 2026-10-23 (#103 D97–D101)

## Kontext / Cíl

První běhy proklikání (`tasks/testing-phase7a.md` → Nálezy) skončily nad
všemi třemi sadami stavem `failed`. Nálezy N1–N3 a N6 jsou chyby v cestě,
kterou jde účetní přes kontrolu návrhu. Při kontrole F7a se navíc ukázalo,
že adresu aplikace pro běh z dashboardu určuje hlavička `Origin`
požadavku bez omezení — Playwright by mohl odnést token session na cizí
host (S1).

**Cíl:** opravit S1 a N1–N3, N6 tak, aby proklikání všech tří sad dalo
stav `ok`, nebo zbyly jen nové nálezy, které se sepíšou (neopravují).

## Návaznost

- **#103** D97–D101 (komentář 2026-10-23), D87, D92, D93, D95
- `tasks/testing-phase7a.md` → Nálezy (N1–N6), Odchylky od zadání
- `docs/test-corpus.md` § 19

## Před implementací přečti

- `docs/test-corpus.md` § 19
- `src/Command/DataSource/DatasetE2eCommand.php` (`--base-url`, pořadí
  preflight → session), `modules/core/exchange/src/Dataset/E2e/E2ePreflight.php`
  (`appReachable`), `PlaywrightRunner.php`, `tests/e2e/playwright.config.mjs`
- `src/Api/Controller/DevEvalController.php` (akce `e2e`),
  `src/Api/Request.php` (`getOrigin`, `isOrigin`)
- `frontend/src/components/exchange/DocumentExchangePreview.svelte`
  (bankovní účet strany kolem řádku 849, `kind === 'bankAccount'`,
  `decisionOpen`, `handleDecide`, `ResolveDecisionPanel`),
  `reviewGate.js`, `DocumentExchangePreviewModal.svelte`
- `frontend/src/components/form/FormEditor.svelte` (`loadForm`,
  `defaults[…]`) a serverová strana `GET /_ui/form/{table}/meta`
- `modules/base/persons/src/Registry/PersonsRegistryClient.php` (`search`,
  `status: 0`), `src/Api/Controller/PersonsRegistryController.php`

## Rozhodnutí

- **D97 — Proklikání jen proti lokální adrese serveru.** Host z
  `--base-url` (z dashboardu i z CLI) musí být IP literál nebo jméno,
  jehož **všechny** adresy (A i AAAA) jsou loopback (`127.0.0.0/8`,
  `::1`) nebo adresa vlastního síťového rozhraní (`net_get_interfaces()`).
  Jinak příkaz odmítne v preflightu (exit 2) **před** založením session;
  akce v dashboardu vrátí 400 a úlohu nezaloží. Ověřená adresa se
  připíchne: Chromium dostane `--host-resolver-rules=MAP <host> <ip>`
  a kontrola dosažitelnosti v PHP `CURLOPT_RESOLVE`, aby mezi ověřením
  a během nešlo jméno přesměrovat.
- **D98 — Každá blokující cesta kontroly má ovládání (N1).** Bankovní účet
  strany (`supplierBank`, `customerBank`) dostane v náhledu odznak
  a rozhodnutí stejně jako strana a položka. Obecné pravidlo: cesta, kterou
  vrací `pendingPaths`, musí mít v náhledu ovládací prvek — hlídá to test
  (rozšířit tak, aby selhal pro jakoukoli budoucí blokující cestu bez
  ovládání, ne jen pro účty).
- **D99 — Osoba založená z kontroly nese adresu a zemi z dokladu (N6).**
  Vnořené výchozí hodnoty (`addresses`) se do formuláře osoby dostanou
  celé, ne jako `[object Object]`. Platí obecně pro `FormEditor` s vnořeným
  `defaultData`, ne jen pro osoby.
- **D100 — Neexistující IČO v registru je prázdný výsledek (N3).**
  `GET /persons/registry?q=<IČO>` pro IČO, které registr nezná, vrátí
  200 a `results: []`. Skutečná chyba registru (nedostupnost, poškozená
  odpověď, chyba s popisem) zůstává 502 / 503. Nejdřív zjistit, jakým
  tvarem odpovědi registr „nenalezeno“ hlásí, a rozlišovat podle něj —
  ne podle prázdného seznamu chyb naslepo.
- **D101 — Chyba JS po rozhodnutí z popoveru (N2)** — panel rozhodnutí se
  po zavření nevyhodnocuje nad `null`.

## 1. Lokální adresa běhu (D97)

- Jedna služba ověření (např. `E2e/LocalBaseUrl`), kterou používá příkaz
  i controller — žádná druhá kopie pravidla.
- Unit testy: IP literál loopback a vlastního rozhraní projde; cizí IP,
  jméno s jednou cizí adresou mezi lokálními, neplatná adresa a
  neresolvovatelné jméno neprojdou; session se při odmítnutí nezaloží
  (test s falešným `SessionService`).
- Controller: `Origin` s cizím hostem → 400 `VALIDATION`, žádná úloha.
- `PlaywrightRunner` / config: mapování hostitele předat Chromiu; ověřit
  ručně, že běh přes jméno serveru i přes IP funguje.

## 2. Ovládání bankovního účtu strany (D98)

- Odznak a rozhodnutí u bankovního účtu strany v náhledu (cesty
  `supplierBank` / `customerBank` v `userActions`), stejný vzhled
  a chování jako odznak strany; testid podle § 9 (`review-ref:supplierBank`,
  `review-ref-create:supplierBank`).
- Test pravidla „blokující cesta má ovládání“ (frontend unit test nad
  náhledem nebo nad funkcí, která vrací ovládané cesty — rozhodne
  implementace, zdokumentovat).
- Spec proklikání: když `review-state` hlásí blokující cestu, pro kterou
  nenajde `review-ref-create`, karta selže s textem „cesta bez ovládání“
  (už dnes v podstatě dělá — ověřit a zachovat).

## 3. Vnořené výchozí hodnoty formuláře (D99)

- `FormEditor.loadForm`: vnořené hodnoty `defaultData` se předají tak,
  aby je server i klient dostaly celé (JSON v jednom parametru, nebo
  předvyplnění podtabulek na klientu — podle toho, jak formulář osob
  adresy skutečně vytváří). Neplatí `String(objekt)`.
- Test (frontend unit nebo PHPUnit nad meta endpointem): nová osoba
  z kontroly s `createPayload` obsahujícím adresu má po uložení adresu
  a zemi.

## 4. Registr — nenalezené IČO (D100)

- Zjistit tvar odpovědi registru pro neznámé IČO (stav, chyby, HTTP kód)
  a zapsat ho do docblocku `search`.
- `search` vrací `[]` pro „nenalezeno“, výjimku pro ostatní chyby; unit
  testy obou větví (falešná odpověď).

## 5. Chyba JS (D101)

- Oprava a test, pokud ho jde napsat bez křehkosti; jinak stačí, že
  proklikání nehlásí `pageerror`.

## Kroky

1. D97 → commit `fix(dataset): proklikání jen proti lokální adrese serveru, připíchnutá adresa (#103 D97)`
2. D98 + D101 → commit `fix(frontend): rozhodnutí u bankovního účtu strany v kontrole, chyba po zavření popoveru (#103 D98, D101)`
3. D99 → commit `fix(frontend): vnořené výchozí hodnoty formuláře — osoba z kontroly s adresou (#103 D99)`
4. D100 → commit `fix(persons): neznámé IČO v registru jako prázdný výsledek (#103 D100)`
5. Běhy `shpd-ds dataset-e2e` nad třemi sadami (zdroje dat s účelem
   „korpus“, **ne evaluační**), výsledky do oddílu Nálezy tohoto tasku,
   `docs/test-corpus.md` § 19 (D97 v přípravě a pastech), `**Stav:**`
   tohoto tasku i `tasks/testing-phase7a.md`, `python3 scripts/tasks-index.py`
   → commit `docs: proklikání po opravách N1–N3, N6 (#103 F7a)`

## Hotovo když

- [ ] `dataset-e2e` s `--base-url` na cizí adresu odmítne před založením
      session (exit 2); akce v dashboardu s cizím `Origin` vrátí 400.
- [ ] Běh přes jméno serveru i přes IP projde, Chromium má připíchnutou
      adresu.
- [ ] Bankovní účet strany má v kontrole odznak a rozhodnutí; test
      pravidla „blokující cesta má ovládání“.
- [ ] Osoba založená z kontroly má adresu a zemi z dokladu.
- [ ] Neznámé IČO vrací prázdný výsledek, skutečná chyba registru dál 502.
- [ ] Proklikání `cz-vat-payer`, `cz-non-payer` a `cz-association`: N1,
      N2, N3 a N6 se neobjeví. Stav `ok`, nebo zbylé nálezy sepsané
      v oddílu Nálezy (neopravovat bez schválení v chatu).
- [ ] `vendor/bin/phpunit --filter` na dotčené testy, `npm test`
      a `npm run build` ve `frontend/`, `npm run check:i18n`,
      `python3 scripts/check-css-tokens.py`.
- [ ] Dokumentace, `**Stav:**` obou tasků, index tasků.

## Pasti

- **Evaluační zdroje dat nepoužívat** — přeseedování by rozbilo
  probíhající evaluaci; zámek úloh dashboardu CLI nehlídá.
- **`net_get_interfaces()` vrací i link-local a IPv6 s rozsahem** —
  porovnávat adresy bez `%scope`.
- **Pořadí karet (N5)** může po opravě N1 začít měnit výsledek proti F3
  (dodavatel vznikne z varianty 02). To je nález k sepsání, ne k opravě
  v tomto tasku.
- **Rozdíl liberal × strict** může po N1 ukázat další cesty (položky,
  jednotky) — stejně: sepsat.

## Nálezy

_(doplní implementace po bězích v kroku 5)_
