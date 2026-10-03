# core_attachments_files

Tabulka příloh dokumentů. Umožňuje připojit libovolný počet souborů k libovolnému záznamu v systému.

## Vazba na cílový záznam

Příloha je navázána na cílový záznam dvojicí `table_id` + `record_id`:

- `table_id` — numerické ID tabulky (`tableId` z JSONC definice), SMALLINT pro kompaktní indexy
- `record_id` — primární klíč záznamu v cílové tabulce

Příklad: příloha navázaná na osobu s `id = 42` v tabulce `base_persons_persons` (`tableId: 201`) bude mít `table_id = 201`, `record_id = 42`.

## Soubory na disku

### Adresářová struktura

```
data-sources/{ds-id}/att/{YYYY}/{MM}/{DD}/{table_name}/{file_name}
```

Příklad:
```
data-sources/a3f2-b8c1-d4e7-f9a0/att/2026/04/15/base_persons_persons/faktura-sd262-a7k2m.pdf
```

Cesta se v tabulce ukládá rozděleně:
- `file_path` = `2026/04/15/base_persons_persons` (relativní adresář bez `att/` prefixu)
- `file_name` = `faktura-sd262-a7k2m.pdf` (název souboru na disku)

Úplná cesta na disku: `{ds_path}/att/{file_path}/{file_name}`

### Řešení duplicity názvů souborů

Při uploadu se ke jménu souboru připojí pětimístný náhodný hash:
```
{original_name}-{5char_hash}.{ext}
```

Hash je náhodný řetězec `[a-z0-9]{5}` generovaný kryptograficky bezpečným způsobem. Příklady:
- `faktura.pdf` → `faktura-a7k2m.pdf`
- `scan 001.jpg` → `scan-001-x9b3p.jpg` (mezery se nahrazují pomlčkou)

### Uživatelský název vs. název souboru

- `name` — zobrazovaný název přílohy v UI. Při uploadu se nastaví na originální název souboru (bez hashe). Uživatel může kdykoli přejmenovat.
- `file_name` — skutečný název souboru na disku. Po uploadu se nemění.

## Kontrolní součet (checksum)

SHA-256 hash obsahu souboru (64 znaků hex). Slouží pro:

- **Detekce duplicit** — při uploadu se zkontroluje, zda soubor se stejným checksumem již existuje u téhož záznamu. Pokud ano, API vrátí upozornění (ne chybu — upload se provede).
- **Ověření integrity** — kontrola, že soubor nebyl poškozen.

Každá příloha má vlastní fyzický soubor — nepoužívá se content-addressable storage.

## Metadata (JSON)

Sloupec `metadata` obsahuje volitelná strukturovaná data extrahovaná při uploadu. Obsah závisí na typu souboru:

### Obrázky (image/jpeg, image/png, image/gif, image/webp)
```json
{
    "width": 1920,
    "height": 1080
}
```

### PDF (application/pdf)
```json
{
    "pages": 12
}
```

Metadata se extrahují při zpracování uploadu. Pokud extrakce selže, `metadata` zůstane `null` — neblokuje upload.

## Soft-delete

Sloupec `is_deleted` (boolean). Při "smazání" přílohy se nastaví na `1`:
- Příloha se přestane zobrazovat v UI
- Fyzický soubor zůstává na disku
- Příloha lze obnovit (nastavit `is_deleted = 0`)
- V1 neimplementuje automatické promazávání — fyzické soubory zůstávají

## Řazení

Přílohy se řadí podle `att_order ASC, name ASC`. Sloupec `att_order` umožňuje ruční řazení (drag-and-drop v UI), `name` slouží jako sekundární řazení pro přílohy se stejným pořadím.

## Odeslání se záznamem

Sloupec `send_with_record` (boolean, výchozí `0`) říká, že se příloha posílá
spolu se záznamem — u dokladu „Odeslat s dokladem“. Nová příloha (upload,
kopie, příloha z pošty nebo spisovny) má `0`, pokud volající výslovně
neřekne jinak. Smazaná příloha (`is_deleted = 1`) se neposílá bez ohledu
na příznak.

## Sloupce

| Sloupec | Typ | Popis |
|---------|-----|-------|
| `id` | int, PK | Primární klíč |
| `table_id` | smallint | Numerické ID cílové tabulky |
| `record_id` | int | ID záznamu v cílové tabulce |
| `name` | varchar(500) | Zobrazovaný název přílohy |
| `file_name` | varchar(500) | Skutečný název souboru na disku |
| `file_path` | varchar(200) | Relativní cesta k adresáři (bez `att/` prefixu) |
| `file_size` | bigint | Velikost souboru v bajtech |
| `mime_type` | varchar(100) | MIME typ souboru |
| `checksum` | varchar(64) | SHA-256 hash obsahu souboru |
| `metadata` | json | Metadata souboru (rozměry, počet stran apod.) |
| `att_order` | smallint | Pořadí přílohy (pro ruční řazení) |
| `is_deleted` | boolean | Příznak soft-delete |
| `send_with_record` | boolean | Příloha se posílá spolu se záznamem (výchozí `0`) |
| `created` | datetime | Datum a čas nahrání |
| `created_by` | int | Uživatel, který přílohu nahrál |
| `modified` | datetime | Datum a čas poslední změny |

## Indexy

| Index | Typ | Sloupce | Účel |
|-------|-----|---------|------|
| `idx_table_record` | index | `table_id`, `record_id`, `is_deleted`, `att_order`, `name` | Hlavní dotaz: přílohy záznamu seřazené |
| `idx_checksum` | index | `checksum` | Vyhledávání duplicit |
| `idx_created` | index | `created DESC` | Řazení podle data nahrání |
| `ft_name` | fulltext | `name` | Fulltextové hledání v názvech příloh |
