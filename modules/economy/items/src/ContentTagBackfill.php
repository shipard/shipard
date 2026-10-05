<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Reverzní otagování živých položek DS podle účtů (D34,
 * `tasks/booking-history-import.md`).
 *
 * Sdílí s reverzními návrhy v Nastavení (D15/D27) dvě věci: dotaz na
 * **neotagované položky s účtem** ({@see untaggedItemsWithAccount()}) a
 * mapu účet→štítek z nabídky ({@see AccountingItemsOffer::tagsByAccount()}).
 * Rozdíl je v rozhodování: obrazovka nabídne i kolizní účty (uživatel
 * vybere), dávka {@see plan()} bere **jen jednoznačné** — hromadný zápis
 * nemá koho se zeptat.
 *
 * Reverz jede na **přesnou shodu čísla účtu**, bez syntetické tolerance
 * (kterou má `AccountTagMap` pro cizí analytiky ve zdrojovém souboru):
 * účty položek pocházejí z naší osnovy, takže syntetika nic nepřidá a
 * zapisovat do dat uživatele na slabší signál nepatří.
 *
 * Zápis jde přes `TableGateway` (ItemDocument — serializace `content_tags`,
 * validace, audit), s **mergem** existujících štítků: dávka nesmí přepsat
 * ručně přidaný štítek. Neotagovaná položka je jen ta s prázdnými štítky,
 * takže merge je pojistka, ne pravidlo.
 */
class ContentTagBackfill
{
    private const ITEMS_TABLE = 'economy_items';

    /** Stejná trojice jako ContentTagResolver / ContentTagsController. */
    private const ITEM_ACTIVE_STATES = [10, 40, 80];

    /**
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly AccountingItemsOffer $offer,
        private readonly ?ConfigRuntime $config = null,
        private readonly array $tables = [],
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DocumentRegistry $documentRegistry = null,
        private readonly ?DocumentEventDispatcher $eventDispatcher = null,
    ) {}

    /** Sloupec accounting_account je extension z economy.accounting. */
    public function accountingActive(): bool
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
     * Je nad čím tagovat? Mapa účet→štítek se čte z nabídky **varianty
     * osnovy nastavené na tomto DS** (`economy.accountChart`) — ne z
     * hlavičky zdrojového souboru. Bez nastavené varianty je mapa prázdná
     * a každý účet by se jinak vykázal jako „mimo nabídku", což by lhalo.
     */
    public function offerAvailable(): bool
    {
        return $this->offer->tagsByAccount() !== [];
    }

    /**
     * Živé položky s účtem a bez obsahových štítků. Sdílené s reverzními
     * návrhy v Nastavení — jeden dotaz, dvě rozhodovací politiky nad ním.
     *
     * @return list<array{id: int, code: string, name: string, account: string}>
     */
    public function untaggedItemsWithAccount(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT i.id, i.code, i.name, a.number AS account_number'
            . ' FROM economy_items i'
            . ' JOIN economy_accounting_accounts a ON a.id = i.accounting_account'
            . ' WHERE i.docState IN %in'
            . ' AND (i.content_tags IS NULL OR i.content_tags = %s OR i.content_tags = %s)'
            . ' ORDER BY i.code ASC',
            self::ITEM_ACTIVE_STATES,
            '',
            '[]',
        );

        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $account = (string) ($row['account_number'] ?? '');
            if ($account === '') {
                continue;
            }
            $out[] = [
                'id'      => (int) $row['id'],
                'code'    => (string) $row['code'],
                'name'    => (string) $row['name'],
                'account' => $account,
            ];
        }
        return $out;
    }

    /**
     * Živé položky s kódem a jejich existujícími štítky — katalog pro
     * otagování z užití (D38, `BookingHistoryItemUsageTagger`). Na rozdíl
     * od {@see untaggedItemsWithAccount()} sem patří **i otagované** a
     * položky bez účtu: usage režim párované kódy jen porovnává (míra
     * shody) a už otagované musí umět poctivě přeskočit.
     *
     * @return list<array{id: int, code: string, name: string, tags: list<string>}>
     */
    public function liveItemsByCode(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, code, name, content_tags FROM ' . self::ITEMS_TABLE
            . ' WHERE docState IN %in AND code IS NOT NULL AND code != %s'
            . ' ORDER BY code ASC',
            self::ITEM_ACTIVE_STATES,
            '',
        );

        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $tags = json_decode((string) ($row['content_tags'] ?? ''), true);
            $out[] = [
                'id'   => (int) $row['id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'tags' => is_array($tags)
                    ? array_values(array_filter($tags, static fn ($t): bool => is_string($t) && $t !== ''))
                    : [],
            ];
        }
        return $out;
    }

    /**
     * Plán otagování — jen položky, jejichž účet nese v nabídce právě jeden
     * štítek. Kolizní a neznámé účty se do plánu nedostanou; jejich počty
     * vrací {@see planSkipped()}, aby CLI mohlo říct, co nechalo být.
     *
     * @return list<array{id: int, code: string, name: string, account: string, tag: string}>
     */
    public function plan(): array
    {
        $tagsByAccount = $this->offer->tagsByAccount();
        if ($tagsByAccount === []) {
            return [];
        }
        $taxonomy = $this->taxonomy();

        $plan = [];
        foreach ($this->untaggedItemsWithAccount() as $item) {
            $tags = $tagsByAccount[$item['account']] ?? [];
            if (count($tags) !== 1) {
                continue;
            }
            // Nabídka může nést štítek, který taxonomie už nezná (starší
            // seed soubor) — enum je autorita i tady.
            if ($taxonomy !== [] && !array_key_exists($tags[0], $taxonomy)) {
                continue;
            }
            $plan[] = $item + ['tag' => $tags[0]];
        }
        return $plan;
    }

    /**
     * Kolik neotagovaných položek plán vynechal a proč.
     *
     * @return array{candidates: int, ambiguousAccount: int, unmappedAccount: int}
     */
    public function planSkipped(): array
    {
        $tagsByAccount = $this->offer->tagsByAccount();
        $counters = ['candidates' => 0, 'ambiguousAccount' => 0, 'unmappedAccount' => 0];
        foreach ($this->untaggedItemsWithAccount() as $item) {
            $tags = $tagsByAccount[$item['account']] ?? [];
            if (count($tags) === 1) {
                $counters['candidates']++;
            } elseif ($tags !== []) {
                $counters['ambiguousAccount']++;
            } else {
                $counters['unmappedAccount']++;
            }
        }
        return $counters;
    }

    /**
     * Zapíše plán. Vrací, co se povedlo a co ne — dávka nepadá na jedné
     * položce.
     *
     * @param list<array{id: int, tag: string}> $plan
     * @return array{updated: list<array{id: int, tags: list<string>}>, failed: list<array{id: int, reason: string}>}
     */
    public function apply(array $plan): array
    {
        $updated = [];
        $failed = [];
        foreach ($plan as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            $tag = (string) ($entry['tag'] ?? '');
            if ($id <= 0 || $tag === '') {
                $failed[] = ['id' => $id, 'reason' => 'bad_entry'];
                continue;
            }

            $row = $this->fetchItemRow($id);
            if ($row === null) {
                $failed[] = ['id' => $id, 'reason' => 'not_found'];
                continue;
            }

            $existing = json_decode((string) ($row['content_tags'] ?? ''), true);
            $merged = array_values(array_unique(array_merge(
                is_array($existing) ? $existing : [],
                [$tag],
            )));

            $payload = $row;
            $payload['content_tags'] = $merged;
            $payload['id'] = $id;

            $result = $this->saveItemRow($payload);
            if ($result->isSuccess()) {
                $updated[] = ['id' => $id, 'tags' => $merged];
            } else {
                $failed[] = ['id' => $id, 'reason' => 'save_failed'];
                ErrorLogger::error('ContentTagBackfill: item save failed', [
                    'id'      => $id,
                    'message' => $result->getErrorMessage(),
                ]);
            }
        }
        return ['updated' => $updated, 'failed' => $failed];
    }

    /** @return array<string, mixed> */
    private function taxonomy(): array
    {
        $taxonomy = $this->config?->cfgItem('core.exchange.contentTags');
        return is_array($taxonomy) ? $taxonomy : [];
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
