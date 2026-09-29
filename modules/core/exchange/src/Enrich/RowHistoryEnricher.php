<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Enrich;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Document\CanonicalRowText;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Docs\Core\OwnCompanyResolver;

/**
 * Obohacení řádků AI-extrahovaného canonical dokumentu z historie dokladů
 * téhož partnera (deterministická vrstva 0 „AI párování položek").
 *
 * Čistá funkce canonical → canonical: pro řádky bez `item.ourCode` dohledá
 * v řádcích dřívějších dokladů stejného partnera a doc_type shodný popis
 * a propíše z něj trojici `item.ourCode` + `vat.code` + `account` (D1).
 * Doplňuje jen prázdná pole — AI extrakce ani userActions se nepřepisují
 * (D3; userActions se mergují až po enrichmentu a reconcile fáze
 * DocumentApplieru má absolutní přednost).
 *
 * Matchování (D5) zkouší kandidátní texty řádku v pořadí preference
 * `description` → `item.description` → `item.name`, tier-major — úroveň
 * matchování má přednost před pořadím kandidátů (exact zásah na `item.name`
 * vyhrává nad fuzzy zásahem na `item.description`):
 *
 *   0. exact match syrového textu               → historyExactRaw      / high
 *   1. exact match normalizovaného textu        → historyExactNorm     / high
 *   2. Jaccard token-set ≥ FUZZY_THRESHOLD      → historyFuzzy         / medium
 *   3. dominantní položka partnera (bez textu)  → historyDominantItem  / low
 *
 * Uvnitř úrovně vyhrává dřívější kandidát, uvnitř kandidáta nejnovější
 * historie. Vyhrávající kandidátní text (originální, nenormalizovaný tvar)
 * jde do auditu jako `matchedText`; jméno navržené položky jako `itemName`.
 *
 * Úroveň 3 je čistě statistický fallback pro dodavatele, jejichž texty
 * řádků se neopakují (spotřební materiál, PHM), ale položka je v historii
 * prakticky konstantní — viz findDominantItem() a
 * `tasks/enrichment-dominant-item.md`.
 *
 * Audit per řádek jde do `_resolve.rows[i].enrichment` (D6) — blok se
 * zapisuje vždy, i pro nenapárované a přeskočené řádky. Opakovaný běh je
 * autoritativní a idempotentní: vlastní dřívější návrhy (poznané podle
 * `enrichment.suggested` == aktuální hodnota) se nejdřív odvolají a matchuje
 * se znovu proti aktuální DB, takže persistnutý enrichment z `/result`
 * nezablokuje fresh běh při preview/apply (D2).
 *
 * Partner nedohledán, `selfParty` chybí nebo doklad nemá řádky → tichý skip,
 * canonical se vrátí beze změny (D4). Žádné side-efekty, žádné issues.
 */
final class RowHistoryEnricher
{
    /** Stavy dokladů považované za naučenou historii: 40 V pořádku, 80 V opravě (doklad s číslem). */
    private const HISTORY_DOC_STATES = [40, 80];

    /** Stejná trojice jako v resolverech — navrhovaná položka musí být živá. */
    private const ITEM_ACTIVE_STATES = [10, 40, 80];

    private const HISTORY_LIMIT = 200;
    private const FUZZY_THRESHOLD = 0.6;

    /** Minimální počet řádků historie pro statistiku dominance. */
    private const DOMINANCE_MIN_ROWS = 10;

    /** Minimální podíl dominantní položky na řádcích historie. */
    private const DOMINANCE_MIN_SHARE = 0.8;

    public function __construct(
        private readonly Connection $db,
        private readonly PartyResolver $partyResolver,
    ) {}

    /** Production factory — stejné složení PartyResolveru jako DocumentApplier::create(). */
    public static function create(Connection $db): self
    {
        return new self($db, new PartyResolver($db, new OwnCompanyResolver($db)));
    }

    /**
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    public function enrich(array $canonical, bool $withDominance = true): array
    {
        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        if ($rows === []) {
            return $canonical;
        }

        $partnerId = $this->resolveCounterparty($canonical);
        if ($partnerId === null) {
            return $canonical;
        }

        $canonical = $this->revertOwnSuggestions($canonical);

        $docType = DocumentApplier::mapDocTypeValue((string) ($canonical['docType'] ?? ''));
        $history = $this->loadHistory($partnerId, $docType);

        foreach ($canonical['rows'] as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $canonical = $this->enrichRow($canonical, (int) $idx, $row, $history, $withDominance);
        }

        return $canonical;
    }

    /**
     * Samostatný dominance krok (tier 3) pro řádky, které zůstaly bez
     * `item.ourCode` — používá RowEnrichmentPipeline při precedenci
     * „contentTag před dominancí" (D13): enrich(withDominance: false) →
     * contentTag vrstva → applyDominance() na zbytek. Mimo pipeline se
     * chování enrich() nemění (default $withDominance = true).
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    public function applyDominance(array $canonical): array
    {
        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        $hasUncovered = false;
        foreach ($rows as $row) {
            if (is_array($row) && self::rowExpectsItem($row)
                && trim((string) ($row['item']['ourCode'] ?? '')) === ''
            ) {
                $hasUncovered = true;
                break;
            }
        }
        if (!$hasUncovered) {
            return $canonical;
        }

        $partnerId = $this->resolveCounterparty($canonical);
        if ($partnerId === null) {
            return $canonical;
        }

        $docType = DocumentApplier::mapDocTypeValue((string) ($canonical['docType'] ?? ''));
        $history = $this->loadHistory($partnerId, $docType);

        foreach ($canonical['rows'] as $idx => $row) {
            if (!is_array($row) || !self::rowExpectsItem($row)) {
                continue;
            }
            if (trim((string) ($row['item']['ourCode'] ?? '')) !== '') {
                continue;
            }
            $match = $this->findDominantItem($history, $row);
            if ($match !== null) {
                $canonical = $this->applyMatchToRow($canonical, (int) $idx, $row, $match);
            }
        }

        return $canonical;
    }

    /**
     * Řádek, u kterého dává smysl navrhovat položku: běžný item řádek.
     * Kontační řádky (acc.record / accSide) položku nenesou — používá se
     * i pro strop statusu (D7) v AnalysisController.
     *
     * @param array<string, mixed> $row
     */
    public static function rowExpectsItem(array $row): bool
    {
        $rowKind = $row['rowKind'] ?? null;
        if ($rowKind !== null && $rowKind !== 'item') {
            return false;
        }
        if (($row['accSide'] ?? null) !== null) {
            return false;
        }
        return ($row['operation'] ?? null) !== 'acc.record';
    }

    // ── Per-row match + propsání ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>
     */
    private function enrichRow(array $canonical, int $idx, array $row, array $history, bool $withDominance = true): array
    {
        $enrichment = [
            'matchedBy'       => null,
            'confidence'      => null,
            'matchedText'     => null,
            'itemName'        => null,
            'sourceDocId'     => null,
            'sourceDocNumber' => null,
            'suggested'       => [],
        ];

        if (!self::rowExpectsItem($row)) {
            $enrichment['skipped'] = 'noItemRow';
            return $this->writeEnrichment($canonical, $idx, $enrichment);
        }
        if (trim((string) ($row['item']['ourCode'] ?? '')) !== '') {
            $enrichment['skipped'] = 'hasOurCode';
            return $this->writeEnrichment($canonical, $idx, $enrichment);
        }

        $candidates = $this->rowTextCandidates($row);
        $match = $candidates !== [] ? $this->findMatch($candidates, $history) : null;
        if ($withDominance) {
            $match ??= $this->findDominantItem($history, $row);
        }
        if ($match === null) {
            return $this->writeEnrichment($canonical, $idx, $enrichment);
        }

        return $this->applyMatchToRow($canonical, $idx, $row, $match);
    }

    /**
     * Propsání matchnuté historie do řádku + zápis audit bloku — společné
     * pro enrichRow() a samostatný applyDominance() krok.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $row
     * @param array{0: array<string, mixed>, 1: string, 2: string, 3: ?string, 4?: array<string, mixed>} $match
     * @return array<string, mixed>
     */
    private function applyMatchToRow(array $canonical, int $idx, array $row, array $match): array
    {
        [$hist, $matchedBy, $confidence, $matchedText] = $match;

        $suggested = [];
        $item = is_array($row['item'] ?? null) ? $row['item'] : [];
        $item['ourCode'] = (string) $hist['item_code'];
        $row['item'] = $item;
        $suggested['ourCode'] = (string) $hist['item_code'];

        $histVat = trim((string) ($hist['vat_code'] ?? ''));
        if ($histVat !== '' && trim((string) ($row['vat']['code'] ?? '')) === '') {
            $vat = is_array($row['vat'] ?? null) ? $row['vat'] : [];
            $vat['code'] = $histVat;
            $row['vat'] = $vat;
            $suggested['vatCode'] = $histVat;
        }

        $histAccount = trim((string) ($hist['account_number'] ?? ''));
        if ($histAccount !== '' && trim((string) ($row['account'] ?? '')) === '') {
            $row['account'] = $histAccount;
            $suggested['account'] = $histAccount;
        }

        $canonical['rows'][$idx] = $row;
        $enrichment = [
            'matchedBy'       => $matchedBy,
            'confidence'      => $confidence,
            'matchedText'     => $matchedText,
            'itemName'        => ((string) ($hist['item_name'] ?? '')) ?: null,
            'sourceDocId'     => (int) $hist['doc_head'],
            'sourceDocNumber' => ((string) ($hist['doc_number'] ?? '')) ?: null,
            'suggested'       => $suggested,
        ];
        if (isset($match[4])) {
            $enrichment['dominance'] = $match[4];
        }

        return $this->writeEnrichment($canonical, $idx, $enrichment);
    }

    /**
     * Tier-major průchod: úroveň matchování má přednost před pořadím
     * kandidátů (D1) — exactRaw přes všechny kandidáty, pak exactNorm,
     * pak fuzzy. Uvnitř úrovně vyhrává dřívější kandidát, uvnitř kandidáta
     * nejnovější historie (řazení `h.id DESC`).
     *
     * @param list<string> $candidates
     * @param list<array<string, mixed>> $history
     * @return array{0: array<string, mixed>, 1: string, 2: string, 3: string}|null
     */
    private function findMatch(array $candidates, array $history): ?array
    {
        foreach ($candidates as $text) {
            foreach ($history as $hist) {
                if (trim((string) ($hist['description'] ?? '')) === $text) {
                    return [$hist, 'historyExactRaw', 'high', $text];
                }
            }
        }

        // norm => první originální text, který na něj vede (pro matchedText)
        $norms = [];
        foreach ($candidates as $text) {
            $norm = $this->normalizeText($text);
            if ($norm !== '' && !isset($norms[$norm])) {
                $norms[$norm] = $text;
            }
        }
        if ($norms === []) {
            return null;
        }
        foreach ($norms as $norm => $text) {
            foreach ($history as $hist) {
                if ($this->normalizeText((string) ($hist['description'] ?? '')) === $norm) {
                    return [$hist, 'historyExactNorm', 'high', $text];
                }
            }
        }

        foreach ($norms as $norm => $text) {
            $tokens = $this->tokenize($norm);
            foreach ($history as $hist) {
                $histTokens = $this->tokenize($this->normalizeText((string) ($hist['description'] ?? '')));
                if ($this->jaccard($tokens, $histTokens) >= self::FUZZY_THRESHOLD) {
                    return [$hist, 'historyFuzzy', 'medium', $text];
                }
            }
        }

        return null;
    }

    /**
     * Úroveň 3 — dominantní položka partnera (D1). Bez textového signálu:
     * pokud historie má >= DOMINANCE_MIN_ROWS řádků a jedna položka pokrývá
     * >= DOMINANCE_MIN_SHARE z nich, navrhne se s confidence `low`.
     *
     * Guard přes částku (D3): návrh se potlačí, když total řádku převyšuje
     * maximum historických total_price dominantní položky — chytá majetkové
     * / investiční řádky u jinak materiálových dodavatelů. Chybějící částka
     * na řádku canonical → guard se neuplatní (navrhne se).
     *
     * Vrací stejný tvar jako findMatch() + pátý prvek s podkladem pro audit
     * klíč `dominance` (D8); matchedText je null (žádný text se nematchoval).
     * Hist řádek = nejnovější výskyt dominantní položky (řazení h.id DESC).
     *
     * @param list<array<string, mixed>> $history
     * @param array<string, mixed> $row
     * @return array{0: array<string, mixed>, 1: string, 2: string, 3: null, 4: array{share: float, rows: int}}|null
     */
    private function findDominantItem(array $history, array $row): ?array
    {
        $total = count($history);
        if ($total < self::DOMINANCE_MIN_ROWS) {
            return null;
        }

        $counts = [];
        foreach ($history as $hist) {
            $counts[(string) $hist['item_code']] = ($counts[(string) $hist['item_code']] ?? 0) + 1;
        }
        arsort($counts);
        $dominantCode = (string) array_key_first($counts);
        $share = $counts[$dominantCode] / $total;
        // Při prahu 0.8 nemůže mít podíl dvě položky zároveň — tie netřeba řešit.
        if ($share < self::DOMINANCE_MIN_SHARE) {
            return null;
        }

        $dominant = null;
        $maxTotalPrice = null;
        foreach ($history as $hist) {
            if ((string) $hist['item_code'] !== $dominantCode) {
                continue;
            }
            $dominant ??= $hist;
            $histTotal = $hist['total_price'] ?? null;
            if ($histTotal !== null && is_numeric($histTotal)
                && ($maxTotalPrice === null || (float) $histTotal > $maxTotalPrice)) {
                $maxTotalPrice = (float) $histTotal;
            }
        }

        $rowTotal = $row['totalPrice'] ?? null;
        if ($maxTotalPrice !== null && $rowTotal !== null && is_numeric($rowTotal)
            && (float) $rowTotal > $maxTotalPrice) {
            return null;
        }

        return [$dominant, 'historyDominantItem', 'low', null, [
            'share' => round($share, 2),
            'rows'  => $total,
        ]];
    }

    /**
     * Kandidátní texty řádku pro matchování, v pořadí preference. První je
     * složený text řádku (CanonicalRowText — přesně to, co applier zapíše
     * do docs_core_rows.description; bez něj by historie vzniklá po #84
     * nenapárovala exact matchem vlastní dřívější řádky), pak jednotlivé
     * zdroje: top-level description, item.description, item.name — zkouší
     * se všechny, ne jen první neprázdný. Neprázdné, trimnuté, deduplikované.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function rowTextCandidates(array $row): array
    {
        $item = is_array($row['item'] ?? null) ? $row['item'] : [];
        $candidates = [];
        foreach ([
            CanonicalRowText::compose($row),
            $row['description'] ?? null,
            $item['description'] ?? null,
            $item['name'] ?? null,
        ] as $text) {
            if (!is_string($text)) {
                continue;
            }
            $text = trim($text);
            if ($text !== '' && !in_array($text, $candidates, true)) {
                $candidates[] = $text;
            }
        }
        return $candidates;
    }

    /** Lowercase + odstranění číslic/datumů/částek/interpunkce + collapse whitespace (D5). */
    private function normalizeText(string $text): string
    {
        $t = mb_strtolower($text, 'UTF-8');
        $t = (string) preg_replace('/[^\p{L}\s]+/u', ' ', $t);
        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    /** @return list<string> Unikátní tokeny. */
    private function tokenize(string $norm): array
    {
        return $norm === '' ? [] : array_values(array_unique(explode(' ', $norm)));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));
        return $union === 0 ? 0.0 : $intersection / $union;
    }

    // ── Counterparty + historie ─────────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     */
    private function resolveCounterparty(array $canonical): ?int
    {
        $counterKey = match ($canonical['selfParty'] ?? null) {
            'customer' => 'supplier',
            'supplier' => 'customer',
            default    => null,
        };
        if ($counterKey === null) {
            return null;
        }
        $party = is_array($canonical[$counterKey] ?? null) ? $canonical[$counterKey] : [];
        if ($party === []) {
            return null;
        }
        $result = $this->partyResolver->resolve($party);
        return $result->status === ResolveStatus::Matched ? $result->matchedId : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadHistory(int $partnerId, string $docType): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [r.description], [r.vat_code], [r.total_price], [h.id] AS [doc_head],
                    [h.doc_number] AS [doc_number],
                    [i.code] AS [item_code], [i.name] AS [item_name],
                    [a.number] AS [account_number]
             FROM [docs_core_rows] AS [r]
             JOIN [docs_core_heads] AS [h] ON [h.id] = [r.doc_head]
             JOIN [economy_items] AS [i]
                  ON [i.id] = [r.item] AND [i.docState] IN (%i, %i, %i)
             LEFT JOIN [economy_accounting_accounts] AS [a] ON [a.id] = [r.account]
             WHERE [h.partner] = %i AND [h.doc_type] = %s
               AND [h.docState] IN (%i, %i)
             ORDER BY [h.id] DESC
             LIMIT %i',
            self::ITEM_ACTIVE_STATES[0], self::ITEM_ACTIVE_STATES[1], self::ITEM_ACTIVE_STATES[2],
            $partnerId, $docType,
            self::HISTORY_DOC_STATES[0], self::HISTORY_DOC_STATES[1],
            self::HISTORY_LIMIT,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = $row instanceof \Dibi\Row ? $row->toArray() : (array) $row;
        }
        return $out;
    }

    // ── Idempotence + audit blok ────────────────────────────────────────────

    /**
     * Odvolá návrhy z předchozího běhu: hodnota, kterou tehdy doplnil
     * enrichment (`enrichment.suggested`) a která od té doby nebyla změněna,
     * se vyprázdní a řádek jde do matchování znovu.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private function revertOwnSuggestions(array $canonical): array
    {
        $resolveRows = $canonical['_resolve']['rows'] ?? null;
        if (!is_array($resolveRows)) {
            return $canonical;
        }
        foreach ($resolveRows as $entry) {
            if (!is_array($entry) || !is_array($entry['enrichment'] ?? null)) {
                continue;
            }
            $idx = $entry['index'] ?? null;
            if (!is_int($idx) || !is_array($canonical['rows'][$idx] ?? null)) {
                continue;
            }
            $suggested = is_array($entry['enrichment']['suggested'] ?? null)
                ? $entry['enrichment']['suggested']
                : [];
            $row = $canonical['rows'][$idx];
            if (isset($suggested['ourCode']) && ($row['item']['ourCode'] ?? null) === $suggested['ourCode']) {
                $row['item']['ourCode'] = null;
            }
            if (isset($suggested['vatCode']) && ($row['vat']['code'] ?? null) === $suggested['vatCode']) {
                $row['vat']['code'] = null;
            }
            if (isset($suggested['account']) && ($row['account'] ?? null) === $suggested['account']) {
                $row['account'] = null;
            }
            $canonical['rows'][$idx] = $row;
        }
        return $canonical;
    }

    /**
     * Zapíše audit blok do `_resolve.rows[i].enrichment` — merge do existující
     * entry podle `index` (konvence resolveAll), jinak novou entry přidá.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $enrichment
     * @return array<string, mixed>
     */
    private function writeEnrichment(array $canonical, int $idx, array $enrichment): array
    {
        $resolveRows = is_array($canonical['_resolve']['rows'] ?? null)
            ? $canonical['_resolve']['rows']
            : [];
        foreach ($resolveRows as $pos => $entry) {
            if (is_array($entry) && ($entry['index'] ?? null) === $idx) {
                $resolveRows[$pos]['enrichment'] = $enrichment;
                $canonical['_resolve']['rows'] = $resolveRows;
                return $canonical;
            }
        }
        $resolveRows[] = ['index' => $idx, 'enrichment' => $enrichment];
        $canonical['_resolve']['rows'] = $resolveRows;
        return $canonical;
    }
}
