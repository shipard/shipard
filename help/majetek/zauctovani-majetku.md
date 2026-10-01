---
title: Zaúčtování majetku
summary: Jak účetní odpisy, zařazení, technické zhodnocení a vyřazení majetku zaúčtovat jedním účetním dokladem za období, co se kam účtuje a jak zaúčtování období zrušit.
keywords: [zaúčtování majetku, zaúčtovat majetek, zaúčtování odpisů, zaúčtovat odpisy, účtování odpisů, účetní doklad majetku, odpisy a zaúčtování za období, zaúčtování zařazení, zaúčtování vyřazení, zůstatková cena, oprávky, 551, 082, 022, 042, 541, zrušit zaúčtování, zrušení zaúčtování období, storno dokladu majetku, řada účetních dokladů majetku, řada dokladů majetku, nezaúčtováno, čeká na zaúčtování, doklad spravuje majetek, majetek v deníku, účetní deník podle majetku]
related: [majetek/odpisy-majetku.md, majetek/nastaveni-majetku.md, majetek/evidence-majetku.md, co-dnes-nejde.md]
---

# Zaúčtování majetku

Majetek se účtuje **dávkou za období**: Shipard založí účetní odpisy
období a všechny potvrzené, dosud nezaúčtované události majetku zaúčtuje
jedním účetním dokladem k poslednímu dni období. Doklad nepíšeš ručně —
účty bere z účetní skupiny karty.

## Kdy to potřebuješ

Končí účetní rok (nebo měsíc při měsíčních účetních odpisech) a chceš mít
odpisy, zařazení a vyřazení majetku v účetním deníku. V seznamu majetku
vidíš u karet štítek **Nezaúčtováno**. Nebo jsi zaúčtoval a potřebuješ
něco opravit.

## Postup

**Než začneš poprvé**

1. Karta musí mít **Účetní skupinu** se všemi účty, které potřebuje
   (viz [Nastavení majetku](nastaveni-majetku.md)).
2. V **Nastavení aplikace → Majetek → Odpisy** vyber **Řadu účetních
   dokladů majetku**. Nový zdroj dat má připravenou řadu **Majetek**.

**Odpisy a zaúčtování za období**

1. V seznamu **Majetek → Majetek** dej **Odpisy za období**.
2. Vyber **Účetní odpisy a zaúčtování** a **Účetní rok** (u měsíčních
   účetních odpisů **Účetní měsíc**).
3. Zkontroluj náhled: **Nové odpisy** (karty s částkou a výpočtem),
   **Události k zaúčtování** (zařazení, technická zhodnocení, snížení
   hodnoty, vyřazení a dříve potvrzené odpisy) a **Souhrn účtů** se
   stranami MD a DAL. Dole jsou **Nezahrnuté karty** s důvodem.
4. Dej **Zaúčtovat**. Vznikne jeden účetní doklad ve stavu **V pořádku**
   a dialog ukáže jeho číslo; **Otevřít doklad** ho zobrazí.

Stejné období můžeš spustit znovu — zaúčtuje jen to, co přibylo, a když
nic nepřibylo, doklad nevznikne.

**Co se kam účtuje**

| Událost | Má dáti | Dal |
|---|---|---|
| Zařazení, technické zhodnocení | Účet majetku | Účet pořízení |
| Snížení hodnoty | Účet pořízení | Účet majetku |
| Účetní odpis | Účet odpisů | Účet oprávek |
| Vyřazení — oprávky | Účet oprávek | Účet majetku |
| Vyřazení — zůstatková cena | Účet zůstatkové ceny při vyřazení | Účet majetku |

Po vyřazení je účet majetku i oprávek té karty nulový. Neodepisovaný
majetek se vyřadí celou vstupní cenou na účet zůstatkové ceny. Daňové
odpisy, přerušení a počáteční stav se neúčtují.

**Kde to uvidíš**

- Na kartě v záložce **Účetní odpisy** má potvrzený řádek stav
  **Zaúčtováno — doklad …**, nebo **Čeká na zaúčtování**.
- V **Účtárna → Účetní deník** je sloupec a filtr **Majetek** — obraty
  účtu jdou rozpadnout po kartách.

**Zrušení zaúčtování období**

1. Otevři **Odpisy za období**, vyber **Účetní odpisy a zaúčtování**
   a poslední zaúčtované období.
2. Dej **Zrušit zaúčtování období** a potvrď. Doklad se stornuje
   a události se od něj odpojí; odpisy zůstanou potvrzené.
3. Oprav, co potřebuješ (odpis smažeš od konce, viz
   [Odpisy majetku](odpisy-majetku.md)), a období zaúčtuj znovu.

## Na co narazíš

**Účtuje se bez děr.** Karta s nezaúčtovanou událostí z dřívějšího
období je v náhledu mezi nezahrnutými — nejdřív zaúčtuj starší období.
Zrušit jde naopak jen **poslední** zaúčtované období.

**Zaúčtovaná událost je zamčená.** Nejde opravit ani smazat, dokud
nezrušíš zaúčtování období. Totéž platí pro zrušení vyřazení, jehož
odpisy jsou zaúčtované.

**Doklad spravuje Majetek.** Účetní doklad majetku nejde ručně opravit,
stornovat ani smazat — mění se jen zrušením zaúčtování a novým během.

**Chybí účet účetní skupiny.** Karta se nezaúčtuje a náhled řekne, který
účet chybí. Doplň ho ve skupině a spusť období znovu.

**Zamčený účetní měsíc.** Do zamčeného měsíce doklad nevznikne a jeho
zaúčtování nejde ani zrušit — nejdřív měsíc odemkni.

**Účetní odpis jedné karty** (tlačítko **Odepsat** v detailu) se jen
potvrdí; zaúčtuje ho až dávka za období.

## Souvisí

- [Odpisy majetku](odpisy-majetku.md)
- [Nastavení majetku](nastaveni-majetku.md)
- [Evidence majetku](evidence-majetku.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
