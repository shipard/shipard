-- Task 22 / vlna D — opravy zdroje lefreal (33271805401633)
-- Rozhodnutí 2026-07-22. Spouštět RW uživatelem, claude_ro je read-only.

-- === D14-A: přečíslování uzavíracích dokladů mylně číslovaných v saldo subřadě ===
UPDATE e10doc_core_heads SET docNumber='6013X0001' WHERE ndx=911;  -- bylo 601310001
UPDATE e10doc_core_heads SET docNumber='6013X0002' WHERE ndx=912;  -- bylo 601310002
UPDATE e10doc_core_heads SET docNumber='6013X0003' WHERE ndx=913;  -- bylo 601310003
UPDATE e10doc_core_heads SET docNumber='6013X0004' WHERE ndx=914;  -- bylo 601310004
UPDATE e10doc_core_heads SET docNumber='6013X0005' WHERE ndx=915;  -- bylo 601310005
UPDATE e10doc_core_heads SET docNumber='6014X0001' WHERE ndx=1743; -- bylo 601410001
UPDATE e10doc_core_heads SET docNumber='6014X0002' WHERE ndx=1744; -- bylo 601410002
UPDATE e10doc_core_heads SET docNumber='6014X0003' WHERE ndx=1745; -- bylo 601410003
UPDATE e10doc_core_heads SET docNumber='6014X0004' WHERE ndx=1746; -- bylo 601410004
UPDATE e10doc_core_heads SET docNumber='6014X0005' WHERE ndx=1747; -- bylo 601410005
UPDATE e10doc_core_heads SET docNumber='6015X0001' WHERE ndx=2480; -- bylo 601510006
UPDATE e10doc_core_heads SET docNumber='6015X0002' WHERE ndx=2481; -- bylo 601510007
UPDATE e10doc_core_heads SET docNumber='6015X0003' WHERE ndx=2482; -- bylo 601510008
UPDATE e10doc_core_heads SET docNumber='6015X0004' WHERE ndx=2483; -- bylo 601510009
UPDATE e10doc_core_heads SET docNumber='6015X0005' WHERE ndx=2484; -- bylo 601510010
UPDATE e10doc_core_heads SET docNumber='6016X0001' WHERE ndx=3000; -- bylo 601610001
UPDATE e10doc_core_heads SET docNumber='6016X0002' WHERE ndx=3001; -- bylo 601610002
UPDATE e10doc_core_heads SET docNumber='6016X0003' WHERE ndx=3002; -- bylo 601610003
UPDATE e10doc_core_heads SET docNumber='6016X0004' WHERE ndx=3003; -- bylo 601610004
UPDATE e10doc_core_heads SET docNumber='6016X0005' WHERE ndx=3004; -- bylo 601610005
UPDATE e10doc_core_heads SET docNumber='6017X0001' WHERE ndx=3350; -- bylo 601710001
UPDATE e10doc_core_heads SET docNumber='6017X0002' WHERE ndx=3351; -- bylo 601710002
UPDATE e10doc_core_heads SET docNumber='6017X0003' WHERE ndx=3352; -- bylo 601710003
UPDATE e10doc_core_heads SET docNumber='6017X0004' WHERE ndx=3353; -- bylo 601710004
UPDATE e10doc_core_heads SET docNumber='6017X0005' WHERE ndx=3354; -- bylo 601710005

-- === D15.1b: chybějící účet 343100 (hlavní analytika DPH dle konvence x100) ===
INSERT INTO e10doc_debs_accounts
  (id, fullName, shortName, accGroup, accountKind, costsType, resultsType,
   docState, docStateMain, note, toBalance, accMethod, nontax, accItem,
   useFor, useBalance, g1, g2, g3, validFrom, validTo, excludeFromReports,
   syncNdx, syncSrc)
SELECT '343100', 'Daň z přidané hodnoty', 'DPH',
   accGroup, accountKind, costsType, resultsType, docState, docStateMain,
   '', toBalance, accMethod, nontax, accItem, useFor, useBalance,
   g1, g2, g3, validFrom, validTo, excludeFromReports, 0, 0
FROM e10doc_debs_accounts WHERE id='343110';

-- === D15.5: číslo s překlepem roku (doklad celý v 2018; 601880008–10 obsazena) ===
UPDATE e10doc_core_heads SET docNumber='601880011' WHERE ndx=2964; -- bylo 601780008

-- === D15.5: legacy číslo z 2012 mimo formuli řad ===
UPDATE e10doc_core_heads SET docNumber='21210001' WHERE ndx=633;   -- bylo 210001
