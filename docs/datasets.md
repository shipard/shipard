# Datové sady — `dataset-dump` / `dataset-seed`

Přenosný obraz obsahu datového zdroje: složka (nebo zip) souborů ve
výměnných formátech, kterou lze naimportovat do jiného (nebo téhož,
zresetovaného) DS. Zadání a rozhodnutí: `tasks/dataset-phase1.md`, issue
shipard/shipard#40 (D1–D5). Pilotní konzument: demonstrační sada pro web.

Fáze 1 pokrývá `dateMode: fixed`, celý DS bez filtrů a *snapshot mode*
došlé pošty (analýza se přenáší jako data — žádné volání AI).

## 1. Struktura sady

```
sada/
  manifest.jsonc                      # shpd.dataset.v1
  setup/<tabulka>.jsonc               # shpd.dataset.setup.v1 — číselníky + DS settings
  persons/NNNN-<slug>.jsonc           # shpd.persons.person.v1
  items/NNNN-<slug>.jsonc             # shpd.items.item.v1
  docs/NNNN-<slug>.jsonc              # shpd.docs.document.v1
  registry/NNNN-<slug>.jsonc          # shpd.dataset.registryDocument.v1 (obálka)
  registry/NNNN-<slug>.files/<soubor> # přílohy dokumentu spisovny
  mail/NNNN-<slug>.jsonc              # shpd.mail.incomingMessage.v1
  mail/NNNN-<slug>.files/<soubor>     # přílohy zprávy (vč. raw .eml)
```

- **Názvy souborů** `NNNN-<slug>.jsonc` (čtyřmístné pořadí + ASCII slug
  z přirozeného klíče: název osoby, kód položky, číslo dokladu, titulek,
  `message_id`). Pořadí dává exporter podle přirozených klíčů, ne podle
  interních id — dump → seed → dump na jiném DS dává stejná jména.
- **Přílohy** leží v sidecar složce záznamu; `attachments[].file` v datech
  je jméno souboru v ní (unikátní v rámci záznamu, kolize → `-2`, `-3`).
- **JSON** deterministicky: pretty print, UTF-8 bez escapování, `\n` na
  konci, klíče v pořadí schématu, `null` a prázdná pole vynechaná.
  Výjimka: `analysisJson` / `canonicalJson` v analýzách zpráv se přenášejí
  verbatim (i s `null` a `{}`).
- **Žádná interní id.** Partner jako identifikátory (IČO/DIČ/VAT ID),
  položky přes `ourCode`, účty číslem, jednotky `system_code`, řada přes
  `numberSeriesCode`, vlastní bankovní účet přes `code` číselníku, mailbox a
  AI profil kódem, vazby zpráva ↔ doklad přes číslo dokladu (spisovna:
  titulek + `created`).

### `manifest.jsonc`

```jsonc
{
  "format": "shpd.dataset.v1",
  "name": "web-demo",                 // [a-z0-9][a-z0-9._-]*
  "title": "Demo webu — den účetní",
  "description": "…",                 // volitelné
  "dateMode": "fixed",                // fáze 1: jen fixed; jiná hodnota = „not implemented"
  "created": "2026-08-26T10:00:00Z",
  "counts": { "setup": 5, "persons": 12, "items": 8, "docs": 15, "registry": 4, "mail": 6 }
}
```

`counts` jsou informativní — počty **souborů** per sekce; nesoulad při seedu
je varování, ne chyba. Třídy: `DatasetManifest`, `DatasetReader` (složka i
zip, guard proti `..`), `DatasetWriter` (`modules/core/exchange/src/Dataset/`).

### Sekce `setup/` (R1)

`ds-reset` + provisionery obnoví jednotky, druhy položek, účtový rozvrh,
fiskální roky, DPH období, číselné řady a default mailbox — ale jen když
znají rozhodovací parametry DS, a neobnoví číselníky, které jsou uživatelská
data. Sada proto nese (`SetupExporter::TABLES`):

| Soubor | Tabulka | Klíč | Poznámka |
|--------|---------|------|----------|
| `settings.jsonc` | `core_system_settings` | `key` | jen `economy.*` + `app.name/shortName/theme/shell`; aplikuje se **před** resetem (tabulka je `keepOnReset`, provisionery ji čtou) |
| `bank_accounts.jsonc` | `economy_codebooks_bank_accounts` | `code` | vlastní účty (vydané faktury ve 40 ho vyžadují) |
| `cost_centers.jsonc` | `economy_codebooks_cost_centers` | `code` | střediska — doklady v sadě na ně odkazují kódem v objektu `dimensions` (#110 T2) |
| `vat_registrations.jsonc` | `economy_codebooks_vat_registrations` | `country`, `name` | `DocDocument` vyžaduje při `vat_mode != 0` |
| `binders.jsonc` | `base_registry_binders` | `name` | šanony spisovny |
| `mailboxes.jsonc` | `core_mail_mailboxes` | `mailbox_id` | `default` po resetu existuje, jen se aktualizuje |

Řádky = sloupce tabulky bez `id`, `docStateMain`, auditních sloupců a FK
(účet z rozvrhu se přenáší číslem, ostatní FK se s varováním vynechají).
Seed = upsert přes `TableGateway` podle klíče. Soubory pro tabulky, které na
cílovém DS nejsou (jiná sada modulů), se přeskočí.

### Obálka spisovny `shpd.dataset.registryDocument.v1` (R3)

`shpd.registry.document.v1` nenese stav, původ, šanon ani přílohy —
obálka je doplňuje: `{format, document: <canonical>, docState, sourceKind,
binder (název), refNumber, validFrom, validTo, notice, created,
attachments[]}`. Schéma `modules/base/registry/schemas/`. Seed staví data
přes `RegistryApplier::buildDocumentData()` a vkládá Document hooky
(chybějící šanon založí). Dokumenty spisovny přílohy **vlastní** (kopie
ze zprávy), doklady ne — ty je zobrazují ze zdrojové zprávy přes
`source_message`, proto stačí obnovit lineage.

### `shpd.mail.incomingMessage.v1` (D2, R4)

Schéma `modules/core/mail/schemas/`. Hlavičky, tělo, stavy (`docState`,
`analysisState`), `target` (vazba na doklad / dokument), `attachments[]`
(`isRawSource` pro `.eml`), `analyses[]` — snapshot běhů s `analysisJson` a
`canonicalJson` verbatim. Odkazy `att:<id>` v canonicalu se při dumpu
přepisují na `att:<pořadí v attachments[]>` (1-based) a při seedu zpět na
nová id — jinak by apply návrhu ze seedované zprávy nesedělo a sada by
nebyla stabilní. `message_id` (`MSG-…`) se zachovává;
`IncomingMessageDocument` generuje jen prázdný.

## 2. `dataset-dump`

`shpd-ds dataset-dump <dir> [--zip[=cesta]] [--force] [--name] [--title] [--description]`
(reference `docs/cli.md`). Bere všechny záznamy s `docState != 90` (archiv
80 ano, koš ne). `DatasetDumper` řídí exportery podle aktivních tabulek DS:
`PersonExporter`, `ItemExporter`, `DocumentExporter`, `SetupExporter`
(`modules/core/exchange/src/Export/`), `RegistryExporter`
(`modules/base/registry/src/`), `MailExporter`
(`modules/core/mail/src/Dataset/`). Každý má `exportAll()` a
`exportByIds()` (R7 — integrační test) a `getWarnings()`; varování příkaz
vypíše, exit code zůstává 0.

Exportery jsou zrcadlem applierů (`DocumentApplier::transform()`,
`PersonApplier::transformHeaderForCreate`, …). Odvozené sloupce se
neexportují (fiskální rok, DPH období, domácí měna, snapshoty partnera,
účetní stav, rekapitulace je jen informativní) — při seedu je spočítá
`DocDocument`.

**Dimenze deníku na dokladech** (#110 T2): `DocumentExporter` vypisuje
objekt `dimensions` na hlavičce i řádcích s přirozeným klíčem
(`exchangeKey` dimenze), ale jen u dimenzí, jejichž cílovou tabulku sada
nese v `setup/` — dnes středisko (`cost_centers.jsonc`). Majetek na
dokladech se do sady nepřenáší (karty majetku nejsou součást sady a seed by
doklad odmítl `dimension_not_found`); dump to ohlásí jedním varováním per
dimenze s počtem dokladů.

## 3. `dataset-seed`

`shpd-ds dataset-seed <dir|zip> [--no-reset] [-y]`. Průběh:

1. **Preflight** (`DatasetPreflight`): manifest, každý soubor proti schématu
   (objektová forma — `{}` nesmí zdegenerovat na `[]`), sémantické validátory
   (`PersonValidator`, `ItemValidator`, `DocumentValidator`), přítomnost
   příloh, `counts`. Chyba = nic se nemění.
2. Potvrzení (bez `-y`).
3. `setup/settings.jsonc` → `SettingsStore` (jen v reset režimu).
4. `ds-reset --yes` delegací ve stejném procesu (respektuje `enableReset`).
   `--no-reset` krok přeskočí: osoby a položky jedou v `mergeAdd`, duplicitní
   číslo dokladu / kód zprávy je chyba záznamu.
5. `DatasetSeeder` → seedery v pořadí sekcí (`SectionSeeder`):
   `SetupSeeder`, `PersonSeeder`, `ItemSeeder`, `DocumentSeeder`
   (applieři; `DocumentApplier` s `DocumentEventDispatcher` → doklady ve 40
   se zaúčtují), `RegistrySeeder`, `MailSeeder` (zpráva + přílohy přes
   `AttachmentService` + analýzy + lineage `target_table_id/target_row` ↔
   `source_message`; jedna zpráva = jedna transakce).
6. Souhrn ok / failed / skipped per sekce, varování, chyby; nenulový exit
   code při jakékoli chybě záznamu.

Applieři cílí jen stavy 10/40 — stav 70/80 osob a položek seed po uložení
dorovná přímým UPDATE. Varování applierů (např. `account_not_found`, když DS
nemá účtový rozvrh) se propouštějí do souhrnu.

## 4. Round-trip a co formát nenese

Ověřeno na jednorázovém DS (`ds-create` → `dataset-seed` sady z dev DS →
`dataset-dump`): dump → seed → dump je **byte-shodný modulo
`manifest.created`** (a `title`, který bez `--title` defaultuje na název DS).
Proti zdrojovému DS shodné počty osob/adres/účtů/položek/dodavatelských
kódů/dokladů/zpráv/příloh i deník (40 řádků, součty MD = D). Integrační
test: `tests/Integration/Dataset/DatasetRoundTripTest.php`.

Vědomé normalizace a limity fáze 1:

- `rows[].orderPos` applier přečísluje od 1 (zdroj s `order_pos = 0` se
  po prvním cyklu ustálí).
- `source.fetchedAt` osob a položek se neexportuje — applier razítkuje
  okamžik importu.
- Doklady importované s `importNumber` nestaví snapshoty partnera
  (`DocDocument::importMode`); detail dokladu bere partnera živě.
- Řádkový partner účetního dokladu a hlavičkový partner `cmnbkp` se
  nepřenáší (formát ho nemá) → warning při dumpu.
- Vlastní bankovní účet bez `code` v číselníku nelze přenést → warning.
- `app.icon` / `app.companyLogo` (soubory v `branding/`) se nepřenáší.
- Fiskální roky po resetu zakládá provisioner jen pro aktuální rok.
- Mimo rozsah: `dateMode: relative`, filtry dumpu, scénářové akce a
  expected výsledky, live mode AI, banka a saldokonto.
