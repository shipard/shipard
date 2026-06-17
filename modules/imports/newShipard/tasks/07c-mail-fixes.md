# Task: Opravy importu pošty (Fáze 07c)

## Kontext

Tři opravy v importu došlé pošty (Fáze 07b) po prvním reálném běhu. **Všechny
se týkají jen `libs/runners/MailRunner.php`** — endpoint `POST /_mail/import`
(`nov_shipard`) už `body_html` i `docState` přijímá a aplikuje správně, stavová
mapa nového Shipardu se nemění.

1. **Stav dokumentu se nepřenáší.** `docState` se dnes odvozuje od navázání na
   doklad (`target_row === null ? 10 : 40`) — to byl chybný návrh. Většina pošty
   doklad nemá → spadne na 10 (Nová), takže v cíli je „všechno Nová", ačkoliv ve
   starém Shipardu je většina „Vyřešeno". `docState` se má mapovat z **původního
   stavu zprávy** (`wkf.issues.docStates.default`), nezávisle na navázání.

2. **HTML těla.** Staré `issues` nemají formátový flag — `body` u e-mailů bývá HTML.
   Starý systém formát autodetekuje až při renderu (`ContentRenderer::createCodeText`
   subtype `'auto'`). Dnes importer cpe vše do `body_plain`, takže HTML se zobrazí
   jako escapovaný text. Stejnou heuristiku pustit při importu a HTML poslat do
   `body_html`.

3. **Druhý mechanismus vazby na doklad.** Vazba zpráva↔doklad je ve starém Shipardu
   dvěma způsoby: (a) `e10_base_doclinks` (`e10docs-inbox`) — používáme; (b)
   `issues.tableNdx` + `recNdx` — **nepoužíváme**. Doklady navázané jen přes (b)
   přijdou o vazbu. Doplnit (b) jako druhý zdroj a sloučit.

**Existující data:** opravy mění chování budoucích běhů; už naimportované zprávy
re-run přeskočí (`ENTITY_MESSAGE`). Na testovacích datech se dělá `ds-reset` +
re-import, takže backfill není potřeba.

## Před implementací přečti

- **`modules/imports/newShipard/libs/runners/MailRunner.php`** — `processIssue()`
  (řádek `docState`, `body_plain`, volání `resolveDocLink`), `resolveDocLink()`,
  `primaryTypeFor()`, `fetchIssues()` (issueType=1 už vrací celý řádek `i.*`, takže
  `tableNdx`/`recNdx` jsou k dispozici).
- **`old_shipard:modules/wkf/core/config/wkf.issues.docStates.default.json`** —
  staré stavy: 1000 Nově rozpracováno, 1001 Nová zpráva, 1200 K řešení, 4000
  Vyřešeno, 8000 V opravě, 9000 Ukončeno, 9800 Smazáno (filtrováno).
- **`nov_shipard:modules/core/mail/config/docStatesIncoming.jsonc`** — cílové stavy:
  10 Nová, 40 Zpracovaná, 80 Archiv (endpoint dopočítá `docStateMain`).
- **`old_shipard:src/UI/Core/ContentRenderer.php`** (`createCodeText`, ~ř. 573) —
  původní `'auto'` heuristika, kterou kopírujeme 1:1.
- **`old_shipard:modules/e10doc/core/tables/heads.json`** — `ndx: 1078` (tabulka
  dokladů, hodnota pro `tableNdx`).

## Co implementovat

### Oprava 1 — `docState` z původního stavu

Konstanta + mapování ze starého `issues.docState`:

```php
/**
 * Starý issues.docState (wkf.issues.docStates.default) → nový docState
 * (core.mail.docStatesIncoming). Nezávisle na navázání na doklad.
 *   1000 Nově rozpracováno → 10 Nová
 *   1001 Nová zpráva       → 10 Nová
 *   1200 K řešení          → 10 Nová
 *   4000 Vyřešeno          → 40 Zpracovaná
 *   8000 V opravě          → 10 Nová
 *   9000 Ukončeno          → 80 Archiv
 *   9800 Smazáno           → (filtrováno ve fetchIssues)
 * Neznámý stav → 10 (Nová).
 */
private const ISSUE_STATE_MAP = [
    1000 => 10, 1001 => 10, 1200 => 10, 4000 => 40, 8000 => 10, 9000 => 80,
];

private function mapDocState(int $oldState): int
{
    return self::ISSUE_STATE_MAP[$oldState] ?? 10;
}
```

V `processIssue` nahradit:

```php
// PŮVODNĚ:  'docState' => $targetRow === null ? 10 : 40,
'docState' => $this->mapDocState((int) ($issue['docState'] ?? 0)),
```

`docStateMain` neřešíme — dopočítá endpoint (`resolveIncomingMainState`).

### Oprava 2 — HTML heuristika těla

Helper (mirror staré `'auto'` detekce):

```php
/**
 * Formát těla — mirror ContentRenderer::createCodeText('auto'):
 * obsahuje-li <html | <span | <div | <p → HTML, jinak plain.
 *
 * @return array{0: ?string, 1: ?string}  [body_plain, body_html]
 */
private function splitBody(?string $body): array
{
    if ($body === null)
        return [null, null];
    $isHtml = str_contains($body, '<html')
        || str_contains($body, '<span')
        || str_contains($body, '<div')
        || str_contains($body, '<p');
    return $isHtml ? [null, $body] : [$body, null];   // HTML → body_plain zůstává NULL
}
```

V `processIssue` před sestavením payloadu:

```php
[$bodyPlain, $bodyHtml] = $this->splitBody(
    $this->emptyToNull($issue['body'] ?? null) ?? $this->emptyToNull($issue['text'] ?? null)
);
```

a v payloadu nahradit `body_plain` + přidat `body_html`:

```php
// PŮVODNĚ:  'body_plain' => $this->emptyToNull(...) ?? $this->emptyToNull(...),
'body_plain' => $bodyPlain,
'body_html'  => $bodyHtml,
```

**ROZHODNUTO:** u HTML zpráv `body_plain = NULL` (žádný `strip_tags` fallback).

### Oprava 3 — druhý mechanismus vazby (`tableNdx`/`recNdx`)

Konstanta + sloučení obou zdrojů v `resolveDocLink`. Metoda nově bere i řádek
zprávy (kvůli `tableNdx`/`recNdx`):

```php
/** e10doc.core.heads — ndx tabulky dokladů (statický, viz heads.json). */
private const DOCS_HEADS_TABLE_NDX = 1078;

/**
 * Vazba zpráva → doklad ze dvou zdrojů (sloučeno, dedup):
 *   1) e10_base_doclinks (e10docs-inbox; doklad=src, zpráva=dst) — primární
 *   2) issues.tableNdx == 1078 && recNdx > 0 — obecný ukazatel na doklad
 * Vrací první kandidát, který je naimportovaný (ENTITY_DOC). Doclink má přednost.
 *
 * @return array{0: ?string, 1: ?int, 2: ?int}  [target_table_id, target_row, pickedOldDocNdx]
 */
private function resolveDocLink(int $issueNdx, array $issue): array
{
    $candidates = [];

    // 1) doclinks e10docs-inbox
    foreach ($this->db()->query(
        'SELECT [srcRecId] FROM [e10_base_doclinks]'
        . ' WHERE [linkId] = %s', 'e10docs-inbox',
        ' AND [dstTableId] = %s', 'wkf.core.issues',
        ' AND [dstRecId] = %i', $issueNdx,
        ' AND [srcTableId] = %s', 'e10doc.core.heads',
        ' ORDER BY [ndx]',
    )->fetchAll() as $r)
        $candidates[] = (int) ((array) $r)['srcRecId'];

    // 2) tableNdx/recNdx → doklad (heads, ndx 1078)
    if ((int) ($issue['tableNdx'] ?? 0) === self::DOCS_HEADS_TABLE_NDX
        && (int) ($issue['recNdx'] ?? 0) > 0)
        $candidates[] = (int) $issue['recNdx'];

    $candidates = array_values(array_unique($candidates));
    if ($candidates === [])
        return [null, null, null];
    if (count($candidates) > 1)
        $this->warn("[mail] issue {$issueNdx}: " . count($candidates)
            . " linked doc candidates, picking first resolvable");

    foreach ($candidates as $oldDocNdx) {
        $newId = $this->idMap()->lookup(LocalIdMap::ENTITY_DOC, $oldDocNdx);
        if ($newId !== null)
            return ['docs_core_heads', $newId, $oldDocNdx];
    }
    // kandidát existuje, ale mimo importovaný rozsah → unlinked (známe starý ndx)
    return [null, null, $candidates[0]];
}
```

Upravit volání v `processIssue`:

```php
// PŮVODNĚ:  [$targetTableId, $targetRow, $linkedDocOldNdx] = $this->resolveDocLink($oldNdx);
[$targetTableId, $targetRow, $linkedDocOldNdx] = $this->resolveDocLink($oldNdx, $issue);
```

`--require-linked-doc` i `primaryTypeFor($linkedDocOldNdx)` fungují beze změny.

## Hotovo když

1. **`docState`** importované zprávy odpovídá mapě z původního `issues.docState`
   (Vyřešeno → Zpracovaná, otevřené → Nová, Ukončeno → Archiv), **nezávisle** na
   navázání na doklad.
2. **HTML tělo** (heuristika `<html`/`<span`/`<div`/`<p`) jde do `body_html`,
   plain do `body_plain`; u HTML je `body_plain = NULL`.
3. **Vazba na doklad** se resolvuje z `doclinks` **i** z `tableNdx==1078`/`recNdx`,
   sloučeně a dedup; doclink má přednost; více kandidátů → first resolvable + warning.
4. Žádné jiné chování (přílohy, schránky, sender, idempotence) se nemění.
5. **Smoke test** (po `ds-reset` + re-import okna):
   - Většina pošty je „Zpracovaná" (ne „Nová"); ukončené v Archivu.
   - HTML zpráva se v detailu renderuje jako HTML, ne jako escapovaný text.
   - Doklad navázaný jen přes `tableNdx`/`recNdx` (bez doclinku) má v novém Shipardu
     navázanou zprávu (a po `nov_shipard:docs-source-mail-attachments` i přílohy v
     detailu dokladu).
   - `linked`/`unlinked` statistika dává smysl (víc `linked` než před opravou).

## Doporučené pořadí implementace

1. Oprava 1 (state map) — nejmenší, hned ověřitelná po re-importu.
2. Oprava 2 (splitBody) — izolovaný helper.
3. Oprava 3 (resolveDocLink merge + signatura + call site).
4. Smoke test na okno dat, kontrola statistik.

## Otevřené body

- **Hardcode `DOCS_HEADS_TABLE_NDX = 1078`** je v pořádku (statický `ndx`, legacy
  kód takové hodnoty taky používá). Pokud by se na cílovém DS lišil, Claude Code
  ověří v `e10doc/core/tables/heads.json`.
- **Neshoda kandidátů** (doclink → docA, tableNdx → docB, různé) je nepravděpodobná;
  přednost má doclink + warning. Pokud by se v logu objevovala často, eskaluj —
  může to značit datovou nekonzistenci ve starém DS.
