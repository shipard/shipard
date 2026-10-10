# Tabulka: Rezervace analýz (core_mail_analysis_claims)

Lease mechanismus AI analýzy. Když si runner (`shpd-ds mail-analyze`,
`AnalysisClaimService::claim()`) vezme zprávu k analýze, vznikne záznam
s `expires_at`; před každým pokusem volání modelu lease prodlužuje. Reaper
cron (`mail-analysis-reap`) najde expirované rezervace a vrátí zprávu zpět
do fronty — to je recovery, když runner mezi claimem a zápisem výsledku
spadne (pád, který PHP nezachytí). Řádky z doby zrušeného externího
analyzeru (#85 D20) zůstávají, liší se jen hodnotou `analyzer_id`.

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `message` | int → `core_mail_incoming_messages`, NOT NULL | Zpráva, kterou analyzer drží |
| `analyzer_id` | varchar(64), NOT NULL | Identita běhu: runner `internal:<hostname>:<pid>`; starší řádky nesou UUID instance zrušeného démona |
| `claim_token` | varchar(64), NOT NULL, UNIQUE | Náhodný token claimu (dřív ověřoval navazující HTTP volání analyzeru; runner v procesu ho jen nese) |

### Rezervace (lease)

| Sloupec | Typ | Popis |
|---|---|---|
| `claimed_at` | datetime, NOT NULL | Čas vytvoření claim |
| `expires_at` | datetime, NOT NULL | Čas, po kterém je claim neplatná |

### Uvolnění (release)

| Sloupec | Typ | Popis |
|---|---|---|
| `released` | boolean, default false | True po `result`, `failed` nebo expiraci |
| `released_at` | datetime | Čas uvolnění |
| `release_reason` | varchar(30) | `result`, `failed`, `expired` |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `unq_claim_token` | unique | `claim_token` | Token musí být globálně unikátní |
| `idx_message_active` | index | `message`, `released` | Rychlé "má zpráva aktivní claim?" |
| `idx_expires_at` | index | `expires_at` | Reaper najde expirované |

## Invariant: max jedna aktivní claim per zpráva

Specifikace zmiňuje "partial unique `(message) WHERE released = false`".
MariaDB partial unique nepodporuje, takže invariant vynucujeme aplikačně —
`AnalysisClaimService::claim()` před INSERT v rámci transakce ověří, že
ke zprávě neexistuje řádek se `released = false` (s expirací v budoucnu),
jinak skončí `ALREADY_CLAIMED`. Při expiraci reaper rezervaci uvolní a
další claim může proběhnout.

## Životní cyklus

1. **Claim**: `AnalysisClaimService::claim()` v transakci ověří, že zpráva
   je `analysis_state=10` a nemá živou claim → vytvoří záznam, přepne zprávu
   na `analysis_state=20`.
2. **Běh**: runner připraví přílohy, vykreslí prompt a volá model; před
   každým pokusem `extend()` prodlouží lease.
3. **Result/Failed**: `AnalysisResultWriter` zapíše běh → `released=true`,
   `release_reason='result'` nebo `'failed'`.
4. **Expirace**: reaper nastaví `released=true`, `release_reason='expired'`,
   přepne zprávu zpět na `analysis_state=10` (při třetím vypršení za hodinu
   stav 70).

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_mail_incoming_messages](core_mail_incoming_messages.md) | `claims.message` → `incoming_messages.id` | Zpráva, kterou claim drží |

## Mazání

CASCADE delete při smazání zdrojové zprávy — řeší
`IncomingMessageDocument::beforeDelete`.
