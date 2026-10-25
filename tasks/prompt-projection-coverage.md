# Prompt: ukázka dokladu pokryje celou papírovou projekci (v4.9.0)

**Stav:** naplánováno — zadání 2026-10-25 (#103 D107, D108)

## Kontext / Cíl

Evaluace tří sad (2026-10-23) ukázaly, že model systematicky vynechává
web dodavatele (P1) a období plnění (P2), i když jsou na PDF. Příčina je
v promptu `czech_general` (v4.8.0): pravidlo „Do struktury nikdy
nepřidávej klíče, které nejsou v ukázce níže“ a ukázka dokladu nemá
**přesně pět polí papírové projekce** (`tests/corpus/projection.jsonc`):

- `supplier.contact.web`, `customer.contact.web`
- `dates.periodFrom`, `dates.periodTo`
- `rows[].item.ean`

Ostatních 97 polí projekce v ukázce je. Výstupní schéma (`output_schema`)
všech pět polí zná — chybí jen v ukázce. EAN by model vynechával dál,
i když ho korpus po `corpus-print-fidelity` tiskne.

**Cíl:** doplnit pět polí do ukázky (verze v4.9.0) a test, který příště
nesoulad ukázky a projekce najde sám — stejný princip jako D105 na
straně korpusu.

## Návaznost

- **#103** D107, D108 (komentář 2026-10-25), D14 (papírová projekce),
  D105 (věrnost tisku), komentář 2026-10-23 „Věrnost tisku korpusu hotovo“
- `modules/core/mail/docs/ai-prompts.md` → Iterativní ladění promptu,
  Changelog promptu

## Před implementací přečti

- `modules/core/mail/profiles/czech_general.jsonc` — `prompt_version`,
  `prompt_template` (oddíl „VRAŤ POUZE JSON v této struktuře“, pravidla
  o vynechání polí), `output_schema` (`periodFrom`, `periodTo`, `web`, `ean`)
- `modules/core/mail/docs/ai-prompts.md` (Default prompt, Iterativní ladění,
  Changelog — vzor záznamu v4.8.0)
- `modules/core/exchange/src/Compare/Projection.php` a `ProjectionTest`
  (čtení projekce, cesty listů)
- `tests/Unit/Module/Core/Mail/AIProfileDocumentTest.php` (jak testy
  šablonu profilu načítají)

## Rozhodnutí

- **D107 — Ukázka v promptu pokryje celou papírovou projekci.** PHPUnit
  test porovná cesty projekce (`Projection`, ne druhý parser) s cestami
  ukázky dokladu v `prompt_template` profilu `czech_general`. Každá cesta
  projekce musí v ukázce být. Strana (`supplier` / `customer`) sdílí tvar —
  stačí ji pokrýt u `supplier` (`customer` je v ukázce `null`). Výjimky
  jen výslovným seznamem v testu, každá s důvodem; teď je seznam prázdný.
  Selhání vypíše chybějící cesty.
- **D108 — Doplnit pět polí do ukázky, verze v4.9.0.** Hodnoty v ukázce
  realistické a v duchu ostatních: web dodavatele v `contact`, období
  plnění v `dates` (dvojice dat jednoho měsíce), EAN u jednoho řádku
  (u zboží; u konzultace ne). Žádná další změna pravidel promptu —
  evaluace má ukázat jen vliv pěti polí.

## 1. Test pokrytí (D107)

- Vytažení ukázky z šablony: první JSON objekt za větou „VRAŤ POUZE JSON
  v této struktuře“, zástupné řetězce `"<…>"` nahradit hodnotou před
  parsováním. Když se značka nenajde nebo ukázka nejde parsovat, test
  selže se srozumitelnou zprávou (ne přeskočí).
- Cesty ukázky z `document.extracted_json`, pole jako `rows[]`.
- Test napsat **nejdřív** a ověřit, že nad v4.8.0 hlásí přesně pět cest
  výše — výsledek do závěrečné zprávy.

## 2. Ukázka a verze (D108)

- Pět polí do ukázky dokladu v `prompt_template`.
- `prompt_version` → `v4.9.0` na obou místech, kde se verze v šabloně
  vyskytuje (`prompt_version` profilu i text `source.promptVersion` v pravidlech
  a v ukázce).
- `modules/core/mail/docs/ai-prompts.md`: nadpis „Default prompt (v4.9.0)“,
  text promptu v dokumentu podle šablony, záznam v Changelogu (co a proč —
  odkaz na D107, D108).

## Kroky

1. Test D107 (nad v4.8.0 selže s pěti cestami) + ukázka a verze D108
   (test projde) → commit
   `feat(mail): ukázka promptu pokrývá papírovou projekci, test pokrytí — v4.9.0 (#103 D107, D108)`
2. Dokumentace, `**Stav:**`, `python3 scripts/tasks-index.py` → commit
   `docs(mail): prompt v4.9.0 (#103 D108)`

## Hotovo když

- [ ] Test D107 nad v4.8.0 hlásí přesně `supplier.contact.web`,
      `customer.contact.web`, `dates.periodFrom`, `dates.periodTo`,
      `rows[].item.ean` (v závěrečné zprávě); nad v4.9.0 projde.
- [ ] `vendor/bin/phpunit --filter 'PromptProjectionCoverageTest|AIProfileDocumentTest|AiProfileReloadCommandTest|ProjectionTest'` projde (název nového testu podle implementace).
- [ ] `shpd-ds ai-profile-reload --dry-run` na volném zdroji dat s účelem
      „korpus“ ukáže v4.8.0 → v4.9.0.
- [ ] Dokumentace a changelog promptu, `**Stav:**`, index tasků.
- [ ] **Žádná evaluace v tomto tasku** — pouští ji člověk z dashboardu.

## Pasti

- **Evaluační zdroje dat nepoužívat** — `ds-upgrade` nebo přeseedování
  by na nich přepnulo prompt dřív, než doběhne srovnávací kolo
  s v4.8.0.
- **Ukázka je zároveň vzor formátu** — nový řádek s EAN nesmí změnit
  součty ukázky (`vatRecap`, `totals` musí dál sedět s řádky); EAN
  doplnit k existujícímu řádku zboží, nebo upravit ukázku tak, aby
  součty dál souhlasily.
