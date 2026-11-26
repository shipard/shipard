# Evidence tržeb — úpravy po fázi 2: název registrací, log a první zaslání (#171)

**Stav:** naplánováno — R1 (uživatel 2026-11-26), F1 a F2 z ověření fáze 2

> Malý task pro Claude Code (1–2 commity). Navazuje na
> `tasks/sales-registration-phase2.md`; ruční proklik fáze 2 prošel
> 2026-11-26. Tři drobnosti před uzavřením fáze 2.

## R1 — „Registrace“ → „Registrace EET“

V paletě příkazů (Cmd/Ctrl+K) vedle sebe stojí **Registrace DPH** a jen
**Registrace** (se skupinou Evidence tržeb) — hledání „EET“ nic nenajde
a „registrace“ je nejednoznačné.

- Viewer `economy.salesRegistration.registrations` v `module.jsonc`:
  `name:cs` **Registrace EET**, `name` / `name:en` **EET registrations**
  (vzor `economy.codebooks.vatRegistrations` — „Registrace DPH“ /
  „VAT registrations“).
- Název tabulky (`Registrace k evidenci tržeb`), popisky sloupců
  `registration` a texty hlášek **beze změny** — tam je význam jasný
  z kontextu.
- Cesty v textech: `help/evidence-trzeb/registrace-a-certifikat.md`
  („Nastavení aplikace → Evidence tržeb → Registrace“) a README doplňku
  (`RegistrationsViewer` — Nastavení → Evidence tržeb → Registrace) →
  „Registrace EET“. Projdi i specifikaci a druhou stránku nápovědy
  (`grep` s `--include=*.md` nad `help/evidence-trzeb` a adresářem doplňku).
- Po změně `ds-upgrade` zdroje dat `8yft-3` (zkompilovaný název v
  navigaci a paletě); ověř, že paleta na dotaz „EET“ registrace najde.

## F1 — log „not created“ u tržby, která vznikla

`DocsHeadsSalesHandler::onStateChanged()` má jeden `try` kolem založení
tržby i okamžitého odeslání (`afterCreated()`). Výjimka při odeslání se
tak zaloguje jako „sale of document … not created“, přestože tržba
vznikla (a cron ji pak poslal) — v logu dev serveru je takový záznam
z ověření fáze 2.

- Odeslání vlastní `try`: hláška „sales registration: sale {sale} of
  document {doc} not sent right away — left to the queue“ (úroveň
  `warning`, kontext `sale`, `doc`, `registration`).
- „not created“ zůstává jen pro selhání založení.
- Test: výjimka z fronty po úspěšném založení → tržba existuje, log říká
  „left to the queue“.

## F2 — `prvni_zaslani` po pádu procesu

`SaleQueue::attempt()` zapíše pokus se stavem `sending` před odesláním
a `sent` doplní až po něm. Když proces spadne mezi odesláním a zápisem
výsledku, zůstane pokus `sending` se `sent = 0`, obnova (`recoverStale`)
vrátí tržbu na `pending` a další pokus pošle znovu `prvni_zaslani = true`
— metodika ale chce `false` u každého dalšího pokusu o zaslání téže
tržby.

- Nový stav pokusu **`interrupted`** („Přerušeno“ / „Interrupted“
  v `attemptStatuses.jsonc`): obnova zaseknuté tržby nastaví jejím pokusům,
  které zůstaly `sending`, stav `interrupted`.
- `hasSentAttempt()` počítá `sent = 1` **nebo** `status = 'interrupted'` —
  pokus, o kterém nevíme, jestli odešel, se bere jako možná odeslaný
  (raději `false` u skutečně prvního zaslání než dvakrát `true`).
- Detail tržby v agendě ukáže přerušený pokus vlastním popiskem.
- Testy: obnova označí osiřelý pokus; další pokus jde s
  `firstSending = false`; pokus `dropped` a pokus s problémem na naší
  straně se dál jako odeslané nepočítají.

## Hotovo když

- [ ] paleta na „EET“ najde Registrace EET; Nastavení a nápověda mají
      nový název
- [ ] výjimka při okamžitém odeslání dá v logu „left to the queue“, ne
      „not created“
- [ ] osiřelý pokus po obnově = `interrupted`, další pokus
      `prvni_zaslani = false`
- [ ] specifikace u doplňku (fronta, stavy pokusu) a `**Stav:**` tohoto
      tasku
- [ ] cílené testy doplňku zelené, celá sada PHPUnit lokálně zelená

## Implementační poznámky

(doplní Claude Code)
