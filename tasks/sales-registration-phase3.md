# Evidence tržeb — fáze 3: Kontaktní platba, záložka a štítek na dokladu (#171)

**Stav:** naplánováno — rozhodnutí #171 D8b, D8c, D8d, D13 a upřesnění U13–U22 (schválená 2026-11-26), připraveno k implementaci

> Task pro Claude Code (4–5 commitů). Design: issue #171 (D8b–D8d, D13)
> a komentář s U13–U20. Staví na fázi 2 (`tasks/sales-registration-phase2.md`
> a `…-phase2-fixes.md` — přečti implementační poznámky). Body rozšíření
> `extensions`, `formTabs`, `detailExtensions`, `rowDecorators`,
> `recordActions` jsou hotové z #172 — změna jádra se nečeká; kdyby byla
> potřeba, popiš ji v poznámkách a přidej do #178.

## Kontext

Fáze 2 eviduje doklady, které do evidence spadají **pravidlem** typu
(prodejka a faktura vydaná hotově / kartou, pokladní doklad příjem nad
řádky). Fáze 3 přidá případ, kdy o zařazení rozhoduje **skutečnost**:
faktura vystavená Převodem, kterou zákazník zaplatí na místě (QR, karta
na terminálu). Způsob úhrady ani zaúčtování se nemění — příznak popisuje,
jak se platilo, evidence z něj odvodí tržbu. Plus viditelnost evidence
přímo na dokladu.

Metodika („Popis položek datové zprávy“, v1.0 z 5. 10. 2026): čas tržby =
okamžik přijetí platby (nebo dřívější platební příkaz — karta, QR); záloha
zaplacená osobně se eviduje při přijetí; storno nezaplaceného plnění bez
zbytečného odkladu po zjištění chyby.

## Rozhodnutí (#171)

- **D8b** — sloupec hlavičky „Kontaktní platba“ (rozšíření z doplňku)
  u faktury vydané a zálohové faktury vydané. V Konceptu a V opravě
  zaškrtávátko ve formuláři; na dokladu V pořádku akce s časem platby,
  která sloupec nastaví bez přechodu přes V opravu a hned vytvoří tržbu.
  Zrušení jen s potvrzením, potvrzenou tržbu vyrovná záporná tržba. Vždy
  celá částka dokladu. **U pokladního dokladu se příznak nenabízí**
  (komentář #171 k U13–U20).
- **D8c** — akce varuje, když fakturu už uhradil pokladní doklad, který do
  evidence spadá.
- **D8d** — výjimka: kontaktní platba je nová platba v době evidence.
- **D13** — záložka Evidence tržeb v detailu dokladu, štítek stavu ve
  vieweru dokladů.
- **U13** — sloupce `contact_payment` (příznak) a `contact_payment_at` (čas
  platby). Tržba kontaktní platby nese čas platby, ne čas přechodu (výjimka
  z D20); další tržby téhož dokladu dál čas přechodu.
- **U14** — příznak jen tam, kde doklad nespadá už pravidlem, podle dat
  typu: faktura vydaná Převodem (1); zálohová faktura vydaná Převodem (1)
  nebo Kartou (2). Nikdy Platební bránou (5), Zápočtem (4), Dobírkou (3).
- **U15** — D8d u kontaktní platby podle **času platby**: spadá, když čas
  platby je v platnosti registrace, i u dokladu vystaveného dřív — pro
  formulář i akci.
- **U16** — formulář (Koncept, V opravě): záložka Evidence tržeb se
  zaškrtávátkem a časem platby; prázdný čas = čas přechodu do V pořádku.
- **U17** — akce **Kontaktní platba** na dokladu V pořádku (dialog s časem,
  výchozí teď, ne v budoucnu; zapíše sloupce bez přechodu stavu — nevadí
  zámek dokladu ani podaného období DPH; hned tržba a pokus o odeslání;
  varování D8c v dialogu) a **Zrušit kontaktní platbu** (jen s potvrzením;
  záporná tržba s časem zrušení). Oprávnění jako Odeslat znovu.
- **U18** — záložka v detailu dokladu: vyhodnocení (spadá / nespadá a proč /
  nejde založit a proč), tržby dokladu s odkazem do agendy, štítek stavu
  v hlavičce detailu.
- **U19** — štítek „EET“ v seznamu dokladů jen u dokladů s tržbou, barva
  podle nejhoršího stavu, playground označený; jeden dotaz na stránku.
- **U20** — mimo fázi: kontaktní platba přes API a výměnný formát, kód
  potvrzení na tisku.

## Upřesnění tohoto tasku (odvozená z U13–U17, schválená 2026-11-26)

- **U21 — čas změny příznaku.** Vedle `contact_payment_at` systémový sloupec
  `contact_payment_changed_at` (kdy se příznak naposledy nastavil nebo
  zrušil; plní doplněk, ne uživatel). Slouží dvakrát:
  - čas záporné tržby po **Zrušit kontaktní platbu** na dokladu V pořádku
    (přechod stavu tam není, `doc_state_changed_at` se nemění) — záchranná
    síť tak dorovná se stejným časem jako akce (obdoba D20);
  - **okno záchranné sítě**: vedle dokladů s přechodem za posledních 7 dní
    i doklady se změnou příznaku za 7 dní — akce na starém dokladu by jinak
    mimo okno zůstala bez tržby, kdyby okamžité založení spadlo.
- **U22 — kontroly při uložení** (`beforeSave` handler hlavičky, chyba 422
  jako u kódu řady): příznak jen u typů a úhrad podle U14; čas platby ne
  v budoucnu; příznak bez času v Konceptu je v pořádku (U16). Změna příznaku
  nebo času nastaví `contact_payment_changed_at`.

## Před implementací přečti

- issue #171 (komentáře k fázi 2 a U13–U20), implementační poznámky obou
  tasků fáze 2, specifikace
  `modules/economy/salesRegistration/docs/sales-registration.md` (hlavně
  vyhodnocení, rozdíly, fronta, záchranná síť, § 8)
- `docs/modules.md` kap. 5: `extensions`, `formTabs`, `detailExtensions`,
  `rowDecorators` (kontext `ModuleExtensionContext`), `recordActions`
  (dialog, pole `datetime`, `confirm`, výsledek `warning`)
- vzory ve fázi 2: `Series\SeriesUnitFormTab` (první `formTabs`),
  `Series\SeriesUnitHandler` (kontrola v `beforeSave`),
  `Actions\ResendSaleAction`, `SalesViewer` (akce `open_detail`)
- `Evaluation\DocSaleEvaluator` — místo pro kontaktní platbu je připravené
  (`matchingRegistrations()`, komentář „Kontaktní platba (fáze 3, D8b)“)
- `Sales\SaleSync` (`soldAt()`), `Sales\ReconcileScanner`
- `src/Core/Viewer/DetailExtension.php`, `RowDecorator.php`,
  `src/Core/Form/ModuleFormTab.php`

## 1. Data a vyhodnocení (U13, U14, U15, U21, U22)

- `extensions/docs_core_heads.jsonc` doplňku: `contact_payment` (bool,
  default 0), `contact_payment_at` (datetime, nullable),
  `contact_payment_changed_at` (datetime, nullable, systémový).
  Zkontroluj matici round-tripu výměnného formátu
  (`tests/Integration/Exchange/RoundTrip/RoundTripColumns.php`): formát
  sloupce zatím nenese (U20) — pokud matice chce výjimku, přidej ji
  s důvodem a v poznámkách označ **ke schválení**.
- Data typu `cz.eet2` (vzor `rules`, D23): `contactPayment` — typy dokladů
  a způsoby úhrady podle U14, s komentářem, proč brána, zápočet a dobírka
  chybí. Hlídá `SalesRegistrationTypesDataTest`.
- `DocFacts` / `DocFactsLoader`: příznak, čas platby, čas změny.
- `DocSaleEvaluator`: doklad, který pravidlo nemá, ale má příznak
  a vyhovuje `contactPayment` typu → spadá **celou částkou**
  (`total_amount_dom`); D8d se posuzuje podle času platby (U15), ne podle
  `issue_date`; platnost registrace k času platby (D22). Pravidlo typu má
  přednost (doklad, který spadá pravidlem, příznak ignoruje). Ověř na
  datech, že `total_amount_dom` faktury s odpočtem zálohy je částka
  k úhradě (řádky `sale.advanceDeduction`).
- Kontroly U22 v `beforeSave` handleru hlavičky (stávající
  `DocsHeadsSalesHandler::onBeforeSave` nebo vlastní třída).

## 2. Čas tržby a záchranná síť (U13, U21)

- `SaleSync`: čas nové tržby
  - první tržba dokladu, který spadá kontaktní platbou → `contact_payment_at`
    (prázdný → čas přechodu, U16);
  - tržba vzniklá akcí na dokladu V pořádku (nastavení i zrušení příznaku)
    → `contact_payment_changed_at`;
  - jinak čas přechodu (D20).
  Rozhodnutí patří do jednoho místa (ne rozprostřít mezi handler a akci) —
  záchranná síť musí dojít ke stejnému času.
- `ReconcileScanner`: okno i podle `contact_payment_changed_at` (U21).
- Nový `--dry-run` výstup záchranné sítě ukáže i důvod „kontaktní platba“.

## 3. Formulář a akce (U16, U17, D8c)

- `formTabs` na `docs_core_heads`: záložka **Evidence tržeb** jen u faktury
  vydané a zálohové faktury vydané (podle dat typu, ne natvrdo), se
  zaškrtávátkem Kontaktní platba a časem platby; krátký text, kdy příznak
  použít (platba na místě — QR, karta; ne platba přes internet) a že na
  způsob úhrady ani zaúčtování nemá vliv. Bez aktivní registrace záložka
  nevzniká.
- Akce záznamu na `docs_core_heads`:
  - **Kontaktní platba** — dostupná na dokladu V pořádku, kde U14 platí,
    příznak není nastavený a existuje registrace V pořádku. Dialog: čas
    platby (`datetime`, výchozí teď). **Varování D8c** v úvodu dialogu,
    když doklad už hradí pokladní doklad příjem, který do evidence spadá
    (řádek `payment.receivable` se stejným platebním symbolem a partnerem
    salda — zjisti nejspolehlivější vazbu; nepřidávej závislost na
    `economy.accbal`, když stačí data `docs.core`). Provedení: zápis tří
    sloupců přímo (doklad zůstává V pořádku, žádný přechod, žádné hooky
    jádra), `SaleSync` + okamžitý pokus jako v handleru (jistič, U6);
    výsledek akce podle stavu tržby (potvrzeno / čeká ve frontě / nejde
    založit a proč).
  - **Zrušit kontaktní platbu** — dostupná, když je příznak nastavený;
    `confirm` s textem, že se pošle záporná tržba; zápis příznaku a času
    změny, `SaleSync` (záporný rozdíl), pokus o odeslání.
  - Obě akce bez `admin: true` (jako `resend`); log `Record action` stačí
    jako záznam, kdo a kdy.
- Na zdroji dat bez doplňku se nic z toho neuplatní (rozšíření tabulky,
  záložka, akce i štítky patří jen aktivnímu modulu) — ověř testem, že
  formulář, detail a seznam dokladů tam zůstávají beze změny.

## 4. Záložka a štítky (U18, U19)

- `detailExtensions` na `docs_core_heads`:
  - záložka **Evidence tržeb** u dokladů, které do evidence spadají, mají
    tržbu nebo nabízejí kontaktní platbu: nahoře vyhodnocení
    (`DocSaleEvaluator` — spadá / nespadá a proč / nejde založit a proč;
    srozumitelné texty důvodů, ne kódy), pod ním tržby dokladu (čas,
    částka, pořadové číslo, stav, kód potvrzení zkráceně, počet pokusů,
    playground) s akcí otevření tržby v agendě (`open_detail`);
  - štítek v hlavičce detailu: stav evidence dokladu (nejhorší stav jeho
    tržeb; „nespadá“ štítek nedává).
- `rowDecorators` na `docs_core_heads`: span „EET“ jen u dokladů s tržbou,
  barva podle nejhoršího stavu (`failed` > `pending`/`sending` > `dropped` >
  `confirmed`/`verified`), playground odlišený; jeden dotaz na stránku
  řádků.
- Výkon: záložka a štítek nečtou řádky dokladu, pokud to vyhodnocení
  nepotřebuje (stejná optimalizace jako záchranná síť).

## 5. Dokumentace

- Specifikace u doplňku: kontaktní platba (data, vyhodnocení, čas tržby,
  akce, záchranná síť), záložka a štítky; § 8 doplnit, pokud něco
  obcházíš.
- Nápověda `help/evidence-trzeb/trzby-z-dokladu.md` (nebo nová stránka):
  kdy použít Kontaktní platbu (QR nebo karta na místě u faktury
  Převodem), kdy ne (platba přes internet = Platební bránou, D15), zrušení,
  záložka a štítek; README doplňku, roadmapa a features; `**Stav:**`.

## Hotovo když

- [ ] faktura vydaná Převodem V pořádku → akce Kontaktní platba → tržba
      s časem platby z dialogu, odeslaná (playground na `8yft-3`)
- [ ] zrušení → záporná tržba s časem zrušení; součet tržeb dokladu 0
- [ ] příznak ve formuláři v Konceptu (s časem i bez) → po přechodu do
      V pořádku tržba s časem platby, resp. časem přechodu
- [ ] zálohová faktura vydaná Kartou s příznakem → tržba; faktura vydaná
      Kartou příznak nenabízí (spadá pravidlem); brána, zápočet a dobírka
      příznak odmítnou (chyba při uložení)
- [ ] doklad vystavený před `valid_from` registrace + kontaktní platba
      v době platnosti → tržba (U15)
- [ ] varování D8c v dialogu u faktury uhrazené pokladním dokladem, který
      do evidence spadá
- [ ] záchranná síť dorovná chybějící tržbu po akci i na dokladu
      s přechodem starším než 7 dní (U21), se stejným časem
- [ ] záložka Evidence tržeb v detailu dokladu (vyhodnocení + tržby),
      štítek v hlavičce, štítek v seznamu dokladů
- [ ] zdroj dat bez doplňku: formulář, detail ani seznam dokladů se
      nemění, nic nepadá
- [ ] dokumentace, nápověda, README, roadmapa a features
- [ ] cílené testy doplňku zelené, celá sada PHPUnit lokálně zelená

## Implementační poznámky

(doplní Claude Code)

### Zbývá

- Ruční proklik v prohlížeči (člověk): akce Kontaktní platba a zrušení,
  záložka formuláře, záložka detailu, štítky.
- Nasazení: `ds-upgrade` zdrojů dat s doplňkem.
