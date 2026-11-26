---
title: Evidence tržeb — registrace a certifikát
summary: Jak zapnout doplněk Evidence tržeb (EET 2.0), založit registraci s evidenčními jednotkami, nahrát pokladní certifikát, zvolit režim a ověřit spojení se správcem daně.
keywords: [evidence tržeb, evidenci tržeb, EET, EET 2.0, eet2, e-tržby, etržby, elektronická evidence tržeb, registrace k evidenci tržeb, registraci, pokladní certifikát, certifikát pokladny, nahrát certifikát, certifikátu, p12, pfx, heslo k certifikátu, EIČ, evidenční identifikační číslo, evidenční jednotka, evidenční jednotky, id jednotky, DIS+, CA EET, ověřit spojení, ověření spojení, ověřovací režim, ostrý režim, playground, pojistka serveru, zprávy se neodesílají, certifikát vyprší, certifikát vypršel, obnova certifikátu, kontaktní platba, POK, potvrzovací kód]
related: [evidence-trzeb/trzby-z-dokladu.md, co-dnes-nejde.md, co-shipard-umi.md, pokladna/prodejka.md, pokladna/platba-kartou-branou-dobirkou.md]
modules: [economy.salesRegistration]
---

# Evidence tržeb — registrace a certifikát

Evidence tržeb (EET 2.0) je povinná od 1. 1. 2027 pro kontaktní platby.
V Shipardu je to **doplněk**, který se zapíná jen tam, kde ho firma
potřebuje. Tahle stránka popisuje přípravu: registraci, evidenční jednotky,
pokladní certifikát a ověření spojení.

Jakmile je registrace **V pořádku**, začnou se tržby z dokladů evidovat
samy — které doklady a jak, popisuje
[Evidence tržeb — tržby z dokladů](trzby-z-dokladu.md).

## Kdy to potřebuješ

Přijímáš platby hotově, kartou na místě nebo QR kódem a od roku 2027 je
budeš evidovat. Máš (nebo si právě vyřizuješ) pokladní certifikát a chceš
ověřit, že s ním Shipard dokáže se správcem daně komunikovat.

## Postup

**Zapnutí doplňku**

Doplněk zapíná správce serveru z konzole, v adresáři zdroje dat:

    shpd-ds ds-module enable economy.salesRegistration

Potom se v **Nastavení aplikace** objeví sekce **Evidence tržeb**.

**Co si připravíš u správce daně**

1. V portálu **DIS+** si zaregistruj evidenční jednotky (provozovny, vozy,
   e-shop…). Každá dostane číslo — to budeš potřebovat.
2. V portálu **CA EET** (přes MOJE daně) si vystav **pokladní certifikát**
   a stáhni ho jako soubor `.p12`. Heslo, které k němu zadáš, si poznamenej.
   Certifikát platí 366 dní a nese tvoje **EIČ**.

**Registrace**

1. V **Nastavení aplikace → Evidence tržeb → Registrace** dej **Přidat**.
2. Vyplň **Název** a vyber **Typ evidence** — *EET 2.0 (Česko)*.
3. **Režim** nech zatím **Ověřovací** (viz níže).
4. Vyplň **Platnost od** — den, od kterého se mají tržby evidovat. Doklad
   vystavený dřív se neeviduje, ani když ho později opravíš nebo
   stornuješ. **Platnost do** je nepovinná. Ulož.
5. Na záložce **Evidenční jednotky** přidej jednotky: **Číslo jednotky**
   z DIS+ a **Název**, podle kterého ji poznáš.
6. Zpátky na první záložce vyber **Výchozí evidenční jednotka** — použije
   se, když doklad neřekne jinak.
7. Dej **V pořádku**.

**Nahrání certifikátu**

Smí jen správce (administrátor) zdroje dat.

1. V detailu registrace dej **Nahrát certifikát**.
2. Vyber **Soubor certifikátu (.p12)** a zadej **Heslo k certifikátu**.
3. Dej **Nahrát**.

Shipard z certifikátu převezme **EIČ** a platnost a ukáže je v detailu.
Soukromý klíč uloží šifrovaně; heslo neukládá vůbec. Za rok, až si vystavíš
nový certifikát, ho nahraješ stejně — nahradí ten starý.

**Režim registrace**

| Režim | Co se děje |
|---|---|
| **Ověřovací** | Zprávy jdou správci daně jen „na zkoušku“ — nic se neeviduje a povinnost se tím neplní. Výchozí pro novou registraci |
| **Ostrý** | Tržby se skutečně evidují. Přepni, až budeš mít vyzkoušeno. **Platnost od** nesmí být dřív než 1. 1. 2027 — dřív povinnost neplatí |
| **Playground** | Zkušební prostředí správce daně se sdílenou testovací identitou. Tvůj certifikát se v něm nepoužije |

Režim změníš ve formuláři registrace (u registrace **V pořádku** přes
**Opravit**).

**Ověření spojení**

1. V detailu registrace dej **Ověřit spojení** a potvrď.
2. Shipard pošle zkušební zprávu na 0,00 Kč v ověřovacím módu — nic se
   neeviduje, ani když je registrace v ostrém režimu.
3. Výsledek řekne, kam zpráva šla a pod jakou identitou: například
   *Spojení ověřeno — produkce, certifikát registrace CZ…*.

## Na co narazíš

**„Zprávy se neodesílají“ v detailu registrace.** Nad Přehledem je vždy
napsané, kam zprávy registrace skutečně jdou. Na zkušebním nebo vývojovém
serveru je zapnutá pojistka, aby kopie tvých dat nemohla poslat tržbu
správci daně. Nastavuje ji správce serveru; z aplikace ji změnit nejde.

**Heslo k souboru certifikátu nesouhlasí.** Jde o heslo, které jsi zadal
při stažení certifikátu z portálu CA EET, ne o heslo do Shipardu.

**Nejde o certifikát pro EET 2.0.** Nahrál jsi jiný certifikát než pokladní
(třeba podpisový nebo k datové schránce). Pokladní certifikát má ve jméně
tvoje EIČ.

**Certifikát patří jinému poplatníkovi.** Registrace už má certifikát
s jiným EIČ. Pro druhého poplatníka založ vlastní registraci.

**Číslo jednotky neodpovídá tvaru.** U EET 2.0 je to číslo z DIS+ bez
úvodních nul, nejvýš devět číslic.

**„Registrace v ostrém režimu může platit nejdříve od…“** Ostrý režim
nejde zapnout s **Platností od** před 1. 1. 2027 ani bez ní. Vyzkoušet
evidenci předem můžeš v režimu **Ověřovací** nebo **Playground**.

**„Typ evidence … eviduje tržby v měně CZK“.** EET 2.0 jde použít jen ve
zdroji dat, který účtuje v korunách.

**Akce Ověřit spojení v detailu chybí.** Registrace nemá výchozí evidenční
jednotku nebo certifikát, případně je v archivu.

**Upozornění na certifikát.** Třicet dní před koncem platnosti certifikátu
se na Dashboardu objeví upozornění, po vypršení další. Upozornění dostane
i registrace **V pořádku**, která certifikát nemá vůbec.

## Souvisí

- [Evidence tržeb — tržby z dokladů](trzby-z-dokladu.md) — co se s doklady děje po zapnutí
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — co z evidence tržeb zatím chybí
- [Prodejka](../pokladna/prodejka.md)
- [Platba kartou, přes bránu a dobírkou](../pokladna/platba-kartou-branou-dobirkou.md)
