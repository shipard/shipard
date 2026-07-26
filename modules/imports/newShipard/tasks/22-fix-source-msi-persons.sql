-- Task 22 / dodatek v2 — opravy zdroje msi (msiu70160): osoby ambiguous-header
-- Rozhodnutí 2026-07-22 (oprava zdroje + fallback v PersonsRunneru).
-- Spouštět RW uživatelem, claude_ro je read-only.
-- PŘED SPUŠTĚNÍM doplnit křestní jména označená TODO (fallback runneru je
-- jen pojistka — odvodí jméno z fullName, správné rozdělení je lepší).

-- === FO bez firstName (lastName drží celé jméno) — PersonsRunner je skipoval,
-- === doklady na ně pak neměly LocalIdMap pin → 422 ambiguous-header ===

-- 481 Zhang Bo (10 dokladů, mj. invno 11310074/154/250/327/423):
-- čínské pořadí: příjmení Zhang, jméno Bo — potvrdit.
UPDATE e10_persons_persons SET firstName='Bo', lastName='Zhang' WHERE ndx=481;

-- 221 JUDr. Růžička (1 doklad): titul do beforeName, křestní jméno TODO.
UPDATE e10_persons_persons SET beforeName='JUDr.', firstName='TODO', lastName='Růžička' WHERE ndx=221;

-- 1748 Beltrám (2 doklady): křestní jméno TODO.
UPDATE e10_persons_persons SET firstName='TODO', lastName='Beltrám' WHERE ndx=1748;

-- 1763 Kalhová (2 doklady): křestní jméno TODO.
UPDATE e10_persons_persons SET firstName='TODO', lastName='Kalhová' WHERE ndx=1763;

-- === Smazané firmy referencované doklady — filtr runneru (docState != 9800)
-- === je vynechá; undelete do archivu (vzor „referencovaná entita existuje") ===

-- 1312 SECOMP PC Plus CZ, a.s. (7 dokladů)
UPDATE e10_persons_persons SET docState=9000 WHERE ndx=1312;

-- 1705 Amazonia ccc s.r.o. (1 doklad, invno 11911084 / old ndx 40342)
UPDATE e10_persons_persons SET docState=9000 WHERE ndx=1705;
