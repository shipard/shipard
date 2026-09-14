# Shipard — Definice databázových tabulek

## 1. Přehled

Každá databázová tabulka v Shipard je definována JSONC souborem umístěným v adresáři `tables/` příslušného modulu. Definiční soubor popisuje sloupce, indexy a metadata tabulky. Systém `ds-upgrade` z těchto definic vytváří a aktualizuje databázové tabulky.

---

## 2. Umístění a pojmenování

**Cesta:** `modules/{skupina}/{modul}/tables/{id_tabulky}.jsonc`

**Pojmenování tabulky:** `{skupina}_{modul}_{nazev}`

Příklady:
- `modules/core/system/tables/core_system_users.jsonc`
- `modules/economy/docs/tables/economy_docs_heads.jsonc`

Název souboru (bez přípony) odpovídá názvu tabulky v databázi.

---

## 3. Struktura definičního souboru

### Kompletní příklad

```jsonc
{
    // Unikátní numerické ID tabulky — pro globální referenční tabulky (štítky, přílohy atd.)
    "tableId": 101,

    // Název tabulky pro UI
    "name": "Document heads",
    "name:cs": "Hlavičky dokladů",
    "name:en": "Document heads",

    // Šablona pro zobrazení záznamu, když na tuto tabulku odkazuje jiná tabulka
    "displayPattern": "{doc_number} — {issue_date}",

    // Logické skupiny sloupců pro UI
    "columnGroups": [
        {
            "id": "basic",
            "name": "Basic information",
            "name:cs": "Základní údaje",
            "name:en": "Basic information"
        },
        {
            "id": "financial",
            "name": "Financial details",
            "name:cs": "Finanční údaje",
            "name:en": "Financial details"
        }
    ],

    // Definice sloupců
    "columns": [
        {
            "id": "id",
            "name": "ID",
            "type": "int",
            "autoIncrement": true,
            "primaryKey": true
        },
        {
            "id": "doc_number",
            "name": "Document number",
            "name:cs": "Číslo dokladu",
            "name:en": "Document number",
            "type": "varchar",
            "length": 50,
            "nullable": false,
            "group": "basic"
        },
        {
            "id": "doc_state",
            "name": "Document state",
            "name:cs": "Stav dokladu",
            "name:en": "Document state",
            "type": "enumInt",
            "cfgItem": "economy.docs.docStates",
            "default": 0,
            "group": "basic"
        },
        {
            "id": "doc_type",
            "name": "Document type",
            "name:cs": "Typ dokladu",
            "name:en": "Document type",
            "type": "enumString",
            "length": 10,
            "cfgItem": "economy.docs.docTypes",
            "group": "basic"
        },
        {
            "id": "issue_date",
            "name": "Issue date",
            "name:cs": "Datum vystavení",
            "name:en": "Issue date",
            "type": "date",
            "group": "basic"
        },
        {
            "id": "customer_id",
            "name": "Customer",
            "name:cs": "Odběratel",
            "name:en": "Customer",
            "type": "int",
            "nullable": true,
            "reference": "base_persons_contacts",
            "group": "basic"
        },
        {
            "id": "total_amount",
            "name": "Total amount",
            "name:cs": "Celková částka",
            "name:en": "Total amount",
            "type": "numeric",
            "precision": 12,
            "scale": 2,
            "group": "financial"
        },
        {
            "id": "currency",
            "name": "Currency",
            "name:cs": "Měna",
            "name:en": "Currency",
            "type": "varchar",
            "length": 3,
            "group": "financial"
        },
        {
            "id": "note",
            "name": "Note",
            "name:cs": "Poznámka",
            "name:en": "Note",
            "type": "text",
            "nullable": true
        }
    ],

    // Indexy
    "indexes": [
        {
            "id": "idx_doc_number",
            "type": "unique",
            "columns": [
                {"column": "doc_number"}
            ]
        },
        {
            "id": "idx_date_state",
            "type": "index",
            "columns": [
                {"column": "issue_date", "order": "ASC"},
                {"column": "doc_state", "order": "ASC"}
            ]
        },
        {
            "id": "ft_note",
            "type": "fulltext",
            "columns": [
                {"column": "note"}
            ]
        }
    ]
}
```

---

## 4. Metadata tabulky

### Pole na úrovni tabulky

| Pole | Typ | Povinné | Vícejazyčné | Popis |
|------|-----|---------|-------------|-------|
| `tableId` | int | Ano | Ne | Unikátní numerické ID tabulky (globálně přes celý systém) |
| `name` | string | Ano | Ano | Název tabulky pro UI |
| `displayPattern` | string | Ne | Ne | Šablona pro zobrazení záznamu při referenci z jiné tabulky |
| `columnGroups` | object[] | Ne | — | Definice logických skupin sloupců |
| `columns` | object[] | Ano | — | Definice sloupců |
| `indexes` | object[] | Ne | — | Definice indexů |
| `hideFromNavigation` | bool | Ne | Ne | Skrýt tabulku ze sidebaru (hlavního i Nastavení). Používá se pro sub-tabulky spravované jen přes parent záznam (např. fiskální měsíce). Výchozí: `false` |
| `adminOnly` | bool | Ne | Ne | Tabulka jen pro administrátory — ne-admin dostane 403 na CRUD/viewer/form/lookup a položka se mu nezobrazí v navigaci Nastavení. Výchozí: `false` |

### `tableId` — unikátní numerické ID

Každá tabulka má globálně unikátní numerické ID (`tableId`). Toto ID se používá v globálních referenčních tabulkách (štítky, přílohy, komentáře atd.) místo textového názvu tabulky — zabírá 2 bajty místo 32+ bajtů v indexech.

Pravidla:
- Hodnota je kladné celé číslo (SMALLINT, tj. max 65535)
- Musí být unikátní přes všechny moduly v celém systému
- Přiděluje se ručně při vytváření tabulky
- Unikátnost se kontroluje při `ds-upgrade` — duplicita je fatální chyba
- CLI příkaz `shpd-server next-table-id` projde všechny moduly a vypíše další volné ID

**Rezervované rozsahy.** Pro projekty s externími moduly (viz `docs/modules.md` sekce 10) doporučujeme rozdělení `tableId` do rozsahů:

| Rozsah | Použití |
|--------|---------|
| `1 – 9 999` | Core (oficiální moduly) |
| `10 000 – 19 999` | Custom (in-house zákaznické moduly) |
| `20 000 – 29 999` | Vendor (placené moduly od třetích stran) |
| `30 000 – 65 535` | Rezerva |

Rozsahy nejsou vynuceny — `ds-upgrade` přijme jakýkoliv unikátní `tableId`. Konvence ale usnadňuje paralelní alokaci. Pro alokaci v konkrétním rozsahu použij `bin/shpd-server next-table-id --range=10000:10099` (vrátí první volné ID v rozsahu).

**Vyřazená ID se nerecyklují.** Když tabulka z definic zmizí, na existujících DS zůstává (`ds-upgrade` nemaže, sekce 10) a globální referenční tabulky mohou její `tableId` dál nést. `next-table-id` bez `--range` vrací max + 1, ale s `--range` by uvolněné číslo nabídl znovu — proto seznam vyřazených ID, která nový modul nesmí použít:

| `tableId` | Původní tabulka | Zrušeno |
|---|---|---|
| 419 | `economy_accbal_allocations` (párovací vazby saldokonta) | 2026-09, #69 D1 — případ je agregát z ledgeru |

### `columnGroups` — logické skupiny sloupců

Nepovinné seskupení sloupců pro přehlednost v UI (formuláře, detailní zobrazení).

| Pole | Typ | Povinné | Vícejazyčné | Popis |
|------|-----|---------|-------------|-------|
| `id` | string | Ano | Ne | Identifikátor skupiny (odkazuje se z `group` u sloupce) |
| `name` | string | Ano | Ano | Název skupiny pro UI |

Sloupce, které nemají nastavené `group`, nejsou zařazeny do žádné skupiny — UI je může zobrazit samostatně nebo v implicitní skupině.

### `displayPattern` — šablona zobrazení záznamu

Nepovinná šablona, která určuje, jak se záznam z této tabulky zobrazí, když na něj odkazuje sloupec s `reference` z jiné tabulky (např. ve výběrovém seznamu nebo prokliku).

Šablona používá placeholdery `{column_id}`, které se za běhu nahradí hodnotami:

```jsonc
// Zobrazí např. "FV-2026-001 — 2026-03-15"
"displayPattern": "{doc_number} — {issue_date}"

// Zobrazí např. "Jan Novák (jan@firma.cz)"
"displayPattern": "{full_name} ({email})"
```

Pravidla:
- Placeholdery odkazují na ID sloupců v téže tabulce
- Pokud tabulka nemá `displayPattern`, UI použije fallback — zobrazí hodnotu sloupce `id`
- Šablona není vícejazyčná — obsahuje pouze reference na sloupce, jejichž hodnoty jsou v datech

### `hideFromNavigation` — skrytí tabulky ze sidebaru

Nepovinný boolean flag. Pokud `true`, tabulka se nezobrazí v hlavním sidebaru ani v navigaci Nastavení aplikace — až to navigace skládá strom z modulů, tuhle tabulku přeskočí.

Typické použití: sub-tabulky, které jsou spravované výhradně přes parent záznam a samostatný vstup do nich nemá v UI smysl. Například `economy_codebooks_fiscal_months` (fiskální měsíce) jsou vytvářeny při uložení fiskálního roku, neexistují samostatně.

```jsonc
{
    "tableId": 314,
    "name": "Fiscal months",
    "name:cs": "Fiskální měsíce",
    "hideFromNavigation": true
    // ...
}
```

Pravidla a chování:
- Flag přeskočí tabulku v `NavigationController` (hlavní menu) i v `SettingsController` (Nastavení)
- Pokud na tabulku odkazuje viewer (`module.jsonc` → `viewers[].table`), je skrytý i ten viewer — jinak by se viewer zobrazoval pro tabulku, kterou designér označil jako skrytou
- Pokud tabulka s `hideFromNavigation: true` figuruje současně v `module.jsonc` → `settingsItems[]`, je to konfigurační chyba a položka se přeskočí s warningem v logu
- Flag se výslovně netýká `_meta` ani `_ui/form` API — tabulka zůstává běžně dostupná pro CRUD a editaci, jen ji nezobrazujíme jako vstupní bod v sidebaru

### `adminOnly` — tabulka jen pro administrátory

Nepovinný boolean flag. Pokud `true`, `TableAccessGuard` vrací ne-adminovi
403 (`FORBIDDEN_ADMIN_ONLY`) na všech generických cestách — CRUD
(list/show/create/update/patch/delete), viewer (meta/rows/detail), form
(meta/save/recalculate) i lookup (search/resolve). Navigace Nastavení položku nad takovou tabulkou
ne-adminovi vůbec nepošle (server-side filtr — žádné mrtvé odkazy).

```jsonc
{
    "tableId": 5001,
    "name": "Servers",
    "adminOnly": true
    // ...
}
```

Kdy použít: modulové tabulky s citlivým obsahem, které nemají být přístupné
běžným uživatelům — např. evidence hosting modulu (`hosting_core_*`).
Analogie k systémovým tabulkám: prefix `core_system_` chrání guard automaticky
(kód `FORBIDDEN_SYSTEM_TABLE`), `adminOnly` je explicitní opt-in pro tabulky
mimo tento prefix. Jde o nejhrubší stupeň budoucího RBAC — jediná hranice je
`is_admin`.

Zdroj pravdy je server; UI jen nezobrazuje mrtvé odkazy. Viz `docs/hosting.md`
rozhodnutí D9 a `docs/auth.md` §Admin model.

---

## 5. Definice sloupců

### Společná pole všech sloupců

| Pole | Typ | Povinné | Vícejazyčné | Popis |
|------|-----|---------|-------------|-------|
| `id` | string | Ano | Ne | Název sloupce v databázi (snake_case) |
| `name` | string | Ano | Ano | Název sloupce pro UI (formuláře, seznamy) |
| `type` | string | Ano | Ne | Datový typ (viz seznam níže) |
| `nullable` | bool | Ne | Ne | Povoluje NULL. Výchozí: `false` |
| `default` | mixed | Ne | Ne | Výchozí hodnota (konstanta) |
| `group` | string | Ne | Ne | ID skupiny sloupců (`columnGroups`) |
| `collation` | string | Ne | Ne | Collation pro tento sloupec (přetíží globální nastavení DB) |
| `reference` | string | Ne | Ne | ID cílové tabulky pro referenční vazbu (jen pro UI, žádná DB constraint) |
| `sensitive` | bool | Ne | Ne | Citlivý sloupec — nikdy neopustí server (viz níže). Výchozí: `false` |
| `schema` | string | Ne | Ne | Jen pro typ `json`: cfgItem se schématem strukturovaného pole (viz § 6 a [structured-fields.md](structured-fields.md)) |

### Speciální pole pro primární klíč

| Pole | Typ | Popis |
|------|-----|-------|
| `primaryKey` | bool | Sloupec je primární klíč |
| `autoIncrement` | bool | Automatický inkrement |

Primární klíč je vždy jeden sloupec typu `int` s `autoIncrement: true`. Každá tabulka musí mít právě jeden sloupec s `primaryKey: true`.

### Reference na jinou tabulku

Nepovinné pole `reference` u sloupce typu `int` označuje, že hodnota sloupce odkazuje na `id` (primární klíč) záznamu v cílové tabulce.

```jsonc
{
    "id": "customer_id",
    "name": "Customer",
    "name:cs": "Odběratel",
    "type": "int",
    "nullable": true,
    "reference": "base_persons_contacts"
}
```

Pravidla:
- `reference` je čistě metadata pro UI — žádná databázová FOREIGN KEY constraint se nevytváří
- Smysluplné pouze u sloupců typu `int` (odkazuje se přes PK cílové tabulky)
- Cílová tabulka by měla mít definovaný `displayPattern` pro smysluplné zobrazení v UI
- UI použije referenci pro: výběrové seznamy, prokliky na detail, zobrazení názvu místo ID
- Validace referenční integrity se řeší na aplikační úrovni

### Citlivé sloupce — `sensitive`

`"sensitive": true` označuje sloupec, jehož hodnota nesmí nikdy opustit server
(hashe hesel, hashe API klíčů, šifrovaná pověření). Vynucuje `TableAccessGuard`
(`src/Api/TableAccessGuard.php`) napříč generickými cestami:

- **Čtení**: sloupec se vynechá ze SELECT i odpovědi CRUD `list`/`show`,
  z `data` ve form meta/save a z metadat tabulky (`/meta/{table}`) —
  klient o něm neví, negeneruje se do gridů ani formulářů (`AutoFormBuilder`
  ho přeskakuje).
- **Zápis**: výskyt sloupce ve vstupu CRUD `create`/`update`/`patch` nebo
  form save → `400 SENSITIVE_COLUMN`. Žádné tiché zahazování — zápis
  citlivých hodnot jde vždy jen dedikovaným endpointem (vzor: API klíče).
- **Filtry a řazení**: `filter[col]`/`sort` nad sensitive sloupcem →
  `400 SENSITIVE_COLUMN` (jinak by `like`/`gt` fungovaly jako orákulum
  na extrakci hodnoty po znacích).

Pro uložení citlivé hodnoty v DB použij typ `encrypted_text`
(per-DS šifrování, viz `docs/operations/secrets.md`); `sensitive: true`
řeší API vrstvu a s `encrypted_text` se typicky kombinuje.

---

## 6. Datové typy

### Celočíselné typy

| Typ v JSONC | SQL typ (MariaDB) | Velikost | Rozsah |
|-------------|-------------------|----------|--------|
| `tinyint` | TINYINT | 1 byte | -128 až 127 |
| `smallint` | SMALLINT | 2 bytes | -32 768 až 32 767 |
| `int` | INT | 4 bytes | -2.1 × 10⁹ až 2.1 × 10⁹ |
| `bigint` | BIGINT | 8 bytes | -9.2 × 10¹⁸ až 9.2 × 10¹⁸ |

Speciální pole: žádná.

### Řetězcové typy

| Typ v JSONC | SQL typ (MariaDB) | Popis |
|-------------|-------------------|-------|
| `varchar` | VARCHAR(length) | Řetězec s proměnnou délkou |
| `text` | TEXT | Dlouhý text (max ~64 KB) |
| `longtext` | LONGTEXT | Velmi dlouhý text (max ~4 GB) |

Speciální pole pro `varchar`:

| Pole | Typ | Povinné | Popis |
|------|-----|---------|-------|
| `length` | int | Ano | Maximální délka řetězce |

### Numerické typy s desetinnými místy

| Typ v JSONC | SQL typ (MariaDB) | Popis |
|-------------|-------------------|-------|
| `numeric` | NUMERIC(precision, scale) | Přesný numerický typ pro finanční výpočty |
| `float` | FLOAT | Číslo s plovoucí desetinnou čárkou (nepřesné) |

Speciální pole pro `numeric`:

| Pole | Typ | Povinné | Popis |
|------|-----|---------|-------|
| `precision` | int | Ano | Celkový počet číslic |
| `scale` | int | Ano | Počet desetinných míst |

**Doporučení:** Pro finanční částky vždy používat `numeric`, nikdy `float`.

### Datumové a časové typy

| Typ v JSONC | SQL typ (MariaDB) | Formát |
|-------------|-------------------|--------|
| `date` | DATE | YYYY-MM-DD |
| `datetime` | DATETIME | YYYY-MM-DD HH:MM:SS |
| `time` | TIME | HH:MM:SS |

Speciální pole: žádná.

### Logický typ

| Typ v JSONC | SQL typ (MariaDB) | Popis |
|-------------|-------------------|-------|
| `boolean` | TINYINT(1) | Logická hodnota (0/1) |

### JSON typ

| Typ v JSONC | SQL typ (MariaDB) | Popis |
|-------------|-------------------|-------|
| `json` | JSON | Nativní JSON typ |

Speciální pole:

| Pole | Typ | Povinné | Popis |
|------|-----|---------|-------|
| `schema` | string | Ne | cfgItem se schématem **strukturovaného pole** — viz níže |

**Strukturované pole (`schema`).** Atribut `schema` říká, že obsah sloupce
popisuje schéma v cfgItem (`"schema": "economy.vat.filingProfileCz"`): pole,
jejich typy a skupiny ve stejném formátu jako sloupce tabulky. Takový
sloupec se pak edituje běžným formulářem, validuje se na serveru
a zobrazuje v detailu vieweru. Na jiném typu než `json` je `schema` chyba
definice; `ds-upgrade` navíc vynutí, že cfgItem existuje a projde
validátorem schématu.

Použij ho pro data, jejichž **sada polí se mění podle typu záznamu, země
nebo formuláře úřadu** a která se nevyhledávají ani nejoinují (hlavičky
daňových podání, profil podatele). Snapshoty dokladů a payloady alertů
schéma mít nemají. Celý mechanismus, formát schématu a verzování:
[structured-fields.md](structured-fields.md).

**Pozor — manuální serializace v Document class.** Dibi pro `json` sloupce neumí automatickou konverzi PHP pole → JSON řetězec. Pokud `Document::beforeSave` zapíše do `$data['some_json_col']` PHP pole, dibi ho při `INSERT`/`UPDATE` interpretuje jako multi-row insert payload a vyprodukuje broken SQL.

Praxe je jednoduchá:

- **Při ukládání** — v `Document::beforeSave` (nebo kde se hodnota nastavuje) zavolej `json_encode(...)` před výstupem do `$data`. Vrať `null` pro prázdný snapshot, aby sloupec skônčil jako NULL, ne jako `"[]"`.
- **Při čtení** — dibi vrátí obsah jako `string`. Form/viewer si ho podle potřeby `json_decode(...)`. Vzor je `DocsHeadsForm::decodeSnapshot`, který elegantně zvládá oba případy (string z DB i pole z server-side computed výstupu).

Vzor implementace pro ukládání v `DocDocument::encodeSnapshot()` — helper, který Document classes používají pro snapshot sloupce.

U sloupců se `schema` **tohle neplatí**: hodnotu serializuje `TableGateway`,
`Document::beforeSave` dostane dekódované pole a nesmí do sloupce zapisovat
JSON string sám (viz [structured-fields.md](structured-fields.md) § 6).

### Enum typy

Shipard nepoužívá databázový `ENUM` typ. Místo toho používá dva interní typy, které jsou fyzicky uloženy jako standardní databázové typy, ale v aplikační logice se chovají jako výčtové typy.

#### `enumInt`

| Typ v JSONC | SQL typ (MariaDB) | Popis |
|-------------|-------------------|-------|
| `enumInt` | SMALLINT | Celočíselný výčtový typ (2 bytes) |

Speciální pole:

| Pole | Typ | Povinné | Popis |
|------|-----|---------|-------|
| `cfgItem` | string | Ano | ID konfigurační položky s definicí hodnot |

Hodnoty se definují v konfiguračním souboru modulu (viz `docs/modules.md`, sekce 8). Příklad odkazu: `"cfgItem": "economy.docs.docStates"`.

#### `enumString`

| Typ v JSONC | SQL typ (MariaDB) | Popis |
|-------------|-------------------|-------|
| `enumString` | CHAR(length) | Řetězcový výčtový typ s pevnou délkou |

Speciální pole:

| Pole | Typ | Povinné | Popis |
|------|-----|---------|-------|
| `length` | int | Ano | Délka CHAR sloupce |
| `cfgItem` | string | Ano | ID konfigurační položky s definicí hodnot |

**Důležité:** Sloupce typu `enumString` automaticky používají znakovou sadu ASCII (ne UTF-8). Důvodem je zmenšení velikosti indexů — ASCII znak zabírá 1 byte oproti až 4 bytům u UTF-8.

SQL realizace:
```sql
-- enumString se vytvoří s explicitním CHARACTER SET ascii
`doc_type` CHAR(10) CHARACTER SET ascii NOT NULL
```

---

## 7. Definice indexů

### Struktura

```jsonc
{
    "id": "idx_date_state",
    "type": "index",
    "columns": [
        {"column": "issue_date", "order": "ASC"},
        {"column": "doc_state", "order": "ASC"}
    ]
}
```

### Pole

| Pole | Typ | Povinné | Popis |
|------|-----|---------|-------|
| `id` | string | Ano | Název indexu v databázi |
| `type` | string | Ano | Typ indexu: `index`, `unique`, `fulltext` |
| `columns` | object[] | Ano | Seznam sloupců v indexu |
| `columns[].column` | string | Ano | ID sloupce |
| `columns[].order` | string | Ne | Řazení: `ASC` (výchozí) nebo `DESC` |

### Typy indexů

| Typ | SQL | Popis |
|-----|-----|-------|
| `index` | `CREATE INDEX` | Standardní index pro urychlení SELECT |
| `unique` | `CREATE UNIQUE INDEX` | Unikátní index — zajišťuje unikátnost hodnot |
| `fulltext` | `CREATE FULLTEXT INDEX` | Fulltextový index pro vyhledávání v textu |

### Pojmenování indexů

Doporučená konvence:
- Klasický index: `idx_{tabulka}_{sloupce}` nebo zkráceně `idx_{popis}`
- Unikátní index: `unq_{tabulka}_{sloupce}`
- Fulltextový index: `ft_{tabulka}_{sloupce}`

### Poznámky

- Primární klíč se nedefinuje v sekci `indexes` — je definován přímo u sloupce pomocí `"primaryKey": true`
- Kompozitní indexy (přes více sloupců) jsou plně podporovány
- Pořadí sloupců v kompozitním indexu je důležité pro výkon

---

## 8. Extensions — rozšíření tabulek

Extension je JSONC soubor, který přidává sloupce a indexy do tabulky jiného modulu.

### Umístění

`modules/{skupina}/{modul}/extensions/ext-{cilova-tabulka}.jsonc`

### Struktura

```jsonc
{
    // Cílová tabulka, která se rozšiřuje
    "table": "base_persons_contacts",

    // Přidávané sloupce
    "columns": [
        {
            "id": "credit_limit",
            "name": "Credit limit",
            "name:cs": "Kreditní limit",
            "name:en": "Credit limit",
            "type": "numeric",
            "precision": 12,
            "scale": 2,
            "nullable": true,
            "group": "financial"
        },
        {
            "id": "payment_term_days",
            "name": "Payment term (days)",
            "name:cs": "Splatnost (dny)",
            "name:en": "Payment term (days)",
            "type": "smallint",
            "default": 14
        }
    ],

    // Přidávané skupiny sloupců (pokud odkazované skupiny neexistují v cílové tabulce)
    "columnGroups": [
        {
            "id": "financial",
            "name": "Financial details",
            "name:cs": "Finanční údaje",
            "name:en": "Financial details"
        }
    ],

    // Přidávané indexy
    "indexes": [
        {
            "id": "idx_credit_limit",
            "type": "index",
            "columns": [
                {"column": "credit_limit"}
            ]
        }
    ]
}
```

### Pole

| Pole | Typ | Povinné | Popis |
|------|-----|---------|-------|
| `table` | string | Ano | ID cílové tabulky |
| `columns` | object[] | Ne | Sloupce k přidání (stejný formát jako v definici tabulky) |
| `columnGroups` | object[] | Ne | Skupiny sloupců k přidání |
| `indexes` | object[] | Ne | Indexy k přidání (stejný formát jako v definici tabulky) |

### Pravidla

- Extension může pouze **přidávat** — nikdy neodebírá ani nemění existující sloupce, skupiny nebo indexy
- Pokud extension přidává sloupec s `group`, musí daná skupina existovat buď v cílové tabulce, nebo v sekci `columnGroups` extension
- Pokud extension přidává skupinu se stejným `id` jako existující skupina v cílové tabulce, ignoruje se (existující má přednost)
- ID přidávaných sloupců a indexů nesmí kolidovat s existujícími
- Modul s extension musí mít v `dependencies` uveden modul vlastnící cílovou tabulku

### Pořadí aplikace

1. Sestaví se základní definice tabulky z vlastnícího modulu
2. Extensions se aplikují v pořadí topologického seřazení modulů
3. Při kolizi ID sloupce/indexu — chyba, upgrade se zastaví

---

## 9. Databázové konvence

### Pojmenování

| Entita | Konvence | Příklad |
|--------|----------|---------|
| Tabulka | `{skupina}_{modul}_{nazev}` | `economy_docs_heads` |
| Sloupec | `snake_case` | `issue_date`, `total_amount` |
| Index | `idx_{popis}` | `idx_date_state` |
| Unikátní index | `unq_{popis}` | `unq_doc_number` |
| Fulltextový index | `ft_{popis}` | `ft_note` |

### Znaková sada a collation

- **Globální:** `utf8mb4` s collation nastavenou při vytvoření databáze (viz PRD — `utf8mb4_czech_ci`)
- **Přetížení na úrovni sloupce:** volitelné pole `collation` v definici sloupce
- **Výjimka — `enumString`:** automaticky `CHARACTER SET ascii` (1 byte na znak, menší indexy)

### NULL a výchozí hodnoty

- Výchozí chování: `NOT NULL` (pole `nullable` je výchozí `false`)
- Pro sloupce, které mohou být prázdné, explicitně nastavit `"nullable": true`
- Výchozí hodnoty jsou pouze konstanty: čísla, řetězce, `null`, `true`, `false`
- Složitější výchozí hodnoty se řeší na aplikační úrovni

### Primární klíč

- Vždy jeden sloupec typu `int` s `autoIncrement: true`
- Vždy pojmenovaný `id`
- Každá tabulka musí mít právě jeden primární klíč

### Cizí klíče

Shipard nepoužívá databázové cizí klíče (FOREIGN KEY). Referenční integrita se řeší na aplikační úrovni. Databáze slouží jako úložiště dat.

### Pořadový sloupec

Projekt používá **dvě konvence pro pořadový sloupec**, podle role tabulky:

| Konvence    | Použití                                              | Příklady                                                                                  |
|-------------|------------------------------------------------------|-------------------------------------------------------------------------------------------|
| `order_pos` | Sub-tabulky zobrazované přes `FormSubTable.svelte`   | `base_persons_addresses`, `base_persons_contacts`, `base_persons_bank_accounts`, `docs_core_rows`, `docs_core_vat_recap` |
| `sort_order`| Top-level entity s vlastním viewerem                 | `economy_codebooks_cash_desks`, `economy_codebooks_bank_accounts`, `economy_codebooks_cost_centers`, `economy_codebooks_warehouses` |

Frontend `FormSubTable.svelte` má hardcoded:
- defaultní řazení podle `order_pos:asc`
- vyloučení `order_pos` ze zobrazených sloupců

Sub-tabulka **bez** `order_pos` se přes `FormSubTable` nedá rozumně zobrazit — defaultní `?sort=order_pos:asc` query selže s SQL chybou „Unknown column".

Pokud nová sub-tabulka potřebuje pořadí, **vždy `order_pos`**. Pokud má vlastní viewer s explicitně řízeným řazením, `sort_order` je tradiční volba pro `economy_codebooks_*`. Konvence napříč těmito dvěma kategoriemi není 100% sjednocená — nejde o problém kvůli oddělené roli, ale stojí za to ji znát při psaní nových tabulek.

---

## 10. Bezpečné změny při `ds-upgrade`

Při porovnání definice s existující tabulkou `ds-upgrade` provádí pouze bezpečné operace:

### Povolené operace

| Operace | Podmínka |
|---------|----------|
| `CREATE TABLE` | Tabulka neexistuje |
| `ALTER TABLE ADD COLUMN` | Sloupec neexistuje v tabulce |
| `ALTER TABLE MODIFY COLUMN` | Bezpečná změna typu (viz níže) |
| `CREATE INDEX` | Index neexistuje |

### Bezpečné změny typu sloupce

| Původní typ | Nový typ | Povoleno |
|-------------|----------|----------|
| `varchar(N)` | `varchar(M)` kde M > N | Ano — prodloužení |
| `tinyint` | `smallint` | Ano — rozšíření |
| `smallint` | `int` | Ano — rozšíření |
| `int` | `bigint` | Ano — rozšíření |
| `numeric(P1, S)` | `numeric(P2, S)` kde P2 > P1 | Ano — rozšíření precision |

Jakékoliv jiné změny typu se ignorují — neprovádí se automaticky.

### Ignorované situace

| Situace | Chování |
|---------|---------|
| Přebytečný sloupec v DB | Ignoruje se (nechá se) |
| Zúžení typu (varchar kratší) | Ignoruje se |
| Změna typu (varchar → int) | Ignoruje se |
| Přebytečný index v DB | Ignoruje se |

---

## 11. Validace při `ds-upgrade`

Před provedením změn v databázi se provede validace:

### Fatální chyby (upgrade se zastaví)

- Duplicitní `tableId` přes více tabulek
- Duplicitní ID sloupce v rámci tabulky (včetně extensions)
- Duplicitní ID indexu v rámci tabulky (včetně extensions)
- Chybějící povinné pole v definici (`tableId`, `name`, `type` u sloupce atd.)
- Neexistující `cfgItem` reference u enum sloupců
- Extension odkazuje na neexistující tabulku
- Tabulka bez primárního klíče

### Varování (upgrade pokračuje)

- Sloupec v DB, který není v definici
- Index v DB, který není v definici
- Skupina sloupců (`columnGroups`) bez jediného přiřazeného sloupce

---

## 12. Kompletní příklady

### Tabulka `core_system_users`

```jsonc
{
    "tableId": 1,
    "name": "Users",
    "name:cs": "Uživatelé",
    "name:en": "Users",

    "displayPattern": "{full_name} ({login})",

    "columnGroups": [
        {
            "id": "credentials",
            "name": "Credentials",
            "name:cs": "Přihlašovací údaje",
            "name:en": "Credentials"
        },
        {
            "id": "personal",
            "name": "Personal information",
            "name:cs": "Osobní údaje",
            "name:en": "Personal information"
        }
    ],

    "columns": [
        {
            "id": "id",
            "name": "ID",
            "type": "int",
            "autoIncrement": true,
            "primaryKey": true
        },
        {
            "id": "login",
            "name": "Login",
            "name:cs": "Přihlašovací jméno",
            "name:en": "Login",
            "type": "varchar",
            "length": 100,
            "nullable": false,
            "group": "credentials"
        },
        {
            "id": "password_hash",
            "name": "Password hash",
            "name:cs": "Hash hesla",
            "name:en": "Password hash",
            "type": "varchar",
            "length": 255,
            "nullable": false,
            "group": "credentials"
        },
        {
            "id": "full_name",
            "name": "Full name",
            "name:cs": "Celé jméno",
            "name:en": "Full name",
            "type": "varchar",
            "length": 200,
            "nullable": false,
            "group": "personal"
        },
        {
            "id": "email",
            "name": "E-mail",
            "type": "varchar",
            "length": 200,
            "nullable": true,
            "group": "personal"
        },
        {
            "id": "is_active",
            "name": "Active",
            "name:cs": "Aktivní",
            "name:en": "Active",
            "type": "boolean",
            "default": 1
        }
    ],

    "indexes": [
        {
            "id": "unq_login",
            "type": "unique",
            "columns": [
                {"column": "login"}
            ]
        },
        {
            "id": "idx_email",
            "type": "index",
            "columns": [
                {"column": "email"}
            ]
        }
    ]
}
```

### Tabulka `core_system_sessions`

```jsonc
{
    "tableId": 2,
    "name": "Sessions",
    "name:cs": "Relace",
    "name:en": "Sessions",

    "columns": [
        {
            "id": "id",
            "name": "ID",
            "type": "int",
            "autoIncrement": true,
            "primaryKey": true
        },
        {
            "id": "user_id",
            "name": "User",
            "name:cs": "Uživatel",
            "name:en": "User",
            "type": "int",
            "nullable": false
        },
        {
            "id": "token",
            "name": "Token",
            "type": "varchar",
            "length": 128,
            "nullable": false
        },
        {
            "id": "ip_address",
            "name": "IP address",
            "name:cs": "IP adresa",
            "name:en": "IP address",
            "type": "varchar",
            "length": 45,
            "nullable": true
        },
        {
            "id": "created",
            "name": "Created",
            "name:cs": "Vytvořeno",
            "name:en": "Created",
            "type": "datetime",
            "nullable": false
        },
        {
            "id": "expires",
            "name": "Expires",
            "name:cs": "Expirace",
            "name:en": "Expires",
            "type": "datetime",
            "nullable": false
        }
    ],

    "indexes": [
        {
            "id": "unq_token",
            "type": "unique",
            "columns": [
                {"column": "token"}
            ]
        },
        {
            "id": "idx_user_id",
            "type": "index",
            "columns": [
                {"column": "user_id"}
            ]
        },
        {
            "id": "idx_expires",
            "type": "index",
            "columns": [
                {"column": "expires"}
            ]
        }
    ]
}
```

### Tabulka `core_system_settings`

```jsonc
{
    "tableId": 3,
    "name": "Settings",
    "name:cs": "Nastavení",
    "name:en": "Settings",

    "columns": [
        {
            "id": "id",
            "name": "ID",
            "type": "int",
            "autoIncrement": true,
            "primaryKey": true
        },
        {
            "id": "key",
            "name": "Key",
            "name:cs": "Klíč",
            "name:en": "Key",
            "type": "varchar",
            "length": 200,
            "nullable": false
        },
        {
            "id": "value",
            "name": "Value",
            "name:cs": "Hodnota",
            "name:en": "Value",
            "type": "json",
            "nullable": true
        },
        {
            "id": "modified",
            "name": "Modified",
            "name:cs": "Změněno",
            "name:en": "Modified",
            "type": "datetime",
            "nullable": true
        }
    ],

    "indexes": [
        {
            "id": "unq_key",
            "type": "unique",
            "columns": [
                {"column": "key"}
            ]
        }
    ]
}
```

---

## 13. Přehled datových typů — rychlá reference

| Typ v JSONC | SQL (MariaDB) | Speciální pole | Poznámka |
|-------------|---------------|----------------|----------|
| `tinyint` | TINYINT | — | 1 byte |
| `smallint` | SMALLINT | — | 2 bytes |
| `int` | INT | — | 4 bytes, primární klíč |
| `bigint` | BIGINT | — | 8 bytes |
| `varchar` | VARCHAR(length) | `length` | Proměnná délka |
| `text` | TEXT | — | Max ~64 KB |
| `longtext` | LONGTEXT | — | Max ~4 GB |
| `numeric` | NUMERIC(p, s) | `precision`, `scale` | Přesný, pro finance |
| `float` | FLOAT | — | Nepřesný |
| `date` | DATE | — | YYYY-MM-DD |
| `datetime` | DATETIME | — | YYYY-MM-DD HH:MM:SS |
| `time` | TIME | — | HH:MM:SS |
| `boolean` | TINYINT(1) | — | 0/1 |
| `json` | JSON | — | Nativní JSON |
| `enumInt` | SMALLINT | `cfgItem` | 2 bytes, enum přes config |
| `enumString` | CHAR(length) | `length`, `cfgItem` | ASCII charset, enum přes config |
