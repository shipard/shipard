# Prompt v4.10.0: poznámka bez doložek DPH, platební reference, DUZP jen z dokladu

**Stav:** hotovo — 2026-10-25 (#103 D109–D111)

## Kontext / Cíl

Evaluace promptu v4.9.0 (#103, 2026-10-25) ukázaly tři pole, kde se model
systematicky odchyluje od definice pole v projekci:

| Pole | Co model dělá | Doklady (kolo 2) |
|------|---------------|------------------|
| `notes.onDocument` | místo poznámky z dokladu vrací zákonnou doložku DPH („Intra-Community supply … exempt“, „Reverse charge …“, „Daň odvede zákazník“, „Nejsem plátce DPH.“) nebo ji k poznámce přidá | plátce 6×, neplátce 1×, spolek 1× |
| `payment.paymentReference` | doplní číslo faktury i tam, kde ho doklad jako referenci neuvádí | 0601 |
| `dates.taxPointDate` | doplní DUZP, které doklad neuvádí — u neplátce z věty „Školení proběhlo …“, u zahraničního předplatného datum vystavení | 0701, 0801 |

Systém doložky DPH odvozuje z polí `vat` (místo plnění, přenesení, plátcovství
dodavatele), takže do poznámky nepatří. Smyslem promptu je opisovat, ne
odvozovat. Úpravu sady řeší `tasks/corpus-field-semantics.md` (D110 strana
sady, D112, D113); po obou tascích jedno kolo evaluací.

Navíc oprava ukázky: první řádek má `10 × 1 033,06`, ale `totalPrice`
10 330,58 (chyba o 2 haléře od dřívějších verzí, `tasks/prompt-projection-coverage.md`
→ Výsledek).

## Návaznost

- **#103** D109–D111 (komentář 2026-10-25), D107 (test pokrytí ukázky)
- `docs/test-corpus.md` § 8 → Význam sporných polí (doplní
  `tasks/corpus-field-semantics.md`; pokud ještě neexistuje, vzít znění
  z rozhodnutí níže a sjednotit, až bude)
- `modules/core/mail/docs/ai-prompts.md` → Iterativní ladění, Changelog

## Před implementací přečti

- `modules/core/mail/profiles/czech_general.jsonc` (`prompt_template` —
  oddíl PRAVIDLA, ukázka dokladu; `prompt_version`)
- `modules/core/mail/docs/ai-prompts.md` (záznam v4.9.0 jako vzor)
- `tests/Unit/Module/Core/Mail/PromptProjectionCoverageTest.php`

## Rozhodnutí

- **D109 — Zákonná doložka DPH není poznámka.** `notes.onDocument` je
  volný text poznámky z dokladu (dodací podmínky, poděkování, pokyny).
  Doložky o osvobození, přenesení daňové povinnosti a „nejsem plátce DPH“
  do něj nepatří — ani samostatně, ani připojené k poznámce. Když doklad
  jinou poznámku nemá, pole se vynechá.
- **D110 (strana promptu) — Platební reference jen z dokladu.**
  `payment.paymentReference` = variabilní symbol nebo platební reference,
  kterou doklad uvádí („Reference“, „Verwendungszweck“, „Payment reference“).
  Když doklad výslovně žádá uvést číslo faktury s platbou, je referencí
  číslo dokladu. Jinak se pole vynechá — číslo faktury se samo od sebe
  nedoplňuje.
- **D111 — DUZP jen z dokladu.** `dates.taxPointDate` jen tehdy, když
  doklad výslovně uvádí datum uskutečnění zdanitelného plnění („DUZP“,
  „Datum zdan. plnění“, „Date of supply“, „Tax point“, „Leistungsdatum“).
  Neodvozovat z data vystavení, data platby ani z textu. Doklad od
  neplátce DPH DUZP nemá — vynechat. (Pravidlo pro účtenky — DUZP = datum
  prodeje — zůstává.)

## 1. Pravidla promptu

- Do oddílu PRAVIDLA doplnit D109–D111 stručně, v duchu ostatních
  pravidel (příklady formulací z tabulky výše jako vzory toho, co **ne**).
  Pravidlo pro účtenky o DUZP ponechat a ověřit, že si s D111 neodporuje.
- Ukázka: řádek 1 aritmeticky správně (`quantity × unitPrice = totalPrice`)
  a rekapitulace i součty podle toho; titulek klasifikace s částkou
  upravit, pokud se částka k úhradě změní.
- `prompt_version` → `v4.10.0` (všechna místa jako u v4.9.0).

## 2. Test ukázky

- `PromptProjectionCoverageTest` (nebo sousední test) rozšířit:
  u řádků ukázky s `priceCalcMode: fromUnitPrice` platí
  `round(quantity × unitPrice, 2) = totalPrice`; součty řádků → rekapitulace
  → součty už hlídá. Nad v4.9.0 musí nová kontrola selhat na řádku 1.

## 3. Dokumentace

- `ai-prompts.md`: Default prompt (v4.10.0), záznam v Changelogu (D109–D111,
  oprava ukázky).

## Kroky

1. Test aritmetiky ukázky (selže nad v4.9.0) + pravidla, ukázka a verze
   → commit `feat(mail): prompt v4.10.0 — poznámka bez doložek DPH, platební reference a DUZP jen z dokladu (#103 D109–D111)`
2. Dokumentace, `**Stav:**`, `python3 scripts/tasks-index.py` → commit
   `docs(mail): prompt v4.10.0 (#103 D109–D111)`

## Hotovo když

- [x] Nová kontrola aritmetiky ukázky nad v4.9.0 selže na řádku 1, nad
      v4.10.0 projde (v závěrečné zprávě).
- [x] `vendor/bin/phpunit --filter 'PromptProjectionCoverageTest|ProfileSchemaDriftTest|MailAiProvisionerTest|AIProfileDocumentTest|AiProfileReloadCommandTest'` projde.
- [x] `shpd-ds ai-profile-reload --dry-run` na zdroji dat s účelem „korpus“:
      v4.9.0 → v4.10.0.
- [x] Changelog promptu, `**Stav:**`, index tasků.
- [x] **Žádná evaluace v tomto tasku.**

## Pasti

- **Evaluační zdroje dat nepoužívat** (`ds-upgrade` / přeseedování by
  přepnulo prompt).
- **Jiná pravidla neměnit** — kolo evaluací má ukázat jen D109–D111
  a změnu sady z `corpus-field-semantics`.

## Výsledek (2026-10-25)

- Nová kontrola `testSampleRowsFromUnitPriceAreArithmeticallyConsistent`
  v `PromptProjectionCoverageTest` nad v4.9.0 hlásila přesně:
  ```
  řádek 1 (orderPos 1): 10 × 1033.06 = 10330.60, ale totalPrice je 10330.58
  ```
  Nad v4.10.0 prochází. `ai-profile-reload --dry-run` na korpusovém
  zdroji `ob9b-7`: `prompt_version: v4.9.0 → v4.10.0`.
- Ukázka: řádek 1 `totalPrice` 10 330,60, základ 10 830,60, DPH 2 274,43,
  k úhradě 13 105,03. Titulek „…, 13 105,03 Kč“ na obou místech (vzor
  titulku v TRIAGE i ukázka) — `MessageTitleComposer` haléře zobrazuje,
  jen `,00` vynechává.
- Pravidla v oddílu PRAVIDLA u příbuzných pravidel: D110 za bankovním
  spojením dodavatele, D111 za formátem dat, D109 za `"vat".reverseCharge`
  (jmenuje tytéž doložky). Pravidlo účtenek beze změny; D111 na něj
  odkazuje („U účtenky platí pravidlo níže: DUZP = datum prodeje“).
- `PROMPT_VERSION` generátoru v4.10.0 bez přegenerování korpusu
  (dokumentační, jako u v4.9.0); § 9 `docs/test-corpus.md` podle toho.
- Evaluace neproběhla — pouští ji člověk z dashboardu.
