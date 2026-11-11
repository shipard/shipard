# Navigace — skupina Reporty s id podle sekce

**Stav:** naplánováno — 2026-11-11 (#133 Další nesoulady)

## Kontext / Cíl

Chyba nalezená při ručním ověřování #133: v rozložení **sidebar** jsou
dvě podskupiny Reporty (pod Majetkem a pod Účtárnou) a rozbalují se
a sbalují spolu — klik na jednu otevře nebo zavře obě. Classic a wild
jsou v pořádku.

**Příčina (z čtení kódu):** `NavigationController::collectReportItems()`
dává skupině Reporty v každé sekci stejné id `'reports'`. Komentář u něj
to předvídá („v praxi žijí reporty v jedné sekci (accounting); víc sekcí
by chtělo id per sekce“). Od 2026-10-01 mají reporty i sekce `assets`
(`modules/economy/assets/config/reports.jsonc`, `navSection: "assets"`).
`NavTree.svelte` drží rozbalení v `Set` podle `group.id`, takže dvě
skupiny se stejným id sdílejí stav. Duplicitní id ve stromu může rozbít
i další věci, které strom prochází podle id (předci aktivní položky,
klíče `#each`).

**Cíl:** id skupiny Reporty je jedinečné ve stromu navigace; podskupiny se
rozbalují nezávisle.

**Mimo rozsah:**

- popisek skupiny (`$language === 'cs' ? 'Reporty' : 'Reports'`) —
  dvojjazyčný zápis v PHP mimo viewery; nech beze změny, pokud převod na
  katalog není na jeden řádek se stávajícím katalogem navigace (pak ano
  a zapiš);
- vzhled navigace, classic a wild.

## Před implementací přečti

- `src/Api/Controller/NavigationController.php` — `collectReportItems()`
  a místo, kde se skupiny zařazují do sekcí (`_section`, `_order`)
- `frontend/src/components/chrome/NavTree.svelte` — `expanded`,
  `toggleGroup()`, `collectAncestorGroupIds()`
- `tests/Unit/Api/Controller/NavigationReportsTest.php`
- `docs/reports.md` §7.1 (UI, D10)

## 1. Oprava

- Id skupiny `reports:<sekce>` (`reports:accounting`, `reports:assets`).
- Ověř, že nic ve frontendu ani v backendu nečte id skupiny `'reports'`
  natvrdo (grep ukázal jen test; `panelId: 'reports'` u leafů je jiná věc
  a zůstává). Ověř i shelly classic a wild — proč fungují (zřejmě strom
  procházejí jinak) a že po změně id fungují dál.
- Komentář v `collectReportItems()` podle nového stavu.

## 2. Testy

- `NavigationReportsTest`: hledání skupiny podle nového id; nový test —
  dvě sekce s reporty dají dvě skupiny s různým id.
- Obecný test, že id všech skupin (uzlů s `children`) ve stromu navigace
  jsou jedinečná — aby se stejná chyba neopakovala s jinou skupinou.

## 3. Dokumentace

- `docs/reports.md` §7.1 — id skupiny Reporty per sekce.
- Checklist #133: bod se zaškrtne při ověření (chat), ne v tasku.

## Commity

1. `fix(navigation): skupina Reporty s id podle sekce (#133)` — kroky 1–2
2. `docs: id skupiny Reporty v navigaci (#133)` — krok 3 + `**Stav:**`
   tohoto tasku + `python3 scripts/tasks-index.py`

Je-li oprava malá, smí být v jednom commitu.

## Ověření

- `php -l`; PHPUnit `--filter 'NavigationReportsTest|Navigation'`;
  `cd frontend && npm run build`.
- Chromium (Playwright, zdroj dat `free` s majetkem a účetnictvím),
  rozložení sidebar: sbalení / rozbalení Reportů pod Majetkem nemění
  Reporty pod Účtárnou a naopak; přímý odkaz na report (deep link)
  rozbalí jen správnou skupinu; classic a wild beze změny.

## Hotovo když

- [ ] Skupiny Reporty mají id podle sekce a rozbalují se nezávisle
- [ ] Test jedinečnosti id skupin ve stromu navigace
- [ ] Classic a wild beze změny
- [ ] Dokumentace, `**Stav:**` aktualizovaný, index tasků přegenerovaný

## Pasti

- Rozbalení se inicializuje při změně stromu (`$effect` v `NavTree`) —
  ověř deep link na report v obou sekcích, ne jen klikání.
- Pořadí skupiny v sekci nese `_order` — změna id ho nesmí ovlivnit.
