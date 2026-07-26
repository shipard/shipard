-- Task 22 / vlna D — opravy zdroje msi (msiu70160)
-- Rozhodnutí 2026-07-22. Spouštět RW uživatelem, claude_ro je read-only.

-- === D14-A: přečíslování uzavíracích dokladů mylně číslovaných v saldo subřadě ===
UPDATE e10doc_core_heads SET docNumber='6013X0001' WHERE ndx=4400;  -- bylo 601310001
UPDATE e10doc_core_heads SET docNumber='6013X0002' WHERE ndx=4401;  -- bylo 601310002
UPDATE e10doc_core_heads SET docNumber='6013X0003' WHERE ndx=4402;  -- bylo 601310003
UPDATE e10doc_core_heads SET docNumber='6013X0004' WHERE ndx=4403;  -- bylo 601310004
UPDATE e10doc_core_heads SET docNumber='6013X0005' WHERE ndx=4404;  -- bylo 601310005
UPDATE e10doc_core_heads SET docNumber='6014X0001' WHERE ndx=7832;  -- bylo 601410001
UPDATE e10doc_core_heads SET docNumber='6014X0002' WHERE ndx=7833;  -- bylo 601410002
UPDATE e10doc_core_heads SET docNumber='6014X0003' WHERE ndx=7834;  -- bylo 601410003
UPDATE e10doc_core_heads SET docNumber='6014X0004' WHERE ndx=7835;  -- bylo 601410004
UPDATE e10doc_core_heads SET docNumber='6014X0005' WHERE ndx=7836;  -- bylo 601410005
UPDATE e10doc_core_heads SET docNumber='6015X0001' WHERE ndx=13047; -- bylo 601510001
UPDATE e10doc_core_heads SET docNumber='6015X0002' WHERE ndx=13048; -- bylo 601510002
UPDATE e10doc_core_heads SET docNumber='6015X0003' WHERE ndx=13049; -- bylo 601510003
UPDATE e10doc_core_heads SET docNumber='6015X0004' WHERE ndx=13050; -- bylo 601510004
UPDATE e10doc_core_heads SET docNumber='6015X0005' WHERE ndx=13051; -- bylo 601510005
UPDATE e10doc_core_heads SET docNumber='6016X0001' WHERE ndx=20037; -- bylo 601610001
UPDATE e10doc_core_heads SET docNumber='6016X0002' WHERE ndx=20038; -- bylo 601610002
UPDATE e10doc_core_heads SET docNumber='6016X0003' WHERE ndx=20039; -- bylo 601610003
UPDATE e10doc_core_heads SET docNumber='6016X0004' WHERE ndx=20040; -- bylo 601610004
UPDATE e10doc_core_heads SET docNumber='6016X0005' WHERE ndx=20041; -- bylo 601610005
UPDATE e10doc_core_heads SET docNumber='6017X0001' WHERE ndx=28910; -- bylo 601710001
UPDATE e10doc_core_heads SET docNumber='6017X0002' WHERE ndx=28911; -- bylo 601710002
UPDATE e10doc_core_heads SET docNumber='6017X0003' WHERE ndx=28912; -- bylo 601710003
UPDATE e10doc_core_heads SET docNumber='6017X0004' WHERE ndx=28913; -- bylo 601710004
UPDATE e10doc_core_heads SET docNumber='6017X0005' WHERE ndx=28914; -- bylo 601710005
UPDATE e10doc_core_heads SET docNumber='6018X0001' WHERE ndx=37697; -- bylo 601810001
UPDATE e10doc_core_heads SET docNumber='6018X0002' WHERE ndx=37698; -- bylo 601810002
UPDATE e10doc_core_heads SET docNumber='6018X0003' WHERE ndx=37699; -- bylo 601810003
UPDATE e10doc_core_heads SET docNumber='6018X0004' WHERE ndx=37700; -- bylo 601810004
UPDATE e10doc_core_heads SET docNumber='6018X0005' WHERE ndx=37701; -- bylo 601810005
UPDATE e10doc_core_heads SET docNumber='6019X0001' WHERE ndx=44865; -- bylo 601910001
UPDATE e10doc_core_heads SET docNumber='6019X0002' WHERE ndx=44866; -- bylo 601910002
UPDATE e10doc_core_heads SET docNumber='6019X0003' WHERE ndx=44867; -- bylo 601910003
UPDATE e10doc_core_heads SET docNumber='6019X0004' WHERE ndx=44868; -- bylo 601910004
UPDATE e10doc_core_heads SET docNumber='6019X0005' WHERE ndx=44869; -- bylo 601910005

-- === D15.1b: chybějící účet 343901 (DPH ze zdaněných záloh, párový k 314901) ===
-- strukturní pole se kopírují ze sousedního 343110; název dle libosti uprav
INSERT INTO e10doc_debs_accounts
  (id, fullName, shortName, accGroup, accountKind, costsType, resultsType,
   docState, docStateMain, note, toBalance, accMethod, nontax, accItem,
   useFor, useBalance, g1, g2, g3, validFrom, validTo, excludeFromReports,
   syncNdx, syncSrc)
SELECT '343901', 'Daň z přidané hodnoty (zálohy)', 'DPH (zálohy)',
   accGroup, accountKind, costsType, resultsType, docState, docStateMain,
   '', toBalance, accMethod, nontax, accItem, useFor, useBalance,
   g1, g2, g3, validFrom, validTo, excludeFromReports, 0, 0
FROM e10doc_debs_accounts WHERE id='343110';

-- === D15.2: chybějící datum vystavení (invni 22110178) ===
UPDATE e10doc_core_heads SET dateIssue='2021-04-28' WHERE ndx=51367;
