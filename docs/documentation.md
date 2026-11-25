# Shipard — Dokumentace modulů a datových struktur

## 1. Přehled

Vedle systémové dokumentace v `docs/` (architektura, formáty souborů, API) existuje
i dokumentace přímo u zdrojového kódu — v adresářích jednotlivých modulů. Tato
dokumentace popisuje konkrétní moduly, jejich tabulky a obchodní logiku, která
není zřejmá ze samotných definičních souborů.

Cíl: vývojář (i Claude Code) se podívá do `README.md` modulu, rychle pochopí
co modul dělá, a přes odkazy se dostane k dokumentaci konkrétních tabulek, kde
najde vazby mezi sloupci, chování hooků dokumentového systému a návaznosti na
další tabulky.

---

## 2. README.md modulu

**Umístění:** `modules/{skupina}/{modul}/README.md`

Stručný přehled modulu a rozcestník. Obsahuje:

- **Název a účel** — co modul spravuje, jednou až dvěma větami.
- **Závislosti** — seznam modulů, na kterých závisí.
- **Tabulky** — tabulka s odkazy na dokumentaci jednotlivých tabulek (`tables/*.md`).
- **Zdrojové soubory** — přehled klíčových tříd v `src/` s krátkým popisem.
- **Konfigurace** — seznam konfiguračních položek s odkazy na soubory v `config/`.

### Vzor

```markdown
# Modul: Osoby (base.persons)

Modul spravuje fyzické osoby i firmy — dodavatele, odběratele, zaměstnance
a další subjekty, se kterými systém pracuje.

## Závislosti

- `core.system`

## Tabulky

| Tabulka | Popis |
|---|---|
| [base_persons_persons](tables/base_persons_persons.md) | Hlavní evidence osob a firem |

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [PersonDocument.php](src/PersonDocument.php) | Dokumentová třída — validace a before-save logika |
| [PersonType.php](src/PersonType.php) | PHP enum `PersonType` |

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `base.persons.personTypes` | [config/personTypes.jsonc](config/personTypes.jsonc) | Číselník typů osob |
```

### Specifikace u modulu — `docs/`

**Umístění:** `modules/{skupina}/{modul}/docs/`

Modul smí mít vlastní adresář `docs/` se specifikacemi, které se týkají jen
jeho (vzor `modules/core/mail/docs/`). `README.md` modulu na ně odkazuje.
**Doplněk** (`"kind": "addon"`, [modules.md](modules.md) §9) tam drží všechno,
co by jinak bylo v kořenovém `docs/` — do kořenového `docs/` patří jen obecná
pravidla a specifikace jádra.

Pozor na záměnu: `modules/docs/` je skupina modulů dokladů, ne dokumentace.

### Rozcestník modulů — `modules/README.md`

Přehled všech modulů po druzích (instalační, jádro po skupinách, doplňky)
s odkazem na `README.md` každého modulu je v [`modules/README.md`](../modules/README.md).
Blok mezi značkami `MODULY:BEGIN` / `MODULY:END` generuje
`php scripts/modules-index.php` z `module.jsonc` (`name:cs`, `description:cs`,
`kind`); ručně se needituje. `--check` běží v pre-commit hooku a shodu hlídá
i `ModulesIndexTest` — po přidání modulu, změně jeho názvu, popisu, druhu
nebo po přidání `README.md` skript spusť znovu.

---

## 3. Dokumentace tabulky

**Umístění:** `modules/{skupina}/{modul}/tables/{id_tabulky}.md`

Soubor leží vedle JSONC definice tabulky a popisuje to, co z definice není zřejmé.

### Struktura dokumentu

#### 3.1 Účel tabulky

Úvodní odstavec — proč tabulka existuje, co eviduje, jaký je její hlavní princip
(např. „ukládá fyzické osoby i firmy do jedné struktury, typ záznamu určuje
sloupec `person_type`").

#### 3.2 Struktura

Přehled sloupců organizovaný podle skupin (`columnGroups`). Pro každý sloupec:
typ, nullable a stručný popis. Důraz na sloupce, jejichž význam nebo chování
není zřejmé ze samotné JSONC definice.

#### 3.3 Obchodní logika

Nejdůležitější sekce. Popisuje chování dokumentové třídy (`*Document.php`)
ve vztahu k datům tabulky:

- Které sloupce ovlivňují chování jiných sloupců (např. `person_type` řídí
  validaci i before-save logiku).
- Jaké validační pravidla platí a za jakých podmínek.
- Co se děje v `beforeSave` — automatické výpočty, skládání hodnot,
  vyprazdňování polí.
- Podmíněné chování — které sloupce mají smysl jen u určitého typu záznamu.

Pokud tabulka nemá dokumentovou třídu, sekce se vynechá nebo se uvede
„Tabulka nemá vlastní dokumentovou třídu".

#### 3.4 Indexy

Přehled indexů s vysvětlením účelu (zejména u kompozitních indexů, kde
samotné jméno indexu nemusí stačit).

#### 3.5 Návaznosti

Vazby na další tabulky — cizí klíče (přes `reference`), logické závislosti,
tabulky, které tuto tabulku rozšiřují přes extensions. Pokud zatím žádné
návaznosti nejsou, uvede se poznámka s příklady plánovaných vazeb.

---

## 4. Pravidla pro psaní dokumentace

- **Jazyk:** čeština (shodně se zdrojovým kódem a komentáři).
- **Stručnost:** dokumentace doplňuje JSONC definici, neopakuje to, co je
  z definice zřejmé. Např. u sloupce `email` typu `varchar(200)` stačí napsat
  „E-mail" — není třeba vysvětlovat, že jde o řetězec.
- **Odkazy:** používat relativní odkazy na zdrojové soubory (`../src/PersonDocument.php`)
  a na konfiguraci (`../config/personTypes.jsonc`).
- **Aktuálnost:** při změně JSONC definice nebo dokumentové třídy je třeba
  aktualizovat i `.md` soubor. Claude Code může tuto kontrolu provádět
  automaticky.

---

## 5. Referenční příklady

- Modul `base.persons`: [modules/base/persons/README.md](../modules/base/persons/README.md)
- Tabulka `base_persons_persons`: [modules/base/persons/tables/base_persons_persons.md](../modules/base/persons/tables/base_persons_persons.md)
