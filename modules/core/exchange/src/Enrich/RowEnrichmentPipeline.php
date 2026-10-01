<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Enrich;

use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\AnthropicLlmClient;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\Items\AccountingItemsOffer;

/**
 * Orchestrátor obohacení řádků AI-extrahovaného canonicalu — Vrstva 0
 * (historie partnera, {@see RowHistoryEnricher}) + Vrstva 2 (obsahová
 * eskalace, tasks/content-tag-enrichment.md).
 *
 * Dvě vstupní cesty:
 *
 *  - {@see enrichAtResult()} — plný běh při /result (D16/D17): Vrstva 0,
 *    a když zbývají nepokryté item řádky, pravidlo IČO → štítek (persist
 *    `_resolve.contentTag {tagSource:'rule'}`), jinak LLM klasifikace
 *    (persist `{tagSource:'llm'}`); LLM selhání nikdy nepropadne výš.
 *  - {@see enrichFresh()} — preview/apply bez LLM: Vrstva 0 fresh, fresh
 *    re-check pravidla (deterministika bije persistnutý LLM odhad, D16),
 *    resolution štítek → položka běží fresh při každém čtení.
 *
 * Precedence vs. dominance (D13): setting `exchange.contentTag.beforeDominance`
 * (default true) → dominance (tier 3 Vrstvy 0) se uplatní až na řádky,
 * které contentTag nepokryl; false → stávající pořadí (dominance uvnitř
 * Vrstvy 0), contentTag jen na zbytek.
 *
 * ContentTag návrhy mají vždy strop pásma review (D14) —
 * {@see \Shipard\Module\Core\Mail\AnalysisConfidenceResolver}.
 */
final class RowEnrichmentPipeline
{
    public function __construct(
        private readonly RowHistoryEnricher $enricher,
        private readonly ContentTagResolver $resolver,
        private readonly ?ContentTagClassifier $classifier = null,
        private readonly bool $contentTagBeforeDominance = true,
    ) {}

    /**
     * Production factory — čte settings `exchange.contentTag.beforeDominance`
     * (D13) a `exchange.contentTag.backend` (D18, ndx core_ai_backends,
     * null = default backend). Classifier je null-safe: bez backendu/klíče
     * degraduje na čistě deterministickou část.
     */
    public static function create(
        DataSourceConnection $db,
        ?ConfigRuntime $configRuntime = null,
        ?DataSourceConfig $dsConfig = null,
    ): self {
        $dibi = $db->getDibiConnection();
        $settings = new SettingsStore($db);

        $beforeDominance = $settings->get('exchange.contentTag.beforeDominance');
        $backendSetting  = $settings->get('exchange.contentTag.backend');
        $backendNdx = is_numeric($backendSetting) && (int) $backendSetting > 0
            ? (int) $backendSetting
            : null;

        return new self(
            RowHistoryEnricher::create($dibi),
            new ContentTagResolver(
                $dibi,
                new AccountingItemsOffer($db),
                $configRuntime,
            ),
            new ContentTagClassifier(
                new AnthropicLlmClient(),
                new AiBackendResolver($db, $dsConfig),
                $configRuntime,
                $backendNdx,
            ),
            $beforeDominance === null ? true : (bool) $beforeDominance,
        );
    }

    /**
     * Štítek dokumentu z `_resolve.contentTag.tag` obohaceného canonicalu —
     * denormalizace do sloupce `core_mail_message_analyses.content_tag`
     * (karta Nová kategorie, learning handler). Sdílí `/result`
     * (AnalysisController) a ISDOC import (#81). Null = bez štítku;
     * validitu dokumentu si hlídá volající.
     *
     * @param array<string, mixed> $canonical
     */
    public static function contentTagOf(array $canonical): ?string
    {
        $tag = $canonical['_resolve']['contentTag']['tag'] ?? null;
        return is_string($tag) && $tag !== '' ? $tag : null;
    }

    /**
     * Štítek konkrétního řádku z bloku `_resolve.contentTag`: výjimka
     * `rowExceptions[]` pro index řádku (`{rowIndex, tag}`, index
     * v `canonical.rows` — stejně jako {@see applyTagToRows()}), jinak
     * štítek dokumentu. Null = bez bloku. Čte ho fallback druhu plnění
     * v DocumentApplier (tasks/exchange-received-supply-kind.md D2) —
     * parsování výjimek žije jen tady.
     *
     * @param array<string, mixed> $canonical
     */
    public static function rowContentTagOf(array $canonical, int $rowIndex): ?string
    {
        $block = $canonical['_resolve']['contentTag'] ?? null;
        if (!is_array($block)) {
            return null;
        }
        foreach ((array) ($block['rowExceptions'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['rowIndex'], $entry['tag'])
                && is_numeric($entry['rowIndex']) && (int) $entry['rowIndex'] === $rowIndex
                && is_string($entry['tag']) && $entry['tag'] !== ''
            ) {
                return $entry['tag'];
            }
        }
        return self::contentTagOf($canonical);
    }

    /**
     * Plný běh při /result — jediné místo, kde smí běžet LLM (D16)
     * a kde se inkrementují statistiky pravidla. Volá ho AnalysisController
     * i IsdocImportService (v runneru předzpracování, #81).
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    public function enrichAtResult(array $canonical): array
    {
        return $this->run($canonical, allowLlm: true, markHits: true);
    }

    /**
     * Fresh běh při preview/apply — bez LLM; persistnutý LLM štítek se
     * použije, pokud ho nepřebije fresh zásah pravidla (D16).
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    public function enrichFresh(array $canonical): array
    {
        return $this->run($canonical, allowLlm: false, markHits: false);
    }

    /**
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private function run(array $canonical, bool $allowLlm, bool $markHits): array
    {
        // Idempotence vlastních návrhů: Vrstva 0 odvolává jen při shodě
        // partnera — contentTag návrhy se musí odvolat i bez ní (pravidlo
        // jede z IČO canonicalu, ne z FK partnera).
        $canonical = $this->revertContentTagSuggestions($canonical);

        $canonical = $this->enricher->enrich($canonical, withDominance: !$this->contentTagBeforeDominance);

        if ($this->hasUncoveredItemRow($canonical)) {
            $canonical = $this->resolveDocumentTag($canonical, $allowLlm, $markHits);

            $block = $canonical['_resolve']['contentTag'] ?? null;
            if (is_array($block) && is_string($block['tag'] ?? null) && $block['tag'] !== '') {
                // Lokalizovaný tagLabel jen na fresh čteních (D23) — /result
                // běží bez uživatelského jazyka, persist label nenese a
                // frontend fallbackuje na klíč štítku.
                if (!$allowLlm) {
                    $label = $this->resolver->tagLabelFor((string) $block['tag']);
                    if ($label !== null) {
                        $canonical['_resolve']['contentTag']['tagLabel'] = $label;
                    }
                }
                $canonical = $this->applyTagToRows($canonical, $block, withLabels: !$allowLlm);
            }
        }

        if ($this->contentTagBeforeDominance) {
            $canonical = $this->enricher->applyDominance($canonical);
        }

        return $canonical;
    }

    /**
     * Dokument-level štítek: fresh re-check pravidla dle IČO má přednost
     * před persistnutým LLM blokem (D16); LLM jen při allowLlm a jen když
     * žádný štítek není k dispozici. Null výstup LLM → blok se nezapisuje.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private function resolveDocumentTag(array $canonical, bool $allowLlm, bool $markHits): array
    {
        $companyId = $this->supplierCompanyId($canonical);
        $rule = $companyId !== null ? $this->resolver->resolveTagByRule($companyId) : null;

        if ($rule !== null) {
            if ($markHits) {
                $this->resolver->markRuleHit($rule['id']);
            }
            $canonical['_resolve']['contentTag'] = [
                'tag'       => $rule['tag'],
                'tagSource' => 'rule',
                'ruleId'    => $rule['id'],
            ];
            return $canonical;
        }

        $existing = $canonical['_resolve']['contentTag'] ?? null;
        if (is_array($existing) && is_string($existing['tag'] ?? null) && $existing['tag'] !== '') {
            return $canonical; // persistnutý štítek (typicky LLM z /result)
        }

        if (!$allowLlm || $this->classifier === null) {
            return $canonical;
        }

        $llm = $this->classifier->classify($canonical);
        if ($llm === null || $llm['primaryTag'] === null) {
            return $canonical; // klasifikace selhala / „žádný štítek" — nic se nezapisuje
        }

        $canonical['_resolve']['contentTag'] = [
            'tag'           => $llm['primaryTag'],
            'tagSource'     => 'llm',
            'tagConfidence' => $llm['confidence'],
            'promptVersion' => ContentTagClassifier::TAG_PROMPT_VERSION,
            'rowExceptions' => $llm['rowExceptions'],
        ];
        return $canonical;
    }

    /**
     * Propsání štítku do nepokrytých item řádků: tag řádku =
     * rowExceptions[rowIndex] ?? primaryTag, resolution štítek → položka
     * běží fresh ({@see ContentTagResolver::resolveItemForTag()}),
     * amountGuard návrh zadrží (D4). Propisují se jen prázdná pole (D3,
     * vzor Vrstvy 0), audit jde do `_resolve.rows[i].enrichment`.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $block  persistnutý/čerstvý `_resolve.contentTag`
     * @param bool $withLabels  fresh čtení → lokalizovaný tagLabel do auditu (D23)
     * @return array<string, mixed>
     */
    private function applyTagToRows(array $canonical, array $block, bool $withLabels = false): array
    {
        $primaryTag = (string) $block['tag'];
        $tagSource  = (string) ($block['tagSource'] ?? 'llm');

        $exceptions = [];
        foreach ((array) ($block['rowExceptions'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['rowIndex'], $entry['tag'])
                && is_numeric($entry['rowIndex']) && is_string($entry['tag'])
            ) {
                $exceptions[(int) $entry['rowIndex']] = $entry['tag'];
            }
        }

        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        foreach ($rows as $idx => $row) {
            if (!is_array($row) || !RowHistoryEnricher::rowExpectsItem($row)) {
                continue;
            }
            if (trim((string) ($row['item']['ourCode'] ?? '')) !== '') {
                continue; // pokryto Vrstvou 0 / AI extrakcí / userActions
            }

            $idx = (int) $idx;
            $rowTag = $exceptions[$idx] ?? $primaryTag;

            $enrichment = [
                'matchedBy' => 'contentTag',
                'confidence' => null,
                'tag'       => $rowTag,
                'tagSource' => $tagSource,
                'suggested' => [],
            ];
            if ($withLabels) {
                $label = $this->resolver->tagLabelFor($rowTag);
                if ($label !== null) {
                    $enrichment['tagLabel'] = $label;
                }
            }
            $vatHint = $this->resolver->vatHintFor($rowTag);
            if ($vatHint !== null) {
                $enrichment['vatHint'] = $vatHint;
            }

            $guard = $this->resolver->amountGuardFor($rowTag, $row);
            if ($guard !== null) {
                $enrichment['resolution'] = 'guarded';
                $enrichment['guard'] = 'amount';
                if (isset($guard['note'])) {
                    $enrichment['guardNote'] = (string) $guard['note'];
                }
                $canonical = $this->writeRowAudit($canonical, $idx, $enrichment);
                continue;
            }

            $resolution = $this->resolver->resolveItemForTag($rowTag);
            $enrichment['resolution'] = $resolution['status'];

            if ($resolution['status'] === 'item' || $resolution['status'] === 'accountOnly') {
                $suggested = [];
                $suggestedCode = $resolution['suggested']['ourCode'] ?? null;
                if (is_string($suggestedCode) && $suggestedCode !== '') {
                    $item = is_array($row['item'] ?? null) ? $row['item'] : [];
                    $item['ourCode'] = $suggestedCode;
                    $row['item'] = $item;
                    $suggested['ourCode'] = $suggestedCode;
                }
                $suggestedAccount = $resolution['suggested']['account'] ?? null;
                if (is_string($suggestedAccount) && $suggestedAccount !== ''
                    && trim((string) ($row['account'] ?? '')) === ''
                ) {
                    $row['account'] = $suggestedAccount;
                    $suggested['account'] = $suggestedAccount;
                }
                $canonical['rows'][$idx] = $row;

                $enrichment['confidence'] = 'medium';
                $enrichment['suggested'] = $suggested;
                if (isset($resolution['itemName'])) {
                    $enrichment['itemName'] = $resolution['itemName'];
                }
                if (isset($resolution['sourceItemId'])) {
                    $enrichment['sourceItemId'] = $resolution['sourceItemId'];
                }
            } elseif (isset($resolution['candidates'])) {
                $enrichment['candidates'] = $resolution['candidates'];
            }

            $canonical = $this->writeRowAudit($canonical, $idx, $enrichment);
        }

        return $canonical;
    }

    /**
     * @param array<string, mixed> $canonical
     */
    private function hasUncoveredItemRow(array $canonical): bool
    {
        foreach ((array) ($canonical['rows'] ?? []) as $row) {
            if (is_array($row) && RowHistoryEnricher::rowExpectsItem($row)
                && trim((string) ($row['item']['ourCode'] ?? '')) === ''
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * IČO protistrany z canonicalu — stejná selfParty logika jako
     * RowHistoryEnricher::resolveCounterparty(), ale bez DB (pravidla
     * operují na normalizovaném IČO, ne na FK partnera).
     *
     * @param array<string, mixed> $canonical
     */
    private function supplierCompanyId(array $canonical): ?string
    {
        $counterKey = ($canonical['selfParty'] ?? null) === 'supplier' ? 'customer' : 'supplier';
        $party = is_array($canonical[$counterKey] ?? null) ? $canonical[$counterKey] : [];
        $companyId = trim((string) ($party['companyId'] ?? ''));
        return $companyId !== '' ? $companyId : null;
    }

    /**
     * Idempotence contentTag návrhů z minulého běhu — vlastní dřívější
     * návrh (enrichment.suggested == aktuální hodnota) se odvolá, řádek
     * jde do resolvování znovu. Vrstva 0 dělá totéž pro své návrhy, ale
     * jen při shodě partnera; contentTag cesta partnera nepotřebuje.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private function revertContentTagSuggestions(array $canonical): array
    {
        $resolveRows = $canonical['_resolve']['rows'] ?? null;
        if (!is_array($resolveRows)) {
            return $canonical;
        }
        foreach ($resolveRows as $entry) {
            if (!is_array($entry)
                || ($entry['enrichment']['matchedBy'] ?? null) !== 'contentTag'
            ) {
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
            if (isset($suggested['account']) && ($row['account'] ?? null) === $suggested['account']) {
                $row['account'] = null;
            }
            $canonical['rows'][$idx] = $row;
        }
        return $canonical;
    }

    /**
     * Zápis audit bloku do `_resolve.rows[i].enrichment` — stejný merge
     * podle `index` jako RowHistoryEnricher::writeEnrichment().
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $enrichment
     * @return array<string, mixed>
     */
    private function writeRowAudit(array $canonical, int $idx, array $enrichment): array
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
