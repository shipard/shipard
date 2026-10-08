# Tabulka: docs_core_number_counters

Atomický counter čísel dokladů per `(number_series, fiscal_year)`.
Obsluhuje ho `Shipard\Core\Numbering\SequenceCounter` (`src/Core/Numbering/`,
#110 D17, `docs/architecture.md` §8) s popisem tabulek `SequenceStorage`
z `DocDocument::sequenceCounter()`; klíč čítače (řada, fiskální rok podle
`reset_scope`, jinak NULL) určuje doklad.

## Sloupce

| Sloupec | Typ | Popis |
|---|---|---|
| `number_series` | int → `docs_core_number_series` | Řada |
| `fiscal_year` | int → `economy_codebooks_fiscal_years`, nullable | Fiskální rok (NULL pro `reset_scope = 'none'`) |
| `last_assigned` | int default 0 | Poslední přidělené sequence_number |

## Bez doc-state

Tabulka **nemá** `docState` / `docStateMain` — je čistě technickým
záznamem. Životní cyklus = vznik při prvním přidělení čísla, existuje
dokud existuje řada.

## Indexy

- **`unq_series_year` UNIQUE** — `(number_series, fiscal_year)`. UNIQUE
  v MariaDB neporušují NULL hodnoty, takže pro řady s `reset_scope='none'`
  (kde `fiscal_year` je vždy NULL) by mohlo vzniknout víc záznamů. Aplikační
  logika (`SELECT … FOR UPDATE` s `WHERE fiscal_year IS NULL`) zajistí
  jediný záznam.

## Algoritmus přidělení čísla (`SequenceCounter::next`)

1. `BEGIN` — jen když doklad nedrží vnější transakci (exchange Applier)
2. `INSERT IGNORE` placeholder counter (idempotentní)
3. `SELECT last_assigned … FOR UPDATE` (lock)
4. `UPDATE … SET last_assigned = N + 1`
5. `COMMIT`; doklad použije `N + 1` jako `sequence_number` a vyhodnotí vzorec

**Import** (`syncImported`, `_importNumber`): `INSERT IGNORE` + `UPDATE …
GREATEST(last_assigned, pořadí)` — idempotentní, nezávislé na pořadí importu,
snáší díry po smazaných zdrojových dokladech.

**Uvolnění** (`release`, návrat V opravě → Koncept): `last_assigned - 1` jen
když `last_assigned = pořadí`. Že jde o poslední doklad v řadě, hlídá doklad
přes `maxSequence` nad `docs_core_heads` (stejný dotaz filtruje nabídku
přechodu do Konceptu).

Pojistka: na `docs_core_heads` je UNIQUE constraint na trojici
`(number_series, fiscal_year, sequence_number)`. I kdyby logika selhala,
INSERT/UPDATE nikdy nezpůsobí duplicitu — místo toho transakce spadne
na duplicate key error.

## Související

- [docs_core_number_series](docs_core_number_series.md) — parent
- [docs_core_heads](docs_core_heads.md) — `unq_series_seq` UNIQUE jako pojistka
- `docs/docs-mvp.md` sekce 5.3 — algoritmus přidělení
