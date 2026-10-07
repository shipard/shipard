# Dokumentace

## Kam co patří

| Adresář | Čtenář | Žánr |
|---------|--------|------|
| [`help/`](../help/README.md) | uživatel, vestavěný asistent | jak to udělám |
| `docs/` | Claude, vývojář | jak je to udělané — specifikace |
| [`docs/dev/`](dev/) | vývojář, hlavně začínající | návod krok za krokem |
| [`docs/operations/`](operations/) | správce systému | provozní postup |

## Návody pro vývojáře

| Dokument | Obsah |
|----------|-------|
| [`../DEVELOPERS.md`](../DEVELOPERS.md) | **Pro vývojáře** — úvod a rozcestník: proč a jak Shipard stavíme (kód píše AI), základ a moduly, co budeš potřebovat, kudy dál podle záměru čtenáře |
| [dev/local-dev.md](dev/local-dev.md) | **Lokální vývoj na macOS a Windows** — návod krok za krokem: Ubuntu v Multipassu (cloud-init `scripts/multipass/shipard-dev.yaml`) nebo ve WSL, instalace skriptem `scripts/dev-bootstrap.sh`, napojení Claude Code a `remote-dev-bridge`, řešení potíží |
| [dev/linux-install.md](dev/linux-install.md) | **Ruční instalace na Linuxu** — vlastní Linux server nebo Ubuntu přímo v počítači: požadavky, systémové balíčky a setup, závislosti a build frontendu, server config, ověření `shpd-server doctor`; totéž jedním příkazem dělá `scripts/dev-bootstrap.sh` |
| [dev/dev-daily.md](dev/dev-daily.md) | **První kroky a každodenní práce** — společné pro Multipass, WSL i Linux: vývojářský dashboard `/_dev/`, první zdroj dat, co dělat po `git pull` (`dev-update.sh`, `ds-upgrade-all`, git hooky), `composer.lock`, přepnutí `remote` před odesláním změn, řešení potíží |
| [dev/claude-code-intro.md](dev/claude-code-intro.md) | **Claude Code pro začátečníky** — instalace a přihlášení ve VM / WSL, režimy oprávnění (Manual → acceptEdits, co repo blokuje), zadávání a kontrola práce, fork a pull request přes `gh`, typické pasti |

## Jak se v projektu pracuje

| Dokument | Obsah |
|----------|-------|
| [roadmap.md](roadmap.md) | **Roadmapa** — milníky podle schopností uživatele, pravidlo prioritizace, vědomě odložené věci |
| [features.md](features.md) | **Přehled funkcí** — mapa rozsahu: co je hotové, částečné a plánované, vědomě mimo rozsah |
| [ai-workflow.md](ai-workflow.md) | **Vývoj s Claudem** — role chat / Claude Code / člověk, postup issue → rozhodnutí → PRD → implementace → ověření, pravidla pro testovací server s reálnými daty, ověřené návyky, mapa zdrojů (repo / soukromé `dev-env` / stroj / Projekt), osobní `CLAUDE.local.md` s režimy zdrojů dat, text instrukcí Projektu |
| [documentation.md](documentation.md) | Pravidla pro dokumentaci modulů a tabulek — kde leží README.md, co obsahuje .md k tabulce, vzory |
| [help-authoring.md](help-authoring.md) | Pravidla pro **uživatelskou** dokumentaci v [`help/`](../help/README.md) — žánrová hranice, front matter, šablona stránky, generovaný rozcestník |
| [services.md](services.md) | **Standard samostatných komponent** — pravidla pro repozitáře mimo `shpd` (`ai-analyzer`, `mail-router`, generátor videa, vendorovaná infrastruktura): tři kategorie, struktura repa, povinná sada CLI verbů, cesty na cílovém stroji, kontrakty vůči `shpd`, checklist souladu |

## Specifikace

| Dokument | Obsah |
|----------|-------|
| [architecture.md](architecture.md) | Mapa tříd, vrstvy, závislosti, tok dat — jak komponenty spolupracují |
| [modules.md](modules.md) | Modulový systém — struktura, závislosti, JSONC formát, i18n, kompilace konfigurace, CLI příkaz `ds-upgrade` |
| [table-definitions.md](table-definitions.md) | Formát definice databázových tabulek — datové typy, sloupce, indexy, extensions, validace, bezpečné změny |
| [structured-fields.md](structured-fields.md) | Strukturovaná pole se schématem — data, jejichž schéma se mění podle typu záznamu, země a času (#74): náhrada `subColumns` starého Shipardu, verzi schématu nese hodnota, ne datum záznamu |
| [document-system.md](document-system.md) | Dokumentový systém — hooky, validace, before/after save, životní cyklus záznamu |
| [doc-states.md](doc-states.md) | Stavy dokumentů — docState/docStateMain, cfgItem stavový automat, viewGroup taby, REST API přechodů |
| [alerts.md](alerts.md) | Systém upozornění — JSONC definice kontrol, PHP třídy checků, reconciliation, snooze/dismiss, CLI alerts-run |
| [rest-api.md](rest-api.md) | REST API — endpointy, autentizace, formát odpovědí, filtrování, řazení, stránkování |
| [auth.md](auth.md) | Autentizace — lokální login, OIDC relying party (authorization code + PKCE) vůči libovolnému providerovi, mapování identit a JIT, break-glass CLI, samoobsluha účtů |
| [attachments.md](attachments.md) | Systém příloh dokumentů — upload, download, náhledy, úložiště souborů, API endpointy |
| [frontend.md](frontend.md) | Frontend architektura — Svelte 5, komponenty, viewer systém, ikony, API komunikace |
| [viewer-grid.md](viewer-grid.md) | **Designový dokument** — tabulkový layout vieweru (grid): sloupce, sticky hlavička, nekonečné skrolování; pro účetní deník, bankovní transakce a saldokonto |
| [edit-forms.md](edit-forms.md) | Editační formuláře — server-driven architektura, generický klient, 4-sloupcový grid, JSONC vs `TableForm`, integrace s doc states |
| [edit-forms-cookbook.md](edit-forms-cookbook.md) | Edit Forms Cookbook — krátké copy-paste vzory pro psaní formulářů (doplněk k `edit-forms.md`) |
| [app-settings.md](app-settings.md) | Nastavení aplikace — mechanismus settings pages (server-driven stránky vlastností), key-value `core_system_settings`, branding (název, logo, ikona) |
| [dashboard.md](dashboard.md) | Dashboard — home obrazovka, prioritizovaný feed akčních karet (pošta→doklad, alerty), inline akce + undo, generované AI shrnutí přes SSE, API kontrakt |
| [ai.md](ai.md) | AI subsystém — přehled: MCP server, katalog nástrojů a tiery podle rizika, chat orchestrátor, sdílené backendy, dvě cesty k LLM |
| [mcp-server.md](mcp-server.md) | MCP server — JSON-RPC protokol, rozhraní `McpTool`, doménová obálka a wire mapping, auth/DS scoping, **jak přidat nový nástroj** |
| [chat.md](chat.md) | Vnitřní AI asistent — orchestrační SSE smyčka, kontrakt událostí, LLM klient, výběr backendu, datový model konverzací, frontend konzumace |
| [registry-mvp.md](registry-mvp.md) | **Design record** Spisovny MVP (`base.registry`, fáze 1–4 hotové 2026-07) — schválená rozhodnutí a fázování; provozní pravda žije v dokumentaci modulu |
| [design-system.md](design-system.md) | Design system — paleta barev, doc-state konvence, badge systém, avatary, CSS proměnné |
| [ui-shells.md](ui-shells.md) | **Designový dokument** — varianty UI (shells): koncepty a cílový stav, realizace po fázích přes #45; hotové části se přesouvají do `frontend.md` |
| [cli.md](cli.md) | CLI nástroje — kompletní reference `shpd-server` a `shpd-ds` příkazů, pomocných skriptů a workflow scénářů |
| [ds-state.md](ds-state.md) | Stavy zdroje dat — lifecycle `active`/`read_only`/`suspended`/`pending_deletion` + maintenance overlay, `config/state.json` v1, fail-closed čtení, 503 `DS_UNAVAILABLE`, cron gating, `shpd-ds ds-state`; fázování #56 |
| [logging.md](logging.md) | Logging — centralizovaný `ErrorLogger`, JSON řádky (jeden per záznam), cesta a konfigurace logu; žádné přímé `error_log()` |
| [render.md](render.md) | PDF rendering — Gotenberg služba per stroj + engine-agnostický `RenderClient` (`src/Core/Render/`), profily untrusted/report, post-processing (embedIsdoc, appendPdfs), degradace |
| [prints.md](prints.md) | Tisky — doména `print`: PDF nad jedním záznamem. Deklarace `prints` v `module.jsonc`, `PrintBuilder` → `PrintData` (JSON kontrakt) → Twig šablona v sandboxu → `RenderClient`; bloky tisku dokladů, QR platba, katalogy překladů, REST `/_prints`, CLI `print-run` (i nástroje pro vývoj šablon), akce Tisk v detailu, vodoznak storna; **odesílání e-mailem** (účely kontaktů, příjemci, odesílatel, `RecordSendService`, šablony e-mailu, REST, CLI `print-send`). Hotové: faktura vydaná, zálohová faktura, pokladní doklad, prodejka, Kontace (#90 fáze 0–2, 4) |
| [mail/outbound.md](mail/outbound.md) | Odchozí pošta — fronta `core_mail_outbox` + log, per-sender SMTP transporty, relay, víc příjemců a kopie, odesílatel záznamu (`SenderResolver`), posluchač výsledku transportu, cron worker, runbook |
| [mail/sent.md](mail/sent.md) | Odeslaná pošta — evidence odeslaných zpráv nad libovolnou tabulkou (`core_mail_sent_messages`), pevný obsah, stavy, transport a propsání výsledku, Odeslat znovu, agenda a formulář zprávy |
| [exchange-format.md](exchange-format.md) | Výměnný formát pro doklady — kanonický JSON `shpd.docs.document.v1`, validate/preview/apply pipeline, resolvery, merge strategie |
| [exchange-format-persons.md](exchange-format-persons.md) | Výměnný formát pro osoby — `shpd.persons.person.v1`, sub-kolekce (adresy, banky, kontakty), authoritative refresh, lineage |
| [exchange-format-items.md](exchange-format-items.md) | Výměnný formát pro položky — `shpd.items.item.v1`, KindResolver / SupplierCodesResolver, per-partner dodavatelské kódy |
| [booking-history-format.md](booking-history-format.md) | Formát účetní historie `shpd.economy.booking-history.v1` — kanonická specifikace souboru s agregovanou účetní historií ze zdrojového systému; zpracování `shpd-ds booking-history` |
| [datasets.md](datasets.md) | Datové sady — `dataset-dump` / `dataset-seed`: přenosný obraz obsahu zdroje dat ve výměnných formátech pro import do jiného nebo zresetovaného DS |
| [docs-mvp.md](docs-mvp.md) | **Designový dokument** — specifikace dokladového systému MVP (faktury vydané + přijaté, DPH model, číselné řady, stavy, snapshoty). Transientní — po implementaci přesune do archivu. |
| [vat-calculation.md](vat-calculation.md) | Jak se počítá DPH na dokladech — autoritativní pravidla pro `docs.core` (#75): cena a DPH řádku, rekapitulace DPH, zaokrouhlení, dorovnání řádků k rekapitulaci; kde se kód liší, platí dokument |
| [design-import-row-operations.md](design-import-row-operations.md) | **Designový dokument** — řádkové operace pro zálohy, majetek a řádky bez položky při importu ze starého Shipardu (vlna C, D1–D7) |
| [design-import-wave-d.md](design-import-wave-d.md) | **Designový dokument** — vlna D: nálezy akceptace plného re-importu (D11–D15), navazuje na vlnu C |
| [accounting.md](accounting.md) | Účtování dokladů — automatické generování záznamů účetního deníku z obsahu dokladu (pohyb → předpis → kategorie → maska účtu → rozvrh), bez ručního zadávání účtů |
| [bank.md](bank.md) | **Referenční spec modulu** `economy.bank`: bankovní transakce a výpisy, ingestion (parsery + dedup), účtovací mikroengine, clearing účty nespárovaných plateb, polymorfní zdroj deníku, migrace. Fáze 1–4 hotové. |
| [ds-setup.md](ds-setup.md) | **Designový dokument** — nastavení nového zdroje dat: tři vrstvy nastavení (instalační parametry / referenční data / firemní identita), odvozený checklist chybějícího nastavení nad setup checky, průvodce jako UI nad ním, plátcovství DPH. Plán, implementace nezačala. |
| [reports.md](reports.md) | **Designový dokument** domény `report`: datové výstupy — princip „jeden výpočet, N prezentací" (`ReportResult` JSON → viewer/REST/MCP/diff), plochý model řádků, strany účtu místo znamének, fiskální období, generické MCP tooly. První reporty: hlavní kniha, výsledovka, rozvaha. Návrh (issue #42). |
| [assets.md](assets.md) | **Designový dokument** modulu `economy.assets`: majetek — rozbor starého `e10pro.property` a jeho dat, ledger hodnotových událostí, daňové a účetní odpisy s pravidly per země, automatické zaúčtování, majetek jako analytická dimenze, import + zpětné navázání dokladů. D1–D46 rozhodnuto; oblast 1 (karta, typy, účetní skupiny, inventární čísla — `tasks/assets-phase1.md`) hotová 2026-09-29, oblast 2 (pravidla daňových odpisů `world.assets` a engine `DepreciationPlanner` — `tasks/assets-phase2a.md`; události, plán na kartě a odpisy za období — `tasks/assets-phase2b.md`) 2026-09-30; oblasti 3–8 následují. |
| [accbal.md](accbal.md) | **Designový dokument** modulu `economy.accbal`: saldokonto postavené nad účetním deníkem — nastavení skupin a účtů, generátor pohybů z deníku (předpisy/úhrady), **případ = agregát párovacího klíče** (saldokonto, období, partner, VS, SS, měna; `CaseQuery`, #69 D1), routing úhrad přes `OpenItemLookup` + přeúčtování clearingu, viewer případů a pohybů, událost `journalWritten`. Fáze 0–2 a revize #69 T1–T2 hotové; T3–T6 (efektivní symboly, opakované platby, průvodci oprav, dashboard) následují. |
| [hosting.md](hosting.md) | **Designový dokument** modulu `hosting.core` — centrální správa zdrojů dat: portál pro uživatele s více DS, centrální identita (minimální OIDC Provider), automatické zakládání DS, napojení na mail-router a AI gateway |

Nginx konfigurace jsou v [`nginx/`](nginx/) (app.conf, development.conf, production.conf).

## Dokumenty k jednotlivým modulům

| Modul | Dokument | Obsah |
|-------|----------|-------|
| `core.mail` | [`mail/api-contract.md`](mail/api-contract.md) | Kontrakt HTTP endpointu `/_mail/incoming` pro externí mail-router (Fáze 2a — stabilní) |

Dokumentace konkrétního modulu žije obvykle v jeho adresáři
(`modules/{skupina}/{modul}/docs/`) — zde v `docs/` jsou pouze ty,
které přesahují hranice modulu (integrace s externími službami, API kontrakty).

## Provoz

Provozní a operační dokumentace (nasazení, oprávnění, bezpečnost).

| Dokument | Obsah |
|----------|-------|
| [migration-guide.md](migration-guide.md) | Migrace DS mezi servery — checklist pro přenos celého data-source (co tvoří DS, kritické secrets); detailní postupy v `operations/` |
| [operations/ai-gateway.md](operations/ai-gateway.md) | Zřízení AI gateway na hostingu (D5) — hosting DS proxuje `POST /_hosting/ai-gw/v1/messages` na `api.anthropic.com` pod klíčem organizace, klientské DS s gateway tokeny `shpd_gw_…`, spotřeba logovaná per DS |
| [operations/hosting-adopt-existing.md](operations/hosting-adopt-existing.md) | Adopce existujícího serveru a jeho DS do hostingu — zpětné napojení už běžícího serveru s živými zdroji dat; hosting vzniká vedle provozu a DS se adoptují postupně |
| [operations/mail-router.md](operations/mail-router.md) | Připojení mail-routeru k hostingu (D4) — řádek routeru + klíč, config `lookup_sync`, timer, ruční backfill `mail_token`, diagnostika lookup endpointu |
| [operations/permissions.md](operations/permissions.md) | Permission kontrakt pro `/opt/shipard` a `/etc/shipard` — `PermissionSpec`, `shpd-server doctor` / `fix-permissions`, single-user model |
| [operations/production.md](operations/production.md) | Produkční instalace — nasazení na čisté Ubuntu LTS: dedikovaný systémový uživatel `shipard`, HTTPS na doméně, zdroje dat z CLI, vývojářský dashboard `/_dev/` vypnutý |
| [operations/render-service.md](operations/render-service.md) | Provoz PDF rendering služby (Gotenberg) — single-server (podman + unit), Incus, Proxmox, upgrade pinu, bezpečnostní model |
| [operations/secrets.md](operations/secrets.md) | Šifrované secrets v DS — AES-256-GCM, per-DS klíč, `encrypted_text` sloupce v jakémkoli modulu |

## Archiv

Historické dokumenty, které už neřídí vývoj — [`archive/`](archive/).

---

[← README.md](../README.md) · [Průvodce vývojáře](../DEVELOPERS.md)
