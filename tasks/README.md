# Tasks — pracovní zadání pro Claude Code

Tato složka obsahuje zadání (PRD, task prompts) pro implementační práce.
Každý soubor je samostatný úkol pro jednu Claude Code session, případně
PRD popisující větší fázi rozdělenou na sub-tasky.

Tasky se po dokončení **nemažou** — slouží jako historický záznam designu
a rozhodnutí. Když narazíš na nesoulad mezi taskem a aktuálním stavem
kódu, věř kódu (kód je živý, task je momentka).

Níže je **kompletní index podle oblastí**. Řídící / designové dokumenty
jednotlivých subsystémů žijí v [`docs/`](../docs/README.md).

---

<!-- STAV:BEGIN — generováno scripts/tasks-index.py, needituj ručně -->

## Stav

Celkem 399 tasků: **naplánováno** 4 · **částečně** 26 · **hotovo** 369.

Zdroj pravdy je řádek `**Stav:**` v hlavičce každého tasku; tato
tabulka je generovaná (`scripts/tasks-index.py`). Hotové tasky se
nevypisují — níže je jen to, co není dokončené.

| Task | Stav | Poznámka |
|------|------|----------|
| `ai-profile-sync-in-ds-upgrade.md` | naplánováno | sync není v `DsUpgradeCommand` |
| `auth-phase0a-hardening.md` | naplánováno | rate limiting a evidence neúspěšných přihlášení chybí |
| `dashboard-alert-grouping.md` | naplánováno | design schválen 2026-07-16, neimplementováno |
| `migration-check.md` | naplánováno | návrh, čeká na schválení rozhodnutí M1–M7, pak implementace |
| `accbal-operation-first.md` | částečně | kód, testy a docs hotové 2026-09-22 (5 commitů); ověřeno na `btpg-p` po resetu + reimportu 2026-09-22 (rok 2026: 104 dluhů, přeplatky 0, uzávěrkové řádky 0, nastavení `legacy`; zbývajících 8 párů proti starému = proformy → #69 D21); zbývá `ds-upgrade` + reset/reimport a srovnání `e8w1-i` |
| `accbal-payment-side.md` | částečně | generátor, viewer, testy a docs hotové 2026-09-22 (3 commity, bez změny schématu); zbývá `btpg-p` po `old_shipard` task 41 + reimportu: `accbal-regenerate --all`, kontrola Přijaté zálohy 2026 (bankovní 324 jako předpisy, `bal_side` 0, +) a Poskytnuté zálohy ≈ starý 17 / 178 078, výsledek do #69 |
| `accbal-prefix-precedence.md` | částečně | generátor, testy a docs hotové 2026-09-22 (2 commity, bez změny schématu); lookup přednost neuplatňuje (skupinu nese `balance` v klíči, `docs/accbal.md` §5.1); zbývá `btpg-p`: přesun 325201/325202 do Přijatých záloh (nastavení, David) + `accbal-regenerate --all` + kontrola 13 / ≈86 a výsledek do #69 |
| `accbal-proforma-closure.md` | částečně | kód hotový 2026-09-23 (5 commitů, #79 D3b/D3c): |
| `accbal-proformas-out.md` | částečně | kód hotový 2026-09-23 (4 commity, #79 D2/D3a); |
| `ai-bedrock-eu.md` | částečně | kroky 1–5 hotové 2026-10-11 (5 commitů, celá sada 9 027 testů; `ds-upgrade` jen `4l3j`); ověření 1–7 s reálným klíčem odloženo: přihlášení klíčem i tvar chyb ověřeny, ale testovací účet AWS nemá přístup k žádnému modelu Anthropicu (403 „not available for this account“ i přímo v konzoli AWS) — řeší se na straně AWS; po zpřístupnění stačí `shpd-ds ai-test` a body 2–5, 7, výsledek do #85 |
| `cron-dev-install.md` | částečně | kód, testy, skripty a dokumentace hotové 2026-10-13 (4 commity podle PRD); ověřeno bez roota na vývojářském stroji (`cron-install --dry-run` vypsal dotčené zdroje dat, `--check` exit 1, `doctor` sekce Cron jako varování, `dev-update.sh` připomínka, `logrotate -d` nad pravidlem projde); zbývá člověk: čistá VM (bootstrap → zelený `doctor`, obnovující se heartbeat slotu `minute`), stroj se zdroji dat (první instalace všechny vypne a vypíše), `shellcheck` (na vývojářském stroji není) |
| `doc-number-release-on-data-save.md` | částečně | kód + testy hotové 2026-08-13; zbývá D2 (reset test DS), ruční proklik a read-only verifikace na alfě |
| `doc-proforma-out.md` | částečně | kód hotový 2026-09-23 (4 commity, #79 D1), `ds-upgrade` |
| `docs-import-party-snapshots.md` | částečně | kód, testy a docs hotové 2026-09-01; zbývá ověření po re-importu qrce (task 29 old_shipard, ops) — poslední dva body checklistu |
| `hosting-09-federated-login.md` | částečně | kód + testy + docs hotové 2026-08-14 (commity 1–3); zbývá ds-upgrade na hostingu, zapnutí Google/GitHub na alfě dle runbooku (mutace — David) a ruční E2E řetěz |
| `local-dev-bootstrap.md` | částečně | skripty, cloud-init a docs hotové 2026-10-03 (4 commity, #96 D17–D25); ověřeno na čisté Multipass VM 26.04 (aarch64) podle `docs/local-dev.md`: cloud-init bootstrap, opakovaný běh s `--with-render --with-ssh`; zbývá WSL na Windows (čeká na Windows build `remote-dev-bridge`) |
| `mail-import-partner-title.md` | částečně | implementace, testy a dokumentace hotové 2026-09-10 (5 commitů), backfill ověřen na dev DS; zbývá nasazení na alfu, re-import a SQL kontroly ze sekce „Ověření na alfě“ |
| `mail-message-centric.md` | částečně | Fáze A–D v tomto repozitáři hotové (schéma, /result v4, |
| `mail-preprocess.md` | částečně | Fáze 1 hotová (2026-08-29, 6 commitů), otevřený jen Bolt E2E na alfě (živý odkaz ze vzorku); Fáze 2 (`renderBodyToPdf`, `renderIfHtml`, aktivace Apple/Google) hotová 2026-08-29 — `tasks/mail-preprocess-phase2.md` |
| `mail-safety.md` | částečně | implementace, testy a docs hotové 2026-10-05 (3 commity); `drop` ověřen na dev serveru (CLI i proklik UI); zbývá ověřit `redirect` proti skutečnému SMTP a nastavit `mail.safety` na testovacím serveru (člověk), `ds-upgrade` zdrojů dat při nasazení |
| `prints-languages.md` | částečně | implementace hotová (4 commity); čeká na revizi formulací `sk` / `de` kolegy (sekce „Formulace k revizi“ na konci) a `ds-upgrade` zdrojů dat při nasazení |
| `prints-phase3.md` | částečně | kód, dokumentace a nápověda hotové 2026-10-05 (#90 D46–D52; podklad loga z D46 po prokliku zrušen); ověřeno na ukázkovém zdroji včetně ručního prokliku nastavení a výsledného tisku, `ds-upgrade` jen tam; zbývá nasazení na alfu (`ds-upgrade` všech zdrojů — bez něj se tiskne bez textů a s výchozím vzhledem) a komentář do #90 |
| `prints-phase4.md` | částečně | implementace hotová (7 commitů); zbývá ověření skutečného odeslání přes SMTP (dev server nemá relay), ruční proklik dialogu a náhledů PDF, `ds-upgrade` zdrojů dat při nasazení a revize `sk` / `de` textů e-mailu (`tasks/prints-languages.md`) |
| `taxes-phase01.md` | částečně | implementace kompletní (mapping, jádro reportů s periodSource vatPeriod + text/date sloupci, kalkulátory, buildery, UI, testy); zbývá ds-upgrade + proklik na dev DS a read-only ověření na alfě dle „Hotovo když" |
| `testing-phase3.md` | částečně | implementace hotová 2026-10-15 (nálezy A–E opravené, D33); kontroly 1–5 `ok` u plátce a neplátce, snímky schválené s výhradou účetní revize (#123); snímek spolku schválený 2026-10-15 po #122 (kontrola 5 `ok` u všech tří sad); zbývá alfa |
| `vat-cs-mode.md` | částečně | shpd hotové 2026-09-11 (4 commity: sloupec + kalkulátor, formuláře |
| `vat-filing-xml.md` | částečně | implementace hotová 2026-09-10 (commity 1–7), nálezy prvního běhu |
| `vat-filings-import.md` | částečně | kód, testy, CLI, UI a docs hotové 2026-09-13 (4 commity); |
| `vat-period-lock.md` | částečně | shpd hotové 2026-09-11 (6 commitů: jádro lock providerů, zámek instance, |
| `vat-report-periods.md` | částečně | implementace kompletní (5 commitů 2026-09-03: tabulka + eventy jádra, přiřazení + on-demand, reporty nad instancemi, cron + zrušení `vat_periods`, docs); E2E na dev DS prošlo (uložení dokladu → koncepty, reporty `--period`, alert, guard, přepočet, ruční přesun). Zbývá ruční proklik UI (picker, viewer Daňová tvrzení, selecty na dokladu) a ověření po re-importu qrce (task 30) — poslední bod „Hotovo když“. |

<!-- STAV:END -->

## Server, CLI a provoz

Nasazení, oprávnění, správa datových zdrojů, dev workflow, logování,
šifrování. Centrální CLI reference v [`docs/cli.md`](../docs/cli.md).

| Task | Co řeší |
|------|---------|
| `server-setup-permissions.md` | Server setup, módy (development/production), model oprávnění a uživatelů |
| `server-setup-doctor-improvements.md` | `doctor`: nginx/FPM kontroly + fix install skriptu |
| `install-for-developers.md` | `DEVELOPERS.md` + `scripts/install-packages.sh` |
| `dev-install-fixes.md` | Instalace na čistém Ubuntu 24.04 / 26.04 — opravy `install-packages.sh` a `DEVELOPERS.md` (#96 D5, D9) |
| `dev-install-home-traverse.md` | Průchod nginx ke checkoutu v domovském adresáři (`HOME_MODE 0750`) — install skript, `doctor`, `fix-permissions` (#96 D11) |
| `dev-small-fixes.md` | Drobnosti z ověření lokální instalace — administrátor z dev dashboardu, adresáře DS s právy podle kontraktu (`PermissionSpec::ensureDsDir`), `composer.lock` (#96 D13, D14) |
| `dev-install-followups.md` | Práva logu podle kontraktu (`ErrorLogger`: adresář `0750`, `shipard.log` `0640`) a Ubuntu 26.04 jako doporučená verze pro nové instalace (#96 D15, D16) |
| `local-dev-bootstrap.md` | Lokální vývoj na macOS a Windows — `scripts/dev-bootstrap.sh`, cloud-init pro Multipass, WSL, `docs/local-dev.md` (#96 D17–D25) |
| `claude-code-settings.md` | Sdílené `.claude/settings.json` — potvrzení odeslání (`git push`, `gh pr create`, `gh pr merge`) a zákaz čtení konfigurace a secrets zdrojů dat v každém režimu Claude Code (#96 D27, D30) |
| `dev-docs-intro.md` | Dokumentace pro vývojáře — `DEVELOPERS.md` jako úvod a rozcestník, návody v `docs/dev/` (ruční instalace na Linuxu, každodenní práce), `docs/README.md` podle čtenáře (#108 D1–D9) |
| `dev-update-script.md` | `scripts/dev-update.sh` + git hooks (post-pull workflow) |
| `ds-upgrade-all.md` | `shpd-server ds-upgrade-all` + kompletní `docs/cli.md` |
| `ds-upgrade-quiet-default.md` | Tichý default `ds-upgrade`, plný výpis jen s `-v` |
| `ds-upgrade-skip-provisioning.md` | Vypnutelný provisioning přes `config/main.json` |
| `ds-create-install-module.md` | `ds-create --module` + UI výběr instalačního modulu |
| `ds-reset.md` | `ds-reset` — čistý stav DS pro opakované testování importů |
| `cron-ds-switch.md` | Přepínač periodických úloh per zdroj dat: klíč `cron` v `state.json`, `ds-state cron`, `ds-create --no-cron`, `cron --ds`, `shpd-server ds-cron`, `doctor`, pruh v aplikaci (#115 fáze 1) |
| `ds-state-file-ownership.md` | `state.json` zapsaný pod rootem patří vlastníkovi zdroje dat (`DataSourceState::save`), soubor v kontraktu práv (`PermissionSpec`) — jinak se zdroj dat fail-closed zavře (#56, #115) |
| `cron-module-aware.md` | Dispatcher periodických úloh pouští úlohu jen na zdroji dat s aktivním modulem (mapa úloha → modul v `CronCommand`), výpis vynechaných úloh u `cron --ds` (#115 D15) |
| `hosting-sync-main-json-owner.md` | `main.json` přepsaný agentem `hosting-sync` pod rootem patří vlastníkovi zdroje dat (`HostingSyncRunner::patchAuthProvider`, `FileOwnership`) |
| `cron-dev-install.md` | Cron ve vývojové instalaci: první `cron-install` mimo produkci vypne cron existujícím zdrojům dat, `cron-install --check`, `doctor` mimo produkci, `dev-bootstrap.sh`, `dev-update.sh`, rotace logů, návody (#115 fáze 2) |
| `ds-data-class.md` | Třída dat zdroje dat (`free` / `realCopy`, účel, původ) v `main.json`, `shpd-ds ds-class`, `shpd-server ds-list`, serverové AI podle třídy, třída v dotazu `ds-reset` a pruh „Kopie reálných dat“ (#126 D6–D12) |
| `ds-secrets-split.md` | Tajemství zdroje dat z `main.json` do `secrets/` (heslo k databázi, relay pošty, OIDC `clientSecret`), přechodné čtení, migrace v `ds-upgrade`, `shpd-ds ds-secret`, sekce `doctor` (#126 B1, D1–D4) |
| `unified-logging.md` | Produkční `ErrorLogger` (JSON řádky, úrovně, deploy guide, migrace `error_log()`) |
| `ds-encrypted-secrets.md` | Šifrování citlivých sloupců (`encrypted_text`, per-DS klíč, rotace) |
| `secrets-openssl-cipher.md` | AES-256-GCM přes OpenSSL místo libsodium — podpora ARM, formát beze změny (#96 D12) |
| `add-user.md` | CLI `shpd-ds user-create` |
| `api-key-cli.md` | CLI příkazy pro správu API klíčů |

## Moduly třetích stran

Podpora modulů mimo hlavní strom — `ModulePathResolver`, `extraModulesPath`
v `server.json`, autoloader, alokace `tableId` napříč rooty.

| Task | Fáze | Co řeší |
|------|------|---------|
| `third-party-modules-phase1.md` | 1 | `ModulePathResolver` |
| `third-party-modules-phase2.md` | 2 | Refactor callsites na `ModulePathResolver` |
| `third-party-modules-phase3a.md` | 3a | Čtení `extraModulesPath` ze `server.json` |
| `third-party-modules-phase3b.md` | 3b | Autoloader custom module tříd |
| `third-party-modules-phase4.md` | 4 | `next-table-id` napříč rooty + `--range` |
| `third-party-modules-phase5.md` | 5 | Dokumentace (finální) |

## Frontend — shell, navigace, viewery

| Task | Co řeší |
|------|---------|
| `frontend-phase1-tasks.md` | Skeleton SPA, routing, layout, login |
| `frontend-phase1-viewer.md` | `TableViewer` komponenta |
| `frontend-phase3-app-sidebar.md` | Dynamická navigace generovaná ze serveru |
| `frontend-phase5-viewers.md` | Viewer systém (formátované řádky, fulltext, infinite scroll) |
| `viewer-row-icons-and-numbers.md` | Ikony a pořadová čísla v řádcích vieweru |
| `viewer-number-series-tabs.md` | Spodní taby číselných řad v per-type doc viewerech |
| `viewer-bottom-tabs.md` | Obecné spodní taby vieweru (`getBottomTabs`, `filter[bottomTab]`, výchozí hodnoty nového záznamu per tab) — nahrazuje vazbu na číselné řady |
| `sidebar-sections.md` | Sémantické sekce sidebaru (Nákup/Prodej/Účtárna) přes `navSection` |
| `sidebar-collapsed-icons.md` | Ikony položek ve sbaleném sidebaru (48 px) |

## Frontend — i18n

| Task | Fáze | Co řeší |
|------|------|---------|
| `frontend-i18n-phase1a.md` | 1A | Kostra vícejazyčnosti |
| `frontend-i18n-phase1b.md` | 1B | Překlad UI chrome + LoginScreen |
| `frontend-i18n-phase1c.md` | 1C | Backend i18n — lokalizace server-driven labelů |

## Editační formuláře

Server-driven formuláře, generický klient. Reference
[`docs/edit-forms.md`](../docs/edit-forms.md) + [`edit-forms-cookbook.md`](../docs/edit-forms-cookbook.md).

| Task | Co řeší |
|------|---------|
| `edit-forms-phase1.md` | Backend jádro (`FormController`, `FormDefinition`, `AutoFormBuilder`) |
| `edit-forms-phase2.md` | JSONC loader, PersonsForm, sub-formy, recalculate hook |
| `edit-forms-phase3.md` | Frontend (`FormRenderer`, `FormDialog`) |
| `frontend-phase4-forms.md` | Frontend formuláře generované z metadat |
| `new-forms-01.md` | Nový layout system (sekce, sloupce, label-left, auto-šířka labelů) |
| `form-builder-hardening.md` | Whitelist `inputType`, dedikované buildery (`addTextArea`, `addDate`, …) |
| `form-header-info.md` | Strukturované info v hlavičce formuláře (`HeaderInfo`) |
| `form-lookup-fields.md` | Typeahead lookup pro FK na velké tabulky |
| `form-modal-unified-size.md` | Sjednocení velikosti editačních modalů |
| `form-validation-errors.md` | Zobrazení a kontrakt validačních hlášek |
| `persons-form-restructure.md` | Restrukturalizace formuláře osob |
| `items-form-restructure.md` | Restrukturalizace formuláře položek |
| `subtable-phase1.md` | Sub-tabulky: sloupce ze serveru, read-only prohlížení, `ConfirmDialog`, filtr (issue #53) |
| `subtable-phase2.md` | Dialog sub-záznamu: Přidat / Přidat a pokračovat, šipky Předchozí/Další |
| `subtable-phase3.md` | Přesun řádků sub-tabulky (`/move`) a automatické `order_pos` |
| `subtable-delete-gateway.md` | Mazání řádku sub-tabulky přes endpoint formuláře `…/delete` a `TableGateway` — přepočet hlavičky dokladu, zámky, guard rodiče (#113 bod 2) |

## Nastavení aplikace a vlastní vzhledy

Režim Nastavení (`docs/app-settings.md`) + custom vzhled sidebaru.

| Task | Co řeší |
|------|---------|
| `frontend-settings-app.md` | Režim Nastavení — číselníky jako settings |
| `app-settings-pages.md` | Settings pages + branding (název, ikona, logo) |
| `settings-subsections.md` | Dvouúrovňové sekce v Nastavení + přesun položek |
| `custom-theme-phase1.md` | Custom téma sidebaru (presety, color picker, OKLCH, per-DS) |
| `custom-theme-phase2.md` | Gradienty, opacity slider, stránkování presetů |
| `custom-theme-phase3.md` | Per-user persistence + režim Nastavení účtu |
| `custom-theme-phase4.md` | DS-wide default vzhledu + `follow` flag |

## Mobilní (responzivní) UI

Responzivní design pro telefon (~380px).

| Task | Fáze | Co řeší |
|------|------|---------|
| `mobile-app-chrome-phase1.md` | 1 | Drawer sidebar + top bar |
| `mobile-viewer-phase2.md` | 2 | List/detail přepínání + akce v top baru |
| `mobile-forms-phase3a.md` | 3a | Editační modál fullscreen |
| `mobile-forms-phase3b.md` | 3b | Inline skupiny pod sebe |
| `mobile-forms-phase3c.md` | 3c | Footer kebab pro vedlejší akce |

## Osoby a číselníky

Základ pro dokladový systém — osoby, měna/země, položky, období.

| Task | Co řeší |
|------|---------|
| `persons-is-own-extension.md` | `base.persons`: `is_own` (vlastní firma) + obchodní rejstřík |
| `world-vat-cz.md` | Modul `world.vat` — CZ DPH kódy a procenta |
| `economy-items-phase1.md` | Položky + měrné jednotky |
| `economy-cash-and-bank.md` | Pokladny + vlastní bankovní spojení |
| `economy-fiscal-periods.md` | Fiskální období (roky/měsíce) |
| `economy-vat-periods.md` | Období DPH (registrace + období) |
| `codebooks-currency-select.md` | Roletky měny/země + sdílený `EnumOptionsHelper` |

## Dokladový systém (doklady, faktury)

Polymorfní jádro `docs.core` + per-typ faktury. Řídící dokument
[`docs/docs-mvp.md`](../docs/docs-mvp.md).

| Task | Co řeší |
|------|---------|
| `docs-core-phase1.md` | Skeleton `docs.core` (tabulky, cfgItem, číselné řady) |
| `docs-core-phase2.md` | Výpočty cen/DPH, rekapitulace, snapshoty, atomické číslo |
| `docs-core-phase3.md` | Formulář a viewer dokladu |
| `docs-invoices.md` | Per-typ moduly `docs.invoicesOut` / `docs.invoicesIn` |
| `docs-invoices-split-forms.md` | Rozdělení editačního formuláře na per-typ varianty |
| `docs-detail-document.md` | Detail jako „textová faktura" (content type `document`) |
| `docs-source-mail-attachments.md` | Přílohy navázaných došlých zpráv v detailu dokladu |
| `docs-payment-reference-rename.md` | `variable_symbol` → `payment_reference` |
| `doc-states-main-persistence.md` | Centralizace dopočtu `docStateMain` do persistenční vrstvy |
| `doc-author-users.md` | Autor dokladu („Vystavil“), `created_by` v gateway, uživatel ↔ Osoba, import uživatelů (#93 D1–D13) |
| `number-series-engine.md` | Společný engine čísel (`src/Core/Numbering/`) vytažený z číselných řad dokladů — vzorec, čítač, import, uvolnění; předpoklad číselných řad zakázek (#110 D17, N1–N3) |
| `exchange-rates-core.md` | Kurzy cizích měn, task A: typy lístků `world.cz`, formát `shpd.exchangeRates.list.v1`, modul `economy.exchangeRates` (lístky, importer, resolver), CLI, kurz v dokladech, applieru a bance, datové sady (#121 D1, D4–D7, D10) |
| `exchange-rates-download.md` | Kurzy cizích měn, task B: stahování lístků ze služby (`server.json`, okno od předchozího fiskálního roku, `five-minutes`, limit a pauza), CLI `exchange-rates-download`, upozornění na chybějící lístek, neznámá měna při importu přeskočena (#121 D2, D3, D9, U1–U7) |

## Tisky (doména print)

PDF nad jedním záznamem — doklady, karta majetku, … Design issue #90,
referenční dokument `docs/prints.md` (od Fáze 1).

| Task | Fáze | Co řeší |
|------|------|---------|
| `prints-phase1.md` | 0 + 1 | Kontrakt `PrintData`, deklarace `prints`, registr a runner, Twig v sandboxu, SPAYD, faktura vydaná a zálohová, REST `/_prints`, akce Tisk v detailu, CLI `print-run` (#90 D1–D21) |
| `prints-phase2.md` | 2 | Nástroje pro šablony (`print-run --format=html`, `--data`), vodoznak storna, strany bez partnera, pokladní doklad, prodejka, Kontace (#90 D22–D28) |
| `prints-languages.md` | 2b | Jazyky tisku `sk` a `de`, formátování přes intl, tiskové zkratky jednotek, přepínač jazyka v náhledu (#90 D29–D33, navazuje na #94) |
| `prints-phase4.md` | 4 | Odesílání e-mailem: účely na kontaktech, resolver příjemců a odesílatele, Odeslaná pošta nad libovolnou tabulkou (pevný obsah, Odeslat znovu), dialog Odeslat (#90 D34–D45) |
| `persons-document-options.md` | — | Jazyk dokumentů na osobě (odvození podle země, živě), spojování příloh, příznak přílohy „odeslat s dokladem“ (#94 D1–D11) |

## Výměnný formát a import ze starého Shipardu

Kanonický `shpd.docs.document.v1` + resolvery + apply pipeline; import
historických dat. Reference [`docs/exchange-format.md`](../docs/exchange-format.md).

| Task | Co řeší |
|------|---------|
| `exchange-format-phase1.md` | Core modul + apply pipeline (doklady) |
| `exchange-format-phase2.md` | Napojení AI analyzeru |
| `exchange-format-phase3a.md` | Vizualizace canonical + PDF split-view |
| `exchange-format-phase3b.md` | Interakce s `_resolve` |
| `exchange-resolve-decision-ui.md` | Rebuild rozhodování canCreate/ambiguous/notFound + smart totals |
| `exchange-row-text.md` | Text řádku z kanonického formátu — jeden helper pro applier, náhled a porovnávání; prompt v4.4.0 (#84) |
| `exchange-preview-matched-item.md` | Review přijaté faktury: napárovaná položka viditelně v řádku, změna napárování, účet podle položky, přepis mapování kódu dodavatele (#111) |
| `exchange-preview-matched-item-fixes.md` | Doplněk k #111: vynechaný řádek mimo součty náhledu, automatické napárování s názvem v panelu, oprava helpu |
| `exchange-contact-name.md` | Kontaktní osoba strany dokladu (`contact.name`) — schéma, ISDOC, náhled; prompt v4.5.0 |
| `exchange-received-reverse-charge.md` | Přenesení daňové povinnosti u přijatých dokladů — kód DPH odvozený ze signálů AI, registrace z naší DPH, přepočítaná rekapitulace; prompt v4.6.0 (#86) |
| `exchange-received-vat-place.md` | Místo plnění přijatého dokladu podle DIČ dodavatele (`world.trade.unions`), ne podle adresy; prompt v4.6.1 (#86) |
| `exchange-received-non-vat-payer.md` | Přijatý doklad u neplátce DPH — Bez DPH s daní dodavatele v cenách řádků, plátcovství k datu, ceny s daní z ISDOC (#97) |
| `exchange-format-persons-phase1.md` | Výměnný formát osob (`shpd.persons.person.v1`) |
| `exchange-format-items-phase1.md` | Výměnný formát položek (`shpd.items.item.v1`) |
| `docs-import-number-mode.md` | Import-mód čísla dokladu + fix validace bank. spojení |
| `docs-import-series-states.md` | Výběr číselné řady + cílové stavy 40/30 při importu |
| `mail-phase4-import-endpoint.md` | Import endpoint pro importer ze starého Shipardu |
| `mail-sent-import.md` | Import Odeslané pošty — `POST /_mail/sent/import` (zpráva a přílohy atomicky), `send_trigger = import`, `import_ref`, stav transportu Nezjištěno, bez Odeslat znovu (#104 D1–D5) |
| `einvoice-phase1.md` | Strukturovaná e-faktura, fáze 1: generátor ISDOC z canonicalu (`IsdocWriter`), XSD 6.0.1 / 6.0.2 a validace, UUIDv5, rozšíření čtečky o přenesení daňové povinnosti, CLI `shpd-server einvoice-render`, soubory ISDOC a round-trip v korpusu, ISDOC jen mezi českými subjekty s IČO (#119 D1–D10) |
| `einvoice-reader-reverse-charge.md` | Čtečka ISDOC: `vat.reverseCharge: false` jen u plnění se stejnou zemí dodavatele a odběratele, jinak `null` — došlý ISDOC od dodavatele z EU nesmí zablokovat kód DPH z historie (#119, oprava fáze 1) |

## Účetnictví

Automatické účtování dokladů ([`docs/accounting.md`](../docs/accounting.md)),
účtový rozvrh a účetní doklady `cmnbkp`.

| Task | Co řeší |
|------|---------|
| `economy-accounting-accounts.md` | Účtový rozvrh (modul `economy.accounting`, Fáze 1) |
| `account-chart-none-variant.md` | `accountChart: "none"` — přeskočení seedu standardní osnovy |
| `accounting-phase1.md` | Pohyby a `_dom` sloupce |
| `accounting-phase2.md` | Deník + účtovací engine |
| `accounting-phase3.md` | UI účtování |
| `accounting-vat-analytics.md` | DPH analytiky per `vatCode` |
| `accounting-rules-chart-variants.md` | Účetní předpisy podle varianty osnovy — přepisy masek pro NNO (`chartOverrides`), bankovní poplatky jako samostatná analytika (#122) |
| `accounting-docs-phase1.md` | `cmnbkp` — schéma řádků + typ dokladu |
| `accounting-docs-phase2.md` | `cmnbkp` — účetní backend (engine + předpis + subclass) |
| `accounting-docs-phase2-balance-ops.md` | `cmnbkp` — saldokontní operace (operation-default účet) |
| `accounting-docs-phase3.md` | `cmnbkp` — UI (viewer, form, sekce Účtárna) |
| `accounting-docs-phase4-import.md` | `cmnbkp` — exchange + applier (import ze starého Shipardu) |
| `reports-export.md` | Export všech reportů do XLSX / CSV (OpenSpout): REST `format`, tlačítko Export, CLI `report-run --format` (#83 D72) |
| `dimensions-core.md` | Standardní dimenze v jádru — majetek a středisko: sloupce v tabulkách jádra, deklarace v `economy.accounting`, nastavení „Dimenze na dokladech“, dimenze ve výměnném formátu (#110 D20, D21, D23) |

## Banka

Modul `economy.bank`. Referenční spec [`docs/bank.md`](../docs/bank.md).

| Task | Fáze | Co řeší |
|------|------|---------|
| `bank-phase1.md` | 1 | Datový model + generalizace deníku |
| `bank-phase2.md` | 2 | Import výpisu ze souboru + deduplikace |
| `bank-phase3.md` | 3 | Účetní mikroengine + UI účtování |
| `bank-phase4.md` | 4 | Výměnný formát + applier pro migraci výpisů |

## Saldokonto (economy.accbal)

Saldokonto nad účetním deníkem. Designový dokument
[`docs/accbal.md`](../docs/accbal.md).

| Task | Fáze | Co řeší |
|------|------|---------|
| `accbal-phase0-payment-identity.md` | 0 | Platební identita v účetním deníku |
| `accbal-phase1-settings.md` | 1 | Nastavení saldokont (skupiny + účty, seed + provisioner) |
| `accbal-phase2a-journal-event.md` | 2a | Core událost `journalWritten` |
| `accbal-phase2b-ledger-generator.md` | 2b | Generátor saldo pohybů z deníku + allocations |
| `accbal-phase3-matcher.md` | 3 | Matcher — párování úhrad (FIFO alokace, ruční úprava) |
| `accbal-clearing-infrastructure.md` | — | Clearing účty + saldo skupina pro migrovaný DS |
| `accbal-ledger-viewgroup-chips.md` | — | Saldo pohyby: chip bar saldokont místo roletky (viewGroups z dat) |
| `accbal-nav-items.md` | — | Saldokonta v sidebaru — navigation providery + `show_in_navigation` |
| `accbal-ledger-grid.md` | — | Saldo pohyby: grid layout se skupinami per partner + footer v HC |
| `bank-payment-routing.md` | #69 T1 | Účet úhrady dohledáním otevřeného předpisu (D3), přeúčtování clearingu po vzniku předpisu (D4), matched operace pryč |
| `bank-payment-routing-accounts.md` | #69 T1 oprava | Lookup otevřeného předpisu přes všechny předpisové účty skupiny (336, 331, 315…), ne jen 311/321 |
| `accbal-symbol-key.md` | #69 T2 | Případ = párovací klíč (agregát z ledgeru), odstranění alokační vrstvy a matcheru, viewer po případech, přepis `accbal.md` |
| `viewer-filter-defaults-fiscal-year.md` | — | Výchozí hodnoty filtrů ve frameworku; období (fiskální rok) v saldokontu a deníku s výchozím aktuálním rokem |
| `accbal-ledger-identity-key.md` | #69 D13 | Pohyb ledgeru per platební identita řádku (partner, VS, SS, měna), ne per doklad; `movement_key`; CLI `accbal-regenerate` |
| `doc-partner-balance.md` | #72 D1–D6 | Osoba pro saldokonto na hlavičce (plátce); číselníky platebních terminálů/bran a způsobů dopravy; karta/brána/dobírka → 311 za plátcem místo 261400; 315 v saldokontu |
| `accbal-operation-first.md` | #69 D17–D20 | Skupina pohybu z operace řádku, dobropis na druhou stranu jen ze seedu (`legacy` varianta pro import), lookup podle směru platby (vratky), uzávěrkové období mimo ledger; `fiscal_period_type` |
| `accbal-prefix-precedence.md` | #69 D22 | Nejdelší shodný prefix účtu vyhrává (per strana účtu) v generátoru i lookupu; podúčty do jiné skupiny per DS (325.2xx = přijaté zálohy) |
| `accbal-payment-side.md` | #69 D23 | `payment.*` = předpis/úhrada podle strany řádku proti předpisové straně skupiny (bankovní záloha na 314/324 je předpis), viewer popisuje bankovní pohyb jako platbu |
| `doc-proforma-out.md` | #79 D1 | Zálohová faktura vydaná (`invpo`): nový typ dokladu a modul `docs.proformasOut`, atribut `tax_document` — DPH jen informativně, bez DUZP a období DPH, kanonický typ `proformaIssued` |
| `accbal-proformas-out.md` | #79 D2, D3a | Proforma na podrozvahu `756100/799100` (účty, povaha 75–79, provisioner), předpis `invpo`, skupina saldokonta `proformas_out`, úhrada nalezená v ní se účtuje na přijatou zálohu (`payment_category`) |
| `accbal-proforma-closure.md` | #79 D3b, D3c | Core rozhraní `JournalContributor` volané oběma enginy před zápisem deníku; `CaseClosureContributor` uzavře případ proformy (`799/756`) úhradou na 324 z banky i pokladny, kurzem proformy, do výše rezidua; reroute pokladní platby dřív než proforma |
| `doc-proforma-in.md` | #106 D1, D2 | Zálohová faktura přijatá (`invpi`): typ dokladu a modul `docs.proformasIn`, báze přijatých faktur v `docs.core`, podrozvaha `799100/757100`, skupina saldokonta `proformas_in`, úhrada jako poskytnutá záloha a uzavření stávajícím contributorem |

## Majetek (economy.assets)

Evidence majetku, odpisy a jejich zaúčtování. Designový dokument
[`docs/assets.md`](../docs/assets.md), issue #83.

| Task | Fáze | Co řeší |
|------|------|---------|
| `assets-phase1.md` | 1 | Karta majetku, typy, skupiny typů, účetní skupiny, inventární čísla, sekce Majetek (D18–D26) |
| `assets-phase2a.md` | 2a | Pravidla daňových odpisů per stát (`world.assets`, CZ vč. §30a) a čistý odpisový engine `DepreciationPlanner` (D27–D45) |
| `assets-phase2b.md` | 2b | Tabulka událostí, odpisové nastavení karty, plán na kartě, vyřazení s polovinou, Odpisy za období, četnost účetních odpisů, krátké zdaňovací období (D27–D46) |
| `assets-phase3.md` | 3 | Zaúčtování majetku: obecné dimenze deníku (`asset`), operace `asset.*`, jeden doklad za období, zrušení zaúčtování, řada dokladů, zrušené vyřazení (D47–D56) |
| `assets-phase4.md` | 4 | Vazba na doklady: nastavení Sledovat náklady na majetek, dimenze na hlavičce a řádcích dokladů, karta na řádku pořízení a její založení z řádku, zařazení z pořízení, tab Náklady a výnosy, alerty, validace účetní skupiny a data událostí (D57–D64) |
| `assets-phase5.md` | 5 | Přehledy a kontroly: hromadné načítání plánů, sestava odpisů, přírůstky a úbytky, daňové odpisy pro DPPO, kontrola evidence × deník s alerty, soupis majetku, drill-down (D65–D72) |
| `assets-phase6.md` | 6 | Import — nový Shipard: výměnný formát karty s historií `shpd.assets.asset.v1`, importované události zaúčtované mimo modul, karta na importovaných dokladech, `assets-import-verify`, účet pořízení v kontrole jen varováním (D73–D82) |
| `assets-import-fixes.md` | 6+ | Opravy po prvním ostrém importu: varování plánu při uplynulé účetní době, kontrola a ověření bez neotevřeného roku a let bez vazby, `doc-links` pro řádky bez účtu, nulový účetní odpis v importu (D83–D86) |

## Zakázky (economy.workOrders)

Evidence zakázek, periodická fakturace a zakázka jako dimenze deníku.
Designový dokument [`docs/work-orders.md`](../docs/work-orders.md),
issue #110. Předpoklady: `dimensions-core.md` (Účetnictví),
`number-series-engine.md` (Dokladový systém).

| Task | Fáze | Co řeší |
|------|------|---------|
| `work-orders-phase1.md` | 1 | Modul `economy.workOrders`: druhy s typem zakázky, číselné řady nad společným enginem čísel, hlavička všech typů, stavy Ukončeno / Zrušeno, nadřazená zakázka, dimenze zakázka na dokladech, detail s Deníkem (#110 D14–D18, D22, D23) |
| `work-orders-phase2.md` | 2 | Periodická fakturace: předpis na druhu a zakázce, řádky s platností, evidence období, denní běh a CLI s náhledem, koncept přes výměnný formát, Přegenerovat (`replaceConcept`) a Obnovit, záložka Fakturace, rozhraní přispěvatelů, upozornění (#110 D2–D7, D10–D12, D24; Q1–Q7) |
| `work-orders-rows-readonly.md` | 2 | Oprava: řádky předpisu se řídí stavem zakázky (bez `independentRows` — V pořádku jen ke čtení, Přidat / Smazat v Konceptu a V opravě), backend guard `parent_read_only` |
| `work-orders-import.md` | — | Výměnný formát zakázky `shpd.workOrders.workOrder.v1` (hlavička, předpis, řádky, *fakturovat od*, cílový stav, převzaté číslo) a endpointy validate / apply pro import ze starého Shipardu (#110 D9, D25, D26) |

## Došlá pošta (core.mail)

Evidence → API endpoint → AI analýza do dokladů. Kontrakt endpointu
[`docs/mail/api-contract.md`](../docs/mail/api-contract.md).

| Task | Co řeší |
|------|---------|
| `mail-phase1.md` | Evidence došlé pošty (tabulky, viewer, editor, fake data) |
| `mail-phase2a.md` | API endpoint `/_mail/incoming` (idempotency, auto-provisioning) |
| `mail-phase3a.md` | AI analýza (shpd strana): backendy, profily, claims, extrahované doklady |
| `mail-states-and-classification.md` | Oddělení `analysis_state` od `docState` + AI klasifikace `primary_type`, karta „Není faktura“ |
| `mail-isdoc-import.md` | Deterministický import ISDOC příloh místo AI analýzy |
| `mail-isdoc-content-tags.md` | Obsahové štítky u ISDOC importu: `enrichAtResult` + `content_tag`, import v runneru předzpracování, inline jen detekce (#81) |
| `content-tag-row-exceptions.md` | Obsahové štítky: karty Položky k založení i ze řádkových výjimek (`rowExceptions`), pravidlo dodavatele se neučí z dokladu s výjimkami |
| `mail-message-title-partner.md` | Partner dokumentu (`partner_person`/`partner_name`) a titulek zprávy z AI (`ai_title`) — zobrazení v seznamu, fulltext, ruční partner má přednost (#43) |
| `mail-import-partner-title.md` | Partner a titulek u zpráv navázaných na doklad (import, Použít před #43) — odvození při `POST /_mail/import` + backfill `mail-target-backfill` (#43 D6) |
| `mail-analysis-schema-fixes.md` | Opravy AI analýzy: schema_error (kind/vat/courtRegistration), prompt v2.3.0, frontování dle docState + data fix |
| `mail-config-viewers.md` | Viewery a formuláře pro mailové konfigurační tabulky |
| `mail-invoice-rounding.md` | Zaokrouhlení celkové částky faktur: derivace `total_rounding_mode` v applieru, rounding-aware validace součtů, módy nahoru/dolů, prompt v3.1.0 |
| `mail-analysis-error-messages.md` | Srozumitelné hlášky selhané AI analýzy — katalog + helper, tab Návrh / Analýzy, Dashboard, chytřejší „Znovu analyzovat“ |
| `mail-preprocess-error-messages.md` | Srozumitelné hlášky selhaného předzpracování — kódy selhání akcí, katalog + helper, karta v Obsahu, upozornění v Návrhu a na Dashboardu |
| `ai-profile-reload.md` | CLI `ai-profile-reload` — reload promptu/schématu profilu z JSONC |
| `ai-profile-sync-in-ds-upgrade.md` | Automatický sync AI profilu ze šablony v rámci `ds-upgrade` (upgrade-only) |
| `enrichment-row-text-candidates.md` | Enrichment řádků z historie: matchování přes více kandidátních textů (description → item.description → item.name, tier-major) |
| `enrichment-dominant-item.md` | Enrichment řádků z historie: úroveň „dominantní položka dodavatele“ (statistika bez textu, confidence low, guard přes částku) |
| `mail-safety.md` | Pojistka odchozí pošty na dev a testovacích serverech: `mail.safety` v `server.json` (redirect / allowlist / drop), stopa ve frontě a v Odeslané poště, `doctor` (#95) |
| `mail-sender-rules-after-analysis.md` | Pravidla odesílatelů: dispozice `archiveIfOther` (archiv až po analýze, jen zprávy bez dokladu — `PostAnalysisDisposer`), pojistka učení u smíšených odesílatelů, potvrzení pravidla odklidí čekající Ostatní |
| `mail-other-attention.md` | Ostatní pošta k vyřízení: prompt v4.7.0 vrací pozornost (`action` / `info` / `promo`), poznámku, lhůtu a protistranu u zprávy bez dokladu; sekce feedu K vyřízení, Archivovat vše v Ostatních, `archiveIfOther` vynechá akční zprávy (#105) |
| `mail-analysis-inprocess.md` | AI analýza v `shpd` místo démona `ai-analyzer`: služby z `AnalysisController`, příprava příloh, prompt přes Twig, runner a CLI `mail-analyze` (spawn + sweep), limit souběhu per server (#85 D9–D19) |
| `mail-analysis-queue-drain.md` | Runner AI analýzy dobírá frontu (rozpočet 10 min, nástupce), strop tří pádů za hodinu v runneru i reaperu, 401/403/404 jako chyba nastavení, nová hláška `configError` (#85 D25, D26) |

Démon volající endpoint žije v jiném repu: `mail_router:tasks/phase1.md`
(mail-router, Python). Externí AI analyzer (`ai_analyzer`) byl zrušen
(#85 D20–D24, `ai-analyzer-removal.md`) — analýzu dělá `shpd` v procesu.

## AI — MCP server a chat

Sdílené LLM backendy, MCP nástroje, vnitřní chat. Přehled
[`docs/ai.md`](../docs/ai.md), [`docs/mcp-server.md`](../docs/mcp-server.md),
[`docs/chat.md`](../docs/chat.md).

| Task | Co řeší |
|------|---------|
| `core-ai-extract-backends.md` | Extrakce AI backendů do `core/ai` (sdíleno analyzer/chat) |
| `ai-models-phase0.md` | Odchod ze Sonnetu 4.5 (končí 30. 11. 2026): přemostění přes Sonnet 4.6, volitelná teplota, thinking/effort, kontrola `stop_reason`, vyšší stropy `max_tokens`, oprava ceníku (#85 F0-D1–D9) |
| `ai-model-catalog.md` | Katalog modelů (`core.ai.models`: úroveň, schopnosti, ceny, životní cyklus), tvarování požadavku podle schopností modelu, ceník a přepis vyřazených modelů z katalogu, pojistka chatu (#85 fáze 1a, D30–D35) |
| `ai-functions.md` | Připojení vs. model, deklarace AI funkcí (`aiFunctions`), mapa úrovní, tabulka nastavení funkcí, `AiFunctionResolver`, převod pěti volajících a dat, přepsání v profilu pošty, upozornění „AI model brzy končí“, vlastní ID modelu (#85 fáze 1b, D36–D43) |
| `ai-settings-section.md` | Sekce AI v Nastavení (připojení, funkce, přehled modelů), detail funkce s úpravami požadavku a stavem resolveru, příkaz `ai-test`, nápověda pro správce (#85 fáze 1c, D49–D52) |
| `chat-thinking-blocks.md` | Asistent na modelech s thinkingem: sběr thinking bloků ze streamu, vracení v rámci jedné tool smyčky (do databáze se neukládají), zánik pojistky chatu a `chatToolLoop`, strop tokenů 16 000, indikace „Přemýšlím…“ (#85 fáze 4, D53–D56) |
| `ai-bedrock-eu.md` | Zpracování v EU: platforma „Amazon Bedrock (EU)“ (`bedrock-runtime`, geografický profil `eu.`), modely úrovní per platforma, cena s násobitelem, čitelné chyby Bedrocku, nápověda (#85 fáze 3a, D57–D61) |
| `mcp-server-01-skeleton.md` | Skeleton MCP serveru + `persons_search` |
| `mcp-server-02-read-tools.md` | Zbývající čtecí nástroje (`documents_search`, `mail_list_pending`, …) |
| `mcp-server-03-draft-tool.md` | Draft nástroj `mail_draft_document` (první zápisový) |
| `chat-phase1-persistence.md` | Perzistenční skelet konverzací |
| `chat-phase2a-streaming.md` | Streamovaný chat bez nástrojů (`LlmClient::streamChat`) |
| `chat-phase2b-tools.md` | Tool-use smyčka |
| `chat-phase3-ui.md` | Svelte UI chatu |
| `ai-analyzer-removal.md` | Zrušení pull protokolu `/_mail/analysis/*`, uživatele `_ai_analyzer`, registru analyzerů na hostingu a démona `ai-analyzer`; příkaz `ai-backend-set-key`; upozornění „Analýza pošty stojí“ (#85 D20–D24) |

## Upozornění (core.alerts)

Systém upozornění s akcemi. Reference [`docs/alerts.md`](../docs/alerts.md).

| Task | Co řeší |
|------|---------|
| `alerts-01.md` | Modul `core.alerts` MVP (checks, reconciliation, snooze/dismiss) |
| `alerts-02.md` | `detail.actions` ve Vieweru (alerts první konzument akcí) |
| `alerts-03.md` | Check `docs.core.stale_in_repair` |

## Dashboard a Úkoly

Home obrazovka + modul úkolů. Reference [`docs/dashboard.md`](../docs/dashboard.md).

| Task | Co řeší |
|------|---------|
| `01-module-tasks.md` | Modul `tasks.core` — Úkoly / To-Do list |
| `dashboard-phase1.md` | Widget MVP (3 widgety, agregovaný endpoint `/_ui/dashboard`) |
| `dashboard-phase2.md` | Přestavba na feed (kartový kontrakt, 2 zdroje, inline akce, undo) |
| `dashboard-phase2b.md` | Generované AI shrnutí (SSE endpoint, cache dle hashe feedu) |
| `dashboard-row-edit-modal.md` | Edit z řádku widgetu Úkoly |
| `dashboard-card-attachments.md` | Tlačítka příloh na mail kartách feedu (chip + hover náhled) |
| `dashboard-feed-filter.md` | Filtr kategorií karet feedu (chip bar; Přijaté faktury / Spisovna / Ostatní) |
| `dashboard-feed-redesign.md` | Redesign karet feedu (grid, strukturovaná hlavička, expander detailu, horní proužek) |
| `dashboard-alert-grouping.md` | Agregace alertů jednoho checku do skupinové karty feedu (práh > 3) |
| `dashboard-chat-panel.md` | Plovoucí chat launcher + boční AI chat panel (AppShell overlay zprava) |
| `dashboard-feed-workflow-sections.md` | Sekce feedu podle toku práce (Položky k založení → … → Ostatní), `feedSection` ze serveru, strop a pravdivé počty per sekce (#101) |
| `dashboard-other-row-title.md` | Řádek sekce Ostatní s titulkem podle obsahu zprávy (`ai_title`), fallback „Neobsahuje doklad ani dokument“, předmět jen když se liší |
| `feed-texts-catalog.md` | Texty karet feedu z katalogu modulu (`*.feedTexts` cfgItem, `FeedTexts`, ICU MessageFormat pro plurály), fallback anglicky v PHP (#101 D11–D17) |
| `company-overview.md` | Přehled firmy — obrazovka „jak na tom firma je“: bloky Peníze, Pohledávky, Závazky, DPH, Výsledek hospodaření z existujících výpočtů, registrace `overviewBlocks`, `GET /_ui/overview`, jen pro adminy (#120) |

## Dev dashboard

Vývojářský nástroj v development módu (`/_dev/`).

| Task | Co řeší |
|------|---------|
| `dev-dashboard-mvp.md` | Seznam datových zdrojů |
| `dev-dashboard-log-viewer.md` | Log viewer `/_dev/logs/` |
| `dev-dashboard-create-ds.md` | Vytvoření DS přes UI |
| `dev-dashboard-actions.md` | Server akce + per-DS upgrade |
| `dev-dashboard-empty-state.md` | Prázdný seznam DS odkazuje na + New DS |
| `dev-dashboard-look.md` | Vzhled podle shipard.dev — vrstva tokenů `theme.css`, IBM Plex, logo, ikony, významové barvy tlačítek, lepicí pruh DEVELOPMENT DASHBOARD s verzí, prázdný stav, kontrola CSS tokenů (#129 fáze 1) |
| `dev-dashboard-update.md` | Zastaralost a Aktualizovat — štítek „N commitů pozadu“ s bublinou, kontrola anonymním HTTPS fetchem, úloha `update` se zámkem na celý server, `shpd-server dev-update-check` a `dev-update` (#129 fáze 3, D16–D22) |
| `dev-dashboard-i18n.md` | Čeština a angličtina dashboardu — vlastní slovníky a `t()`, jazyk podle prohlížeče, přepínač v liště, `shpd_dev_language`, `check:i18n` i pro dashboard (#129 D12, fáze 2) |

## Testování a datové sady

Datové sady (`dataset-dump` / `dataset-seed`, [`docs/datasets.md`](../docs/datasets.md))
a nové testování podle issue #103 — korpus dokladů, profily, ověření
zaúčtování a evaluace AI.

| Task | Fáze | Co řeší |
|------|------|---------|
| `dataset-phase1.md` | — | Datové sady: `dataset-dump` / `dataset-seed`, snapshot mode došlé pošty (#40 D1–D5) |
| `demo-invoices.md` | — | Generátor fiktivních přijatých faktur (PDF) pro demo video |
| `demo-dataset-phase1.md` | — | Demo sada „IT servis“: generátor sad z příběhu firmy nad `invoicegen`, sada `demo/datasets/it-servis/` bajtově shodná s dumpem, dump umí pokladní doklady, `provision` a pozornost zpráv (#131 D1–D15) |
| `testing-phase1.md` | F1 | Korpus testovacích dokladů: sdílený generátor, 10 variant × 2 podoby (PDF, PDF + ISDOC), tři profily vlastní firmy, očekávaný výsledek jako papírová projekce canonicalu, tvary hodnot (#103 D12–D18) |
| `testing-phase2.md` | F2 | Profily a datové sady z korpusu: základ profilu, sady `tests/datasets/cz/` s ideální analýzou (snapshot), manifest `country` / `profile` / `provision`, seed zakládá fiskální roky a účetní položky z nabídky (#103 D19–D25) |
| `testing-phase3.md` | F3 | Ověření zaúčtování a DPH: `dataset-verify` (apply + kontroly), porovnávač canonicalu v PHP, očekávání DPH a nákladů nezávisle na Shipardu, schválený snímek deníku (#103 D26–D30) |
| `testing-phase4.md` | F4 | Evaluace AI analýzy: serverové AI připojení pro vývoj (opt-in), analýza nanečisto, varianty, `ai-eval` s opakováním, výsledky v `tests/eval/`, `ai-eval-compare` (#103 D34–D41) |
| `testing-phase4b.md` | F4 | Oprava očekávání (štítek dlouhých dokladů), prompt v4.8.0, nástroje evaluace, srovnání Sonnet 4.6 / Sonnet 5.5 / Opus 5.5 / Haiku 5.5 / Fable 5.1 (#103 D42–D46) |
| `ai-tiers-series5.md` | M4 | Výchozí modely úrovní na řadu 5: `fast` a `balanced` Sonnet 5.5, `strong` Opus 5.5, ověřovací běhy (#103 D47–D50) |
| `testing-phase6a.md` | F6 | Vývojářský dashboard ve Svelte (port beze změny API) a prohlížeč výsledků evaluací: běhy, detail dokladu s PDF, porovnání (#103 D51–D53, D57) |
| `testing-phase6b.md` | F6 | Spouštění z dashboardu: úlohy na pozadí, evaluační zdroje dat (pole `dataset`), evaluace s odhadem a stropem útraty, `dataset-verify`, editor variant, cena štítků (#103 D54–D56, D58–D63) |
| `testing-phase6c.md` | F6 | Datová sada při zakládání zdroje dat a akce „Nahrát datovou sadu“ v seznamu, heslo admina přes stdin, zrušení starých `seed-*` (#103 D64–D67) |
| `ds-delete.md` | M5 | `shpd-server ds-delete` pro volné zdroje dat mimo produkci, mazání z dashboardu, přehled zdrojů dat bez sloupce Databáze a s kebab menu (#103 D68–D71) |
| `testing-rates-isdoc.md` | F3/F4 | Kurzy a ISDOC v datových sadách: lístky ČNB v korpusu, očekávání v Kč, `dataset-verify` bez přeskakování cizí měny, ISDOC jako příloha, evaluace zpráv s ISDOC produkční cestou (#103 D72–D77) |
| `corpus-phones.md` | F3/F4 | Telefonní čísla fiktivních identit korpusu z nepřidělitelného rozsahu (`000` za kódem země), kontrola v buildu, přegenerované sady a výsledky evaluací (#132, #103 D90) |
| `testing-phase7a.md` | F7a | Proklikání datových sad: Playwright Test v `tests/e2e/`, `shpd-ds dataset-e2e` (přeseedování, krátkodobá session, proklikání karet, `dataset-verify --no-apply`), akce Proklikat ve vývojářském dashboardu (#103 D86–D89, D91–D96) |
| `testing-phase7a-fixes.md` | F7a | Opravy nálezů proklikání: proklikání jen proti lokální adrese serveru, rozhodnutí u bankovního účtu strany v kontrole, osoba z kontroly s adresou, neznámé IČO v registru, chyba JS v popoveru (#103 D97–D101) |
| `corpus-print-fidelity.md` | F3/F4 | Věrnost tisku korpusu: kódy položek a EAN na PDF, spisová značka bez popisku, jednoznačné položky dlouhého dokladu, build hlídá identifikátory očekávání v textu PDF (#103 D102–D105) |
| `eval-tests-fixtures.md` | F4 | Testy katalogu a prohlížeče evaluací nad vlastní fixturou — commit nového běhu do `tests/eval/` žádný test nerozbije (#103 D106) |
| `prompt-projection-coverage.md` | F4 | Prompt `czech_general` v4.9.0: ukázka dokladu pokrývá celou papírovou projekci (web, období plnění, EAN), test pokrytí ukázky proti `projection.jsonc` (#103 D107, D108) |
| `corpus-field-semantics.md` | F3/F4 | Význam sporných polí projekce v korpusu: platební reference podle pokynu dokladu, období dokladu z řádků, jednoznačné texty řádků 0402 a 0702, kontrola netištěných hodnot (#103 D110, D112, D113) |
| `prompt-field-rules.md` | F4 | Prompt `czech_general` v4.10.0: poznámka bez zákonných doložek DPH, platební reference a DUZP jen z dokladu, aritmeticky správná ukázka (#103 D109–D111) |
| `demo-dataset-phase2a-bank.md` | — | Demo sada „IT servis“, fáze 2a: sekce bankovních výpisů v sadě (exportér, seeder, účet kódem, partner a párování dopočítá aplikace), platby v příběhu, vyrovnání mezd a odvodů interním dokladem, eurový účet (#131 D16–D18, D21, D22) |
| `demo-dataset-economy.md` | — | Demo sada „IT servis“: ekonomika příběhu (zisk, podíl zahraničí, marže hardwaru), oprava přepočtu ceny položky na faktuře v EUR, převody EUR → CZK mezi vlastními účty, dodavatel v EUR (#131 D25–D28) |

## Drobné

| Task | Co řeší |
|------|---------|
| `add-reference.md` | Přidání `reference` (FK) + `displayPattern` do schema |
| `search-accent-insensitive.md` | Hledání bez ohledu na diakritiku — `SearchCondition`, `COLLATE utf8mb4_uca1400_ai_ci` (#18) |

---

## Konvence

### Hlavička se stavem

Každý task má hned za nadpisem H1 řádek:

```
**Stav:** hotovo
**Stav:** částečně — krátká poznámka, co zbývá
**Stav:** naplánováno — krátká poznámka
**Stav:** zrušeno — proč
```

Tento řádek je **zdroj pravdy**. Souhrnná tabulka na začátku tohoto
souboru se z něj generuje:

```bash
python3 scripts/tasks-index.py          # přegeneruje souhrn
python3 scripts/tasks-index.py --check  # jen ověří
```

`--check` běží v `pre-commit` hooku, takže se index nemůže rozejít
s hlavičkami. Rozejit se **může** hlavička s kódem — to žádný
skript neuhlídá. Proto: **poslední krok každé implementace je aktualizace
hlavičky tasku**, ve stejném commitu jako kód. Audit v srpnu 2026 našel osm
tasků, které tvrdily „k implementaci“ u věcí, co byly dávno v kódu.

### Pojmenování souborů

- **Fázové PRD:** `<oblast>-phase<N>[<sub>].md` (`mail-phase2a.md`,
  `edit-forms-phase3.md`)
- **Jednorázové úkoly:** popisný kebab-case (`form-builder-hardening.md`,
  `add-user.md`)
- Všechny v ASCII (žádná diakritika v názvech), aby šly snadno tab-completit

### Struktura PRD

Větší PRD typicky obsahují:

- **Status / Cíl fáze** — krátká věta, co tato fáze přidává
- **Návaznost** — odkazy na předchozí PRD a externí závislosti
- **Scope** — V rozsahu / Mimo rozsah, přesně vymezené
- **Datový model** — JSONC tabulky, FK, indexy
- **API / kontrakty** — endpointy, request/response, error paths
- **Task breakdown** — commitovatelné jednotky s akceptačními kritérii
- **Rozhodnutí k designu** — body s `✓ potvrzeno` po finalizaci

Menší tasky stačí jako jednostránkové prompty s nadpisem „Co je potřeba
udělat" a check-listem „Hotovo když".

### Citlivé údaje z reálných dat

Task fily (a jakékoli commitované soubory — docs, commit messages)
**nikdy nesmí obsahovat citlivé údaje z reálných dat** testovacích ani
produkčních prostředí: jména firem a osob, čísla dokladů/faktur, reálné
částky, e-mailové adresy, identifikátory zdrojů dat (DS ID, názvy DS)
ani přímé odkazy na konkrétní záznamy (id řádků). Tasky jsou trvalý
záznam v gitu (GitHub) a po dokončení se nemažou.

Diagnostické příklady **anonymizuj se zachováním poměrů** (např.
Σ řádků 1 000,05 → částka 1 000,00, zaokrouhlení −0,05) — vypovídací
hodnota pro implementaci zůstane, identifikace zdroje ne. Konkrétní
testovací případ (DS, id záznamu) si drž mimo repo (chat, lokální
poznámky).

### Automatická kontrola citlivých údajů

`pre-commit` hook spouští `scripts/check-sensitive.py`, který hlásí:

- **vzor ID datového zdroje** (`xxxx-xxxx-xxxx-xxxx`), pokud nevypadá syntetické
  (všechny čtyři skupiny z `DUMMY_GROUPS` — např. `test-test-test-test`)
- **vlastní termíny** ze souboru `.git/sensitive-terms` — jeden na řádek,
  `#` je komentář

`.git/sensitive-terms` **není v gitu** — právě proto do něj patří skutečné
názvy firem, datových zdrojů a dodavatelů. Kdyby byl commitovaný, vrátil
by ty názvy do veřejného repozitáře. Nový klon si ho musí vytvořit znovu —
bez něj se kontrola názvů přeskakuje a skript to oznámí.

Rozsah kontroly:

```bash
python3 scripts/check-sensitive.py        # staged soubory (hook)
python3 scripts/check-sensitive.py --all  # celý strom (audit)
```

Kontrola **nezachytí** názvy produktů, tarifů a předměty zpráv — ty nejdou
odlišit od obecných pojmů. Ty hlídej očima při psaní diagnostiky.

### Referencování externích repozitářů

Když task odkazuje na něco v jiném repu, používej prefix `<projekt>:cesta`,
kde `<projekt>` je `project_id` z mapy projektů níže:

- `mail_router:tasks/phase1.md`
- `shipard:docs/mail/api-contract.md` (zpětný odkaz z jiných repo tasků)

### Otevřené otázky

V `## Otevřené otázky` (nebo `## Rozhodnutí k designu`) sekci na konci
PRD. Po finalizaci přepsat na **`## Rozhodnutí k designu (potvrzená)`**
s `✓` na začátku každého bodu — ať příští čtenář ví, že to není
otevřené.

---

## Mapa projektů

| `project_id` v `remote-dev-bridge` | Repo | Role |
|------------------------------------|------|------|
| `shipard` | `shipard/shipard` | Hlavní aplikace (PHP backend + Svelte frontend) |
| `old_shipard` | `shipard/shipard-old` (soukromé) | Starý Shipard + migrační pipeline do nového |
| `dev_env` | `shipard/dev-env` (soukromé) | Přístupy a prostředí týmu (`docs/ai-workflow.md` §6) |
| `mail_router` | `shipard/mail-router` | Mail-router daemon (Python) |
| `ai_analyzer` | `shipard/ai-analyzer` | AI analyzer daemon (Python) — **zrušen** #85 D24 (2026-10-10), repozitář archivován |

Úplný seznam včetně testovacího serveru je v `dev-env.md` §3. Lokální
adresář checkoutu se může jmenovat jinak (`shpd`); `project_id` se řídí
touto tabulkou.

Tasky obvykle žijí v repu, kterého se týkají primárně. Cross-repo
změny dělej tak, že nejdřív stabilizuješ jeden repo (typicky `shipard`
jako „server"), pak proti němu vyvíjíš klienty.

---

## Když se vracíš po pauze

1. Najdi v **indexu podle oblastí** oblast, které se chceš věnovat
2. Poslední commitnutý PRD v dané oblasti napoví, kde práce skončila
   (kód je zdroj pravdy — task je momentka)
3. Čti `CLAUDE.md` v kořeni repa pro celkový architektonický přehled
4. Při startu nové fáze: nejdřív design diskuze (otevřené otázky), pak
   PRD, pak implementace
