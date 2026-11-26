# Evidence tržeb — úpravy po fázi 2: název registrací, log a první zaslání (#171)

**Stav:** hotovo — R1, F1 a F2 hotové 2026-11-26 ve dvou commitech; ověřeno na ukázkovém zdroji `8yft-3` (název v navigaci Nastavení a hledání palety, integrační test fronty); při nasazení `ds-upgrade` zdrojů dat s doplňkem (název položky a popisek stavu „Přerušeno“)

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

- [x] paleta na „EET“ najde Registrace EET; Nastavení a nápověda mají
      nový název
- [x] výjimka při okamžitém odeslání dá v logu „left to the queue“, ne
      „not created“
- [x] osiřelý pokus po obnově = `interrupted`, další pokus
      `prvni_zaslani = false`
- [x] specifikace u doplňku (fronta, stavy pokusu) a `**Stav:**` tohoto
      tasku
- [x] cílené testy doplňku zelené, celá sada PHPUnit lokálně zelená

## Implementační poznámky

**R1.** Jen název vieweru v `module.jsonc` a tři texty s cestou (nápověda,
README, specifikace § 3.3); druhá stránka nápovědy cestu nenese. Skupina
„Registrace“ v detailu registrace a mezinadpis v nápovědě zůstaly. Ověřeno
na `8yft-3` po `ds-upgrade`: navigace Nastavení vrací „Registrace EET“ /
„EET registrations“ a hledání palety (`rankResults` nad skutečnou odpovědí
navigace) dává na dotaz „EET“ položku jako první, na „registrace“ obě
registrace. V prohlížeči neproklikáno.

**F1.** Záznam „not created“ z ověření fáze 2 nevznikl v odeslání, ale už
při sestavení fronty: ověřovací skript podvrhl `ServerConfig` bez `host`
a `queue()` vyhodila výjimku. Vlastní `try` proto obaluje celé
`afterCreated()`, ne jen `attempt()`. Kontext hlášky nese navíc třídu
a text výjimky (`exception`, `error`) — `ErrorLogger::warn()` výjimku
s trasou neloguje a příčina by se jinak ztratila.

Hláška „left to the queue“ je doslova pravdivá, když tržba zůstala
`pending`. Spadne-li odeslání až po zámku odesílatele, zůstane tržba
`sending` a do fronty ji vrátí obnova po 10 minutách — pokus, který stihl
vzniknout, pak dostane `interrupted` (F2). Popsáno ve specifikaci § 12.3.

**F2.**

- Schéma se nemění — sloupec `status` má délku 12, `interrupted` se vejde.
- `SaleStore::recoverStale()` označí pokusy **před** vrácením tržby
  (`UPDATE` pokusů přes `JOIN` na zaseknuté tržby). Pád mezi oběma dotazy
  nechá tržbu `sending` a další obnova ji dorovná; v opačném pořadí by
  pokus zůstal `sending` navždy.
- `sent` přerušeného pokusu zůstává 0 — „možná odeslaný“ říká jen stav.
- Jistič (`lastAttemptStatus()`) přerušený pokus přeskakuje stejně jako
  `sending`: nemá výsledek, o výpadku služby nic neříká.
- V detailu tržby má přerušený pokus popisek „Přerušeno“ a třídu řádku
  `error` jako odmítnutí a výpadek (zadání chtělo jen popisek; zvýraznění
  je volba implementace — zpráva mohla odejít a jde podruhé).
- Nápověda dostala odstavec „Pokus s výsledkem Přerušeno“ (`Na co narazíš`).
- Jediné nové SQL (`UPDATE … JOIN`) pokrývá integrační test
  `testRecoveryMarksTheOrphanedAttemptAndTheNextOneIsNotTheFirstSending`
  nad skutečnými tabulkami; paměťové úložiště testů ho nevidí.

**Při nasazení:** `ds-upgrade` zdrojů dat s doplňkem — bez něj zůstane
v navigaci starý název a přerušený pokus se v detailu ukáže kódem
`interrupted` místo popisku.
