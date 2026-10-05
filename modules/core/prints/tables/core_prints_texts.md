# Tabulka: Texty na tiscích (core_prints_texts)

Vlastní texty, které se vkládají do tisků a do e-mailů s nimi (#90 D47–D52):
krátká informace na doklad („příští týden máme dovolenou“), trvalý text
v patičce, vlastní předmět a tělo e-mailu. Záznam říká **co** (text), **kam**
(slot) a **kdy a kde** platí (cílení, platnost).

Životní cyklus jede na `core.system.docStatesArchive`. **Tiskne se jen text
ve stavu V pořádku (40)** — koncept, text v opravě, v archivu ani smazaný se
na tisk nedostane.

## Struktura

### Text (text)

| Sloupec | Typ | Popis |
|---|---|---|
| `name` | varchar(120), NOT NULL | Interní název pro agendu; na tisku není |
| `slot` | enumString(20), NOT NULL | Kam se text vloží — `core.prints.textSlots`; hodnoty = výčet `PrintTextSlot` |
| `text` | text, NOT NULL | Text s proměnnými Twigu. Sloty do stránky tisku: Markdown; e-mailové sloty: prostý text. Při uložení se kompiluje v sandboxu `PrintSecurityPolicy::userTexts()` |
| `note` | varchar(250) | Poznámka pro správce; na tisku není |

### Kde se text použije (targeting)

| Sloupec | Typ | Popis |
|---|---|---|
| `prints` | json | Pole id tisků. NULL = všechny tisky, které slot podporují (`textSlots` deklarace) |
| `doc_types` | json | Pole typů dokladů. NULL = bez omezení |
| `number_series` | json | Pole id číselných řad. NULL = bez omezení |
| `language` | varchar(5) | Jazyk tisku (`PrintLanguageResolver::LANGUAGES`). NULL = všechny jazyky |

`doc_types` a `number_series` mají smysl jen u tisků nad tabulkou, která typ
a řadu má — mapu drží `PrintTextTargeting` (dnes `docs_core_heads`). U tisku
nad jinou tabulkou text s tímto omezením **neplatí**. JSON sloupce nemají
auto-serializaci: kóduje je `PrintTextDocument::beforeSave()`, prázdný výběr
ukládá jako NULL.

### Platnost (validity)

| Sloupec | Typ | Popis |
|---|---|---|
| `valid_from` | date | První den platnosti; NULL = bez omezení |
| `valid_to` | date | Poslední den platnosti (včetně); NULL = bez omezení |
| `order_pos` | int, NOT NULL, default 0 | Pořadí mezi texty stejného slotu — do slotu jdou **všechny** platné texty (D48) |
| `docState` / `docStateMain` | tinyint, system | `core.system.docStatesArchive` (10 Koncept, 40 V pořádku, 80 V opravě, 70 V archívu, 90 Smazáno) |

Platnost se počítá ke **dni tisku nebo odeslání**, ne k datu dokladu (D52).

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `idx_state_slot` | index | `docState`, `slot` | Výběr platných textů při tisku |

## Návaznosti

Žádné cizí klíče. Id tisků v `prints` odkazují na deklarace `prints` modulů
(cfgItem `core.prints.declarations`), `doc_types` na číselník typů a
`number_series` na tabulku řad z `PrintTextTargeting` — existenci ověřuje
`PrintTextDocument` při uložení.
