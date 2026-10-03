# Shipard — Systém příloh dokumentů

## 1. Přehled

Systém příloh umožňuje připojit libovolný počet souborů k libovolnému záznamu v systému. Přílohy se ukládají jako fyzické soubory na disku s metadaty v databázové tabulce `core_attachments_files`.

Klíčové vlastnosti:
- Univerzální vazba přes `table_id` + `record_id` — funguje pro libovolnou tabulku
- Stromová struktura úložiště na disku (rok/měsíc/den/tabulka)
- Generování náhledů on-demand s diskovou cache
- Soft-delete s možností obnovení
- Detekce duplicit přes SHA-256 checksum
- Automatická extrakce metadat (rozměry obrázků, počet stran PDF)

---

## 2. Datový model

### Tabulka `core_attachments_files`

| Sloupec | Typ | Popis |
|---------|-----|-------|
| `id` | int, PK | Primární klíč |
| `table_id` | smallint | Numerické `tableId` cílové tabulky (z JSONC definice) |
| `record_id` | int | Primární klíč záznamu v cílové tabulce |
| `name` | varchar(500) | Zobrazovaný název přílohy (uživatel může přejmenovat) |
| `file_name` | varchar(500) | Skutečný název souboru na disku (neměnný po uploadu) |
| `file_path` | varchar(200) | Relativní cesta k adresáři (bez `att/` prefixu) |
| `file_size` | bigint | Velikost souboru v bajtech |
| `mime_type` | varchar(100) | MIME typ souboru |
| `checksum` | varchar(64) | SHA-256 hash obsahu souboru |
| `metadata` | json | Metadata souboru (volitelné — rozměry, počet stran) |
| `att_order` | smallint | Pořadí přílohy (manuální řazení, výchozí 0) |
| `is_deleted` | boolean | Příznak soft-delete |
| `send_with_record` | boolean | Příloha se posílá spolu se záznamem (#94 D6, výchozí `0`) |
| `created` | datetime | Datum a čas nahrání |
| `created_by` | int | FK na `core_system_users` |
| `modified` | datetime | Datum a čas poslední změny |

**Odeslání se záznamem.** `send_with_record` určuje, které přílohy se posílají
spolu se záznamem (u dokladu „Odeslat s dokladem“). Název je obecný — příloha
patří libovolnému záznamu. Nová příloha (upload, `copyTo`, příloha z pošty
nebo spisovny) má `0`, pokud volající výslovně neřekne jinak. Smazaná
příloha (`is_deleted = 1`) se neposílá bez ohledu na příznak.

Podrobná dokumentace tabulky: `modules/core/attachments/tables/core_attachments_files.md`

---

## 3. Úložiště souborů

### Adresářová struktura

```
data-sources/{ds-id}/
├── att/                                    # Kořen úložiště příloh
│   └── 2026/
│       └── 04/
│           └── 15/
│               └── base_persons_persons/
│                   ├── faktura-sd262-a7k2m.pdf
│                   └── logo-firmy-p3x9w.png
├── cache/
│   └── thumbnails/                         # Cache generovaných náhledů
│       ├── a1b2c3d4e5f6...sha256.jpg      # Náhledy pojmenované SHA-256 hashem parametrů
│       └── ...
└── config/
    └── ...
```

### Konvence pojmenování souborů

Při uploadu se ke jménu souboru připojí pětimístný náhodný hash pro zabránění kolizím:

```
{sanitized_name}-{5char_hash}.{ext}
```

Sanitizace názvu:
- Mezery → pomlčky
- Diakritika → zachová se (filesystem je UTF-8)
- Speciální znaky (`/`, `\`, `..`, `\0`) → odstraní se
- Více po sobě jdoucích pomlček → jedna pomlčka

Příklady:
- `Faktura č. 2026-001.pdf` → `Faktura-č.-2026-001-a7k2m.pdf`
- `scan 001.jpg` → `scan-001-x9b3p.jpg`

Hash je `[a-z0-9]{5}` generovaný přes `random_int()`.

### Co se ukládá v DB

- `file_path` = `2026/04/15/base_persons_persons` — relativní cesta bez `att/` prefixu
- `file_name` = `Faktura-č.-2026-001-a7k2m.pdf` — název souboru na disku

Kompletní cesta na disku: `{ds_path}/att/{file_path}/{file_name}`

V cestě se používá stringový název tabulky (ne numerický `table_id`) — pro snadnou orientaci při prohlížení souborového systému.

---

## 4. API endpointy

Přílohy mají vlastní endpointy pod prefixem `_attachments` (mimo standardní CRUD, protože upload vyžaduje `multipart/form-data`).

### Upload

```
POST /api/v1/_attachments/upload
Content-Type: multipart/form-data

Fields:
  table_id: 201          # smallint — numerické tableId cílové tabulky
  record_id: 42           # int — ID záznamu
  file: (binary)          # soubor
  send_with_record: 1     # volitelné, "1" | "0" — příloha se posílá se záznamem
```

Bez pole `send_with_record` má nová příloha příznak `0`. Pole je cesta pro
import (přílohy označené k odeslání ve starém systému, #94 D9); z UI se
příznak nastavuje až u nahrané přílohy přes PATCH.

Odpověď — úspěch (201 Created):
```json
{
    "success": true,
    "data": {
        "id": 1,
        "table_id": 201,
        "record_id": 42,
        "name": "Faktura č. 2026-001.pdf",
        "file_name": "Faktura-č.-2026-001-a7k2m.pdf",
        "file_path": "2026/04/15/base_persons_persons",
        "file_size": 245760,
        "mime_type": "application/pdf",
        "checksum": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
        "metadata": {
            "pages": 3
        },
        "att_order": 0,
        "is_deleted": false,
        "send_with_record": false,
        "created": "2026-04-15T14:30:00+02:00",
        "created_by": 1
    }
}
```

Odpověď — úspěch s upozorněním na duplicitu:
```json
{
    "success": true,
    "data": { ... },
    "warning": {
        "code": "DUPLICATE_CHECKSUM",
        "message": "Soubor se shodným obsahem již existuje u tohoto záznamu",
        "existing_attachment_id": 5
    }
}
```

### Upload — zpracování na serveru

```
POST /api/v1/_attachments/upload
│
├─ 1. Validace parametrů (table_id, record_id, soubor)
│     → table_id musí existovat v definicích tabulek
│     → record_id musí existovat v cílové tabulce
│
├─ 2. Sanitizace názvu souboru
│     → Nahrazení mezer pomlčkami, odstranění nebezpečných znaků
│     → Připojení 5-char hashe
│
├─ 3. Výpočet SHA-256 checksumu
│
├─ 4. Kontrola duplicit
│     → SELECT id FROM core_attachments_files
│       WHERE table_id = ? AND record_id = ? AND checksum = ? AND is_deleted = 0
│     → Pokud nalezena → nastavit warning v odpovědi (upload pokračuje)
│
├─ 5. Vytvoření adresáře
│     → att/{YYYY}/{MM}/{DD}/{table_name}/
│
├─ 6. Uložení souboru na disk
│     → Zápis do cílového adresáře
│
├─ 7. Detekce MIME typu
│     → PHP finfo_file() na skutečný obsah souboru (ne příponu)
│
├─ 8. Extrakce metadat
│     → Obrázky: getimagesize() → width, height
│     → PDF: pdftocairo nebo pdfinfo → počet stran
│     → Ostatní: null
│
├─ 9. INSERT do core_attachments_files
│
└─ 10. Vrátit odpověď s vytvořeným záznamem
```

### Download

```
GET /api/v1/_attachments/{id}/download
```

Odpověď: binární obsah souboru s hlavičkami:
```
Content-Type: application/pdf
Content-Disposition: attachment; filename="Faktura č. 2026-001.pdf"
Content-Length: 245760
```

Název v `Content-Disposition` odpovídá sloupci `name` (zobrazovaný název), ne `file_name` (název na disku).

### Náhled (thumbnail)

```
GET /api/v1/_attachments/{id}/thumbnail?w=500&q=90&page=1
```

| Parametr | Typ | Výchozí | Popis |
|----------|-----|---------|-------|
| `w` | int | 300 | Šířka náhledu v pixelech |
| `q` | int | 85 | Kvalita JPEG (1–100) |
| `page` | int | 1 | Číslo stránky (pro PDF) |

Pravidla:
- Poměr stran se vždy zachovává
- Šířka je jediný rozměrový parametr (výška se dopočítá)
- Maximální šířka: 2000 px
- Výstupní formát: vždy JPEG

Odpověď: binární JPEG s hlavičkami:
```
Content-Type: image/jpeg
Cache-Control: public, max-age=31536000
```

### Náhled — zpracování na serveru

```
GET /api/v1/_attachments/{id}/thumbnail?w=500&q=90&page=1
│
├─ 1. Načíst záznam přílohy z DB
│
├─ 2. Sestavit klíč cache
│     → SHA-256("{id}:{w}:{q}:{page}:{checksum}")
│     → Cesta: cache/thumbnails/{hash}.jpg
│
├─ 3. Kontrola cache
│     → Pokud soubor existuje → vrátit přímo
│
├─ 4. Generování náhledu (podle MIME typu)
│     ├─ application/pdf → pdftocairo
│     │   pdftocairo -jpeg -f {page} -l {page} -scale-to-x {w} -scale-to-y -1 input.pdf output
│     ├─ image/svg+xml → rsvg-convert
│     │   rsvg-convert -w {w} -f png input.svg | vipsthumbnail --size={w} -o output.jpg[Q={q}]
│     ├─ image/* → libvips
│     │   vipsthumbnail input.jpg --size={w} -o output.jpg[Q={q}]
│     └─ ostatní → vrátit generickou ikonu podle MIME typu
│
├─ 5. Uložit výsledek do cache
│
└─ 6. Vrátit JPEG s cache hlavičkami
```

### Seznam příloh záznamu

```
GET /api/v1/_attachments?table_id={tableId}&record_id={recordId}
```

Vrací seznam příloh seřazený podle `att_order ASC, name ASC`. Soft-deleted přílohy se ve výchozím stavu nezobrazují.

Volitelný parametr `include_deleted=1` zobrazí i smazané přílohy (pro UI koše).

Odpověď:
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "Faktura č. 2026-001.pdf",
            "file_size": 245760,
            "mime_type": "application/pdf",
            "metadata": {"pages": 3},
            "att_order": 0,
            "send_with_record": true,
            "created": "2026-04-15T14:30:00+02:00",
            "thumbnail_url": "/api/v1/_attachments/1/thumbnail?w=300"
        },
        {
            "id": 2,
            "name": "Logo firmy.png",
            "file_size": 84521,
            "mime_type": "image/png",
            "metadata": {"width": 800, "height": 600},
            "att_order": 1,
            "created": "2026-04-15T14:31:00+02:00",
            "thumbnail_url": "/api/v1/_attachments/2/thumbnail?w=300"
        }
    ],
    "meta": {
        "total": 2
    }
}
```

### Přejmenování

```
PATCH /api/v1/_attachments/{id}
Content-Type: application/json

{
    "name": "Nový název přílohy.pdf"
}
```

Mění pouze zobrazovaný `name`. Sloupec `file_name` (název na disku) se nemění.

### Změna pořadí

```
PATCH /api/v1/_attachments/{id}
Content-Type: application/json

{
    "att_order": 5
}
```

### Odeslat se záznamem

```
PATCH /api/v1/_attachments/{id}
Content-Type: application/json

{
    "send_with_record": true
}
```

Zapne / vypne odeslání přílohy spolu se záznamem (#94 D6). Hodnota musí být
`true` nebo `false` (jinak 422, nic se nezmění). Pole jde poslat samostatně
i spolu s `name` / `att_order`; tělo se nejdřív celé zvaliduje, pak se změny
provedou.

**Zámek záznamu příznak neblokuje** (`documentLockProviders`, read-only stav
dokladu) — není to obsah dokladu a typicky se nastavuje u dokladu ve stavu
V pořádku těsně před odesláním. Přes guard cílové tabulky ale jde
(`AttachmentGuard::OPERATION_SEND_FLAG`), stejně jako přejmenování a pořadí;
odmítnutí guardem je u celého PATCH **409 `ATTACHMENT_LOCKED`**.

**Kontrakt pro odeslání záznamu** (konzument: odeslání dokladu, #90 fáze 4):
posílají se přílohy záznamu s `send_with_record = 1` a `is_deleted = 0`
v pořadí `att_order ASC, name ASC`. Co se s nimi při odeslání stane (spojení
PDF do dokladu × samostatné soubory), určuje volba osoby — `docs/prints.md`
→ Volby osoby pro odeslání.

### Smazání (soft-delete)

```
DELETE /api/v1/_attachments/{id}
```

Nastaví `is_deleted = 1`. Fyzický soubor zůstává na disku.

### Obnovení ze smazaných

```
POST /api/v1/_attachments/{id}/restore
```

Nastaví `is_deleted = 0`.

---

## 5. Generování náhledů — nástroje

### PDF → JPEG: pdftocairo

```bash
pdftocairo -jpeg -f 1 -l 1 -scale-to-x 500 -scale-to-y -1 input.pdf /tmp/output
# Vytvoří /tmp/output-01.jpg
```

Parametry:
- `-jpeg` — výstupní formát
- `-f 1 -l 1` — první stránka (page parametr z API)
- `-scale-to-x 500` — šířka 500 px
- `-scale-to-y -1` — výška se dopočítá (zachování poměru stran)

Balíček: `poppler-utils`

### SVG → JPEG: rsvg-convert + libvips

```bash
# SVG → PNG (rsvg-convert neumí přímo JPEG)
rsvg-convert -w 500 input.svg -o /tmp/output.png

# PNG → JPEG s kvalitou
vips jpegsave /tmp/output.png /tmp/output.jpg --Q 90
```

Balíček: `librsvg2-bin`

### Obrázky → JPEG: libvips

```bash
vipsthumbnail input.jpg --size=500 -o output.jpg[Q=90]
```

Balíček: `libvips-tools`

### Proč libvips místo ImageMagick

- **Rychlost** — libvips je 4–8× rychlejší díky streaming architektuře
- **Paměť** — zpracovává obrázky po řádcích, nenahrává celý soubor do RAM
- **Bezpečnost** — menší útočná plocha (ImageMagick měl historicky řadu CVE)

### Extrakce textu: pdftotext (`TextExtractor`)

Pro fulltext (`extracted_text` sloupce, např. Spisovna) extrahuje
`TextExtractor` plný text přílohy:

```bash
pdftotext -layout input.pdf -    # PDF → text na stdout
```

- `application/pdf` → `pdftotext -layout` (drží sloupce tabulek u sebe)
- `text/*` → přímé čtení souboru
- ostatní MIME → `null` (žádná OCR — skenované PDF vrátí prázdno)

Výstup je UTF-8 sanitizovaný (nevalidní sekvence a řídicí znaky kromě
`\n`/`\t` vypuštěné) a zastropovaný na 500 000 znaků. **Best-effort:**
chybějící binárka, rozbitý soubor i nepodporovaný formát vrací `null`
a nikdy neblokují volající flow — chybějící `pdftotext` hlásí
`shpd-server doctor` (balíček `poppler-utils`, sdílený s `pdftocairo`).

Zápis do `base_registry_documents.extracted_text` zajišťuje
`ExtractedTextFiller` (modul `base.registry`) — volá se po commitu vzniku
dokumentu, přímým UPDATE mimo Document hooky (nesmí bumpnout `modified`,
na kterém stojí unapply guard AI cesty).

---

## 6. Cache náhledů

### Umístění

```
data-sources/{ds-id}/cache/thumbnails/
```

Cache je oddělená od `att/` — lze ji kdykoliv smazat a náhledy se přegenerují při dalším požadavku.

### Pojmenování souborů

Název souboru v cache je SHA-256 hash parametrů:

```
SHA-256("{attachment_id}:{width}:{quality}:{page}:{file_checksum}")
```

Příklad: `cache/thumbnails/a1b2c3d4e5f6789...abc.jpg`

Použití `file_checksum` v klíči zajistí, že pokud by se v budoucnu implementovalo nahrazování souborů, cache se automaticky invaliduje.

### Invalidace

- Při smazání přílohy se cache neinvaliduje — soubory v cache jsou malé a přegenerují se, pokud je příloha obnovena
- CLI příkaz pro vyčištění cache (budoucí): `shpd-ds cache-clear --thumbnails`

---

## 7. Frontend — integrace do UI

### Tab Přílohy v editačním formuláři

Formuláře pro záznamy, které podporují přílohy, zobrazí tab „Přílohy" (`attachments`). Tab obsahuje:

- Grid náhledů příloh (kliknutí → otevření/stažení)
- Tlačítko „Přidat přílohu" (klasický file input)
- Drag-and-drop zona pro přetažení souborů
- Kontextové menu na příloze: přejmenovat, smazat, stáhnout
- Přepínač **„Odeslat s dokladem"** u každé přílohy — jen u formulářů, které
  o něj požádají: `TableForm::attachmentsTab(sendFlag: true)` → `FormTab`
  JSON `send_flag: true` → prop `sendFlag` komponenty `AttachmentPanel`.
  Zatím faktura vydaná a zálohová faktura (`IssuedInvoiceFormBase` přes hook
  `DocsHeadsFormBase::attachmentsSendable()`). Přepínač má vlastní vypínač
  `sendFlagDisabled` — zůstává aktivní i u dokladu jen pro čtení (V pořádku,
  zámek), kde je zbytek panelu `disabled`; neaktivní je jen v režimu
  prohlížení (`readOnly` prop formuláře, pak se ukazuje jen značka)
  a během ukládání.

Read-only grid (`AttachmentGrid` — detail dokladu, náhledy vedle formuláře)
ukazuje u příloh s příznakem značku „Odeslat s dokladem", bez přepínání.

### Detail prohlížeče

V detailu záznamu v prohlížeči se přílohy zobrazují buď:
- Jako samostatný tab „Přílohy" s gridem náhledů
- Jako velké náhledy přímo v primárním tabu (konfigurováno per tabulka)

### Drag-and-drop

Podpora přetažení souboru na:
- Řádek prohlížeče → upload přílohy k danému záznamu
- Otevřený editační formulář → upload přílohy k editovanému záznamu

---

## 8. PHP třídy

### Umístění

```
modules/core/attachments/src/
├── AttachmentDocument.php     # Document třída (validate, beforeSave)
├── AttachmentService.php      # Business logika — upload, download, thumbnail
├── FileStorage.php            # Nízkoúrovňové operace se soubory na disku
├── TextExtractor.php          # Extrakce textu pro fulltext (pdftotext)
└── ThumbnailGenerator.php     # Generování náhledů (pdftocairo, rsvg, vips)
```

### AttachmentDocument

```php
namespace Shipard\Module\Core\Attachments;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class AttachmentDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['table_id'])) {
            $result->addError('table_id', 'ID tabulky je povinné', 'required');
        }
        if (empty($data['record_id'])) {
            $result->addError('record_id', 'ID záznamu je povinné', 'required');
        }
        if (empty($data['name'])) {
            $result->addError('name', 'Název přílohy je povinný', 'required');
        }

        return $result;
    }
}
```

### AttachmentService

Hlavní business logika. Metody:

- `upload(int $tableId, int $recordId, UploadedFile $file, ?int $userId): DocumentResult`
  - Sanitizace názvu, výpočet checksumu, uložení souboru, extrakce metadat, INSERT do DB
  - Vrací DocumentResult s vytvořeným záznamem + volitelný warning pro duplicitu

- `copyTo(int $attachmentId, int $targetTableId, int $targetRecordId, ?int $userId): array`
  - Kopie existující přílohy k jinému záznamu (typicky jiné tabulky) —
    vzor: zařazení zprávy do Spisovny (D8 — kopie, ne přesun)
  - Fyzicky zkopíruje soubor přes `FileStorage::copy()` do dnešního
    adresáře cílové tabulky s **novým 5-char hashem v názvu** (starý hash
    suffix se nejdřív odřízne, aby se nekumulovaly)
  - Nový řádek `core_attachments_files`: `name`, `att_order`, `metadata`,
    `mime_type` převzaté ze zdroje, `checksum` shodný (obsah identický),
    `created_by = $userId`; zdrojový řádek i soubor zůstávají netknuté
  - Duplicitní checksum u **cílového** záznamu → non-fatal warning
    `DUPLICATE_CHECKSUM` (stejná sémantika jako upload)
  - Selhání kopie souboru → výjimka, žádný DB zápis; selhání INSERTu →
    zkopírovaný soubor se unlinkne (žádné orphany). Volající s vnější
    transakcí musí při rollbacku unlinkovat `data.file_path`/`data.file_name`
    sám (vzor `MailController::cleanupOrphanedFiles`)
  - Vrací stejný tvar výsledku jako `upload`:
    `{success, data?, warning?, error?}`

- `download(int $attachmentId): StreamedResponse`
  - Načte záznam, sestaví cestu, vrátí soubor se správnými hlavičkami

- `thumbnail(int $attachmentId, int $width, int $quality, int $page): StreamedResponse`
  - Zkontroluje cache, pokud miss → zavolá ThumbnailGenerator, uloží do cache

- `setSendWithRecord(int $attachmentId, bool $value): bool`
  - Zapne / vypne `send_with_record` (#94 D6); ptá se guardů s operací
    `AttachmentGuard::OPERATION_SEND_FLAG`, odmítnutí = `DomainException`
  - `upload()` má k tomu volitelný parametr `$sendWithRecord` (výchozí
    `false`); `copyTo()` příznak **nepřenáší** — kopie u jiného záznamu se
    neposílá, dokud to tam někdo výslovně nezapne

- `softDelete(int $attachmentId): DocumentResult`
  - Nastaví `is_deleted = 1`

- `restore(int $attachmentId): DocumentResult`
  - Nastaví `is_deleted = 0`

### ThumbnailGenerator

Generování náhledů — volá CLI nástroje:

- `generatePdf(string $inputPath, string $outputPath, int $width, int $quality, int $page): bool`
- `generateSvg(string $inputPath, string $outputPath, int $width, int $quality): bool`
- `generateImage(string $inputPath, string $outputPath, int $width, int $quality): bool`

Každá metoda vrací `bool` — `true` při úspěchu, `false` pokud CLI nástroj selže. Selhání generování náhledu není fatální — API vrátí generickou ikonu.

---

## 9. Konfigurace

### Velikost uploadu — nginx + PHP

Limity žijí ve **verzovaných include souborech** v repu (zdroj pravdy),
site config a FPM pool je includují — nic se neopisuje ručně:

- `docs/nginx/shipard-common.conf` — `client_max_body_size 128M`
  (include v každém server bloku)
- `docs/php/shipard-fpm-common.conf` — `upload_max_filesize = 128M`,
  `post_max_size = 130M` (mírně větší kvůli multipart overhead;
  `include=` v pool.d/shipard.conf)

Změna hodnoty = úprava include souboru + `git pull` + reload služby;
`shpd-server upgrade` reload provede sám. Chybějící include řádky v živých
configech hlásí `shpd-server doctor`. Detaily: `docs/operations/production.md` §6.

### Systémové závislosti

Balíčky pro generování náhledů instaluje `scripts/install-packages.sh`
automaticky; chybějící binárky hlásí `shpd-server doctor`. Pro ruční
instalaci:

```bash
# Generování náhledů + extrakce textu
apt install poppler-utils    # pdftocairo, pdftotext
apt install librsvg2-bin     # rsvg-convert
apt install libvips-tools    # vipsthumbnail, vips
```

---

## 10. Implementační plán pro Claude Code

### Fáze 1 — Modul a tabulka

- `modules/core/attachments/module.jsonc` ✓
- `modules/core/attachments/tables/core_attachments_files.jsonc` ✓
- `modules/core/attachments/tables/core_attachments_files.md` ✓
- `modules/core/attachments/README.md` ✓
- Přidat `core.attachments` do závislostí `install.base`
- Spustit `shpd-ds ds-upgrade` pro vytvoření tabulky

### Fáze 2 — FileStorage

- `modules/core/attachments/src/FileStorage.php`
  - `store(string $dsPath, string $tableName, string $originalName, string $tmpPath): FileInfo`
  - `getFullPath(string $dsPath, string $filePath, string $fileName): string`
  - `sanitizeFileName(string $name): string`
  - `generateHash(): string`
- Testy: sanitizace názvů, vytvoření adresářové struktury, uložení souboru

### Fáze 3 — ThumbnailGenerator

- `modules/core/attachments/src/ThumbnailGenerator.php`
  - Metody pro PDF, SVG, obrázky
  - Cache logika (klíč, kontrola, uložení)
- Testy: generování pro každý typ, cache hit/miss

### Fáze 4 — AttachmentDocument + AttachmentService

- `modules/core/attachments/src/AttachmentDocument.php`
- `modules/core/attachments/src/AttachmentService.php`
  - Upload flow (validace → uložení → metadata → DB)
  - Download flow
  - Thumbnail flow (s cache)
  - Soft-delete + restore
- Testy: upload, duplicita checksumu, download, soft-delete/restore

### Fáze 5 — API endpointy

- Registrace endpointů `_attachments/*` v routeru
- Controller pro upload (multipart), download, thumbnail, list, PATCH, DELETE, restore
- Testy: API testy pro všechny endpointy

### Fáze 6 — Frontend

- Svelte komponenta pro tab Přílohy (grid náhledů, upload, drag-and-drop)
- Integrace do systému formulářů
- Integrace do detailu prohlížeče
