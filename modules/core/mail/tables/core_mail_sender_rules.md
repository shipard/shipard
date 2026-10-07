# Tabulka: Pravidla odesílatelů (core_mail_sender_rules)

Deterministická pravidla pro zpracování šumu v došlé poště (Fáze 3 programu
Spisovny, design `docs/registry-mvp.md` §8, zásady D6/D7). Pravidlo má
jednu ze dvou dispozic (`core.mail.senderRuleDispositions`,
`tasks/mail-sender-rules-after-analysis.md`):

- **`archiveIfOther`** — „Archivovat, když neobsahuje doklad ani dokument“
  (výchozí). Zpráva při příjmu projde normálně (předzpracování, ISDOC, AI);
  když první úspěšná analýza nevrátí dokument a klasifikace zprávy je
  `other` s jistotou aspoň na `review` prahu profilu, `PostAnalysisDisposer`
  ji v transakci `/result` archivuje. Faktury od téhož odesílatele chodí
  dál normálně — bezpečná volba pro smíšené odesílatele (sken obálky
  i obsahu, dodavatel posílající upozornění i faktury).
- **`archive`** — „Archivovat hned, bez analýzy“. Zpráva, jejíž odesílatel
  matchne **potvrzené** pravidlo, vzniká při ingestu rovnou v Archivu
  (pre-triage v `MailController::receiveIncoming`). Po analýze a při
  potvrzení pravidla archivuje ostatní poštu také — `archive` znamená
  „všechno“.

Oba zásahy nesou stejný audit na zprávě (`auto_disposed_by`,
`auto_disposed_at`); liší se `analysis_state` (0 před analýzou, 30 po ní),
podle něhož „Vrátit vše“ rozhodne, zda zprávu znovu frontovat (D7).

Životní cyklus jede na `core.system.docStatesArchive`: návrh učícího
handleru vzniká jako Koncept (10), potvrzení kartou na dashboardu = přechod
10→40, zamítnutí = 90. Žádný `is_confirmed` flag — stavový automat stačí.
**Matchují výhradně pravidla ve stavu 40.**

Pozor na záměnu: `core_mail_senders` jsou odchozí SMTP transporty a
s pravidly došlé pošty nesouvisí.

## Struktura

### Pravidlo (rule)

| Sloupec | Typ | Popis |
|---|---|---|
| `pattern_kind` | enumString(10), NOT NULL, default `email` | `email` \| `domain` (`core.mail.senderRulePatternKinds`). Přesný e-mail > doména; první zásah vyhrává |
| `pattern` | varchar(190), NOT NULL | Lowercase vzor (vynucuje `SenderRuleDocument`): celá adresa, nebo doména bez `@` |
| `disposition` | enumString(20), NOT NULL, default `archiveIfOther` | Fáze zásahu (`core.mail.senderRuleDispositions`): `archiveIfOther` po analýze, `archive` při příjmu (viz úvod). Default definice platí pro nové DS a `SenderRuleDocument::beforeSave`; `ds-upgrade` výchozí hodnotu existujícího sloupce nemění (porovnávač schématu defaulty neporovnává), na starších DS tak v DB zůstává `archive` — bez vlivu na chování, dispozici vždy dodá formulář nebo Document |
| `origin` | enumString(10), NOT NULL, default `user` | `user` (ručně) \| `suggested` (učící handler) — `core.mail.senderRuleOrigins` |
| `notice` | varchar(250) | Poznámka; u návrhů počet ručních zásahů, které návrh vyvolaly |

### Statistiky (stats)

| Sloupec | Typ | Popis |
|---|---|---|
| `hit_count` | int, NOT NULL, default 0 | Kolikrát pravidlo auto-archivovalo zprávu (při příjmu, po analýze i při potvrzení) |
| `last_hit_at` | datetime | Čas posledního zásahu |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime, NOT NULL | Čas vytvoření |
| `created_by` | int → core_system_users | Kdo založil; NULL u návrhů z učícího handleru |
| `modified` | datetime, NOT NULL | Čas poslední změny |
| `docState` / `docStateMain` | tinyint, system | `core.system.docStatesArchive` (10 Koncept, 40 V pořádku, 80 V opravě, 70 V archívu, 90 Smazáno) |

Unikátnost `(pattern_kind, pattern)` mezi „živými" pravidly (docState 10/40/80)
vynucuje aplikačně `SenderRuleDocument` — koš/archiv reuse vzoru neblokuje.

## Přednost pravidel a potvrzení

- **Konkrétnější pravidlo vyhrává bez ohledu na dispozici** (D6):
  `SenderRuleMatcher::match()` vrací nejkonkrétnější potvrzené pravidlo
  (přesný e-mail > doména, přesná doména za posledním `@`) a jeho dispozice
  určí fázi. Kombinace „doména `archive`, konkrétní adresa
  `archiveIfOther`“ tak nechá zprávy od té adresy projít analýzou.
- **Potvrzení pravidla odklidí čekající řádky Ostatní** (D8):
  `SenderRuleDocument::beforeSave` eviduje přechod docState, takže
  `TableGateway` po commitu vyšle `stateChanged`; `SenderRuleConfirmedHandler`
  při přechodu do 40 (karta návrhu i uložení formuláře) zavolá
  `PostAnalysisDisposer::applyToWaiting()` — zprávy v Nové, analyzované,
  `other`, bez ručního nahrání, s jistotou poslední úspěšné analýzy nad
  prahem, pro které je pravidlo nejkonkrétnějším zásahem. Objeví se
  v digestu, „Vrátit vše“ funguje.
- **Učící handler** (`SenderRuleSuggestionHandler`) navrhne `archiveIfOther`
  odesílateli, od kterého už přišel doklad nebo dokument (zpráva
  s `target_row`, nebo `primary_type` ≠ `other` od AI / ISDOC / uživatele —
  D1), jinak `archive`; poznámka návrhu to říká (D2).

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `idx_match` | index | `docStateMain`, `pattern_kind`, `pattern` | Lookup při ingestu (match jen aktivních) |
| `idx_state` | index | `docState` | Návrhové karty feedu (docState 10 + origin) |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_mail_incoming_messages](core_mail_incoming_messages.md) | `messages.auto_disposed_by` → id | Audit auto-archivace; digest karta a „Vrátit vše" se derivují dotazem |

## Mazání a reset

Tabulka je v `keepOnReset` — pravidla jsou konfigurace, ne data. Smazání
pravidla nechává `auto_disposed_by` na historických zprávách jako sirotčí
referenci (referenční integrita je aplikační, digest se dívá jen na dnešek).
