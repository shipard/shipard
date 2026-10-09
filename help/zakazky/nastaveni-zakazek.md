---
title: Nastavení zakázek
summary: Kde založíš druhy zakázek s typem a číselné řady zakázek, jak napsat vzorec čísla zakázky a co se po založení už nedá změnit.
keywords: [nastavení zakázek, druh zakázky, druhy zakázek, nový druh zakázky, typ zakázky, typ druhu, číselná řada zakázek, číselné řady zakázek, nová řada zakázek, kód řady, vzorec čísla zakázky, vzorec čísla, číslování zakázek, pořadové číslo, restart počítadla, restart každý fiskální rok, průběžné číslování, bez restartu, platnost řady, "%C", "%y", "%Y", "%4", placeholder, řada bez pořadí, nejde uložit řadu, nejde změnit typ druhu, nejde změnit druh řady]
related: [zakazky/zakazky.md, zakazky/zakazka-na-dokladech.md, co-shipard-umi.md]
---

# Nastavení zakázek

Číselníky zakázek jsou v **Nastavení aplikace → Zakázky**: **Druhy
zakázek** a **Číselné řady zakázek**. Bez druhu a jeho řady zakázku
nezaložíš — Shipard žádné předpřipravené nemá, zakládáš je podle toho,
jak zakázky dělíš ty.

## Kdy to potřebuješ

Zakládáš první zakázku a tlačítko **Přidat** nemá kam ji dát. Nebo chceš
zakázky dělit po pobočkách či oblastech do víc řad. Nebo ti nevyhovuje
výchozí tvar čísla a chceš vlastní vzorec.

## Postup

**Druh zakázky**

1. V **Nastavení aplikace → Zakázky → Druhy zakázek** dej **Přidat**.
2. Vyplň **Název** (třeba *Projekty*, *Smlouvy*, *Režie*) a vyber **Typ
   zakázky**: **Periodická**, **Externí jednorázová**, **Interní
   průběžná** nebo **Interní jednorázová**. Co jednotlivé typy znamenají,
   říká tabulka v [Zakázkách](zakazky.md).
3. Dej **V pořádku**. Od té chvíle je typ druhu jen ke čtení — určuje,
   co zakázky druhu mají, a jeho změna by je rozbila. Pro jiný typ založ
   nový druh.

**Číselná řada zakázek**

1. V **Nastavení aplikace → Zakázky → Číselné řady zakázek** dej
   **Přidat**.
2. Vyber **Druh zakázky**. Řada patří jednomu druhu a po založení se
   druh nemění; druh může mít víc řad (pobočky, oblasti). Prázdný **Název
   řady** se předvyplní názvem druhu.
3. Vyplň **Kód řady** (dosadí se za `%C`) a zkontroluj **Vzorec čísla
   zakázky**. Výchozí `%C%y%4` dá pro kód *Z* v roce 2026 číslo
   *Z260001*. Ve vzorci můžeš použít:
   - `%C` — kód řady,
   - `%y` / `%Y` — rok dvoumístně / čtyřmístně,
   - `%3` až `%6` — pořadové číslo doplněné nulami na tolik míst.
   Pořadové číslo je ve vzorci povinné, jinak by se čísla opakovala.
4. **Restart počítadla**: **Restart každý fiskální rok** začíná v každém
   účetním roce od jedničky (rok bere z data zahájení zakázky), **Bez
   restartu (průběžné)** čísluje dál napříč roky.
5. **Platnost od** a **Platnost do** jsou nepovinné.
6. Dej **V pořádku**. Řada se objeví jako záložka dole v seznamu zakázek
   a **Přidat** z ní zakládá zakázku rovnou do ní.

## Na co narazíš

**Řada s ročním restartem potřebuje účetní rok.** Zakázku s datem
zahájení v roce, pro který není založený účetní rok, nejde potvrdit —
založ ho v **Nastavení aplikace → Účetnictví → Fiskální období**.

**Řadu ani druh, které už nepoužíváš, nemaž** — zakázky na ně odkazují.
Dej jim **Ukončit platnost**; v nabídce nové zakázky se přestanou nabízet,
staré zakázky zůstanou čitelné.

**Číslo zakázky je v celém zdroji dat jedinečné**, i napříč řadami. Dvě
řady se stejným kódem a vzorcem by se o čísla přetahovaly — dej každé
jiný kód.

## Souvisí

- [Zakázky](zakazky.md)
- [Zakázka na dokladech](zakazka-na-dokladech.md)
