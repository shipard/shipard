# Došlá zpráva v náhledu dokladu a na detailu dokladu

**Stav:** hotovo — implementováno 2026-10-05 (D1–D6, D8–D10; D7 nahrazeno D10)

## Cíl

Z review modalu (Náhled dokladu — faktury i spisovna) není vidět, ze které
došlé zprávy návrh vznikl, a nejde se na ni podívat. Když přijde víc pošty
od jednoho dodavatele, uživatel neví, kterou zprávu v Došlé poště hledat —
a podle kódu zprávy hledat nejde, fulltext Došlé pošty `message_id`
neprochází.

Task přidá do hlavičky review modalu řádek se zdrojovou zprávou (kód, datum
přijetí, odesílatel). Klik otevře read-only detail zprávy nad review
modalem. Stejný modal otevře i odkaz na zdrojovou zprávu na detailu dokladu
(tab Přehled, pod rekapitulací DPH), který dnes přepíná do vieweru Došlá
pošta. Kód se zobrazuje zkráceně, plný je v tooltipu, a jde podle něj
hledat.

## Před implementací přečti

- `docs/dashboard.md` — review modal, akce `open_detail`, `ViewerDetailModal`
- `docs/mail/api-contract.md` §9.12 — `GET /_mail/messages/{ndx}/preview`
- `docs/frontend.md` — akce `open_detail` přes `onAction` (buňka-odkaz
  v detailu, hostitel `Viewer.svelte`)
- `docs/help-authoring.md` — před úpravou `help/`
- kód:
  - `src/Api/Controller/AnalysisController.php` — `previewMessage()`
  - `modules/docs/core/src/DocsHeadsViewer.php` — `sourceMessages()`,
    `sourceAttachmentGroups()`
  - `modules/core/mail/src/IncomingMessagesViewer.php` — `selectRows()`
    (fulltext), `formatDateTime()`
  - `modules/core/mail/src/IncomingMessageDocument.php` — `generateMessageId()`
  - `frontend/src/components/exchange/DocumentExchangePreviewModal.svelte`
  - `frontend/src/components/viewer/DocumentDetail.svelte`,
    `ViewerDetail.svelte`, `Viewer.svelte` (obsluha `open_detail`)
  - `frontend/src/components/dashboard/Dashboard.svelte`
  - `frontend/src/components/ui/Modal.svelte` — `subtitle`, `headerExtra`, stack

## Jak to je dnes

- Kód zprávy (`core_mail_incoming_messages.message_id`, varchar 30) má tvar
  `MSG-YYYYMMDD-NNNN`; `NNNN` je pořadí zprávy v rámci dne přijetí
  (`IncomingMessageDocument::generateMessageId()`). Seed používá
  `TEST-MSG-NNNN`, export (`MailExporter`) fallback `MSG-{id}`.
- `previewMessage()` vrací `messageNdx`, `analysisNdx`, přílohy, canonical
  a `userActions` — žádná metadata zprávy.
- `DocumentExchangePreviewModal` má titulek „Náhled dokladu“, ve frontovém
  režimu `headerExtra` s pozicí ve frontě. Hostitelé: `Dashboard.svelte`
  a `ViewerDetail.svelte` (detail zprávy v Došlé poště).
- `DocsHeadsViewer::sourceAttachmentGroups()` posílá skupiny
  `{kind: 'mail', sourceViewerId, message_id, received_at, message_ndx,
  attachments}`. `DocumentDetail.svelte` je vykreslí pod rekapitulací DPH
  s nadpisem `#{message_id}`; klik volá `navigationStore.navigateToViewer()`.
- `ViewerDetail.svelte` má větev `content.type === 'attachments'` se stejným
  odkazem `#{message_id}`. Žádný backend tento typ bloku podle grepu
  negeneruje.
- Fulltext Došlé pošty prochází `subject`, `ai_title`, `sender_email`,
  `sender_name`, `partner_name`, `full_name` partnera a `body_plain` —
  `message_id` ne.
- Read-only detail záznamu v modalu (`ViewerDetailModal`) už hostí
  `Dashboard.svelte` (akce `openMail` karet, tab `content`) a `Viewer.svelte`
  (akce `open_detail` z detailu, vzor odkazů na doklady v kartě majetku).

## Rozhodnutí

- ✓ **D1** — Zdrojová zpráva v řádku `subtitle` hlavičky review modalu:
  *Došlá zpráva #260905-0012 · 5. 9. 2026 14:32 · odesílatel*. Platí pro
  doklady i spisovnu (stejný modal).
- ✓ **D2** — Kód je odkaz: klik otevře read-only detail zprávy
  (`ViewerDetailModal`, viewer `core.mail.incoming`, tab `content`) nad
  review modalem. Po zavření je uživatel zpět v review se všemi
  rozhodnutími. Když je review otevřené z detailu té samé zprávy v Došlé
  poště, řádek je jen text.
- ✓ **D3** — Preview endpoint vrací blok `message` s metadaty zprávy; datum
  formátuje server.
- ✓ **D4** — Fulltext Došlé pošty prochází i `message_id`.
- ✓ **D5** — Uložený formát kódu se nemění. Zobrazuje se zkráceně, plný kód
  v tooltipu.
- ✓ **D6** — Na detailu dokladu (tab Přehled) klik na kód zdrojové zprávy
  otevře modal zprávy jako v D2, místo přechodu do vieweru Došlá pošta.
  HeaderInfo dokladu se nemění.
- ~~**D7**~~ — globální store a hostitel modalu v `AppShell`; **nahrazeno D10**.
- ✓ **D8** — Krátký tvar `YYMMDD-NNNN` (`MSG-20260905-0012` → `260905-0012`).
  Je jednoznačný napříč roky a je podřetězcem plného kódu, takže ho najde
  fulltext (D4). Počítá ho server jedním helperem; kód, který vzoru
  neodpovídá, vrací beze změny.
- ✓ **D9** — Krátký tvar ukazuje subtitle review modalu a nadpis skupiny
  zdrojové zprávy na detailu dokladu. Plný kód zůstává v Technických údajích
  detailu zprávy a v `displayPattern` tabulky. Mrtvou větev `attachments`
  ve `ViewerDetail.svelte` ověřit a odstranit.
- ✓ **D10** (nahrazuje D7) — Žádný nový globální hostitel
  ani store. Využít existující cesty: detail dokladu emituje akci
  `open_detail` přes `onAction` (obsluhu i `ViewerDetailModal` už má
  `Viewer.svelte`); review modal dostane callback `onOpenMessage`
  a `Dashboard.svelte` otevře svou existující instanci `ViewerDetailModal`.
  Hostitel bez `onAction` (`DocumentDetail` uvnitř read-only
  `ViewerDetailModal`) zachová dnešní `navigateToViewer`. Důvod: D7 vznikl
  v domnění, že `DocumentDetail` musí modal importovat sám (kruhový import);
  vzor `onAction` → `open_detail` je v kódu zavedený a nepřidává paralelní
  mechanismus.

## Kontrakty

### Helper `Shipard\Core\Mail\IncomingMessageCode`

Soubor `src/Core/Mail/IncomingMessageCode.php`. V `src/Core`, ne v modulu:
`docs.core` na `core.mail` nezávisí a helper používají oba.

```php
final class IncomingMessageCode
{
    /** `MSG-20260905-0012` → `260905-0012`; jiný tvar beze změny (D8). */
    public static function short(string $code): string
    {
        return preg_match('/^MSG-\d{2}(\d{6}-\d{4,})$/', $code, $m) === 1
            ? $m[1]
            : $code;
    }
}
```

`\d{4,}`: sekvence se doplňuje na 4 číslice, nad 9 999 zpráv za den by byla
delší.

### Preview — blok `message` (D3)

Patří do `$base` v `previewMessage()`, takže je ve všech větvích odpovědi
(ai_failed, registry, bez applieru, docs):

```json
"message": {
  "ndx": 123,
  "code": "MSG-20260905-0012",
  "codeShort": "260905-0012",
  "receivedAt": "5. 9. 2026 14:32",
  "sender": "Odesílatel"
}
```

- `receivedAt` ve formátu `j. n. Y H:i` (stejně jako
  `IncomingMessagesViewer::formatDateTime()`), `null` když chybí.
- `sender` = `sender_name`, jinak `sender_email`, jinak `null`.
- Prázdný `message_id` → `code` i `codeShort` prázdný řetězec; frontend pak
  kód nevykreslí, zbytek řádku ano.

### Skupina zdrojové zprávy v detailu dokladu (D9)

`sourceAttachmentGroups()` přidá klíč `message_code_short`
(`IncomingMessageCode::short()`). Stávající klíče beze změny.

### Fulltext Došlé pošty (D4)

Do seznamu sloupců `SearchCondition::anyContains()` v
`IncomingMessagesViewer::selectRows()` přidat `m.\`message_id\``; upravit
komentář nad voláním.

## Co je potřeba udělat

### 1. Backend

- Helper `IncomingMessageCode` + `tests/Unit/Core/Mail/IncomingMessageCodeTest.php`:
  plný tvar → krátký, pětimístná sekvence, `TEST-MSG-0001`, `MSG-17`
  (fallback exportu), prázdný řetězec.
- `previewMessage()` — blok `message`. Test v
  `AnalysisControllerPreviewMessageTest`: blok ve větvi docs i ai_failed,
  formát data, `sender` z `sender_name` / fallback na `sender_email` / `null`.
- `DocsHeadsViewer::sourceAttachmentGroups()` — `message_code_short`.
  Aserce v `DocsHeadsViewerDetailTest`: stávající fixture `MSG-A` vzoru
  neodpovídá → `message_code_short` = `MSG-A`; přidat případ s plným tvarem.
- `IncomingMessagesViewer` — `message_id` ve fulltextu + test v
  `IncomingMessagesViewerTest` podle vzoru, který soubor používá pro SQL
  a parametry.
- `docs/mail/api-contract.md` §9.12 — blok `message`.

### 2. Frontend

**`DocumentExchangePreviewModal.svelte`**

- Nový prop `onOpenMessage = null` (`(messageNdx) => void`). Když je `null`,
  kód je prostý text (D2).
- Snippet `subtitle`: popisek + `#{codeShort}` (s callbackem
  `<button type="button">`, jinak `<span>`; obojí s `title={code}`)
  + ` · {receivedAt}` + ` · {sender}`. Prázdné části vynechat. Nový i18n
  klíč `exchange.preview.sourceMessage` (cs „Došlá zpráva“, en „Incoming
  message“).
- Snippet předávat po celou dobu `open`, obsah vykreslit jen s
  `data?.message`. Jinak hlavička ve frontovém režimu poskakuje o řádek při
  načítání další zprávy.

**`Dashboard.svelte`** — předat review modalu `onOpenMessage`, který nastaví
existující `detailModal` na `{open: true, viewerId: 'core.mail.incoming',
recordId: ndx, tabId: 'content'}` — stejný cíl jako akce `openMail` karet.

**`ViewerDetail.svelte`** — review modal bez `onOpenMessage` (zpráva je
právě otevřený záznam).

**`DocumentDetail.svelte`**

- `openSourceMessage(group)`: s `onAction` →
  `onAction('openSourceMessage', {kind: 'open_detail', target: {viewerId:
  group.sourceViewerId, recordId: group.message_ndx, tabId: 'content'}})`;
  bez `onAction` → dnešní `navigateToViewer` (D10).
- Nadpis skupiny: `#{group.message_code_short ?? group.message_id}`
  s `title={group.message_id}`.

**`ViewerDetail.svelte` — větev `attachments`**: grep backendu
(`--include=*.php`) na blok typu `attachments`. Když ho nic negeneruje,
větev i její CSS odstranit. Když ano, upravit stejně jako `DocumentDetail`
a zmínit v commitu.

Po změnách: `cd frontend && npm run check:i18n && npm run build`.

### 3. Dokumentace

- `help/posta/kontrola-vytezeni.md`, krok 2 („Zorientuj se v náhledu“) —
  v hlavičce náhledu je řádek se zprávou, ze které návrh vznikl; klik ukáže
  e-mail, zavřením se vrátíš do náhledu.
- `help/posta/prijem-posty.md` — věta o hledání („podle předmětu, titulku
  i dodavatele“) doplnit o kód zprávy.
- Grep `help/` na popis odkazu na zdrojovou zprávu u dokladu; kde říká, že
  přepne do Došlé pošty, opravit na otevření zprávy.
- Popisky ověřit ve zdroji (`frontend/src/i18n/cs.js`), pak
  `python3 scripts/help-index.py`.
- `docs/dashboard.md` — review modal: subtitle se zdrojovou zprávou,
  callback `onOpenMessage`.

## Commit strategie

1. `feat(mail): kód a metadata zdrojové zprávy v náhledu dokladu, hledání podle kódu`
   — helper, preview, `DocsHeadsViewer`, fulltext, testy, `api-contract.md`
2. `feat(exchange): zdrojová zpráva v hlavičce náhledu a modal zprávy z detailu dokladu`
   — frontend, i18n, odstranění mrtvé větve
3. `docs(mail): zdrojová zpráva v náhledu a detailu dokladu` — `help/`,
   `docs/dashboard.md`, hlavička tasku, `tasks/README.md`,
   `python3 scripts/tasks-index.py`

## Pasti

- **Závislost modulů.** `docs.core` nesmí importovat třídu z `core.mail` —
  proto helper v `src/Core/Mail`.
- **Všechny větve preview.** Blok `message` patří do `$base`; registry
  větev (spisovna) i ai_failed ho musí mít.
- **Vrstvení modalů.** `ViewerDetailModal` v `Dashboard.svelte` je v DOM za
  review modalem, takže leží nad ním (oba `z-index: 1000`), a stack
  v `Modal.svelte` pošle Esc jen hornímu. Ověřit ručně: Esc zavře jen
  detail zprávy, review zůstane otevřené s neuloženými rozhodnutími.
- **Queue badge se přesune.** Se `subtitle` vykreslí `Modal` `headerExtra`
  na začátek subtitle řádku. Ověřit vizuálně ve frontovém režimu.
- **Akční id v `DocumentDetail`.** `openSourceMessage` nesmí kolidovat se
  sdíleným slovníkem vestavěných akcí ve `Viewer.svelte` — musí propadnout
  na obsluhu podle `kind` (vzor `open_record` v témže souboru).
- **Žádný nový mechanismus.** Nezakládat globální store ani hostitele
  modalu (D10).
- **JS uvozovky.** V kódu jen ASCII uvozovky; `·` a české texty patří do
  `cs.js`.
- **Reálná data.** Proklik dělat na dev DS s režimem `volný`
  (`CLAUDE.local.md`); nic neměnit na zdrojích s režimem `reálná kopie`.

## Hotovo když

- [x] review modal (faktura i spisovna) ukazuje řádek Došlá zpráva
      s krátkým kódem, datem a odesílatelem; tooltip nese plný kód
- [x] z Dashboardu klik na kód otevře detail zprávy nad review; zavření
      (× i Esc) vrátí do review a rozhodnutí zůstanou; funguje i ve
      frontovém režimu po přeskočení na další zprávu
- [x] review otevřené z detailu zprávy v Došlé poště ukazuje kód jako text
- [x] detail dokladu (tab Přehled) ve vieweru ukazuje krátký kód
      s tooltipem; klik otevře modal zprávy a viewer se nepřepne
- [x] detail dokladu otevřený v read-only modalu se při kliku chová jako dnes
- [x] hledání krátkého i plného kódu v Došlé poště najde zprávu
- [x] mrtvá větev `attachments` ve `ViewerDetail.svelte` ověřená
      a odstraněná (žádný backend blok `type: attachments` negeneruje;
      s ní odešel i nepoužitý import `navigationStore` a její CSS)
- [x] cílené PHPUnit testy, `npm run check:i18n`, `npm run build`,
      `help-index.py` prošly
- [x] hlavička tasku a `tasks/README.md` aktualizované

Ruční proklik (vrstvení modalů, badge fronty, přeskočení ve frontě) dělá
člověk na dev DS s režimem `volný` před commitem.
