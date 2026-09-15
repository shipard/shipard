# Modul: docs.cashRegister

Modul pro **Prodejky** (`doc_type = 'cashreg'`). Polymorfní subclass nad
`docs.core`, issue #59.

## Účel

Prodej za hotové nebo kartou na pokladně — doklad s pevným směrem výstup
(`trade_dir: 1`, my jsme dodavatel, DPH na výstupu), typicky bez partnera.
Výjimečně i **převodem**: prodejka „na převod“ je pohledávka (311 místo
pokladny), partner je pak povinný. Zavedené kvůli migraci (starý Shipard je
měl), v běžném provozu je to spíš faktura.
Vratka je prodejka se **zápornými řádky** (#59 D9), žádný zvláštní typ ani
směr.

Číselná řada je **vázaná na pokladnu** (`series_binding: cash_desk`) stejně
jako u pokladních dokladů: řada per aktivní pokladna vzniká automaticky,
záložky vieweru = pokladny, pokladna se na dokladu nezadává. Číslo má tvar
`14{kód pokladny}{rok}{pořadí}`, např. `14HP12600001`.

## Co modul přidává

- **Document třída** `CashRegisterDocument extends CashDeskDocumentBase`
  (docs.core) — partner nepovinný (povinný při úhradě Převodem), způsob
  úhrady Hotovost / Převodem / Kartou (`PAYMENT_METHODS_ALLOWED` přepsáno
  nad bází, která má jen Hotovost / Kartou), měna dokladu = měna pokladny,
  splatnost = datum vystavení. Pohyby řádku:
  `sale.services`, `sale.goods`, `acc.entry`.
- **Editační formulář** `CashRegisterForm extends CashDeskFormBase` —
  minimalistická hlavička: způsob úhrady, nepovinný partner, datum vystavení,
  měna jen pro čtení, readOnly sekce „Pokladna". Tab **Nastavení** za
  Přílohami (#68): režim DPH, místo plnění, registrace DPH, zaokrouhlení
  částky a DPH — pole, která se při běžném prodeji nemění.
- **Viewer** `CashRegisterViewer extends DocsHeadsViewer` — Prodej → Prodejky,
  `scopedDocType = 'cashreg'`, v řádku datum a způsob úhrady.
- **Polymorfní registrace** v `documentClasses` / `forms` (typeColumn
  `doc_type`).

## Co modul NEpřidává

- Žádné nové tabulky ani cfgItems — typ a pohyby žijí v `docs.core`,
  účtovací předpis `cashreg` v `economy.accounting` (protiúčet = účet pokladny
  přes `accountSrc: cashDesk`; převod, karta, dobírka i brána → pohledávka
  311 za plátcem `partner_balance` — protistrana terminálu / brány /
  dopravce, `partnerSrc: balance`, #72; bez plátce prodejka neprojde).
- Kontrolní hlášení: prodejka s partnerem s CZ DIČ nad limit jde do A4,
  anonymní do A5 — dělá existující `economy.vat`, tady nic.
- Tisk prodejky, platební terminály per analytika, pokladní knihu (fáze 2).

## Vztah k `docs.cashDocs`

Sesterský modul pro **Pokladní doklady** (`doc_type = 'cash'`, směr per
doklad) sdílí tutéž bázi `CashDeskDocumentBase` / `CashDeskFormBase`
v `docs.core`; moduly na sobě nezávisejí.
