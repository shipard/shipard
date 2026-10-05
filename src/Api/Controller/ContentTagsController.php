<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Exchange\Enrich\ContentTagResolver;
use Shipard\Module\Economy\Items\AccountingItemMaterializer;
use Shipard\Module\Economy\Items\AccountingItemsOffer;
use Shipard\Module\Economy\Items\ContentTagBackfill;

/**
 * Endpoints (tasks/content-tag-ui.md D26/D27):
 *   POST /_exchange/content-tags/materialize — {tag, account?} → založení
 *        účetní položky pro obsahový štítek (nabídka aktivní varianty
 *        osnovy / explicitní účet u goods.stock); živá otagovaná položka
 *        → 409 ALREADY_MAPPED.
 *   GET  /_exchange/content-tags/overview    — stav mapování taxonomie
 *        (otagované položky / default účet z nabídky / bez mapování)
 *        + reverzní návrhy účet→štítek pro neotagované položky (D15).
 *   POST /_exchange/content-tags/tag-items   — hromadné otagování položek
 *        {items: [{id, tags}]} přes TableGateway (load → merge → save).
 *
 * Auth: přihlášený uživatel, bez adminOnly — materializaci spouští
 * dashboard karta běžného uživatele (stejná úroveň jako /_setup).
 */
class ContentTagsController
{
    private const ITEMS_TABLE = 'economy_items';

    /** Stejná trojice jako ContentTagResolver — „živé" položky. */
    private const ITEM_ACTIVE_STATES = [10, 40, 80];

    private ?AccountingItemsOffer $offerCache = null;

    /**
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ConfigRuntime $config,
        private readonly string $language,
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly array $tables = [],
        private readonly ?DocumentRegistry $documentRegistry = null,
        private readonly ?DocumentEventDispatcher $eventDispatcher = null,
    ) {}

    /** POST /_exchange/content-tags/materialize — body {tag, account?} */
    public function materialize(Request $request, AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $body = $request->getBody();
        $tag = is_string($body['tag'] ?? null) ? trim($body['tag']) : '';
        $account = is_string($body['account'] ?? null) ? trim($body['account']) : null;

        if ($tag === '' || !array_key_exists($tag, $this->taxonomy())) {
            return Response::error('UNKNOWN_TAG', "Unknown content tag \"{$tag}\"", 422);
        }
        if (!$this->accountingActive()) {
            return Response::error(
                'OFFER_UNAVAILABLE',
                'Accounting module is not active — items cannot carry an account',
                409,
            );
        }

        // Živá otagovaná položka (i ambiguous = víc položek) → štítek už je
        // mapovaný, materializace nemá co dělat.
        $resolution = $this->resolver()->resolveItemForTag($tag);
        if (in_array($resolution['status'], ['item', 'ambiguous'], true)) {
            return Response::error('ALREADY_MAPPED', "Content tag \"{$tag}\" already has a live tagged item", 409);
        }

        $result = $this->materializer()->materializeForTag($tag, $account);
        if ($result['status'] === 'created') {
            return Response::success([
                'itemId' => $result['id'],
                'code'   => $result['code'],
                'name'   => $result['name'],
            ]);
        }

        return match ($result['reason']) {
            'account_required'  => Response::error(
                'ACCOUNT_REQUIRED',
                "Content tag \"{$tag}\" has no offer item — an account number is required",
                422,
            ),
            'account_not_found' => Response::error(
                'ACCOUNT_NOT_FOUND',
                'Account "' . ($result['message'] ?? '') . '" not found in the active chart',
                422,
            ),
            'item_kind_missing' => Response::error(
                'ITEM_KIND_MISSING',
                "Item kind with system_code 'accounting' not found — run ds-upgrade first",
                409,
            ),
            'unit_missing'      => Response::error(
                'UNIT_MISSING',
                "Unit with system_code 'pcs' not found — run ds-upgrade first",
                409,
            ),
            'code_collision'    => Response::error(
                'CODE_COLLISION',
                'No free item code for base "' . ($result['message'] ?? '') . '"',
                409,
            ),
            default             => $this->saveFailed($tag, $result),
        };
    }

    /** GET /_exchange/content-tags/overview */
    public function overview(AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $accounting = $this->accountingActive();
        $variant = $this->offer()->variant();
        $available = $accounting && $variant !== null && $variant !== 'none';

        $taggedByTag = $accounting ? $this->liveTaggedItemsByTag() : [];

        $tags = [];
        foreach ($this->taxonomy() as $tag => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $items = $taggedByTag[$tag] ?? [];
            $defaultAccount = ($items === [] && $available)
                ? $this->offer()->defaultAccountForTag($tag)
                : null;
            $tags[] = [
                'tag'            => (string) $tag,
                'label'          => is_string($entry['name'] ?? null) && $entry['name'] !== ''
                    ? $entry['name']
                    : (string) $tag,
                'order'          => (int) ($entry['order'] ?? 0),
                'state'          => $items !== [] ? 'mapped' : ($defaultAccount !== null ? 'defaultAccount' : 'unmapped'),
                'items'          => $items,
                'defaultAccount' => $defaultAccount,
            ];
        }
        usort($tags, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return Response::success([
            'available'    => $available,
            'chartVariant' => $variant,
            'tags'         => $tags,
            'untagged'     => $available ? $this->untaggedSuggestions() : [],
        ]);
    }

    /** POST /_exchange/content-tags/tag-items — body {items: [{id, tags}]} */
    public function tagItems(Request $request, AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $items = $request->getBody()['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return Response::error('BAD_REQUEST', '`items` must be a non-empty list of {id, tags}', 400);
        }

        $taxonomy = $this->taxonomy();
        $updated = [];
        $failed = [];
        foreach ($items as $entry) {
            $id = is_array($entry) && is_numeric($entry['id'] ?? null) ? (int) $entry['id'] : 0;
            $tags = is_array($entry) && is_array($entry['tags'] ?? null) ? $entry['tags'] : [];
            $tags = array_values(array_unique(array_filter($tags, static fn ($t): bool => is_string($t) && $t !== '')));
            if ($id <= 0 || $tags === []) {
                $failed[] = ['id' => $id, 'reason' => 'bad_entry'];
                continue;
            }
            $unknown = array_diff($tags, array_keys($taxonomy));
            if ($unknown !== []) {
                $failed[] = ['id' => $id, 'reason' => 'unknown_tag'];
                continue;
            }

            $row = $this->fetchItemRow($id);
            if ($row === null) {
                $failed[] = ['id' => $id, 'reason' => 'not_found'];
                continue;
            }

            // Merge s existujícími štítky — reverzní otagování nesmí ztratit
            // ručně přidané. Gateway validuje payload as-is, proto celý řádek.
            $existing = json_decode((string) ($row['content_tags'] ?? ''), true);
            $merged = array_values(array_unique(array_merge(
                is_array($existing) ? $existing : [],
                $tags,
            )));
            $payload = $row;
            $payload['content_tags'] = $merged;
            $payload['id'] = $id;

            $result = $this->saveItemRow($payload);
            if ($result->isSuccess()) {
                $updated[] = ['id' => $id, 'tags' => $merged];
            } else {
                $failed[] = ['id' => $id, 'reason' => 'save_failed'];
                ErrorLogger::error('ContentTagsController: tag-items save failed', [
                    'id'      => $id,
                    'message' => $result->getErrorMessage(),
                ]);
            }
        }

        return Response::success(['updated' => $updated, 'failed' => $failed]);
    }

    // ── Interní ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function taxonomy(): array
    {
        $taxonomy = $this->config->cfgItem('core.exchange.contentTags');
        return is_array($taxonomy) ? $taxonomy : [];
    }

    /** Sloupec accounting_account je extension z economy.accounting. */
    private function accountingActive(): bool
    {
        $def = $this->tables[self::ITEMS_TABLE] ?? null;
        foreach ($def?->columns ?? [] as $column) {
            if ($column->id === 'accounting_account') {
                return true;
            }
        }
        return false;
    }

    /**
     * Živé otagované položky seskupené per štítek (jeden dotaz, JSON filtr
     * v PHP — vzor ContentTagResolver::liveItemsForTag()).
     *
     * @return array<string, list<array{id: int, code: string, name: string, account: ?string}>>
     */
    private function liveTaggedItemsByTag(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT i.id, i.code, i.name, i.content_tags, a.number AS account_number'
            . ' FROM economy_items i'
            . ' LEFT JOIN economy_accounting_accounts a ON a.id = i.accounting_account'
            . ' WHERE i.docState IN %in AND i.content_tags IS NOT NULL'
            . ' ORDER BY i.code ASC',
            self::ITEM_ACTIVE_STATES,
        );
        $byTag = [];
        foreach ($rows as $row) {
            $tags = json_decode((string) ($row['content_tags'] ?? ''), true);
            if (!is_array($tags)) {
                continue;
            }
            $item = [
                'id'      => (int) $row['id'],
                'code'    => (string) $row['code'],
                'name'    => (string) $row['name'],
                'account' => isset($row['account_number']) ? (string) $row['account_number'] : null,
            ];
            foreach ($tags as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $byTag[$tag][] = $item;
                }
            }
        }
        return $byTag;
    }

    /**
     * Reverzní návrhy účet → štítek (D15): živé položky s účtem a bez
     * štítků; návrh jen když účet nese v nabídce aktivní varianty právě
     * jeden štítek napříč položkami nabídky — kolizní účty poctivě bez
     * návrhu (candidateTags pro UI).
     *
     * @return list<array<string, mixed>>
     */
    private function untaggedSuggestions(): array
    {
        // Reverzní mapa účet → štítky je sdílená s reverzem nad souborem
        // účetní historie (AccountingItemsOffer::tagsByAccount()).
        $tagsByAccount = $this->offer()->tagsByAccount();
        if ($tagsByAccount === []) {
            return [];
        }

        // Dotaz na neotagované položky je sdílený s dávkovým otagováním
        // z CLI (ContentTagBackfill, D34) — jeden dotaz, dvě politiky nad
        // ním: obrazovka nabídne i kolizní účty, dávka bere jen jednoznačné.
        $rows = $this->backfill()->untaggedItemsWithAccount();

        $taxonomy = $this->taxonomy();
        $out = [];
        foreach ($rows as $row) {
            $tags = $tagsByAccount[$row['account']] ?? [];
            if ($tags === []) {
                continue; // účet mimo nabídku — bez návrhu i bez záznamu
            }
            $suggestion = [
                'id'      => $row['id'],
                'code'    => $row['code'],
                'name'    => $row['name'],
                'account' => $row['account'],
            ];
            if (count($tags) === 1) {
                $tag = $tags[0];
                $suggestion['suggestedTag'] = $tag;
                $suggestion['suggestedTagLabel'] = is_string($taxonomy[$tag]['name'] ?? null)
                    ? $taxonomy[$tag]['name']
                    : $tag;
            } else {
                $suggestion['suggestedTag'] = null;
                $suggestion['candidateTags'] = $tags;
            }
            $out[] = $suggestion;
        }
        return $out;
    }

    /** Seam pro testy (vzor saveItemRow). */
    protected function resolver(): ContentTagResolver
    {
        return new ContentTagResolver($this->db->getDibiConnection(), $this->offer(), $this->config);
    }

    /** Sdílená služba reverzního otagování položek (D34). */
    protected function backfill(): ContentTagBackfill
    {
        return new ContentTagBackfill(
            db: $this->db,
            offer: $this->offer(),
            config: $this->config,
            tables: $this->tables,
            dsConfig: $this->dsConfig,
            documentRegistry: $this->documentRegistry,
            eventDispatcher: $this->eventDispatcher,
        );
    }

    /** Seam pro testy (vzor saveItemRow). */
    protected function materializer(): AccountingItemMaterializer
    {
        return new AccountingItemMaterializer(
            db: $this->db,
            offer: $this->offer(),
            language: $this->language,
            config: $this->config,
            tables: $this->tables,
            dsConfig: $this->dsConfig,
            documentRegistry: $this->documentRegistry,
            eventDispatcher: $this->eventDispatcher,
        );
    }

    private function offer(): AccountingItemsOffer
    {
        return $this->offerCache ??= new AccountingItemsOffer($this->db);
    }

    /** @param array{status: string, reason: string, message?: string} $result */
    private function saveFailed(string $tag, array $result): Response
    {
        ErrorLogger::error('ContentTagsController: materialize failed', [
            'tag'     => $tag,
            'reason'  => $result['reason'],
            'message' => $result['message'] ?? null,
        ]);
        return Response::error(
            'SAVE_FAILED',
            "Materializing item for \"{$tag}\" failed: " . ($result['message'] ?? $result['reason']),
            500,
        );
    }

    /**
     * Celý řádek položky pro merge-save (gateway validuje payload as-is).
     *
     * @return array<string, mixed>|null
     */
    protected function fetchItemRow(int $id): ?array
    {
        $row = $this->db->fetchRow(
            'SELECT * FROM ' . self::ITEMS_TABLE . ' WHERE id = %i AND docState IN %in',
            $id,
            self::ITEM_ACTIVE_STATES,
        );
        return is_array($row) ? $row : null;
    }

    /** Seam pro testy — zápis přes ItemDocument (TableGateway). */
    protected function saveItemRow(array $payload): DocumentResult
    {
        $def = $this->tables[self::ITEMS_TABLE] ?? null;
        if ($def === null || $this->documentRegistry === null) {
            return DocumentResult::error('Table definition or document registry unavailable');
        }
        $gateway = new TableGateway(
            self::ITEMS_TABLE,
            $this->db->getDibiConnection(),
            $this->documentRegistry,
            $def->childTables,
            $this->config,
            $this->dsConfig,
            $this->eventDispatcher,
            $def->docStates,
            $def,
        );
        return $gateway->saveDocument($payload);
    }
}
