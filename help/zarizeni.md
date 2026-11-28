---
title: Zařízení a jejich registrace
summary: Kde v Nastavení zaregistruješ zařízení s nativní aplikací Shipardu, jak mu přiřadíš tiskárnu účtenek a pokladnu, jak z něj vytiskneš účtenku a jak ztracené zařízení zneplatníš.
keywords: [zařízení, registrace zařízení, registrovat zařízení, toto zařízení, pracoviště, sdílené pracoviště, osobní zařízení, nativní aplikace, aplikace pro Mac, aplikace pro iPad, tiskárna účtenek, pokladní tiskárna, čtečka, periferie, role periferie, účtenky, zneplatnit zařízení, zneplatnění, ztracené zařízení, odcizené zařízení, synchronizovat, automatické zamčení, pokladna zařízení, tisk na účtenkovou tiskárnu, tisk účtenky, zkušební tisk, otevřít šuplík, pokladní zásuvka, tiskové úlohy, zkusit znovu, tiskárna netiskne, došel papír]
related: [co-dnes-nejde.md, instalace-aplikace.md, slovnicek.md, pokladna/prodejka.md]
---

# Zařízení a jejich registrace

Zařízení je počítač nebo tablet s nativní aplikací Shipardu, který se
serveru prokazuje vlastním klíčem. Registrované zařízení může mít tiskárnu
účtenek, čtečku a pokladnu — je to obdoba **pracoviště** ze starého Shipardu.

**Nativní aplikace zatím není vydaná.** Sekce je připravená pro ni; do té
doby v ní zařízení zaregistrovat nejde a seznam zůstane prázdný.

## Kdy to potřebuješ

- Chceš na Macu nebo iPadu s aplikací Shipardu tisknout účtenky nebo číst
  čárové kódy.
- Zřizuješ pracoviště, na kterém se střídá víc lidí.
- Zařízení se ztratilo nebo ho vyřazuješ a nemá mít dál přístup.

## Postup

Zařízení registruje a spravuje **administrátor**.

### Registrace

1. Na zařízení, které chceš registrovat, otevři Shipard v nativní aplikaci
   a přihlas se jako administrátor.
2. Otevři **Nastavení aplikace** → sekce **Zařízení** → **Toto zařízení**.
3. Vyplň **Název** (podle něj zařízení poznáš v seznamu, například
   *Pokladna 2*) a zvol **Režim**:
   - **Osobní** — zařízení jednoho uživatele s běžným přihlášením;
   - **Sdílené pracoviště** — pracoviště pro víc uživatelů.
4. Klikni na **Registrovat**. Panel pak ukáže název, režim a periferie,
   které zařízení nahlásilo.

### Tiskárna účtenek, pokladna a další nastavení

1. V **Nastavení aplikace** → **Zařízení** otevři seznam **Zařízení**
   a vyber zařízení (na registrovaném zařízení stačí v panelu **Toto
   zařízení** kliknout na **Otevřít záznam zařízení**).
2. Na první záložce upravíš **Název**, **Režim**, **Automatické zamčení po
   (minuty)** a **Pokladnu**.
3. Na záložce **Periferie** otevři tiskárnu a nastav jí roli **Účtenky**.
4. Ulož. Zařízení si změny převezme při příštím startu aplikace, nebo hned
   tlačítkem **Synchronizovat** v panelu **Toto zařízení**.

### Tisk účtenky, zkušební tisk a šuplík

Na registrovaném zařízení, které má tiskárnu s rolí **Účtenky**:

1. Otevři detail prodejky ve stavu **V pořádku** a klikni na **Tisk na
   účtenkovou tiskárnu**. Účtenka jde rovnou na tiskárnu zařízení, bez
   náhledu; Shipard potvrdí, že odešla.
2. Tiskárnu vyzkoušíš v panelu **Toto zařízení** tlačítkem **Zkušební
   tisk** — vytiskne zkušební účtenku s diakritikou a šířkou řádku.
3. **Otevřít šuplík** tamtéž pošle tiskárně povel k otevření pokladní
   zásuvky, bez tisku.

Když se tisk nepovede, Shipard ukáže důvod. Je-li chyba na tiskárně
(nepřipojená, došel papír), oprav ji a klikni na **Zkusit znovu** — vytiskne
se tatáž účtenka. Chybí-li nastavení (zařízení nemá tiskárnu s rolí
**Účtenky**), dialog řekne, co nastavit.

Administrátor vidí všechny tisky v **Nastavení aplikace** → **Zařízení** →
**Tiskové úlohy**: kdy, na kterém zařízení, co se tisklo, kdo tiskl a jak to
dopadlo — **Odesláno**, **Chyba** s kódem chyby, nebo **Neověřeno**.

### Zneplatnění

1. V seznamu **Zařízení** vyber zařízení a v detailu klikni na
   **Zneplatnit**.
2. Potvrď. Zařízení přestane se zdrojem dat pracovat.

## Na co narazíš

- **Zneplatnění nejde vrátit.** Zneplatněné zařízení zůstane v seznamu na
  záložce archivu; chceš-li s ním dál pracovat, zaregistruj ho znovu —
  vznikne nový záznam a tiskárnu a pokladnu mu nastavíš znovu.
- **V prohlížeči zařízení nezaregistruješ.** Panel **Toto zařízení** ti
  řekne, že registrovat jde jen z nativní aplikace.
- **Nové zařízení v seznamu nepřidáš.** Seznam nemá tlačítko **Přidat** —
  záznam vzniká jen registrací na zařízení samotném.
- **Tiskárny a čtečky se nepřidávají v Shipardu.** Připojují a nastavují se
  v aplikaci; Shipard ukáže, co zařízení nahlásilo, a ty jim jen přiřadíš
  roli. Odpojená periferie v seznamu zůstává jako **nepřipojená** a role
  jí zůstane.
- **Roli Účtenky má na jednom zařízení jen jedna tiskárna.** Druhé ji
  Shipard nepřiřadí, dokud ji první neodebereš.
- **Tlačítko Tisk na účtenkovou tiskárnu vidíš jen na registrovaném
  zařízení s tiskárnou.** V obyčejném prohlížeči není vidět vůbec — tam
  prodejku vytiskneš jen jako PDF tlačítkem **Tisk**.
- **Odesláno neznamená vytištěno.** Shipard ví, že tiskárna data převzala,
  ne že účtenka vyjela. **Neověřeno** je tisk, o kterém se výsledek
  nedozvěděl — účtenka vyjet mohla i nemusela.
- **Zkusit znovu funguje jen na zařízení, které tisklo.** Z přehledu
  **Tiskové úlohy** tisk zopakovat nejde.
- **Přehled drží tisky 30 dní,** starší se mažou.
- **Pole Pokladna** je jen ve zdroji dat s číselníkem pokladen.
- **Zařízení platí pro jeden zdroj dat.** Pracuješ-li na jednom počítači
  s víc zdroji dat, registruješ ho v každém zvlášť.
- Ztracené zařízení, ke kterému se nedostaneš ani do Shipardu, zneplatní
  správce serveru příkazem `shpd-ds device-revoke`.

## Souvisí

- [Co Shipard dnes neumí](co-dnes-nejde.md)
- [Instalace aplikace](instalace-aplikace.md)
- [Slovníček](slovnicek.md)
- [Prodejka](pokladna/prodejka.md) — doklad, ze kterého se účtenka tiskne
