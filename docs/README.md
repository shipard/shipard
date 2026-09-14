# Dokumentace

Technické specifikace projektu Shipard.

| Dokument | Obsah |
|----------|-------|
| [roadmap.md](roadmap.md) | **Roadmapa** — milníky podle schopností uživatele, pravidlo prioritizace, vědomě odložené věci |
| [features.md](features.md) | **Přehled funkcí** — mapa rozsahu: co je hotové, částečné a plánované, vědomě mimo rozsah |
| [architecture.md](architecture.md) | Mapa tříd, vrstvy, závislosti, tok dat — jak komponenty spolupracují |
| [modules.md](modules.md) | Modulový systém — struktura, závislosti, JSONC formát, i18n, kompilace konfigurace, CLI příkaz `ds-upgrade` |
| [table-definitions.md](table-definitions.md) | Formát definice databázových tabulek — datové typy, sloupce, indexy, extensions, validace, bezpečné změny |
| [document-system.md](document-system.md) | Dokumentový systém — hooky, validace, before/after save, životní cyklus záznamu |
| [doc-states.md](doc-states.md) | Stavy dokumentů — docState/docStateMain, cfgItem stavový automat, viewGroup taby, REST API přechodů |
| [alerts.md](alerts.md) | Systém upozornění — JSONC definice kontrol, PHP třídy checků, reconciliation, snooze/dismiss, CLI alerts-run |
| [rest-api.md](rest-api.md) | REST API — endpointy, autentizace, formát odpovědí, filtrování, řazení, stránkování |
| [attachments.md](attachments.md) | Systém příloh dokumentů — upload, download, náhledy, úložiště souborů, API endpointy |
| [frontend.md](frontend.md) | Frontend architektura — Svelte 5, komponenty, viewer systém, ikony, API komunikace |
| [edit-forms.md](edit-forms.md) | Editační formuláře — server-driven architektura, generický klient, 4-sloupcový grid, JSONC vs `TableForm`, integrace s doc states |
| [edit-forms-cookbook.md](edit-forms-cookbook.md) | Edit Forms Cookbook — krátké copy-paste vzory pro psaní formulářů (doplněk k `edit-forms.md`) |
| [app-settings.md](app-settings.md) | Nastavení aplikace — mechanismus settings pages (server-driven stránky vlastností), key-value `core_system_settings`, branding (název, logo, ikona) |
| [dashboard.md](dashboard.md) | Dashboard — home obrazovka, prioritizovaný feed akčních karet (pošta→doklad, alerty), inline akce + undo, generované AI shrnutí přes SSE, API kontrakt |
| [ai.md](ai.md) | AI subsystém — přehled: MCP server, katalog nástrojů a tiery podle rizika, chat orchestrátor, sdílené backendy, dvě cesty k LLM |
| [mcp-server.md](mcp-server.md) | MCP server — JSON-RPC protokol, rozhraní `McpTool`, doménová obálka a wire mapping, auth/DS scoping, **jak přidat nový nástroj** |
| [chat.md](chat.md) | Vnitřní AI asistent — orchestrační SSE smyčka, kontrakt událostí, LLM klient, výběr backendu, datový model konverzací, frontend konzumace |
| [design-system.md](design-system.md) | Design system — paleta barev, doc-state konvence, badge systém, avatary, CSS proměnné |
| [documentation.md](documentation.md) | Pravidla pro dokumentaci modulů a tabulek — kde leží README.md, co obsahuje .md k tabulce, vzory |
| [ai-workflow.md](ai-workflow.md) | **Vývoj s Claudem** — role chat / Claude Code / člověk, postup issue → rozhodnutí → PRD → implementace → ověření, pravidla pro testovací server s reálnými daty, ověřené návyky, mapa zdrojů (repo / soukromé `dev-env` / stroj / Projekt), šablona `CLAUDE.local.md` |
| [help-authoring.md](help-authoring.md) | Pravidla pro **uživatelskou** dokumentaci v [`help/`](../help/README.md) — žánrová hranice, front matter, šablona stránky, generovaný rozcestník |
| [services.md](services.md) | **Standard samostatných komponent** — pravidla pro repozitáře mimo `shpd` (`ai-analyzer`, `mail-router`, generátor videa, vendorovaná infrastruktura): tři kategorie, struktura repa, povinná sada CLI verbů, cesty na cílovém stroji, kontrakty vůči `shpd`, checklist souladu |
| [cli.md](cli.md) | CLI nástroje — kompletní reference `shpd-server` a `shpd-ds` příkazů, pomocných skriptů a workflow scénářů |
| [ds-state.md](ds-state.md) | Stavy zdroje dat — lifecycle `active`/`read_only`/`suspended`/`pending_deletion` + maintenance overlay, `config/state.json` v1, fail-closed čtení, 503 `DS_UNAVAILABLE`, cron gating, `shpd-ds ds-state`; fázování #56 |
| [logging.md](logging.md) | Logging — centralizovaný `ErrorLogger`, JSON řádky (jeden per záznam), cesta a konfigurace logu; žádné přímé `error_log()` |
| [render.md](render.md) | PDF rendering — Gotenberg služba per stroj + engine-agnostický `RenderClient` (`src/Core/Render/`), profily untrusted/report, post-processing (embedIsdoc, appendPdfs), degradace |
| [exchange-format.md](exchange-format.md) | Výměnný formát pro doklady — kanonický JSON `shpd.docs.document.v1`, validate/preview/apply pipeline, resolvery, merge strategie |
| [exchange-format-persons.md](exchange-format-persons.md) | Výměnný formát pro osoby — `shpd.persons.person.v1`, sub-kolekce (adresy, banky, kontakty), authoritative refresh, lineage |
| [exchange-format-items.md](exchange-format-items.md) | Výměnný formát pro položky — `shpd.items.item.v1`, KindResolver / SupplierCodesResolver, per-partner dodavatelské kódy |
| [docs-mvp.md](docs-mvp.md) | **Designový dokument** — specifikace dokladového systému MVP (faktury vydané + přijaté, DPH model, číselné řady, stavy, snapshoty). Transientní — po implementaci přesune do archivu. |
| [accounting.md](accounting.md) | Účtování dokladů — automatické generování záznamů účetního deníku z obsahu dokladu (pohyb → předpis → kategorie → maska účtu → rozvrh), bez ručního zadávání účtů |
| [bank.md](bank.md) | **Referenční spec modulu** `economy.bank`: bankovní transakce a výpisy, ingestion (parsery + dedup), účtovací mikroengine, clearing účty nespárovaných plateb, polymorfní zdroj deníku, migrace. Fáze 1–4 hotové. |
| [ds-setup.md](ds-setup.md) | **Designový dokument** — nastavení nového zdroje dat: tři vrstvy nastavení (instalační parametry / referenční data / firemní identita), odvozený checklist chybějícího nastavení nad setup checky, průvodce jako UI nad ním, plátcovství DPH. Plán, implementace nezačala. |
| [reports.md](reports.md) | **Designový dokument** domény `report`: datové výstupy — princip „jeden výpočet, N prezentací" (`ReportResult` JSON → viewer/REST/MCP/diff), plochý model řádků, strany účtu místo znamének, fiskální období, generické MCP tooly. První reporty: hlavní kniha, výsledovka, rozvaha. Návrh (issue #42). |
| [accbal.md](accbal.md) | **Designový dokument** modulu `economy.accbal`: saldokonto postavené nad účetním deníkem — nastavení skupin a účtů, generátor pohybů z deníku (předpisy/úhrady), **případ = agregát párovacího klíče** (saldokonto, období, partner, VS, SS, měna; `CaseQuery`, #69 D1), routing úhrad přes `OpenItemLookup` + přeúčtování clearingu, viewer případů a pohybů, událost `journalWritten`. Fáze 0–2 a revize #69 T1–T2 hotové; T3–T6 (efektivní symboly, opakované platby, průvodci oprav, dashboard) následují. |

Nginx konfigurace jsou v [`nginx/`](nginx/) (app.conf, development.conf, production.conf).

**Tady jsou technické specifikace.** Návody pro uživatele („jak to udělám“)
žijí v [`help/`](../help/README.md) — pravidla pro jejich psaní v
[help-authoring.md](help-authoring.md).

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
| [operations/mail-router.md](operations/mail-router.md) | Připojení mail-routeru k hostingu (D4) — řádek routeru + klíč, config `lookup_sync`, timer, ruční backfill `mail_token`, diagnostika lookup endpointu |
| [operations/permissions.md](operations/permissions.md) | Permission kontrakt pro `/opt/shipard` a `/etc/shipard` — `PermissionSpec`, `shpd-server doctor` / `fix-permissions`, single-user model |
| [operations/render-service.md](operations/render-service.md) | Provoz PDF rendering služby (Gotenberg) — single-server (podman + unit), Incus, Proxmox, upgrade pinu, bezpečnostní model |
| [operations/secrets.md](operations/secrets.md) | Šifrované secrets v DS — AES-256-GCM, per-DS klíč, `encrypted_text` sloupce v jakémkoli modulu |

## Archiv

Historické dokumenty, které už neřídí vývoj — [`archive/`](archive/).

---

[← README.md](../README.md) · [Průvodce vývojáře](../DEVELOPERS.md)
