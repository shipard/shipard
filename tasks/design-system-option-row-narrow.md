# Design system — volitelný řádek na úzké šířce

**Stav:** naplánováno — rozhodnutí D81 potvrzené v #149 2026-11-16

## Kontext / Cíl

Při ručním ověření `tasks/design-system-shared-controls-fixes.md` (#149)
se v dialogu Přidat firmu z registru na telefonu ukázalo, že řádek se
štítkem (např. zaniklá firma) má adresu, IČO a DIČ stlačené do úzkého
sloupce a divně zalomené; řádek bez štítku má text přes celou šířku.
`OptionRow` dává obsah vpravo (`aside`) vedle textu na každé šířce —
štítky (`.shpd-registry-wizard__row-badges`, sloupec zarovnaný vpravo)
si vezmou svou šířku a text dostane jen zbytek.

**Cíl:** na úzké šířce jde `aside` pod popis a text zůstává přes celou
šířku řádku (D81).

**Mimo rozsah:** vzhled štítků (badge systém §5), kompaktní řádky
(žádný dnes `aside` nemá), patička review na telefonu (#155).

## Návaznost

- **#149** — D76, D80, D81
- `tasks/design-system-shared-controls.md`, `tasks/design-system-shared-controls-fixes.md`

## Před implementací přečti

- `docs/design-system.md` §8 Volitelné řádky, §8 Responzivita (prahy
  `@container`, jak převést obsah na kontejner, pojmenované kontejnery)
- `frontend/src/components/ui/OptionRow.svelte`
- `frontend/src/components/registry/RegistryImportWizard.svelte`
  (`.shpd-registry-wizard__row-badges`)
- `frontend/src/components/settings/BankAccountBridgeDialog.svelte`
  (`.shpd-bank-bridge__default` v `aside`)

## Rozhodnutí (potvrzené v #149)

- ✓ **D81** Volitelný řádek na úzké šířce: když je řádek užší než práh
  úzkého obsahu (`@container` 30rem, §8 Responzivita), obsah vpravo
  (`aside`) se přesune pod popis, zarovnaný s textem (za ukazatelem
  volby); název a popis jdou přes celou šířku řádku. Štítky, které jsou
  vpravo pod sebou, se pod popisem řadí vedle sebe. Doplňuje D76.

## 1. `OptionRow`

- kořen velkého řádku je pojmenovaný kontejner: `container-type:
  inline-size; container-name: option-row` (kořen má `width: 100%`, takže
  nulovou šířku nedostane — §8 Responzivita).
- kořen má `flex-wrap: wrap` vždy (dotaz na kořen sám nedosáhne; na
  široké šířce se nic nezalomí, text má `flex: 1` a `min-width: 0`).
- `@container option-row (max-width: 30rem)`: `aside` dostane
  `flex-basis: 100%` a odsazení zleva rovné šířce ukazatele + mezeře md
  (u řádku bez ukazatele bez odsazení); mezera mezi textem a `aside` je
  mezera řádku. Kdyby to bez obalu nešlo, vlož mezi kořen a obsah obal
  na celou šířku (vzor v §8 Responzivita) a zapiš do Odchylek.
- `aside--over` (vrstva nad roztaženým labelem) musí fungovat i pod
  popisem — klik na štítek pod popisem pořád volí řádek, radio „výchozí“
  v převodu účtů jde ovládat samo.
- komentář v hlavičce komponenty doplnit o D81.

## 2. Volající

- `RegistryImportWizard.svelte`: `@container option-row (max-width:
  30rem)` — `.shpd-registry-wizard__row-badges` vedle sebe
  (`flex-direction: row; flex-wrap: wrap; align-items: flex-start`).
- `BankAccountBridgeDialog.svelte`: radio „výchozí“ pod popisem zarovnané
  s textem — zkontroluj, že nepotřebuje nic dalšího.

## 3. Dokumentace

`docs/design-system.md` §8 Volitelné řádky — věta o úzké šířce (D81);
§8 Responzivita, seznam vzorů — `OptionRow` (pojmenovaný kontejner
`option-row`).

## Commity

1. `OptionRow` na úzké šířce + volající + docs (D81)
2. stav tasku + index

## Ověření

V prohlížeči na volném dev zdroji dat, světlý vzhled, šířky 1500 a 360 px
(dialogy s podvrženou odpovědí API, jako v předchozím tasku):

1. Osoby → „Z registru“ → hledání s firmou se štítkem (zaniklá, už
   v Osobách) i bez: na 360 px má řádek se štítkem adresu, IČO a DIČ přes
   celou šířku jako řádek bez štítku, štítky pod nimi vedle sebe,
   zarovnané s textem; na 1500 px beze změny (štítky vpravo).
2. Klik na štítek pod popisem vybere řádek; zakázaný řádek (firma už
   v Osobách) se vybrat nedá.
3. Převod bankovních účtů na 360 px: zaškrtnutý účet má radio „výchozí“
   pod popisem, ovládá se samo a nezaškrtává / neodškrtává řádek.

## Hotovo když

- [ ] D81 v `OptionRow`, štítky dialogu z registru vedle sebe na úzké šířce
- [ ] ověření 1–3
- [ ] `docs/design-system.md` §8
- [ ] `**Stav:**` aktualizovaný, `scripts/tasks-index.py`

## Odchylky od zadání

(vyplní implementace)

## Pasti

- **Dotaz `@container` neplatí pro kontejner sám** — jen pro potomky
  (§8 Responzivita).
- **Pojmenovaný kontejner** — dialog z registru je uvnitř `Modal`, který
  může mít vlastní kontejner; nepojmenovaný dotaz by se ptal jiného
  předka.
- **Práh jen z tabulky §8** — `check-css-units.py` jiný `@container`
  odmítne.
- **px v CSS** zastaví pre-commit.
