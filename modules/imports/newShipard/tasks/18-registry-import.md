# Task 18: Import Dokumentů (`wkf.docs`) → Spisovna (`base.registry`)

## Kontext

Import starého modulu Dokumenty (`wkf_docs_documents`, `wkf_docs_folders`,
`wkf_docs_docsKinds`) do Spisovny nového Shipardu (`base_registry_documents`,
`base_registry_binders`). Design cílového modulu:
`nov_shipard: docs/registry-mvp.md` (§10 = mapování a mechanika migrace).

Inventura vzorku (dev server, 15 DS):

- dokumentů celkem ~2 650; dominuje msi-zlin (`msiu70160`): 2 392 dokumentů,
  46 složek, 3 druhy; druhý `58078505583667` (132/10/4); zbytek malé
- druhy napříč DS: Dokument/Dokumenty, Smlouva, Archív, Vysvědčení (1 DS)
- složky max 2 úrovně; děti kořene „Mzdy" = **roky** (1992–2005+) a drží
  většinu obsahu
- `validFrom`/`validTo` prakticky nepoužité (1 z 2 392)
- příloh k dokumentům na msi-zlin 16 138 (16 051 živých, ~6,7/dokument);
  na dev serveru fyzicky existují jen soubory 2025–2026 (data ze zálohy),
  ostrý běh poběží z produkčních serverů, kde soubory jsou
- doclinks k dokumentům: 163 (nemigrují se — akceptovaná malá ztráta)

### Prerekvizity

1. **`nov_shipard: tasks/registry-import-endpoint.md`** — endpoint
   `POST /_registry/import` (dedupe podle `legacy.ndx`, zachované
   `created`, cílový docState). Dokumenty nelze zakládat generickým CRUD.
2. **Fáze 07a** (`07a-attachments-client.md`) — `AttachmentImporter` /
   `AttachmentReader` / `AttachmentUploadClient` pro přenos příloh
   (cílová tableId **428**).
3. Orchestrátor (`06a`) — zařazení do `AllRunner`.

Žádná závislost na persons/docs fázích: autor se přenáší jen jako jméno
(`loadPersonName` vzor `MailRunner`), partner se nemapuje.

### Klíčová rozhodnutí (M1–M4, potvrzena)

- **M1 — druhy:** mapa podle `docsKinds.fullName` (lowercase, trim):
  `smlouva` → `contract`; vše ostatní (Dokument, Dokumenty, Archív,
  Vysvědčení, …) → `other`. Původní název **vždy** do
  `legacy.kind` → `metadata.legacyKind`. Nemapovaný/neznámý kind →
  `other` + počítadlo `unmappedKinds` (log na konci).
- **M2 — složky:** **šanon = kořenová složka stromu.** Runner resolvuje
  `folder → kořen` průchodem `parentFolder` (cycle guard, hloubka
  reálně ≤2, ale smyčka obecná); plná cesta („Mzdy / 1999") →
  `legacy.folder` → `metadata.legacyFolder`. Šanony zakládá runner
  **před dokumenty**: jeden per živý kořen (`docState != 9800`),
  name = `shortName` kořene, `order_pos` ze starého `order`,
  přes generický CRUD klient (`CrudClient`); mapa starý kořen-ndx →
  nový binder id v `LocalIdMap` (nová entita, konvence dle existujících).
  Dokument v koši/smazané složce → binder dle kořene, pokud kořen žije;
  jinak NULL + `legacyFolder`.
- **M3 — stavy:** filtr `docState != 9800` (smazané se nemigrují,
  plošná konvence runnerů); mapa `1000→10`, `4000→40`, `8000→80`,
  `9000→70` (staré „V Archívu / Ukončit platnost" = nové „V archívu").
- **M4 — přílohy:** jen `deleted=0` z `e10_attachments_files`
  (`tableid='wkf.docs.documents'`); před uploadem ověřit existenci
  souboru a `fileCheckSum` (pokud vyplněn); **chybějící soubor →
  log + počítadlo `attachmentsMissing`, dokument se importuje dál**
  (na dev serveru bude chybět většina — očekávané; ostrý běh z produkce).

### Další mapování (dle design §10.1)

| staré | nové |
|---|---|
| `title` | `title` |
| `text` | `notice` |
| `validFrom`/`validTo` (≠ 0000-00-00) | `validFrom`/`validTo` |
| `documentId` | `legacy.id` → `metadata.legacyId` |
| `author` (persons ref) | jméno → `legacy.author` |
| `dateCreate` | `created` (historické, endpoint zachová) |
| `ndx` | `legacy.ndx` (dedupe klíč) |
| doclinks, klasifikace, práva | nemigrují |

## Implementace

`libs/runners/RegistryRunner.php` (vzor `MailRunner` — struktura,
stats, continue-on-error, printDone):

1. **Bindery:** načti živé kořeny (`parentFolder=0 AND docState!=9800`),
   založ přes CRUD (skip, pokud už v `LocalIdMap`), ulož mapu.
2. **Dokumenty:** keyset pagination podle `ndx` (vzor tasků 16/17,
   `fetchIssuesBatch` analogie), `WHERE docState != 9800`; per řádek:
   - resolve kořen složky + cesta; kind mapa (M1); stav mapa (M3);
   - `POST /_registry/import` (payload dle endpoint tasku; `binder` =
     name šanonu); `existed=true` → počítadlo `skippedExisting`;
   - `LocalIdMap` starý ndx → nový id;
   - **přílohy:** `AttachmentImporter` na tableId 428 + nový id (M4).
3. **Stats:** documents imported/skippedExisting/failed, binders created,
   unmappedKinds, attachments uploaded/missing/checksumMismatch.
4. **Volby:** `--from/--to` na `dateCreate` (volitelné okno, default vše;
   objemy okno nevyžadují), `-v`, continue-on-error dle konvence.
5. **Orchestrátor:** zařadit `registry` na konec řetězce `AllRunner`
   (po mail); reset subcommand dle konvence orchestrátoru (ds-reset na
   nové straně tabulky maže — `keepOnReset` Spisovna nemá záměrně).

```bash
shpd-app cli-action --action=imports.newShipard/import registry
shpd-app cli-action --action=imports.newShipard/import registry --from=2020-01-01 -v
```

**Post-import krok (nová strana):** `shpd-ds registry-extract-texts`
(CLI z Fáze 4 Spisovny) — doplní fulltexty z nahraných příloh. Zmínit
v runbook výstupu runneru (printDone hint), nespouštět automaticky.

## Testy

- unit: kind mapa (smlouva/dokument/archív/neznámý), folder→kořen
  (root, dítě, cyklus → guard, smazaný kořen), stav mapa vč. filtru 9800,
  payload builder (legacy blok kompletní, prázdné validity vynechané,
  0000-00-00 → null);
- integračně proti dev datům msi-zlin: běh projde, počty sedí
  (2 392 celkem − 7 × 9800 = **2 385 importovaných**, z toho 2 275→40
  a 110→70), ~6 šanonů,
  `attachmentsMissing` vysoké (očekávané na dev), přílohy 2025–2026
  nahrané a checksumy sedí;
- **idempotence:** druhý běh → `imported=0`, `skippedExisting=2385`,
  žádné duplicitní šanony.

## Hotovo když

- [ ] `import registry` projde na msi-zlin vzorku s počty dle inventury
- [ ] šanony = živé kořeny stromů, dokumenty nesou `legacyFolder`
      s plnou cestou
- [ ] stavy mapované 1:1, smazané (9800) vynechané
- [ ] přílohy: živé nahrané na tableId 428, chybějící soubory logované
      a nepadající, checksum ověřený
- [ ] opakovaný běh idempotentní (LocalIdMap + endpoint dedupe)
- [ ] zařazeno v `AllRunner` vč. reset konvence; README pipeline
      aktualizované
- [ ] unit testy zelené
