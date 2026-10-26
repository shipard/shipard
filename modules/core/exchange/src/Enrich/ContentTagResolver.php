<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Enrich;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Items\AccountingItemsOffer;
use Shipard\Module\World\Validations\CompanyId;
use Shipard\Module\World\Validations\ValueRulesRegistry;

/**
 * Deterministická část obsahové eskalace párování položek
 * (tasks/content-tag-enrichment.md, Vrstva 2):
 *
 *  - pravidlo IČO → štítek (`core_exchange_tag_rules`, D12) — zásah
 *    přeskakuje LLM klasifikaci; statistiky pravidla inkrementuje pipeline
 *    až při skutečném použití ({@see markRuleHit()}),
 *  - resolution štítek → účetní položka přes `economy_items.content_tags`
 *    (D9): právě jedna živá otagovaná položka → návrh trojice
 *    {ourCode, account}; více → ambiguous (bez návrhu); žádná → fallback
 *    účet z nabídky aktivní varianty osnovy včetně prefix fallbacku (D3,
 *    {@see AccountingItemsOffer::defaultAccountForTag()}),
 *  - amountGuard z economy.items.contentTagDefaults (D4) — řádek nad
 *    limitem návrh nedostane.
 *
 * Čistá služba bez side-efektů kromě markRuleHit(). Resolution běží fresh
 * při každém čtení (D16) — otagování položky mezi analýzou a preview se
 * projeví bez reanalýzy.
 */
final class ContentTagResolver
{
    /** Stejná trojice jako RowHistoryEnricher — navrhovaná položka musí být živá. */
    private const ITEM_ACTIVE_STATES = [10, 40, 80];

    public function __construct(
        private readonly Connection $db,
        private readonly ?AccountingItemsOffer $offer = null,
        private readonly ?ConfigRuntime $config = null,
    ) {}

    /**
     * Klíč tabulky pravidel: se zemí strany nejdřív pravidla státu
     * (`world.validations` — cz/sk doplní vedoucí nuly IČO, #118 D16), pak
     * dosavadní tvar klíče — bez mezer a oddělovačů, jen alfanumerické
     * znaky velkými. Uložená pravidla se nepřepisují; starý tvar bez
     * vedoucích nul hledá {@see resolveTagByRule()} jako druhou variantu.
     */
    public static function normalizeCompanyId(string $companyId, ?string $country = null): string
    {
        $value = $country !== null ? ValueRulesRegistry::forCountry($country)->normalizeCompanyId($companyId) : $companyId;
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $value));
    }

    /**
     * Lookup pravidla IČO → štítek. Nezvyšuje statistiky — to dělá pipeline
     * přes markRuleHit() až při skutečném použití zásahu.
     *
     * @return array{id: int, tag: string, origin: string}|null
     */
    public function resolveTagByRule(string $companyId, ?string $country = null): ?array
    {
        $normalized = self::normalizeCompanyId($companyId, $country);
        if ($normalized === '') {
            return null;
        }
        foreach (CompanyId::lookupVariants($normalized) as $variant) {
            $row = $this->db->fetch(
                'SELECT id, tag, origin FROM core_exchange_tag_rules WHERE company_id = %s',
                $variant,
            );
            if ($row !== null && $row !== false) {
                return [
                    'id'     => (int) $row['id'],
                    'tag'    => (string) $row['tag'],
                    'origin' => (string) $row['origin'],
                ];
            }
        }
        return null;
    }

    /** Inkrement statistik pravidla při skutečném použití v pipeline. */
    public function markRuleHit(int $ruleId): void
    {
        $this->db->update('core_exchange_tag_rules', [
            'hit_count%sql' => 'hit_count + 1',
            'last_hit_at'   => date('Y-m-d H:i:s'),
        ])->where('id = %i', $ruleId)->execute();
    }

    /**
     * Resolution štítek → návrh. Tvary výsledku (`status`):
     *
     *  - `item`        — právě jedna živá otagovaná položka; `suggested`
     *                    nese {ourCode, account?}, + itemName/sourceItemId,
     *  - `ambiguous`   — více otagovaných položek; bez návrhu, `candidates`
     *                    vyjmenovává kódy pro audit,
     *  - `accountOnly` — žádná otagovaná položka, ale nabídka aktivní
     *                    varianty osnovy nese fallback účet; `suggested`
     *                    má jen {account},
     *  - `unmapped`    — ani fallback účet. Nabídka pokrývá každý štítek
     *                    kromě admin.other (ds-setup Task 12) a ten dovede
     *                    prefix `admin.*` na účet, takže sem reálně spadne
     *                    jen štítek s prefixem, který nabídka nezná.
     *
     * @return array{status: string, suggested: array<string, string>,
     *               itemName?: string, sourceItemId?: int, candidates?: list<string>}
     */
    public function resolveItemForTag(string $tag): array
    {
        $items = $this->liveItemsForTag($tag);

        if (count($items) === 1) {
            $item = $items[0];
            $suggested = ['ourCode' => (string) $item['code']];
            $account = trim((string) ($item['account_number'] ?? ''));
            if ($account !== '') {
                $suggested['account'] = $account;
            }
            return [
                'status'       => 'item',
                'suggested'    => $suggested,
                'itemName'     => (string) $item['name'],
                'sourceItemId' => (int) $item['id'],
            ];
        }

        if (count($items) > 1) {
            return [
                'status'     => 'ambiguous',
                'suggested'  => [],
                'candidates' => array_map(static fn($it): string => (string) $it['code'], $items),
            ];
        }

        $account = $this->offer?->defaultAccountForTag($tag);
        if ($account !== null && $account !== '') {
            return ['status' => 'accountOnly', 'suggested' => ['account' => $account]];
        }

        return ['status' => 'unmapped', 'suggested' => []];
    }

    /**
     * amountGuard pro štítek (economy.items.contentTagDefaults) — vrací
     * konfiguraci guardu, pokud `totalPrice` řádku překračuje limit `over`;
     * jinak null (návrh smí projít).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public function amountGuardFor(string $tag, array $row): ?array
    {
        $guard = $this->tagDefaults()[$tag]['amountGuard'] ?? null;
        if (!is_array($guard)) {
            return null;
        }
        $over = (float) ($guard['over'] ?? 0);
        if ($over <= 0) {
            return null;
        }
        $total = $row['totalPrice'] ?? null;
        if (!is_numeric($total) || (float) $total <= $over) {
            return null;
        }
        return $guard;
    }

    /**
     * Lokalizovaný label štítku z cfgItem taxonomie. Compiled config je
     * načtený pro jazyk requestu — label proto patří jen do fresh odpovědí
     * (preview/apply), nikdy do persistu z /result (D23).
     */
    public function tagLabelFor(string $tag): ?string
    {
        $taxonomy = $this->config?->cfgItem('core.exchange.contentTags');
        $name = is_array($taxonomy) ? ($taxonomy[$tag]['name'] ?? null) : null;
        return is_string($name) && $name !== '' ? $name : null;
    }

    /** vatHint pro štítek — v1 informativní (jde jen do auditu), D4. */
    public function vatHintFor(string $tag): ?string
    {
        $hint = $this->tagDefaults()[$tag]['vatHint'] ?? null;
        return is_string($hint) && $hint !== '' ? $hint : null;
    }

    /** @return array<string, mixed> */
    private function tagDefaults(): array
    {
        $defaults = $this->config?->cfgItem('economy.items.contentTagDefaults');
        return is_array($defaults) ? $defaults : [];
    }

    /**
     * Živé položky nesoucí štítek. content_tags je JSON list — filtr běží
     * v PHP nad podmnožinou s neprázdným content_tags (otagovaných položek
     * jsou jednotky až desítky). LEFT JOIN na účty je stejný předpoklad
     * jako v RowHistoryEnricher::loadHistory().
     *
     * @return list<array<string, mixed>>
     */
    private function liveItemsForTag(string $tag): array
    {
        $rows = $this->db->fetchAll(
            'SELECT i.id, i.code, i.name, i.content_tags, a.number AS account_number'
            . ' FROM economy_items i'
            . ' LEFT JOIN economy_accounting_accounts a ON a.id = i.accounting_account'
            . ' WHERE i.docState IN %in AND i.content_tags IS NOT NULL'
            . ' ORDER BY i.id ASC',
            self::ITEM_ACTIVE_STATES,
        );

        $matched = [];
        foreach ($rows as $row) {
            $tags = json_decode((string) ($row['content_tags'] ?? ''), true);
            if (is_array($tags) && in_array($tag, $tags, true)) {
                $matched[] = $row;
            }
        }
        return $matched;
    }
}
