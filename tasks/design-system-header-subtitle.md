# Design system — podtitul hlavičky v roli body

**Stav:** hotovo — #139 task 3 (D56), 2026-11-09; drobnost z ověření tasků 1 a 2

## Kontext / Cíl

Při ručním ověření #139 byl podtitul hlavičky („číslo · text“ u dokladu)
v detailu i v review modalu příliš malý — dnes role meta (12 px), stejně
velký jako štítky. Hlavičku kreslí jedna sdílená `DetailHeader`
(#139 D48), takže změna platí pro všechny detaily záznamů i review.

**Cíl:** podtitul `DetailHeader` v roli body (14 px), dokumentace a
komentáře podle D56. Nic jiného se nemění.

**Mimo rozsah:**

- podtitul `Modal` (zdrojová zpráva v review, formulářové modaly) a
  podtitulek karty feedu — jiné prvky;
- štítky (`Badge`, meta), titulek (title), ikona a odsazení hlavičky;
- co backend do podtitulu skládá.

## Návaznost

- **#133** — D17, D39 (typografie), D56
- **#139** — `tasks/design-system-document-parts.md` (vznik
  `DetailHeader`), `tasks/design-system-review-modal.md`

## Před implementací přečti

- `docs/design-system.md` §11 Typografie (tabulka rolí), §12 Pořadí shora
  dolů (Hlavička)
- `frontend/src/components/ui/DetailHeader.svelte`

## Rozhodnutí (potvrzená v #133)

- ✓ **D56** Podtitul hlavičky (`DetailHeader`) je v roli **body (14 px)**,
  ne meta: titulek 18 / podtitul 14 / štítky 12 px. Platí pro detail
  záznamu i review (#139); upřesňuje D17 a §12 Hlavička. Podtitul modalu
  a karet feedu se nemění.

## Co je potřeba udělat

1. `DetailHeader.svelte`: `.shpd-detail-header__subtitle` →
   `font-size: var(--shpd-font-size-body)`, barva textu beze změny;
   `margin-top` ponechat a posoudit v prohlížeči (jiná výška řádku).
   Komentáře v hlavičce souboru a nad CSS: „podtitul body (D56)“.
2. `docs/design-system.md`:
   - §11 tabulka rolí: u **body** do sloupce Kde přidat „podtitul hlavičky
     detailu (D56)“, u **meta** „podtitul“ odebrat;
   - §12 Pořadí shora dolů, bod Hlavička: „podtitul (meta, barvou textu)“
     → „podtitul (body, barvou textu, D56)“;
   - §13 Soubory, řádek `DetailHeader`: „podtitul meta“ → „podtitul body“.
3. `frontend/src/styles/variables.css`: komentář u
   `--shpd-font-size-meta` („doplňky, podtitul, štítek“) → bez podtitulu,
   u `--shpd-font-size-body` doplnit podtitul hlavičky.
4. `grep -rn -i "podtitul" docs/design-system.md frontend/src` — jiné
   zmínky o podtitulu v roli meta upravit.

## Commity

1. `style(frontend): podtitul hlavičky detailu v roli body (#139, D56)`
   — body 1–4, `**Stav:**` tasku, `python3 scripts/tasks-index.py`

## Ověření

```bash
cd frontend && npm run build
python3 scripts/check-css-tokens.py && python3 scripts/check-css-units.py
```

V prohlížeči (Firefox), okno 1440 a 1280 px a telefon 390 px:

- detail dokladu (podtitul „číslo · text“), karty majetku a bankovního
  výpisu — podtitul 14 px, štítky dál menší, titulek dál výrazně větší;
- **dlouhý podtitul** na úzké šířce (drawer, telefon) se zalomí, nepřeteče
  a hlavička nevyskočí mimo panel;
- review modal z feedu a náhled Spisovny — totéž;
- `ViewerDetailModal` (karta majetku → Řádky deníku → doklad) — totéž.

## Hotovo když

- [x] podtitul `DetailHeader` v roli body
- [x] `docs/design-system.md` §11, §12, §13 a komentáře v `variables.css`
      bez podtitulu v roli meta
- [x] ověřeno v detailu tří typů záznamů, v review a v náhledu Spisovny
- [x] `**Stav:**` aktualizovaný, `tasks-index.py`

## Pasti

- **Jedna komponenta, všechny detaily.** Změna se projeví u každého
  vieweru — ověřit i záznam s dlouhým podtitulem (výchozí podtitul
  z `displayPattern`, #136 D19), ne jen doklad.
- **Podtitul modalu je jiný prvek.** `Modal` má vlastní
  `.shpd-modal__subtitle` (zdrojová zpráva v review) — neměnit.
