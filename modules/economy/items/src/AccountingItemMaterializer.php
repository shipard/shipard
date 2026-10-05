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

/**
 * Materializace jedné účetní položky z nabídky (accountingItemsDefault/
 * Npo.jsonc) — generátor extrahovaný ze SetupController (tasks/content-tag-ui.md
 * D26), sdílený setup panelem a endpointem
 * POST /_exchange/content-tags/materialize. Dvě cesty:
 *
 *  - {@see materializeOfferCode()} — setup panel: položka dle kódu nabídky;
 *    existující kód = skip (opakované generování je bezpečné).
 *  - {@see materializeForTag()} — obsahový štítek: položka z první nabídkové
 *    položky nesoucí přesně tento štítek (`account` override možný); štítek
 *    bez položky v nabídce vyžaduje `account` — kód = číslo účtu, název =
 *    lokalizovaný label štítku, content_tags = [tag]. Kolize kódu →
 *    písmenný sufix (konvence nabídky: sdílený účet, jiný kód).
 *
 * Zápis vždy přes TableGateway::saveDocument (ItemDocument validace + hooks);
 * volitelný $saveItem closure zachovává testovací seam SetupControlleru.
 */
class AccountingItemMaterializer
{
    /** source_kind vygenerovaných účetních položek (config/sourceKinds.jsonc). */
    public const SOURCE_KIND = 'setup.accountingItems';

    private const ITEMS_TABLE = 'economy_items';

    /** Aktivní analytické účty — stejná kritéria jako validace ItemDocument. */
    private const ACCOUNT_ACTIVE_STATES = [10, 40, 80];

    private int|false|null $itemKindIdCache = null;
    private int|false|null $unitIdCache = null;

    /**
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     * @param (\Closure(array<string, mixed>): DocumentResult)|null $saveItem
     *        Náhrada výchozího zápisu přes TableGateway (seam SetupControlleru).
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly AccountingItemsOffer $offer,
        private readonly string $language = 'en',
        private readonly ?ConfigRuntime $config = null,
        private readonly array $tables = [],
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DocumentRegistry $documentRegistry = null,
        private readonly ?DocumentEventDispatcher $eventDispatcher = null,
        private readonly ?\Closure $saveItem = null,
    ) {}

    /**
     * Podmínky funkčnosti (druh 'accounting' + jednotka 'pcs') — chybějící
     * je hlasitá chyba (409 u volajícího), žádný fallback: položka s jiným
     * druhem by v resolveItemAccount() tiše nefungovala.
     *
     * @return 'item_kind_missing'|'unit_missing'|null
     */
    public function missingPrerequisite(): ?string
    {
        if ($this->itemKindId() === null) {
            return 'item_kind_missing';
        }
        if ($this->unitId() === null) {
            return 'unit_missing';
        }
        return null;
    }

    /**
     * Položka dle kódu nabídky aktivní varianty osnovy (setup panel).
     *
     * @return array{status: 'created', id: int, code: string, name: string}
     *       | array{status: 'skipped', reason: string, accountNumber?: string}
     *       | array{status: 'failed', reason: string, message?: string}
     */
    public function materializeOfferCode(string $code): array
    {
        $variant = $this->offer->variant();
        $seed = $variant !== null ? $this->offer->loadSeed($variant) : null;
        if ($seed === null) {
            return ['status' => 'failed', 'reason' => 'seed_missing'];
        }

        $entry = $seed['items'][$code] ?? null;
        if ($entry === null) {
            return ['status' => 'skipped', 'reason' => 'unknown_code'];
        }
        if (isset($this->existingItemCodes([$code])[$code])) {
            return ['status' => 'skipped', 'reason' => 'already_exists'];
        }

        // Účet mimo osnovu položku přeskočí, nezaloží ji rozbitou.
        $number = (string) ($entry['account'] ?? '');
        $accountId = $this->accountIdForNumber($number);
        if ($accountId === null) {
            return ['status' => 'skipped', 'reason' => 'account_not_found', 'accountNumber' => $number];
        }

        $tags = is_array($entry['contentTags'] ?? null) ? array_values($entry['contentTags']) : [];
        return $this->create(
            $code,
            AccountingItemsOffer::localizedField($entry, 'name', $this->language, $code),
            $accountId,
            $tags,
        );
    }

    /**
     * Položka pro obsahový štítek (D26). Volající je odpovědný za kontrolu
     * „štítek už má živou otagovanou položku" (409 ALREADY_MAPPED) — ta
     * potřebuje ContentTagResolver a sem nepatří.
     *
     * @return array{status: 'created', id: int, code: string, name: string}
     *       | array{status: 'failed', reason: string, message?: string}
     */
    public function materializeForTag(string $tag, ?string $accountNumber = null): array
    {
        $entry = $this->offer->entryForTag($tag);
        $override = is_string($accountNumber) && trim($accountNumber) !== '' ? trim($accountNumber) : null;

        if ($entry === null && $override === null) {
            return ['status' => 'failed', 'reason' => 'account_required'];
        }

        $number = $override ?? (string) ($entry['account'] ?? '');
        $accountId = $this->accountIdForNumber($number);
        if ($accountId === null) {
            return ['status' => 'failed', 'reason' => 'account_not_found', 'message' => $number];
        }

        $baseCode = $entry !== null ? (string) $entry['code'] : $number;
        $code = $this->availableCode($baseCode);
        if ($code === null) {
            return ['status' => 'failed', 'reason' => 'code_collision', 'message' => $baseCode];
        }

        if ($entry !== null) {
            $name = AccountingItemsOffer::localizedField($entry, 'name', $this->language, $baseCode);
            $tags = is_array($entry['contentTags'] ?? null) ? array_values($entry['contentTags']) : [$tag];
        } else {
            $name = $this->tagLabel($tag) ?? $tag;
            $tags = [$tag];
        }

        return $this->create($code, $name, $accountId, $tags);
    }

    /**
     * @param list<string> $contentTags
     * @return array{status: 'created', id: int, code: string, name: string}
     *       | array{status: 'failed', reason: string, message?: string}
     */
    private function create(string $code, string $name, int $accountId, array $contentTags): array
    {
        $prereq = $this->missingPrerequisite();
        if ($prereq !== null) {
            return ['status' => 'failed', 'reason' => $prereq];
        }

        $payload = [
            'code'                => $code,
            'name'                => $name,
            'item_kind'           => (int) $this->itemKindId(),
            'unit'                => (int) $this->unitId(),
            'sales_price_no_vat'  => null,
            'accounting_account'  => $accountId,
            'source_kind'         => self::SOURCE_KIND,
            'source_ref'          => $code,
            'source_imported_at'  => date('Y-m-d H:i:s'),
            // Rovnou V pořádku — záznam je kurátorský a kompletní, Koncept
            // by jen čekal na ruční potvrzení, které nemá co ověřit.
            'docState'            => 40,
        ];
        if ($contentTags !== []) {
            $payload['content_tags'] = $contentTags;
        }

        $result = $this->saveItemRow($payload);
        if (!$result->isSuccess()) {
            return [
                'status'  => 'failed',
                'reason'  => 'save_failed',
                'message' => $result->getErrorMessage() ?? 'unknown error',
            ];
        }
        $saved = $result->getData() ?? [];
        return ['status' => 'created', 'id' => (int) ($saved['id'] ?? 0), 'code' => $code, 'name' => $name];
    }

    private function itemKindId(): ?int
    {
        if ($this->itemKindIdCache === null) {
            $id = $this->db->fetchSingle(
                'SELECT id FROM economy_items_kinds WHERE system_code = %s',
                'accounting',
            );
            $this->itemKindIdCache = $id ? (int) $id : false;
        }
        return $this->itemKindIdCache === false ? null : $this->itemKindIdCache;
    }

    private function unitId(): ?int
    {
        if ($this->unitIdCache === null) {
            $id = $this->db->fetchSingle(
                'SELECT id FROM core_units WHERE system_code = %s',
                'pcs',
            );
            $this->unitIdCache = $id ? (int) $id : false;
        }
        return $this->unitIdCache === false ? null : $this->unitIdCache;
    }

    private function accountIdForNumber(string $number): ?int
    {
        if ($number === '') {
            return null;
        }
        $id = $this->db->fetchSingle(
            'SELECT id FROM economy_accounting_accounts'
                . ' WHERE number = %s AND account_level = 4 AND docState IN %in',
            $number,
            self::ACCOUNT_ACTIVE_STATES,
        );
        return $id ? (int) $id : null;
    }

    /**
     * Kódy z $codes, které už v economy_items existují (unq_code, bez ohledu
     * na docState — kolize indexu je kolize) → set.
     *
     * @param list<string> $codes
     * @return array<string, true>
     */
    private function existingItemCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $set = [];
        $rows = $this->db->fetchAll(
            'SELECT code FROM ' . self::ITEMS_TABLE . ' WHERE code IN %in',
            $codes,
        );
        foreach ($rows as $row) {
            $set[(string) ($row['code'] ?? '')] = true;
        }
        return $set;
    }

    /** První volný kód: base, pak base+A…Z; null = vyčerpáno (26 kolizí). */
    private function availableCode(string $base): ?string
    {
        if (!isset($this->existingItemCodes([$base])[$base])) {
            return $base;
        }
        foreach (range('A', 'Z') as $suffix) {
            $candidate = $base . $suffix;
            if (!isset($this->existingItemCodes([$candidate])[$candidate])) {
                return $candidate;
            }
        }
        return null;
    }

    /** Lokalizovaný label štítku z cfgItem taxonomie (compiled config). */
    private function tagLabel(string $tag): ?string
    {
        $taxonomy = $this->config?->cfgItem('core.exchange.contentTags');
        $name = is_array($taxonomy) ? ($taxonomy[$tag]['name'] ?? null) : null;
        return is_string($name) && $name !== '' ? $name : null;
    }

    /** @param array<string, mixed> $payload */
    private function saveItemRow(array $payload): DocumentResult
    {
        if ($this->saveItem !== null) {
            return ($this->saveItem)($payload);
        }
        $def = $this->tables[self::ITEMS_TABLE] ?? null;
        if ($def === null || $this->documentRegistry === null || $this->config === null) {
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
