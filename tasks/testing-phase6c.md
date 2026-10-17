# Testování F6c: datová sada při zakládání zdroje dat a v seznamu zdrojů dat

**Stav:** naplánováno — design schválen 2026-10-17 (#103 D64–D67), čeká na implementaci

## Kontext / Cíl

Ve vývojářském dashboardu jde dnes datovou sadu dostat do zdroje dat
jen z panelu sady (F6b): akcí „Založit evaluační zdroj dat“ nebo
přeseedováním. Formulář „Nový zdroj dat“ nabízí jen zaškrtávátko pro
staré náhodné testovací osoby a poštu (`seed-persons`, `seed-mail`),
které po zavedení datových sad nedává smysl.

**Cíl:**

1. Ve formuláři „Nový zdroj dat“ jde vybrat datovou sadu. Zdroj dat
   vznikne rovnou s ní (D64).
2. V seznamu zdrojů dat přibude akce „Nahrát datovou sadu“ pro existující
   volný zdroj dat, včetně výměny jedné sady za jinou (D66).
3. Heslo admina z formuláře přestane jít přes příkazovou řádku (D65).
4. Staré seedy se zruší (D67).

## Návaznost

- **#103** D64–D67 (oddíl na konci); F6b D58–D63 (úlohy, `reseed`,
  pojistka `DevActionGuard`), D59 (pole `dataset`)
- **#126** — třída dat; `dataset-seed` nastavuje `free` a `dataset`
- `docs/dev/dev-daily.md` § 1, `docs/cli.md` (`ds-create`, `user-create`,
  `dataset-seed`, `seed-*`)

## Před implementací přečti

- `src/Api/Controller/DevDashboardController.php` — `dsCreate` (streamovaný
  průběh `ds-create` → `ds-upgrade` → `user-create` → seed) a `streamCommand`
- `src/Api/Controller/DevEvalController.php` — `reseed`, výběr zdrojů
  dat k přeseedování, `DevActionGuard`
- `frontend/src/dev/` — stránka Nový zdroj dat, seznam zdrojů dat, dialogy
- `src/Command/DataSource/UserCreateCommand.php`
- `docs/cli.md` → `ds-reset` (`keepOnReset`: uživatelé, relace,
  nastavení a API klíče reset přežijí)

## 1. Formulář „Nový zdroj dat“ (D64)

- Zaškrtávátko „seed“ nahradí výběr **Data**:
  - „žádná“ (výchozí),
  - datové sady z repa (`EvalCatalog` / seznam sad z F6a; titulek, země,
    profil).

  Bez checkoutu repa (`/_dev/api/info` → `testing: false`) výběr zmizí.
- **Země:** s vybranou sadou se pole země nastaví podle sady a zamkne.
  Kontrolu země dělá i `dataset-seed`.
- **Průběh** (dál živě streamovaný): `ds-create` → `ds-upgrade` →
  `user-create` → **`dataset-seed <sada> --yes`** (nový krok, jen
  s vybranou sadou). Selhání seedu = chyba kroku. Zdroj dat i admin
  zůstanou použitelné a hláška to řekne.
- Výsledek: odkaz na nový zdroj dat jako dnes. Se sadou je zdroj dat
  `free` a má pole `dataset`.
- **Účel:** když je vybraná sada a pole účel je prázdné, předvyplní se
  „datová sada <název>“.

## 2. Heslo bez příkazové řádky (D65)

- `user-create --password-stdin`: heslo se přečte z prvního řádku stdin.
  Konflikt s `--password` = chyba. Bez TTY a bez vstupu = chyba.
- Formulář pustí `user-create` s `--password-stdin` a heslo předá přes
  stdin (`proc_open` s rourou, varianta `streamCommand`). Heslo nesmí
  být v argv, ve výstupu ani v logu (dnešní redakce výstupu zůstává).
- `docs/cli.md`: `--password-stdin` doporučit místo `--password`.

## 3. „Nahrát datovou sadu“ v seznamu zdrojů dat (D66)

- Akce v řádku seznamu zdrojů dat **jen u třídy `free`**. U reálné kopie
  se akce nezobrazí a API ji stejně odmítne (`DevActionGuard`, 403).
- Dialog nabídne sady se zemí zdroje dat (ostatní neaktivní s důvodem)
  a ukáže dnešní sadu zdroje dat (pole `dataset` nebo „žádná“). Varování
  zní: „Reset smaže data zdroje dat. Uživatelé, nastavení a API klíče
  zůstanou.“ Potvrzení spustí úlohu `reseed` z F6b. Průběh je vidět
  v seznamu úloh a v řádku zdroje dat (běžící úloha).
- **Výměna sady:** API `reseed` dnes povolí jen zdroj dat bez sady nebo
  se stejnou sadou. Nově přijme i jinou sadu, ale jen s výslovným
  `replaceDataset: true` (dialog ho pošle po druhém potvrzení „Nahradit
  sadu X sadou Y“). Bez příznaku jiná sada = 409 s vysvětlením.
- Panel sady (F6b) se nemění.

## 4. Zrušení starých seedů (D67)

Odstranit příkazy `seed-persons`, `seed-mail`, `seed-clear`,
`seed-mail-clear` a s nimi:

- generátory `src/Core/Seed/FakePersonGenerator.php`,
  `modules/core/mail/src/FakeIncomingMessageGenerator.php`,
  `FakeMailboxGenerator.php` a `tests/Unit/Core/Seed/FakePersonGeneratorTest.php`;
- registraci v `DsApplicationFactory` a výpis v `HelpCommand`;
- zmínky v `docs/cli.md` a `modules/core/mail/docs/documentation.md`.

Před mazáním ověř grepem (`--include`), že je nic dalšího nepoužívá.
Výskyt v `tests/Unit/Api/Controller/LookupControllerTest.php` prověř:
pokud jde o jiné „fake“ (testovací dvojník), nemaž ho. Testovací data
pro vývoj = datové sady (`docs/test-corpus.md`).

## Kroky

1. `user-create --password-stdin` + testy; formulář přes stdin.
2. Formulář: výběr dat, země podle sady, krok `dataset-seed`, účel.
3. API `reseed` s `replaceDataset` + testy (jiná sada bez příznaku 409,
   s příznakem projde, `realCopy` 403).
4. UI: akce a dialog „Nahrát datovou sadu“.
5. Zrušení starých seedů.
6. Ověření (níže), dokumentace, hlavička tasku.

## Hotovo když

- [ ] `user-create --password-stdin` (testy); heslo z formuláře není v argv
      ani v logu (ověřit `ps` během kroku nebo testem příkazu)
- [ ] nový zdroj dat z formuláře se sadou `cz-non-payer`: země zamčená
      na `cz`, průběh včetně `dataset-seed`, zdroj dat je `free`, má
      `dataset`, admin se přihlásí
- [ ] „Nahrát datovou sadu“ na volném zdroji dat bez sady i s jinou sadou
      (druhé potvrzení); na reálné kopii akce není a API vrátí 403
      (testem, ne živě)
- [ ] staré seedy pryč, `shpd-ds help` je nevypisuje, testy zelené (úzké `--filter`
      pro dotčené oblasti)
- [ ] `docs/dev/dev-daily.md` § 1, `docs/cli.md` (`user-create`, odstraněné
      `seed-*`), `docs/test-corpus.md` (sada při zakládání a v seznamu)
- [ ] hlavička tasku, `python3 scripts/tasks-index.py`

## Pasti

- **Reset maže data zdroje dat.** Dialog to musí říct a výměna sady
  potřebuje druhé potvrzení.
- **Pojistka je na serveru** (`DevActionGuard`), UI ji jen doplňuje.
- **Heslo:** ani do `job.json`, ani do argv, ani do streamovaného výstupu.

## Rozhodnutí k designu (potvrzená)

- ✓ **D64 — Datová sada ve formuláři „Nový zdroj dat“:** výběr místo
  zaškrtávátka seedu, země podle sady, krok `dataset-seed` v průběhu.
- ✓ **D65 — Heslo admina přes stdin** (`user-create --password-stdin`),
  nikdy v příkazové řádce.
- ✓ **D66 — „Nahrát datovou sadu“ v seznamu zdrojů dat** pro `free`;
  výměna sady jen s výslovným potvrzením (`replaceDataset`).
- ✓ **D67 — Zrušení starých seedů** (`seed-persons`, `seed-mail`,
  `seed-clear`, `seed-mail-clear` a jejich generátory); testovací data
  pro vývoj jsou datové sady.
