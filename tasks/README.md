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

Celkem 284 tasků: **naplánováno** 5 · **částečně** 12 · **hotovo** 267.

Zdroj pravdy je řádek `**Stav:**` v hlavičce každého tasku; tato
tabulka je generovaná (`scripts/tasks-index.py`). Hotové tasky se
nevypisují — níže je jen to, co není dokončené.

| Task | Stav | Poznámka |
|------|------|----------|
| `ai-profile-sync-in-ds-upgrade.md` | naplánováno | sync není v `DsUpgradeCommand` |
| `auth-phase0a-hardening.md` | naplánováno | rate limiting a evidence neúspěšných přihlášení chybí |
| `dashboard-alert-grouping.md` | naplánováno | design schválen 2026-07-16, neimplementováno |
| `doc-partner-balance.md` | naplánováno |  |
| `migration-check.md` | naplánováno | návrh, čeká na schválení rozhodnutí M1–M7, pak implementace |
| `doc-number-release-on-data-save.md` | částečně | kód + testy hotové 2026-08-13; zbývá D2 (reset test DS), ruční proklik a read-only verifikace na alfě |
| `docs-import-party-snapshots.md` | částečně | kód, testy a docs hotové 2026-09-01; zbývá ověření po re-importu qrce (task 29 old_shipard, ops) — poslední dva body checklistu |
| `hosting-09-federated-login.md` | částečně | kód + testy + docs hotové 2026-08-14 (commity 1–3); zbývá ds-upgrade na hostingu, zapnutí Google/GitHub na alfě dle runbooku (mutace — David) a ruční E2E řetěz |
| `mail-import-partner-title.md` | částečně | implementace, testy a dokumentace hotové 2026-09-10 (5 commitů), backfill ověřen na dev DS; zbývá nasazení na alfu, re-import a SQL kontroly ze sekce „Ověření na alfě“ |
| `mail-message-centric.md` | částečně | Fáze A–D v tomto repozitáři hotové (schéma, /result v4, |
| `mail-preprocess.md` | částečně | Fáze 1 hotová (2026-08-29, 6 commitů), otevřený jen Bolt E2E na alfě (živý odkaz ze vzorku); Fáze 2 (`renderBodyToPdf`, `renderIfHtml`, aktivace Apple/Google) hotová 2026-08-29 — `tasks/mail-preprocess-phase2.md` |
| `taxes-phase01.md` | částečně | implementace kompletní (mapping, jádro reportů s periodSource vatPeriod + text/date sloupci, kalkulátory, buildery, UI, testy); zbývá ds-upgrade + proklik na dev DS a read-only ověření na alfě dle „Hotovo když" |
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
| `dev-update-script.md` | `scripts/dev-update.sh` + git hooks (post-pull workflow) |
| `ds-upgrade-all.md` | `shpd-server ds-upgrade-all` + kompletní `docs/cli.md` |
| `ds-upgrade-quiet-default.md` | Tichý default `ds-upgrade`, plný výpis jen s `-v` |
| `ds-upgrade-skip-provisioning.md` | Vypnutelný provisioning přes `config/main.json` |
| `ds-create-install-module.md` | `ds-create --module` + UI výběr instalačního modulu |
| `ds-reset.md` | `ds-reset` — čistý stav DS pro opakované testování importů |
| `unified-logging.md` | Produkční `ErrorLogger` (JSON řádky, úrovně, deploy guide, migrace `error_log()`) |
| `ds-encrypted-secrets.md` | Šifrování citlivých sloupců (`encrypted_text`, per-DS klíč, rotace) |
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
| `exchange-format-persons-phase1.md` | Výměnný formát osob (`shpd.persons.person.v1`) |
| `exchange-format-items-phase1.md` | Výměnný formát položek (`shpd.items.item.v1`) |
| `docs-import-number-mode.md` | Import-mód čísla dokladu + fix validace bank. spojení |
| `docs-import-series-states.md` | Výběr číselné řady + cílové stavy 40/30 při importu |
| `mail-phase4-import-endpoint.md` | Import endpoint pro importer ze starého Shipardu |

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
| `accounting-docs-phase1.md` | `cmnbkp` — schéma řádků + typ dokladu |
| `accounting-docs-phase2.md` | `cmnbkp` — účetní backend (engine + předpis + subclass) |
| `accounting-docs-phase2-balance-ops.md` | `cmnbkp` — saldokontní operace (operation-default účet) |
| `accounting-docs-phase3.md` | `cmnbkp` — UI (viewer, form, sekce Účtárna) |
| `accounting-docs-phase4-import.md` | `cmnbkp` — exchange + applier (import ze starého Shipardu) |

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
| `mail-message-title-partner.md` | Partner dokumentu (`partner_person`/`partner_name`) a titulek zprávy z AI (`ai_title`) — zobrazení v seznamu, fulltext, ruční partner má přednost (#43) |
| `mail-import-partner-title.md` | Partner a titulek u zpráv navázaných na doklad (import, Použít před #43) — odvození při `POST /_mail/import` + backfill `mail-target-backfill` (#43 D6) |
| `mail-analysis-schema-fixes.md` | Opravy AI analýzy: schema_error (kind/vat/courtRegistration), prompt v2.3.0, frontování dle docState + data fix |
| `mail-config-viewers.md` | Viewery a formuláře pro mailové konfigurační tabulky |
| `mail-invoice-rounding.md` | Zaokrouhlení celkové částky faktur: derivace `total_rounding_mode` v applieru, rounding-aware validace součtů, módy nahoru/dolů, prompt v3.1.0 |
| `ai-profile-reload.md` | CLI `ai-profile-reload` — reload promptu/schématu profilu z JSONC |
| `ai-profile-sync-in-ds-upgrade.md` | Automatický sync AI profilu ze šablony v rámci `ds-upgrade` (upgrade-only) |
| `enrichment-row-text-candidates.md` | Enrichment řádků z historie: matchování přes více kandidátních textů (description → item.description → item.name, tier-major) |
| `enrichment-dominant-item.md` | Enrichment řádků z historie: úroveň „dominantní položka dodavatele“ (statistika bez textu, confidence low, guard přes částku) |

Daemony volající endpoint žijí v jiných repech: `mail_router:tasks/phase1.md`
(mail-router, Python) a `ai_analyzer:tasks/phase1.md` (AI analyzer, Python).

## AI — MCP server a chat

Sdílené LLM backendy, MCP nástroje, vnitřní chat. Přehled
[`docs/ai.md`](../docs/ai.md), [`docs/mcp-server.md`](../docs/mcp-server.md),
[`docs/chat.md`](../docs/chat.md).

| Task | Co řeší |
|------|---------|
| `core-ai-extract-backends.md` | Extrakce AI backendů do `core/ai` (sdíleno analyzer/chat) |
| `mcp-server-01-skeleton.md` | Skeleton MCP serveru + `persons_search` |
| `mcp-server-02-read-tools.md` | Zbývající čtecí nástroje (`documents_search`, `mail_list_pending`, …) |
| `mcp-server-03-draft-tool.md` | Draft nástroj `mail_draft_document` (první zápisový) |
| `chat-phase1-persistence.md` | Perzistenční skelet konverzací |
| `chat-phase2a-streaming.md` | Streamovaný chat bez nástrojů (`LlmClient::streamChat`) |
| `chat-phase2b-tools.md` | Tool-use smyčka |
| `chat-phase3-ui.md` | Svelte UI chatu |

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

## Dev dashboard

Vývojářský nástroj v development módu (`/_dev/`).

| Task | Co řeší |
|------|---------|
| `dev-dashboard-mvp.md` | Seznam datových zdrojů |
| `dev-dashboard-log-viewer.md` | Log viewer `/_dev/logs/` |
| `dev-dashboard-create-ds.md` | Vytvoření DS přes UI |
| `dev-dashboard-actions.md` | Server akce + per-DS upgrade |

## Drobné

| Task | Co řeší |
|------|---------|
| `add-reference.md` | Přidání `reference` (FK) + `displayPattern` do schema |

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

Když task v `nov_shipard/tasks/` odkazuje na něco v jiném repu, používej
prefix `<projekt>:cesta`:

- `mail_router:tasks/phase1.md`
- `ai_analyzer:tasks/phase1.md`
- `shpd:docs/mail/api-contract.md` (zpětný odkaz z jiných repo tasků)

### Otevřené otázky

V `## Otevřené otázky` (nebo `## Rozhodnutí k designu`) sekci na konci
PRD. Po finalizaci přepsat na **`## Rozhodnutí k designu (potvrzená)`**
s `✓` na začátku každého bodu — ať příští čtenář ví, že to není
otevřené.

---

## Mapa projektů

| Projekt v `remote-dev-bridge` | Repo                      | Role                          |
|-------------------------------|---------------------------|-------------------------------|
| `nov_shipard`                 | `shipard/shpd`            | Hlavní aplikace (PHP backend + Svelte frontend) |
| `mail_router`                 | (samostatný repo)         | Mail-router daemon (Python)   |
| `ai_analyzer`                 | (samostatný repo)         | AI analyzer daemon (Python)   |

Tasky obvykle žijí v repu, kterého se týkají primárně. Cross-repo
změny dělej tak, že nejdřív stabilizuješ jeden repo (typicky shpd jako
„server"), pak proti němu vyvíjíš klienty.

---

## Když se vracíš po pauze

1. Najdi v **indexu podle oblastí** oblast, které se chceš věnovat
2. Poslední commitnutý PRD v dané oblasti napoví, kde práce skončila
   (kód je zdroj pravdy — task je momentka)
3. Čti `CLAUDE.md` v kořeni repa pro celkový architektonický přehled
4. Při startu nové fáze: nejdřív design diskuze (otevřené otázky), pak
   PRD, pak implementace
